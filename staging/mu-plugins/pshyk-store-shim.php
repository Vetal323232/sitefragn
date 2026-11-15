<?php
/**
 * Plugin Name: STAGING ONLY — Pshyk store behaviour shim
 * Description: Emulates, for local testing only, the behaviour of the live store's private plugins
 *              (pshyk-rozpyv-store-functionality + WPC Frequently Bought Together) as observed from the
 *              public site on 2026-11-15. This is NOT their source code. Never install on production:
 *              the real store already has the real plugins.
 *
 * Observed contract that pshyk-boutique relies on:
 *  - decants are simple products in category "rozpyv"; price = price for 1 ml; cart quantity = ml;
 *  - stock is kept in ml; each decant added to the cart is a separate cart line;
 *  - allowed volumes: min 1 ml → 1,2,3,4,5,10,15,20; min 3 ml → 3,5,10,15,20; capped by min(20, stock);
 *  - server snaps a requested quantity up to the next allowed tier and clamps to the max;
 *  - product page renders <div class="quantity-select" data-price=".."><select data-pr-custom-quantity>;
 *  - optional bottle add-on: 5 ml → «Флакон 5 мл», 10 ml → «Флакон 10 мл», 15/20 ml → «Флакон 20 мл», 3 ml → none;
 *  - minimum order 400 UAH; free shipping from 2500 UAH.
 */

defined( 'ABSPATH' ) || exit;


function pshyk_shim_is_decant( $product ) {
	$product = wc_get_product( $product );
	if ( ! $product ) {
		return false;
	}
	$id = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
	return has_term( 'rozpyv', 'product_cat', $id );
}

function pshyk_shim_min( $product ) {
	$min = (int) get_post_meta( $product->get_id(), '_pr_min_ml', true );
	return $min > 0 ? $min : 3;
}

function pshyk_shim_max( $product ) {
	$max = 20;
	if ( $product->managing_stock() ) {
		$max = min( $max, max( 0, (int) $product->get_stock_quantity() ) );
	}
	return $max;
}

function pshyk_shim_allowed( $product ) {
	$min = pshyk_shim_min( $product );
	$max = pshyk_shim_max( $product );
	$tiers = $min >= 3 ? array( 3, 5, 10, 15, 20 ) : array( 1, 2, 3, 4, 5, 10, 15, 20 );
	return array_values( array_filter( $tiers, function ( $t ) use ( $min, $max ) {
		return $t >= $min && $t <= $max;
	} ) );
}

/* Quantity input bounds (live: min="3" max="20"). */
add_filter( 'woocommerce_quantity_input_args', function ( $args, $product ) {
	if ( pshyk_shim_is_decant( $product ) ) {
		$args['min_value'] = pshyk_shim_min( $product );
		$args['max_value'] = max( pshyk_shim_min( $product ), pshyk_shim_max( $product ) );
		$args['step']      = 1;
	}
	return $args;
}, 10, 2 );

add_filter( 'woocommerce_store_api_product_quantity_minimum', function ( $min, $product ) {
	return pshyk_shim_is_decant( $product ) ? pshyk_shim_min( $product ) : $min;
}, 10, 2 );

/* «за 1 мл» suffix (live markup: <span class="custom-price-suffix">за 1 мл</span>). */
add_filter( 'woocommerce_get_price_html', function ( $html, $product ) {
	if ( pshyk_shim_is_decant( $product ) && '' !== $html ) {
		$html .= ' <span class="custom-price-suffix">за 1 мл</span>';
	}
	return $html;
}, 10, 2 );

/* Volume select on the product page. */
add_action( 'woocommerce_before_add_to_cart_quantity', function () {
	global $product;
	if ( ! $product || ! pshyk_shim_is_decant( $product ) ) {
		return;
	}
	$allowed = pshyk_shim_allowed( $product );
	if ( ! $allowed ) {
		return;
	}
	printf(
		'<div class="quantity-select" data-pr-product-qty-select="1" data-base-price="%1$s" data-price="%2$s" data-regular-price="%3$s"><label for="custom_quantity">Виберіть об&#039;єм:</label><select id="custom_quantity" data-pr-custom-quantity="1">',
		esc_attr( $product->get_price() ),
		esc_attr( $product->get_price() ),
		esc_attr( $product->get_regular_price() )
	);
	foreach ( $allowed as $ml ) {
		printf( '<option value="%1$d">%1$d мл</option>', $ml );
	}
	echo '</select></div><input name="woobt_ids" class="woobt-ids" type="hidden"/>';
} );

/* Server-side snapping of the requested volume (observed: 1→3, 4→5, 25→20). */
add_filter( 'woocommerce_add_to_cart_quantity', function ( $qty, $product_id ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || ! pshyk_shim_is_decant( $product ) ) {
		return $qty;
	}
	$allowed = pshyk_shim_allowed( $product );
	if ( ! $allowed ) {
		return $qty;
	}
	foreach ( $allowed as $t ) {
		if ( $t >= $qty ) {
			return $t;
		}
	}
	return end( $allowed );
}, 10, 2 );

/* Every decant is its own cart line. */
add_filter( 'woocommerce_add_cart_item_data', function ( $data, $product_id ) {
	if ( pshyk_shim_is_decant( $product_id ) ) {
		$data['pr_decant_uid'] = wp_generate_uuid4();
	}
	return $data;
}, 10, 2 );

