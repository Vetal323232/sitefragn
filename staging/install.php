<?php
/* STAGING ONLY: install WordPress, activate WooCommerce + Storefront, store settings as on the live site. */
defined( 'ABSPATH' ) || exit;
if ( ! is_blog_installed() ) {
	wp_install( 'Pshyk Rozpyv — staging', 'admin', 'admin@example.test', true, '', 'admin-staging' );
}
update_option( 'WPLANG', 'uk' );
$r = activate_plugin( 'woocommerce/woocommerce.php' );
echo is_wp_error( $r ) ? $r->get_error_message() . "\n" : "WooCommerce active\n";
switch_theme( 'storefront' );
foreach ( array(
	'permalink_structure'            => '/%postname%/',
	'woocommerce_currency'           => 'UAH',
	'woocommerce_currency_pos'       => 'right_space',
	'woocommerce_price_decimal_sep'  => ',',
	'woocommerce_price_thousand_sep' => '',
	'woocommerce_price_num_decimals' => 2,
	'woocommerce_default_country'    => 'UA:UA12',
	'woocommerce_manage_stock'       => 'yes',
	'woocommerce_enable_guest_checkout' => 'yes',
	'woocommerce_coming_soon'        => 'no',
	'blogname'                       => 'Pshyk Rozpyv',
	'blogdescription'                => 'Розпив оригінальної парфумерії',
	'timezone_string'                => 'Europe/Kyiv',
) as $k => $v ) {
	update_option( $k, $v );
}
update_option( 'woocommerce_permalinks', array( 'product_base' => '/product', 'category_base' => 'category' ) );
flush_rewrite_rules();
