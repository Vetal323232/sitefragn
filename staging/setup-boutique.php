<?php
/** STAGING ONLY: activate pshyk-boutique, create the new homepage page and point «/» to it. */
defined( 'ABSPATH' ) || exit;
$r = activate_plugin( 'pshyk-boutique/pshyk-boutique.php' );
echo is_wp_error( $r ) ? $r->get_error_message() : "plugin active\n";
$existing = get_posts( array( 'post_type' => 'page', 'title' => 'Головна', 'numberposts' => 1, 'fields' => 'ids' ) );
$home     = $existing ? $existing[0] : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Головна', 'post_content' => '[pshyk_home]' ) );
update_option( 'show_on_front', 'page' );
update_post_meta( $home, '_wp_page_template', 'template-fullwidth.php' ); // Storefront full width ≈ WoodMart full-width layout
update_option( 'page_on_front', $home );
$opts                   = (array) get_option( 'pshyk_boutique', array() );
$opts['collection_ids'] = implode( ',', (array) get_option( 'pshyk_staging_clean_ids', array() ) );
$opts['per_page']       = 24;
update_option( 'pshyk_boutique', $opts );
foreach ( array( 'about-us' => 'Про нас', 'dostavka-ta-oplata' => 'Доставка та оплата', 'contact' => 'Контакти' ) as $slug => $title ) {
	if ( ! get_page_by_path( $slug ) ) {
		wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => 'Сторінка-заглушка на стенді.' ) );
	}
}
echo "home page id $home\n";
/* The live store uses the classic (shortcode) cart and checkout of WoodMart, not the block versions WC 11 installs by default. */
wp_update_post( array( 'ID' => wc_get_page_id( 'cart' ), 'post_content' => '[woocommerce_cart]' ) );
wp_update_post( array( 'ID' => wc_get_page_id( 'checkout' ), 'post_content' => '[woocommerce_checkout]' ) );
echo "classic cart/checkout\n";
