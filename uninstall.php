<?php
/**
 * Remove all plugin data on uninstall.
 *
 * @package SPR
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'relations', 'tokens', 'token_df', 'views', 'stats' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}spr_{$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

foreach ( array( 'spr_settings', 'spr_cache_ver', 'spr_db_version', 'spr_index_state', 'spr_needs_initial_build', 'spr_algo_changed' ) as $opt ) {
	delete_option( $opt );
}

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_spr\\_%' OR option_name LIKE '\\_transient\\_timeout\\_spr\\_%'" );

wp_clear_scheduled_hook( 'spr_full_rebuild' );
wp_clear_scheduled_hook( 'spr_rebuild_batch' );
