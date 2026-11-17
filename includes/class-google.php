<?php
/**
 * Sign in with Google for the POS.
 *
 * Uses the Google Client ID that Site Kit's "Sign in with Google" already set
 * up (or one pasted in POS settings), so one Google setup covers the website
 * and the POS. Google sends the person back to /pos/ itself, which keeps the
 * installed app on its own page (pop-ups are unreliable in iPhone home-screen
 * apps).
 *
 * Google only lets someone in when their Google email matches a store account
 * that has POS access. It never creates accounts.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_Google {

	const OPT          = 'prdrwndn_pos_google';
	const SITEKIT_OPT  = 'googlesitekit_sign-in-with-google_settings';
	const SITEKIT_META = 'googlesitekitpersistent_siwg_google_user_hid';
	const LINK_META    = 'prdrwndn_pos_google_sub';
	const CERTS_URL    = 'https://www.googleapis.com/oauth2/v1/certs';
	const CERTS_CACHE  = 'prdrwndn_pos_google_certs';
	const COOKIE       = 'prdpos_gnonce';
	const ISSUERS      = array( 'accounts.google.com', 'https://accounts.google.com' );

	public static function settings() {
		return wp_parse_args(
			(array) get_option( self::OPT, array() ),
			array(
				'enabled'   => 1,
				'client_id' => '',
			)
		);
	}

	public static function save( $enabled, $client_id ) {
		$client_id = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $client_id );
		update_option(
			self::OPT,
			array(
				'enabled'   => $enabled ? 1 : 0,
				'client_id' => $client_id,
			),
			false
		);
	}

	public static function sitekit_client_id() {
		$s = get_option( self::SITEKIT_OPT );
		return is_array( $s ) && ! empty( $s['clientID'] ) ? trim( (string) $s['clientID'] ) : '';
	}

	/** Client ID in use: the one pasted in POS settings, otherwise Site Kit's. */
	public static function client_id() {
		$own = trim( (string) self::settings()['client_id'] );
		return '' !== $own ? $own : self::sitekit_client_id();
	}

	/** 'own', 'sitekit' or '' when Google sign-in isn't set up. */
	public static function source() {
		if ( '' !== trim( (string) self::settings()['client_id'] ) ) {
			return 'own';
		}
		return '' !== self::sitekit_client_id() ? 'sitekit' : '';
	}

	public static function enabled() {
		return ! empty( self::settings()['enabled'] ) && '' !== self::client_id() && function_exists( 'openssl_verify' );
	}

	/** The exact address to add under "Authorized redirect URIs" in Google Cloud. */
	public static function redirect_uri() {
		return PRDRWNDN_POS_App::url();
	}

	/**
	 * Starts a sign-in: a one-time code Google puts inside its answer, kept in
	 * a cookie on this device so the answer can't be replayed elsewhere.
	 */
	public static function start( $cookie_path ) {
		$nonce = bin2hex( random_bytes( 16 ) );
		self::cookie( $nonce, time() + 15 * MINUTE_IN_SECONDS, $cookie_path );
		return $nonce;
	}

	private static function cookie( $value, $expires, $path ) {
		if ( headers_sent() ) {
			return;
		}
		// Google sends the person back with a POST from its own site, so the
		// cookie must be allowed on that cross-site return (needs HTTPS).
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => $path,
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => is_ssl() ? 'None' : 'Lax',
			)
		);
	}

	/**
	 * Handles Google's POST back to /pos/.
	 *
	 * @return WP_User|WP_Error
	 */
	public static function sign_in( $cookie_path ) {
		// phpcs:disable WordPress.Security.NonceVerification -- Google's POST; checked with the one-time code below.
		$credential = isset( $_POST['credential'] ) ? (string) wp_unslash( $_POST['credential'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$post_csrf  = isset( $_POST['g_csrf_token'] ) ? (string) wp_unslash( $_POST['g_csrf_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		$cookie_csrf = isset( $_COOKIE['g_csrf_token'] ) ? (string) wp_unslash( $_COOKIE['g_csrf_token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$expected    = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Each code works once.
		self::cookie( '', time() - HOUR_IN_SECONDS, $cookie_path );

		if ( ! self::enabled() ) {
			return new WP_Error( 'off', '' );
		}
		if ( '' === $credential ) {
			return new WP_Error( 'invalid', '' );
		}
		// Google's own check: the token in the form must match its cookie, when both are there.
		if ( '' !== $post_csrf && '' !== $cookie_csrf && ! hash_equals( $cookie_csrf, $post_csrf ) ) {
			return new WP_Error( 'invalid', '' );
		}

		$payload = self::verify( $credential, self::client_id() );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		if ( '' === $expected || empty( $payload['nonce'] ) || ! hash_equals( $expected, (string) $payload['nonce'] ) ) {
			return new WP_Error( 'expired', '' );
		}
		$email = isset( $payload['email'] ) ? sanitize_email( $payload['email'] ) : '';
		$verified = $payload['email_verified'] ?? false;
		if ( '' === $email || ( true !== $verified && 'true' !== $verified ) ) {
			return new WP_Error( 'unverified', $email );
		}

		$user = self::find_user( $payload, $email );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		if ( ! user_can( $user, PRDRWNDN_POS_Roles::CAP_SELL ) ) {
			return new WP_Error( 'no_access', $email );
		}

		// Remember the Google account, so it still works if the email changes later.
		update_user_meta( $user->ID, self::LINK_META, (string) $payload['sub'] );

		wp_set_current_user( $user->ID, $user->user_login );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		return $user;
	}

	/**
	 * Finds the store account for a Google account: one already linked (here
	 * or by Site Kit), otherwise the single account with that email.
	 */
	private static function find_user( $payload, $email ) {
		global $wpdb;
		$sub = (string) ( $payload['sub'] ?? '' );
		if ( '' === $sub ) {
			return new WP_Error( 'invalid', '' );
		}

		$linked = get_users(
			array(
				'meta_key'   => self::LINK_META, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $sub, // phpcs:ignore WordPress.DB.SlowDBQuery
				'number'     => 1,
			)
		);
		if ( ! $linked ) {
			$linked = get_users(
				array(
					'meta_key'   => $wpdb->get_blog_prefix() . self::SITEKIT_META, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value' => md5( $sub ), // phpcs:ignore WordPress.DB.SlowDBQuery
					'number'     => 1,
				)
			);
		}
		if ( $linked ) {
			return $linked[0];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_email = %s LIMIT 2", $email ) );
		if ( ! $ids ) {
			return new WP_Error( 'no_account', $email );
		}
		if ( count( $ids ) > 1 ) {
			return new WP_Error( 'duplicate', $email );
		}
		$user = get_user_by( 'id', (int) $ids[0] );
		if ( ! $user ) {
			return new WP_Error( 'no_account', $email );
		}
		// Linking by email would skip a two-step sign-in this person set up.
		if ( class_exists( 'Two_Factor_Core' ) && \Two_Factor_Core::is_user_using_two_factor( $user->ID ) ) {
			return new WP_Error( 'two_factor', $email );
		}
		return $user;
	}

	/**
	 * Checks Google's signed ID token and returns its contents.
	 *
	 * @return array|WP_Error
	 */
	public static function verify( $jwt, $client_id, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$parts = explode( '.', (string) $jwt );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'invalid', '' );
		}
		$header  = json_decode( self::b64( $parts[0] ), true );
		$payload = json_decode( self::b64( $parts[1] ), true );
		$sig     = self::b64( $parts[2] );
		if ( ! is_array( $header ) || ! is_array( $payload ) || '' === $sig || 'RS256' !== ( $header['alg'] ?? '' ) || empty( $header['kid'] ) ) {
			return new WP_Error( 'invalid', '' );
		}

		$certs = self::certs();
		if ( ! isset( $certs[ $header['kid'] ] ) ) {
			$certs = self::certs( true ); // Google rotates its keys; fetch the new set once.
		}
		if ( ! $certs ) {
			return new WP_Error( 'unavailable', '' );
		}
		if ( ! isset( $certs[ $header['kid'] ] ) ) {
			return new WP_Error( 'invalid', '' );
		}
		$key = openssl_pkey_get_public( $certs[ $header['kid'] ] );
		if ( ! $key || 1 !== openssl_verify( $parts[0] . '.' . $parts[1], $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
			return new WP_Error( 'invalid', '' );
		}

		$aud = $payload['aud'] ?? '';
		if ( ! in_array( $payload['iss'] ?? '', self::ISSUERS, true ) || ! is_string( $aud ) || '' === $client_id || ! hash_equals( $client_id, $aud ) ) {
			return new WP_Error( 'invalid', '' );
		}
		$skew = 5 * MINUTE_IN_SECONDS;
		if ( (int) ( $payload['exp'] ?? 0 ) < $now - $skew || (int) ( $payload['iat'] ?? 0 ) > $now + $skew ) {
			return new WP_Error( 'expired', '' );
		}
		return $payload;
	}

	/** Google's current signing certificates, by key id, cached as long as Google allows. */
	private static function certs( $refresh = false ) {
		$cached = get_transient( self::CERTS_CACHE );
		if ( is_array( $cached ) && $cached && ! $refresh ) {
			return $cached;
		}
		// Don't ask Google more than once every 5 minutes for a fresh set.
		if ( $refresh && get_transient( self::CERTS_CACHE . '_tried' ) ) {
			return is_array( $cached ) ? $cached : array();
		}
		set_transient( self::CERTS_CACHE . '_tried', 1, 5 * MINUTE_IN_SECONDS );

		$res = wp_remote_get( self::CERTS_URL, array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return is_array( $cached ) ? $cached : array();
		}
		$certs = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $certs ) || ! $certs ) {
			return is_array( $cached ) ? $cached : array();
		}
		$ttl = HOUR_IN_SECONDS;
		if ( preg_match( '/max-age=(\d+)/', (string) wp_remote_retrieve_header( $res, 'cache-control' ), $m ) ) {
			$ttl = max( 5 * MINUTE_IN_SECONDS, min( DAY_IN_SECONDS, (int) $m[1] ) );
		}
		set_transient( self::CERTS_CACHE, $certs, $ttl );
		return $certs;
	}

	private static function b64( $s ) {
		$s = strtr( (string) $s, '-_', '+/' );
		$d = base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
		return false === $d ? '' : $d;
	}

	/** What to tell the person when Google sign-in didn't let them in. */
	public static function message( WP_Error $e ) {
		$email = (string) $e->get_error_message();
		switch ( $e->get_error_code() ) {
			case 'no_account':
				/* translators: %s: email address */
				return sprintf( __( 'No store account uses %s. Ask the owner to add you as a POS Cashier with this email.', 'prdrwndn-pos' ), $email );
			case 'no_access':
				/* translators: %s: email address */
				return sprintf( __( '%s has a store account, but it can\'t use the POS. Ask the owner to make it a POS Cashier.', 'prdrwndn-pos' ), $email );
			case 'duplicate':
				return __( 'More than one store account uses this email. Sign in with your username and password instead.', 'prdrwndn-pos' );
			case 'two_factor':
				return __( 'This account uses two-step sign-in. Sign in with your username and password instead.', 'prdrwndn-pos' );
			case 'unverified':
				return __( 'Google hasn\'t confirmed this email address yet. Sign in with your username and password instead.', 'prdrwndn-pos' );
			case 'unavailable':
				return __( 'Couldn\'t reach Google to check the sign-in. Try again, or use your password.', 'prdrwndn-pos' );
			case 'off':
				return __( 'Sign in with Google is turned off. Use your username and password.', 'prdrwndn-pos' );
			default:
				return __( 'Google sign-in didn\'t go through. Try again.', 'prdrwndn-pos' );
		}
	}
}
