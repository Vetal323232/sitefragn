<?php
namespace Pshyk\Boutique;

defined( 'ABSPATH' ) || exit;

/**
 * Customer-facing English strings found on the live site (2026-11-15) → Ukrainian.
 * gettext covers strings that go through translation functions; boutique.js has a fallback for the
 * back-in-stock button in case its plugin prints the text without gettext.
 */
final class I18n {

	public static function map(): array {
		return array(
			'Notify me when available'                      => 'Повідомити про надходження',
			'Notify me when this product is back in stock'  => 'Повідомити, коли аромат знову буде в наявності',
			'Increase quantity'                             => 'Збільшити кількість',
			'Decrease quantity'                             => 'Зменшити кількість',
		);
	}

	public static function init(): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		add_filter( 'gettext', array( __CLASS__, 'gettext' ), 20, 3 );
	}

	public static function gettext( $translation, $text, $domain ) {
		static $map = null;
		if ( null === $map ) {
			$map = self::map();
		}
		/* Only replace when nobody translated it (translation is still the English original). */
		if ( isset( $map[ $text ] ) && $translation === $text ) {
			return $map[ $text ];
		}
		return $translation;
	}
}
