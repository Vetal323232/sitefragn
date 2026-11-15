<?php
namespace Pshyk\Boutique;

defined( 'ABSPATH' ) || exit;

/** Catalog: active-filter chips, result count, reset, optional page size. URLs and filter params stay WooCommerce-native. */
final class Catalog {

	public static function init(): void {
		add_filter( 'loop_shop_per_page', array( __CLASS__, 'per_page' ), 50 );
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'active_filters' ), 35 );
		add_action( 'woocommerce_no_products_found', array( __CLASS__, 'empty_state' ), 5 );
	}

	public static function per_page( $n ) {
		$custom = (int) Settings::get( 'per_page' );
		return $custom > 0 ? $custom : $n;
	}

	private static function base_url(): string {
		if ( is_product_taxonomy() ) {
			$link = get_term_link( get_queried_object() );
			return is_wp_error( $link ) ? wc_get_page_permalink( 'shop' ) : $link;
		}
		return wc_get_page_permalink( 'shop' );
	}

	/** @return array<int, array{label:string, url:string}> */
	public static function chips(): array {
		$chips  = array();
		$chosen = \WC_Query::get_layered_nav_chosen_attributes();
		$base   = remove_query_arg( 'paged' );
		$base   = preg_replace( '#/page/\d+/?#', '/', $base );
		foreach ( $chosen as $taxonomy => $data ) {
			$filter = 'filter_' . wc_attribute_taxonomy_slug( $taxonomy );
			$label  = wc_attribute_label( $taxonomy );
			foreach ( $data['terms'] as $slug ) {
				$term = get_term_by( 'slug', $slug, $taxonomy );
				if ( ! $term ) {
					continue;
				}
				$rest = array_diff( $data['terms'], array( $slug ) );
				$url  = $rest ? add_query_arg( $filter, implode( ',', $rest ), $base ) : remove_query_arg( array( $filter, 'query_type_' . wc_attribute_taxonomy_slug( $taxonomy ) ), $base );
				$chips[] = array( 'label' => $label . ': ' . $term->name, 'url' => $url );
			}
		}
		if ( isset( $_GET['min_price'] ) || isset( $_GET['max_price'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$chips[] = array( 'label' => 'Ціна за 1 мл', 'url' => remove_query_arg( array( 'min_price', 'max_price' ), $base ) );
		}
		return $chips;
	}

	public static function active_filters(): void {
		$total = (int) wc_get_loop_prop( 'total' );
		$chips = self::chips();
		$term  = get_search_query();
		echo '<div class="pb-filterbar" role="region" aria-label="Обрані фільтри">';
		printf(
			'<p class="pb-filterbar__count"><strong>%1$s</strong> %2$s%3$s</p>',
			esc_html( number_format_i18n( $total ) ),
			esc_html( self::plural( $total, 'товар', 'товари', 'товарів' ) ),
			$term ? ' за запитом «' . esc_html( $term ) . '»' : ''
		);
		if ( $chips ) {
			echo '<ul class="pb-filterbar__chips">';
			foreach ( $chips as $c ) {
				printf( '<li><a class="pb-chip pb-chip--active" href="%1$s"><span>%2$s</span><span class="pb-chip__x" aria-hidden="true">×</span><span class="screen-reader-text">— прибрати фільтр</span></a></li>', esc_url( $c['url'] ), esc_html( $c['label'] ) );
			}
			$reset = self::base_url();
			if ( $term ) {
				$reset = add_query_arg( array( 's' => $term, 'post_type' => 'product' ), home_url( '/' ) );
			}
			printf( '<li><a class="pb-filterbar__reset" href="%s">Скинути всі фільтри</a></li>', esc_url( $reset ) );
			echo '</ul>';
		}
		echo '</div>';
	}

	public static function empty_state(): void {
		$shop = wc_get_page_permalink( 'shop' );
		echo '<div class="pb-empty"><p class="pb-empty__title">Нічого не знайшли</p><p>Спробуйте прибрати частину фільтрів або написати назву інакше — латиницею чи кирилицею, ми зрозуміємо обидва варіанти.</p>';
		if ( self::chips() ) {
			printf( '<p><a class="pb-btn pb-btn--ghost" href="%s">Скинути всі фільтри</a></p>', esc_url( self::base_url() ) );
		}
		printf( '<p><a class="pb-btn" href="%1$s">Дивитися весь каталог</a> <a class="pb-link" href="%2$s">Написати нам в Instagram</a></p></div>', esc_url( $shop ), esc_url( Settings::get( 'instagram_url' ) ) );
	}

	public static function plural( int $n, string $one, string $few, string $many ): string {
		$n10  = $n % 10;
		$n100 = $n % 100;
		if ( 1 === $n10 && 11 !== $n100 ) {
			return $one;
		}
		if ( $n10 >= 2 && $n10 <= 4 && ( $n100 < 12 || $n100 > 14 ) ) {
			return $few;
		}
		return $many;
	}
}
