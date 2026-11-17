<?php
/**
 * REST routes used by the label screen now and the POS app later.
 *
 * All routes use the logged-in WordPress session (cookie + nonce),
 * so no API keys live in the browser.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_REST {

	const NS = 'prdrwndn-pos/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/lookup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'lookup' ),
				'permission_callback' => static function () {
					return current_user_can( PRDRWNDN_POS_Roles::CAP_SELL ) || current_user_can( PRDRWNDN_POS_Roles::CAP_LABELS );
				},
				'args'                => array(
					'code' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/qz/certificate',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => static function () {
					return rest_ensure_response( array( 'certificate' => PRDRWNDN_POS_QZ::certificate() ) );
				},
				'permission_callback' => array( __CLASS__, 'can_print' ),
			)
		);

		register_rest_route(
			self::NS,
			'/qz/sign',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'sign' ),
				'permission_callback' => array( __CLASS__, 'can_print' ),
				'args'                => array(
					'request' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public static function can_print() {
		return current_user_can( PRDRWNDN_POS_Roles::CAP_LABELS );
	}

	public static function lookup( WP_REST_Request $req ) {
		$id = PRDRWNDN_POS_Lookup::find_id( $req->get_param( 'code' ) );
		if ( ! $id ) {
			return new WP_Error( 'not_found', __( 'No product has this code.', 'prdrwndn-pos' ), array( 'status' => 404 ) );
		}
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return new WP_Error( 'not_found', __( 'No product has this code.', 'prdrwndn-pos' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( PRDRWNDN_POS_Lookup::product_data( $product ) );
	}

	public static function sign( WP_REST_Request $req ) {
		$sig = PRDRWNDN_POS_QZ::sign( $req->get_param( 'request' ) );
		if ( is_wp_error( $sig ) ) {
			return $sig;
		}
		return rest_ensure_response( array( 'signature' => $sig ) );
	}
}
