<?php
/**
 * Database tables, activation and upgrades.
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

class SPR_Install {

	/**
	 * Table name helper.
	 *
	 * @param string $name Short name (relations, tokens, token_df, views, stats).
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'spr_' . $name;
	}

	public static function activate() {
		self::create_tables();
		add_option( 'spr_settings', SPR_Settings::defaults() );
		add_option( 'spr_cache_ver', 1 );
		update_option( 'spr_db_version', SPR_DB_VERSION );

		// The first full index is started on the next request, once WooCommerce is loaded.
		// Re-activating a plugin that already has a finished index keeps it.
		$state = get_option( 'spr_index_state', array() );
		if ( empty( $state['status'] ) || 'done' !== $state['status'] ) {
			update_option( 'spr_needs_initial_build', 1 );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( SPR_Indexer::CRON_FULL );
		wp_clear_scheduled_hook( SPR_Indexer::CRON_BATCH );
		wp_clear_scheduled_hook( 'spr_cleanup' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( SPR_Indexer::CRON_BATCH, array(), 'spr' );
			as_unschedule_all_actions( SPR_Indexer::CRON_FULL, array(), 'spr' );
			as_unschedule_all_actions( SPR_Indexer::HOOK_SINGLE, array(), 'spr' );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'spr_db_version' ) !== SPR_DB_VERSION ) {
			self::create_tables();
			update_option( 'spr_db_version', SPR_DB_VERSION );
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$rel     = self::table( 'relations' );
		$tok     = self::table( 'tokens' );
		$df      = self::table( 'token_df' );
		$views   = self::table( 'views' );
		$stats   = self::table( 'stats' );

		// Tokens use a binary collation: they are already normalized, and a binary
		// comparison keeps distinct Persian/Arabic letters from being merged.
		$sql = "CREATE TABLE {$rel} (
  product_id bigint(20) unsigned NOT NULL,
  type tinyint(3) unsigned NOT NULL,
  related_id bigint(20) unsigned NOT NULL,
  score float NOT NULL DEFAULT 0,
  PRIMARY KEY  (product_id,type,related_id),
  KEY related_id (related_id)
) {$charset};
CREATE TABLE {$tok} (
  token varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  product_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (token,product_id),
  KEY product_id (product_id)
) {$charset};
CREATE TABLE {$df} (
  token varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  df int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (token)
) {$charset};
CREATE TABLE {$views} (
  product_id bigint(20) unsigned NOT NULL,
  day date NOT NULL,
  views int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (product_id,day),
  KEY day (day)
) {$charset};
CREATE TABLE {$stats} (
  day date NOT NULL,
  placement varchar(32) NOT NULL,
  impressions int(10) unsigned NOT NULL DEFAULT 0,
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  orders int(10) unsigned NOT NULL DEFAULT 0,
  revenue decimal(19,4) NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,placement)
) {$charset};";

		dbDelta( $sql );
	}

	public static function drop_tables() {
		global $wpdb;
		foreach ( array( 'relations', 'tokens', 'token_df', 'views', 'stats' ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}
}
