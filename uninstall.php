<?php
/**
 * Clean up when the plugin is deleted. SKUs and UPCs stay on your products.
 *
 * @package PRDRWNDN_POS
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-roles.php';
PRDRWNDN_POS_Roles::uninstall();

foreach ( array( 'prdrwndn_pos_settings', 'prdrwndn_pos_qz_cert', 'prdrwndn_pos_qz_key', 'prdrwndn_pos_version', 'prdrwndn_pos_google', 'prdrwndn_pos_deals' ) as $opt ) {
	delete_option( $opt );
}
delete_site_transient( 'prdrwndn_pos_release' );
delete_transient( 'prdrwndn_pos_google_certs' );
delete_metadata( 'user', 0, 'prdrwndn_pos_google_sub', '', true );
