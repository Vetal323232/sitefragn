<?php
/**
 * STAGING ONLY. Imports the subset produced by select-subset.py into a local WordPress + WooCommerce.
 * Run through staging/run.php:  PSHYK_DATA=data/products.json php run.php import-products.php
 * Images are taken from staging/data/img (no network needed); remote URLs are only a fallback.
 */

defined( 'ABSPATH' ) || exit;
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$data = json_decode( file_get_contents( getenv( 'PSHYK_DATA' ) ), true );
wp_set_current_user( 1 );
WC_Install::create_pages();

function pshyk_imp_term( $name, $slug, $tax ) {
	$t = get_term_by( 'slug', $slug, $tax );
	if ( $t ) {
		return (int) $t->term_id;
	}
	$r = wp_insert_term( $name, $tax, array( 'slug' => $slug ) );
	return is_wp_error( $r ) ? 0 : (int) $r['term_id'];
}

function pshyk_imp_attribute( $taxonomy, $label ) {
	$slug = substr( $taxonomy, 3 );
	if ( ! wc_attribute_taxonomy_id_by_name( $slug ) ) {
		wc_create_attribute( array( 'name' => $label, 'slug' => $slug, 'type' => 'select', 'has_archives' => false ) );
		register_taxonomy( $taxonomy, 'product', array( 'hierarchical' => false ) );
	}
}

function pshyk_imp_image( $p, $post_id ) {
	$local = ! empty( $p['image_file'] ) ? dirname( getenv( 'PSHYK_DATA' ) ) . '/img/' . $p['image_file'] : '';
	if ( $local && file_exists( $local ) ) {
		$tmp = wp_tempnam( $p['image_file'] );
		copy( $local, $tmp );
		$id = media_handle_sideload( array( 'name' => $p['image_file'], 'tmp_name' => $tmp ), $post_id );
		return is_wp_error( $id ) ? 0 : (int) $id;
	}
	if ( empty( $p['image'] ) || getenv( 'PSHYK_OFFLINE' ) ) {
		return 0;
	}
	$id = media_sideload_image( $p['image'], $post_id, null, 'id' );
	return is_wp_error( $id ) ? 0 : (int) $id;
}

$cat_names = array( 'rozpyv' => 'Розпив', 'zalyshky' => 'Залишки', 'sale' => 'Sale', 'pidbirky' => 'Підбірки', 'tara' => 'Тара', 'inshe' => 'Інше' );
foreach ( $cat_names as $slug => $name ) {
	pshyk_imp_term( $name, $slug, 'product_cat' );
}

$map = array();
$new = array();
foreach ( $data['products'] as $p ) {
	$existing = wc_get_product_id_by_sku( 'live-' . $p['id'] );
	if ( $existing ) {
		$map[ $p['id'] ] = $existing; // re-runs are idempotent
		continue;
	}
	$product = new WC_Product_Simple();
	$product->set_name( html_entity_decode( $p['name'] ) );
	$product->set_slug( $p['slug'] );
	$product->set_sku( 'live-' . $p['id'] );
	$product->set_description( $p['description'] );
	$product->set_short_description( $p['short_description'] );
	$product->set_regular_price( (string) $p['regular_price'] );
	if ( null !== $p['sale_price'] && $p['sale_price'] < $p['regular_price'] ) {
		$product->set_sale_price( (string) $p['sale_price'] );
	}
	$is_tara = in_array( 'tara', wp_list_pluck( $p['categories'], 'slug' ), true );
	$product->set_manage_stock( ! $is_tara );
	if ( $is_tara ) {
		$product->set_stock_status( 'instock' );
	} else {
		$product->set_stock_quantity( $p['in_stock'] ? (int) ( $p['stock'] ?? 10 ) : 0 );
	}
	$cats = array();
	foreach ( $p['categories'] as $c ) {
		$cats[] = pshyk_imp_term( $c['name'], $c['slug'], 'product_cat' );
	}
	if ( false !== mb_strpos( $p['name'], 'ЗАЛИШОК' ) ) {
		$cats[] = pshyk_imp_term( 'Залишки', 'zalyshky', 'product_cat' );
	}
	$product->set_category_ids( array_filter( $cats ) );

	$attrs = array();
	foreach ( $p['attributes'] as $a ) {
		pshyk_imp_attribute( $a['taxonomy'], $a['name'] );
		$ids = array();
		foreach ( $a['terms'] as $t ) {
			$ids[] = pshyk_imp_term( $t['name'], $t['slug'], $a['taxonomy'] );
		}
		$attr = new WC_Product_Attribute();
		$attr->set_id( wc_attribute_taxonomy_id_by_name( substr( $a['taxonomy'], 3 ) ) );
		$attr->set_name( $a['taxonomy'] );
		$attr->set_options( array_filter( $ids ) );
		$attr->set_visible( true );
		$attrs[] = $attr;
	}
	$product->set_attributes( $attrs );
	$product->set_status( 'publish' );
	$pid = $product->save();
	update_post_meta( $pid, '_pr_min_ml', (int) $p['min'] );
	update_post_meta( $pid, '_pshyk_live_id', (int) $p['id'] );
	$img = pshyk_imp_image( $p, $pid );
	if ( $img ) {
		$product->set_image_id( $img );
		$product->save();
	}
	$map[ $p['id'] ] = $pid;
	$new[ $p['id'] ] = $pid;
	echo '.';
}
echo "\nnew products: " . count( $new ) . ', total: ' . count( $map ) . "\n";

