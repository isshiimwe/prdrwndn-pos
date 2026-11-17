<?php
/**
 * Face ID / Touch ID sign-in for the POS (passkeys, WebAuthn).
 *
 * Uses the same saved passkeys as the PRDRWNDN shop app (user meta
 * _prd_passkeys and _prd_passkey_handle, same site address), so Face ID set
 * up in either app works in both. Only accounts with POS access can sign in
 * to the POS this way; it never creates accounts.
 *
 * Saved per user in _prd_passkeys: [ { id (base64url), pk (PEM), count, created, name } ].
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_Passkeys {

	const META   = '_prd_passkeys';
	const HANDLE = '_prd_passkey_handle';
	const KEEP   = 10;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$ns     = PRDRWNDN_POS_REST::NS;
		$public = '__return_true';
		$seller = static function () {
			return current_user_can( PRDRWNDN_POS_Roles::CAP_SELL );
		};
		register_rest_route( $ns, '/passkey/login-options', array( 'methods' => 'POST', 'permission_callback' => $public, 'callback' => array( __CLASS__, 'login_options' ) ) );
		register_rest_route( $ns, '/passkey/login', array( 'methods' => 'POST', 'permission_callback' => $public, 'callback' => array( __CLASS__, 'login' ) ) );
		register_rest_route( $ns, '/passkey/register-options', array( 'methods' => 'POST', 'permission_callback' => $seller, 'callback' => array( __CLASS__, 'register_options' ) ) );
		register_rest_route( $ns, '/passkey/register', array( 'methods' => 'POST', 'permission_callback' => $seller, 'callback' => array( __CLASS__, 'register' ) ) );
		register_rest_route(
			$ns,
			'/passkeys',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $seller,
					'callback'            => static function () {
						return array( 'count' => count( self::all( get_current_user_id() ) ) );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $seller,
					'callback'            => static function () {
						delete_user_meta( get_current_user_id(), self::META );
						return array( 'count' => 0 );
					},
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Shared with the shop app                                            */
	/* ------------------------------------------------------------------ */

	public static function all( $user_id ) {
		$list = get_user_meta( $user_id, self::META, true );
		return is_array( $list ) ? $list : array();
	}

	public static function rp_id() {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	public static function rp_origin() {
		$p = wp_parse_url( home_url() );
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	/** Stable, non-guessable user handle stored on the passkey (same as the shop app). */
	public static function user_handle( $user_id ) {
		$h = get_user_meta( $user_id, self::HANDLE, true );
		if ( ! $h ) {
			$h = self::b64u_enc( random_bytes( 32 ) );
			update_user_meta( $user_id, self::HANDLE, $h );
		}
		return $h;
	}

	public static function b64u_enc( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	public static function b64u_dec( $s ) {
		$s = strtr( (string) $s, '-_', '+/' );
		return (string) base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/** A few tries per address, so nobody can hammer the sign-in. */
	private static function throttle( $what, $max ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'prdpos_pk_' . $what . '_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Setup (signed in)                                                   */
	/* ------------------------------------------------------------------ */

	public static function register_options() {
		$u         = wp_get_current_user();
		$challenge = random_bytes( 32 );
		set_transient( 'prd_pk_reg_' . $u->ID, self::b64u_enc( $challenge ), 300 );
		$exclude = array();
		foreach ( self::all( $u->ID ) as $k ) {
			$exclude[] = array(
				'type' => 'public-key',
				'id'   => $k['id'],
			);
		}
		return array(
			'challenge'              => self::b64u_enc( $challenge ),
			'rp'                     => array(
				'name' => 'PRDRWNDN',
				'id'   => self::rp_id(),
			),
			'user'                   => array(
				'id'          => self::user_handle( $u->ID ),
				'name'        => $u->user_email ? $u->user_email : $u->user_login,
				'displayName' => $u->display_name,
			),
			'pubKeyCredParams'       => array(
				array(
					'type' => 'public-key',
					'alg'  => -7,
				),
				array(
					'type' => 'public-key',
					'alg'  => -257,
				),
			),
			'authenticatorSelection' => array(
				'residentKey'        => 'required',
				'requireResidentKey' => true,
				'userVerification'   => 'required',
			),
			'attestation'            => 'none',
			'timeout'                => 60000,
			'excludeCredentials'     => $exclude,
		);
	}

	public static function register( WP_REST_Request $r ) {
		$uid       = get_current_user_id();
		$challenge = get_transient( 'prd_pk_reg_' . $uid );
		delete_transient( 'prd_pk_reg_' . $uid );
		if ( ! $challenge ) {
			return new WP_Error( 'prd_pk_expired', 'That took too long. Try again.', array( 'status' => 400 ) );
		}
		$client = json_decode( self::b64u_dec( $r->get_param( 'clientDataJSON' ) ), true );
		if ( ! is_array( $client ) || 'webauthn.create' !== ( $client['type'] ?? '' ) || ! hash_equals( $challenge, (string) ( $client['challenge'] ?? '' ) ) || self::rp_origin() !== ( $client['origin'] ?? '' ) ) {
			return new WP_Error( 'prd_pk_bad', 'Face ID setup could not be verified.', array( 'status' => 400 ) );
		}
		try {
			$att  = self::cbor_decode( self::b64u_dec( $r->get_param( 'attestationObject' ) ) );
			$auth = self::parse_auth_data( (string) ( $att['authData'] ?? '' ), true );
		} catch ( Exception $e ) {
			return new WP_Error( 'prd_pk_bad', 'Face ID setup could not be read.', array( 'status' => 400 ) );
		}
		if ( ! hash_equals( hash( 'sha256', self::rp_id(), true ), $auth['rpIdHash'] ) || ! ( $auth['flags'] & 0x01 ) || ! ( $auth['flags'] & 0x04 ) || empty( $auth['pem'] ) ) {
			return new WP_Error( 'prd_pk_bad', 'Face ID setup could not be verified.', array( 'status' => 400 ) );
		}
		$list   = self::all( $uid );
		$list[] = array(
			'id'      => self::b64u_enc( $auth['credId'] ),
			'pk'      => $auth['pem'],
			'count'   => $auth['signCount'],
			'created' => time(),
			'name'    => substr( sanitize_text_field( (string) $r->get_param( 'device' ) ), 0, 60 ),
		);
		$list   = array_slice( $list, -self::KEEP );
		update_user_meta( $uid, self::META, $list );
		return array(
			'ok'    => true,
			'count' => count( $list ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Sign in                                                             */
	/* ------------------------------------------------------------------ */

	public static function login_options() {
		if ( ! self::throttle( 'opt', 30 ) ) {
			return new WP_Error( 'prd_slow_down', 'Too many tries. Wait a few minutes.', array( 'status' => 429 ) );
		}
		$challenge = random_bytes( 32 );
		$token     = self::b64u_enc( random_bytes( 18 ) );
		set_transient( 'prd_pk_login_' . $token, self::b64u_enc( $challenge ), 300 );
		return array(
			'token'   => $token,
			'options' => array(
				'challenge'        => self::b64u_enc( $challenge ),
				'rpId'             => self::rp_id(),
				'userVerification' => 'required',
				'timeout'          => 60000,
				'allowCredentials' => array(),
			),
		);
	}

	public static function login( WP_REST_Request $r ) {
		if ( ! self::throttle( 'login', 20 ) ) {
			return new WP_Error( 'prd_slow_down', 'Too many tries. Wait a few minutes.', array( 'status' => 429 ) );
		}
		$fail      = new WP_Error( 'prd_pk_fail', 'Face ID sign-in didn\'t work. Sign in with your password instead.', array( 'status' => 401 ) );
		$token     = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $r->get_param( 'token' ) );
		$challenge = $token ? get_transient( 'prd_pk_login_' . $token ) : false;
		if ( $token ) {
			delete_transient( 'prd_pk_login_' . $token );
		}
		if ( ! $challenge ) {
			return $fail;
		}
		$client_raw = self::b64u_dec( $r->get_param( 'clientDataJSON' ) );
		$client     = json_decode( $client_raw, true );
		if ( ! is_array( $client ) || 'webauthn.get' !== ( $client['type'] ?? '' ) || ! hash_equals( $challenge, (string) ( $client['challenge'] ?? '' ) ) || self::rp_origin() !== ( $client['origin'] ?? '' ) ) {
			return $fail;
		}

		// The browser returns the handle set at setup; it points to the account.
		$handle = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $r->get_param( 'userHandle' ) );
		$users  = '' !== $handle ? get_users(
			array(
				'meta_key'   => self::HANDLE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $handle, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'     => 1,
				'fields'     => 'ID',
			)
		) : array();
		if ( ! $users ) {
			return new WP_Error( 'prd_pk_none', 'This Face ID isn\'t set up for any account here. Sign in with your password, then turn on Face ID in the menu.', array( 'status' => 401 ) );
		}
		$uid  = (int) $users[0];
		$cid  = (string) $r->get_param( 'id' );
		$list = self::all( $uid );
		$idx  = null;
		foreach ( $list as $i => $k ) {
			if ( hash_equals( (string) $k['id'], $cid ) ) {
				$idx = $i;
				break;
			}
		}
		if ( null === $idx ) {
			return new WP_Error( 'prd_pk_none', 'Face ID was turned off for this account. Sign in with your password, then turn it on again in the menu.', array( 'status' => 401 ) );
		}
		$auth_raw = self::b64u_dec( $r->get_param( 'authenticatorData' ) );
		try {
			$auth = self::parse_auth_data( $auth_raw, false );
		} catch ( Exception $e ) {
			return $fail;
		}
		if ( ! hash_equals( hash( 'sha256', self::rp_id(), true ), $auth['rpIdHash'] ) || ! ( $auth['flags'] & 0x01 ) || ! ( $auth['flags'] & 0x04 ) ) {
			return $fail;
		}
		$sig = self::b64u_dec( $r->get_param( 'signature' ) );
		if ( 1 !== openssl_verify( $auth_raw . hash( 'sha256', $client_raw, true ), $sig, $list[ $idx ]['pk'], OPENSSL_ALGO_SHA256 ) ) {
			return $fail;
		}
		if ( $auth['signCount'] > 0 && $auth['signCount'] <= (int) $list[ $idx ]['count'] ) {
			return $fail; // A copied key.
		}
		$list[ $idx ]['count'] = $auth['signCount'];
		update_user_meta( $uid, self::META, $list );

		$user = get_user_by( 'id', $uid );
		if ( ! $user || ! user_can( $user, PRDRWNDN_POS_Roles::CAP_SELL ) ) {
			return new WP_Error( 'prd_pk_no_pos', 'That account can\'t use the POS. Ask the owner to make it a POS Cashier.', array( 'status' => 403 ) );
		}
		wp_set_current_user( $uid, $user->user_login );
		wp_set_auth_cookie( $uid, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		return array(
			'ok'   => true,
			'name' => $user->display_name,
		);
	}

	/* ------------------------------------------------------------------ */
	/* WebAuthn data                                                       */
	/* ------------------------------------------------------------------ */

	/** Authenticator data: rpIdHash, flags, signCount and (on setup) credential id + PEM key. */
	public static function parse_auth_data( $bin, $with_cred ) {
		if ( strlen( $bin ) < 37 ) {
			throw new Exception( 'short' );
		}
		$out = array(
			'rpIdHash'  => substr( $bin, 0, 32 ),
			'flags'     => ord( $bin[32] ),
			'signCount' => unpack( 'N', substr( $bin, 33, 4 ) )[1],
		);
		if ( $with_cred ) {
			if ( ! ( $out['flags'] & 0x40 ) || strlen( $bin ) < 55 ) {
				throw new Exception( 'no cred' );
			}
			$len           = unpack( 'n', substr( $bin, 53, 2 ) )[1];
			$out['credId'] = substr( $bin, 55, $len );
			$out['pem']    = self::cose_to_pem( self::cbor_decode( substr( $bin, 55 + $len ) ) );
		}
		return $out;
	}

	/** COSE public key (ES256 or RS256) to PEM. */
	public static function cose_to_pem( $k ) {
		$kty = $k[1] ?? null;
		if ( 2 === $kty && isset( $k[-2], $k[-3] ) && 32 === strlen( $k[-2] ) && 32 === strlen( $k[-3] ) ) {
			$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . "\x04" . $k[-2] . $k[-3];
		} elseif ( 3 === $kty && isset( $k[-1], $k[-2] ) ) {
			$int = static function ( $b ) {
				if ( ord( $b[0] ) > 0x7f ) {
					$b = "\x00" . $b;
				}
				return "\x02" . self::der_len( strlen( $b ) ) . $b;
			};
			$seq = static function ( $b ) {
				return "\x30" . self::der_len( strlen( $b ) ) . $b;
			};
			$rsa = $seq( $int( $k[-1] ) . $int( $k[-2] ) );
			$bit = "\x03" . self::der_len( strlen( $rsa ) + 1 ) . "\x00" . $rsa;
			$der = $seq( hex2bin( '300d06092a864886f70d0101010500' ) . $bit );
		} else {
			return '';
		}
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	}

	private static function der_len( $n ) {
		if ( $n < 128 ) {
			return chr( $n );
		}
		$b = ltrim( pack( 'N', $n ), "\x00" );
		return chr( 0x80 | strlen( $b ) ) . $b;
	}

	/** Minimal CBOR decoder (enough for WebAuthn): decodes the first item. */
	public static function cbor_decode( $bin ) {
		$pos = 0;
		return self::cbor_item( $bin, $pos );
	}

	private static function cbor_item( $b, &$p ) {
		if ( $p >= strlen( $b ) ) {
			throw new Exception( 'eof' );
		}
		$ib = ord( $b[ $p++ ] );
		$mt = $ib >> 5;
		$ai = $ib & 0x1f;
		if ( $ai < 24 ) {
			$len = $ai;
		} elseif ( 24 === $ai ) {
			$len = ord( $b[ $p ] );
			$p  += 1;
		} elseif ( 25 === $ai ) {
			$len = unpack( 'n', substr( $b, $p, 2 ) )[1];
			$p  += 2;
		} elseif ( 26 === $ai ) {
			$len = unpack( 'N', substr( $b, $p, 4 ) )[1];
			$p  += 4;
		} elseif ( 27 === $ai ) {
			$len = unpack( 'J', substr( $b, $p, 8 ) )[1];
			$p  += 8;
		} else {
			throw new Exception( 'unsupported' );
		}
		switch ( $mt ) {
			case 0:
				return $len;
			case 1:
				return -1 - $len;
			case 2:
			case 3:
				$v  = substr( $b, $p, $len );
				$p += $len;
				return $v;
			case 4:
				$a = array();
				for ( $i = 0; $i < $len; $i++ ) {
					$a[] = self::cbor_item( $b, $p );
				}
				return $a;
			case 5:
				$m = array();
				for ( $i = 0; $i < $len; $i++ ) {
					$k       = self::cbor_item( $b, $p );
					$m[ $k ] = self::cbor_item( $b, $p );
				}
				return $m;
			case 7:
				return 20 === $ai ? false : ( 21 === $ai ? true : null );
		}
		throw new Exception( 'type' );
	}
}
