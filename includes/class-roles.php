<?php
/**
 * Capabilities and the POS Cashier role.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_Roles {

	const CAP_SELL      = 'prdrwndn_pos_sell';      // Use the POS: scan, sell, look up products.
	const CAP_INVENTORY = 'prdrwndn_pos_inventory'; // Count and receive stock.
	const CAP_LABELS    = 'prdrwndn_pos_labels';    // Print barcode labels.
	const CAP_MANAGE    = 'prdrwndn_pos_manage';    // SKUs, settings, prices, refunds.

	const CASHIER_ROLE = 'prdrwndn_cashier';

	/**
	 * All capabilities this plugin adds.
	 *
	 * @return string[]
	 */
	public static function all_caps() {
		return array( self::CAP_SELL, self::CAP_INVENTORY, self::CAP_LABELS, self::CAP_MANAGE );
	}

	/**
	 * Give owners every capability and create the Cashier role.
	 */
	public static function install() {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all_caps() as $cap ) {
				$role->add_cap( $cap );
			}
		}

		if ( ! get_role( self::CASHIER_ROLE ) ) {
			add_role(
				self::CASHIER_ROLE,
				__( 'POS Cashier', 'prdrwndn-pos' ),
				array(
					'read'              => true,
					self::CAP_SELL      => true,
					self::CAP_INVENTORY => true,
				)
			);
		}
	}

	/**
	 * Remove the role and capabilities (used on uninstall).
	 */
	public static function uninstall() {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( self::all_caps() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		remove_role( self::CASHIER_ROLE );
	}
}
