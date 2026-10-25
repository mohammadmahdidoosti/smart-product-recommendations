<?php
/**
 * Views, impressions, clicks and conversion attribution.
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

class SPR_Tracking {

	const COOKIE_ATTR   = 'spr_attr';
	const COOKIE_VIEWED = 'spr_viewed';

	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'attribute_order_id' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'attribute_order' ), 20, 1 );
	}

	public static function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|curl|wget|python|scrapy/', $ua );
	}

	public static function valid_placement( $placement ) {
		$placement = sanitize_key( $placement );
		if ( strlen( $placement ) > 32 ) {
			return '';
		}
		if ( array_key_exists( $placement, SPR_Settings::placements() ) ) {
			return $placement;
		}
		if ( preg_match( '/^(sc|widget)_(similar|bought|viewed|for_you|trending|cart)$/', $placement ) ) {
			return $placement;
		}
		return '';
	}

	private static function today() {
		return wp_date( 'Y-m-d' );
	}

	public static function record_view( $product_id ) {
		global $wpdb;
		$product_id = absint( $product_id );
		if ( ! $product_id || self::is_bot() ) {
			return;
		}
		$table = SPR_Install::table( 'views' );
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (product_id, day, views) VALUES (%d, %s, 1) ON DUPLICATE KEY UPDATE views = views + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$product_id,
				self::today()
			)
		);
	}

	/**
	 * Increment a counter.
	 *
	 * @param string $placement Placement key.
	 * @param string $field     impressions|clicks|orders.
	 * @param int    $n         Amount.
	 * @param float  $revenue   Revenue to add.
	 */
	public static function bump( $placement, $field, $n = 1, $revenue = 0.0 ) {
		global $wpdb;
		$placement = self::valid_placement( $placement );
		if ( ! $placement || ! in_array( $field, array( 'impressions', 'clicks', 'orders' ), true ) ) {
			return;
		}
		$table = SPR_Install::table( 'stats' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (day, placement, {$field}, revenue) VALUES (%s, %s, %d, %f)
				ON DUPLICATE KEY UPDATE {$field} = {$field} + VALUES({$field}), revenue = revenue + VALUES(revenue)",
				self::today(),
				$placement,
				max( 1, (int) $n ),
				(float) $revenue
			)
		);
		// phpcs:enable
	}

	/**
	 * Parse the attribution cookie: "pid:placement|pid:placement".
	 *
	 * @return array<int,string>
	 */
	public static function attribution_map() {
		if ( empty( $_COOKIE[ self::COOKIE_ATTR ] ) ) {
			return array();
		}
		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_ATTR ] ) );
		$out = array();
		foreach ( explode( '|', $raw ) as $pair ) {
			$bits = explode( ':', $pair );
			if ( 2 === count( $bits ) ) {
				$pid       = absint( $bits[0] );
				$placement = self::valid_placement( $bits[1] );
				if ( $pid && $placement ) {
					$out[ $pid ] = $placement;
				}
			}
		}
		return $out;
	}

	public static function attribute_order_id( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::attribute_order( $order );
		}
	}

	/**
	 * Credit orders/revenue to the placements whose recommendations were clicked before purchase.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function attribute_order( $order ) {
		if ( ! $order instanceof WC_Order || $order->get_meta( '_spr_attributed' ) ) {
			return;
		}
		$map = self::attribution_map();
		if ( ! $map ) {
			return;
		}

		$per = array();
		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( isset( $map[ $pid ] ) ) {
				$pl = $map[ $pid ];
				if ( ! isset( $per[ $pl ] ) ) {
					$per[ $pl ] = 0.0;
				}
				$per[ $pl ] += (float) $item->get_total();
				unset( $map[ $pid ] );
			}
		}
		if ( ! $per ) {
			return;
		}

		foreach ( $per as $placement => $revenue ) {
			self::bump( $placement, 'orders', 1, $revenue );
		}
		$order->update_meta_data( '_spr_attributed', wp_json_encode( $per ) );
		$order->save_meta_data();

		// Drop attributed products from the cookie so they are not credited twice.
		if ( ! headers_sent() ) {
			$rest = array();
			foreach ( $map as $pid => $pl ) {
				$rest[] = $pid . ':' . $pl;
			}
			setcookie( self::COOKIE_ATTR, implode( '|', $rest ), $rest ? time() + 30 * DAY_IN_SECONDS : time() - 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), false );
		}
	}

	/**
	 * Recently viewed product IDs from the cookie (newest first).
	 *
	 * @return int[]
	 */
	public static function viewed_from_cookie() {
		if ( empty( $_COOKIE[ self::COOKIE_VIEWED ] ) ) {
			return array();
		}
		return array_slice( SPR_Engine::ids( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_VIEWED ] ) ) ), 0, 20 );
	}

	/**
	 * Aggregated stats.
	 *
	 * @param int $days Days back.
	 * @return array{rows:array,daily:array}
	 */
	public static function report( $days = 30 ) {
		global $wpdb;
		$table = SPR_Install::table( 'stats' );
		$since = wp_date( 'Y-m-d', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT placement, SUM(impressions) AS impressions, SUM(clicks) AS clicks, SUM(orders) AS orders, SUM(revenue) AS revenue
				FROM {$table} WHERE day >= %s GROUP BY placement ORDER BY revenue DESC, clicks DESC",
				$since
			),
			ARRAY_A
		);
		$daily = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, SUM(impressions) AS impressions, SUM(clicks) AS clicks, SUM(orders) AS orders, SUM(revenue) AS revenue
				FROM {$table} WHERE day >= %s GROUP BY day ORDER BY day ASC",
				$since
			),
			ARRAY_A
		);
		// phpcs:enable
		return array(
			'rows'  => (array) $rows,
			'daily' => (array) $daily,
		);
	}
}