/* WPC «Додати красивий флакон» emulation. */
function pshyk_shim_bottles() {
	$out = array();
	foreach ( array( 5 => 'Флакон 5 мл', 10 => 'Флакон 10 мл', 20 => 'Флакон 20 мл' ) as $ml => $title ) {
		$ids = get_posts( array( 'post_type' => 'product', 'title' => $title, 'fields' => 'ids', 'numberposts' => 1 ) );
		if ( $ids ) {
			$out[ $ml ] = wc_get_product( $ids[0] );
		}
	}
	return $out;
}

add_action( 'woocommerce_after_add_to_cart_form', function () {
	global $product;
	if ( ! $product || ! pshyk_shim_is_decant( $product ) ) {
		return;
	}
	$bottles = pshyk_shim_bottles();
	if ( ! $bottles ) {
		return;
	}
	echo '<div class="woobt-wrap"><div class="woobt-before-text woobt-text">Додати красивий флакон</div><div class="woobt-products">';
	$i = 1;
	foreach ( $bottles as $ml => $b ) {
		$img = wp_get_attachment_image_url( $b->get_image_id(), 'woocommerce_gallery_thumbnail' );
		printf(
			'<div class="woobt-product woobt-product-together" data-pid="%1$d" data-name="%2$s" data-price="%3$s" data-qty="1"><div class="woobt-choose"><label for="woobt_checkbox_%4$d">%2$s</label><input id="woobt_checkbox_%4$d" class="woobt-checkbox" type="checkbox" value="%1$d"/></div><div class="woobt-thumb">%5$s</div><div class="woobt-title">%2$s</div><div class="woobt-price">%6$s</div></div>',
			$b->get_id(),
			esc_attr( $b->get_name() ),
			esc_attr( $b->get_price() ),
			$i++,
			$img ? '<img src="' . esc_url( $img ) . '" alt="" width="60" height="60">' : '',
			wp_kses_post( wc_price( $b->get_price() ) )
		);
	}
	echo '</div><div class="woobt-summary"><div class="woobt-total woobt-text"></div></div></div>';
} );

add_action( 'woocommerce_add_to_cart', function ( $key, $product_id ) {
	static $busy = false;
	if ( $busy || empty( $_POST['woobt_ids'] ) || ! pshyk_shim_is_decant( $product_id ) ) { // phpcs:ignore
		return;
	}
	$busy = true;
	foreach ( explode( ',', sanitize_text_field( wp_unslash( $_POST['woobt_ids'] ) ) ) as $pair ) { // phpcs:ignore
		$pid = absint( strtok( $pair, '/' ) );
		if ( $pid ) {
			WC()->cart->add_to_cart( $pid, 1 );
		}
	}
	$busy = false;
}, 10, 2 );

/* Minimum order 400 UAH (live top bar). */
add_action( 'woocommerce_check_cart_items', function () {
	if ( WC()->cart && ! WC()->cart->is_empty() && WC()->cart->get_subtotal() < 400 ) {
		wc_add_notice( 'Мінімальна сума замовлення 400 грн', 'error' );
	}
} );

/* Small front-end emulation of the live quantity.js + tara.js. */
add_action( 'wp_footer', function () {
	if ( ! is_product() ) {
		return;
	}
	?>
	<style>.quantity-select ~ .quantity{display:none}</style>
	<script>
	(function(){
		var sel=document.querySelector('select[data-pr-custom-quantity]'); if(!sel) return;
		var form=sel.closest('form.cart'), qty=form.querySelector('input.qty'), wrap=sel.closest('.quantity-select');
		var price=parseFloat(wrap.getAttribute('data-price'));
		var map={'5':'Флакон 5 мл','10':'Флакон 10 мл','15':'Флакон 20 мл','20':'Флакон 20 мл'};
		function fmt(v){return v.toLocaleString('uk-UA',{minimumFractionDigits:2,maximumFractionDigits:2}).replace(/ /g,'')}
		function sync(){
			var ml=parseInt(sel.value,10); qty.value=ml;
			var amt=document.querySelector('.summary .price .woocommerce-Price-amount bdi');
			if(amt){amt.firstChild.nodeValue=fmt(price*ml)+' ';}
			var suf=document.querySelector('.summary .price .custom-price-suffix'); if(suf) suf.textContent='за '+ml+' мл';
			var ids=[];
			document.querySelectorAll('.woobt-product-together').forEach(function(item){
				var show=map[String(ml)]===item.getAttribute('data-name');
				item.style.display=show?'':'none';
				var cb=item.querySelector('.woobt-checkbox'); if(!show) cb.checked=false;
				if(show&&cb.checked) ids.push(item.getAttribute('data-pid')+'/1');
			});
			var wb=document.querySelector('.woobt-wrap'); if(wb) wb.style.display=map[String(ml)]?'':'none';
			var h=form.querySelector('input[name=woobt_ids]'); if(h) h.value=ids.join(',');
		}
		sel.addEventListener('change',sync);
		document.addEventListener('change',function(e){ if(e.target.classList.contains('woobt-checkbox')) sync(); });
		sync();
	})();
	</script>
	<?php
}, 5 );
