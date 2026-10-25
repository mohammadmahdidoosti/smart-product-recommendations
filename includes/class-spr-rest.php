<?php
/**
 * REST API: /wp-json/spr/v1/render (all blocks on a page in one request) and /track.
 *
 * Rendering via AJAX keeps product pages fully cacheable by page-cache plugins/CDNs
 * while recommendations stay personal (recently viewed, for you, cart).
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

class SPR_REST {

	const NS = 'spr/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NS,
			'/render',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'render' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/track',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'track' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/recommendations',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'json' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'source'     => array( 'type' => 'string', 'default' => 'similar' ),
					'product_id' => array( 'type' => 'integer', 'default' => 0 ),
					'items'      => array( 'type' => 'string', 'default' => '' ),
					'viewed'     => array( 'type' => 'string', 'default' => '' ),
					'limit'      => array( 'type' => 'integer', 'default' => 8 ),
				),
			)
		);
	}

	private static function body( WP_REST_Request $req ) {
		$data = $req->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = json_decode( (string) $req->get_body(), true );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Render every recommendation block found on the page.
	 */
	public static function render( WP_REST_Request $req ) {
		$data   = self::body( $req );
		$blocks = isset( $data['blocks'] ) && is_array( $data['blocks'] ) ? array_slice( $data['blocks'], 0, 8, true ) : array();
		$viewed = isset( $data['viewed'] ) ? array_slice( SPR_Engine::ids( $data['viewed'] ), 0, 20 ) : array();

		$sources = array_keys( SPR_Settings::sources() );
		$sources[] = 'order';
		$out       = array();
		$shown     = array(); // Avoid repeating the same product in two blocks on one page.

		foreach ( $blocks as $key => $b ) {
			$key = sanitize_key( $key );
			if ( ! is_array( $b ) || '' === $key ) {
				continue;
			}
			$source    = isset( $b['source'] ) ? sanitize_key( $b['source'] ) : '';
			$placement = isset( $b['placement'] ) ? SPR_Tracking::valid_placement( $b['placement'] ) : '';
			if ( ! in_array( $source, $sources, true ) || ! $placement ) {
				$out[ $key ] = '';
				continue;
			}

			$args = array(
				'product_id' => isset( $b['product'] ) ? absint( $b['product'] ) : 0,
				'items'      => isset( $b['items'] ) ? array_slice( SPR_Engine::ids( $b['items'] ), 0, 30 ) : array(),
				'viewed'     => $viewed,
				'limit'      => isset( $b['limit'] ) ? max( 1, min( 24, absint( $b['limit'] ) ) ) : 8,
				'exclude'    => array_keys( $shown ),
			);
			$opts  = array(
				'layout'  => isset( $b['layout'] ) && 'grid' === $b['layout'] ? 'grid' : SPR_Settings::get( 'layout' ),
				'columns' => isset( $b['columns'] ) ? max( 1, min( 6, absint( $b['columns'] ) ) ) : SPR_Settings::get( 'columns' ),
			);
			if ( isset( $b['layout'] ) && 'carousel' === $b['layout'] ) {
				$opts['layout'] = 'carousel';
			}
			if ( 'bought' === $source && $args['product_id'] ) {
				$opts['main'] = $args['product_id']; // Bundle layout with "add all to cart".
			}
			$title = isset( $b['title'] ) ? sanitize_text_field( $b['title'] ) : '';

			$html = self::cached_block( $source, $args, $title, $placement, $opts );
			if ( $html ) {
				// Remember which products this block shows.
				if ( preg_match_all( '/data-spr-pid="(\d+)"/', $html, $m ) ) {
					foreach ( $m[1] as $pid ) {
						$shown[ (int) $pid ] = true;
					}
				}
			}
			$out[ $key ] = $html;
		}

		$res = rest_ensure_response( array( 'blocks' => $out ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	/**
	 * Non-personal blocks (similar / bought / trending) are cached as HTML.
	 */
	private static function cached_block( $source, array $args, $title, $placement, array $opts ) {
		$cacheable = in_array( $source, array( 'similar', 'bought', 'trending' ), true )
			&& ! is_user_logged_in()
			&& apply_filters( 'spr_cache_enabled', true, $source, $args );

		$minutes = (int) SPR_Settings::get( 'cache_minutes', 60 );
		$key     = '';
		if ( $cacheable && $minutes > 0 ) {
			$key = 'spr_h_' . md5(
				wp_json_encode(
					array(
						get_option( 'spr_cache_ver', 1 ),
						$source,
						$args['product_id'],
						$args['limit'],
						$args['exclude'],
						$title,
						$placement,
						$opts,
						get_locale(),
						function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
					)
				)
			);
			$hit = get_transient( $key );
			if ( false !== $hit ) {
				return $hit;
			}
		}

		$ids  = SPR_Engine::get( $source, $args );
		$html = SPR_Renderer::block( $ids, $title, $placement, $opts );

		if ( $key ) {
			set_transient( $key, $html, $minutes * MINUTE_IN_SECONDS );
		}
		return $html;
	}

	/**
	 * Analytics beacon: views, impressions, clicks.
	 */
	public static function track( WP_REST_Request $req ) {
		if ( SPR_Tracking::is_bot() ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}
		$data   = self::body( $req );
		$events = isset( $data['events'] ) && is_array( $data['events'] ) ? array_slice( $data['events'], 0, 20 ) : array();

		$imp = array();
		$clk = array();
		foreach ( $events as $e ) {
			if ( ! is_array( $e ) || empty( $e['t'] ) ) {
				continue;
			}
			switch ( $e['t'] ) {
				case 'view':
					if ( ! empty( $e['id'] ) ) {
						SPR_Tracking::record_view( absint( $e['id'] ) );
					}
					break;
				case 'imp':
					$p = isset( $e['p'] ) ? SPR_Tracking::valid_placement( $e['p'] ) : '';
					if ( $p ) {
						$imp[ $p ] = 1; // Count once per placement per beacon.
					}
					break;
				case 'click':
					$p = isset( $e['p'] ) ? SPR_Tracking::valid_placement( $e['p'] ) : '';
					if ( $p ) {
						$clk[ $p ] = 1;
					}
					break;
			}
		}
		foreach ( array_keys( $imp ) as $p ) {
			SPR_Tracking::bump( $p, 'impressions' );
		}
		foreach ( array_keys( $clk ) as $p ) {
			SPR_Tracking::bump( $p, 'clicks' );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Plain JSON for headless frontends / mobile apps.
	 */
	public static function json( WP_REST_Request $req ) {
		$source  = sanitize_key( $req['source'] );
		$allowed = array_merge( array_keys( SPR_Settings::sources() ), array( 'order' ) );
		if ( ! in_array( $source, $allowed, true ) ) {
			return new WP_Error( 'spr_bad_source', 'Unknown source', array( 'status' => 400 ) );
		}
		$ids = SPR_Engine::get(
			$source,
			array(
				'product_id' => absint( $req['product_id'] ),
				'items'      => array_slice( SPR_Engine::ids( (string) $req['items'] ), 0, 30 ),
				'viewed'     => array_slice( SPR_Engine::ids( (string) $req['viewed'] ), 0, 20 ),
				'limit'      => max( 1, min( 24, (int) $req['limit'] ) ),
			)
		);

		$items = array();
		foreach ( $ids as $id ) {
			$p = wc_get_product( $id );
			if ( ! $p ) {
				continue;
			}
			$img     = wp_get_attachment_image_src( $p->get_image_id(), 'woocommerce_thumbnail' );
			$items[] = array(
				'id'            => $id,
				'name'          => $p->get_name(),
				'permalink'     => $p->get_permalink(),
				'price'         => $p->get_price(),
				'regular_price' => $p->get_regular_price(),
				'price_html'    => $p->get_price_html(),
				'on_sale'       => $p->is_on_sale(),
				'in_stock'      => $p->is_in_stock(),
				'image'         => $img ? $img[0] : wc_placeholder_img_src(),
			);
		}
		return rest_ensure_response( array( 'source' => $source, 'items' => $items ) );
	}
}
