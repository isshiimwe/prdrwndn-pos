<?php
/**
 * Plugin Name:       PRDRWNDN POS
 * Plugin URI:        https://github.com/isshiimwe/prdrwndn-pos
 * Description:       In-person point of sale (installable app at /pos), barcodes and labels for the PRDRWNDN store. Uses WooCommerce stock, so the website and in-person sales share one count.
 * Version:           0.4.2
 * Author:            PRDRWNDN
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.2
 * Text Domain:       prdrwndn-pos
 * License:           GPL-2.0-or-later
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

define( 'PRDRWNDN_POS_VERSION', '0.4.2' );
define( 'PRDRWNDN_POS_FILE', __FILE__ );
define( 'PRDRWNDN_POS_BASENAME', plugin_basename( __FILE__ ) );
define( 'PRDRWNDN_POS_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRDRWNDN_POS_URL', plugin_dir_url( __FILE__ ) );

require_once PRDRWNDN_POS_DIR . 'includes/class-roles.php';
require_once PRDRWNDN_POS_DIR . 'includes/class-updater.php';

register_activation_hook(
	__FILE__,
	static function () {
		PRDRWNDN_POS_Roles::install();
		update_option( 'prdrwndn_pos_flush', 1 ); // Add the /pos address on the next page load.
	}
);
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

// Updates keep working even if WooCommerce is switched off.
PRDRWNDN_POS_Updater::init();

// Declare support for WooCommerce's order storage (HPOS); the POS will create orders.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'PRDRWNDN POS needs WooCommerce to be active.', 'prdrwndn-pos' ) . '</p></div>';
				}
			);
			return;
		}

		require_once PRDRWNDN_POS_DIR . 'includes/class-upc.php';
		require_once PRDRWNDN_POS_DIR . 'includes/class-lookup.php';
		require_once PRDRWNDN_POS_DIR . 'includes/class-sku-builder.php';
		require_once PRDRWNDN_POS_DIR . 'includes/class-qz.php';
		require_once PRDRWNDN_POS_DIR . 'includes/class-rest.php';
		require_once PRDRWNDN_POS_DIR . 'includes/class-pos-sales.php';
		require_once PRDRWNDN_POS_DIR . 'includes/class-pos-app.php';
		require_once PRDRWNDN_POS_DIR . 'includes/class-google.php';

		// Re-run setup after an update (activation hooks do not run on updates).
		if ( get_option( 'prdrwndn_pos_version' ) !== PRDRWNDN_POS_VERSION ) {
			PRDRWNDN_POS_Roles::install();
			update_option( 'prdrwndn_pos_flush', 1 );
			update_option( 'prdrwndn_pos_version', PRDRWNDN_POS_VERSION );
		}

		PRDRWNDN_POS_REST::init();
		PRDRWNDN_POS_Sales::init();
		PRDRWNDN_POS_App::init();

		if ( is_admin() ) {
			require_once PRDRWNDN_POS_DIR . 'admin/class-admin.php';
			PRDRWNDN_POS_Admin::init();
		}
	}
);
