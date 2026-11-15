<?php
namespace Pshyk\Boutique;

defined( 'ABSPATH' ) || exit;

/**
 * New homepage as a shortcode: [pshyk_home]. Sections can also be placed separately in Elementor:
 * [pshyk_home_section name="hero|moods|popular|collection|decant|proof|instagram|service"].
 * Everything is rendered on the server from live WooCommerce data; no section needs JavaScript.
 */
final class Home {

	const SECTIONS = array( 'hero', 'moods', 'popular', 'collection', 'decant', 'proof', 'instagram', 'service' );

	public static function init(): void {
		add_shortcode( 'pshyk_home', array( __CLASS__, 'render' ) );
		add_shortcode( 'pshyk_home_section', array( __CLASS__, 'render_section' ) );
		add_action( 'save_post_product', array( __CLASS__, 'flush' ) );
		add_action( 'comment_post', array( __CLASS__, 'flush' ) );
		add_filter( 'body_class', function ( $c ) {
			if ( self::page_has_shortcode() ) {
				$c[] = 'pb-home-page';
			}
			return $c;
		} );
	}

	public static function flush(): void {
		delete_transient( 'pshyk_boutique_home_stats' );
	}

	public static function page_has_shortcode(): bool {
		$post = get_post();
		return $post && is_singular() && has_shortcode( (string) $post->post_content, 'pshyk_home' );
	}

	public static function render(): string {
		$html = '';
		foreach ( self::SECTIONS as $s ) {
			$html .= self::section( $s );
		}
		return '<div class="pb-home">' . $html . '</div>';
	}

	public static function render_section( $atts ): string {
		$atts = shortcode_atts( array( 'name' => '' ), $atts );
		return in_array( $atts['name'], self::SECTIONS, true ) ? '<div class="pb-home">' . self::section( $atts['name'] ) . '</div>' : '';
	}

