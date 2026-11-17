<?php
/**
 * In-person sales: catalog for the app, deals, totals, and saving each sale
 * as a WooCommerce order so the website and the POS share one stock count.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_Sales {

	const OPT_DEALS = 'prdrwndn_pos_deals';
	const VIA       = 'prdrwndn-pos';

	/** How a customer paid, recorded on the order. */
	const METHODS = array(
		'cash'     => 'Cash',
		'zelle'    => 'Zelle',
		'cashapp'  => 'Cash App',
		'applepay' => 'Apple Pay',
		'other'    => 'Other',
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// You are standing at the table: no "new order" email for every in-person sale.
		add_filter( 'woocommerce_email_enabled_new_order', array( __CLASS__, 'skip_admin_email' ), 10, 2 );
	}

	public static function skip_admin_email( $enabled, $order ) {
		if ( $order instanceof WC_Order && self::VIA === $order->get_created_via() ) {
			return false;
		}
		return $enabled;
	}

	/* ------------------------------------------------------------------ */
	/* Deals                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Saved deal rules: "any N items from a category for $X".
	 *
	 * @return array[] label, cat, qty, price.
	 */
	public static function deals() {
		$rules = get_option( self::OPT_DEALS, array() );
		$out   = array();
		foreach ( (array) $rules as $r ) {
			$qty   = isset( $r['qty'] ) ? (int) $r['qty'] : 0;
			$price = isset( $r['price'] ) ? (float) $r['price'] : -1;
			$cat   = isset( $r['cat'] ) ? (int) $r['cat'] : 0;
			if ( $qty >= 2 && $price >= 0 && $cat > 0 ) {
				$out[] = array(
					'label' => '' !== trim( (string) ( $r['label'] ?? '' ) ) ? sanitize_text_field( $r['label'] ) : sprintf( '%d for %s', $qty, PRDRWNDN_POS_Lookup::money( $price ) ),
					'cat'   => $cat,
					'qty'   => $qty,
					'price' => $price,
				);
			}
		}
		return $out;
	}

	/**
	 * Discounts the deals give a cart. Each deal groups the most expensive
	 * matching items first, so the customer always gets the full deal value.
	 * The app runs the same math so totals match offline.
	 *
	 * @param array[] $lines Each: price (unit), qty, cats (category IDs).
	 * @return array[] label, amount (positive number to take off).
	 */
	public static function deal_discounts( $lines ) {
		$out = array();
		foreach ( self::deals() as $rule ) {
			$units = array();
			foreach ( $lines as $l ) {
				if ( in_array( $rule['cat'], (array) $l['cats'], true ) ) {
					for ( $i = 0; $i < (int) $l['qty']; $i++ ) {
						$units[] = (float) $l['price'];
					}
				}
			}
			$bundles = intdiv( count( $units ), $rule['qty'] );
			if ( $bundles < 1 ) {
				continue;
			}
			rsort( $units );
			$normal = array_sum( array_slice( $units, 0, $bundles * $rule['qty'] ) );
			$off    = round( $normal - $bundles * $rule['price'], 2 );
			if ( $off > 0 ) {
				$out[] = array(
					'label'  => $rule['label'] . ( $bundles > 1 ? ' x' . $bundles : '' ),
					'amount' => $off,
					'cat'    => (int) $rule['cat'],
				);
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	public static function routes() {
		$ns   = PRDRWNDN_POS_REST::NS;
		$sell = static function () {
			return current_user_can( PRDRWNDN_POS_Roles::CAP_SELL );
		};
		register_rest_route(
			$ns,
			'/pos/catalog',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_catalog' ),
				'permission_callback' => $sell,
			)
		);
		register_rest_route(
			$ns,
			'/pos/quote',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_quote' ),
				'permission_callback' => $sell,
			)
		);
		register_rest_route(
			$ns,
			'/pos/sale',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_sale' ),
				'permission_callback' => $sell,
			)
		);
		register_rest_route(
			$ns,
			'/pos/sales',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_sales' ),
				'permission_callback' => $sell,
			)
		);
	}

	/**
	 * Everything the app needs to sell, even with no signal.
	 */
	public static function rest_catalog() {
		$items    = array();
		$products = wc_get_products(
			array(
				'limit'   => -1,
				'status'  => array( 'publish', 'private' ),
				'type'    => array( 'simple', 'variable' ),
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		foreach ( $products as $product ) {
			$cats = array_map( 'intval', $product->get_category_ids() );
			$list = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
			foreach ( $list as $item ) {
				if ( 'trash' === $item->get_status() || '' === (string) $item->get_price() ) {
					continue;
				}
				$d       = PRDRWNDN_POS_Lookup::product_data( $item );
				$items[] = array(
					'id'      => $d['id'],
					'parent'  => $product->get_id(),
					'name'    => $d['name'],
					'variant' => $d['variant'],
					'sku'     => $d['sku'],
					'barcode' => $d['barcode'],
					'gtin'    => $d['gtin'],
					'price'   => $d['price'],
					'stock'   => $d['stock'],
					'image'   => $d['image'],
					'cats'    => $cats,
				);
			}
		}
		$user = wp_get_current_user();
		return rest_ensure_response(
			array(
				'items'       => $items,
				'deals'       => self::deals(),
				'methods'     => self::METHODS,
				'currency'    => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'decimals'    => wc_get_price_decimals(),
				'taxes'       => wc_tax_enabled(),
				'canDiscount' => current_user_can( PRDRWNDN_POS_Roles::CAP_MANAGE ),
				'user'        => $user->display_name,
				'updated'     => time(),
			)
		);
	}

	public static function rest_quote( WP_REST_Request $req ) {
		$cart = self::read_cart( $req );
		if ( is_wp_error( $cart ) ) {
			return $cart;
		}
		return rest_ensure_response( self::totals( $cart ) );
	}

	public static function rest_sale( WP_REST_Request $req ) {
		$uuid = sanitize_text_field( (string) $req->get_param( 'uuid' ) );
		if ( ! preg_match( '/^[A-Za-z0-9-]{8,64}$/', $uuid ) ) {
			return new WP_Error( 'bad_uuid', __( 'Missing sale ID.', 'prdrwndn-pos' ), array( 'status' => 400 ) );
		}
		// A sale sent twice (for example after a dropped connection) is saved once.
		$existing = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => '_prdrwndn_pos_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value' => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery
				'return'     => 'objects',
			)
		);
		if ( $existing ) {
			return rest_ensure_response( self::sale_response( $existing[0], array(), true ) );
		}

		$cart = self::read_cart( $req );
		if ( is_wp_error( $cart ) ) {
			return $cart;
		}
		$method = sanitize_key( (string) $req->get_param( 'method' ) );
		if ( ! isset( self::METHODS[ $method ] ) ) {
			return new WP_Error( 'bad_method', __( 'Pick how the customer paid.', 'prdrwndn-pos' ), array( 'status' => 400 ) );
		}
		$customer = (array) $req->get_param( 'customer' );
		$tendered = $req->get_param( 'tendered' );
		$sold_at  = (int) $req->get_param( 'soldAt' );

		$warnings = array();
		$holds    = $req->get_param( 'holds' );
		$held     = ( is_array( $holds ) && class_exists( 'PRDRWNDN_POS_Stock' ) ) ? PRDRWNDN_POS_Stock::holds_by_variation( $holds ) : array();
		$order    = wc_create_order(
			array(
				'status'      => 'pending',
				'customer_id' => 0,
				'created_via' => self::VIA,
			)
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		foreach ( $cart['lines'] as $l ) {
			$order->add_product( $l['product'], $l['qty'] );
			if ( $l['product']->managing_stock() && (int) $l['product']->get_stock_quantity() < $l['qty'] - ( $held[ $l['product']->get_id() ] ?? 0 ) ) {
				/* translators: 1: product, 2: stock */
				$warnings[] = sprintf( __( '%1$s: only %2$d were in stock on the website. Recount this item.', 'prdrwndn-pos' ), $l['label'], (int) $l['product']->get_stock_quantity() );
			}
		}
		foreach ( self::discount_lines( $cart ) as $d ) {
			$fee = new WC_Order_Item_Fee();
			$fee->set_name( $d['label'] );
			$fee->set_amount( -$d['amount'] );
			$fee->set_total( -$d['amount'] );
			$fee->set_tax_status( 'taxable' );
			if ( ! empty( $d['cat'] ) ) {
				$fee->add_meta_data( '_prdpos_deal_cat', (int) $d['cat'], true );
			}
			if ( ! empty( $d['manual'] ) ) {
				$fee->add_meta_data( '_prdpos_discount', 1, true );
			}
			$order->add_item( $fee );
		}

		$name = sanitize_text_field( (string) ( $customer['name'] ?? '' ) );
		if ( '' !== $name ) {
			$parts = preg_split( '/\s+/', $name, 2 );
			$order->set_billing_first_name( $parts[0] );
			$order->set_billing_last_name( $parts[1] ?? '' );
		}
		$order->set_billing_phone( sanitize_text_field( (string) ( $customer['phone'] ?? '' ) ) );
		$email = sanitize_email( (string) ( $customer['email'] ?? '' ) );
		if ( $email && is_email( $email ) ) {
			$order->set_billing_email( $email ); // WooCommerce emails the receipt when the order completes.
		}

		$order->calculate_taxes( self::store_location() );
		$order->calculate_totals( false );

		$label = self::METHODS[ $method ];
		$order->set_payment_method( 'prdrwndn_pos_' . $method );
		$order->set_payment_method_title( $label . ' (in person)' );
		$order->set_date_paid( $sold_at > 0 ? $sold_at : time() );
		if ( $sold_at > 0 ) {
			$order->set_date_created( $sold_at );
		}
		$user = wp_get_current_user();
		$order->update_meta_data( '_prdrwndn_pos_uuid', $uuid );
		$order->update_meta_data( '_prdrwndn_pos_cashier', $user->ID );
		if ( 'cash' === $method && is_numeric( $tendered ) ) {
			$order->update_meta_data( '_prdrwndn_pos_tendered', wc_format_decimal( $tendered, 2 ) );
			$order->update_meta_data( '_prdrwndn_pos_change', wc_format_decimal( max( 0, (float) $tendered - (float) $order->get_total() ), 2 ) );
		}
		if ( $req->get_param( 'offline' ) ) {
			$order->update_meta_data( '_prdrwndn_pos_offline', 1 );
		}
		$order->save();

		/* translators: 1: cashier, 2: payment method */
		$order->add_order_note( sprintf( __( 'In-person sale by %1$s with PRDRWNDN POS. Paid with %2$s.', 'prdrwndn-pos' ), $user->display_name, $label ) );
		if ( $warnings ) {
			$order->add_order_note( implode( "\n", $warnings ) );
		}
		$order->update_status( 'completed', __( 'Paid in person.', 'prdrwndn-pos' ) );
		wc_maybe_reduce_stock_levels( $order->get_id() );

		// Jersey holds picked up with this sale (Stock tab > Ring up): the sale counts them now.
		$cleared = array();
		if ( $held ) {
			$cleared = PRDRWNDN_POS_Stock::clear_holds( $holds, wc_get_order( $order->get_id() ) );
		}

		$out                 = self::sale_response( wc_get_order( $order->get_id() ), $warnings, false );
		$out['holdsCleared'] = $cleared;
		return rest_ensure_response( $out );
	}

	public static function rest_sales() {
		$start  = new DateTime( 'today', wp_timezone() );
		$orders = wc_get_orders(
			array(
				'limit'        => 200,
				'created_via'  => self::VIA,
				'date_created' => '>=' . $start->getTimestamp(),
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);
		$list   = array();
		$totals = array();
		foreach ( $orders as $o ) {
			$method = str_replace( 'prdrwndn_pos_', '', $o->get_payment_method() );
			$label  = self::METHODS[ $method ] ?? $o->get_payment_method_title();
			$items  = array();
			foreach ( $o->get_items() as $it ) {
				$items[] = $it->get_quantity() . ' x ' . wp_strip_all_tags( $it->get_name() );
			}
			$cashier = get_userdata( (int) $o->get_meta( '_prdrwndn_pos_cashier' ) );
			$list[]  = array(
				'number'  => $o->get_order_number(),
				'time'    => $o->get_date_created() ? $o->get_date_created()->date_i18n( get_option( 'time_format' ) ) : '',
				'total'   => (float) $o->get_total(),
				'method'  => $label,
				'items'   => implode( ', ', $items ),
				'cashier' => $cashier ? $cashier->display_name : '',
				'status'  => $o->get_status(),
			);
			if ( 'completed' === $o->get_status() ) {
				$totals[ $label ] = ( $totals[ $label ] ?? 0 ) + (float) $o->get_total();
			}
		}
		return rest_ensure_response(
			array(
				'sales'  => $list,
				'totals' => $totals,
				'total'  => array_sum( $totals ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Read and check the cart the app sent. Prices always come from the store.
	 *
	 * @return array|WP_Error lines, discount (manual, owner only).
	 */
	private static function read_cart( WP_REST_Request $req ) {
		$raw   = (array) $req->get_param( 'items' );
		$lines = array();
		foreach ( $raw as $row ) {
			$id  = absint( $row['id'] ?? 0 );
			$qty = absint( $row['qty'] ?? 0 );
			if ( ! $id || ! $qty ) {
				continue;
			}
			$product = wc_get_product( $id );
			if ( ! $product || $product->is_type( 'variable' ) || 'trash' === $product->get_status() ) {
				/* translators: %d: product ID */
				return new WP_Error( 'bad_item', sprintf( __( 'Product %d is no longer in the store.', 'prdrwndn-pos' ), $id ), array( 'status' => 400 ) );
			}
			$parent  = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : $product;
			$lines[] = array(
				'product' => $product,
				'qty'     => min( $qty, 999 ),
				'price'   => (float) $product->get_price(),
				'cats'    => array_map( 'intval', $parent ? $parent->get_category_ids() : array() ),
				'label'   => wp_strip_all_tags( $product->get_name() ),
			);
		}
		if ( ! $lines ) {
			return new WP_Error( 'empty', __( 'The cart is empty.', 'prdrwndn-pos' ), array( 'status' => 400 ) );
		}
		$discount = 0.0;
		if ( current_user_can( PRDRWNDN_POS_Roles::CAP_MANAGE ) ) {
			$discount = max( 0, round( (float) $req->get_param( 'discount' ), 2 ) );
		}
		return array(
			'lines'    => $lines,
			'discount' => $discount,
		);
	}

	private static function discount_lines( $cart ) {
		$out = self::deal_discounts( $cart['lines'] );
		if ( $cart['discount'] > 0 ) {
			$out[] = array(
				'label'  => __( 'Discount', 'prdrwndn-pos' ),
				'amount' => $cart['discount'],
				'manual' => true,
			);
		}
		return $out;
	}

	/**
	 * Totals before saving, including sales tax if the store charges it.
	 */
	private static function totals( $cart ) {
		$subtotal = 0.0;
		foreach ( $cart['lines'] as $l ) {
			$subtotal += $l['price'] * $l['qty'];
		}
		$discounts = self::discount_lines( $cart );
		$off       = array_sum( wp_list_pluck( $discounts, 'amount' ) );
		$off       = min( $off, $subtotal );
		$tax       = 0.0;
		if ( wc_tax_enabled() && ! wc_prices_include_tax() && $subtotal > 0 ) {
			$ratio = ( $subtotal - $off ) / $subtotal;
			foreach ( $cart['lines'] as $l ) {
				if ( 'taxable' !== $l['product']->get_tax_status() ) {
					continue;
				}
				$rates = WC_Tax::find_rates( array_merge( self::store_location(), array( 'tax_class' => $l['product']->get_tax_class() ) ) );
				$tax  += array_sum( WC_Tax::calc_tax( $l['price'] * $l['qty'] * $ratio, $rates, false ) );
			}
		}
		$tax = round( $tax, wc_get_price_decimals() );
		return array(
			'subtotal'  => round( $subtotal, 2 ),
			'discounts' => $discounts,
			'tax'       => $tax,
			'total'     => round( $subtotal - $off + $tax, 2 ),
		);
	}

	private static function store_location() {
		$c = WC()->countries;
		return array(
			'country'  => $c->get_base_country(),
			'state'    => $c->get_base_state(),
			'postcode' => $c->get_base_postcode(),
			'city'     => $c->get_base_city(),
		);
	}

	private static function sale_response( $order, $warnings, $duplicate ) {
		$change = $order->get_meta( '_prdrwndn_pos_change' );
		return array(
			'id'        => $order->get_id(),
			'number'    => $order->get_order_number(),
			'total'     => (float) $order->get_total(),
			'tax'       => (float) $order->get_total_tax(),
			'change'    => '' === $change ? null : (float) $change,
			'method'    => $order->get_payment_method_title(),
			'warnings'  => $warnings,
			'duplicate' => $duplicate,
			'stock'     => self::stock_after( $order ),
		);
	}

	/** New stock numbers for what was sold, so the app can update its list. */
	private static function stock_after( $order ) {
		$out = array();
		foreach ( $order->get_items() as $item ) {
			$p = $item->get_product();
			if ( $p && $p->managing_stock() ) {
				$out[ $p->get_id() ] = (int) $p->get_stock_quantity();
			}
		}
		return $out;
	}
}
