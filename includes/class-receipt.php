<?php
/**
 * Receipts for in-person sales: on screen, printed, as a PDF, by email and
 * by WhatsApp. Each receipt also has a private web page (with a QR code on
 * the printout) that the customer can open again later.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

require_once PRDRWNDN_POS_DIR . 'assets/vendor/qrcode.php';

class PRDRWNDN_POS_Receipt {

	const OPT = 'prdrwndn_pos_receipt';

	/** Emails that carry the PDF receipt for POS sales. */
	const EMAILS = array( 'customer_invoice', 'customer_completed_order' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'woocommerce_email_attachments', array( __CLASS__, 'attach' ), 10, 3 );
		add_filter( 'woocommerce_email_subject_customer_invoice_paid', array( __CLASS__, 'subject' ), 10, 2 );
		add_filter( 'woocommerce_email_subject_customer_completed_order', array( __CLASS__, 'subject' ), 10, 2 );
	}

	/** In-person sales get a subject that says what it is: the receipt. */
	public static function subject( $subject, $order ) {
		if ( ! self::is_pos( $order ) ) {
			return $subject;
		}
		/* translators: 1: store name, 2: order number */
		return sprintf( __( 'Your receipt from %1$s (#%2$s)', 'prdrwndn-pos' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $order->get_order_number() );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'phone'     => '+1 602 888 2065',
			'instagram' => 'prdrwndn',
			'footer'    => 'Thank you for shopping PRDRWNDN. Murakoze!',
		);
	}

	public static function settings() {
		$s = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	public static function save( $phone, $instagram, $footer ) {
		update_option(
			self::OPT,
			array(
				'phone'     => mb_substr( sanitize_text_field( $phone ), 0, 40 ),
				'instagram' => mb_substr( ltrim( preg_replace( '/[^A-Za-z0-9._]/', '', (string) $instagram ), '.' ), 0, 30 ),
				'footer'    => mb_substr( sanitize_textarea_field( $footer ), 0, 300 ),
			),
			false
		);
	}

	/* ------------------------------------------------------------------ */
	/* Who and what                                                        */
	/* ------------------------------------------------------------------ */

	public static function is_pos( $order ) {
		return $order instanceof WC_Order && PRDRWNDN_POS_Sales::VIA === $order->get_created_via();
	}

	/** Managers see every POS receipt; cashiers only the sales they rang up. */
	public static function can_see( $order ) {
		if ( ! self::is_pos( $order ) || ! current_user_can( PRDRWNDN_POS_Roles::CAP_SELL ) ) {
			return false;
		}
		return current_user_can( PRDRWNDN_POS_Roles::CAP_MANAGE ) || (int) $order->get_meta( '_prdrwndn_pos_cashier' ) === get_current_user_id();
	}

	/** The customer's private link to this receipt. */
	public static function url( $order, $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'o' => $order->get_id(),
					'k' => $order->get_order_key(),
				),
				$args
			),
			PRDRWNDN_POS_App::url( 'receipt/' )
		);
	}

	public static function money( $n ) {
		$n      = round( (float) $n, 2 );
		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		return ( $n < 0 ? '-' : '' ) . $symbol . number_format( abs( $n ), 2, wc_get_price_decimal_separator(), wc_get_price_thousand_separator() );
	}

	private static function short( $name ) {
		return trim( preg_replace( '/^PRDRWNDN\s*®?\s*/iu', '', wp_strip_all_tags( (string) $name ) ) );
	}

	/** Everything a receipt shows, in one place for every format. */
	public static function data( $order ) {
		$s     = self::settings();
		$lines = array();
		$sub   = 0.0;
		foreach ( $order->get_items() as $item ) {
			$qty     = max( 1, (int) $item->get_quantity() );
			$amount  = (float) $item->get_subtotal();
			$sub    += $amount;
			$lines[] = array(
				'name'  => self::short( $item->get_name() ),
				'qty'   => $qty,
				'unit'  => $amount / $qty,
				'total' => $amount,
			);
		}
		$discounts = array();
		$charges   = array();
		foreach ( $order->get_fees() as $fee ) {
			$amt = (float) $fee->get_total();
			if ( $amt < 0 ) {
				$discounts[] = array(
					'label'  => wp_strip_all_tags( $fee->get_name() ),
					'amount' => -$amt,
				);
			} elseif ( $amt > 0 ) {
				$charges[] = array(
					'label'  => wp_strip_all_tags( $fee->get_name() ),
					'amount' => $amt,
				);
			}
		}
		$uid      = (int) $order->get_meta( '_prdrwndn_pos_cashier' );
		$cashier  = $uid ? get_userdata( $uid ) : null;
		$method   = str_replace( 'prdrwndn_pos_', '', $order->get_payment_method() );
		$created  = $order->get_date_created();
		$tendered = $order->get_meta( '_prdrwndn_pos_tendered' );
		$change   = $order->get_meta( '_prdrwndn_pos_change' );
		$refunded = (float) $order->get_total_refunded();
		$status   = $order->get_status();
		$site     = wp_parse_url( home_url(), PHP_URL_HOST );
		return array(
			'number'    => (string) $order->get_order_number(),
			'date'      => $created ? wp_date( 'D, M j, Y, g:i A', $created->getTimestamp() ) : '',
			'cashier'   => $cashier ? wp_strip_all_tags( $cashier->display_name ) : '',
			'customer'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'phone'     => (string) $order->get_billing_phone(),
			'email'     => (string) $order->get_billing_email(),
			'lines'     => $lines,
			'discounts' => $discounts,
			'charges'   => $charges,
			'subtotal'  => $sub,
			'tax'       => (float) $order->get_total_tax(),
			'total'     => (float) $order->get_total(),
			'method'    => PRDRWNDN_POS_Sales::METHODS[ $method ] ?? preg_replace( '/\s*\(in person\)$/', '', $order->get_payment_method_title() ),
			'tendered'  => '' === $tendered ? null : (float) $tendered,
			'change'    => '' === $change ? null : (float) $change,
			'refunded'  => $refunded,
			'status'    => $status,
			'cancelled' => in_array( $status, array( 'cancelled', 'failed' ), true ),
			'url'       => self::url( $order ),
			'site'      => $site ? preg_replace( '/^www\./', '', $site ) : '',
			'phone_biz' => $s['phone'],
			'instagram' => $s['instagram'],
			'footer'    => $s['footer'],
		);
	}

	/* ------------------------------------------------------------------ */
	/* QR code                                                             */
	/* ------------------------------------------------------------------ */

	/** Dark modules of a QR code for $text, as rows of booleans. */
	public static function qr( $text ) {
		$q    = PRDPOS_QRCode::getMinimumQRCode( (string) $text, PRDPOS_QR_ERROR_CORRECT_LEVEL_M );
		$n    = $q->getModuleCount();
		$rows = array();
		for ( $r = 0; $r < $n; $r++ ) {
			$row = array();
			for ( $c = 0; $c < $n; $c++ ) {
				$row[] = (bool) $q->isDark( $r, $c );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	public static function qr_svg( $text, $size = 120 ) {
		$rows = self::qr( $text );
		$n    = count( $rows );
		$q    = 4; // Quiet zone.
		$path = '';
		foreach ( $rows as $r => $row ) {
			foreach ( $row as $c => $dark ) {
				if ( $dark ) {
					$path .= 'M' . ( $c + $q ) . ' ' . ( $r + $q ) . 'h1v1h-1z';
				}
			}
		}
		$t = $n + 2 * $q;
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $t . ' ' . $t . '" width="' . (int) $size . '" height="' . (int) $size . '" shape-rendering="crispEdges" role="img" aria-label="QR code for this receipt"><rect width="' . $t . '" height="' . $t . '" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
	}

	/* ------------------------------------------------------------------ */
	/* On screen and printed                                               */
	/* ------------------------------------------------------------------ */

	public static function css() {
		return '.prdrcpt{box-sizing:border-box;width:100%;max-width:360px;margin:0 auto;padding:20px 18px 22px;background:#fff;color:#141414;font:14px/1.4 -apple-system,BlinkMacSystemFont,"Helvetica Neue",Helvetica,Arial,sans-serif;font-variant-numeric:tabular-nums;text-align:left}'
			. '.prdrcpt *{box-sizing:border-box}'
			. '.prdrcpt .rc-logo{display:block;width:112px;height:auto;margin:0 auto 8px}'
			. '.prdrcpt .rc-contact,.prdrcpt .rc-meta,.prdrcpt .rc-small,.prdrcpt .rc-foot{margin:0;text-align:center;color:#555;font-size:12px}'
			. '.prdrcpt .rc-title{margin:14px 0 2px;text-align:center;font-size:19px;font-weight:700;letter-spacing:.02em}'
			. '.prdrcpt .rc-void{margin:10px 0 0;text-align:center;font-weight:700;letter-spacing:.12em;color:#B42318;border:2px solid #B42318;padding:4px}'
			. '.prdrcpt table{width:100%;border-collapse:collapse;margin:0}'
			. '.prdrcpt .rc-items{margin-top:14px;border-top:1px dashed #999}'
			. '.prdrcpt .rc-items td{padding:8px 0 0;vertical-align:top}'
			. '.prdrcpt .rc-items .rc-name{font-weight:600}'
			. '.prdrcpt .rc-items .rc-each{display:block;color:#666;font-size:12px}'
			. '.prdrcpt td.r{text-align:right;white-space:nowrap;padding-left:12px}'
			. '.prdrcpt .rc-items tr.rc-disc td{color:#1E6B3A;padding-top:6px}'
			. '.prdrcpt .rc-sums{margin-top:12px;border-top:1px dashed #999}'
			. '.prdrcpt .rc-sums td{padding:5px 0 0}'
			. '.prdrcpt .rc-sums tr.rc-total td{font-size:18px;font-weight:700;padding-top:8px}'
			. '.prdrcpt .rc-sums tr.rc-dim td{color:#555;font-size:13px}'
			. '.prdrcpt .rc-qr{margin:16px auto 4px;width:112px;border-top:0}'
			. '.prdrcpt .rc-qr svg{display:block;width:112px;height:112px;fill:initial;stroke:none}'
			. '.prdrcpt .rc-qr svg rect{fill:#fff;stroke:none}.prdrcpt .rc-qr svg path{fill:#000;stroke:none}'
			. '.prdrcpt .rc-foot{margin-top:12px;color:#141414;font-weight:600}';
	}

	/**
	 * The receipt as HTML (the same block on the web page, in the app and when printed).
	 *
	 * @param WC_Order $order Order.
	 * @param string   $logo  Logo URL.
	 */
	public static function html( $order, $logo = '' ) {
		$d    = self::data( $order );
		$logo = $logo ? $logo : PRDRWNDN_POS_URL . 'assets/pos/logo.svg?v=' . PRDRWNDN_POS_VERSION;
		$out  = '<div class="prdrcpt">';
		$out .= '<img class="rc-logo" src="' . esc_url( $logo ) . '" alt="Proud Rwandan" width="112" height="84">';
		$out .= '<p class="rc-contact">' . esc_html( $d['site'] ) . '<br>' . esc_html( trim( ( $d['phone_biz'] ? 'WhatsApp ' . $d['phone_biz'] : '' ) . ( $d['instagram'] ? ' · @' . $d['instagram'] : '' ), ' ·' ) ) . '</p>';
		$out .= '<h1 class="rc-title">Receipt #' . esc_html( $d['number'] ) . '</h1>';
		$meta = array( $d['date'] );
		if ( $d['cashier'] ) {
			$meta[] = 'Cashier: ' . $d['cashier'];
		}
		if ( $d['customer'] ) {
			$meta[] = 'Customer: ' . $d['customer'];
		}
		$out .= '<p class="rc-meta">' . implode( '<br>', array_map( 'esc_html', $meta ) ) . '</p>';
		if ( $d['cancelled'] ) {
			$out .= '<p class="rc-void">CANCELLED</p>';
		}
		$out .= '<table class="rc-items">';
		foreach ( $d['lines'] as $l ) {
			$out .= '<tr><td><span class="rc-name">' . esc_html( $l['name'] ) . '</span><span class="rc-each">' . esc_html( $l['qty'] . ' x ' . self::money( $l['unit'] ) ) . '</span></td><td class="r">' . esc_html( self::money( $l['total'] ) ) . '</td></tr>';
		}
		foreach ( $d['charges'] as $c ) {
			$out .= '<tr><td>' . esc_html( $c['label'] ) . '</td><td class="r">' . esc_html( self::money( $c['amount'] ) ) . '</td></tr>';
		}
		foreach ( $d['discounts'] as $c ) {
			$out .= '<tr class="rc-disc"><td>' . esc_html( $c['label'] ) . '</td><td class="r">' . esc_html( self::money( -$c['amount'] ) ) . '</td></tr>';
		}
		$out .= '</table><table class="rc-sums">';
		$rows = self::sum_rows( $d );
		foreach ( $rows as $r ) {
			$out .= '<tr class="' . esc_attr( $r[2] ) . '"><td>' . esc_html( $r[0] ) . '</td><td class="r">' . esc_html( $r[1] ) . '</td></tr>';
		}
		$out .= '</table>';
		$out .= '<div class="rc-qr">' . self::qr_svg( $d['url'], 112 ) . '</div>';
		$out .= '<p class="rc-small">Scan to see this receipt online</p>';
		if ( '' !== trim( $d['footer'] ) ) {
			$out .= '<p class="rc-foot">' . nl2br( esc_html( $d['footer'] ) ) . '</p>';
		}
		$out .= '</div>';
		return $out;
	}

	/** Totals block rows: label, amount, css class. */
	private static function sum_rows( $d ) {
		$rows = array();
		if ( $d['discounts'] || $d['charges'] || $d['tax'] > 0 ) {
			$rows[] = array( 'Subtotal', self::money( $d['subtotal'] ), '' );
		}
		if ( $d['tax'] > 0 ) {
			$rows[] = array( 'Tax', self::money( $d['tax'] ), '' );
		}
		$rows[] = array( 'Total', self::money( $d['total'] ), 'rc-total' );
		$rows[] = array( 'Paid with', $d['method'], 'rc-dim' );
		if ( null !== $d['tendered'] ) {
			$rows[] = array( 'Cash given', self::money( $d['tendered'] ), 'rc-dim' );
			$rows[] = array( 'Change', self::money( (float) $d['change'] ), 'rc-dim' );
		}
		if ( $d['refunded'] > 0 ) {
			$rows[] = array( 'Refunded', self::money( -$d['refunded'] ), '' );
			$rows[] = array( 'Paid after refund', self::money( $d['total'] - $d['refunded'] ), 'rc-total' );
		}
		return $rows;
	}

	/** Plain text for WhatsApp. */
	public static function text( $order ) {
		$d     = self::data( $order );
		$lines = array( 'PRDRWNDN receipt #' . $d['number'], $d['date'], '' );
		foreach ( $d['lines'] as $l ) {
			$lines[] = $l['qty'] . ' x ' . $l['name'] . ': ' . self::money( $l['total'] );
		}
		foreach ( $d['discounts'] as $c ) {
			$lines[] = $c['label'] . ': ' . self::money( -$c['amount'] );
		}
		if ( $d['tax'] > 0 ) {
			$lines[] = 'Tax: ' . self::money( $d['tax'] );
		}
		$lines[] = 'Total: ' . self::money( $d['total'] ) . ' (' . $d['method'] . ')';
		if ( $d['refunded'] > 0 ) {
			$lines[] = 'Refunded: ' . self::money( -$d['refunded'] );
		}
		if ( $d['cancelled'] ) {
			$lines[] = 'This sale was cancelled.';
		}
		$lines[] = '';
		$lines[] = 'View or download: ' . $d['url'];
		if ( '' !== trim( $d['footer'] ) ) {
			$lines[] = $d['footer'];
		}
		return implode( "\n", $lines );
	}

	/* ------------------------------------------------------------------ */
	/* PDF (4 x 6 in, fits the label printer and any phone)                 */
	/* ------------------------------------------------------------------ */

	public static function pdf( $order ) {
		$d   = self::data( $order );
		$pdf = new PRDRWNDN_POS_PDF( 288, 432 );
		$m   = 20;              // Side margins.
		$w   = 288 - 2 * $m;    // Text width.
		$bot = 432 - 22;        // Lowest baseline on a page.
		$y   = 0;
		$pdf->add_page();

		$need = static function ( $h ) use ( &$y, $pdf, $bot ) {
			if ( $y + $h > $bot ) {
				$pdf->add_page();
				$y = 26;
			}
		};

		// Header.
		$pdf->jpeg( PRDRWNDN_POS_DIR . 'assets/pos/receipt-logo.jpg', ( 288 - 72 ) / 2, 14, 72, 54 );
		$y        = 80;
		$contact  = trim( ( $d['phone_biz'] ? 'WhatsApp ' . $d['phone_biz'] : '' ) . ( $d['instagram'] ? ' · @' . $d['instagram'] : '' ), ' ·' );
		$pdf->text( 144, $y, $d['site'], 7.5, false, 'center', 0.33 );
		$y += 10;
		if ( $contact ) {
			$pdf->text( 144, $y, $contact, 7.5, false, 'center', 0.33 );
			$y += 10;
		}
		$y += 7;
		$pdf->text( 144, $y, 'Receipt #' . $d['number'], 13, true, 'center' );
		$y += 12;
		$meta = array( $d['date'] );
		if ( $d['cashier'] ) {
			$meta[] = 'Cashier: ' . $d['cashier'];
		}
		if ( $d['customer'] ) {
			$meta[] = 'Customer: ' . $d['customer'];
		}
		foreach ( $meta as $line ) {
			$pdf->text( 144, $y, $line, 8, false, 'center', 0.33 );
			$y += 10;
		}
		if ( $d['cancelled'] ) {
			$y += 4;
			$pdf->text( 144, $y + 9, 'CANCELLED', 12, true, 'center' );
			$y += 18;
		}
		$y += 2;
		$pdf->line( $m, $y, 288 - $m, $y, 0.6, 0.5, 2 );
		$y += 13;

		// Items.
		foreach ( $d['lines'] as $l ) {
			$price = self::money( $l['total'] );
			$pw    = $pdf->text_width( $price, 9, false ) + 10;
			$names = $pdf->wrap( $l['name'], 9, $w - $pw, true );
			$need( 11 * count( $names ) + 12 );
			foreach ( $names as $i => $n ) {
				$pdf->text( $m, $y, $n, 9, true );
				if ( 0 === $i ) {
					$pdf->text( 288 - $m, $y, $price, 9, false, 'right' );
				}
				$y += 10;
			}
			$pdf->text( $m, $y, $l['qty'] . ' x ' . self::money( $l['unit'] ), 7.5, false, 'left', 0.4 );
			$y += 12.5;
		}
		foreach ( array_merge( $d['charges'], array_map( static function ( $x ) { $x['amount'] = -$x['amount']; return $x; }, $d['discounts'] ) ) as $c ) {
			$price = self::money( $c['amount'] );
			$names = $pdf->wrap( $c['label'], 8.5, $w - $pdf->text_width( $price, 8.5 ) - 10 );
			$need( 11 * count( $names ) + 3 );
			foreach ( $names as $i => $n ) {
				$pdf->text( $m, $y, $n, 8.5, false, 'left', $c['amount'] < 0 ? 0.15 : 0 );
				if ( 0 === $i ) {
					$pdf->text( 288 - $m, $y, $price, 8.5, false, 'right' );
				}
				$y += 11;
			}
			$y += 3;
		}

		// Totals.
		$need( 40 );
		$y -= 4;
		$pdf->line( $m, $y, 288 - $m, $y, 0.6, 0.5, 2 );
		$y += 14;
		foreach ( self::sum_rows( $d ) as $r ) {
			$big  = 'rc-total' === $r[2];
			$size = $big ? 12 : 8.5;
			$need( $size + 6 );
			if ( $big ) {
				$y += 2;
			}
			$pdf->text( $m, $y, $r[0], $size, $big, 'left', 'rc-dim' === $r[2] ? 0.33 : 0 );
			$pdf->text( 288 - $m, $y, $r[1], $size, $big, 'right', 'rc-dim' === $r[2] ? 0.33 : 0 );
			$y += $big ? 15 : 10.5;
		}

		// QR code and thanks.
		$footer = '' !== trim( $d['footer'] ) ? $pdf->wrap( $d['footer'], 8, $w, true ) : array();
		$extra  = 22 + 10.5 * count( $footer );
		// As big as fits on this page (down to a size phones still scan), else a new page.
		$qrsize = min( 60, $bot - $y - 2 - $extra );
		if ( $qrsize < 46 ) {
			$need( 999 );
			$qrsize = 60;
		}
		$y += 2;
		$rows = self::qr( $d['url'] );
		$cell = $qrsize / count( $rows );
		$x0   = ( 288 - $qrsize ) / 2;
		$sq   = array();
		foreach ( $rows as $r => $row ) {
			foreach ( $row as $c => $dark ) {
				if ( $dark ) {
					$sq[] = array( $x0 + $c * $cell, $y + $r * $cell, $cell + 0.02 );
				}
			}
		}
		$pdf->squares( $sq );
		$y += $qrsize + 9;
		$pdf->text( 144, $y, 'Scan to see this receipt online', 7, false, 'center', 0.4 );
		$y += 12.5;
		foreach ( $footer as $f ) {
			$pdf->text( 144, $y, $f, 8, true, 'center' );
			$y += 10.5;
		}
		return $pdf->output( 'PRDRWNDN receipt #' . $d['number'] );
	}

	public static function filename( $order ) {
		return 'PRDRWNDN-receipt-' . sanitize_file_name( (string) $order->get_order_number() ) . '.pdf';
	}

	/* ------------------------------------------------------------------ */
	/* Public receipt page: /pos/receipt/?o=ID&k=KEY                        */
	/* ------------------------------------------------------------------ */

	public static function serve() {
		$id    = isset( $_GET['o'] ) ? absint( $_GET['o'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$key   = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$order = $id ? wc_get_order( $id ) : null;
		$ok    = $order && self::is_pos( $order ) && '' !== $key && hash_equals( (string) $order->get_order_key(), $key );

		if ( $ok && isset( $_GET['pdf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$bytes = self::pdf( $order );
			header( 'Content-Type: application/pdf' );
			header( 'Content-Length: ' . strlen( $bytes ) );
			header( 'Content-Disposition: ' . ( isset( $_GET['download'] ) ? 'attachment' : 'inline' ) . '; filename="' . self::filename( $order ) . '"' ); // phpcs:ignore WordPress.Security.NonceVerification
			echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}

		if ( ! $ok ) {
			status_header( 404 );
		}
		header( 'Content-Type: text/html; charset=utf-8' );
		$title = $ok ? 'PRDRWNDN receipt #' . $order->get_order_number() : 'Receipt not found';
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title ); ?></title>
<style>
body{margin:0;background:#EFEBE3;padding:24px 12px 40px;font:15px/1.4 -apple-system,BlinkMacSystemFont,"Helvetica Neue",Helvetica,Arial,sans-serif;color:#141414}
.wrap{max-width:380px;margin:0 auto}
.paper{box-shadow:0 1px 2px rgba(0,0,0,.08),0 8px 24px rgba(0,0,0,.08);border-radius:6px;overflow:hidden}
.acts{display:flex;gap:10px;justify-content:center;margin:18px 0 0}
.acts a,.acts button{flex:1;display:inline-flex;align-items:center;justify-content:center;min-height:46px;border-radius:10px;border:1px solid #1E3B2F;background:#1E3B2F;color:#fff;font-family:inherit;font-size:15px;font-weight:600;line-height:1;text-decoration:none;cursor:pointer}
.acts button{background:#fff;color:#1E3B2F}
.miss{background:#fff;border-radius:8px;padding:28px 20px;text-align:center}
<?php echo self::css(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
@media print{body{background:#fff;padding:0}.acts{display:none}.paper{box-shadow:none}}
</style>
</head>
<body>
<main class="wrap">
		<?php if ( $ok ) : ?>
	<div class="paper"><?php echo self::html( $order ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
	<div class="acts">
		<a href="<?php echo esc_url( self::url( $order, array( 'pdf' => 1, 'download' => 1 ) ) ); ?>">Download PDF</a>
		<button type="button" onclick="window.print()">Print</button>
	</div>
		<?php else : ?>
	<div class="miss"><h1>Receipt not found</h1><p>Check the link, or ask the store to send it again.</p></div>
		<?php endif; ?>
</main>
</body>
</html>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Email                                                               */
	/* ------------------------------------------------------------------ */

	/** Send the receipt by email from the store (WooCommerce invoice email, PDF attached). */
	public static function email( $order, $to ) {
		$to = sanitize_email( $to );
		if ( ! $to || ! is_email( $to ) ) {
			return new WP_Error( 'bad_email', __( 'That email address does not look right.', 'prdrwndn-pos' ), array( 'status' => 400 ) );
		}
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return new WP_Error( 'no_mailer', __( 'The store cannot send email right now.', 'prdrwndn-pos' ), array( 'status' => 500 ) );
		}
		$emails = WC()->mailer()->get_emails();
		$mail   = $emails['WC_Email_Customer_Invoice'] ?? null;
		if ( ! $mail ) {
			return new WP_Error( 'no_mailer', __( 'The store cannot send email right now.', 'prdrwndn-pos' ), array( 'status' => 500 ) );
		}
		if ( $order->get_billing_email() !== $to ) {
			$order->set_billing_email( $to );
			$order->save();
		}
		$failed = null;
		$catch  = static function ( $err ) use ( &$failed ) {
			$failed = $err;
		};
		add_action( 'wp_mail_failed', $catch );
		$mail->trigger( $order->get_id(), $order );
		remove_action( 'wp_mail_failed', $catch );
		if ( $failed instanceof WP_Error ) {
			return new WP_Error( 'mail_failed', __( 'The email could not be sent: ', 'prdrwndn-pos' ) . $failed->get_error_message(), array( 'status' => 502 ) );
		}
		/* translators: 1: email address, 2: user */
		$order->add_order_note( sprintf( __( 'Receipt emailed to %1$s from the POS by %2$s.', 'prdrwndn-pos' ), $to, wp_get_current_user()->display_name ) );
		return true;
	}

	/** POS receipts go out with the PDF attached. */
	public static function attach( $attachments, $email_id, $object ) {
		if ( ! in_array( $email_id, self::EMAILS, true ) || ! self::is_pos( $object ) ) {
			return $attachments;
		}
		$dir = trailingslashit( get_temp_dir() ) . 'prdpos-' . wp_generate_password( 10, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return $attachments;
		}
		$file = $dir . '/' . self::filename( $object );
		if ( false === file_put_contents( $file, self::pdf( $object ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $attachments;
		}
		$attachments   = (array) $attachments;
		$attachments[] = $file;
		register_shutdown_function(
			static function () use ( $file, $dir ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			}
		);
		return $attachments;
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	public static function routes() {
		$ns = PRDRWNDN_POS_REST::NS;
		register_rest_route(
			$ns,
			'/pos/receipt/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_get' ),
				'permission_callback' => array( __CLASS__, 'rest_can' ),
			)
		);
		register_rest_route(
			$ns,
			'/pos/receipt/(?P<id>\d+)/email',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_email' ),
				'permission_callback' => array( __CLASS__, 'rest_can' ),
				'args'                => array(
					'email' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public static function rest_can( WP_REST_Request $req ) {
		if ( ! current_user_can( PRDRWNDN_POS_Roles::CAP_SELL ) ) {
			return false;
		}
		$order = wc_get_order( (int) $req['id'] );
		if ( ! self::is_pos( $order ) ) {
			return new WP_Error( 'not_found', __( 'That sale was not found.', 'prdrwndn-pos' ), array( 'status' => 404 ) );
		}
		if ( ! self::can_see( $order ) ) {
			return new WP_Error( 'forbidden', __( 'Only the owner can see receipts for other people\'s sales.', 'prdrwndn-pos' ), array( 'status' => 403 ) );
		}
		return true;
	}

	public static function rest_get( WP_REST_Request $req ) {
		$order = wc_get_order( (int) $req['id'] );
		$d     = self::data( $order );
		return rest_ensure_response(
			array(
				'id'        => $order->get_id(),
				'number'    => $d['number'],
				'html'      => self::html( $order, PRDRWNDN_POS_URL . 'assets/pos/logo.svg?v=' . PRDRWNDN_POS_VERSION ),
				'css'       => self::css(),
				'text'      => self::text( $order ),
				'url'       => $d['url'],
				'pdf'       => self::url( $order, array( 'pdf' => 1 ) ),
				'download'  => self::url( $order, array( 'pdf' => 1, 'download' => 1 ) ),
				'filename'  => self::filename( $order ),
				'email'     => $d['email'],
				'phone'     => $d['phone'],
				'customer'  => $d['customer'],
				'total'     => $d['total'],
				'cancelled' => $d['cancelled'],
			)
		);
	}

	public static function rest_email( WP_REST_Request $req ) {
		$order = wc_get_order( (int) $req['id'] );
		$res   = self::email( $order, (string) $req->get_param( 'email' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response(
			array(
				'sent' => true,
				'to'   => $order->get_billing_email(),
			)
		);
	}
}
