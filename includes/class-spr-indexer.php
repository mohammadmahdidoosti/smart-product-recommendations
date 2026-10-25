<?php
/**
 * Background indexer: builds the title token index and pre-computes
 * "similar" and "bought together" relations for every product.
 *
 * Pipeline (resumable, cursor based, safe for 10k–100k+ products):
 *   1. tokens    — tokenize product titles into {prefix}spr_tokens
 *   2. df        — document frequency per token into {prefix}spr_token_df
 *   3. relations — per product: content similarity + co-purchase into {prefix}spr_relations
 *
 * Runs on Action Scheduler (bundled with WooCommerce) when available, otherwise WP-Cron.
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

class SPR_Indexer {

	const CRON_FULL   = 'spr_full_rebuild';
	const CRON_BATCH  = 'spr_rebuild_batch';
	const HOOK_SINGLE = 'spr_index_product';
	const STATE       = 'spr_index_state';
	const LOCK        = 'spr_index_lock';

	/** Seconds of work per background run. */
	const RUN_SECONDS = 20;

	/** Candidates fetched per signal before merging. */
	const CANDIDATES = 150;

	/** @var array|null Per-request cache of taxonomy weights. */
	private static $tax_weights = null;

	/** @var bool|null */
	private static $has_lookup = null;

	public static function init() {
		add_action( self::CRON_FULL, array( __CLASS__, 'start_full' ) );
		add_action( self::CRON_BATCH, array( __CLASS__, 'run_background' ) );
		add_action( self::HOOK_SINGLE, array( __CLASS__, 'index_single' ) );

		add_action( 'woocommerce_new_product', array( __CLASS__, 'queue_single' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue_single' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_delete' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'on_delete' ) );

		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 20 );
	}

	/* ------------------------------------------------------------------ */
	/* Scheduling                                                         */
	/* ------------------------------------------------------------------ */

	public static function ensure_schedule() {
		if ( get_option( 'spr_needs_initial_build' ) ) {
			delete_option( 'spr_needs_initial_build' );
			self::start_full();
		}

		$schedule = SPR_Settings::get( 'schedule', 'daily' );
		$next     = wp_next_scheduled( self::CRON_FULL );

		if ( 'manual' === $schedule ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::CRON_FULL );
			}
			return;
		}

		$recurrence = 'weekly' === $schedule ? 'weekly' : 'daily';
		$current    = $next ? wp_get_schedule( self::CRON_FULL ) : false;
		if ( $next && $current === $recurrence ) {
			return;
		}
		wp_clear_scheduled_hook( self::CRON_FULL );

		// Run at ~03:00 site time, when traffic is lowest.
		$tz    = wp_timezone();
		$start = new DateTime( 'tomorrow 03:00', $tz );
		wp_schedule_event( $start->getTimestamp(), $recurrence, self::CRON_FULL );
	}

	private static function enqueue_batch() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! as_has_scheduled_action( self::CRON_BATCH, array(), 'spr' ) ) {
				as_enqueue_async_action( self::CRON_BATCH, array(), 'spr' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_BATCH ) ) {
			wp_schedule_single_event( time(), self::CRON_BATCH );
		}
	}

	public static function queue_single( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id || 'product' !== get_post_type( $product_id ) ) {
			return;
		}
		$args = array( $product_id );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK_SINGLE, $args, 'spr' ) ) {
				as_enqueue_async_action( self::HOOK_SINGLE, $args, 'spr' );
			}
		} elseif ( ! wp_next_scheduled( self::HOOK_SINGLE, $args ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK_SINGLE, $args );
		}
	}

	/* ------------------------------------------------------------------ */
	/* State                                                              */
	/* ------------------------------------------------------------------ */

	public static function state() {
		$s = get_option( self::STATE, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'status'    => 'idle',  // idle|running|done.
				'phase'     => '',
				'last_id'   => 0,
				'processed' => 0,
				'total'     => 0,
				'n_docs'    => 0,
				'started'   => 0,
				'finished'  => 0,
				'duration'  => 0,
				'relations' => 0,
				'message'   => '',
			)
		);
	}

	private static function save_state( $s ) {
		update_option( self::STATE, $s, false );
	}

	public static function count_products() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
	}

	/**
	 * Start (or restart) a full rebuild. Work happens in background batches.
	 */
	public static function start_full() {
		$n = self::count_products();
		self::save_state(
			array(
				'status'    => 'running',
				'phase'     => 'tokens',
				'last_id'   => 0,
				'processed' => 0,
				'total'     => $n,
				'n_docs'    => $n,
				'started'   => time(),
				'finished'  => 0,
				'duration'  => 0,
				'relations' => 0,
				'message'   => '',
			)
		);
		delete_transient( self::LOCK );
		self::enqueue_batch();
	}

	public static function cancel() {
		$s           = self::state();
		$s['status'] = 'idle';
		$s['phase']  = '';
		self::save_state( $s );
		delete_transient( self::LOCK );
	}

	/**
	 * Background entry point: work for RUN_SECONDS, then re-queue if not finished.
	 */
	public static function run_background() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		$deadline = microtime( true ) + self::RUN_SECONDS;
		do {
			$more = self::step();
		} while ( $more && microtime( true ) < $deadline );

		delete_transient( self::LOCK );

		if ( $more ) {
			self::enqueue_batch();
		}
	}

	/**
	 * Run one batch of the current phase.
	 *
	 * @return bool True if there is more work.
	 */
	public static function step() {
		global $wpdb;
		$s = self::state();
		if ( 'running' !== $s['status'] ) {
			return false;
		}

		$batch = max( 10, (int) SPR_Settings::get( 'batch_size', 100 ) );

		switch ( $s['phase'] ) {
			case 'tokens':
				$ids = self::next_ids( $s['last_id'], $batch * 5 );
				if ( ! $ids ) {
					$s['phase']   = 'df';
					$s['last_id'] = 0;
					break;
				}
				$wpdb->query( 'START TRANSACTION' );
				self::index_tokens( $ids );
				$wpdb->query( 'COMMIT' );
				$s['last_id'] = end( $ids );
				break;

			case 'df':
				self::build_df();
				$s['phase']     = 'relations';
				$s['last_id']   = 0;
				$s['processed'] = 0;
				$s['n_docs']    = self::count_products();
				$s['total']     = $s['n_docs'];
				break;

			case 'relations':
				$ids = self::next_ids( $s['last_id'], $batch );
				if ( ! $ids ) {
					self::cleanup_orphans();
					$s['status']    = 'done';
					$s['phase']     = '';
					$s['finished']  = time();
					$s['duration']  = $s['finished'] - $s['started'];
					$s['relations'] = self::count_relations();
					self::bump_cache();
					self::save_state( $s );
					do_action( 'spr_rebuild_finished', $s );
					return false;
				}
				// One transaction per batch: far fewer disk flushes than autocommit per row set.
				$wpdb->query( 'START TRANSACTION' );
				foreach ( $ids as $id ) {
					self::compute_relations( $id, (int) $s['n_docs'] );
				}
				$wpdb->query( 'COMMIT' );
				$s['last_id']    = end( $ids );
				$s['processed'] += count( $ids );
				break;

			default:
				return false;
		}

		self::save_state( $s );
		return true;
	}

	/**
	 * Run the whole pipeline synchronously (WP-CLI).
	 *
	 * @param callable|null $tick Progress callback ( $state ).
	 */
	public static function run_sync( $tick = null ) {
		self::start_full();
		// Remove the queued async batch: we are doing the work right here.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_BATCH, array(), 'spr' );
		}
		wp_clear_scheduled_hook( self::CRON_BATCH );

		while ( self::step() ) {
			if ( $tick ) {
				call_user_func( $tick, self::state() );
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}
		return self::state();
	}

	private static function next_ids( $after, $limit ) {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
					$after,
					$limit
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Phase 1/2: title tokens                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * @param int[] $ids Product IDs.
	 */
	public static function index_tokens( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return;
		}
		$table = SPR_Install::table( 'tokens' );
		$in    = implode( ',', $ids );

		$rows = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->query( "DELETE FROM {$table} WHERE product_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$values = array();
		$params = array();
		foreach ( $rows as $r ) {
			$title  = apply_filters( 'spr_index_text', $r->post_title, (int) $r->ID );
			foreach ( SPR_Tokenizer::tokens( $title ) as $tok ) {
				$values[] = '(%s,%d)';
				$params[] = $tok;
				$params[] = (int) $r->ID;
			}
		}

		foreach ( array_chunk( $values, 1000, true ) as $i => $chunk ) {
			$offset = array_key_first( $chunk ) * 2;
			$p      = array_slice( $params, $offset, count( $chunk ) * 2 );
			$sql    = "INSERT IGNORE INTO {$table} (token, product_id) VALUES " . implode( ',', $chunk );
			$wpdb->query( $wpdb->prepare( $sql, $p ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	public static function build_df() {
		global $wpdb;
		$tok = SPR_Install::table( 'tokens' );
		$df  = SPR_Install::table( 'token_df' );

		// Drop tokens of products that are no longer published.
		$wpdb->query(
			"DELETE t FROM {$tok} t LEFT JOIN {$wpdb->posts} p ON p.ID = t.product_id AND p.post_type = 'product' AND p.post_status = 'publish' WHERE p.ID IS NULL"
		);
		$wpdb->query( "TRUNCATE TABLE {$df}" );
		$wpdb->query( "INSERT INTO {$df} (token, df) SELECT token, COUNT(*) FROM {$tok} GROUP BY token" );
	}

	/* ------------------------------------------------------------------ */
	/* Phase 3: relations                                                 */
	/* ------------------------------------------------------------------ */

	private static function tax_weights() {
		if ( null === self::$tax_weights ) {
			$w = array(
				'product_cat' => (float) SPR_Settings::get( 'w_cat' ),
				'product_tag' => (float) SPR_Settings::get( 'w_tag' ),
			);
			foreach ( SPR_Settings::brand_taxonomies() as $t ) {
				$w[ $t ] = (float) SPR_Settings::get( 'w_brand' );
			}
			foreach ( SPR_Settings::attribute_taxonomies() as $t ) {
				$w[ $t ] = (float) SPR_Settings::get( 'w_attr' );
			}
			$w                 = apply_filters( 'spr_taxonomy_weights', $w );
			self::$tax_weights = array_filter( $w, function ( $v ) {
				return $v > 0;
			} );
		}
		return self::$tax_weights;
	}

	/**
	 * SQL fragments for weighted taxonomy matching.
	 *
	 * @return array{0:string,1:string}|null [ CASE expr, IN list ]
	 */
	private static function tax_sql() {
		global $wpdb;
		$w = self::tax_weights();
		if ( ! $w ) {
			return null;
		}
		$case = 'CASE tt.taxonomy';
		$in   = array();
		foreach ( $w as $tax => $weight ) {
			$case .= $wpdb->prepare( ' WHEN %s THEN %f', $tax, $weight );
			$in[]  = $wpdb->prepare( '%s', $tax );
		}
		$case .= ' ELSE 0 END';
		return array( $case, implode( ',', $in ) );
	}

	/**
	 * Weighted taxonomy overlap scores.
	 *
	 * @param int        $id      Product.
	 * @param int        $n       Total products.
	 * @param int        $max_cnt Ignore terms used by more products than this.
	 * @param int[]|null $only    Restrict to these candidate IDs (exact scoring), or null for top-N discovery.
	 * @return array<int,float>
	 */
	private static function tax_scores( $id, $n, $max_cnt, $only = null ) {
		global $wpdb;
		$sql = self::tax_sql();
		if ( ! $sql || ( is_array( $only ) && ! $only ) ) {
			return array();
		}
		list( $case, $in ) = $sql;

		$restrict = '';
		$limit    = '';
		if ( is_array( $only ) ) {
			$restrict = ' AND tr2.object_id IN (' . implode( ',', array_map( 'absint', $only ) ) . ')';
		} else {
			$limit = ' ORDER BY score DESC LIMIT ' . self::CANDIDATES;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tr2.object_id AS pid, SUM( ({$case}) * LN( %d / tt.count ) ) AS score
				FROM {$wpdb->term_relationships} tr1
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr1.term_taxonomy_id
				INNER JOIN {$wpdb->term_relationships} tr2 ON tr2.term_taxonomy_id = tr1.term_taxonomy_id AND tr2.object_id <> tr1.object_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr2.object_id AND p.post_type = 'product' AND p.post_status = 'publish'
				WHERE tr1.object_id = %d AND tt.taxonomy IN ({$in}) AND tt.count > 1 AND tt.count <= %d {$restrict}
				GROUP BY tr2.object_id{$limit}",
				$n,
				$id,
				$max_cnt
			)
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->pid ] = (float) $r->score;
		}
		return $out;
	}

	private static function tax_self( $id, $n, $max_cnt ) {
		global $wpdb;
		$sql = self::tax_sql();
		if ( ! $sql ) {
			return 0.0;
		}
		list( $case, $in ) = $sql;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM( ({$case}) * LN( %d / tt.count ) )
				FROM {$wpdb->term_relationships} tr1
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr1.term_taxonomy_id
				WHERE tr1.object_id = %d AND tt.taxonomy IN ({$in}) AND tt.count > 1 AND tt.count <= %d",
				$n,
				$id,
				$max_cnt
			)
		);
		// phpcs:enable
	}

	/**
	 * Title token overlap (IDF-weighted).
	 *
	 * @return array<int,float>
	 */
	private static function title_scores( $id, $n, $max_df, $only = null ) {
		global $wpdb;
		if ( is_array( $only ) && ! $only ) {
			return array();
		}
		$tok = SPR_Install::table( 'tokens' );
		$df  = SPR_Install::table( 'token_df' );

		$restrict = '';
		$limit    = '';
		if ( is_array( $only ) ) {
			$restrict = ' AND t2.product_id IN (' . implode( ',', array_map( 'absint', $only ) ) . ')';
		} else {
			$limit = ' ORDER BY score DESC LIMIT ' . self::CANDIDATES;
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t2.product_id AS pid, SUM( LN( %d / d.df ) ) AS score
				FROM {$tok} t1
				INNER JOIN {$df} d ON d.token = t1.token
				INNER JOIN {$tok} t2 ON t2.token = t1.token AND t2.product_id <> t1.product_id
				WHERE t1.product_id = %d AND d.df > 1 AND d.df <= %d {$restrict}
				GROUP BY t2.product_id{$limit}",
				$n,
				$id,
				$max_df
			)
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->pid ] = (float) $r->score;
		}
		return $out;
	}

	/**
	 * Candidate IDs sharing at least one term/word used by at most $max_cnt products.
	 *
	 * @return int[]
	 */
	private static function discover( $id, $n, $max_cnt, $w_title ) {
		$c = array_keys( self::tax_scores( $id, $n, $max_cnt ) );
		if ( $w_title > 0 ) {
			$c = array_merge( $c, array_keys( self::title_scores( $id, $n, $max_cnt ) ) );
		}
		return array_values( array_unique( $c ) );
	}

	private static function title_self( $id, $n, $max_df ) {
		global $wpdb;
		$tok = SPR_Install::table( 'tokens' );
		$df  = SPR_Install::table( 'token_df' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM( LN( %d / d.df ) ) FROM {$tok} t1 INNER JOIN {$df} d ON d.token = t1.token
				WHERE t1.product_id = %d AND d.df > 1 AND d.df <= %d",
				$n,
				$id,
				$max_df
			)
		);
		// phpcs:enable
	}

	/**
	 * Prices for a set of products (uses WooCommerce's indexed meta lookup table).
	 *
	 * @param int[] $ids IDs.
	 * @return array<int,float>
	 */
	private static function prices( array $ids ) {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$in     = implode( ',', array_map( 'absint', $ids ) );
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		$out    = array();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT product_id AS pid, min_price AS price FROM {$lookup} WHERE product_id IN ({$in})" );
		if ( $wpdb->last_error || ! $rows ) {
			$rows = $wpdb->get_results( "SELECT post_id AS pid, MIN(CAST(meta_value AS DECIMAL(19,4))) AS price FROM {$wpdb->postmeta} WHERE meta_key = '_price' AND meta_value <> '' AND post_id IN ({$in}) GROUP BY post_id" );
		}
		// phpcs:enable
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->pid ] = (float) $r->price;
		}
		return $out;
	}

	/**
	 * Compute and store relations for one product.
	 *
	 * @param int $id Product ID.
	 * @param int $n  Number of published products.
	 */
	public static function compute_relations( $id, $n = 0 ) {
		$id = absint( $id );
		$n  = $n > 0 ? $n : self::count_products();
		if ( ! $id || $n < 2 ) {
			return;
		}

		$share   = max( 1, min( 100, (float) SPR_Settings::get( 'max_term_share', 20 ) ) ) / 100;
		$max_cnt = max( 2, (int) floor( $n * $share ) );
		$top     = max( 4, (int) SPR_Settings::get( 'store_top', 30 ) );
		$w_title = (float) SPR_Settings::get( 'w_title', 2 );
		$w_price = max( 0, min( 1, (float) SPR_Settings::get( 'w_price', 0.15 ) ) );

		// --- Content similarity -------------------------------------------------
		// Stage 1 — discovery through *rare* terms only (small leaf categories, brands,
		// specific title words). Joining through huge generic terms (a parent category,
		// a colour) is what makes naive item-to-item similarity slow on big catalogs.
		$rare  = max( 50, min( $max_cnt, (int) floor( $n * 0.02 ) ) );
		$cands = self::discover( $id, $n, $rare, $w_title );
		if ( count( $cands ) < 2 * $top && $rare < $max_cnt ) {
			$cands = self::discover( $id, $n, $max_cnt, $w_title );
		}

		// Stage 2 — exact score of every candidate with all terms (cheap: restricted by ID).
		$tax   = self::tax_scores( $id, $n, $max_cnt, $cands );
		$title = $w_title > 0 ? self::title_scores( $id, $n, $max_cnt, $cands ) : array();

		$self_max = self::tax_self( $id, $n, $max_cnt ) + ( $w_title > 0 ? $w_title * self::title_self( $id, $n, $max_cnt ) : 0 );

		$similar = array();
		if ( $self_max > 0 ) {
			$cands  = array_unique( array_merge( array_keys( $tax ), array_keys( $title ) ) );
			$prices = $w_price > 0 ? self::prices( array_merge( array( $id ), $cands ) ) : array();
			$p0     = isset( $prices[ $id ] ) ? $prices[ $id ] : 0;

			foreach ( $cands as $c ) {
				$raw     = ( isset( $tax[ $c ] ) ? $tax[ $c ] : 0 ) + $w_title * ( isset( $title[ $c ] ) ? $title[ $c ] : 0 );
				$content = min( 1, $raw / $self_max );
				$score   = $content;
				if ( $w_price > 0 ) {
					$p1 = isset( $prices[ $c ] ) ? $prices[ $c ] : 0;
					if ( $p0 > 0 && $p1 > 0 ) {
						$price_sim = 1 - min( 1, abs( log( $p1 / $p0 ) ) / log( 4 ) );
					} else {
						$price_sim = 0.5;
					}
					$score = ( 1 - $w_price ) * $content + $w_price * $price_sim;
				}
				$similar[ $c ] = $score;
			}
			arsort( $similar );
			$similar = array_slice( $similar, 0, $top, true );
		}
		$similar = apply_filters( 'spr_similar_scores', $similar, $id );

		// --- Co-purchase ---------------------------------------------------------
		$bought = apply_filters( 'spr_bought_scores', self::co_purchase( $id, $top ), $id );

		self::write_relations( $id, SPR_REL_SIMILAR, $similar );
		self::write_relations( $id, SPR_REL_BOUGHT, $bought );
	}

	public static function has_order_lookup() {
		global $wpdb;
		if ( null === self::$has_lookup ) {
			$t                = $wpdb->prefix . 'wc_order_product_lookup';
			$s                = $wpdb->prefix . 'wc_order_stats';
			self::$has_lookup = ( $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) )
				&& ( $s === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $s ) ) );
		}
		return self::$has_lookup;
	}

	/**
	 * "Customers who bought X also bought" from WooCommerce analytics lookup tables
	 * (works with both HPOS and legacy order storage).
	 *
	 * @return array<int,float>
	 */
	public static function co_purchase( $id, $top ) {
		global $wpdb;
		if ( ! self::has_order_lookup() ) {
			return array();
		}
		$lookup  = $wpdb->prefix . 'wc_order_product_lookup';
		$stats   = $wpdb->prefix . 'wc_order_stats';
		$days    = max( 7, (int) SPR_Settings::get( 'lookback_days', 365 ) );
		$min     = max( 1, (int) SPR_Settings::get( 'min_support', 2 ) );
		$since   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$status  = apply_filters( 'spr_order_statuses', array( 'wc-completed', 'wc-processing', 'wc-on-hold' ) );
		$st_in   = implode( ',', array_map( function ( $s ) use ( $wpdb ) {
			return $wpdb->prepare( '%s', $s );
		}, $status ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.product_id AS pid, COUNT(DISTINCT b.order_id) AS cnt
				FROM {$lookup} a
				INNER JOIN {$stats} s ON s.order_id = a.order_id AND s.status IN ({$st_in})
				INNER JOIN {$lookup} b ON b.order_id = a.order_id AND b.product_id <> a.product_id
				INNER JOIN {$wpdb->posts} p ON p.ID = b.product_id AND p.post_type = 'product' AND p.post_status = 'publish'
				WHERE a.product_id = %d AND a.date_created >= %s
				GROUP BY b.product_id
				HAVING cnt >= %d
				ORDER BY cnt DESC
				LIMIT %d",
				$id,
				$since,
				$min,
				$top
			)
		);
		// phpcs:enable

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r->pid ] = (float) $r->cnt;
		}
		return $out;
	}

	private static function write_relations( $id, $type, array $scores ) {
		global $wpdb;
		$table = SPR_Install::table( 'relations' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE product_id = %d AND type = %d", $id, $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $scores ) {
			return;
		}
		$values = array();
		foreach ( $scores as $rid => $score ) {
			$values[] = $wpdb->prepare( '(%d,%d,%d,%f)', $id, $type, $rid, $score );
		}
		$wpdb->query( "INSERT IGNORE INTO {$table} (product_id, type, related_id, score) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		wp_cache_delete( "rel_{$id}_{$type}", 'spr' );
	}

	public static function cleanup_orphans() {
		global $wpdb;
		$rel = SPR_Install::table( 'relations' );
		$wpdb->query(
			"DELETE r FROM {$rel} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.product_id AND p.post_type = 'product' AND p.post_status = 'publish' WHERE p.ID IS NULL"
		);
		$wpdb->query(
			"DELETE r FROM {$rel} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.related_id AND p.post_type = 'product' AND p.post_status = 'publish' WHERE p.ID IS NULL"
		);
		// Keep 400 days of view counters.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . SPR_Install::table( 'views' ) . ' WHERE day < %s', gmdate( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function count_relations() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SPR_Install::table( 'relations' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/* ------------------------------------------------------------------ */
	/* Incremental updates                                                */
	/* ------------------------------------------------------------------ */

	public static function index_single( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return;
		}
		if ( 'publish' !== get_post_status( $product_id ) ) {
			self::on_delete( $product_id );
			return;
		}
		self::index_tokens( array( $product_id ) );
		$s = self::state();
		// No global cache bump here: stock updates after every sale also land here, and
		// flushing all cached blocks per sale would defeat the cache on a busy store.
		self::compute_relations( $product_id, $s['n_docs'] ? (int) $s['n_docs'] : 0 );
	}

	public static function on_delete( $post_id ) {
		global $wpdb;
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		$post_id = absint( $post_id );
		$rel     = SPR_Install::table( 'relations' );
		$tok     = SPR_Install::table( 'tokens' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$rel} WHERE product_id = %d OR related_id = %d", $post_id, $post_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$tok} WHERE product_id = %d", $post_id ) );
		// phpcs:enable
		self::bump_cache();
	}

	/**
	 * Invalidate rendered-HTML caches (cache keys include this version).
	 */
	public static function bump_cache() {
		update_option( 'spr_cache_ver', (int) get_option( 'spr_cache_ver', 1 ) + 1, true );
	}
}
