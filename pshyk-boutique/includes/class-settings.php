<?php
namespace Pshyk\Boutique;

defined( 'ABSPATH' ) || exit;

/**
 * All customer-facing copy and business facts live here, editable in WooCommerce → Pshyk Boutique,
 * so staff can correct a phrase without a developer. Defaults come from the live site pages
 * «Доставка та оплата», «Контакти», «Про нас», «Про деканти» (checked 2026-11-15).
 */
final class Settings {

	const OPTION = 'pshyk_boutique';

	public static function defaults(): array {
		return array(
			'enable_skin'        => 1,
			'hide_visits_count'  => 1,
			'decant_categories'  => 'rozpyv',
			'per_page'           => 0,
			'min_order'          => 400,
			'free_shipping_from' => 2500,
			'packaging_included' => 'Аромат розливаємо в стандартний атомайзер-розпилювач — він уже входить у ціну.',
			'bottle_note'        => 'Красивий флакон — окремий багаторазовий флакон за додаткову плату. Його пропонуємо для 5, 10, 15 і 20 мл.',
			'ship_note'          => 'Відправляємо Новою Поштою в пн, ср, пт. Самовивіз у Дніпрі — зазвичай до 2 робочих днів після підтвердження.',
			'hero_eyebrow'       => 'Розпив оригінальної парфумерії · з 2021 року',
			'hero_title'         => 'Спробуйте аромат, <em>перш ніж</em> купувати флакон',
			'hero_lead'          => 'Відливаємо потрібний об\'єм з оригінального заводського флакона саме під ваше замовлення. Від 3 мл — щоб познайомитися з ароматом на власній шкірі.',
			'collection_title'   => 'Парфуми, які пахнуть чистотою',
			'collection_text'    => 'Тренд на «чисті» аромати з роками набирає обертів: пахнути доглянуто, але не парфумами — доволі цікаво, погодьтесь? Чистота в ароматах буває дуже різною: мило, кондиціонер для білизни, чиста шкіра. Експериментуйте й шукайте своє.',
			'collection_ids'     => '',
			'instagram_url'      => 'https://www.instagram.com/pshyk_rozpyv/',
			'instagram_handle'   => 'pshyk_rozpyv',
			'instagram_shortcode'=> '',
			'stores'             => "пров. Вільний, 4а (Лівобережний, 3)|+380 93 015 63 57|https://maps.google.com/?q=Дніпро,+провулок+Вільний,+4а\nпр-т Слобожанський, 86 (ТЦ «Правда-Кіно»)|+380 93 918 31 69|https://maps.google.com/?q=Дніпро,+проспект+Слобожанський,+86",
			'store_hours'        => 'щодня з 10:00 до 20:00',
			'email'              => 'manager@pshykrozpyv.com',
		);
	}

	public static function get( string $key ) {
		static $opts = null;
		if ( null === $opts ) {
			$opts = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		}
		return $opts[ $key ] ?? null;
	}

