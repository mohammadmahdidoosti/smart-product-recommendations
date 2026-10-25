<?php
/**
 * Persian/English aware tokenizer for product titles.
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

class SPR_Tokenizer {

	/** @var array|null */
	private static $stop = null;

	/**
	 * Normalize Arabic/Persian variants, digits and spacing.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );

		$map = array(
			'ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'ۀ' => 'ه',
			'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و',
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			"\u{200C}" => ' ', // ZWNJ: split "کفش‌های" into "کفش" + "های".
			"\u{200D}" => '',
			"\u{0640}" => '',  // Tatweel.
		);
		$text = strtr( $text, $map );
		// Arabic diacritics (harakat, tanwin, superscript alef).
		$text = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $text );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Split text into unique, meaningful tokens.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max tokens.
	 * @return string[]
	 */
	public static function tokens( $text, $max = 40 ) {
		$text  = self::normalize( $text );
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! $parts ) {
			return array();
		}

		$stop = self::stopwords();
		$out  = array();
		foreach ( $parts as $p ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $p, 'UTF-8' ) : strlen( $p );
			if ( $len < 2 || isset( $stop[ $p ] ) ) {
				continue;
			}
			if ( $len > 48 ) {
				$p = mb_substr( $p, 0, 48, 'UTF-8' );
			}
			$out[ $p ] = true;
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return array_keys( $out );
	}

	private static function stopwords() {
		if ( null === self::$stop ) {
			$words = array(
				// Persian.
				'و', 'در', 'به', 'از', 'که', 'با', 'این', 'ان', 'برای', 'را', 'های', 'ها', 'هایی', 'ای', 'یک', 'تا',
				'بر', 'یا', 'هم', 'نیز', 'مدل', 'رنگ', 'سایز', 'طرح', 'جدید', 'اصل', 'اصلی', 'عدد', 'عددی', 'بسته',
				'مناسب', 'مخصوص', 'همراه', 'کد', 'تعداد', 'سری', 'نوع', 'دار', 'می', 'شود', 'است', 'تر', 'ترین',
				// English.
				'the', 'and', 'for', 'with', 'of', 'in', 'on', 'to', 'by', 'new', 'model', 'color', 'size', 'pack',
				'pcs', 'set', 'from', 'an', 'or', 'is',
			);
			$words      = apply_filters( 'spr_stopwords', $words );
			self::$stop = array_fill_keys( array_map( array( __CLASS__, 'normalize' ), $words ), true );
		}
		return self::$stop;
	}
}
