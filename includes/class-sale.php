<?php
/**
 * Store sales (Black Friday and the like): a percent off whole categories
 * for a set of days, a banner across the top of the website, and an
 * optional free shipping code. Prices go back by themselves when it ends.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_Sale {

	const OPT  = 'prdrwndn_pos_sale';
	const HOOK = 'prdrwndn_pos_sale_tick';
	const PAGE = 'prdrwndn-pos-sale';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_prdrwndn_pos_sale', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_prdrwndn_pos_timezone', array( __CLASS__, 'timezone' ) );
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'banner' ), 1 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'coupon_window' ), 10, 2 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'free_shipping_only' ), 50 );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/** Friday after the fourth Thursday of November. */
	private static function black_friday() {
		$tz   = wp_timezone();
		$year = (int) wp_date( 'Y' );
		$bf   = new DateTimeImmutable( "fourth thursday of november $year", $tz );
		if ( $bf->modify( '+4 days' )->format( 'Y-m-d' ) < wp_date( 'Y-m-d' ) ) {
			$bf = new DateTimeImmutable( 'fourth thursday of november ' . ( $year + 1 ), $tz );
		}
		return $bf->modify( '+1 day' );
	}

	private static function jersey_cat() {
		$slug = function_exists( 'prd_mod' ) ? (string) prd_mod( 'prd_jersey_cat' ) : '';
		$term = get_term_by( 'slug', $slug ? $slug : 'jerseys', 'product_cat' );
		return $term ? (int) $term->term_id : 0;
	}

	public static function defaults() {
		$bf   = self::black_friday();
		$code = 'BLACKFRIDAY';
		$cat  = self::jersey_cat();
		return array(
			'name'        => 'Black Friday',
			'percent'     => 20,
			'cats'        => $cat ? array( $cat ) : array(),
			'start'       => $bf->format( 'Y-m-d' ),
			'end'         => $bf->modify( '+3 days' )->format( 'Y-m-d' ),
			'banner'      => 'BLACK FRIDAY · 20% off all jerseys · Free shipping with code ' . $code . ' · Ends Mon ' . $bf->modify( '+3 days' )->format( 'M j' ),
			'teaser'      => 'Black Friday: 20% off all jerseys, Fri ' . $bf->format( 'M j' ) . ' to Mon ' . $bf->modify( '+3 days' )->format( 'M j' ),
			'teaser_from' => $bf->modify( '-4 days' )->format( 'Y-m-d' ),
			'code'        => $code,
			'state'       => 'draft',
			'applied'     => array(),
			'ship'        => array(),
			'coupon'      => 0,
			'starts'      => 0,
			'ends'        => 0,
			'teaser_at'   => 0,
		);
	}

	public static function get() {
		$s = get_option( self::OPT, array() );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	private static function put( $s ) {
		update_option( self::OPT, $s, false );
	}

	private static function day_start( $ymd ) {
		return ( new DateTimeImmutable( $ymd . ' 00:00:00', wp_timezone() ) )->getTimestamp();
	}

	private static function day_end( $ymd ) {
		return ( new DateTimeImmutable( $ymd . ' 23:59:59', wp_timezone() ) )->getTimestamp();
	}

	/** True when the site clock is plain UTC (so "midnight" is 5pm in Phoenix). */
	private static function utc_clock() {
		$tz = wp_timezone()->getName();
		return in_array( $tz, array( 'UTC', '+00:00', 'Etc/UTC', 'Z' ), true );
	}

	/* ------------------------------------------------------------------ */
	/* What goes on sale                                                   */
	/* ------------------------------------------------------------------ */

	/** Everything with its own price in these categories: variations, or the product itself. */
	private static function targets( $cats ) {
		$slugs = array();
		foreach ( (array) $cats as $id ) {
			$t = get_term( (int) $id, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) {
				$slugs[] = $t->slug;
			}
		}
		if ( ! $slugs ) {
			return array();
		}
		$out = array();
		foreach ( wc_get_products(
			array(
				'status'   => array( 'publish', 'private' ),
				'limit'    => -1,
				'category' => $slugs,
				'type'     => array( 'simple', 'variable' ),
			)
		) as $product ) {
			$list = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
			foreach ( $list as $p ) {
				if ( (float) $p->get_regular_price( 'edit' ) > 0 ) {
					$out[ $p->get_id() ] = $p;
				}
			}
		}
		return $out;
	}

	private static function sale_price( $regular, $percent ) {
		return wc_format_decimal( round( (float) $regular * ( 100 - $percent ) / 100, wc_get_price_decimals() ) );
	}

	/** Product name and sizes, regular and sale price, for the preview. */
	private static function preview( $s ) {
		$rows = array();
		foreach ( self::targets( $s['cats'] ) as $p ) {
			$parent = $p->get_parent_id() ? wc_get_product( $p->get_parent_id() ) : $p;
			$key    = $parent->get_id() . ':' . $p->get_regular_price( 'edit' );
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'name'  => wp_strip_all_tags( $parent->get_name() ),
					'opts'  => array(),
					'was'   => (float) $p->get_regular_price( 'edit' ),
					'sale'  => (float) self::sale_price( $p->get_regular_price( 'edit' ), $s['percent'] ),
				);
			}
			if ( $p->is_type( 'variation' ) ) {
				$rows[ $key ]['opts'][] = implode( ' ', array_filter( $p->get_variation_attributes() ) );
			}
		}
		return array_values( $rows );
	}

	/* ------------------------------------------------------------------ */
	/* Schedule, start, end, cancel                                        */
	/* ------------------------------------------------------------------ */

	private static function schedule( $s ) {
		$starts = self::day_start( $s['start'] );
		$ends   = self::day_end( $s['end'] );
		if ( $ends <= time() ) {
			return new WP_Error( 'past', 'The last day has already passed.' );
		}
		// If the code's name is taken, change nothing.
		if ( '' !== $s['code'] ) {
			$id = wc_get_coupon_id_by_code( $s['code'] );
			if ( $id && ! get_post_meta( $id, '_prdpos_sale', true ) ) {
				return new WP_Error( 'taken', sprintf( 'There is already a coupon called %s. Pick another code, or delete that coupon first.', $s['code'] ) );
			}
		}
		if ( 'scheduled' === $s['state'] ) {
			$s = self::undo( $s );
		}
		$old    = (int) $s['coupon'];
		$coupon = '' !== $s['code'] ? self::coupon( $s, $starts, $ends ) : 0;
		if ( $old && $old !== $coupon ) {
			self::drop_coupon( $old );
		}

		$applied = array();
		foreach ( self::targets( $s['cats'] ) as $id => $p ) {
			$from = $p->get_date_on_sale_from( 'edit' );
			$to   = $p->get_date_on_sale_to( 'edit' );
			// Something already on its own sale keeps that price until this sale starts.
			$wait           = $starts > time() && $p->is_on_sale( 'edit' );
			$applied[ $id ] = array(
				'sale' => (string) $p->get_sale_price( 'edit' ),
				'from' => $from ? $from->getTimestamp() : null,
				'to'   => $to ? $to->getTimestamp() : null,
				'wait' => $wait,
			);
			if ( ! $wait ) {
				self::put_on_sale( $p, $s['percent'], $starts, $ends );
			}
		}
		self::sync_parents( array_keys( $applied ) );

		$s['applied']   = $applied;
		$s['coupon']    = $coupon;
		$s['ship']      = $coupon ? self::add_free_shipping() : array();
		$s['starts']    = $starts;
		$s['ends']      = $ends;
		$s['teaser_at'] = '' !== $s['teaser_from'] && '' !== $s['teaser'] ? self::day_start( $s['teaser_from'] ) : 0;
		$s['state']     = 'scheduled';
		self::put( $s );

		wp_clear_scheduled_hook( self::HOOK, array( 'start' ) );
		wp_clear_scheduled_hook( self::HOOK, array( 'end' ) );
		if ( $starts > time() ) {
			wp_schedule_single_event( $starts, self::HOOK, array( 'start' ) );
		}
		wp_schedule_single_event( $ends + 1, self::HOOK, array( 'end' ) );
		self::purge();
		return $s;
	}

	private static function put_on_sale( $p, $percent, $starts, $ends ) {
		$p->set_sale_price( self::sale_price( $p->get_regular_price( 'edit' ), $percent ) );
		$p->set_date_on_sale_from( $starts );
		$p->set_date_on_sale_to( $ends );
		$p->save();
	}

	/** Put prices, shipping and the code back the way they were. */
	private static function undo( $s ) {
		foreach ( (array) $s['applied'] as $id => $prev ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$p->set_sale_price( $prev['sale'] );
			$p->set_date_on_sale_from( $prev['from'] );
			$p->set_date_on_sale_to( $prev['to'] );
			$p->set_price( $p->is_on_sale( 'edit' ) ? $p->get_sale_price( 'edit' ) : $p->get_regular_price( 'edit' ) );
			$p->save();
		}
		self::sync_parents( array_keys( (array) $s['applied'] ) );
		self::remove_free_shipping( (array) $s['ship'] );
		wp_clear_scheduled_hook( self::HOOK, array( 'start' ) );
		wp_clear_scheduled_hook( self::HOOK, array( 'end' ) );
		$s['applied'] = array();
		$s['ship']    = array();
		return $s;
	}

	/** Runs at the start and end minute, so prices and pages don't wait for the nightly update. */
	public static function tick( $what = '' ) {
		$s = self::get();
		if ( 'scheduled' !== $s['state'] ) {
			return;
		}
		if ( 'end' === $what || time() > (int) $s['ends'] ) {
			$s = self::undo( $s );
			if ( $s['coupon'] ) {
				$c = new WC_Coupon( $s['coupon'] );
				if ( $c->get_id() ) {
					$c->set_date_expires( (int) $s['ends'] );
					$c->save();
				}
			}
			$s['state'] = 'ended';
			self::put( $s );
			self::purge();
			return;
		}
		foreach ( (array) $s['applied'] as $id => $prev ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			if ( ! empty( $prev['wait'] ) ) {
				self::put_on_sale( $p, $s['percent'], (int) $s['starts'], (int) $s['ends'] );
				$s['applied'][ $id ]['wait'] = false;
			}
			$want = $p->is_on_sale( 'edit' ) ? $p->get_sale_price( 'edit' ) : $p->get_regular_price( 'edit' );
			if ( (string) $p->get_price( 'edit' ) !== (string) $want ) {
				$p->set_price( $want );
				$p->save();
			}
		}
		self::put( $s );
		self::sync_parents( array_keys( (array) $s['applied'] ) );
		self::purge();
	}

	/** Delete a code this page made, or just end it if someone already used it. */
	private static function drop_coupon( $id ) {
		$c = new WC_Coupon( (int) $id );
		if ( ! $c->get_id() || ! $c->get_meta( '_prdpos_sale', true ) ) {
			return;
		}
		if ( $c->get_usage_count() ) {
			$c->set_date_expires( time() - 60 );
			$c->save();
		} else {
			$c->delete( true );
		}
	}

	private static function cancel( $s ) {
		$s = self::undo( $s );
		if ( $s['coupon'] ) {
			self::drop_coupon( $s['coupon'] );
		}
		$s['coupon'] = 0;
		$s['state']  = 'draft';
		self::put( $s );
		self::purge();
		return $s;
	}

	private static function sync_parents( $ids ) {
		$parents = array();
		foreach ( $ids as $id ) {
			$parent = wp_get_post_parent_id( $id );
			if ( $parent ) {
				$parents[ $parent ] = true;
			}
			wc_delete_product_transients( $id );
		}
		foreach ( array_keys( $parents ) as $pid ) {
			WC_Product_Variable::sync( $pid );
			wc_delete_product_transients( $pid );
		}
	}

	/** Clear the page cache so the website shows the new prices right away. */
	private static function purge() {
		do_action( 'litespeed_purge_all' );
	}

	/* ------------------------------------------------------------------ */
	/* Free shipping code                                                  */
	/* ------------------------------------------------------------------ */

	private static function coupon( $s, $starts, $ends ) {
		$id = wc_get_coupon_id_by_code( $s['code'] );
		$c  = new WC_Coupon( $id ? $id : 0 );
		$c->set_code( $s['code'] );
		$c->set_discount_type( 'fixed_cart' );
		$c->set_amount( 0 );
		$c->set_free_shipping( true );
		$c->set_product_categories( array_map( 'intval', $s['cats'] ) );
		$c->set_individual_use( false );
		$c->set_date_expires( $ends );
		$c->set_description( $s['name'] . ': free shipping on orders with a sale item. Made on the POS Sale page.' );
		$c->update_meta_data( '_prdpos_sale', 1 );
		$c->update_meta_data( '_prdpos_starts', $starts );
		$c->save();
		return $c->get_id();
	}

	/** The code only works once the sale has started. */
	public static function coupon_window( $valid, $coupon ) {
		$starts = (int) $coupon->get_meta( '_prdpos_starts', true );
		if ( $valid && $starts && time() < $starts ) {
			throw new Exception( esc_html( sprintf( 'This code works from %s.', wp_date( 'l, M j', $starts ) ) ), 100 );
		}
		return $valid;
	}

	/**
	 * Free shipping a code can unlock, in every zone that ships but has no
	 * such option yet. Only shows when the code is used.
	 */
	private static function add_free_shipping() {
		$added = array();
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return $added;
		}
		$ids   = array_map( 'intval', wp_list_pluck( WC_Shipping_Zones::get_zones(), 'zone_id' ) );
		$ids[] = 0;
		foreach ( $ids as $zid ) {
			$zone  = WC_Shipping_Zones::get_zone( $zid );
			$ships = false;
			$free  = false;
			foreach ( $zone ? $zone->get_shipping_methods( true ) : array() as $m ) {
				if ( 'free_shipping' === $m->id ) {
					$free = $free || in_array( (string) $m->get_option( 'requires', '' ), array( '', 'coupon', 'either' ), true );
				} elseif ( ! in_array( $m->id, array( 'local_pickup', 'pickup_location' ), true ) ) {
					$ships = true;
				}
			}
			if ( ! $ships || $free ) {
				continue;
			}
			$iid = (int) $zone->add_shipping_method( 'free_shipping' );
			if ( $iid ) {
				update_option(
					'woocommerce_free_shipping_' . $iid . '_settings',
					array(
						'title'            => 'Free shipping',
						'requires'         => 'coupon',
						'min_amount'       => '0',
						'ignore_discounts' => 'no',
					)
				);
				$added[] = array(
					'zone'     => $zid,
					'instance' => $iid,
				);
			}
		}
		WC_Cache_Helper::get_transient_version( 'shipping', true );
		return $added;
	}

	private static function remove_free_shipping( $added ) {
		if ( ! $added || ! class_exists( 'WC_Shipping_Zones' ) ) {
			return;
		}
		foreach ( $added as $a ) {
			$zone = WC_Shipping_Zones::get_zone( (int) $a['zone'] );
			if ( $zone ) {
				$zone->delete_shipping_method( (int) $a['instance'] );
				delete_option( 'woocommerce_free_shipping_' . (int) $a['instance'] . '_settings' );
			}
		}
		WC_Cache_Helper::get_transient_version( 'shipping', true );
	}

	/** With the code in, free shipping replaces the paid options (pickup stays). */
	public static function free_shipping_only( $rates ) {
		$s    = get_option( self::OPT, array() );
		$mine = wp_list_pluck( isset( $s['ship'] ) ? (array) $s['ship'] : array(), 'instance' );
		if ( ! $mine ) {
			return $rates;
		}
		$have = false;
		foreach ( $rates as $rate ) {
			if ( 'free_shipping' === $rate->get_method_id() && in_array( (int) $rate->get_instance_id(), array_map( 'intval', $mine ), true ) ) {
				$have = true;
			}
		}
		if ( ! $have ) {
			return $rates;
		}
		foreach ( $rates as $key => $rate ) {
			if ( ! in_array( $rate->get_method_id(), array( 'free_shipping', 'local_pickup', 'pickup_location' ), true ) ) {
				unset( $rates[ $key ] );
			}
		}
		return $rates;
	}

	/* ------------------------------------------------------------------ */
	/* Website banner                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * The bar is always in the page and the visitor's own clock decides
	 * whether it shows, so cached pages switch on and off at the right minute.
	 */
	public static function banner() {
		if ( is_admin() ) {
			return;
		}
		$s = self::get();
		if ( 'scheduled' !== $s['state'] || time() > (int) $s['ends'] ) {
			return;
		}
		$url  = '';
		$cats = (array) $s['cats'];
		if ( 1 === count( $cats ) ) {
			$link = get_term_link( (int) $cats[0], 'product_cat' );
			$url  = is_wp_error( $link ) ? '' : $link;
		}
		if ( ! $url && function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'shop' );
		}
		$data = array(
			'start'  => (int) $s['starts'] * 1000,
			'end'    => (int) $s['ends'] * 1000,
			'soon'   => (int) $s['teaser_at'] * 1000,
			'sale'   => (string) $s['banner'],
			'teaser' => (string) $s['teaser'],
			'chip'   => (int) $s['percent'] . '% off',
		);
		?>
