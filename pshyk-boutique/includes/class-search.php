<?php
namespace Pshyk\Boutique;

defined( 'ABSPATH' ) || exit;

/**
 * Product search that forgives typos and Cyrillic spellings of brand names.
 *
 *  «bacarat» → baccarat, «баккара» → baccarat, «Діор» → dior, «tomford» → tom ford, «lost chery» → lost cherry.
 *
 * How: a vocabulary of words from published product titles (cached, rebuilt when a product changes).
 * Each query word that is not in the vocabulary is transliterated and matched by a phonetic key + Levenshtein
 * distance. The corrected phrase is searched *in addition to* the original one, so exact matches are never lost.
 * Works for the theme search page, WoodMart live search and the Store API — all use WP_Query.
 */
final class Search {

	const CACHE = 'pshyk_boutique_search_vocab_v1';

	public static function init(): void {
		add_filter( 'posts_search', array( __CLASS__, 'posts_search' ), 20, 2 );
		add_action( 'save_post_product', array( __CLASS__, 'flush' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'flush' ) );
	}

	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	private static function is_product_search( \WP_Query $q ): bool {
		if ( ! $q->is_search() || '' === trim( (string) $q->get( 's' ) ) ) {
			return false;
		}
		$pt = $q->get( 'post_type' );
		return 'product' === $pt || ( is_array( $pt ) && in_array( 'product', $pt, true ) );
	}

	public static function posts_search( $search, $q ) {
		if ( ! $q instanceof \WP_Query || ! self::is_product_search( $q ) ) {
			return $search;
		}
		$original  = self::normalize( (string) $q->get( 's' ) );
		$corrected = self::correct( $original );
		if ( '' === $corrected || $corrected === $original ) {
			return $search;
		}
		global $wpdb;
		$groups = array();
		foreach ( array_unique( array( $original, $corrected ) ) as $phrase ) {
			$and = array();
			foreach ( preg_split( '/\s+/u', $phrase ) as $word ) {
				if ( '' === $word ) {
					continue;
				}
				$like  = '%' . $wpdb->esc_like( $word ) . '%';
				$and[] = $wpdb->prepare( "({$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content LIKE %s)", $like, $like, $like );
			}
			if ( $and ) {
				$groups[] = '(' . implode( ' AND ', $and ) . ')';
			}
		}
		return $groups ? ' AND (' . implode( ' OR ', $groups ) . ') ' : $search;
	}

	public static function normalize( string $s ): string {
		$s = mb_strtolower( wp_strip_all_tags( $s ) );
		$s = str_replace( array( '’', 'ʼ', '`', '´', '‘' ), "'", $s );
		$s = preg_replace( '/[^\p{L}\p{N}\'\.&\s-]+/u', ' ', $s );
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	/** Ukrainian → Latin, tuned for brand names (х→h, г→g, и→i). */
	public static function translit( string $s ): string {
		static $map = array(
			'щ' => 'shch', 'дж' => 'j', 'ж' => 'zh', 'ч' => 'ch', 'ш' => 'sh', 'ц' => 'ts', 'х' => 'h', 'є' => 'ye', 'ї' => 'yi', 'ю' => 'yu', 'я' => 'ya',
			'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'з' => 'z', 'и' => 'i', 'і' => 'i', 'ы' => 'y',
			'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
			'ф' => 'f', 'ь' => '', 'ъ' => '', 'э' => 'e', "'" => '',
		);
		return strtr( $s, $map );
	}

	/** Phonetic key: spelling differences that sound alike collapse to the same key. */
	public static function key( string $word ): string {
		$w = self::translit( $word );
		$w = preg_replace( '/[^a-z0-9]/', '', $w );
		$w = strtr( $w, array( 'ph' => 'f', 'ck' => 'k', 'qu' => 'k', 'ch' => 'sh', 'kh' => 'h', 'ts' => 'c', 'ou' => 'u', 'oo' => 'u', 'ee' => 'i' ) );
		$w = strtr( $w, array( 'c' => 'k', 'q' => 'k', 'w' => 'v', 'x' => 'ks', 'y' => 'i', 'j' => 'i', 'z' => 's' ) );
		$w = preg_replace( '/(.)\1+/', '$1', $w );          // double letters
		$w = preg_replace( '/(?<=.{3})[ht]$/', '', $w );     // silent final h/t (Baccarat, Mont Blanc's «-t»)
		return (string) $w;
	}

	/** @return array{words: array<string,int>, keys: array<string,string>} */
	private static function vocabulary(): array {
		$v = get_transient( self::CACHE );
		if ( is_array( $v ) ) {
			return $v;
		}
		global $wpdb;
		$titles = $wpdb->get_col( "SELECT post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
		$words  = array();
		foreach ( $titles as $t ) {
			foreach ( preg_split( '/\s+/u', self::normalize( $t ) ) as $w ) {
				if ( mb_strlen( $w ) >= 2 ) {
					$words[ $w ] = ( $words[ $w ] ?? 0 ) + 1;
				}
			}
		}
		$keys = array();
		foreach ( $words as $w => $n ) {
			$k = self::key( $w );
			if ( '' !== $k && ( ! isset( $keys[ $k ] ) || $words[ $keys[ $k ] ] < $n ) ) {
				$keys[ $k ] = $w;
			}
		}
		$v = array( 'words' => $words, 'keys' => $keys );
		set_transient( self::CACHE, $v, 12 * HOUR_IN_SECONDS );
		return $v;
	}

	public static function correct( string $phrase ): string {
		$phrase = self::normalize( $phrase );
		if ( '' === $phrase ) {
			return '';
		}
		$v   = self::vocabulary();
		$out = array();
		foreach ( explode( ' ', $phrase ) as $word ) {
			$out[] = self::correct_word( $word, $v );
		}
		return implode( ' ', $out );
	}

	private static function correct_word( string $word, array $v ): string {
		if ( isset( $v['words'][ $word ] ) || mb_strlen( $word ) < 3 ) {
			return $word;
		}
		$key = self::key( $word );
		if ( isset( $v['keys'][ $key ] ) ) {
			return $v['keys'][ $key ];
		}
		$len   = strlen( $key );
		$limit = $len <= 4 ? 0 : ( $len <= 7 ? 1 : 2 );
		$best  = null;
		$bestd = PHP_INT_MAX;
		if ( $limit > 0 ) {
			foreach ( $v['keys'] as $k => $orig ) {
				if ( abs( strlen( $k ) - $len ) > $limit ) {
					continue;
				}
				$d = levenshtein( $key, (string) $k );
				if ( $d < $bestd || ( null !== $best && $d === $bestd && $v['words'][ $orig ] > $v['words'][ $best ] ) ) {
					$best  = $orig;
					$bestd = $d;
				}
			}
		}
		if ( null !== $best && $bestd <= $limit ) {
			return $best;
		}
		/* Glued words: «tomford» → «tom ford». */
		for ( $i = 2; $i <= $len - 2; $i++ ) {
			$l = substr( $key, 0, $i );
			$r = substr( $key, $i );
			if ( isset( $v['keys'][ $l ], $v['keys'][ $r ] ) ) {
				return $v['keys'][ $l ] . ' ' . $v['keys'][ $r ];
			}
		}
		/* Cyrillic word with no match: still try its transliteration (helps partial input like «діо»). */
		$t = self::translit( $word );
		return $t !== $word ? $t : $word;
	}
}
