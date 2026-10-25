<?php
/**
 * Recommendation engine: turns pre-computed relations + context into product IDs.
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

class SPR_Engine {

	/** @var array|null */
	private static $hidden_tt = null;

	/**
	 * Get recommended product IDs.
	 *
	 * @param string $source similar|bought|viewed|for_you|cart|order|trending.
	 * @param array  $args   product_id, viewed[], items[], limit, exclude[].
	 * @return int[]
	 */
	public static function get( $source, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'product_id' => 0,
				'viewed'     => array(),
				'items'      => array(),
				'limit'      => 8,
				'exclude'    => array(),
			)
		);

		$limit   = max( 1, min( 24, (int) $args['limit'] ) );
		$pid     = absint( $args['product_id'] );
		$viewed  = self::ids( $args['viewed'] );
		$items   = self::ids( $args['items'] );
		$exclude = self::ids( $args['exclude'] );
		if ( $pid ) {
			$exclude[] = $pid;
		}

		switch ( $source ) {
			case 'similar':
				$ids = $pid ? array_keys( self::relations( $pid, SPR_REL_SIMILAR ) ) : array();
				break;

			case 'bought':
				$ids = $pid ? array_keys( self::relations( $pid, SPR_REL_BOUGHT ) ) : array();
				break;

			case 'viewed':
				$ids = $viewed;
				break;

			case 'for_you':
				$exclude = array_merge( $exclude, $viewed );
				$ids     = self::aggregate( $viewed, true );
				if ( count( $ids ) < $limit ) {
					$ids = array_merge( $ids, self::trending( 60 ) );
				}
				break;

			case 'cart':
			case 'order':
				$exclude = array_merge( $exclude, $items );
				$ids     = self::aggregate( $items, false );
				if ( count( $ids ) < $limit ) {
					$ids = array_merge( $ids, self::aggregate( $viewed, true ) );
				}
				if ( count( $ids ) < $limit ) {
					$ids = array_merge( $ids, self::trending( 60 ) );
				}
				break;

			case 'trending':
				$ids = self::trending( 60 );
				break;

			default:
				$ids = array();
		}

		$ids = apply_filters( 'spr_candidates', $ids, $source, $args );
		$ids = array_values( array_diff( array_unique( array_map( 'intval', $ids ) ), $exclude ) );
		$ids = self::filter_visible( array_slice( $ids, 0, $limit * 4 ) );

		return apply_filters( 'spr_recommendations', array_slice( $ids, 0, $limit ), $source, $args );
	}

	/**
	 * Pre-computed relations for a product, best first.
	 *
	 * @return array<int,float>
	 */
	public static function relations( $product_id, $type ) {
		global $wpdb;
		$key  = "rel_{$product_id}_{$type}";
		$rels = wp_cache_get( $key, 'spr' );
		if ( false === $rels ) {
			$table = SPR_Install::table( 'relations' );
			$rows  = $wpdb->get_results(
				$wpdb->prepare( "SELECT related_id, score FROM {$table} WHERE product_id = %d AND type = %d ORDER BY score DESC", $product_id, $type ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$rels = array();
			foreach ( (array) $rows as $r ) {
				$rels[ (int) $r->related_id ] = (float) $r->score;
			}
			wp_cache_set( $key, $rels, 'spr', HOUR_IN_SECONDS );
		}
		return $rels;
	}

	/**
	 * Merge relations of several seed products into one ranked list.
	 *
	 * @param int[] $seeds          Seed products (most recent / most important first).
	 * @param bool  $recency_weight Decay weight by position.
	 * @return int[]
	 */
	private static function aggregate( array $seeds, $recency_weight ) {
		$seeds = array_slice( $seeds, 0, 10 );
		$total = array();
		foreach ( $seeds as $i => $seed ) {
			$w = $recency_weight ? 1 / ( 1 + $i * 0.5 ) : 1;

			// Co-purchase is a stronger buying signal than similarity: normalize and boost it.
			$bought = self::relations( $seed, SPR_REL_BOUGHT );
			$max    = $bought ? max( $bought ) : 1;
			foreach ( $bought as $rid => $s ) {
				$total[ $rid ] = ( isset( $total[ $rid ] ) ? $total[ $rid ] : 0 ) + $w * 1.5 * ( $s / $max );
			}
			foreach ( self::relations( $seed, SPR_REL_SIMILAR ) as $rid => $s ) {
				$total[ $rid ] = ( isset( $total[ $rid ] ) ? $total[ $rid ] : 0 ) + $w * $s;
			}
		}
		arsort( $total );
		return array_keys( $total );
	}

	/**
	 * Most viewed products of the last 7 days, falling back to best sellers.
	 *
	 * @return int[]
	 */
	public static function trending( $limit = 60 ) {
		global $wpdb;
		$ids = get_transient( 'spr_trending' );
		if ( false === $ids ) {
			$views = SPR_Install::table( 'views' );
			$ids   = array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT product_id FROM {$views} WHERE day >= %s GROUP BY product_id ORDER BY SUM(views) DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS ),
						100
					)
				)
			);
			if ( count( $ids ) < 20 ) {
				$best = $wpdb->get_col(
					"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'total_sales'
					WHERE p.post_type = 'product' AND p.post_status = 'publish'
					ORDER BY CAST(m.meta_value AS UNSIGNED) DESC LIMIT 100"
				);
				$ids  = array_values( array_unique( array_merge( $ids, array_map( 'intval', $best ) ) ) );
			}
			if ( count( $ids ) < 20 ) {
				// Brand-new store without sales or views yet: newest products.
				$new = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY post_date DESC LIMIT 100" );
				$ids = array_values( array_unique( array_merge( $ids, array_map( 'intval', $new ) ) ) );
			}
			set_transient( 'spr_trending', $ids, HOUR_IN_SECONDS );
		}
		return array_slice( $ids, 0, $limit );
	}

	/**
	 * Keep only published, catalog-visible, (optionally) in-stock, non-excluded products — order preserved.
	 *
	 * @param int[] $ids Candidates.
	 * @return int[]
	 */
	public static function filter_visible( array $ids ) {
		global $wpdb;
		$ids = self::ids( $ids );
		if ( ! $ids ) {
			return array();
		}
		$in     = implode( ',', $ids );
		$hidden = self::hidden_term_taxonomy_ids();

		$not = '';
		if ( $hidden ) {
			$not = " AND ID NOT IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN (" . implode( ',', $hidden ) . ") AND object_id IN ({$in}) )";
		}

		$ok = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ({$in}) AND post_type = 'product' AND post_status = 'publish' AND post_password = ''{$not}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = array_flip( array_map( 'intval', $ok ) );

		$manual = self::ids( wp_parse_id_list( SPR_Settings::get( 'exclude_ids', '' ) ) );
		foreach ( $manual as $m ) {
			unset( $ok[ $m ] );
		}

		return array_values(
			array_filter(
				$ids,
				function ( $id ) use ( $ok ) {
					return isset( $ok[ $id ] );
				}
			)
		);
	}

	/**
	 * term_taxonomy_ids whose products must not be recommended:
	 * hidden from catalog, out of stock (optional), excluded categories (incl. children).
	 *
	 * @return int[]
	 */
	private static function hidden_term_taxonomy_ids() {
		if ( null !== self::$hidden_tt ) {
			return self::$hidden_tt;
		}
		$tt = array();

		$vis = array( 'exclude-from-catalog' );
		if ( SPR_Settings::get( 'hide_out_of_stock' ) ) {
			$vis[] = 'outofstock';
		}
		foreach ( $vis as $slug ) {
			$t = get_term_by( 'slug', $slug, 'product_visibility' );
			if ( $t && ! is_wp_error( $t ) ) {
				$tt[] = (int) $t->term_taxonomy_id;
			}
		}

		foreach ( wp_parse_id_list( SPR_Settings::get( 'exclude_cats', array() ) ) as $cat_id ) {
			$t = get_term( $cat_id, 'product_cat' );
			if ( $t && ! is_wp_error( $t ) ) {
				$tt[] = (int) $t->term_taxonomy_id;
				foreach ( (array) get_term_children( $cat_id, 'product_cat' ) as $child ) {
					$c = get_term( $child, 'product_cat' );
					if ( $c && ! is_wp_error( $c ) ) {
						$tt[] = (int) $c->term_taxonomy_id;
					}
				}
			}
		}

		self::$hidden_tt = array_values( array_unique( $tt ) );
		return self::$hidden_tt;
	}

	/**
	 * Sanitize a list of IDs (array or comma string), keep order, drop zeros/dupes.
	 *
	 * @param mixed $list List.
	 * @return int[]
	 */
	public static function ids( $list ) {
		if ( is_string( $list ) ) {
			$list = explode( ',', $list );
		}
		if ( ! is_array( $list ) ) {
			return array();
		}
		$out = array();
		foreach ( $list as $v ) {
			$v = absint( $v );
			if ( $v ) {
				$out[ $v ] = $v;
			}
		}
		return array_values( $out );
	}
}