<style id="prd-salebar-css">
.prd-salebar{display:block;background:#141414;color:#F1ECE2;text-align:center;text-decoration:none;font:500 13px/1.35 Inter,system-ui,-apple-system,sans-serif;letter-spacing:.03em;padding:9px 16px;padding-top:calc(9px + env(safe-area-inset-top,0px))}
.prd-salebar[hidden]{display:none}
.prd-salebar b{color:#FAD201;font-weight:700;letter-spacing:.08em}
.prd-salebar:hover,.prd-salebar:focus{color:#fff}
.prd-salebar.is-soon{background:#1E3B2F}
html.prd-sale-on .prd-topbar,html.prd-sale-soon .prd-topbar,html.prd-in-app .prd-salebar{display:none!important}
html.prd-sale-on .prd-deal-note,html.prd-sale-on .prd-cart-deal,html.prd-sale-on .prd-deal-band,html.prd-sale-on .prd-badge-deal{display:none!important}
</style>
<a class="prd-salebar" id="prd-salebar" href="<?php echo esc_url( $url ); ?>" hidden></a>
<script>(function(d){var c=<?php echo wp_json_encode( $data ); ?>,n=Date.now(),on=n>=c.start&&n<=c.end,soon=!on&&c.soon&&c.teaser&&n>=c.soon&&n<c.start,el=document.getElementById("prd-salebar");if(!el||(!on&&!soon))return;var t=on?c.sale:c.teaser,i=t.indexOf(" · ");el.textContent="";if(on&&i>0){var b=d.createElement("b");b.textContent=t.slice(0,i);el.appendChild(b);el.appendChild(d.createTextNode(t.slice(i)));}else{el.textContent=t;}el.className="prd-salebar "+(on?"is-on":"is-soon");el.hidden=false;d.documentElement.classList.add(on?"prd-sale-on":"prd-sale-soon");if(on){d.addEventListener("DOMContentLoaded",function(){[].forEach.call(d.querySelectorAll(".prd-hero-facts strong"),function(s){if(/^\d+ for \$/.test(s.textContent.trim())){s.textContent=c.chip;}});});}})(document);</script>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Admin page                                                          */
	/* ------------------------------------------------------------------ */

	public static function menu() {
		add_submenu_page( 'prdrwndn-pos', 'Sale', 'Sale', PRDRWNDN_POS_Roles::CAP_MANAGE, self::PAGE, array( __CLASS__, 'page' ) );
	}

	private static function when( $ts ) {
		return wp_date( 'D, M j \a\t g:i a', (int) $ts );
	}

	public static function page() {
		if ( ! current_user_can( PRDRWNDN_POS_Roles::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have access to this page.', 'prdrwndn-pos' ) );
		}
		$s       = self::get();
		$live    = 'scheduled' === $s['state'] && time() >= (int) $s['starts'] && time() <= (int) $s['ends'];
		$notice  = get_transient( 'prdrwndn_pos_sale_notice_' . get_current_user_id() );
		delete_transient( 'prdrwndn_pos_sale_notice_' . get_current_user_id() );
		$cats    = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$preview = self::preview( $s );
		$tz      = wp_timezone_string();
		?>
		<div class="wrap prdpos prdpos-sale">
			<h1><?php esc_html_e( 'Sale', 'prdrwndn-pos' ); ?></h1>
			<p><?php esc_html_e( 'Take a percent off whole categories for a few days. Prices change on the website and in the POS at the start and go back by themselves at the end.', 'prdrwndn-pos' ); ?></p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( self::utc_clock() ) : ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'Your site clock is set to UTC.', 'prdrwndn-pos' ); ?></strong> <?php esc_html_e( 'A sale day would start and end at 5 pm Phoenix time, and order dates jump to the next day in the evening.', 'prdrwndn-pos' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'prdrwndn_pos_timezone' ); ?>
						<input type="hidden" name="action" value="prdrwndn_pos_timezone">
						<p><button class="button button-primary" type="submit"><?php esc_html_e( 'Switch the site to Phoenix time', 'prdrwndn-pos' ); ?></button></p>
					</form>
				</div>
			<?php endif; ?>

			<div class="prdpos-sale-state is-<?php echo esc_attr( $live ? 'live' : $s['state'] ); ?>">
				<?php
				if ( $live ) {
					/* translators: 1: sale name, 2: end time */
					printf( esc_html__( '%1$s is on now. It ends %2$s.', 'prdrwndn-pos' ), '<strong>' . esc_html( $s['name'] ) . '</strong>', esc_html( self::when( $s['ends'] ) ) );
				} elseif ( 'scheduled' === $s['state'] ) {
					/* translators: 1: sale name, 2: start time, 3: end time */
					printf( esc_html__( '%1$s is scheduled. It starts %2$s and ends %3$s.', 'prdrwndn-pos' ), '<strong>' . esc_html( $s['name'] ) . '</strong>', esc_html( self::when( $s['starts'] ) ), esc_html( self::when( $s['ends'] ) ) );
				} elseif ( 'ended' === $s['state'] ) {
					/* translators: %s: sale name */
					printf( esc_html__( '%s has ended. Prices are back to normal.', 'prdrwndn-pos' ), '<strong>' . esc_html( $s['name'] ) . '</strong>' );
				} else {
					esc_html_e( 'Not scheduled yet. Check the details, then tap Schedule sale.', 'prdrwndn-pos' );
				}
				?>
				<span class="prdpos-sale-tz"><?php echo esc_html( sprintf( 'Times are %s.', $tz ) ); ?></span>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'prdrwndn_pos_sale' ); ?>
				<input type="hidden" name="action" value="prdrwndn_pos_sale">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="sale-name"><?php esc_html_e( 'Name', 'prdrwndn-pos' ); ?></label></th>
						<td><input type="text" id="sale-name" name="name" value="<?php echo esc_attr( $s['name'] ); ?>" class="regular-text" maxlength="40"></td>
					</tr>
					<tr>
						<th scope="row"><label for="sale-pct"><?php esc_html_e( 'Percent off', 'prdrwndn-pos' ); ?></label></th>
						<td><input type="number" id="sale-pct" name="percent" value="<?php echo esc_attr( $s['percent'] ); ?>" min="1" max="90" step="1" class="small-text"> %</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Products', 'prdrwndn-pos' ); ?></th>
						<td><fieldset class="prdpos-sale-cats">
							<?php foreach ( is_array( $cats ) ? $cats : array() as $t ) : ?>
								<label><input type="checkbox" name="cats[]" value="<?php echo esc_attr( $t->term_id ); ?>" <?php checked( in_array( (int) $t->term_id, array_map( 'intval', $s['cats'] ), true ) ); ?>> <?php echo esc_html( $t->name ); ?> <span class="count">(<?php echo esc_html( $t->count ); ?>)</span></label>
							<?php endforeach; ?>
						</fieldset></td>
					</tr>
					<tr>
						<th scope="row"><label for="sale-start"><?php esc_html_e( 'Days', 'prdrwndn-pos' ); ?></label></th>
						<td><input type="date" id="sale-start" name="start" value="<?php echo esc_attr( $s['start'] ); ?>"> <?php esc_html_e( 'to', 'prdrwndn-pos' ); ?> <input type="date" id="sale-end" name="end" value="<?php echo esc_attr( $s['end'] ); ?>" aria-label="<?php esc_attr_e( 'Last day', 'prdrwndn-pos' ); ?>">
						<p class="description"><?php esc_html_e( 'Whole days: from midnight on the first day to 11:59 pm on the last.', 'prdrwndn-pos' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="sale-banner"><?php esc_html_e( 'Banner during the sale', 'prdrwndn-pos' ); ?></label></th>
						<td><input type="text" id="sale-banner" name="banner" value="<?php echo esc_attr( $s['banner'] ); ?>" class="large-text" maxlength="160">
						<p class="description"><?php esc_html_e( 'Shows across the top of the website, in place of the usual top bar. The words before the first " · " are highlighted.', 'prdrwndn-pos' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="sale-teaser"><?php esc_html_e( 'Heads-up banner before it', 'prdrwndn-pos' ); ?></label></th>
						<td><input type="text" id="sale-teaser" name="teaser" value="<?php echo esc_attr( $s['teaser'] ); ?>" class="large-text" maxlength="160">
						<p><label for="sale-teaser-from"><?php esc_html_e( 'Show it from', 'prdrwndn-pos' ); ?></label> <input type="date" id="sale-teaser-from" name="teaser_from" value="<?php echo esc_attr( $s['teaser_from'] ); ?>"></p>
						<p class="description"><?php esc_html_e( 'Leave the text empty for no heads-up.', 'prdrwndn-pos' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="sale-code"><?php esc_html_e( 'Free shipping code', 'prdrwndn-pos' ); ?></label></th>
						<td><input type="text" id="sale-code" name="code" value="<?php echo esc_attr( $s['code'] ); ?>" class="regular-text" maxlength="30" spellcheck="false" style="text-transform:uppercase">
						<p class="description"><?php esc_html_e( 'Works only during the sale, on orders with a sale product. With the code in, free shipping replaces paid shipping at checkout (pickup stays). Leave empty for no code.', 'prdrwndn-pos' ); ?></p></td>
					</tr>
				</table>

				<?php if ( $preview ) : ?>
					<h2><?php esc_html_e( 'During the sale', 'prdrwndn-pos' ); ?></h2>
					<table class="widefat striped prdpos-sale-preview">
						<thead><tr><th><?php esc_html_e( 'Product', 'prdrwndn-pos' ); ?></th><th><?php esc_html_e( 'Now', 'prdrwndn-pos' ); ?></th><th><?php esc_html_e( 'Sale price', 'prdrwndn-pos' ); ?></th></tr></thead>
						<tbody>
						<?php foreach ( $preview as $r ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $r['name'] ); ?></strong><?php echo $r['opts'] ? '<br><span class="description">' . esc_html( implode( ', ', $r['opts'] ) ) . '</span>' : ''; ?></td>
								<td><?php echo wp_kses_post( wc_price( $r['was'] ) ); ?></td>
								<td><strong><?php echo wp_kses_post( wc_price( $r['sale'] ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php elseif ( $s['cats'] ) : ?>
					<p><?php esc_html_e( 'No products with a price in those categories yet.', 'prdrwndn-pos' ); ?></p>
				<?php endif; ?>

				<p class="submit">
					<button type="submit" name="do" value="schedule" class="button button-primary button-hero"><?php echo esc_html( 'scheduled' === $s['state'] ? __( 'Save and reschedule', 'prdrwndn-pos' ) : __( 'Schedule sale', 'prdrwndn-pos' ) ); ?></button>
					<?php if ( 'scheduled' === $s['state'] ) : ?>
						<button type="submit" name="do" value="cancel" class="button button-link-delete prdpos-sale-cancel"><?php esc_html_e( 'Cancel sale', 'prdrwndn-pos' ); ?></button>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<style>
			.prdpos-sale-state{background:#fff;border-left:4px solid #8c8f94;padding:12px 14px;margin:16px 0;max-width:760px;font-size:14px}
			.prdpos-sale-state.is-scheduled{border-color:#dba617}.prdpos-sale-state.is-live{border-color:#00a32a}.prdpos-sale-state.is-ended{border-color:#2271b1}
			.prdpos-sale-tz{display:block;color:#646970;font-size:12px;margin-top:4px}
			.prdpos-sale-cats label{display:inline-block;margin:0 16px 8px 0}
			.prdpos-sale-preview{max-width:760px}
			.prdpos-sale .button-hero{margin-right:12px}
		</style>
		<?php
	}

	private static function say( $type, $text ) {
		set_transient(
			'prdrwndn_pos_sale_notice_' . get_current_user_id(),
			array(
				'type' => $type,
				'text' => $text,
			),
			60
		);
	}

	private static function back() {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	public static function save() {
		if ( ! current_user_can( PRDRWNDN_POS_Roles::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have access to this page.', 'prdrwndn-pos' ) );
		}
		check_admin_referer( 'prdrwndn_pos_sale' );
		$s  = self::get();
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';

		if ( 'cancel' === $do ) {
			self::cancel( $s );
			self::say( 'success', 'Sale cancelled. Prices, shipping and the code are back the way they were.' );
			self::back();
		}

		$date = static function ( $key ) {
			$v = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		};
		$s['name']        = mb_substr( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), 0, 40 );
		$s['percent']     = (int) ( $_POST['percent'] ?? 0 );
		$s['cats']        = array_values( array_filter( array_map( 'intval', (array) wp_unslash( $_POST['cats'] ?? array() ) ) ) );
		$s['start']       = $date( 'start' );
		$s['end']         = $date( 'end' );
		$s['banner']      = mb_substr( sanitize_text_field( wp_unslash( $_POST['banner'] ?? '' ) ), 0, 160 );
		$s['teaser']      = mb_substr( sanitize_text_field( wp_unslash( $_POST['teaser'] ?? '' ) ), 0, 160 );
		$s['teaser_from'] = $date( 'teaser_from' );
		$s['code']        = strtoupper( preg_replace( '/[^A-Za-z0-9_-]/', '', wp_unslash( $_POST['code'] ?? '' ) ) );

		$err = '';
		if ( '' === $s['name'] ) {
			$err = 'Give the sale a name.';
		} elseif ( $s['percent'] < 1 || $s['percent'] > 90 ) {
			$err = 'Percent off must be between 1 and 90.';
		} elseif ( ! $s['cats'] ) {
			$err = 'Pick at least one category.';
		} elseif ( ! $s['start'] || ! $s['end'] || $s['end'] < $s['start'] ) {
			$err = 'Check the days: the last day must be on or after the first.';
		} elseif ( '' !== $s['code'] && strlen( $s['code'] ) < 3 ) {
			$err = 'The code needs at least 3 letters or numbers.';
		} elseif ( '' !== $s['teaser'] && $s['teaser_from'] && $s['teaser_from'] >= $s['start'] ) {
			$err = 'The heads-up banner has to start before the sale does.';
		}
		$r = $err ? new WP_Error( 'bad', $err ) : self::schedule( $s );
		if ( is_wp_error( $r ) ) {
			// Keep what was typed so nothing has to be retyped, but nothing changes in the store.
			$keep = self::get();
			foreach ( array( 'name', 'percent', 'cats', 'start', 'end', 'banner', 'teaser', 'teaser_from', 'code' ) as $k ) {
				$keep[ $k ] = $s[ $k ];
			}
			self::put( $keep );
			self::say( 'error', $r->get_error_message() );
			self::back();
		}
		self::say(
			'success',
			sprintf(
				'%1$s scheduled: %2$d %3$s at %4$d%% off from %5$s to %6$s.',
				$r['name'],
				count( $r['applied'] ),
				1 === count( $r['applied'] ) ? 'price' : 'prices',
				$r['percent'],
				self::when( $r['starts'] ),
				self::when( $r['ends'] )
			)
		);
		self::back();
	}

	/** Switch the site clock to Phoenix (Arizona has no daylight saving). */
	public static function timezone() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have access to this page.', 'prdrwndn-pos' ) );
		}
		check_admin_referer( 'prdrwndn_pos_timezone' );
		update_option( 'timezone_string', 'America/Phoenix' );
		update_option( 'gmt_offset', '' );
		$s = self::get();
		if ( 'scheduled' === $s['state'] ) {
			self::schedule( $s ); // Same days, now in Phoenix time.
		}
		self::say( 'success', 'The site now runs on Phoenix time.' );
		self::back();
	}
}
