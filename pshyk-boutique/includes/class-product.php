<?php
namespace Pshyk\Boutique;

defined( 'ABSPATH' ) || exit;

/**
 * Product cards and the purchase area.
 * Display only: final price, volume and stock are validated by WooCommerce and the store plugin on the server.
 */
final class Product {

	public static function init(): void {
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'card_price_html' ), 99, 2 );
		add_filter( 'woocommerce_quantity_input_args', array( __CLASS__, 'valid_initial_quantity' ), 20, 2 );
		add_filter( 'woocommerce_get_availability_text', array( __CLASS__, 'availability_text' ), 20, 2 );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'total_placeholder' ), 50 );
		add_action( 'woocommerce_after_add_to_cart_form', array( __CLASS__, 'purchase_info' ), 50 );
		add_action( 'wp_footer', array( __CLASS__, 'sticky_bar' ) );

		/* Cart / checkout / order: say «5 мл», never a bare «× 5» that reads like five pieces. */
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_volume' ), 20, 2 );
		add_filter( 'woocommerce_cart_product_not_enough_stock_message', array( __CLASS__, 'stock_message' ), 20, 3 );
		add_filter( 'woocommerce_cart_product_not_enough_stock_already_in_cart_message', array( __CLASS__, 'stock_message_in_cart' ), 20, 4 );
		add_filter( 'wc_add_to_cart_message_html', array( __CLASS__, 'added_message' ), 20, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'cart_quantity_unit' ), 20, 3 );
		add_filter( 'woocommerce_checkout_cart_item_quantity', array( __CLASS__, 'checkout_quantity' ), 20, 3 );
		add_filter( 'woocommerce_order_item_quantity_html', array( __CLASS__, 'order_quantity' ), 20, 2 );
		add_filter( 'woocommerce_email_order_item_quantity', array( __CLASS__, 'email_quantity' ), 20, 2 );
		if ( apply_filters( 'pshyk_boutique_order_volume_meta', true ) ) {
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_item_meta' ), 20, 3 );
		}
	}

	public static function cart_item_volume( $data, $item ) {
		if ( ! empty( $item['data'] ) && Decant::is_decant( $item['data'] ) ) {
			$data[] = array( 'key' => 'Об\'єм', 'value' => (int) $item['quantity'] . ' мл', 'display' => '' );
		}
		return $data;
	}

	/** Stock errors in ml (the server still decides; only the wording changes). */
	public static function stock_message( $message, $product, $stock ) {
		if ( $product instanceof \WC_Product && Decant::is_decant( $product ) ) {
			return sprintf( 'Аромату «%1$s» залишилось лише %2$s мл. Оберіть менший об\'єм.', $product->get_name(), wc_format_stock_quantity_for_display( $stock, $product ) );
		}
		return $message;
	}

	public static function stock_message_in_cart( $message, $product, $stock, $in_cart ) {
		if ( $product instanceof \WC_Product && Decant::is_decant( $product ) ) {
			$cart = wc_get_cart_url();
			return sprintf( 'Аромату «%1$s» залишилось %2$s мл, і %3$s мл із них уже у вашому кошику. <a href="%4$s" class="wc-forward">Переглянути кошик</a>', esc_html( $product->get_name() ), wc_format_stock_quantity_for_display( $stock, $product ), wc_format_stock_quantity_for_display( $in_cart, $product ), esc_url( $cart ) );
		}
		return $message;
	}

	/** «3 × "Bois Imperial" додано» → «"Bois Imperial", 3 мл — додано до кошика». */
	public static function added_message( $message, $products ) {
		$parts = array();
		foreach ( (array) $products as $id => $qty ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$parts[] = '«' . esc_html( $p->get_name() ) . '»' . ( Decant::is_decant( $p ) ? ', ' . (float) $qty . ' мл' : ( $qty > 1 ? ' × ' . (float) $qty : '' ) );
		}
		if ( ! $parts ) {
			return $message;
		}
		$link = sprintf( ' <a href="%s" tabindex="1" class="button wc-forward">%s</a>', esc_url( wc_get_cart_url() ), 'Переглянути кошик' );
		return implode( ', ', $parts ) . ' — додано до кошика.' . $link;
	}

	public static function cart_quantity_unit( $html, $key, $item ) {
		if ( ! empty( $item['data'] ) && Decant::is_decant( $item['data'] ) ) {
			return '<div class="pb-qty-ml">' . $html . '<span class="pb-qty-ml__unit">мл</span></div>';
		}
		return $html;
	}

	public static function checkout_quantity( $html, $item, $key ) {
		if ( ! empty( $item['data'] ) && Decant::is_decant( $item['data'] ) ) {
			return ' <strong class="product-quantity">' . (int) $item['quantity'] . '&nbsp;мл</strong>';
		}
		return $html;
	}

	public static function order_quantity( $html, $item ) {
		if ( $item instanceof \WC_Order_Item_Product && Decant::is_decant( $item->get_product_id() ) ) {
			return ' <strong class="product-quantity">' . (int) $item->get_quantity() . '&nbsp;мл</strong>';
		}
		return $html;
	}

	public static function email_quantity( $qty, $item ) {
		if ( $item instanceof \WC_Order_Item_Product && Decant::is_decant( $item->get_product_id() ) ) {
			return (int) $item->get_quantity() . ' мл';
		}
		return $qty;
	}

	/** Visible to staff in the order screen and e-mails; does not affect totals or stock. */
	public static function order_item_meta( $item, $key, $values ) {
		if ( ! empty( $values['data'] ) && Decant::is_decant( $values['data'] ) ) {
			$item->add_meta_data( 'Об\'єм', (int) $values['quantity'] . ' мл', true );
		}
	}

	/** «240 ₴» instead of «240,00 ₴»; kopiyky are shown only when present. */
	public static function money( float $v ): string {
		return wc_price( $v, array( 'decimals' => abs( $v - round( $v ) ) < 0.001 ? 0 : wc_get_price_decimals() ) );
	}

	/**
	 * Live markup has value="1" with min="3" on a hidden quantity field: until JS runs, the browser's form
	 * validation silently blocks «Додати в кошик». Start the field at the minimum volume instead.
	 */
	public static function valid_initial_quantity( $args, $product ) {
		if ( isset( $args['min_value'], $args['input_value'] ) && (float) $args['input_value'] < (float) $args['min_value'] && $product instanceof \WC_Product && Decant::is_decant( $product ) && ! is_cart() ) {
			$args['input_value'] = $args['min_value'];
		}
		return $args;
	}

	/** True while WooCommerce renders the main product's own summary (keep the store plugin's live «за N мл» price there). */
	private static function in_main_summary( \WC_Product $product ): bool {
		return is_product()
			&& (int) get_queried_object_id() === $product->get_id()
			&& ( doing_action( 'woocommerce_single_product_summary' ) || doing_action( 'woocommerce_after_add_to_cart_form' ) || doing_action( 'woocommerce_before_add_to_cart_form' ) );
	}

	/**
	 * Cards everywhere (catalog, search, related, home, Store API): show the price of the smallest volume
	 * a customer can buy now, with the price per ml as secondary information.
	 */
	public static function card_price_html( $html, $product ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $html;
		}
		if ( ! $product instanceof \WC_Product || '' === $html || ! Decant::is_decant( $product ) || self::in_main_summary( $product ) ) {
			return $html;
		}
		$offer = Decant::from_offer( $product );
		if ( ! $offer ) {
			return sprintf(
				'<span class="pb-price pb-price--out"><span class="pb-price__per">%s <span class="pb-price__unit">за 1 мл</span></span></span>',
				self::money( (float) wc_get_price_to_display( $product ) )
			);
		}
		$amount = $product->is_on_sale() && $offer['regular'] > $offer['price']
			? '<del aria-hidden="true">' . self::money( $offer['regular'] ) . '</del> <span class="screen-reader-text">Ціна зі знижкою:</span><ins>' . self::money( $offer['price'] ) . '</ins>'
			: self::money( $offer['price'] );
		return sprintf(
			'<span class="pb-price">%1$s<span class="pb-price__amount">%2$s</span> <span class="pb-price__vol">за %3$d&nbsp;мл</span></span><span class="pb-price__per">%4$s / мл</span>',
			$offer['single'] ? '' : '<span class="pb-price__from">від </span>',
			$amount,
			$offer['ml'],
			self::money( $offer['per_ml'] )
		);
	}

	/** «198 в наявності» → «198 мл у наявності» for decants (stock is kept in ml). */
	public static function availability_text( $text, $product ) {
		if ( ! $product instanceof \WC_Product || ! Decant::is_decant( $product ) || ! $product->managing_stock() || ! $product->is_in_stock() ) {
			return $text;
		}
		$qty = (int) $product->get_stock_quantity();
		if ( $qty <= 0 ) {
			return $text;
		}
		$min = Decant::min_volume( $product );
		if ( $qty < $min ) {
			return 'Залишок менший за мінімальний об\'єм';
		}
		return sprintf( '%d мл у наявності', $qty );
	}

	/** Server-rendered summary slot; boutique.js fills it in. Without JS it stays hidden. */
	public static function total_placeholder(): void {
		global $product;
		if ( ! $product instanceof \WC_Product || ! Decant::is_decant( $product ) ) {
			return;
		}
		echo '<div class="pb-total" data-pb-total hidden aria-live="polite"></div>';
	}

	/** Packaging + shipping/pickup next to the purchase button (short, factual, editable in settings). */
	public static function purchase_info(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$decant  = Decant::is_decant( $product );
		$free    = (int) Settings::get( 'free_shipping_from' );
		$stores  = Settings::stores();
		$hours   = (string) Settings::get( 'store_hours' );
		$deliv   = home_url( '/dostavka-ta-oplata/' );
		echo '<div class="pb-buy-info">';
		if ( $decant ) {
			echo '<details class="pb-buy-info__item" open><summary><span class="pb-buy-info__icon" aria-hidden="true">◇</span> Що ви отримаєте</summary><p>' . esc_html( Settings::get( 'packaging_included' ) ) . ' Декант має маркування, за яким можна ідентифікувати оригінальний флакон.</p>';
			echo '<p class="pb-buy-info__muted">' . esc_html( Settings::get( 'bottle_note' ) ) . '</p></details>';
		}
		echo '<details class="pb-buy-info__item"><summary><span class="pb-buy-info__icon" aria-hidden="true">→</span> Доставка і самовивіз</summary><p>' . esc_html( Settings::get( 'ship_note' ) ) . '</p>';
		if ( $free ) {
			printf( '<p>Безкоштовна доставка — від %s.</p>', wp_kses_post( wc_price( $free, array( 'decimals' => 0 ) ) ) );
		}
		if ( $stores ) {
			echo '<ul class="pb-buy-info__stores">';
			foreach ( $stores as $s ) {
				echo '<li>' . esc_html( $s['address'] ) . '</li>';
			}
			echo '</ul><p class="pb-buy-info__muted">Магазини працюють ' . esc_html( $hours ) . '. Наявність у магазині може відрізнятися від сайту.</p>';
		}
		echo '<p><a href="' . esc_url( $deliv ) . '">Усі умови доставки та оплати</a></p></details>';
		echo '</div>';
	}

	/** Mobile purchase bar. Hidden until boutique.js decides it is useful (main button scrolled away). */
	public static function sticky_bar(): void {
		if ( ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() || ! $product->is_type( 'simple' ) ) {
			return;
		}
		printf(
			'<div class="pb-sticky" data-pb-sticky hidden><div class="pb-sticky__info"><span class="pb-sticky__name">%1$s</span><span class="pb-sticky__sum" data-pb-sticky-sum></span></div><button type="button" class="pb-sticky__btn" data-pb-sticky-btn>Додати в кошик</button></div>',
			esc_html( $product->get_name() )
		);
	}
}
