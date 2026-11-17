<?php
/**
 * Suggests short, readable SKUs for every product and variation.
 *
 * Pattern: PREFIX-PRODUCT-OPTION-OPTION, for example
 *   PRD-FR-DR       Car freshener, Desert Rose
 *   PRD-JR-IZ-W-XL  Izuba jersey, White, XL
 *
 * Existing SKUs are never changed unless the owner edits them.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_SKU_Builder {

	/** Words that name the product type, mapped to a 2-letter code. */
	const TYPE_WORDS = array(
		'freshener'     => 'FR',
		'fresheners'    => 'FR',
		'jersey'        => 'JR',
		'jerseys'       => 'JR',
		'flag'          => 'FL',
		'flags'         => 'FL',
		'idarapo'       => 'FL',
		't-shirt'       => 'TS',
		'tshirt'        => 'TS',
		'shirt'         => 'TS',
		'tee'           => 'TS',
		'hoodie'        => 'HD',
		'sweatshirt'    => 'SW',
		'hat'           => 'HT',
		'cap'           => 'HT',
		'plate'         => 'LP',
		'sticker'       => 'ST',
		'stickers'      => 'ST',
		'mug'           => 'MG',
		'bag'           => 'BG',
		'keychain'      => 'KC',
	);

	/** Words that never help tell products apart. */
	const SKIP_WORDS = array(
		'prdrwndn', 'proud', 'rwandan', 'rwanda', 'the', 'and', 'of', 'for', 'with', 'a', 'an', 'car',
		'country', 'special', 'bundle', 'buy', 'get', 'free', 'vintage', 'distressed', 'heritage', 'design',
		'license', 'frame', 'in', 'on', 'x',
	);

	const SIZES = array(
		'xxs'      => 'XXS',
		'xs'       => 'XS',
		's'        => 'S',
		'small'    => 'S',
		'm'        => 'M',
		'medium'   => 'M',
		'l'        => 'L',
		'large'    => 'L',
		'xl'       => 'XL',
		'x-large'  => 'XL',
		'xxl'      => 'XXL',
		'2xl'      => '2XL',
		'xx-large' => 'XXL',
		'xxxl'     => '3XL',
		'3xl'      => '3XL',
		'4xl'      => '4XL',
		'5xl'      => '5XL',
	);

	const COLORS = array(
		'white'  => 'W',
		'yellow' => 'Y',
		'black'  => 'BK',
		'green'  => 'GN',
		'blue'   => 'BL',
		'red'    => 'RD',
		'grey'   => 'GY',
		'gray'   => 'GY',
		'navy'   => 'NV',
		'pink'   => 'PK',
		'orange' => 'OR',
		'purple' => 'PU',
		'brown'  => 'BR',
		'gold'   => 'GD',
		'silver' => 'SV',
		'cream'  => 'CR',
	);

	/**
	 * Every product row for the SKU screen, with a suggested SKU where one is missing.
	 *
	 * @param string $prefix SKU prefix, for example "PRD".
	 * @return array[] Rows: id, parent_id, title, variant, type, sku, gtin, stock, suggested.
	 */
	public static function rows( $prefix ) {
		$products = wc_get_products(
			array(
				'limit'   => -1,
				'status'  => array( 'publish', 'private', 'draft', 'pending' ),
				'type'    => array( 'simple', 'variable' ),
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);

		$codes = self::product_codes( $products );
		$taken = array();
		$rows  = array();

		foreach ( $products as $product ) {
			$items = array( $product );
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$child = wc_get_product( $child_id );
					if ( $child ) {
						$items[] = $child;
					}
				}
			}
			foreach ( $items as $item ) {
				$is_var  = $item->is_type( 'variation' );
				$parts   = array( $prefix, $codes[ $product->get_id() ] );
				$variant = '';
				if ( $is_var ) {
					$parts   = array_merge( $parts, self::option_codes( $item, $product ) );
					$variant = wc_get_formatted_variation( $item, true, false, false );
				}
				$suggested = '';
				if ( '' === $item->get_sku( 'edit' ) ) {
					$suggested = self::unique( self::join( $parts ), $item->get_id(), $taken );
					$taken[]   = $suggested;
				}
				$rows[] = array(
					'id'        => $item->get_id(),
					'parent_id' => $item->get_parent_id(),
					'title'     => $product->get_name(),
					'variant'   => html_entity_decode( wp_strip_all_tags( $variant ), ENT_QUOTES, 'UTF-8' ),
					'type'      => $item->get_type(),
					'sku'       => $item->get_sku( 'edit' ),
					'gtin'      => method_exists( $item, 'get_global_unique_id' ) ? $item->get_global_unique_id( 'edit' ) : '',
					'stock'     => $item->managing_stock() ? (int) $item->get_stock_quantity() : null,
					'suggested' => $suggested,
				);
			}
		}
		return $rows;
	}

	/**
	 * A short code per parent product, unique across the store.
	 *
	 * @param WC_Product[] $products Parent products.
	 * @return array Map of product ID to code.
	 */
	public static function product_codes( $products ) {
		$base = array();
		foreach ( $products as $p ) {
			$base[ $p->get_id() ] = self::base_code( $p->get_name() );
		}
		$counts = array_count_values( $base );
		$codes  = array();
		$used   = array();
		foreach ( $products as $p ) {
			$id   = $p->get_id();
			$code = $base[ $id ];
			if ( $counts[ $code ] > 1 ) {
				$extra = self::distinct_word_code( $p->get_name(), $code );
				if ( '' !== $extra && $extra !== $code ) {
					$code .= '-' . $extra;
				}
			}
			$try = $code;
			$n   = 2;
			while ( in_array( $try, $used, true ) ) {
				$try = $code . $n;
				$n++;
			}
			$used[]       = $try;
			$codes[ $id ] = $try;
		}
		return $codes;
	}

	/**
	 * Code from a product name: a known type word, else initials.
	 *
	 * @param string $name Product name.
	 * @return string
	 */
	public static function base_code( $name ) {
		$words = self::words( $name );
		// The product noun usually comes last ("Flag Vintage T-Shirt" is a shirt).
		foreach ( array_reverse( $words ) as $w ) {
			if ( isset( self::TYPE_WORDS[ $w ] ) ) {
				return self::TYPE_WORDS[ $w ];
			}
		}
		$sig = self::significant( $words );
		if ( empty( $sig ) ) {
			$sig = $words;
		}
		if ( empty( $sig ) ) {
			return 'P';
		}
		if ( count( $sig ) >= 2 ) {
			$out = '';
			foreach ( array_slice( $sig, 0, 3 ) as $w ) {
				$out .= $w[0];
			}
			return strtoupper( $out );
		}
		return strtoupper( substr( $sig[0], 0, 3 ) );
	}

	/**
	 * Two letters from the first word that is not a type word, e.g. Izuba -> IZ.
	 *
	 * @param string $name Product name.
	 * @param string $base The product's type code, so that word is not repeated.
	 * @return string
	 */
	public static function distinct_word_code( $name, $base = '' ) {
		foreach ( self::significant( self::words( $name ) ) as $w ) {
			$same_type = isset( self::TYPE_WORDS[ $w ] ) && self::TYPE_WORDS[ $w ] === $base;
			if ( ! $same_type && ! ctype_digit( $w ) ) {
				return strtoupper( substr( $w, 0, 2 ) );
			}
		}
		return '';
	}

	/**
	 * Codes for a variation's options, in the parent's attribute order.
	 *
	 * @param WC_Product_Variation $variation Variation.
	 * @param WC_Product           $parent    Parent product.
	 * @return string[]
	 */
	public static function option_codes( $variation, $parent ) {
		$values = $variation->get_attributes( 'edit' );
		$out    = array();
		foreach ( array_keys( $parent->get_attributes() ) as $key ) {
			if ( ! isset( $values[ $key ] ) || '' === $values[ $key ] ) {
				continue; // "Any" option: leave it out.
			}
			$value = $values[ $key ];
			if ( taxonomy_exists( $key ) ) {
				$term  = get_term_by( 'slug', $value, $key );
				$value = $term ? $term->name : $value;
			}
			$code = self::option_code( $value );
			if ( '' !== $code ) {
				$out[] = $code;
			}
		}
		return $out;
	}

	/**
	 * Code for one option value: sizes and colors have fixed codes,
	 * several words use initials (Desert Rose -> DR), else 3 letters.
	 *
	 * @param string $value Option value.
	 * @return string
	 */
	public static function option_code( $value ) {
		$plain = strtolower( trim( html_entity_decode( wp_strip_all_tags( $value ), ENT_QUOTES, 'UTF-8' ) ) );
		if ( isset( self::SIZES[ $plain ] ) ) {
			return self::SIZES[ $plain ];
		}
		if ( isset( self::COLORS[ $plain ] ) ) {
			return self::COLORS[ $plain ];
		}
		$words = self::words( $plain );
		if ( empty( $words ) ) {
			return '';
		}
		if ( count( $words ) >= 2 ) {
			$out = '';
			foreach ( array_slice( $words, 0, 3 ) as $w ) {
				$out .= ctype_digit( $w ) ? $w : $w[0];
			}
			return strtoupper( $out );
		}
		$w = $words[0];
		return strtoupper( ctype_digit( $w ) ? substr( $w, 0, 4 ) : substr( $w, 0, 3 ) );
	}

	/**
	 * Keep only characters that are safe in a barcode and easy to read.
	 *
	 * @param string $sku SKU.
	 * @return string
	 */
	public static function sanitize( $sku ) {
		$sku = strtoupper( trim( (string) $sku ) );
		$sku = preg_replace( '/[^A-Z0-9-]+/', '-', $sku );
		$sku = preg_replace( '/-+/', '-', $sku );
		return trim( $sku, '-' );
	}

	/**
	 * Valid GTIN check (8, 12, 13 or 14 digits with a correct check digit).
	 *
	 * @param string $gtin Code.
	 * @return bool
	 */
	public static function valid_gtin( $gtin ) {
		if ( ! preg_match( '/^\d{8}$|^\d{12,14}$/', $gtin ) ) {
			return false;
		}
		$digits = array_map( 'intval', str_split( $gtin ) );
		$check  = array_pop( $digits );
		$sum    = 0;
		$digits = array_reverse( $digits );
		foreach ( $digits as $i => $d ) {
			$sum += ( 0 === $i % 2 ) ? $d * 3 : $d;
		}
		return ( 10 - $sum % 10 ) % 10 === $check;
	}

	private static function join( $parts ) {
		return self::sanitize( implode( '-', array_filter( $parts, 'strlen' ) ) );
	}

	private static function unique( $sku, $own_id, $taken ) {
		$try = $sku;
		$n   = 2;
		while ( in_array( $try, $taken, true ) || self::used_elsewhere( $try, $own_id ) ) {
			$try = $sku . '-' . $n;
			$n++;
		}
		return $try;
	}

	private static function used_elsewhere( $sku, $own_id ) {
		$id = (int) wc_get_product_id_by_sku( $sku );
		return $id && $id !== (int) $own_id;
	}

	private static function words( $text ) {
		$text = strtolower( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
		$text = str_replace( array( '®', '™', '©' ), ' ', $text );
		$text = preg_replace( '/\bt-shirts?\b/', 'tshirt', $text );
		preg_match_all( '/[a-z0-9]+/', $text, $m );
		return $m[0];
	}

	private static function significant( $words ) {
		return array_values(
			array_filter(
				$words,
				static function ( $w ) {
					return ! in_array( $w, self::SKIP_WORDS, true ) && ! preg_match( '/^\d+x\d+[a-z]*$/', $w );
				}
			)
		);
	}
}
