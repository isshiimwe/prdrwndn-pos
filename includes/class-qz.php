<?php
/**
 * QZ Tray certificate and signing, so labels print without a permission prompt.
 *
 * The owner creates a certificate in QZ Tray (Advanced > Site Manager > + > Create New),
 * which QZ Tray trusts on that computer, then pastes the certificate and private key
 * into the plugin settings. The key never leaves the server: the browser asks the
 * server to sign each QZ request.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_QZ {

	const OPT_CERT = 'prdrwndn_pos_qz_cert';
	const OPT_KEY  = 'prdrwndn_pos_qz_key';

	public static function certificate() {
		return (string) get_option( self::OPT_CERT, '' );
	}

	public static function has_key() {
		return '' !== (string) get_option( self::OPT_KEY, '' );
	}

	/**
	 * Silent printing is ready when both halves are saved.
	 *
	 * @return bool
	 */
	public static function ready() {
		return '' !== self::certificate() && self::has_key();
	}

	/**
	 * Save a certificate and (optionally) a new private key after checking they match.
	 *
	 * @param string $cert PEM certificate.
	 * @param string $key  PEM private key, or '' to keep the saved one.
	 * @return true|WP_Error
	 */
	public static function save( $cert, $key ) {
		$cert = trim( (string) $cert );
		$key  = trim( (string) $key );

		if ( '' === $cert ) {
			delete_option( self::OPT_CERT );
			delete_option( self::OPT_KEY );
			return true;
		}
		if ( ! function_exists( 'openssl_x509_read' ) ) {
			return new WP_Error( 'no_openssl', __( 'This server is missing the OpenSSL extension, so silent printing cannot be set up.', 'prdrwndn-pos' ) );
		}
		$x509 = @openssl_x509_read( $cert ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $x509 ) {
			return new WP_Error( 'bad_cert', __( 'The certificate could not be read. Paste the whole digital-certificate.txt, including the BEGIN and END lines.', 'prdrwndn-pos' ) );
		}
		$check_key = '' !== $key ? $key : self::private_key();
		if ( '' !== $check_key ) {
			$pkey = @openssl_pkey_get_private( $check_key ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( ! $pkey ) {
				return new WP_Error( 'bad_key', __( 'The private key could not be read. Paste the whole private-key.pem, including the BEGIN and END lines.', 'prdrwndn-pos' ) );
			}
			if ( ! openssl_x509_check_private_key( $x509, $pkey ) ) {
				return new WP_Error( 'mismatch', __( 'The certificate and private key do not belong together. Copy both from the same QZ Tray Site Manager entry.', 'prdrwndn-pos' ) );
			}
		}
		update_option( self::OPT_CERT, $cert, false );
		if ( '' !== $key ) {
			update_option( self::OPT_KEY, self::seal( $key ), false );
		}
		return true;
	}

	/**
	 * Sign a QZ Tray request with SHA-512.
	 *
	 * @param string $request Text QZ Tray asks us to sign.
	 * @return string|WP_Error Base64 signature.
	 */
	public static function sign( $request ) {
		$key = self::private_key();
		if ( '' === $key ) {
			return new WP_Error( 'no_key', __( 'Silent printing is not set up yet.', 'prdrwndn-pos' ), array( 'status' => 400 ) );
		}
		$pkey = openssl_pkey_get_private( $key );
		if ( ! $pkey || ! openssl_sign( (string) $request, $signature, $pkey, OPENSSL_ALGO_SHA512 ) ) {
			return new WP_Error( 'sign_failed', __( 'Could not sign the print request.', 'prdrwndn-pos' ), array( 'status' => 500 ) );
		}
		return base64_encode( $signature ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	private static function private_key() {
		$stored = (string) get_option( self::OPT_KEY, '' );
		return '' === $stored ? '' : self::open( $stored );
	}

	/**
	 * Encrypt the key at rest with a key derived from this site's salts,
	 * so a copied database alone does not expose it.
	 */
	private static function seal( $plain ) {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return 'plain:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::box_key() );
		return 'sodium:' . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	private static function open( $stored ) {
		if ( 0 === strpos( $stored, 'plain:' ) ) {
			return (string) base64_decode( substr( $stored, 6 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		if ( 0 === strpos( $stored, 'sodium:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$raw   = base64_decode( substr( $stored, 7 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( $box, $nonce, self::box_key() );
			return false === $plain ? '' : $plain;
		}
		return '';
	}

	private static function box_key() {
		return hash( 'sha256', wp_salt( 'auth' ) . '|prdrwndn-pos-qz', true );
	}
}
