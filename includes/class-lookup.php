<?php
/**
 * Find a product from a scanned code (SKU or UPC/GTIN).
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_Lookup {

	/**
	 * Clean up what a scanner or camera sends (spaces, line breaks).
	 *
	 * @param string $code Raw code.
	 * @return string
	 */
	public static function clean( $code ) {
		$code = preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $code );
		return trim( $code );
	}

	/**
	 * Codes to try for one scan. Cameras often read a UPC-A as a 13-digit
	 * EAN with a leading 0, and GS1 shows GTIN-14 with two leading zeros.
	 *
	 * @param string $code Clean code.
	 * @return string[]
	 */
	public static function candidates( $code ) {
		$list = array( $code );
		if ( strtoupper( $code ) !== $code ) {
			$list[] = strtoupper( $code );
		}
		if ( ctype_digit( $code ) ) {
			$len = strlen( $code );
			if ( 13 === $len && '0' === $code[0] ) {
				$list[] = substr( $code, 1 );
			}
			if ( 14 === $len && '00' === substr( $code, 0, 2 ) ) {
				$list[] = substr( $code, 2 );
				$list[] = substr( $code, 1 );
			}
			if ( 12 === $len ) {
				$list[] = '0' . $code;
				$list[] = '00' . $code;
			}
		}
		return array_values( array_unique( $list ) );
	}

	/**
	 * Product ID for a scanned code, or 0.
	 *
	 * @param string $code Raw code.
	 * @return int
	 */
	public static function find_id( $code ) {
		$code = self::clean( $code );
		if ( '' === $code ) {
			return 0;
		}
		$candidates = self::candidates( $code );
		foreach ( $candidates as $try ) {
			$id = (int) wc_get_product_id_by_sku( $try );
			if ( $id ) {
				return $id;
			}
			$id = self::id_by_gtin( $try );
			if ( $id ) {
				return $id;
			}
		}
		// In-store numbers (4 + product ID + check digit) point straight at the product.
		foreach ( $candidates as $try ) {
			$id = PRDRWNDN_POS_UPC::id_from_internal( $try );
			if ( $id ) {
				$product = wc_get_product( $id );
				if ( $product && 'trash' !== get_post_status( $id ) ) {
					return $id;
				}
			}
		}
		return 0;
	}

	/**
	 * Product ID by the WooCommerce "GTIN, UPC, EAN, or ISBN" field.
	 *
	 * @param string $gtin Code.
	 * @return int
	 */
	public static function id_by_gtin( $gtin ) {
		if ( function_exists( 'wc_get_product_id_by_global_unique_id' ) ) {
			return (int) wc_get_product_id_by_global_unique_id( $gtin );
		}
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_global_unique_id' AND pm.meta_value = %s
				AND p.post_status != 'trash' LIMIT 1",
				$gtin
			)
		);
	}

	/**
	 * Data the POS and label screens need about a product.
	 *
	 * @param WC_Product $product Product or variation.
	 * @return array
	 */
	public static function product_data( $product ) {
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
		$name   = $parent ? $parent->get_name() : $product->get_name();
		$image  = wp_get_attachment_image_url( $product->get_image_id() ? $product->get_image_id() : ( $parent ? $parent->get_image_id() : 0 ), 'thumbnail' );
		$code   = PRDRWNDN_POS_UPC::for_product( $product );
		$data   = array(
			'id'           => $product->get_id(),
			'parent_id'    => $product->get_parent_id(),
			'type'         => $product->get_type(),
			'name'         => html_entity_decode( wp_strip_all_tags( $name ), ENT_QUOTES, 'UTF-8' ),
			'variant'      => $product->is_type( 'variation' ) ? html_entity_decode( wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, false ) ), ENT_QUOTES, 'UTF-8' ) : '',
			'sku'          => $product->get_sku( 'edit' ),
			'gtin'         => method_exists( $product, 'get_global_unique_id' ) ? $product->get_global_unique_id( 'edit' ) : '',
			'barcode'      => $code['number'],
			'barcode_kind' => $code['kind'],
			'price'        => self::price( $product ),
			'regular'      => (float) $product->get_regular_price(),
			'price_text'   => self::money( self::price( $product ) ),
			'manage_stock' => $product->managing_stock(),
			'stock'        => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			'stock_status' => $product->get_stock_status(),
			'image'        => $image ? $image : '',
			'status'       => $parent ? $parent->get_status() : $product->get_status(),
		);
		if ( $product->is_type( 'variable' ) ) {
			$data['variations'] = array();
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child ) {
					$data['variations'][] = self::product_data( $child );
				}
			}
		}
		return $data;
	}

	/**
	 * Plain-text price like "$45" or "$4.50".
	 *
	 * @param mixed $amount Amount.
	 * @return string
	 */
	/**
	 * What the product costs right now. A scheduled sale counts from the
	 * minute it starts to the minute it ends, without waiting for the
	 * store's nightly price update.
	 */
	public static function price( $product ) {
		$sale = (string) $product->get_sale_price( 'edit' );
		if ( '' !== $sale && ( $product->get_date_on_sale_from( 'edit' ) || $product->get_date_on_sale_to( 'edit' ) ) ) {
			return (float) ( $product->is_on_sale( 'edit' ) ? $sale : $product->get_regular_price( 'edit' ) );
		}
		return (float) $product->get_price();
	}

	public static function money( $amount ) {
		if ( '' === $amount || null === $amount ) {
			return '';
		}
		$amount   = (float) $amount;
		$symbol   = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$decimals = ( floor( $amount ) == $amount ) ? 0 : wc_get_price_decimals(); // phpcs:ignore Universal.Operators.StrictComparisons
		return $symbol . number_format( $amount, $decimals, wc_get_price_decimal_separator(), wc_get_price_thousand_separator() );
	}
}
