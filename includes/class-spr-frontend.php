<?php
/**
 * Front-end placements, shortcode, assets.
 *
 * Every placement prints a lightweight placeholder; assets/js/spr.js loads all
 * blocks on the page in a single REST call. This keeps full-page caching intact.
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

class SPR_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_shortcode( 'spr_recommendations', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp', array( __CLASS__, 'placements' ) );
		add_filter( 'render_block', array( __CLASS__, 'block_cart' ), 10, 2 );
	}

	public static function assets() {
		wp_register_style( 'spr', SPR_URL . 'assets/css/spr.css', array(), SPR_VERSION );
		wp_register_script( 'spr', SPR_URL . 'assets/js/spr.js', array(), SPR_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );

		$data = array(
			'rest'    => esc_url_raw( rest_url( SPR_REST::NS . '/' ) ),
			'nonce'   => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'product' => ( function_exists( 'is_product' ) && is_product() ) ? (int) get_queried_object_id() : 0,
			'path'    => COOKIEPATH ? COOKIEPATH : '/',
			// Bundle ("bought together") needs WooCommerce's AJAX cart endpoint and price format.
			'wcAjax'  => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'add_to_cart' ) : home_url( '/?wc-ajax=add_to_cart' ),
			'cartUrl' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ),
			'money'   => array(
				'symbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '',
				'pos'      => get_option( 'woocommerce_currency_pos', 'right_space' ),
				'decimals' => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 0,
				'dec'      => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
				'thou'     => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
			),
			'i18n'    => array(
				'adding'  => __( 'Adding…', 'smart-product-recommendations' ),
				/* translators: %d: number of products */
				'added'   => __( '%d items added to your cart.', 'smart-product-recommendations' ),
				'viewCart' => __( 'View cart', 'smart-product-recommendations' ),
				'failed'  => __( 'Some items could not be added. Open the product page to choose options.', 'smart-product-recommendations' ),
				'none'    => __( 'Select at least one item.', 'smart-product-recommendations' ),
			),
		);
		wp_add_inline_script( 'spr', 'window.SPR=' . wp_json_encode( $data ) . ';', 'before' );

		// Product pages always need the script (recently-viewed cookie + view counter).
		if ( $data['product'] ) {
			wp_enqueue_script( 'spr' );
			wp_enqueue_style( 'spr' );
		}
	}

	private static function enqueue() {
		wp_enqueue_style( 'spr' );
		wp_enqueue_script( 'spr' );
	}

	/**
	 * Hook placements for the current page.
	 */
	public static function placements() {
		if ( is_admin() ) {
			return;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$similar = SPR_Settings::placement( 'product_similar' );

			if ( SPR_Settings::get( 'replace_wc_related' ) && $similar && $similar['enabled'] ) {
				remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
			}

			$map = array(
				'product_bought'  => 12,
				'product_similar' => 19,
				'product_viewed'  => 25,
			);
			foreach ( $map as $key => $priority ) {
				add_action(
					'woocommerce_after_single_product_summary',
					function () use ( $key ) {
						echo self::slot_for_placement( $key, get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					},
					$priority
				);
			}
		}

		add_action(
			'woocommerce_after_cart',
			function () {
				echo self::slot_for_placement( 'cart', 0, self::cart_ids() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);

		add_action(
			'woocommerce_thankyou',
			function ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					return;
				}
				$ids = array();
				foreach ( $order->get_items() as $item ) {
					$ids[] = (int) $item->get_product_id();
				}
				echo self::slot_for_placement( 'thankyou', 0, $ids ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			},
			20
		);
	}

	/**
	 * Append the cart placement after the block-based Cart.
	 */
	public static function block_cart( $content, $block ) {
		if ( is_admin() || empty( $block['blockName'] ) ) {
			return $content;
		}
		$name = $block['blockName'];

		if ( 'woocommerce/cart' === $name ) {
			return $content . self::slot_for_placement( 'cart', 0, self::cart_ids() );
		}

		// Block themes: the classic woocommerce_after_single_product_summary hook does not run.
		if ( function_exists( 'is_product' ) && is_product() ) {
			if ( 'woocommerce/product-details' === $name ) {
				$id = get_queried_object_id();
				foreach ( array( 'product_bought', 'product_similar', 'product_viewed' ) as $key ) {
					$content .= self::slot_for_placement( $key, $id );
				}
				return $content;
			}
			$similar = SPR_Settings::placement( 'product_similar' );
			$is_related = 'woocommerce/related-products' === $name
				|| ( 'core/query' === $name && isset( $block['attrs']['namespace'] ) && 'woocommerce/related-products' === $block['attrs']['namespace'] );
			if ( $is_related && SPR_Settings::get( 'replace_wc_related' ) && $similar && $similar['enabled'] ) {
				return '';
			}
		}
		return $content;
	}

	private static function cart_ids() {
		$ids = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$ids[] = (int) $item['product_id'];
			}
		}
		return $ids;
	}

	private static function slot_for_placement( $key, $product_id = 0, array $items = array() ) {
		static $done = array();
		$p = SPR_Settings::placement( $key );
		if ( ! $p || empty( $p['enabled'] ) || isset( $done[ $key ] ) ) {
			return ''; // Each built-in placement prints once per page (classic + block hooks).
		}
		$done[ $key ] = true;
		return self::slot(
			array(
				'source'    => $p['source'],
				'placement' => $key,
				'limit'     => (int) $p['limit'],
				'title'     => $p['title'],
				'product'   => (int) $product_id,
				'items'     => $items,
			)
		);
	}

	/**
	 * Placeholder markup that spr.js fills in.
	 *
	 * @param array $a source, placement, limit, title, product, items, layout, columns.
	 * @return string
	 */
	public static function slot( array $a ) {
		self::enqueue();

		$a = wp_parse_args(
			$a,
			array(
				'source'    => 'similar',
				'placement' => 'sc_similar',
				'limit'     => 8,
				'title'     => '',
				'product'   => 0,
				'items'     => array(),
				'layout'    => '',
				'columns'   => '',
			)
		);

		$attrs = array(
			'class'          => 'spr-slot spr-loading',
			'data-spr-source' => $a['source'],
			'data-spr-placement' => $a['placement'],
			'data-spr-limit' => (int) $a['limit'],
			'data-spr-title' => $a['title'],
		);
		if ( $a['product'] ) {
			$attrs['data-spr-product'] = (int) $a['product'];
		}
		if ( $a['items'] ) {
			$attrs['data-spr-items'] = implode( ',', SPR_Engine::ids( $a['items'] ) );
		}
		if ( in_array( $a['layout'], array( 'grid', 'carousel' ), true ) ) {
			$attrs['data-spr-layout'] = $a['layout'];
		}
		if ( $a['columns'] ) {
			$attrs['data-spr-columns'] = max( 1, min( 6, (int) $a['columns'] ) );
		}

		$html = '<div';
		foreach ( $attrs as $k => $v ) {
			$html .= ' ' . $k . '="' . esc_attr( (string) $v ) . '"';
		}
		$html .= ' aria-busy="true"><div class="spr-skeleton"><span></span><span></span><span></span><span></span></div></div>';
		return $html;
	}

	/**
	 * [spr_recommendations source="similar|bought|viewed|for_you|trending|cart" limit="8" title="" product_id="" layout="grid|carousel" columns="4"]
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'source'     => 'similar',
				'limit'      => 8,
				'title'      => null,
				'product_id' => 0,
				'layout'     => '',
				'columns'    => '',
			),
			$atts,
			'spr_recommendations'
		);

		$sources = SPR_Settings::sources();
		$source  = sanitize_key( $atts['source'] );
		if ( ! isset( $sources[ $source ] ) ) {
			return '';
		}

		$product_id = absint( $atts['product_id'] );
		if ( ! $product_id && in_array( $source, array( 'similar', 'bought' ), true ) ) {
			global $product;
			if ( $product instanceof WC_Product ) {
				$product_id = $product->get_id();
			} elseif ( function_exists( 'is_product' ) && is_product() ) {
				$product_id = get_queried_object_id();
			}
			if ( ! $product_id ) {
				return '';
			}
		}

		return self::slot(
			array(
				'source'    => $source,
				'placement' => 'sc_' . $source,
				'limit'     => max( 1, min( 24, (int) $atts['limit'] ) ),
				'title'     => null === $atts['title'] ? $sources[ $source ] : (string) $atts['title'],
				'product'   => $product_id,
				'items'     => 'cart' === $source ? self::cart_ids() : array(),
				'layout'    => $atts['layout'],
				'columns'   => $atts['columns'],
			)
		);
	}
}
