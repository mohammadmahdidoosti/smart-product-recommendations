<?php
/**
 * Settings storage and defaults.
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

class SPR_Settings {

	/** @var array|null */
	private static $cache = null;

	/**
	 * Placement keys and their recommendation source.
	 * "Source" tells the engine which algorithm feeds the placement.
	 */
	public static function placements() {
		return array(
			'product_similar' => array(
				'label'  => __( 'Product page — similar products', 'smart-product-recommendations' ),
				'title'  => __( 'Similar products', 'smart-product-recommendations' ),
				'source' => 'similar',
				'limit'  => 8,
			),
			'product_bought'  => array(
				'label'  => __( 'Product page — frequently bought together', 'smart-product-recommendations' ),
				'title'  => __( 'Frequently bought together', 'smart-product-recommendations' ),
				'source' => 'bought',
				'limit'  => 4,
			),
			'product_viewed'  => array(
				'label'  => __( 'Product page — recently viewed', 'smart-product-recommendations' ),
				'title'  => __( 'Recently viewed', 'smart-product-recommendations' ),
				'source' => 'viewed',
				'limit'  => 8,
			),
			'cart'            => array(
				'label'  => __( 'Cart page', 'smart-product-recommendations' ),
				'title'  => __( 'You may also like', 'smart-product-recommendations' ),
				'source' => 'cart',
				'limit'  => 4,
			),
			'thankyou'        => array(
				'label'  => __( 'Order received (thank-you) page', 'smart-product-recommendations' ),
				'title'  => __( 'Recommended for you', 'smart-product-recommendations' ),
				'source' => 'order',
				'limit'  => 4,
			),
		);
	}

	/**
	 * Sources usable from the shortcode / widget.
	 */
	public static function sources() {
		return array(
			'similar' => __( 'Similar products', 'smart-product-recommendations' ),
			'bought'  => __( 'Frequently bought together', 'smart-product-recommendations' ),
			'viewed'  => __( 'Recently viewed', 'smart-product-recommendations' ),
			'for_you' => __( 'Recommended for you', 'smart-product-recommendations' ),
			'trending' => __( 'Trending now', 'smart-product-recommendations' ),
			'cart'    => __( 'You may also like', 'smart-product-recommendations' ),
		);
	}

	public static function defaults() {
		$placements = array();
		foreach ( self::placements() as $key => $p ) {
			$placements[ $key ] = array(
				'enabled' => 1,
				'title'   => '', // Empty = translated default title.
				'limit'   => $p['limit'],
			);
		}

		return array(
			// Display.
			'placements'         => $placements,
			'layout'             => 'carousel', // carousel|grid.
			'columns'            => 4,
			'show_add_to_cart'   => 1,
			'hide_out_of_stock'  => 1,
			'replace_wc_related' => 1,
			'exclude_ids'        => '',
			'exclude_cats'       => array(),

			// Appearance.
			'style'              => 'card',    // card|minimal|compact.
			'accent'             => '#0F8B8D',
			'radius'             => 12,
			'show_discount'      => 1,
			'show_rating'        => 1,
			'bundle'             => 1,         // "Bought together" as a bundle with add-all-to-cart.

			// Content similarity weights.
			'w_cat'              => 3,
			'w_tag'              => 2,
			'w_brand'            => 3,
			'w_attr'             => 1.5,
			'w_title'            => 2,
			'w_price'            => 0.15, // 0..1 share of final score taken by price closeness.
			'max_term_share'     => 20,   // % — terms shared by more products than this are ignored (too generic).

			// Co-purchase.
			'min_support'        => 2,
			'lookback_days'      => 365,

			// Index.
			'store_top'          => 30,
			'batch_size'         => 100,
			'schedule'           => 'daily', // daily|weekly|manual.
			'cache_minutes'      => 60,
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved    = get_option( 'spr_settings', array() );
			$defaults = self::defaults();
			$merged   = array_merge( $defaults, is_array( $saved ) ? $saved : array() );

			// Deep-merge placements so new placements get defaults.
			$merged['placements'] = $defaults['placements'];
			if ( ! empty( $saved['placements'] ) && is_array( $saved['placements'] ) ) {
				foreach ( $saved['placements'] as $k => $v ) {
					if ( isset( $merged['placements'][ $k ] ) && is_array( $v ) ) {
						$merged['placements'][ $k ] = array_merge( $merged['placements'][ $k ], $v );
					}
				}
			}
			self::$cache = $merged;
		}
		return self::$cache;
	}

	/**
	 * Temporarily override settings for this request (admin live preview).
	 *
	 * @param array $values Values.
	 */
	public static function override( array $values ) {
		self::$cache = array_merge( self::all(), $values );
	}

	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	public static function placement( $key ) {
		$all  = self::all();
		$defs = self::placements();
		if ( ! isset( $all['placements'][ $key ], $defs[ $key ] ) ) {
			return null;
		}
		$p          = $all['placements'][ $key ];
		$p['title'] = '' !== trim( (string) $p['title'] ) ? $p['title'] : $defs[ $key ]['title'];
		$p['source'] = $defs[ $key ]['source'];
		return $p;
	}

	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Brand taxonomies that exist on this site (WooCommerce Brands + popular brand plugins).
	 */
	public static function brand_taxonomies() {
		$candidates = apply_filters( 'spr_brand_taxonomies', array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand' ) );
		return array_values( array_filter( $candidates, 'taxonomy_exists' ) );
	}

	/**
	 * Product attribute taxonomies (pa_*).
	 */
	public static function attribute_taxonomies() {
		$out = array();
		if ( function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
			$out = wc_get_attribute_taxonomy_names();
		}
		return apply_filters( 'spr_attribute_taxonomies', $out );
	}
}
