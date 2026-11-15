<?php
namespace Pshyk\Boutique;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only knowledge about the decant data model. Never changes prices or stock.
 *
 * Live data model (verified 2026-11-15 via Store API and guest cart):
 *  - a decant is a *simple* product; its price is the price of 1 ml; the cart quantity is the volume in ml;
 *  - stock is kept in ml; WooCommerce sums all lines of one product, so several decants consume the combined volume;
 *  - the minimum and the list of allowed volumes come from the store plugin (quantity args / volume select);
 *    the server snaps any other quantity to an allowed volume.
 */
final class Decant {

	public static function is_decant( $product ): bool {
		$product = $product instanceof \WC_Product ? $product : wc_get_product( $product );
		if ( ! $product || ! $product->is_type( 'simple' ) ) {
			return false;
		}
		$slugs = array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'decant_categories' ) ) ) );
		$is    = $slugs && has_term( $slugs, 'product_cat', $product->get_id() );
		return (bool) apply_filters( 'pshyk_boutique_is_decant', $is, $product );
	}

	/** Minimum volume the store accepts for this product, as the store plugin reports it. */
	public static function min_volume( \WC_Product $product ): int {
		$args = apply_filters( 'woocommerce_quantity_input_args', array( 'min_value' => 1, 'max_value' => -1, 'input_value' => 1, 'step' => 1 ), $product );
		$min  = max( 1, (int) ( $args['min_value'] ?? 1 ) );
		$min  = max( $min, (int) apply_filters( 'woocommerce_quantity_input_min', $min, $product ) );
		return (int) apply_filters( 'pshyk_boutique_min_volume', $min, $product );
	}

	/** Upper bound the store accepts (quantity args), limited by stock in ml. 0 = cannot be bought. */
	public static function max_volume( \WC_Product $product ): int {
		$args = apply_filters( 'woocommerce_quantity_input_args', array( 'min_value' => 1, 'max_value' => -1, 'input_value' => 1, 'step' => 1 ), $product );
		$max  = (int) ( $args['max_value'] ?? -1 );
		if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
			$stock = max( 0, (int) $product->get_stock_quantity() );
			$max   = $max > 0 ? min( $max, $stock ) : $stock;
		}
		return $max < 0 ? PHP_INT_MAX : $max;
	}

	/**
	 * Display data for cards: the cheapest volume a customer can actually buy now.
	 * Returns null when the product is not purchasable (stock below the minimum volume).
	 *
	 * @return array{ml:int, price:float, regular:float, per_ml:float, single:bool}|null
	 */
	public static function from_offer( \WC_Product $product ): ?array {
		if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			return null;
		}
		$min = self::min_volume( $product );
		$max = self::max_volume( $product );
		if ( $max < $min ) {
			return null;
		}
		$per     = (float) wc_get_price_to_display( $product );
		$regular = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
		return array(
			'ml'      => $min,
			'price'   => $per * $min,
			'regular' => $regular * $min,
			'per_ml'  => $per,
			'single'  => $max === $min,
		);
	}
}
