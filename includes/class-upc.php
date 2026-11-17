<?php
/**
 * UPC-A barcodes: the 12-digit kind with numbers under the bars.
 *
 * Products with a real GS1 UPC (like Black Ice) use it. Every other product
 * and option gets an in-store number: 4 + the product's ID (10 digits) +
 * a check digit. UPC numbers starting with 4 are set aside by GS1 for a
 * business's own use, so they never clash with real products. Because the
 * number comes from the product ID, nothing needs to be stored and two
 * products can never share a number.
 *
 * In-store numbers are never put in WooCommerce's GTIN field: Google
 * Shopping rejects GTINs starting with 2, 02 or 04.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_UPC {

	const L = array( '0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011' );

	/** Quiet zone each side, in modules (GS1 asks for 9). */
	const QUIET = 9;

	/**
	 * GS1 check digit for a string of digits (without the check digit).
	 *
	 * @param string $digits Digits.
	 * @return int
	 */
	public static function check_digit( $digits ) {
		$sum = 0;
		$rev = strrev( (string) $digits );
		$len = strlen( $rev );
		for ( $i = 0; $i < $len; $i++ ) {
			$sum += (int) $rev[ $i ] * ( 0 === $i % 2 ? 3 : 1 );
		}
		return ( 10 - $sum % 10 ) % 10;
	}

	/**
	 * Whether a 12-digit string is a valid UPC-A.
	 *
	 * @param string $upc Number.
	 * @return bool
	 */
	public static function valid( $upc ) {
		return (bool) preg_match( '/^\d{12}$/', (string) $upc ) && self::check_digit( substr( $upc, 0, 11 ) ) === (int) $upc[11];
	}

	/**
	 * In-store number for a product or variation ID.
	 *
	 * @param int $id Product ID.
	 * @return string 12 digits.
	 */
	public static function internal_number( $id ) {
		$body = '4' . str_pad( (string) absint( $id ), 10, '0', STR_PAD_LEFT );
		return $body . self::check_digit( $body );
	}

	/**
	 * Product ID inside an in-store number, or 0 if it is not one.
	 *
	 * @param string $upc Number.
	 * @return int
	 */
	public static function id_from_internal( $upc ) {
		if ( ! self::valid( $upc ) || '4' !== $upc[0] ) {
			return 0;
		}
		return (int) substr( $upc, 1, 10 );
	}

	/**
	 * Real UPC from a GTIN field value, if it can be printed as UPC-A.
	 *
	 * @param string $gtin GTIN (8, 12, 13 or 14 digits).
	 * @return string 12 digits, or ''.
	 */
	public static function upc_from_gtin( $gtin ) {
		$gtin = preg_replace( '/\D+/', '', (string) $gtin );
		if ( 14 === strlen( $gtin ) && '00' === substr( $gtin, 0, 2 ) ) {
			$gtin = substr( $gtin, 2 );
		} elseif ( 13 === strlen( $gtin ) && '0' === $gtin[0] ) {
			$gtin = substr( $gtin, 1 );
		}
		return self::valid( $gtin ) ? $gtin : '';
	}

	/**
	 * Whether a number is in a range Google and GS1 keep for internal use (2, 02, 04).
	 *
	 * @param string $gtin Digits.
	 * @return bool
	 */
	public static function is_restricted( $gtin ) {
		$gtin = preg_replace( '/\D+/', '', (string) $gtin );
		$len  = strlen( $gtin );
		if ( 14 === $len ) {
			$gtin = substr( $gtin, 1 );
			$len  = 13;
		}
		$g13 = 12 === $len ? '0' . $gtin : $gtin;
		if ( 13 !== strlen( $g13 ) ) {
			return 8 === $len && '2' === $gtin[0];
		}
		return '2' === $g13[0] || '02' === substr( $g13, 0, 2 ) || '04' === substr( $g13, 0, 2 );
	}

	/**
	 * The barcode a product prints: its real UPC if it has one, else its in-store number.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array number, kind ('upc' or 'store').
	 */
	public static function for_product( $product ) {
		$gtin = method_exists( $product, 'get_global_unique_id' ) ? $product->get_global_unique_id( 'edit' ) : '';
		$upc  = self::upc_from_gtin( $gtin );
		if ( '' !== $upc ) {
			return array(
				'number' => $upc,
				'kind'   => 'upc',
			);
		}
		return array(
			'number' => self::internal_number( $product->get_id() ),
			'kind'   => 'store',
		);
	}

	/**
	 * Bars for a UPC-A, including quiet zones.
	 *
	 * @param string $upc 12 digits.
	 * @return array modules (1 = bar), long (1 = guard-length bar, includes first and last digit).
	 */
	public static function modules( $upc ) {
		if ( ! self::valid( $upc ) ) {
			throw new InvalidArgumentException( 'Not a valid UPC-A number.' );
		}
		$mods = '';
		$long = '';
		$add  = static function ( $bits, $is_long ) use ( &$mods, &$long ) {
			$mods .= $bits;
			$long .= str_repeat( $is_long ? '1' : '0', strlen( $bits ) );
		};
		$add( str_repeat( '0', self::QUIET ), false );
		$add( '101', true );
		for ( $i = 0; $i < 6; $i++ ) {
			$add( self::L[ (int) $upc[ $i ] ], 0 === $i );
		}
		$add( '01010', true );
		for ( $i = 6; $i < 12; $i++ ) {
			$add( strtr( self::L[ (int) $upc[ $i ] ], '01', '10' ), 11 === $i );
		}
		$add( '101', true );
		$add( str_repeat( '0', self::QUIET ), false );
		return array(
			'modules' => $mods,
			'long'    => $long,
		);
	}

	/**
	 * Human-readable form: 1 99874 89194 1.
	 *
	 * @param string $upc 12 digits.
	 * @return string
	 */
	public static function pretty( $upc ) {
		return substr( $upc, 0, 1 ) . ' ' . substr( $upc, 1, 5 ) . ' ' . substr( $upc, 6, 5 ) . ' ' . substr( $upc, 11, 1 );
	}

	/**
	 * Small inline SVG with the digits, for admin screens.
	 *
	 * @param string $upc    12 digits.
	 * @param float  $module Module width in px.
	 * @param int    $height Normal bar height in px.
	 * @return string
	 */
	public static function svg( $upc, $module = 1.0, $height = 34 ) {
		$m     = self::modules( $upc );
		$mods  = $m['modules'];
		$long  = $m['long'];
		$n     = strlen( $mods );
		$ext   = 5;
		$fs    = 9.6;
		$h     = $height + 11;
		$rects = '';
		for ( $i = 0; $i < $n; $i++ ) {
			if ( '1' === $mods[ $i ] ) {
				$bh     = $height + ( '1' === $long[ $i ] ? $ext : 0 );
				$rects .= sprintf( 'M%s 0h%sv%dh-%sz', self::num( $i * $module ), self::num( $module ), $bh, self::num( $module ) );
			}
		}
		$q    = self::QUIET;
		$text = '';
		$put  = static function ( $x, $digit, $size ) use ( &$text, $height, $module ) {
			$text .= sprintf( '<text x="%s" y="%s" font-size="%s" text-anchor="middle">%s</text>', self::num( $x * $module ), self::num( $height + 8.5 ), self::num( $size * $module ), esc_html( $digit ) );
		};
		$put( $q / 2, $upc[0], 7.4 );
		for ( $k = 0; $k < 5; $k++ ) {
			$put( $q + 10 + 7 * $k + 3.5, $upc[ 1 + $k ], $fs );
			$put( $q + 50 + 7 * $k + 3.5, $upc[ 6 + $k ], $fs );
		}
		$put( $q + 95 + $q / 2, $upc[11], 7.4 );
		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" width="%1$s" height="%2$d" viewBox="0 0 %1$s %2$d" role="img" aria-label="%3$s"><rect width="100%%" height="100%%" fill="#fff"/><path d="%4$s" fill="#000" shape-rendering="crispEdges"/><g font-family="Helvetica, Arial, sans-serif" font-weight="600" fill="#000">%5$s</g></svg>',
			self::num( $n * $module ),
			$h,
			esc_attr( 'UPC ' . $upc ),
			$rects,
			$text
		);
	}

	private static function num( $n ) {
		return rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
	}
}
