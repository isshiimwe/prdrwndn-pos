<?php
/**
 * Jersey Stock: the Izuba and Imigongo tracker, inside the POS (Stock tab).
 * Only administrators can see or change it.
 *
 *  1. Settings       sizes, designs, channels, defaults
 *  2. Storage        three options, not autoloaded: config, sales, costs
 *  3. Website link   finds the Izuba and Imigongo size variations in the shop
 *  4. Orders         WooCommerce orders that took jersey stock (website and POS)
 *  5. Sync           sets each size's website stock to what's free here
 *  6. Changes        one apply() for every change
 *  7. REST           GET/POST prdrwndn-pos/v1/stock
 *
 * Free to sell = made - sold - on hold - taken by orders.
 * With website sync on, every change (and every load of the Stock tab) sets
 * each size variation's stock to that number, so neither the website nor the
 * POS can sell a jersey that is held for pickup or was sold elsewhere.
 *
 * Storage keys match the earlier theme version (prd_jersey_*), so nothing
 * moves if that ever ran.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_Stock {

	const SIZES    = array( 'S', 'M', 'L', 'XL', '2XL', '3XL' );
	const DESIGNS  = array(
		'izuba'    => 'Izuba',
		'imigongo' => 'Imigongo',
	);
	const CHANNELS = array( 'Presale site', 'TikTok Shop', 'WhatsApp', 'Instagram', 'In person', 'Other' );
	const PAYMENTS = array( 'Cash', 'Zelle', 'Apple Pay', 'Cash App', 'Other', 'Card', 'TikTok Shop' );
	const STATUSES = array( 'Picked up', 'To ship', 'On hold', 'Shipped' );

	/* ================================================================== */
	/* 1. Settings                                                         */
	/* ================================================================== */

	/** Who can see the Stock tab: administrators. */
	public static function cap() {
		return (string) apply_filters( 'prdrwndn_pos_stock_cap', 'manage_options' );
	}

	public static function can() {
		return is_user_logged_in() && current_user_can( self::cap() );
	}

	public static function default_config() {
		$run = array(
			'S'   => 2,
			'M'   => 5,
			'L'   => 8,
			'XL'  => 5,
			'2XL' => 3,
			'3XL' => 2,
		);
		return array(
			'made'   => array(
				'izuba'    => $run,
				'imigongo' => $run,
			),
			'prices' => array(
				'presale' => 45,
				'regular' => 50,
				'bundle'  => self::pos_bundle_price(),
			),
			'fees'   => array(
				'Presale site' => array(
					'pct'   => 2.9,
					'fixed' => 0.3,
				),
				'TikTok Shop'  => array(
					'pct'   => 6,
					'fixed' => 0,
				),
				'Website'      => array(
					'pct'   => 2.9,
					'fixed' => 0.3,
				),
			),
			'note'   => 'First run: 25 of each design',
		);
	}

	/** The POS "2 jerseys for $X" deal price, if one is set up. */
	private static function pos_bundle_price() {
		$cats = self::jersey_cat_ids();
		foreach ( PRDRWNDN_POS_Sales::deals() as $d ) {
			if ( 2 === (int) $d['qty'] && ( in_array( (int) $d['cat'], $cats, true ) || false !== stripos( $d['label'], 'jersey' ) ) ) {
				return (float) $d['price'];
			}
		}
		return 80;
	}

	/* ================================================================== */
	/* 2. Storage                                                          */
	/* ================================================================== */

	private static function get( $key, $fallback ) {
		wp_cache_delete( 'prd_jersey_' . $key, 'options' );
		$v = get_option( 'prd_jersey_' . $key, null );
		return is_array( $v ) ? $v : $fallback;
	}

	private static function put( $key, $value ) {
		update_option( 'prd_jersey_' . $key, $value, false );
	}

	public static function config() {
		$c           = self::get( 'config', array() );
		$d           = self::default_config();
		$c           = array_merge( $d, $c );
		$c['prices'] = array_merge( $d['prices'], (array) $c['prices'] );
		$c['fees']   = array_merge( $d['fees'], (array) $c['fees'] );
		return $c;
	}

	/** One change at a time, so two phones can't both sell the last XL. */
	public static function lock( $on ) {
		global $wpdb;
		if ( ! ( $wpdb instanceof wpdb ) || ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) ) {
			return;
		}
		$was = $wpdb->suppress_errors( true );
		$wpdb->get_var( $on ? "SELECT GET_LOCK('prd_jersey_stock', 8)" : "SELECT RELEASE_LOCK('prd_jersey_stock')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( $was );
	}

	private static function new_id() {
		return strtolower( wp_generate_password( 16, false, false ) );
	}

	/** Clean one sale or hold. Returns the record or WP_Error. */
	public static function clean_sale( $r ) {
		if ( ! is_array( $r ) ) {
			return new WP_Error( 'bad', 'Something in that sale is missing.' );
		}
		$out           = array();
		$out['design'] = (string) ( $r['design'] ?? '' );
		if ( ! isset( self::DESIGNS[ $out['design'] ] ) ) {
			return new WP_Error( 'bad', 'Pick Izuba or Imigongo.' );
		}
		$out['size'] = (string) ( $r['size'] ?? '' );
		if ( ! in_array( $out['size'], self::SIZES, true ) ) {
			return new WP_Error( 'bad', 'Pick a size.' );
		}
		$out['qty'] = (int) ( $r['qty'] ?? 0 );
		if ( $out['qty'] < 1 || $out['qty'] > 10 ) {
			return new WP_Error( 'bad', 'How many must be between 1 and 10.' );
		}
		$price = (float) ( $r['price'] ?? -1 );
		if ( $price < 0 || $price > 10000 ) {
			return new WP_Error( 'bad', 'Enter the price in dollars.' );
		}
		$out['price']   = round( $price, 2 );
		$out['channel'] = (string) ( $r['channel'] ?? '' );
		if ( ! in_array( $out['channel'], self::CHANNELS, true ) ) {
			$out['channel'] = 'Other';
		}
		$pay = (string) ( $r['payment'] ?? '' );
		if ( '' !== $pay && in_array( $pay, self::PAYMENTS, true ) ) {
			$out['payment'] = $pay;
		}
		$out['customer'] = mb_substr( sanitize_text_field( (string) ( $r['customer'] ?? '' ) ), 0, 80 );
		$out['status']   = (string) ( $r['status'] ?? '' );
		if ( ! in_array( $out['status'], self::STATUSES, true ) ) {
			return new WP_Error( 'bad', 'Pick what happened.' );
		}
		if ( 'On hold' === $out['status'] && '' === $out['customer'] ) {
			return new WP_Error( 'bad', "Add who it's held for." );
		}
		foreach ( array( 'date', 'heldDate' ) as $k ) {
			$v = (string) ( $r[ $k ] ?? '' );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
				$out[ $k ] = $v;
			}
		}
		if ( empty( $out['date'] ) ) {
			$out['date'] = current_time( 'Y-m-d' );
		}
		$b = (string) ( $r['bundleId'] ?? '' );
		if ( '' !== $b && preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $b ) ) {
			$out['bundleId'] = $b;
		}
		$at               = (float) ( $r['createdAt'] ?? 0 );
		$out['createdAt'] = $at > 0 ? $at : round( microtime( true ) * 1000 );
		return $out;
	}

	/* ================================================================== */
	/* 3. Website link                                                     */
	/* ================================================================== */

	public static function norm_size( $v ) {
		$s     = strtoupper( preg_replace( '/[^a-z0-9]/i', '', (string) $v ) );
		$alias = array(
			'SMALL'  => 'S',
			'MEDIUM' => 'M',
			'LARGE'  => 'L',
			'XLARGE' => 'XL',
			'XXL'    => '2XL',
			'2X'     => '2XL',
			'XXXL'   => '3XL',
			'3X'     => '3XL',
		);
		if ( isset( $alias[ $s ] ) ) {
			$s = $alias[ $s ];
		}
		return in_array( $s, self::SIZES, true ) ? $s : null;
	}

	/** Category IDs of the jersey products (for deals and order discounts). */
	private static function jersey_cat_ids() {
		$ids = array();
		foreach ( self::links()['products'] as $p ) {
			$ids = array_merge( $ids, $p['cats'] );
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * The jersey products in the shop and their size variations.
	 *
	 * @return array map [design][size] => variation id, products [design] => info, issues string[]
	 */
	public static function links( $fresh = false ) {
		static $cache = null;
		if ( null !== $cache && ! $fresh ) {
			return $cache;
		}
		$out = array(
			'map'      => array(),
			'products' => array(),
			'issues'   => array(),
		);
		if ( ! function_exists( 'wc_get_products' ) ) {
			$out['issues'][] = 'WooCommerce is switched off, so the website stock is not linked.';
			$cache           = $out;
			return $cache;
		}
		// The jersey category set in the theme (Customizer > Jerseys & deals), else "jerseys".
		$cat   = function_exists( 'prd_mod' ) ? sanitize_title( (string) prd_mod( 'prd_jersey_cat' ) ) : '';
		$args  = array(
			'limit'    => 30,
			'status'   => array( 'publish', 'private', 'draft', 'pending' ),
			'type'     => 'variable',
			'category' => array( '' !== $cat ? $cat : 'jerseys' ),
		);
		$found = wc_get_products( $args );
		if ( ! $found ) {
			unset( $args['category'] );
			$found = wc_get_products( $args );
		}
		foreach ( $found as $p ) {
			$hay    = strtolower( $p->get_name() . ' ' . $p->get_slug() );
			$design = null;
			foreach ( self::DESIGNS as $id => $name ) {
				if ( false !== strpos( $hay, $id ) ) {
					$design = $id;
					break;
				}
			}
			if ( ! $design ) {
				continue;
			}
			if ( isset( $out['products'][ $design ] ) ) {
				$out['issues'][] = 'There is more than one ' . self::DESIGNS[ $design ] . ' jersey product. Using "' . $out['products'][ $design ]['name'] . '".';
				continue;
			}
			$out['products'][ $design ] = array(
				'id'   => $p->get_id(),
				'name' => wp_strip_all_tags( $p->get_name() ),
				'url'  => get_permalink( $p->get_id() ),
				'cats' => array_map( 'intval', $p->get_category_ids() ),
			);
			foreach ( $p->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v ) {
					continue;
				}
				$size = null;
				foreach ( (array) $v->get_attributes() as $val ) {
					$size = self::norm_size( $val );
					if ( $size ) {
						break;
					}
				}
				if ( $size && ! isset( $out['map'][ $design ][ $size ] ) ) {
					$out['map'][ $design ][ $size ] = (int) $vid;
				}
			}
		}
		foreach ( self::DESIGNS as $id => $name ) {
			if ( empty( $out['products'][ $id ] ) ) {
				$out['issues'][] = 'No ' . $name . ' jersey product with sizes was found in the shop.';
				continue;
			}
			$missing = array_values( array_diff( self::SIZES, array_keys( $out['map'][ $id ] ?? array() ) ) );
			if ( $missing ) {
				$out['issues'][] = $name . ' has no ' . implode( ', ', $missing ) . ' option in the shop, so those sizes are tracked here only.';
			}
		}
		$cache = $out;
		return $cache;
	}

	/** Current website stock per size: int, or null when the size doesn't track stock. */
	private static function store_levels( $links ) {
		$out = array();
		foreach ( $links['map'] as $d => $sizes ) {
			foreach ( $sizes as $s => $vid ) {
				$v               = wc_get_product( $vid );
				$out[ $d ][ $s ] = ( $v && $v->get_manage_stock() ) ? (int) $v->get_stock_quantity() : null;
			}
		}
		return $out;
	}

	/* ================================================================== */
	/* 4. Orders that took jersey stock                                    */
	/* ================================================================== */

	/**
	 * Every order line that took jersey stock (WooCommerce's own record of
	 * what it took, less anything restocked by a refund). POS sales come in
	 * as in-person sales; cancelled orders give their stock back and drop out.
	 */
	public static function order_rows( $links ) {
		$rows   = array();
		$by_vid = array();
		foreach ( $links['map'] as $d => $sizes ) {
			foreach ( $sizes as $s => $vid ) {
				$by_vid[ $vid ] = array( $d, $s );
			}
		}
		if ( ! $by_vid || ! function_exists( 'wc_get_orders' ) ) {
			return $rows;
		}
		$config    = self::config();
		$fee_rule  = $config['fees']['Website'] ?? array(
			'pct'   => 0,
			'fixed' => 0,
		);
		$jersey_cats = self::jersey_cat_ids();

		$orders = wc_get_orders(
			array(
				'limit'   => 500,
				'type'    => 'shop_order',
				'status'  => array_keys( wc_get_order_statuses() ),
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		foreach ( $orders as $order ) {
			$lines = array();
			$all   = 0.0;
			foreach ( $order->get_items() as $item_id => $item ) {
				$all += (float) $item->get_total();
				$vid  = (int) $item->get_variation_id();
				if ( ! isset( $by_vid[ $vid ] ) ) {
					continue;
				}
				$q = (int) $item->get_meta( '_reduced_stock', true ) - (int) $item->get_meta( '_restock_refunded_items', true );
				if ( $q <= 0 ) {
					continue;
				}
				$qty     = max( 1, (int) $item->get_quantity() );
				$lines[] = array(
					'id'    => $item_id,
					'd'     => $by_vid[ $vid ][0],
					's'     => $by_vid[ $vid ][1],
					'q'     => $q,
					'total' => (float) $item->get_total() * min( 1, $q / $qty ),
				);
			}
			if ( ! $lines ) {
				continue;
			}
			$is_pos = PRDRWNDN_POS_Sales::VIA === $order->get_created_via();
			$gross  = array_sum( array_column( $lines, 'total' ) );

			// Jersey deals (a negative fee) belong to the jerseys; a general discount is shared by everything in the order.
			$deal = 0.0;
			foreach ( $order->get_fees() as $f ) {
				$amt = (float) $f->get_total();
				if ( $amt >= 0 ) {
					continue;
				}
				$cat = (int) $f->get_meta( '_prdpos_deal_cat', true );
				if ( $cat ? in_array( $cat, $jersey_cats, true ) : false !== stripos( $f->get_name(), 'jersey' ) ) {
					$deal += $amt;
				} elseif ( $f->get_meta( '_prdpos_discount', true ) || ( $is_pos && ! $cat ) ) {
					$deal += $all > 0 ? $amt * min( 1, $gross / $all ) : 0;
				}
			}
			$net = max( 0, $gross + $deal );

			$st   = $order->get_status();
			$paid = in_array( $st, array( 'processing', 'completed' ), true );
			if ( $is_pos ) {
				$method  = str_replace( 'prdrwndn_pos_', '', (string) $order->get_payment_method() );
				$payment = PRDRWNDN_POS_Sales::METHODS[ $method ] ?? 'Other';
				$channel = 'In person';
				$status  = $paid ? 'Picked up' : 'Awaiting payment';
				$fee     = 0.0; // Cash, Zelle, Cash App, Apple Pay: no fees.
			} else {
				$pickup = false;
				foreach ( $order->get_shipping_methods() as $m ) {
					if ( false !== strpos( (string) $m->get_method_id(), 'pickup' ) ) {
						$pickup = true;
					}
				}
				$payment     = wp_strip_all_tags( (string) $order->get_payment_method_title() );
				$channel     = 'Website';
				$status      = ! $paid ? 'Awaiting payment' : ( 'completed' === $st ? ( $pickup ? 'Picked up' : 'Shipped' ) : ( $pickup ? 'To pick up' : 'To ship' ) );
				$order_total = (float) $order->get_total();
				$fee         = $net > 0 ? $net * (float) $fee_rule['pct'] / 100 + (float) $fee_rule['fixed'] * ( $order_total > 0 ? min( 1, $net / $order_total ) : 1 ) : 0;
			}
			$name = trim( $order->get_formatted_billing_full_name() );
			if ( '' === $name ) {
				$name = trim( $order->get_formatted_shipping_full_name() );
			}
			$date = $order->get_date_created();

			foreach ( $lines as $l ) {
				$share  = $gross > 0 ? $l['total'] / $gross : 0;
				$rows[] = array(
					'id'        => 'wc' . $l['id'],
					'web'       => true,
					'pos'       => $is_pos,
					'design'    => $l['d'],
					'size'      => $l['s'],
					'qty'       => $l['q'],
					'price'     => round( ( $l['total'] + $deal * $share ) / $l['q'], 2 ),
					'fee'       => round( $fee * $share, 2 ),
					'channel'   => $channel,
					'payment'   => $payment,
					'customer'  => wp_strip_all_tags( $name ),
					'date'      => $date ? $date->date_i18n( 'Y-m-d' ) : current_time( 'Y-m-d' ),
					'status'    => $status,
					'createdAt' => $date ? $date->getTimestamp() * 1000 : 0,
					'bundleId'  => count( $lines ) > 1 ? 'wc' . $order->get_id() : null,
					'orderNo'   => (string) $order->get_order_number(),
					'orderUrl'  => $order->get_edit_order_url(),
				);
			}
		}
		return $rows;
	}

	/* ================================================================== */
	/* 5. Sync                                                             */
	/* ================================================================== */

	public static function free( $config, $sales, $orders ) {
		$free = array();
		foreach ( array_keys( self::DESIGNS ) as $d ) {
			foreach ( self::SIZES as $s ) {
				$free[ $d ][ $s ] = (int) ( $config['made'][ $d ][ $s ] ?? 0 );
			}
		}
		foreach ( array_merge( array_values( $sales ), $orders ) as $r ) {
			if ( isset( $free[ $r['design'] ][ $r['size'] ] ) ) {
				$free[ $r['design'] ][ $r['size'] ] -= (int) $r['qty'];
			}
		}
		return $free;
	}

	public static function sync_on() {
		return (bool) get_option( 'prd_jersey_sync', false );
	}

	/** Set each size's website stock to what's free here. Returns what changed. */
	public static function push( $free, $links ) {
		$changed = array();
		$parents = array();
		foreach ( $links['map'] as $d => $sizes ) {
			foreach ( $sizes as $s => $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v ) {
					continue;
				}
				$target = max( 0, (int) ( $free[ $d ][ $s ] ?? 0 ) );
				$now    = $v->get_manage_stock() ? (int) $v->get_stock_quantity() : null;
				if ( $now === $target && 'no' === $v->get_backorders() ) {
					continue;
				}
				$v->set_manage_stock( true );
				$v->set_backorders( 'no' );
				$v->set_stock_quantity( $target );
				$v->save();
				$changed[]                      = array(
					'design' => $d,
					'size'   => $s,
					'from'   => $now,
					'to'     => $target,
				);
				$parents[ $v->get_parent_id() ] = true;
			}
		}
		foreach ( array_keys( $parents ) as $pid ) {
			if ( class_exists( 'WC_Product_Variable' ) ) {
				WC_Product_Variable::sync( $pid );
			}
			wc_delete_product_transients( $pid );
			do_action( 'litespeed_purge_post', $pid ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
		}
		return $changed;
	}

	/** Brings the website in line when sync is on (after a POS sale that cleared a hold, for example). */
	public static function resync() {
		if ( ! self::sync_on() ) {
			return array();
		}
		$links = self::links( true );
		return self::push( self::free( self::config(), self::get( 'sales', array() ), self::order_rows( $links ) ), $links );
	}

	/** Everything the Stock tab shows. Syncs the website first when sync is on. */
	public static function state() {
		$config  = self::config();
		$sales   = self::get( 'sales', array() );
		$costs   = self::get( 'costs', array() );
		$links   = self::links( true );
		$orders  = self::order_rows( $links );
		$free    = self::free( $config, $sales, $orders );
		$sync    = self::sync_on();
		$changed = $sync ? self::push( $free, $links ) : array();

		$list = array();
		foreach ( $sales as $id => $r ) {
			$r['id'] = (string) $id;
			$list[]  = $r;
		}
		$clist = array();
		foreach ( $costs as $id => $c ) {
			$c['id'] = (string) $id;
			$clist[] = $c;
		}
		$linked = array();
		foreach ( $links['products'] as $d => $p ) {
			$linked[ $d ] = array(
				'id'    => $p['id'],
				'name'  => $p['name'],
				'url'   => $p['url'],
				'sizes' => count( $links['map'][ $d ] ?? array() ),
			);
		}
		return array(
			'config' => $config,
			'sales'  => $list,
			'costs'  => $clist,
			'web'    => $orders,
			'store'  => array(
				'sync'    => $sync,
				'linked'  => $linked,
				'map'     => $links['map'],
				'issues'  => $links['issues'],
				'levels'  => self::store_levels( $links ),
				'changed' => $changed,
			),
			'today'  => current_time( 'Y-m-d' ),
		);
	}

	/* ================================================================== */
	/* 6. Changes                                                          */
	/* ================================================================== */

	/**
	 * Holds rung up in the POS: the sale now counts them, so the holds go.
	 * Only holds whose jersey is in the order are cleared.
	 *
	 * @return string[] Cleared hold IDs.
	 */
	public static function clear_holds( $ids, WC_Order $order ) {
		$ids = array_filter( array_map( 'strval', (array) $ids ) );
		if ( ! $ids || ! self::can() ) {
			return array();
		}
		self::lock( true );
		try {
			$links  = self::links( true );
			$in     = array();
			foreach ( $links['map'] as $d => $sizes ) {
				foreach ( $sizes as $s => $vid ) {
					$in[ $vid ] = $d . '|' . $s;
				}
			}
			$room = array();
			foreach ( $order->get_items() as $item ) {
				$k = $in[ (int) $item->get_variation_id() ] ?? '';
				if ( $k ) {
					$room[ $k ] = ( $room[ $k ] ?? 0 ) + (int) $item->get_quantity();
				}
			}
			$sales   = self::get( 'sales', array() );
			$cleared = array();
			foreach ( $ids as $id ) {
				$r = $sales[ $id ] ?? null;
				if ( ! $r || 'On hold' !== ( $r['status'] ?? '' ) ) {
					continue;
				}
				$k = $r['design'] . '|' . $r['size'];
				if ( ( $room[ $k ] ?? 0 ) < (int) $r['qty'] ) {
					continue;
				}
				$room[ $k ] -= (int) $r['qty'];
				unset( $sales[ $id ] );
				$cleared[] = $id;
			}
			if ( $cleared ) {
				self::put( 'sales', $sales );
				$order->add_order_note( sprintf( 'Picked up %d jersey hold(s) from Jersey Stock.', count( $cleared ) ) );
				self::resync();
			}
			return $cleared;
		} finally {
			self::lock( false );
		}
	}

	/**
	 * How many held jerseys these hold IDs cover, by size variation. A held
	 * jersey is already out of the website count, so selling it isn't an oversell.
	 *
	 * @return int[] variation id => quantity.
	 */
	public static function holds_by_variation( $ids ) {
		$ids = array_filter( array_map( 'strval', (array) $ids ) );
		if ( ! $ids || ! self::can() ) {
			return array();
		}
		$map   = self::links()['map'];
		$sales = self::get( 'sales', array() );
		$out   = array();
		foreach ( $ids as $id ) {
			$r   = $sales[ $id ] ?? null;
			$vid = $r && 'On hold' === ( $r['status'] ?? '' ) ? ( $map[ $r['design'] ][ $r['size'] ] ?? 0 ) : 0;
			if ( $vid ) {
				$out[ $vid ] = ( $out[ $vid ] ?? 0 ) + (int) $r['qty'];
			}
		}
		return $out;
	}

	/** Apply one change. Returns true or WP_Error. */
	public static function apply( $op, $data ) {
		switch ( $op ) {
			case 'state':
				return true;

			case 'add':
				$recs = isset( $data['recs'] ) && is_array( $data['recs'] ) ? array_values( $data['recs'] ) : array();
				if ( ! $recs || count( $recs ) > 2 ) {
					return new WP_Error( 'bad', 'Nothing to log.' );
				}
				$sales = self::get( 'sales', array() );
				$clean = array();
				$need  = array();
				foreach ( $recs as $r ) {
					$c = self::clean_sale( $r );
					if ( is_wp_error( $c ) ) {
						return $c;
					}
					$clean[]    = $c;
					$k          = $c['design'] . '|' . $c['size'];
					$need[ $k ] = ( $need[ $k ] ?? 0 ) + $c['qty'];
				}
				$free = self::free( self::config(), $sales, self::order_rows( self::links( true ) ) );
				foreach ( $need as $k => $n ) {
					list( $d, $s ) = explode( '|', $k );
					if ( $n > $free[ $d ][ $s ] ) {
						return new WP_Error( 'stock', 'Only ' . max( 0, $free[ $d ][ $s ] ) . ' ' . self::DESIGNS[ $d ] . ' free in ' . $s . '.' );
					}
				}
				foreach ( $clean as $c ) {
					$sales[ self::new_id() ] = $c;
				}
				self::put( 'sales', $sales );
				return true;

			case 'update':
				$id    = (string) ( $data['id'] ?? '' );
				$patch = isset( $data['patch'] ) && is_array( $data['patch'] ) ? $data['patch'] : array();
				$sales = self::get( 'sales', array() );
				if ( ! isset( $sales[ $id ] ) ) {
					return new WP_Error( 'gone', 'That entry was already removed. The list is up to date now.' );
				}
				$old = $sales[ $id ];
				$new = $old;
				foreach ( array( 'status', 'date', 'heldDate', 'design', 'size', 'payment' ) as $k ) {
					if ( ! array_key_exists( $k, $patch ) ) {
						continue;
					}
					if ( 'heldDate' === $k && ( null === $patch[ $k ] || '' === $patch[ $k ] ) ) {
						unset( $new['heldDate'] );
						continue;
					}
					$new[ $k ] = $patch[ $k ];
				}
				$c = self::clean_sale( $new );
				if ( is_wp_error( $c ) ) {
					return $c;
				}
				if ( $c['design'] !== $old['design'] || $c['size'] !== $old['size'] ) {
					$rest = $sales;
					unset( $rest[ $id ] );
					$free = self::free( self::config(), $rest, self::order_rows( self::links( true ) ) );
					if ( $c['qty'] > $free[ $c['design'] ][ $c['size'] ] ) {
						return new WP_Error( 'stock', 'Only ' . max( 0, $free[ $c['design'] ][ $c['size'] ] ) . ' ' . self::DESIGNS[ $c['design'] ] . ' free in ' . $c['size'] . '.' );
					}
				}
				$sales[ $id ] = $c;
				self::put( 'sales', $sales );
				return true;

			case 'delete':
				$id    = (string) ( $data['id'] ?? '' );
				$sales = self::get( 'sales', array() );
				unset( $sales[ $id ] );
				self::put( 'sales', $sales );
				return true;

			case 'cost_add':
				$label  = mb_substr( sanitize_text_field( (string) ( $data['label'] ?? '' ) ), 0, 60 );
				$amount = round( (float) ( $data['amount'] ?? 0 ), 2 );
				if ( '' === $label ) {
					return new WP_Error( 'bad', 'Say what the cost was for.' );
				}
				if ( $amount <= 0 || $amount > 100000 ) {
					return new WP_Error( 'bad', 'Enter the cost in dollars.' );
				}
				$costs                   = self::get( 'costs', array() );
				$costs[ self::new_id() ] = array(
					'label'     => $label,
					'amount'    => $amount,
					'createdAt' => round( microtime( true ) * 1000 ),
				);
				self::put( 'costs', $costs );
				return true;

			case 'cost_remove':
				$costs = self::get( 'costs', array() );
				unset( $costs[ (string) ( $data['id'] ?? '' ) ] );
				self::put( 'costs', $costs );
				return true;

			case 'sync':
				update_option( 'prd_jersey_sync', ! empty( $data['on'] ), false );
				return true;

			case 'config':
				// Made counts (a new run arrived, or a recount) and prices.
				$c = self::config();
				foreach ( array_keys( self::DESIGNS ) as $d ) {
					foreach ( self::SIZES as $s ) {
						if ( isset( $data['made'][ $d ][ $s ] ) ) {
							$n = (int) $data['made'][ $d ][ $s ];
							if ( $n < 0 || $n > 5000 ) {
								return new WP_Error( 'bad', 'Counts must be between 0 and 5000.' );
							}
							$c['made'][ $d ][ $s ] = $n;
						}
					}
				}
				foreach ( array( 'presale', 'regular', 'bundle' ) as $k ) {
					if ( isset( $data['prices'][ $k ] ) ) {
						$p = (float) $data['prices'][ $k ];
						if ( $p <= 0 || $p > 10000 ) {
							return new WP_Error( 'bad', 'Prices must be more than $0.' );
						}
						$c['prices'][ $k ] = $p;
					}
				}
				if ( isset( $data['note'] ) ) {
					$c['note'] = mb_substr( sanitize_text_field( (string) $data['note'] ), 0, 120 );
				}
				// Not below what is already sold or held.
				$free = self::free( $c, self::get( 'sales', array() ), self::order_rows( self::links( true ) ) );
				foreach ( $free as $d => $sizes ) {
					foreach ( $sizes as $s => $n ) {
						if ( $n < 0 ) {
							return new WP_Error( 'bad', self::DESIGNS[ $d ] . ' ' . $s . ' would be ' . ( -$n ) . ' below what is already sold or held.' );
						}
					}
				}
				self::put( 'config', $c );
				return true;

			case 'import':
				return self::import( $data );
		}
		return new WP_Error( 'bad', 'Unknown action.' );
	}

	/** One-time move from the old tracker. Refuses to overwrite unless asked. */
	private static function import( $data ) {
		$have = self::get( 'sales', array() ) || self::get( 'costs', array() );
		if ( $have && empty( $data['replace'] ) ) {
			return new WP_Error( 'exists', 'The tracker already has entries.' );
		}
		if ( isset( $data['config'] ) && is_array( $data['config'] ) ) {
			$c = self::default_config();
			foreach ( array_keys( self::DESIGNS ) as $d ) {
				foreach ( self::SIZES as $s ) {
					if ( isset( $data['config']['made'][ $d ][ $s ] ) ) {
						$c['made'][ $d ][ $s ] = max( 0, (int) $data['config']['made'][ $d ][ $s ] );
					}
				}
			}
			foreach ( array( 'presale', 'regular', 'bundle' ) as $k ) {
				if ( isset( $data['config']['prices'][ $k ] ) && (float) $data['config']['prices'][ $k ] > 0 ) {
					$c['prices'][ $k ] = (float) $data['config']['prices'][ $k ];
				}
			}
			foreach ( (array) ( $data['config']['fees'] ?? array() ) as $ch => $f ) {
				if ( isset( $c['fees'][ $ch ] ) ) {
					$c['fees'][ $ch ] = array(
						'pct'   => (float) ( $f['pct'] ?? 0 ),
						'fixed' => (float) ( $f['fixed'] ?? 0 ),
					);
				}
			}
			if ( isset( $data['config']['note'] ) ) {
				$c['note'] = mb_substr( sanitize_text_field( (string) $data['config']['note'] ), 0, 120 );
			}
			self::put( 'config', $c );
		}
		$sales = array();
		foreach ( (array) ( $data['sales'] ?? array() ) as $r ) {
			$c = self::clean_sale( $r );
			if ( is_wp_error( $c ) ) {
				return new WP_Error( 'bad', 'Sale ' . sanitize_text_field( (string) ( $r['id'] ?? '?' ) ) . ': ' . $c->get_error_message() );
			}
			$id           = preg_match( '/^[a-z0-9]{6,40}$/', (string) ( $r['id'] ?? '' ) ) ? (string) $r['id'] : self::new_id();
			$sales[ $id ] = $c;
		}
		$costs = array();
		foreach ( (array) ( $data['costs'] ?? array() ) as $r ) {
			$label  = mb_substr( sanitize_text_field( (string) ( $r['label'] ?? '' ) ), 0, 60 );
			$amount = round( (float) ( $r['amount'] ?? 0 ), 2 );
			if ( '' === $label || $amount <= 0 ) {
				continue;
			}
			$id           = preg_match( '/^[a-z0-9-]{2,40}$/', (string) ( $r['id'] ?? '' ) ) ? (string) $r['id'] : self::new_id();
			$costs[ $id ] = array(
				'label'     => $label,
				'amount'    => $amount,
				'createdAt' => (float) ( $r['createdAt'] ?? 0 ),
			);
		}
		self::put( 'sales', $sales );
		self::put( 'costs', $costs );
		return true;
	}

	/* ================================================================== */
	/* 7. REST                                                             */
	/* ================================================================== */

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			PRDRWNDN_POS_REST::NS,
			'/stock',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => array( __CLASS__, 'can' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_post' ),
					'permission_callback' => array( __CLASS__, 'can' ),
				),
			)
		);
	}

	public static function rest_get() {
		self::lock( true );
		try {
			return rest_ensure_response( self::state() );
		} finally {
			self::lock( false );
		}
	}

	public static function rest_post( WP_REST_Request $req ) {
		$op   = sanitize_key( (string) $req->get_param( 'op' ) );
		$data = $req->get_param( 'data' );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		self::lock( true );
		try {
			$res = self::apply( $op, $data );
			if ( is_wp_error( $res ) ) {
				$res->add_data( array( 'status' => 400 ) );
				return $res;
			}
			return rest_ensure_response( self::state() );
		} finally {
			self::lock( false );
		}
	}
}