	/** @return array<int, array{address:string, phone:string, map:string}> */
	public static function stores(): array {
		$out = array();
		foreach ( preg_split( '/\r?\n/', (string) self::get( 'stores' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( '' !== $parts[0] ) {
				$out[] = array( 'address' => $parts[0], 'phone' => $parts[1] ?? '', 'map' => $parts[2] ?? '' );
			}
		}
		return $out;
	}

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', 'Pshyk Boutique', 'Pshyk Boutique', 'manage_woocommerce', 'pshyk-boutique', array( __CLASS__, 'page' ) );
	}

	public static function register(): void {
		register_setting( 'pshyk_boutique', self::OPTION, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $in ): array {
		$d   = self::defaults();
		$out = array();
		foreach ( $d as $k => $v ) {
			if ( is_int( $v ) ) {
				$out[ $k ] = isset( $in[ $k ] ) ? absint( $in[ $k ] ) : 0;
			} elseif ( in_array( $k, array( 'hero_title' ), true ) ) {
				$out[ $k ] = wp_kses( $in[ $k ] ?? $v, array( 'em' => array(), 'br' => array() ) );
			} elseif ( in_array( $k, array( 'stores', 'collection_text', 'hero_lead', 'packaging_included', 'bottle_note', 'ship_note' ), true ) ) {
				$out[ $k ] = sanitize_textarea_field( $in[ $k ] ?? $v );
			} elseif ( 'instagram_shortcode' === $k ) {
				$out[ $k ] = trim( wp_strip_all_tags( $in[ $k ] ?? '' ) );
			} else {
				$out[ $k ] = sanitize_text_field( $in[ $k ] ?? $v );
			}
		}
		return $out;
	}

	public static function page(): void {
		$o      = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$fields = array(
			'Загальне' => array(
				'enable_skin'        => array( 'checkbox', 'Увімкнути бутік-оформлення сайту (кольори, шрифти, картки)' ),
				'hide_visits_count'  => array( 'checkbox', 'Приховати лічильник «Люди переглядають цей товар зараз» (WoodMart показує випадкове число — краще також вимкнути його в Theme Settings)' ),
				'decant_categories'  => array( 'text', 'Слаги категорій розпиву (через кому). Ціна в цих товарах — за 1 мл, кількість у кошику — мл.' ),
				'per_page'           => array( 'number', 'Товарів за одне підвантаження в каталозі (0 — не змінювати налаштування теми). Рекомендовано 24.' ),
				'min_order'          => array( 'number', 'Мінімальна сума замовлення, ₴ (лише для текстів; перевірка — на сервері)' ),
				'free_shipping_from' => array( 'number', 'Безкоштовна доставка від, ₴ (лише для текстів)' ),
			),
			'Сторінка аромату' => array(
				'packaging_included' => array( 'textarea', 'Що входить у ціну (тара за замовчуванням) — ПЕРЕВІРТЕ формулювання' ),
				'bottle_note'        => array( 'textarea', 'Пояснення про додатковий флакон' ),
				'ship_note'          => array( 'textarea', 'Коротко про доставку й самовивіз біля кнопки' ),
			),
			'Головна' => array(
				'hero_eyebrow'        => array( 'text', 'Надзаголовок першого екрана' ),
				'hero_title'          => array( 'text', 'Заголовок (H1). Дозволено <em> для курсиву' ),
				'hero_lead'           => array( 'textarea', 'Підзаголовок' ),
				'collection_title'    => array( 'text', 'Тематична добірка: назва' ),
				'collection_text'     => array( 'textarea', 'Тематична добірка: текст' ),
				'collection_ids'      => array( 'text', 'Тематична добірка: ID товарів через кому (порядок збережеться)' ),
				'instagram_handle'    => array( 'text', 'Instagram: нікнейм' ),
				'instagram_url'       => array( 'text', 'Instagram: посилання' ),
				'instagram_shortcode' => array( 'text', 'Instagram: шорткод стрічки Smash Balloon (порожньо — лише посилання, без завантаження стрічки)' ),
				'stores'              => array( 'textarea', 'Магазини: по рядку «адреса|телефон|посилання на карту»' ),
				'store_hours'         => array( 'text', 'Графік магазинів' ),
				'email'               => array( 'text', 'E-mail' ),
			),
		);
		echo '<div class="wrap"><h1>Pshyk Boutique</h1><form method="post" action="options.php">';
		settings_fields( 'pshyk_boutique' );
		foreach ( $fields as $group => $items ) {
			echo '<h2>' . esc_html( $group ) . '</h2><table class="form-table" role="presentation">';
			foreach ( $items as $key => $f ) {
				$name = self::OPTION . '[' . $key . ']';
				echo '<tr><th scope="row"><label for="pb-' . esc_attr( $key ) . '">' . esc_html( $f[1] ) . '</label></th><td>';
				if ( 'checkbox' === $f[0] ) {
					printf( '<input type="checkbox" id="pb-%1$s" name="%2$s" value="1" %3$s>', esc_attr( $key ), esc_attr( $name ), checked( 1, (int) $o[ $key ], false ) );
				} elseif ( 'textarea' === $f[0] ) {
					printf( '<textarea id="pb-%1$s" name="%2$s" rows="3" class="large-text">%3$s</textarea>', esc_attr( $key ), esc_attr( $name ), esc_textarea( $o[ $key ] ) );
				} else {
					printf( '<input type="%4$s" id="pb-%1$s" name="%2$s" value="%3$s" class="regular-text">', esc_attr( $key ), esc_attr( $name ), esc_attr( $o[ $key ] ), esc_attr( $f[0] ) );
				}
				echo '</td></tr>';
			}
			echo '</table>';
		}
		submit_button();
		echo '</form><p>Нова головна: створіть сторінку з шорткодом <code>[pshyk_home]</code> (шаблон Elementor «Full width» або звичайний) і призначте її головною в «Налаштування → Читання». URL «/» не зміниться.</p></div>';
	}
}
