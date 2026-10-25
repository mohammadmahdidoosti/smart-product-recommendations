<?php
/**
 * WP-CLI commands.
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manage the product recommendation index.
 */
class SPR_CLI {

	/**
	 * Rebuild the whole index synchronously (recommended for large catalogs / first run).
	 *
	 * ## EXAMPLES
	 *
	 *     wp spr rebuild
	 */
	public function rebuild() {
		$start    = microtime( true );
		$progress = null;
		$last     = '';
		$done     = 0;

		$state = SPR_Indexer::run_sync(
			function ( $s ) use ( &$progress, &$last, &$done ) {
				if ( $s['phase'] !== $last ) {
					if ( $progress ) {
						$progress->finish();
						$progress = null;
					}
					$last = $s['phase'];
					if ( 'relations' === $s['phase'] ) {
						$progress = \WP_CLI\Utils\make_progress_bar( 'Computing recommendations', $s['total'] );
					} elseif ( $s['phase'] ) {
						WP_CLI::log( 'Phase: ' . $s['phase'] );
					}
				}
				if ( $progress && 'relations' === $s['phase'] ) {
					$progress->tick( max( 0, $s['processed'] - $done ) );
					$done = $s['processed'];
				}
			}
		);
		if ( $progress ) {
			$progress->finish();
		}
		WP_CLI::success(
			sprintf(
				'Done in %.1fs — %d products, %d stored relations.',
				microtime( true ) - $start,
				$state['total'],
				$state['relations']
			)
		);
	}

	/**
	 * Show index status.
	 */
	public function status() {
		$s = SPR_Indexer::state();
		foreach ( $s as $k => $v ) {
			WP_CLI::log( str_pad( $k, 10 ) . ': ' . ( is_scalar( $v ) ? $v : wp_json_encode( $v ) ) );
		}
	}

	/**
	 * Recompute and print recommendations for one product.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Product ID.
	 */
	public function product( $args ) {
		$id = absint( $args[0] );
		$s  = SPR_Indexer::state();
		SPR_Indexer::index_tokens( array( $id ) );
		SPR_Indexer::compute_relations( $id, (int) $s['n_docs'] );
		wp_cache_delete( "rel_{$id}_" . SPR_REL_SIMILAR, 'spr' );
		wp_cache_delete( "rel_{$id}_" . SPR_REL_BOUGHT, 'spr' );

		WP_CLI::log( '#' . $id . ' ' . get_the_title( $id ) );
		foreach ( array( 'similar' => SPR_REL_SIMILAR, 'bought' => SPR_REL_BOUGHT ) as $label => $type ) {
			$rows = array();
			foreach ( array_slice( SPR_Engine::relations( $id, $type ), 0, 15, true ) as $rid => $score ) {
				$rows[] = array(
					'id'    => $rid,
					'score' => round( $score, 3 ),
					'title' => get_the_title( $rid ),
				);
			}
			WP_CLI::log( "\n" . strtoupper( $label ) );
			if ( $rows ) {
				\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'score', 'title' ) );
			} else {
				WP_CLI::log( '(none)' );
			}
		}
	}
}