/* Popularity: reproduce the order of the live «Топ продаж» tab. */
$sales = 500;
foreach ( $data['top'] as $live_id ) {
	if ( isset( $map[ $live_id ] ) ) {
		update_post_meta( $map[ $live_id ], 'total_sales', $sales );
		$sales -= 50;
	}
}
$n = 0;
foreach ( $map as $live_id => $pid ) {
	if ( ! in_array( $live_id, $data['top'], true ) && has_term( 'rozpyv', 'product_cat', $pid ) && $n < 8 ) {
		update_post_meta( $pid, 'total_sales', 100 - $n * 7 );
		$n++;
	}
}
update_option( 'pshyk_staging_clean_ids', array_values( array_filter( array_map( function ( $id ) use ( $map ) {
	return $map[ $id ] ?? 0;
}, $data['clean'] ) ) ) );

/* Reviews (public reviews from the live product pages). */
foreach ( $data['reviews'] as $r ) {
	if ( empty( $new[ $r['product_id'] ] ) ) { // only for products created in this run
		continue;
	}
	$cid = wp_insert_comment( array(
		'comment_post_ID'  => $map[ $r['product_id'] ],
		'comment_author'   => $r['reviewer'],
		'comment_content'  => wp_strip_all_tags( $r['review'] ),
		'comment_type'     => 'review',
		'comment_approved' => 1,
		'comment_date'     => str_replace( 'T', ' ', $r['date'] ),
	) );
	update_comment_meta( $cid, 'rating', (int) $r['rating'] );
	update_comment_meta( $cid, 'verified', 1 );
}
foreach ( $map as $pid ) {
	WC_Comments::clear_transients( $pid );
}

/* Shipping: Nova Poshta (flat 0 — carrier tariffs paid on delivery in reality), free from 2500, two pickup points. */
if ( ! WC_Shipping_Zones::get_zones() ) {
	$zone = new WC_Shipping_Zone();
	$zone->set_zone_name( 'Україна' );
	$zone->add_location( 'UA', 'country' );
	$zone->save();
	$zone->add_shipping_method( 'flat_rate' );
	$zone->add_shipping_method( 'free_shipping' );
	$zone->add_shipping_method( 'local_pickup' );
	$zone->add_shipping_method( 'local_pickup' );
	foreach ( $zone->get_shipping_methods() as $m ) {
		$key = $m->get_instance_option_key();
		$opt = get_option( $key, array() );
		if ( 'flat_rate' === $m->id ) {
			$opt = array_merge( $opt, array( 'title' => 'Нова Пошта (за тарифами перевізника)', 'cost' => '0' ) );
		} elseif ( 'free_shipping' === $m->id ) {
			$opt = array_merge( $opt, array( 'title' => 'Нова Пошта — безкоштовно', 'requires' => 'min_amount', 'min_amount' => '2500' ) );
		} else {
			static $k = 0;
			$opt = array_merge( $opt, array( 'title' => 0 === $k++ ? 'Самовивіз: пров. Вільний, 4а' : 'Самовивіз: пр-т Слобожанський, 86 (ТЦ Правда-Кіно)', 'cost' => '0' ) );
		}
		update_option( $key, $opt );
	}
}
/* Test payment only: bank transfer (no real money moves). */
update_option( 'woocommerce_bacs_settings', array( 'enabled' => 'yes', 'title' => 'Оплата на рахунок ФОП (тест)', 'description' => 'СТЕНД: оплата не проводиться.' ) );
update_option( 'woocommerce_cod_settings', array( 'enabled' => 'no' ) );
echo "done\n";