	private static function section( string $name ): string {
		ob_start();
		call_user_func( array( __CLASS__, 'section_' . $name ) );
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------ data */

	private static function decant_slugs(): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) Settings::get( 'decant_categories' ) ) ) ) );
	}

	public static function moods(): array {
		return apply_filters( 'pshyk_boutique_moods', array(
			'fresh'  => array( 'title' => 'Свіжі', 'hint' => 'цитруси, зелень, морське повітря', 'terms' => array( 'svizhi', 'tsytrusovi', 'zeleni', 'akvatychni', 'morski', 'ozonovi' ) ),
			'sweet'  => array( 'title' => 'Солодкі', 'hint' => 'ваніль, карамель, десерти', 'terms' => array( 'solodki', 'vanilni', 'hurmanski', 'karamelnyj' ) ),
			'woody'  => array( 'title' => 'Деревинні', 'hint' => 'кедр, сандал, уд, дим', 'terms' => array( 'derevynni', 'udovi', 'dymni', 'shkiriani' ) ),
			'floral' => array( 'title' => 'Квіткові', 'hint' => 'троянда, жасмин, пудра', 'terms' => array( 'kvitkovi', 'bilokvitkovi', 'troiandovi', 'pudrovi' ) ),
		) );
	}

	private static function in_stock_query( array $extra = array() ): array {
		$tax = array(
			'relation' => 'AND',
			array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => self::decant_slugs() ),
		);
		$hidden = array_filter( array( get_term_by( 'slug', 'outofstock', 'product_visibility' ), get_term_by( 'slug', 'exclude-from-catalog', 'product_visibility' ) ) );
		if ( $hidden ) {
			$tax[] = array( 'taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id', 'terms' => wp_list_pluck( $hidden, 'term_taxonomy_id' ), 'operator' => 'NOT IN' );
		}
		return array_merge( array( 'post_type' => 'product', 'post_status' => 'publish', 'tax_query' => $tax, 'no_found_rows' => false, 'fields' => 'ids' ), $extra ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/** Counts and mood data, cached for an hour (flushed on product save / new review). */
	public static function stats(): array {
		$s = get_transient( 'pshyk_boutique_home_stats' );
		if ( is_array( $s ) ) {
			return $s;
		}
		$q      = new \WP_Query( self::in_stock_query( array( 'posts_per_page' => 1 ) ) );
		$brands = taxonomy_exists( 'pa_brend' ) ? (int) wp_count_terms( array( 'taxonomy' => 'pa_brend', 'hide_empty' => true ) ) : 0;
		$moods  = array();
		foreach ( self::moods() as $key => $m ) {
			$terms = taxonomy_exists( 'pa_hrupa_aromativ' ) ? array_values( array_filter( $m['terms'], function ( $slug ) {
				return (bool) get_term_by( 'slug', $slug, 'pa_hrupa_aromativ' );
			} ) ) : array();
			if ( ! $terms ) {
				continue;
			}
			$args               = self::in_stock_query( array( 'posts_per_page' => 1, 'meta_key' => 'total_sales', 'orderby' => 'meta_value_num', 'order' => 'DESC' ) ); // phpcs:ignore
			$args['tax_query'][] = array( 'taxonomy' => 'pa_hrupa_aromativ', 'field' => 'slug', 'terms' => $terms );
			$mq                 = new \WP_Query( $args );
			$moods[ $key ]      = array(
				'count' => (int) $mq->found_posts,
				'image' => $mq->posts ? (int) get_post_thumbnail_id( $mq->posts[0] ) : 0,
				'url'   => add_query_arg( array( 'filter_hrupa_aromativ' => implode( ',', $terms ), 'query_type_hrupa_aromativ' => 'or' ), wc_get_page_permalink( 'shop' ) ),
			);
		}
		$s = array( 'fragrances' => (int) $q->found_posts, 'brands' => $brands, 'moods' => $moods );
		set_transient( 'pshyk_boutique_home_stats', $s, HOUR_IN_SECONDS );
		return $s;
	}

	private static function brand( \WC_Product $p ): string {
		$terms = taxonomy_exists( 'pa_brend' ) ? wc_get_product_terms( $p->get_id(), 'pa_brend', array( 'fields' => 'names' ) ) : array();
		return $terms ? (string) $terms[0] : '';
	}

	/** «Essential Parfums Bois Imperial» → [«Essential Parfums», «Bois Imperial»]. */
	public static function split_name( \WC_Product $p ): array {
		$name  = html_entity_decode( $p->get_name() );
		$brand = self::brand( $p );
		/* Strip only unambiguous brand prefixes: «Etat» (brand) vs «Etat Libre d'Orange …» (name) must stay intact. */
		$safe = $brand && ( mb_strlen( $brand ) >= 5 || false !== mb_strpos( $brand, ' ' ) );
		if ( $safe && 0 === mb_stripos( $name, $brand . ' ' ) ) {
			return array( $brand, trim( mb_substr( $name, mb_strlen( $brand ) ) ) );
		}
		return array( $brand, $name );
	}

	private static function first_sentence( string $html, int $max = 110 ): string {
		$t = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $html ) ) ) );
		if ( '' === $t ) {
			return '';
		}
		if ( preg_match( '/^(.{20,}?[.!?…])(\s|$)/u', $t, $m ) ) {
			$t = $m[1];
		}
		return mb_strlen( $t ) > $max ? rtrim( mb_substr( $t, 0, $max - 1 ) ) . '…' : $t;
	}

	private static function groups( \WC_Product $p, int $n = 3 ): array {
		if ( ! taxonomy_exists( 'pa_hrupa_aromativ' ) ) {
			return array();
		}
		return array_slice( wc_get_product_terms( $p->get_id(), 'pa_hrupa_aromativ', array( 'fields' => 'names' ) ), 0, $n );
	}

	/* ------------------------------------------------------------- partials */

	public static function card( \WC_Product $p, string $sizes = '(min-width: 1024px) 280px, (min-width: 640px) 33vw, 46vw' ): string {
		list( $brand, $title ) = self::split_name( $p );
		$img   = $p->get_image_id() ? wp_get_attachment_image( $p->get_image_id(), 'woocommerce_thumbnail', false, array( 'class' => 'pb-card__img', 'loading' => 'lazy', 'decoding' => 'async', 'sizes' => $sizes, 'alt' => '' ) ) : '';
		$notes = self::groups( $p );
		$sale  = $p->is_on_sale() ? '<span class="pb-card__badge">Знижка</span>' : '';
		return sprintf(
			'<article class="pb-card">%1$s<div class="pb-card__media">%2$s</div><div class="pb-card__body"><p class="pb-card__brand">%3$s</p><h3 class="pb-card__title"><a class="pb-card__link" href="%4$s">%5$s</a></h3>%6$s<p class="pb-card__price">%7$s</p><span class="pb-card__cta" aria-hidden="true">Обрати об\'єм</span></div></article>',
			$sale,
			$img,
			esc_html( $brand ),
			esc_url( $p->get_permalink() ),
			esc_html( $title ),
			$notes ? '<p class="pb-card__notes">' . esc_html( implode( ' · ', $notes ) ) . '</p>' : '',
			wp_kses_post( $p->get_price_html() )
		);
	}

	private static function eyebrow( string $t ): string {
		return '<p class="pb-eyebrow">' . esc_html( $t ) . '</p>';
	}

	/* ------------------------------------------------------------- sections */

	private static function section_hero(): void {
		$s    = self::stats();
		$shop = wc_get_page_permalink( 'shop' );
		$img  = PSHYK_BOUTIQUE_URL . 'assets/img/';
		?>
		<section class="pb-hero" aria-labelledby="pb-hero-title">
			<div class="pb-hero__text">
				<?php echo self::eyebrow( (string) Settings::get( 'hero_eyebrow' ) ); // phpcs:ignore ?>
				<h1 id="pb-hero-title" class="pb-hero__title"><?php echo wp_kses( (string) Settings::get( 'hero_title' ), array( 'em' => array(), 'br' => array() ) ); ?></h1>
				<p class="pb-hero__lead"><?php echo esc_html( Settings::get( 'hero_lead' ) ); ?></p>
				<div class="pb-hero__actions">
					<a class="pb-btn pb-btn--primary" href="<?php echo esc_url( $shop ); ?>">Обрати аромат</a>
					<a class="pb-btn pb-btn--ghost" href="#pb-moods">Допомогти з вибором</a>
				</div>
				<ul class="pb-hero__facts">
					<?php if ( $s['fragrances'] ) : ?>
						<li><strong><?php echo esc_html( number_format_i18n( $s['fragrances'] ) ); ?></strong> <?php echo esc_html( Catalog::plural( $s['fragrances'], 'аромат', 'аромати', 'ароматів' ) ); ?> у&nbsp;наявності</li>
					<?php endif; ?>
					<?php if ( $s['brands'] ) : ?>
						<li><strong><?php echo esc_html( number_format_i18n( $s['brands'] ) ); ?></strong> <?php echo esc_html( Catalog::plural( $s['brands'], 'бренд', 'бренди', 'брендів' ) ); ?></li>
					<?php endif; ?>
					<li><strong>пн · ср · пт</strong> відправки Новою Поштою</li>
				</ul>
			</div>
			<figure class="pb-hero__media">
				<img class="pb-hero__img" src="<?php echo esc_url( $img . 'store-interior-800.webp' ); ?>" srcset="<?php echo esc_url( $img . 'store-interior-480.webp' ); ?> 480w, <?php echo esc_url( $img . 'store-interior-800.webp' ); ?> 800w, <?php echo esc_url( $img . 'store-interior-1200.webp' ); ?> 1200w" sizes="(min-width: 1024px) 46vw, 100vw" width="1200" height="900" alt="Полиці з оригінальними флаконами в магазині Pshyk Rozpyv у Дніпрі" fetchpriority="high" decoding="async">
				<figcaption class="pb-hero__note">
					<img src="<?php echo esc_url( $img . 'atomizers-360.webp' ); ?>" width="90" height="160" alt="" loading="lazy" decoding="async">
					<span><strong>Ви отримуєте розпив</strong> — атомайзер з потрібним об'ємом аромату, а не повний флакон.</span>
				</figcaption>
			</figure>
		</section>
		<?php
	}

	private static function section_moods(): void {
		$s     = self::stats();
		$moods = self::moods();
		if ( ! $s['moods'] ) {
			return;
		}
		?>
		<section id="pb-moods" class="pb-section pb-moods" aria-labelledby="pb-moods-title" data-pb-reveal tabindex="-1">
			<div class="pb-section__head">
				<?php echo self::eyebrow( 'З чого почати' ); // phpcs:ignore ?>
				<h2 id="pb-moods-title" class="pb-h2">Оберіть настрій — <em>ми покажемо аромати</em></h2>
			</div>
			<ul class="pb-moods__list">
				<?php
				$i = 0;
				foreach ( $s['moods'] as $key => $m ) :
					++$i;
					?>
					<li class="pb-mood">
						<a class="pb-mood__link" href="<?php echo esc_url( $m['url'] ); ?>">
							<span class="pb-mood__index" aria-hidden="true"><?php echo esc_html( sprintf( '%02d', $i ) ); ?></span>
							<span class="pb-mood__title"><?php echo esc_html( $moods[ $key ]['title'] ); ?></span>
							<span class="pb-mood__hint"><?php echo esc_html( $moods[ $key ]['hint'] ); ?></span>
							<span class="pb-mood__count"><?php echo esc_html( $m['count'] . ' ' . Catalog::plural( $m['count'], 'аромат', 'аромати', 'ароматів' ) ); ?> <span aria-hidden="true">→</span></span>
							<?php
							if ( $m['image'] ) {
								echo wp_get_attachment_image( $m['image'], 'woocommerce_thumbnail', false, array( 'class' => 'pb-mood__img', 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '', 'sizes' => '120px' ) );
							}
							?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="pb-moods__help">
				<form class="pb-search" role="search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get">
					<label class="pb-search__label" for="pb-home-search">Уже знаєте назву? Пишіть латиницею або кирилицею</label>
					<div class="pb-search__row">
						<input id="pb-home-search" class="pb-search__input" type="search" name="s" placeholder="Наприклад, Baccarat або Діор" autocomplete="off">
						<input type="hidden" name="post_type" value="product">
						<button class="pb-btn pb-btn--primary pb-search__btn" type="submit">Знайти</button>
					</div>
				</form>
				<p class="pb-moods__dm">Не знаєте, з чого почати? <a class="pb-link" href="<?php echo esc_url( Settings::get( 'instagram_url' ) ); ?>" target="_blank" rel="noopener">Напишіть нам в Instagram</a> — підберемо 2–3 аромати під ваш смак і бюджет.</p>
			</div>
		</section>
		<?php
	}

	private static function section_popular(): void {
		$q = new \WP_Query( self::in_stock_query( array( 'posts_per_page' => 8, 'meta_key' => 'total_sales', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'no_found_rows' => true ) ) ); // phpcs:ignore
		if ( ! $q->posts ) {
			return;
		}
		?>
		<section class="pb-section pb-popular" aria-labelledby="pb-popular-title" data-pb-reveal>
			<div class="pb-section__head pb-section__head--row">
				<div>
					<?php echo self::eyebrow( 'Найчастіше замовляють' ); // phpcs:ignore ?>
					<h2 id="pb-popular-title" class="pb-h2">Популярні аромати</h2>
				</div>
				<a class="pb-link pb-link--arrow" href="<?php echo esc_url( add_query_arg( 'orderby', 'popularity', wc_get_page_permalink( 'shop' ) ) ); ?>">Усі популярні</a>
			</div>
			<p class="pb-section__note">Ціна вказана за найменший доступний об'єм. Ціну за 1 мл показуємо нижче дрібним шрифтом.</p>
			<div class="pb-rail" role="list">
				<?php
				foreach ( $q->posts as $id ) {
					$p = wc_get_product( $id );
					if ( $p ) {
						echo '<div class="pb-rail__item" role="listitem">' . self::card( $p ) . '</div>'; // phpcs:ignore
					}
				}
				?>
			</div>
		</section>
		<?php
	}

	private static function section_collection(): void {
		$ids = array_filter( array_map( 'absint', explode( ',', (string) Settings::get( 'collection_ids' ) ) ) );
		$ids = apply_filters( 'pshyk_boutique_collection_ids', $ids );
		$products = array_values( array_filter( array_map( 'wc_get_product', $ids ), function ( $p ) {
			return $p && 'publish' === $p->get_status() && $p->is_visible();
		} ) );
		if ( count( $products ) < 3 ) {
			return;
		}
		$lead = $products[0];
		?>
		<section class="pb-section pb-collection" aria-labelledby="pb-collection-title" data-pb-reveal>
			<div class="pb-collection__intro">
				<?php echo self::eyebrow( 'Добірка' ); // phpcs:ignore ?>
				<h2 id="pb-collection-title" class="pb-h2 pb-collection__title"><?php echo esc_html( Settings::get( 'collection_title' ) ); ?></h2>
				<p class="pb-collection__text"><?php echo esc_html( Settings::get( 'collection_text' ) ); ?></p>
				<div class="pb-collection__lead">
					<?php echo $lead->get_image_id() ? wp_get_attachment_image( $lead->get_image_id(), 'woocommerce_single', false, array( 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '', 'sizes' => '(min-width: 1024px) 360px, 70vw' ) ) : ''; // phpcs:ignore ?>
				</div>
			</div>
			<ol class="pb-collection__list">
				<?php
				foreach ( $products as $p ) :
					list( $brand, $title ) = self::split_name( $p );
					$line = self::first_sentence( $p->get_short_description() ? $p->get_short_description() : $p->get_description() );
					?>
					<li class="pb-collection__item">
						<a class="pb-collection__link" href="<?php echo esc_url( $p->get_permalink() ); ?>">
							<span class="pb-collection__name"><?php echo esc_html( $title ); ?></span>
							<span class="pb-collection__brand"><?php echo esc_html( $brand ); ?></span>
							<?php if ( $line ) : ?>
								<span class="pb-collection__line"><?php echo esc_html( $line ); ?></span>
							<?php endif; ?>
							<span class="pb-collection__price"><?php echo wp_kses_post( $p->get_price_html() ); ?></span>
						</a>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>
		<?php
	}

	private static function bottles(): array {
		$out = array();
		foreach ( array( 5, 10, 20 ) as $ml ) {
			$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'title' => 'Флакон ' . $ml . ' мл', 'fields' => 'ids', 'numberposts' => 1 ) );
			$p   = $ids ? wc_get_product( $ids[0] ) : null;
			$out[] = array(
				'ml'    => $ml,
				'url'   => $p ? $p->get_permalink() : '',
				'price' => $p ? (float) $p->get_price() : 0,
				'img'   => PSHYK_BOUTIQUE_URL . 'assets/img/bottle-' . $ml . '.webp',
			);
		}
		return $out;
	}

	private static function section_decant(): void {
		$img = PSHYK_BOUTIQUE_URL . 'assets/img/';
		?>
		<section class="pb-section pb-decant" aria-labelledby="pb-decant-title" data-pb-reveal>
			<div class="pb-decant__head">
				<?php echo self::eyebrow( 'Як це працює' ); // phpcs:ignore ?>
				<h2 id="pb-decant-title" class="pb-h2">Що таке розпив <em>і що приходить у посилці</em></h2>
				<p class="pb-decant__lead">Розпив (декант) — це частина оригінального аромату, яку ми відливаємо із заводського флакона в менший атомайзер. Склад, концентрація і властивості аромату не змінюються.</p>
			</div>
			<figure class="pb-decant__photo">
				<img src="<?php echo esc_url( $img . 'atomizers-560.webp' ); ?>" srcset="<?php echo esc_url( $img . 'atomizers-360.webp' ); ?> 360w, <?php echo esc_url( $img . 'atomizers-560.webp' ); ?> 560w" sizes="(min-width: 1024px) 360px, 80vw" width="560" height="998" alt="Три скляні атомайзери з розпилювачем різного об'єму" loading="lazy" decoding="async">
				<figcaption>Приклад атомайзерів для розпиву різного об'єму</figcaption>
			</figure>
			<ol class="pb-steps">
				<li class="pb-step"><span class="pb-step__n" aria-hidden="true">1</span><h3 class="pb-step__title">Обираєте аромат і об'єм</h3><p>Зазвичай від 3 до 20 мл — доступні об'єми й ціну кожного видно на сторінці аромату.</p></li>
				<li class="pb-step"><span class="pb-step__n" aria-hidden="true">2</span><h3 class="pb-step__title">Відливаємо під ваше замовлення</h3><p>З оригінального флакона виробника. Кожен декант маркуємо, щоб можна було ідентифікувати флакон, з якого зроблено відлив. На запит покажемо відео з флаконом.</p></li>
				<li class="pb-step"><span class="pb-step__n" aria-hidden="true">3</span><h3 class="pb-step__title">Відправляємо або чекаємо в магазині</h3><p>Нова Пошта — відправки в понеділок, середу й пʼятницю. Або самовивіз в одному з двох магазинів у Дніпрі.</p></li>
			</ol>
			<div class="pb-decant__pack">
				<p class="pb-decant__included"><strong>У ціні:</strong> <?php echo esc_html( Settings::get( 'packaging_included' ) ); ?></p>
				<p class="pb-decant__optional"><strong>За бажанням:</strong> <?php echo esc_html( Settings::get( 'bottle_note' ) ); ?></p>
				<ul class="pb-bottles">
					<?php foreach ( self::bottles() as $b ) : ?>
						<li class="pb-bottle">
							<img src="<?php echo esc_url( $b['img'] ); ?>" width="120" height="120" alt="" loading="lazy" decoding="async">
							<span class="pb-bottle__name">Флакон <?php echo esc_html( $b['ml'] ); ?> мл</span>
							<?php if ( $b['price'] ) : ?>
								<span class="pb-bottle__price">+<?php echo wp_kses_post( wc_price( $b['price'], array( 'decimals' => 0 ) ) ); ?></span>
							<?php endif; ?>
							<span class="pb-bottle__for"><?php echo esc_html( 20 === $b['ml'] ? 'до 15 і 20 мл' : 'до ' . $b['ml'] . ' мл' ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>
		<?php
	}

	private static function reviews( int $n = 6 ): array {
		$comments = get_comments( array(
			'post_type'  => 'product',
			'status'     => 'approve',
			'type'       => 'review',
			'number'     => 40,
			'meta_query' => array( array( 'key' => 'rating', 'value' => 4, 'compare' => '>=', 'type' => 'NUMERIC' ) ), // phpcs:ignore
		) );
		$out = array();
		foreach ( $comments as $c ) {
			$len = mb_strlen( trim( $c->comment_content ) );
			if ( $len < 25 || $len > 320 ) {
				continue;
			}
			$p = wc_get_product( $c->comment_post_ID );
			if ( ! $p || 'publish' !== $p->get_status() ) {
				continue;
			}
			$out[] = array( 'c' => $c, 'p' => $p );
			if ( count( $out ) >= $n ) {
				break;
			}
		}
		return $out;
	}

	private static function section_proof(): void {
		global $wpdb;
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} c JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID WHERE c.comment_approved = '1' AND c.comment_type = 'review' AND p.post_type = 'product'" );
		$avg     = (float) $wpdb->get_var( "SELECT AVG(CAST(m.meta_value AS DECIMAL(3,2))) FROM {$wpdb->commentmeta} m JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id WHERE m.meta_key = 'rating' AND c.comment_approved = '1' AND c.comment_type = 'review'" );
		$reviews = self::reviews();
		$img     = PSHYK_BOUTIQUE_URL . 'assets/img/';
		?>
		<section class="pb-section pb-proof" aria-labelledby="pb-proof-title" data-pb-reveal>
			<div class="pb-section__head pb-section__head--row">
				<div>
					<?php echo self::eyebrow( 'Відгуки зі сторінок ароматів' ); // phpcs:ignore ?>
					<h2 id="pb-proof-title" class="pb-h2">Що кажуть покупці</h2>
				</div>
				<?php if ( $total ) : ?>
					<p class="pb-proof__score"><strong><?php echo esc_html( number_format_i18n( $avg, 1 ) ); ?></strong><span> з 5 · <?php echo esc_html( number_format_i18n( $total ) . ' ' . Catalog::plural( $total, 'відгук', 'відгуки', 'відгуків' ) ); ?></span></p>
				<?php endif; ?>
			</div>
			<?php if ( $reviews ) : ?>
				<ul class="pb-quotes">
					<?php
					foreach ( $reviews as $r ) :
						$rating = (int) get_comment_meta( $r['c']->comment_ID, 'rating', true );
						list( $brand, $title ) = self::split_name( $r['p'] );
						?>
						<li class="pb-quote">
							<p class="pb-quote__stars" aria-label="<?php echo esc_attr( 'Оцінка ' . $rating . ' з 5' ); ?>"><?php echo esc_html( str_repeat( '★', $rating ) . str_repeat( '☆', 5 - $rating ) ); ?></p>
							<blockquote class="pb-quote__text"><p><?php echo esc_html( wp_strip_all_tags( $r['c']->comment_content ) ); ?></p></blockquote>
							<p class="pb-quote__meta"><span class="pb-quote__author"><?php echo esc_html( $r['c']->comment_author ); ?></span> про <a href="<?php echo esc_url( $r['p']->get_permalink() . '#reviews' ); ?>"><?php echo esc_html( trim( $brand . ' ' . $title ) ); ?></a></p>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<div class="pb-about">
				<img class="pb-about__img" src="<?php echo esc_url( $img . 'store-interior-800.webp' ); ?>" srcset="<?php echo esc_url( $img . 'store-interior-480.webp' ); ?> 480w, <?php echo esc_url( $img . 'store-interior-800.webp' ); ?> 800w" sizes="(min-width: 1024px) 520px, 100vw" width="800" height="600" alt="Інтерʼєр фірмового магазину Pshyk Rozpyv" loading="lazy" decoding="async">
				<div class="pb-about__text">
					<?php echo self::eyebrow( 'Про нас' ); // phpcs:ignore ?>
					<h3 class="pb-h3">Розпив, який можна понюхати наживо</h3>
					<p>Pshyk_rozpyv — сервіс розпиву оригінальної парфумерії, створений у 2021 році. Ми відкрили два фірмові магазини в Дніпрі, тож аромати можна не лише замовити онлайн, а й послухати на місці.</p>
					<p>В асортименті — і хіти, і рідкісні нішеві аромати, готові добірки та подарункові набори.</p>
					<a class="pb-link pb-link--arrow" href="<?php echo esc_url( home_url( '/about-us/' ) ); ?>">Детальніше про нас</a>
				</div>
			</div>
		</section>
		<?php
	}

	private static function section_instagram(): void {
		$code   = trim( (string) Settings::get( 'instagram_shortcode' ) );
		$handle = (string) Settings::get( 'instagram_handle' );
		$url    = (string) Settings::get( 'instagram_url' );
		?>
		<section class="pb-section pb-insta" aria-labelledby="pb-insta-title" data-pb-reveal>
			<div class="pb-insta__head">
				<?php echo self::eyebrow( 'Новинки, розпаковки, відповіді на питання' ); // phpcs:ignore ?>
				<h2 id="pb-insta-title" class="pb-h2">Ми в Instagram <a class="pb-insta__handle" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">@<?php echo esc_html( $handle ); ?></a></h2>
				<p>Там ми першими показуємо нові надходження і відповідаємо в дірект — зокрема, допомагаємо з вибором.</p>
				<a class="pb-btn pb-btn--ghost" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">Відкрити Instagram</a>
			</div>
			<?php if ( '' !== $code && shortcode_exists( trim( strtok( trim( $code, '[]' ), ' ' ) ) ) ) : ?>
				<div class="pb-insta__feed"><?php echo do_shortcode( $code ); // phpcs:ignore ?></div>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function section_service(): void {
		$stores = Settings::stores();
		$min    = (int) Settings::get( 'min_order' );
		$free   = (int) Settings::get( 'free_shipping_from' );
		$faq    = array(
			array( 'Це оригінальні парфуми?', 'Так. Ми відливаємо аромат лише з оригінального заводського флакона. На запит покажемо відео з флаконом, зробимо огляд і надішлемо код, який можна перевірити самостійно.' ),
			array( 'Який мінімальний об\'єм?', 'Зазвичай 3 мл, для деяких ароматів — від 1 мл. Доступні об\'єми та ціну кожного видно на сторінці аромату.' ),
			array( 'Що входить у ціну?', Settings::get( 'packaging_included' ) . ' ' . Settings::get( 'bottle_note' ) ),
			array( 'Коли відправите замовлення?', 'Зазвичай протягом 1–2 робочих днів. Відправляємо в пн, ср і пт; замовлення, оплачені до 13:00 у середу чи пʼятницю, зазвичай їдуть того ж дня. Замовлення з пʼятниці по неділю — найчастіше в понеділок.' ),
			array( 'Як оплатити?', 'Онлайн-оплатою на сайті або на рахунок ФОП — 100% передоплата. Накладеного платежу немає; товар без передоплати не бронюємо.' ),
			array( 'Чи можна повернути або обміняти?', 'Ні: за Законом України «Про захист прав споживачів» парфумерно-косметичні товари не підлягають обміну й поверненню. Претензії щодо якості чи кількості приймаємо одразу після отримання і вирішуємо індивідуально.' ),
		);
		?>
		<section class="pb-section pb-service" id="pb-service" aria-labelledby="pb-service-title" data-pb-reveal>
			<div class="pb-section__head">
				<?php echo self::eyebrow( 'Перед замовленням' ); // phpcs:ignore ?>
				<h2 id="pb-service-title" class="pb-h2">Доставка, самовивіз <em>і відповіді</em></h2>
			</div>
			<div class="pb-service__grid">
				<div class="pb-service__block">
					<h3 class="pb-h3">Доставка</h3>
					<ul class="pb-facts">
						<li>Новою Поштою по Україні</li>
						<li>Відправки: понеділок, середа, пʼятниця</li>
						<?php if ( $min ) : ?>
							<li>Мінімальне замовлення — <?php echo wp_kses_post( wc_price( $min, array( 'decimals' => 0 ) ) ); ?></li>
						<?php endif; ?>
						<?php if ( $free ) : ?>
							<li>Безкоштовна доставка — від <?php echo wp_kses_post( wc_price( $free, array( 'decimals' => 0 ) ) ); ?></li>
						<?php endif; ?>
					</ul>
					<a class="pb-link pb-link--arrow" href="<?php echo esc_url( home_url( '/dostavka-ta-oplata/' ) ); ?>">Усі умови доставки та оплати</a>
				</div>
				<div class="pb-service__block">
					<h3 class="pb-h3">Самовивіз у Дніпрі</h3>
					<ul class="pb-stores">
						<?php foreach ( $stores as $st ) : ?>
							<li class="pb-store">
								<span class="pb-store__addr"><?php echo esc_html( $st['address'] ); ?></span>
								<?php if ( $st['phone'] ) : ?>
									<a class="pb-store__phone" href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $st['phone'] ) ); ?>"><?php echo esc_html( $st['phone'] ); ?></a>
								<?php endif; ?>
								<?php if ( $st['map'] ) : ?>
									<a class="pb-link" href="<?php echo esc_url( $st['map'] ); ?>" target="_blank" rel="noopener">На карті</a>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="pb-service__muted">Графік: <?php echo esc_html( Settings::get( 'store_hours' ) ); ?>. Забрати замовлення можна після сповіщення про готовність (зазвичай до 2 робочих днів); зберігаємо 5 днів.</p>
				</div>
				<div class="pb-service__block pb-service__faq">
					<h3 class="pb-h3">Часті питання</h3>
					<?php foreach ( $faq as $f ) : ?>
						<details class="pb-faq">
							<summary><?php echo esc_html( $f[0] ); ?></summary>
							<p><?php echo esc_html( $f[1] ); ?></p>
						</details>
					<?php endforeach; ?>
					<p class="pb-service__contact">Лишилися питання? <a class="pb-link" href="<?php echo esc_url( Settings::get( 'instagram_url' ) ); ?>" target="_blank" rel="noopener">Instagram</a> · <a class="pb-link" href="mailto:<?php echo esc_attr( Settings::get( 'email' ) ); ?>"><?php echo esc_html( Settings::get( 'email' ) ); ?></a> · <a class="pb-link" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Контакти</a></p>
				</div>
			</div>
		</section>
		<?php
	}
}
