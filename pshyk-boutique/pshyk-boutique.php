<?php
/**
 * Plugin Name:       Pshyk Boutique — дизайн і вибір об'єму
 * Description:       Бутік-оформлення поверх WoodMart: нова головна, картки з мінімальною ціною об'єму, вибір об'єму кнопками, підсумок ціни, липка панель покупки на мобільному, пошук з транслітерацією й опечатками. Не змінює ціни, склад і правила замовлення — їх і далі перевіряє сервер (pshyk-rozpyv-store-functionality + WooCommerce).
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Pshyk Rozpyv
 * Text Domain:       pshyk-boutique
 * WC requires at least: 8.0
 */

defined( 'ABSPATH' ) || exit;

define( 'PSHYK_BOUTIQUE_VERSION', '1.0.0' );
define( 'PSHYK_BOUTIQUE_FILE', __FILE__ );
define( 'PSHYK_BOUTIQUE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSHYK_BOUTIQUE_URL', plugin_dir_url( __FILE__ ) );

require_once PSHYK_BOUTIQUE_DIR . 'includes/class-settings.php';
require_once PSHYK_BOUTIQUE_DIR . 'includes/class-decant.php';
require_once PSHYK_BOUTIQUE_DIR . 'includes/class-assets.php';
require_once PSHYK_BOUTIQUE_DIR . 'includes/class-product.php';
require_once PSHYK_BOUTIQUE_DIR . 'includes/class-catalog.php';
require_once PSHYK_BOUTIQUE_DIR . 'includes/class-search.php';
require_once PSHYK_BOUTIQUE_DIR . 'includes/class-home.php';
require_once PSHYK_BOUTIQUE_DIR . 'includes/class-i18n.php';

add_action( 'plugins_loaded', function () {
	\Pshyk\Boutique\Settings::init();
	\Pshyk\Boutique\I18n::init();
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	\Pshyk\Boutique\Assets::init();
	\Pshyk\Boutique\Product::init();
	\Pshyk\Boutique\Catalog::init();
	\Pshyk\Boutique\Search::init();
	\Pshyk\Boutique\Home::init();
}, 20 );

/* HPOS compatibility: the plugin never touches orders. */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
