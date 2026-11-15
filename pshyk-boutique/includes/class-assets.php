<?php
namespace Pshyk\Boutique;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 30 );
		add_action( 'wp_head', array( __CLASS__, 'preload' ), 1 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		/* WP Rocket «Delay JavaScript execution» would postpone the volume buttons until the first tap. */
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'rocket_exclusions' ) );
	}

	private static function ver( string $rel ): string {
		$file = PSHYK_BOUTIQUE_DIR . $rel;
		return PSHYK_BOUTIQUE_VERSION . '.' . ( file_exists( $file ) ? filemtime( $file ) : 0 );
	}

	public static function enqueue(): void {
		$css = PSHYK_BOUTIQUE_URL . 'assets/css/';
		wp_enqueue_style( 'pshyk-boutique-tokens', $css . 'tokens.css', array(), self::ver( 'assets/css/tokens.css' ) );
		if ( Settings::get( 'enable_skin' ) ) {
			wp_enqueue_style( 'pshyk-boutique', $css . 'boutique.css', array( 'pshyk-boutique-tokens' ), self::ver( 'assets/css/boutique.css' ) );
		}
		if ( is_front_page() || Home::page_has_shortcode() ) {
			wp_enqueue_style( 'pshyk-boutique-home', $css . 'home.css', array( 'pshyk-boutique-tokens' ), self::ver( 'assets/css/home.css' ) );
		}
		if ( Settings::get( 'hide_visits_count' ) ) {
			wp_add_inline_style( 'pshyk-boutique-tokens', '.wd-visits-count{display:none!important}' );
		}
		wp_enqueue_script( 'pshyk-boutique', PSHYK_BOUTIQUE_URL . 'assets/js/boutique.js', array(), self::ver( 'assets/js/boutique.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script( 'pshyk-boutique', 'pshykBoutique', array(
			'currency' => html_entity_decode( get_woocommerce_currency_symbol() ),
			'decimals' => wc_get_price_decimals(),
			'i18n'     => array(
				'volume'      => 'Об\'єм',
				'fragrance'   => 'Аромат',
				'total'       => 'Разом',
				'ml'          => 'мл',
				'perMl'       => 'за 1 мл',
				'addToCart'   => 'Додати в кошик',
				'unavailable' => 'Недоступно',
				'notify'      => 'Повідомити про надходження',
				'notifyLong'  => 'Повідомити, коли аромат знову буде в наявності',
			),
		) );
	}

	public static function preload(): void {
		$fonts = array( 'manrope-cyrillic-wght-normal.woff2', 'cormorant-cyrillic-600-normal.woff2' );
		foreach ( $fonts as $f ) {
			printf( '<link rel="preload" href="%s" as="font" type="font/woff2" crossorigin>' . "\n", esc_url( PSHYK_BOUTIQUE_URL . 'assets/fonts/' . $f ) );
		}
	}

	public static function body_class( array $classes ): array {
		if ( Settings::get( 'enable_skin' ) ) {
			$classes[] = 'pb-skin';
		}
		return $classes;
	}

	public static function rocket_exclusions( $list ): array {
		$list   = (array) $list;
		$list[] = 'pshyk-boutique/assets/js/';
		$list[] = 'pshykBoutique';
		return $list;
	}
}
