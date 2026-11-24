<?php
/**
 * Sales history for the POS: every in-person sale with filters and totals.
 * The owner sees all sales; cashiers see the sales they rang up.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_History {

	const MAX = 5000; // Most sales one search looks through.

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			PRDRWNDN_POS_REST::NS,
			'/pos/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest' ),
				'permission_callback' => static function () {
					return current_user_can( PRDRWNDN_POS_Roles::CAP_SELL );
				},
			)
		);
	}

	private static function day( $v, $fallback ) {
		$v = (string) $v;
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : $fallback;
	}

	/** Sales that still count (not cancelled or failed). */
	public static function counts( $status ) {
		return ! in_array( $status, array( 'cancelled', 'failed', 'pending', 'trash' ), true );
	}

	public static function rest( WP_REST_Request $req ) {
		$tz      = wp_timezone();
		$today   = wp_date( 'Y-m-d' );
		$range   = sanitize_key( (string) $req->get_param( 'range' ) );
		$now     = new DateTimeImmutable( 'now', $tz );
		// Quick ranges are worked out in the store's time zone, whatever the device's clock says.
		if ( 'yesterday' === $range ) {
			$from = $now->modify( '-1 day' )->format( 'Y-m-d' );
			$to   = $from;
		} elseif ( in_array( $range, array( '7', '30' ), true ) ) {
			$from = $now->modify( '-' . ( (int) $range - 1 ) . ' days' )->format( 'Y-m-d' );
			$to   = $today;
		} elseif ( 'month' === $range ) {
			$from = $now->format( 'Y-m-01' );
			$to   = $today;
		} elseif ( 'today' === $range ) {
			$from = $today;
			$to   = $today;
		} else {
			$from = self::day( $req->get_param( 'from' ), $today );
			$to   = self::day( $req->get_param( 'to' ), $from );
		}
		if ( $to < $from ) {
			list( $from, $to ) = array( $to, $from );
		}
		$start   = ( new DateTimeImmutable( $from . ' 00:00:00', $tz ) )->getTimestamp();
		$end     = ( new DateTimeImmutable( $to . ' 23:59:59', $tz ) )->getTimestamp();
		$manager = current_user_can( PRDRWNDN_POS_Roles::CAP_MANAGE );
		$method  = sanitize_key( (string) $req->get_param( 'method' ) );
		$cashier = $manager ? absint( $req->get_param( 'cashier' ) ) : get_current_user_id();
		$needle  = mb_strtolower( trim( sanitize_text_field( (string) $req->get_param( 'q' ) ) ) );
		$needle  = ltrim( $needle, '#' );
		$page    = max( 1, absint( $req->get_param( 'page' ) ) );
		$per     = min( 1000, max( 1, absint( $req->get_param( 'per_page' ) ? $req->get_param( 'per_page' ) : 50 ) ) );

		$orders = wc_get_orders(
			array(
				'limit'        => self::MAX,
				'created_via'  => PRDRWNDN_POS_Sales::VIA,
				'date_created' => $start . '...' . $end,
				'status'       => array_keys( wc_get_order_statuses() ),
				'orderby'      => 'date',
				'order'        => 'DESC',
			)
		);

		$people  = array();
		$name_of = static function ( $uid ) use ( &$people ) {
			if ( ! isset( $people[ $uid ] ) ) {
				$u               = $uid ? get_userdata( $uid ) : null;
				$people[ $uid ] = $u ? wp_strip_all_tags( $u->display_name ) : '';
			}
			return $people[ $uid ];
		};

		$rows     = array();
		$totals   = array();
		$sum      = 0.0;
		$count    = 0;
		$units    = 0;
		$cashiers = array();
		foreach ( $orders as $o ) {
			$uid = (int) $o->get_meta( '_prdrwndn_pos_cashier' );
			if ( $manager && $uid ) {
				$cashiers[ $uid ] = $name_of( $uid );
			}
			if ( $cashier && $uid !== $cashier ) {
				continue;
			}
			$m = str_replace( 'prdrwndn_pos_', '', $o->get_payment_method() );
			if ( $method && $m !== $method ) {
				continue;
			}
			$items = array();
			$qty   = 0;
			$sub   = 0.0;
			foreach ( $o->get_items() as $it ) {
				$items[] = $it->get_quantity() . ' x ' . trim( preg_replace( '/^PRDRWNDN\s*®?\s*/iu', '', wp_strip_all_tags( $it->get_name() ) ) );
				$qty    += (int) $it->get_quantity();
				$sub    += (float) $it->get_subtotal();
			}
			$customer = trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() );
			$number   = (string) $o->get_order_number();
			$label    = PRDRWNDN_POS_Sales::METHODS[ $m ] ?? preg_replace( '/\s*\(in person\)$/', '', $o->get_payment_method_title() );
			if ( '' !== $needle ) {
				$hay = mb_strtolower( $number . ' ' . $customer . ' ' . $o->get_billing_phone() . ' ' . $o->get_billing_email() . ' ' . implode( ' ', $items ) . ' ' . $label );
				if ( false === mb_strpos( $hay, $needle ) ) {
					continue;
				}
			}
			$created  = $o->get_date_created();
			$ts       = $created ? $created->getTimestamp() : 0;
			$total    = (float) $o->get_total();
			$refunded = (float) $o->get_total_refunded();
			$status   = $o->get_status();
			$counts   = self::counts( $status );
			$discount = 0.0;
			foreach ( $o->get_fees() as $fee ) {
				if ( (float) $fee->get_total() < 0 ) {
					$discount += -(float) $fee->get_total();
				}
			}
			$rows[] = array(
				'id'        => $o->get_id(),
				'number'    => $number,
				'date'      => $ts ? wp_date( 'Y-m-d', $ts ) : '',
				'time'      => $ts ? wp_date( get_option( 'time_format' ), $ts ) : '',
				'day'       => $ts ? wp_date( 'D, M j', $ts ) : '',
				'cashier'   => $name_of( $uid ),
				'customer'  => $customer,
				'phone'     => (string) $o->get_billing_phone(),
				'email'     => (string) $o->get_billing_email(),
				'items'     => implode( ', ', $items ),
				'units'     => $qty,
				'subtotal'  => round( $sub, 2 ),
				'discount'  => round( $discount, 2 ),
				'tax'       => (float) $o->get_total_tax(),
				'total'     => $total,
				'refunded'  => $refunded,
				'net'       => $counts ? round( $total - $refunded, 2 ) : 0,
				'method'    => $label,
				'methodKey' => $m,
				'status'    => $status,
				'statusLabel' => wc_get_order_status_name( $status ),
				'counts'    => $counts,
			);
			if ( $counts ) {
				$totals[ $label ] = round( ( $totals[ $label ] ?? 0 ) + $total - $refunded, 2 );
				$sum             += $total - $refunded;
				++$count;
				$units += $qty;
			}
		}

		asort( $cashiers );
		$pages = max( 1, (int) ceil( count( $rows ) / $per ) );
		return rest_ensure_response(
			array(
				'from'     => $from,
				'to'       => $to,
				'today'    => $today,
				'sales'    => array_slice( $rows, ( $page - 1 ) * $per, $per ),
				'found'    => count( $rows ),
				'page'     => $page,
				'pages'    => $pages,
				'totals'   => $totals,
				'total'    => round( $sum, 2 ),
				'count'    => $count,
				'units'    => $units,
				'manager'  => $manager,
				'cashiers' => $manager ? array_map(
					static function ( $id, $name ) {
						return array(
							'id'   => $id,
							'name' => $name,
						);
					},
					array_keys( $cashiers ),
					$cashiers
				) : array(),
				'methods'  => PRDRWNDN_POS_Sales::METHODS,
				'capped'   => count( $orders ) >= self::MAX,
			)
		);
	}
}
