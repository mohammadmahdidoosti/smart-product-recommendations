<?php
/**
 * Admin: WooCommerce → Recommendations.
 *
 * Tabs: overview (setup steps, index, KPIs), appearance (with live preview),
 * locations, matching (with product inspector), results, shortcode builder.
 * Forms save over AJAX; options.php remains the no-JS fallback.
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

class SPR_Admin {

	const SLUG = 'spr-recommendations';

	/** Checkbox fields per form tab: a missing checkbox in a submitted tab means "off". */
	const TAB_CHECKS = array(
		'appearance' => array( 'show_add_to_cart', 'show_discount', 'show_rating', 'bundle' ),
		'placements' => array( 'hide_out_of_stock', 'replace_wc_related' ),
		'algorithm'  => array(),
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'status', 'rebuild', 'cancel', 'tick', 'preview', 'save', 'preview_block' ) as $a ) {
			add_action( 'wp_ajax_spr_' . $a, array( __CLASS__, 'ajax_' . $a ) );
		}
		add_action( 'update_option_spr_settings', array( __CLASS__, 'after_save' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( SPR_FILE ), array( __CLASS__, 'links' ) );
	}

	public static function url( $tab = '', $args = array() ) {
		$args = array_merge( array( 'page' => self::SLUG ), $tab ? array( 'tab' => $tab ) : array(), $args );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'smart-product-recommendations' ) . '</a>' );
		return $links;
	}

	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Product recommendations', 'smart-product-recommendations' ),
			__( 'Recommendations', 'smart-product-recommendations' ),
			'manage_woocommerce',
			self::SLUG,
			array( __CLASS__, 'page' )
		);
	}

	public static function register() {
		register_setting(
			'spr',
			'spr_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
			)
		);
	}

	private static function money_format() {
		return array(
			'symbol'   => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '',
			'pos'      => get_option( 'woocommerce_currency_pos', 'right_space' ),
			'decimals' => function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 0,
			'dec'      => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
			'thou'     => function_exists( 'wc_get_price_thousand_separator' ) ? wc_get_price_thousand_separator() : ',',
		);
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		if ( is_rtl() ) {
			wp_enqueue_style( 'spr-vazirmatn', SPR_URL . 'assets/css/fonts.css', array(), SPR_VERSION );
		}
		wp_enqueue_style( 'spr', SPR_URL . 'assets/css/spr.css', array(), SPR_VERSION );
		wp_enqueue_style( 'spr-admin', SPR_URL . 'assets/css/admin.css', array( 'spr' ), SPR_VERSION );

		// Storefront script in preview mode: carousels and bundle totals work, nothing is tracked.
		wp_register_script( 'spr', SPR_URL . 'assets/js/spr.js', array(), SPR_VERSION, true );
		wp_add_inline_script(
			'spr',
			'window.SPR=' . wp_json_encode(
				array(
					'rest'    => rest_url( SPR_REST::NS . '/' ),
					'preview' => true,
					'money'   => self::money_format(),
				)
			) . ';',
			'before'
		);
		wp_enqueue_script( 'spr-admin', SPR_URL . 'assets/js/admin.js', array( 'spr' ), SPR_VERSION, true );
		wp_localize_script(
			'spr-admin',
			'SPRAdmin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'spr_admin' ),
				'i18n'  => array(
					'idle'      => __( 'Not built yet', 'smart-product-recommendations' ),
					'running'   => __( 'Building', 'smart-product-recommendations' ),
					'done'      => __( 'Up to date', 'smart-product-recommendations' ),
					'tokens'    => __( 'Reading product names', 'smart-product-recommendations' ),
					'df'        => __( 'Weighing words', 'smart-product-recommendations' ),
					'relations' => __( 'Finding matches for each product', 'smart-product-recommendations' ),
					'confirm'   => __( 'Rebuild recommendations for all products? The store keeps showing the current ones until it finishes.', 'smart-product-recommendations' ),
					'none'      => __( 'No matches for this product yet.', 'smart-product-recommendations' ),
					/* translators: %s: number */
					'rel_count' => __( '%s stored recommendations', 'smart-product-recommendations' ),
					/* translators: %s: date */
					'built'     => __( 'Last built %s', 'smart-product-recommendations' ),
					/* translators: %s: date */
					'next'      => __( 'Next automatic build %s', 'smart-product-recommendations' ),
					/* translators: 1: done, 2: total */
					'progress'  => __( '%1$s of %2$s products', 'smart-product-recommendations' ),
					'saved'     => __( 'Changes saved', 'smart-product-recommendations' ),
					'saveFail'  => __( 'Changes were not saved. Check your connection and try again.', 'smart-product-recommendations' ),
					'unsaved'   => __( 'You have unsaved changes', 'smart-product-recommendations' ),
					'allSaved'  => __( 'All changes saved', 'smart-product-recommendations' ),
					'copied'    => __( 'Shortcode copied', 'smart-product-recommendations' ),
					'stockOut'  => __( 'Out of stock', 'smart-product-recommendations' ),
					'similar'   => __( 'Similar products', 'smart-product-recommendations' ),
					'bought'    => __( 'Frequently bought together', 'smart-product-recommendations' ),
					'noPreview' => __( 'The preview needs the first build to finish. It uses real products from your store.', 'smart-product-recommendations' ),
					'notFound'  => __( 'No product matches that ID or name.', 'smart-product-recommendations' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Sanitize settings. Only fields of the submitted tab change; everything else keeps its value.
	 *
	 * @param mixed $in Raw.
	 * @return array
	 */
	public static function sanitize( $in ) {
		SPR_Settings::flush();
		$in  = is_array( $in ) ? $in : array();
		$tab = isset( $in['_tab'] ) && isset( self::TAB_CHECKS[ $in['_tab'] ] ) ? $in['_tab'] : '';
		$out = SPR_Settings::all();
		$d   = SPR_Settings::defaults();

		// Checkboxes of the submitted tab (all of them when no tab is given).
		$checks = $tab ? self::TAB_CHECKS[ $tab ] : array_merge( ...array_values( self::TAB_CHECKS ) );
		foreach ( $checks as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}

		if ( 'placements' === $tab || ( isset( $in['placements'] ) && is_array( $in['placements'] ) ) ) {
			$given = isset( $in['placements'] ) && is_array( $in['placements'] ) ? $in['placements'] : array();
			foreach ( SPR_Settings::placements() as $key => $def ) {
				$p                         = isset( $given[ $key ] ) && is_array( $given[ $key ] ) ? $given[ $key ] : array();
				$out['placements'][ $key ] = array(
					'enabled' => empty( $p['enabled'] ) ? 0 : 1,
					'title'   => isset( $p['title'] ) ? sanitize_text_field( $p['title'] ) : '',
					'limit'   => isset( $p['limit'] ) ? max( 1, min( 24, absint( $p['limit'] ) ) ) : $def['limit'],
				);
			}
		}

		$enums = array(
			'layout'   => array( 'carousel', 'grid' ),
			'style'    => array( 'card', 'minimal', 'compact' ),
			'schedule' => array( 'daily', 'weekly', 'manual' ),
		);
		foreach ( $enums as $k => $allowed ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = in_array( $in[ $k ], $allowed, true ) ? $in[ $k ] : $d[ $k ];
			}
		}

		if ( isset( $in['accent'] ) ) {
			$hex           = sanitize_hex_color( $in['accent'] );
			$out['accent'] = $hex ? $hex : $d['accent'];
		}

		$floats = array(
			'w_cat'          => array( 0, 10 ),
			'w_tag'          => array( 0, 10 ),
			'w_brand'        => array( 0, 10 ),
			'w_attr'         => array( 0, 10 ),
			'w_title'        => array( 0, 10 ),
			'w_price'        => array( 0, 1 ),
			'max_term_share' => array( 1, 100 ),
		);
		foreach ( $floats as $k => $r ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = max( $r[0], min( $r[1], (float) str_replace( ',', '.', (string) $in[ $k ] ) ) );
			}
		}

		$ints = array(
			'columns'       => array( 1, 6 ),
			'radius'        => array( 0, 28 ),
			'min_support'   => array( 1, 1000 ),
			'lookback_days' => array( 7, 3650 ),
			'store_top'     => array( 4, 100 ),
			'batch_size'    => array( 10, 1000 ),
			'cache_minutes' => array( 0, 1440 ),
		);
		foreach ( $ints as $k => $r ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = max( $r[0], min( $r[1], (int) $in[ $k ] ) );
			}
		}

		if ( isset( $in['exclude_ids'] ) ) {
			$out['exclude_ids'] = implode( ',', array_filter( wp_parse_id_list( $in['exclude_ids'] ) ) );
		}
		if ( 'placements' === $tab || isset( $in['exclude_cats'] ) ) {
			$out['exclude_cats'] = isset( $in['exclude_cats'] ) ? array_values( array_filter( wp_parse_id_list( $in['exclude_cats'] ) ) ) : array();
		}

		return $out;
	}

	public static function after_save( $old, $new ) {
		SPR_Settings::flush();
		SPR_Indexer::bump_cache();
		delete_transient( 'spr_trending' );
		wp_clear_scheduled_hook( SPR_Indexer::CRON_FULL );
		SPR_Indexer::ensure_schedule();

		foreach ( array( 'w_cat', 'w_tag', 'w_brand', 'w_attr', 'w_title', 'w_price', 'max_term_share', 'min_support', 'lookback_days', 'store_top' ) as $k ) {
			if ( ! isset( $old[ $k ] ) || (string) $old[ $k ] !== (string) $new[ $k ] ) {
				update_option( 'spr_algo_changed', 1 );
				break;
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                               */
	/* ------------------------------------------------------------------ */

	private static function guard() {
		check_ajax_referer( 'spr_admin', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
	}

	private static function status_payload() {
		$s            = SPR_Indexer::state();
		$fmt          = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$s['pct']     = 'done' === $s['status'] ? 100 : ( 'running' === $s['status'] && 'relations' === $s['phase'] && $s['total'] ? round( 100 * $s['processed'] / $s['total'], 1 ) : 0 );
		$s['ended']   = $s['finished'] ? wp_date( $fmt, $s['finished'] ) : '';
		$next         = wp_next_scheduled( SPR_Indexer::CRON_FULL );
		$s['next']    = $next ? wp_date( $fmt, $next ) : '';
		$s['changed'] = (bool) get_option( 'spr_algo_changed' );
		foreach ( array( 'processed', 'total', 'relations' ) as $k ) {
			$s[ $k . '_i18n' ] = number_format_i18n( (int) $s[ $k ] );
		}
		$s['pct_i18n'] = number_format_i18n( $s['pct'], 0 );
		return $s;
	}

	public static function ajax_status() {
		self::guard();
		wp_send_json_success( self::status_payload() );
	}

	public static function ajax_rebuild() {
		self::guard();
		delete_option( 'spr_algo_changed' );
		SPR_Indexer::start_full();
		wp_send_json_success( self::status_payload() );
	}

	public static function ajax_cancel() {
		self::guard();
		SPR_Indexer::cancel();
		wp_send_json_success( self::status_payload() );
	}

	/**
	 * While the admin page is open, the browser also drives the indexer —
	 * progress continues even where WP-Cron / Action Scheduler is slow or disabled.
	 */
	public static function ajax_tick() {
		self::guard();
		SPR_Indexer::run_background();
		wp_send_json_success( self::status_payload() );
	}

	public static function ajax_save() {
		self::guard();
		$raw = isset( $_POST['spr_settings'] ) && is_array( $_POST['spr_settings'] ) ? wp_unslash( $_POST['spr_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize().
		update_option( 'spr_settings', self::sanitize( $raw ) );
		SPR_Settings::flush();
		wp_send_json_success( array( 'changed' => (bool) get_option( 'spr_algo_changed' ) ) );
	}

	private static function find_product( $q ) {
		$id = absint( $q );
		if ( ! $id && '' !== $q ) {
			$found = wc_get_products( array( 's' => $q, 'limit' => 1, 'return' => 'ids', 'status' => 'publish' ) );
			$id    = $found ? (int) $found[0] : 0;
		}
		return ( $id && 'product' === get_post_type( $id ) ) ? $id : 0;
	}

	/**
	 * A product that shows the preview well: one with co-purchase data if possible.
	 */
	private static function sample_product() {
		global $wpdb;
		$id = (int) get_transient( 'spr_sample_product' );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return $id;
		}
		$rel = SPR_Install::table( 'relations' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( "SELECT product_id FROM {$rel} WHERE type = " . SPR_REL_BOUGHT . ' GROUP BY product_id HAVING COUNT(*) >= 3 ORDER BY SUM(score) DESC LIMIT 1' );
		if ( ! $id ) {
			$id = (int) $wpdb->get_var( "SELECT product_id FROM {$rel} WHERE type = " . SPR_REL_SIMILAR . ' LIMIT 1' );
		}
		// phpcs:enable
		if ( $id ) {
			set_transient( 'spr_sample_product', $id, DAY_IN_SECONDS );
		}
		return $id;
	}

	private static function price_text( $p ) {
		$price = function_exists( 'wc_get_price_to_display' ) ? wc_get_price_to_display( $p ) : $p->get_price();
		return html_entity_decode( wp_strip_all_tags( wc_price( $price ) ), ENT_QUOTES, 'UTF-8' );
	}

	private static function thumb_url( $p ) {
		$src = wp_get_attachment_image_src( $p->get_image_id(), 'thumbnail' );
		if ( $src ) {
			return $src[0];
		}
		return function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'thumbnail' ) : '';
	}

	public static function ajax_preview() {
		self::guard();
		$id      = self::find_product( isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '' );
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product ) {
			wp_send_json_error( __( 'No product matches that ID or name.', 'smart-product-recommendations' ) );
		}

		$out = array(
			'product' => array(
				'id'    => $id,
				'name'  => $product->get_name(),
				'url'   => get_edit_post_link( $id, 'raw' ),
				'img'   => self::thumb_url( $product ),
				'price' => self::price_text( $product ),
			),
		);
		foreach ( array( 'similar' => SPR_REL_SIMILAR, 'bought' => SPR_REL_BOUGHT ) as $k => $type ) {
			$rels = SPR_Engine::relations( $id, $type );
			$max  = $rels ? max( $rels ) : 1;
			$rows = array();
			foreach ( array_slice( $rels, 0, 8, true ) as $rid => $score ) {
				$p = wc_get_product( $rid );
				if ( ! $p ) {
					continue;
				}
				$rows[] = array(
					'id'    => $rid,
					'name'  => $p->get_name(),
					// Similar: absolute 0–1 match. Bought: relative to the strongest pair.
					'pct'   => (int) round( 100 * ( 'similar' === $k ? $score : $score / $max ) ),
					'label' => 'similar' === $k
						? number_format_i18n( round( 100 * $score ) ) . '%'
						: sprintf( /* translators: %s: orders */ _n( '%s order', '%s orders', (int) $score, 'smart-product-recommendations' ), number_format_i18n( $score ) ),
					'price' => self::price_text( $p ),
					'stock' => $p->is_in_stock(),
					'img'   => self::thumb_url( $p ),
					'url'   => get_edit_post_link( $rid, 'raw' ),
				);
			}
			$out[ $k ] = $rows;
		}
		wp_send_json_success( $out );
	}

	/**
	 * Render real blocks with the (unsaved) appearance values from the form.
	 */
	public static function ajax_preview_block() {
		self::guard();
		$raw         = isset( $_POST['spr_settings'] ) && is_array( $_POST['spr_settings'] ) ? wp_unslash( $_POST['spr_settings'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$raw['_tab'] = 'appearance';
		SPR_Settings::override( self::sanitize( $raw ) );

		$q  = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$id = $q ? self::find_product( $q ) : self::sample_product();
		if ( ! $id ) {
			wp_send_json_error( $q ? 'not_found' : 'no_index' );
		}

		$similar = SPR_Engine::get( 'similar', array( 'product_id' => $id, 'limit' => 8 ) );
		$bought  = SPR_Engine::get( 'bought', array( 'product_id' => $id, 'limit' => 3 ) );
		$p1      = SPR_Settings::placement( 'product_bought' );
		$p2      = SPR_Settings::placement( 'product_similar' );

		wp_send_json_success(
			array(
				'html' => SPR_Renderer::block( $bought, $p1['title'], 'product_bought', array( 'main' => $id ) )
					. SPR_Renderer::block( array_values( array_diff( $similar, $bought ) ), $p2['title'], 'product_similar' ),
				'name' => get_the_title( $id ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Page shell                                                         */
	/* ------------------------------------------------------------------ */

	private static function tabs() {
		return array(
			'dashboard'  => __( 'Overview', 'smart-product-recommendations' ),
			'appearance' => __( 'Appearance', 'smart-product-recommendations' ),
			'placements' => __( 'Where to show', 'smart-product-recommendations' ),
			'algorithm'  => __( 'Matching', 'smart-product-recommendations' ),
			'stats'      => __( 'Results', 'smart-product-recommendations' ),
			'help'       => __( 'Shortcode', 'smart-product-recommendations' ),
		);
	}

	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ sanitize_key( $_GET['tab'] ) ] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$n    = SPR_Indexer::count_products();
		?>
		<div class="wrap spr-app">
			<header class="spr-top">
				<div class="spr-brand">
					<span class="spr-logo" aria-hidden="true"><svg viewBox="0 0 32 32"><rect x="3" y="3" width="11" height="11" rx="3"/><rect x="18" y="3" width="11" height="11" rx="3" opacity=".4"/><rect x="3" y="18" width="11" height="11" rx="3" opacity=".4"/><rect x="18" y="18" width="11" height="11" rx="3"/></svg></span>
					<div>
						<h1><?php esc_html_e( 'Product recommendations', 'smart-product-recommendations' ); ?></h1>
						<p><?php echo esc_html( sprintf( /* translators: %s: number of products */ _n( '%s published product', '%s published products', $n, 'smart-product-recommendations' ), number_format_i18n( $n ) ) ); ?></p>
					</div>
				</div>
				<a class="spr-pill" href="<?php echo esc_url( self::url() ); ?>" data-spr-pill><span class="spr-dot" aria-hidden="true"></span><span data-spr-pill-text>…</span></a>
			</header>
			<hr class="wp-header-end">

			<nav class="spr-tabs" aria-label="<?php esc_attr_e( 'Sections', 'smart-product-recommendations' ); ?>">
				<?php foreach ( $tabs as $k => $label ) : ?>
					<a href="<?php echo esc_url( self::url( $k ) ); ?>" class="<?php echo $k === $tab ? 'is-active' : ''; ?>" <?php echo $k === $tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="spr-banner" data-spr-changed hidden>
				<p><?php esc_html_e( 'Matching settings changed. Rebuild so every product uses them.', 'smart-product-recommendations' ); ?></p>
				<button type="button" class="spr-btn-primary" data-spr-rebuild><?php esc_html_e( 'Rebuild now', 'smart-product-recommendations' ); ?></button>
			</div>

			<main class="spr-main spr-tab-<?php echo esc_attr( $tab ); ?>">
				<?php call_user_func( array( __CLASS__, 'tab_' . $tab ) ); ?>
			</main>

			<div class="spr-toast" role="status" aria-live="polite" hidden></div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Form helpers                                                       */
	/* ------------------------------------------------------------------ */

	private static function form_open( $tab ) {
		echo '<form method="post" action="options.php" class="spr-form" data-spr-form="' . esc_attr( $tab ) . '">';
		settings_fields( 'spr' );
		echo '<input type="hidden" name="spr_settings[_tab]" value="' . esc_attr( $tab ) . '">';
	}

	private static function form_close() {
		?>
		<div class="spr-savebar" data-spr-savebar>
			<span data-spr-dirty><?php esc_html_e( 'All changes saved', 'smart-product-recommendations' ); ?></span>
			<button type="submit" class="spr-btn-primary"><?php esc_html_e( 'Save changes', 'smart-product-recommendations' ); ?></button>
		</div>
		</form>
		<?php
	}

	private static function toggle( $name, $checked, $label, $help = '' ) {
		$id = 'spr-' . md5( $name );
		?>
		<label class="spr-switch" for="<?php echo esc_attr( $id ); ?>">
			<input type="checkbox" role="switch" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked, 1 ); ?>>
			<span class="spr-switch-ui" aria-hidden="true"></span>
			<span class="spr-switch-text"><?php echo esc_html( $label ); ?>
				<?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?>
			</span>
		</label>
		<?php
	}

	private static function slider( $key, $value, $label, $min, $max, $step, $help = '', $left = '', $right = '' ) {
		$id = 'spr-' . $key;
		?>
		<div class="spr-field spr-slider">
			<div class="spr-field-head">
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label>
				<output for="<?php echo esc_attr( $id ); ?>" data-spr-out><?php echo esc_html( number_format_i18n( (float) $value, (float) $step < 1 ? ( (float) $step < 0.1 ? 2 : 1 ) : 0 ) ); ?></output>
			</div>
			<input type="range" id="<?php echo esc_attr( $id ); ?>" name="spr_settings[<?php echo esc_attr( $key ); ?>]" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>" step="<?php echo esc_attr( $step ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php if ( $left || $right ) : ?>
				<div class="spr-scale" aria-hidden="true"><span><?php echo esc_html( $left ); ?></span><span><?php echo esc_html( $right ); ?></span></div>
			<?php endif; ?>
			<?php if ( $help ) : ?><p class="spr-help"><?php echo esc_html( $help ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	private static function number( $key, $value, $label, $min, $max, $help = '', $suffix = '' ) {
		$id = 'spr-' . $key;
		?>
		<div class="spr-field spr-field-inline">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?>
				<?php if ( $help ) : ?><small><?php echo esc_html( $help ); ?></small><?php endif; ?>
			</label>
			<span class="spr-num">
				<input type="number" id="<?php echo esc_attr( $id ); ?>" name="spr_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $min ); ?>" max="<?php echo esc_attr( $max ); ?>">
				<?php if ( $suffix ) : ?><em><?php echo esc_html( $suffix ); ?></em><?php endif; ?>
			</span>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Overview                                                           */
	/* ------------------------------------------------------------------ */

	private static function tab_dashboard() {
		$s       = SPR_Settings::all();
		$state   = SPR_Indexer::state();
		$report  = SPR_Tracking::report( 30 );
		$tot     = self::totals( $report['rows'] );
		$enabled = 0;
		foreach ( $s['placements'] as $p ) {
			$enabled += empty( $p['enabled'] ) ? 0 : 1;
		}
		$lookup = SPR_Indexer::has_order_lookup();

		$steps = array(
			array(
				'done'  => 'done' === $state['status'],
				'title' => __( 'Build recommendations', 'smart-product-recommendations' ),
				'text'  => __( 'Every product is compared with the rest of the catalog once, in the background.', 'smart-product-recommendations' ),
				'link'  => '',
			),
			array(
				'done'  => $lookup,
				'title' => __( 'Connect sales history', 'smart-product-recommendations' ),
				'text'  => $lookup
					? __( 'WooCommerce Analytics is on, so "bought together" learns from real orders.', 'smart-product-recommendations' )
					: __( 'Turn on WooCommerce Analytics and import past orders (Analytics → Settings → Import historical data).', 'smart-product-recommendations' ),
				'link'  => $lookup ? '' : admin_url( 'admin.php?page=wc-admin&path=/analytics/settings' ),
			),
			array(
				'done'  => $enabled > 0,
				'title' => __( 'Choose where they appear', 'smart-product-recommendations' ),
				'text'  => sprintf( /* translators: %s: number */ _n( '%s location is on.', '%s locations are on.', $enabled, 'smart-product-recommendations' ), number_format_i18n( $enabled ) ),
				'link'  => self::url( 'placements' ),
			),
			array(
				'done'  => $tot['clicks'] > 0,
				'title' => __( 'First shopper clicks', 'smart-product-recommendations' ),
				'text'  => $tot['clicks'] > 0
					? __( 'Shoppers are using recommendations. See what works under Results.', 'smart-product-recommendations' )
					: __( 'Results appear here once visitors start clicking recommended products.', 'smart-product-recommendations' ),
				'link'  => $tot['clicks'] > 0 ? self::url( 'stats' ) : '',
			),
		);
		?>
		<div class="spr-grid spr-grid-dash">
			<section class="spr-panel spr-index" data-spr-index>
				<h2><?php esc_html_e( 'Recommendation index', 'smart-product-recommendations' ); ?></h2>
				<p class="spr-index-state"><strong data-spr-state>…</strong> <span data-spr-phase></span></p>
				<div class="spr-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-spr-progress><span data-spr-bar></span></div>
				<ul class="spr-index-meta">
					<li data-spr-meta="progress" hidden></li>
					<li data-spr-meta="built" hidden></li>
					<li data-spr-meta="count" hidden></li>
					<li data-spr-meta="next" hidden></li>
				</ul>
				<div class="spr-actions">
					<button type="button" class="spr-btn-primary" data-spr-rebuild><?php esc_html_e( 'Rebuild now', 'smart-product-recommendations' ); ?></button>
					<button type="button" class="spr-btn-ghost" data-spr-cancel><?php esc_html_e( 'Stop', 'smart-product-recommendations' ); ?></button>
				</div>
				<p class="spr-help"><?php esc_html_e( 'Keeping this page open makes the build faster. On large stores you can also run wp spr rebuild over SSH.', 'smart-product-recommendations' ); ?></p>
			</section>

			<section class="spr-panel spr-steps">
				<h2><?php esc_html_e( 'Setup', 'smart-product-recommendations' ); ?></h2>
				<ol>
					<?php foreach ( $steps as $i => $st ) : ?>
						<li class="<?php echo $st['done'] ? 'is-done' : ''; ?>">
							<span class="spr-step-mark" aria-hidden="true"><?php echo $st['done'] ? '' : esc_html( number_format_i18n( $i + 1 ) ); ?></span>
							<div>
								<strong><?php echo esc_html( $st['title'] ); ?><?php if ( $st['done'] ) : ?><span class="screen-reader-text"> (<?php esc_html_e( 'done', 'smart-product-recommendations' ); ?>)</span><?php endif; ?></strong>
								<p><?php echo esc_html( $st['text'] ); ?></p>
								<?php if ( $st['link'] ) : ?>
									<a href="<?php echo esc_url( $st['link'] ); ?>"><?php echo $st['done'] ? esc_html__( 'Open', 'smart-product-recommendations' ) : esc_html__( 'Set up', 'smart-product-recommendations' ); ?></a>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			</section>

			<section class="spr-panel spr-span">
				<div class="spr-panel-head">
					<h2><?php esc_html_e( 'Last 30 days', 'smart-product-recommendations' ); ?></h2>
					<a href="<?php echo esc_url( self::url( 'stats' ) ); ?>"><?php esc_html_e( 'All results', 'smart-product-recommendations' ); ?></a>
				</div>
				<?php self::kpis( $tot ); ?>
				<?php self::chart( $report['daily'], 30 ); ?>
			</section>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Appearance                                                         */
	/* ------------------------------------------------------------------ */

	private static function tab_appearance() {
		$s        = SPR_Settings::all();
		$styles   = array(
			'card'    => array( __( 'Cards', 'smart-product-recommendations' ), __( 'Framed, works on any background', 'smart-product-recommendations' ) ),
			'minimal' => array( __( 'Minimal', 'smart-product-recommendations' ), __( 'Photo-led, no frame', 'smart-product-recommendations' ) ),
			'compact' => array( __( 'Compact rows', 'smart-product-recommendations' ), __( 'For sidebars and the cart', 'smart-product-recommendations' ) ),
		);
		$swatches = array(
			'#0F8B8D' => __( 'Turquoise', 'smart-product-recommendations' ),
			'#1E3A8A' => __( 'Navy', 'smart-product-recommendations' ),
			'#9F1239' => __( 'Crimson', 'smart-product-recommendations' ),
			'#C2410C' => __( 'Orange', 'smart-product-recommendations' ),
			'#15803D' => __( 'Green', 'smart-product-recommendations' ),
			'#1F2937' => __( 'Charcoal', 'smart-product-recommendations' ),
		);
		self::form_open( 'appearance' );
		?>
		<div class="spr-grid spr-grid-preview">
			<div class="spr-stack">
				<section class="spr-panel">
					<h2 id="spr-h-style"><?php esc_html_e( 'Card style', 'smart-product-recommendations' ); ?></h2>
					<div class="spr-styles" role="radiogroup" aria-labelledby="spr-h-style">
						<?php foreach ( $styles as $k => $st ) : ?>
							<label class="spr-style-opt">
								<input type="radio" name="spr_settings[style]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $s['style'], $k ); ?>>
								<span class="spr-style-art spr-art-<?php echo esc_attr( $k ); ?>" aria-hidden="true"><i></i><i></i><i></i></span>
								<strong><?php echo esc_html( $st[0] ); ?></strong>
								<small><?php echo esc_html( $st[1] ); ?></small>
							</label>
						<?php endforeach; ?>
					</div>
				</section>

				<section class="spr-panel">
					<h2><?php esc_html_e( 'Color and shape', 'smart-product-recommendations' ); ?></h2>
					<div class="spr-field">
						<label for="spr-accent"><?php esc_html_e( 'Accent color', 'smart-product-recommendations' ); ?></label>
						<div class="spr-swatches">
							<?php foreach ( $swatches as $hex => $name ) : ?>
								<button type="button" class="spr-swatch" style="--c:<?php echo esc_attr( $hex ); ?>" data-color="<?php echo esc_attr( $hex ); ?>" aria-label="<?php echo esc_attr( $name ); ?>" title="<?php echo esc_attr( $name ); ?>"></button>
							<?php endforeach; ?>
							<span class="spr-color-custom">
								<input type="color" id="spr-accent" name="spr_settings[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>">
								<code data-spr-hex dir="ltr"><?php echo esc_html( strtoupper( $s['accent'] ) ); ?></code>
							</span>
						</div>
						<p class="spr-help"><?php esc_html_e( 'Used for buttons, the bundle total and selected items. Pick your brand color.', 'smart-product-recommendations' ); ?></p>
					</div>
					<?php self::slider( 'radius', $s['radius'], __( 'Corner roundness', 'smart-product-recommendations' ), 0, 24, 1, '', __( 'Square', 'smart-product-recommendations' ), __( 'Round', 'smart-product-recommendations' ) ); ?>
				</section>

				<section class="spr-panel">
					<h2 id="spr-h-layout"><?php esc_html_e( 'Layout', 'smart-product-recommendations' ); ?></h2>
					<div class="spr-seg" role="radiogroup" aria-labelledby="spr-h-layout">
						<label><input type="radio" name="spr_settings[layout]" value="carousel" <?php checked( $s['layout'], 'carousel' ); ?>><span><?php esc_html_e( 'Slider', 'smart-product-recommendations' ); ?></span></label>
						<label><input type="radio" name="spr_settings[layout]" value="grid" <?php checked( $s['layout'], 'grid' ); ?>><span><?php esc_html_e( 'Grid', 'smart-product-recommendations' ); ?></span></label>
					</div>
					<?php self::slider( 'columns', $s['columns'], __( 'Products per row on desktop', 'smart-product-recommendations' ), 2, 6, 1, __( 'Narrow spaces and phones show fewer automatically.', 'smart-product-recommendations' ) ); ?>
				</section>

				<section class="spr-panel">
					<h2><?php esc_html_e( 'On each card', 'smart-product-recommendations' ); ?></h2>
					<div class="spr-switches">
					<?php
					self::toggle( 'spr_settings[show_add_to_cart]', $s['show_add_to_cart'], __( 'Quick "add to cart" button', 'smart-product-recommendations' ) );
					self::toggle( 'spr_settings[show_discount]', $s['show_discount'], __( 'Discount percentage', 'smart-product-recommendations' ) );
					self::toggle( 'spr_settings[show_rating]', $s['show_rating'], __( 'Star rating', 'smart-product-recommendations' ), __( 'Only for products that have reviews.', 'smart-product-recommendations' ) );
					self::toggle( 'spr_settings[bundle]', $s['bundle'], __( 'Show "bought together" as a bundle', 'smart-product-recommendations' ), __( 'This product plus its usual companions, with a live total and one button to add them all.', 'smart-product-recommendations' ) );
					?>
					</div>
				</section>
			</div>

			<aside class="spr-preview" data-spr-preview aria-label="<?php esc_attr_e( 'Live preview', 'smart-product-recommendations' ); ?>">
				<div class="spr-preview-bar">
					<strong><?php esc_html_e( 'Live preview', 'smart-product-recommendations' ); ?></strong>
					<div class="spr-seg spr-seg-sm" role="radiogroup" aria-label="<?php esc_attr_e( 'Preview width', 'smart-product-recommendations' ); ?>">
						<label><input type="radio" name="spr_device" value="desktop" checked form="spr-none"><span><?php esc_html_e( 'Desktop', 'smart-product-recommendations' ); ?></span></label>
						<label><input type="radio" name="spr_device" value="mobile" form="spr-none"><span><?php esc_html_e( 'Mobile', 'smart-product-recommendations' ); ?></span></label>
					</div>
				</div>
				<div class="spr-preview-product">
					<input type="search" form="spr-none" data-spr-preview-q placeholder="<?php esc_attr_e( 'Preview another product: ID or name', 'smart-product-recommendations' ); ?>" aria-label="<?php esc_attr_e( 'Preview another product', 'smart-product-recommendations' ); ?>">
				</div>
				<div class="spr-preview-frame" data-spr-frame>
					<p class="spr-preview-name" data-spr-preview-name></p>
					<div class="spr-preview-sizer"><div class="spr-preview-canvas" data-spr-canvas><div class="spr-skeleton"><span></span><span></span><span></span><span></span></div></div></div>
				</div>
				<p class="spr-help"><?php esc_html_e( 'Real products from your store. On the live site your theme\'s font is used.', 'smart-product-recommendations' ); ?></p>
			</aside>
		</div>
		<?php
		self::form_close();
	}

	/* ------------------------------------------------------------------ */
	/* Locations                                                          */
	/* ------------------------------------------------------------------ */

	private static function tab_placements() {
		$s     = SPR_Settings::all();
		$defs  = SPR_Settings::placements();
		$cats  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 1000, 'orderby' => 'name' ) );
		$where = array(
			'product_bought'  => __( 'Under the product summary, as a bundle with one "add all" button.', 'smart-product-recommendations' ),
			'product_similar' => __( 'Under the product, in place of "Related products".', 'smart-product-recommendations' ),
			'product_viewed'  => __( 'Helps shoppers get back to what they were comparing.', 'smart-product-recommendations' ),
			'cart'            => __( 'Under the cart, based on what is in it.', 'smart-product-recommendations' ),
			'thankyou'        => __( 'After checkout, based on what was just bought.', 'smart-product-recommendations' ),
		);
		self::form_open( 'placements' );
		?>
		<div class="spr-grid spr-grid-wide">
			<section class="spr-panel spr-span">
				<h2><?php esc_html_e( 'Locations', 'smart-product-recommendations' ); ?></h2>
				<p class="spr-help"><?php esc_html_e( 'Leave a title empty to use the default shown in grey.', 'smart-product-recommendations' ); ?></p>
				<ul class="spr-places">
					<?php foreach ( $defs as $key => $def ) : $p = $s['placements'][ $key ]; $id = 'spr-pl-' . $key; ?>
						<li class="spr-place <?php echo $p['enabled'] ? 'is-on' : ''; ?>">
							<label class="spr-switch" for="<?php echo esc_attr( $id ); ?>">
								<input type="checkbox" role="switch" id="<?php echo esc_attr( $id ); ?>" name="spr_settings[placements][<?php echo esc_attr( $key ); ?>][enabled]" value="1" <?php checked( $p['enabled'], 1 ); ?>>
								<span class="spr-switch-ui" aria-hidden="true"></span>
								<span class="spr-switch-text"><?php echo esc_html( $def['label'] ); ?><small><?php echo esc_html( $where[ $key ] ); ?></small></span>
							</label>
							<div class="spr-place-fields">
								<label><span><?php esc_html_e( 'Title', 'smart-product-recommendations' ); ?></span>
									<input type="text" name="spr_settings[placements][<?php echo esc_attr( $key ); ?>][title]" value="<?php echo esc_attr( $p['title'] ); ?>" placeholder="<?php echo esc_attr( $def['title'] ); ?>"></label>
								<label class="spr-place-count"><span><?php esc_html_e( 'Products', 'smart-product-recommendations' ); ?></span>
									<input type="number" min="1" max="24" name="spr_settings[placements][<?php echo esc_attr( $key ); ?>][limit]" value="<?php echo esc_attr( $p['limit'] ); ?>"></label>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>

			<section class="spr-panel">
				<h2><?php esc_html_e( 'Rules', 'smart-product-recommendations' ); ?></h2>
				<div class="spr-switches">
				<?php
				self::toggle( 'spr_settings[hide_out_of_stock]', $s['hide_out_of_stock'], __( 'Skip out-of-stock products', 'smart-product-recommendations' ) );
				self::toggle( 'spr_settings[replace_wc_related]', $s['replace_wc_related'], __( 'Replace WooCommerce\'s "Related products"', 'smart-product-recommendations' ), __( 'Avoids showing two similar rows on the product page.', 'smart-product-recommendations' ) );
				?>
				</div>
				<?php self::number( 'cache_minutes', $s['cache_minutes'], __( 'Keep ready-made blocks for', 'smart-product-recommendations' ), 0, 1440, __( 'Makes pages faster. 0 turns it off.', 'smart-product-recommendations' ), __( 'minutes', 'smart-product-recommendations' ) ); ?>
			</section>

			<section class="spr-panel">
				<h2><?php esc_html_e( 'Never recommend', 'smart-product-recommendations' ); ?></h2>
				<div class="spr-field">
					<label for="spr-exclude-ids"><?php esc_html_e( 'Product IDs', 'smart-product-recommendations' ); ?></label>
					<input type="text" id="spr-exclude-ids" name="spr_settings[exclude_ids]" value="<?php echo esc_attr( $s['exclude_ids'] ); ?>" dir="ltr" placeholder="12, 345, 6789">
				</div>
				<div class="spr-field">
					<label for="spr-exclude-cats"><?php esc_html_e( 'Categories (sub-categories included)', 'smart-product-recommendations' ); ?></label>
					<input type="search" class="spr-filter" form="spr-none" data-spr-filter="#spr-exclude-cats" placeholder="<?php esc_attr_e( 'Filter categories', 'smart-product-recommendations' ); ?>" aria-label="<?php esc_attr_e( 'Filter categories', 'smart-product-recommendations' ); ?>">
					<select id="spr-exclude-cats" name="spr_settings[exclude_cats][]" multiple size="7">
						<?php if ( ! is_wp_error( $cats ) ) : foreach ( $cats as $c ) : ?>
							<option value="<?php echo (int) $c->term_id; ?>" <?php selected( in_array( (int) $c->term_id, array_map( 'intval', (array) $s['exclude_cats'] ), true ) ); ?>><?php echo esc_html( $c->name . ' (' . number_format_i18n( $c->count ) . ')' ); ?></option>
						<?php endforeach; endif; ?>
					</select>
					<p class="spr-help"><?php esc_html_e( 'Hold Ctrl (⌘ on Mac) to select more than one.', 'smart-product-recommendations' ); ?></p>
				</div>
			</section>
		</div>
		<?php
		self::form_close();
	}

	/* ------------------------------------------------------------------ */
	/* Matching                                                           */
	/* ------------------------------------------------------------------ */

	private static function tab_algorithm() {
		$s      = SPR_Settings::all();
		$brands = SPR_Settings::brand_taxonomies();
		$low    = __( 'Ignore', 'smart-product-recommendations' );
		$high   = __( 'Very important', 'smart-product-recommendations' );
		self::form_open( 'algorithm' );
		?>
		<div class="spr-grid spr-grid-preview">
			<div class="spr-stack">
				<section class="spr-panel">
					<h2><?php esc_html_e( 'What makes two products similar', 'smart-product-recommendations' ); ?></h2>
					<p class="spr-help"><?php esc_html_e( 'Products are compared on what they share. Rare things in common (same brand, a specific category) count more than common ones.', 'smart-product-recommendations' ); ?></p>
					<?php
					self::slider( 'w_cat', $s['w_cat'], __( 'Same category', 'smart-product-recommendations' ), 0, 10, 0.5, '', $low, $high );
					self::slider( 'w_brand', $s['w_brand'], __( 'Same brand', 'smart-product-recommendations' ), 0, 10, 0.5, $brands ? '' : __( 'No brand taxonomy found. Brands stored as attributes or tags are covered by those weights.', 'smart-product-recommendations' ), $low, $high );
					self::slider( 'w_title', $s['w_title'], __( 'Words in the product name', 'smart-product-recommendations' ), 0, 10, 0.5, '', $low, $high );
					self::slider( 'w_attr', $s['w_attr'], __( 'Same attributes (color, size…)', 'smart-product-recommendations' ), 0, 10, 0.5, '', $low, $high );
					self::slider( 'w_tag', $s['w_tag'], __( 'Same tags', 'smart-product-recommendations' ), 0, 10, 0.5, '', $low, $high );
					self::slider( 'w_price', $s['w_price'], __( 'Similar price', 'smart-product-recommendations' ), 0, 1, 0.05, __( 'Higher values keep suggestions within the same price range.', 'smart-product-recommendations' ), $low, $high );
					self::slider( 'max_term_share', $s['max_term_share'], __( 'Ignore anything shared by more than this % of products', 'smart-product-recommendations' ), 1, 50, 1, __( 'A category holding a large part of the catalog says little about similarity and slows the build.', 'smart-product-recommendations' ) );
					?>
				</section>

				<section class="spr-panel">
					<h2><?php esc_html_e( 'Bought together', 'smart-product-recommendations' ); ?></h2>
					<?php
					self::number( 'min_support', $s['min_support'], __( 'Pair must appear in at least', 'smart-product-recommendations' ), 1, 1000, __( 'Higher values filter out coincidences.', 'smart-product-recommendations' ), __( 'orders', 'smart-product-recommendations' ) );
					self::number( 'lookback_days', $s['lookback_days'], __( 'Use orders from the last', 'smart-product-recommendations' ), 7, 3650, '', __( 'days', 'smart-product-recommendations' ) );
					?>
				</section>

				<section class="spr-panel">
					<h2 id="spr-h-sched"><?php esc_html_e( 'Build schedule', 'smart-product-recommendations' ); ?></h2>
					<div class="spr-seg" role="radiogroup" aria-labelledby="spr-h-sched">
						<?php foreach ( array( 'daily' => __( 'Every night', 'smart-product-recommendations' ), 'weekly' => __( 'Weekly', 'smart-product-recommendations' ), 'manual' => __( 'Only when I ask', 'smart-product-recommendations' ) ) as $k => $l ) : ?>
							<label><input type="radio" name="spr_settings[schedule]" value="<?php echo esc_attr( $k ); ?>" <?php checked( $s['schedule'], $k ); ?>><span><?php echo esc_html( $l ); ?></span></label>
						<?php endforeach; ?>
					</div>
					<p class="spr-help"><?php esc_html_e( 'Edited products update right after you save them, whatever the schedule.', 'smart-product-recommendations' ); ?></p>
					<details class="spr-details">
						<summary><?php esc_html_e( 'Advanced', 'smart-product-recommendations' ); ?></summary>
						<?php
						self::number( 'store_top', $s['store_top'], __( 'Matches kept per product', 'smart-product-recommendations' ), 4, 100 );
						self::number( 'batch_size', $s['batch_size'], __( 'Products per build step', 'smart-product-recommendations' ), 10, 1000, __( 'Lower it on slow shared hosting.', 'smart-product-recommendations' ) );
						?>
					</details>
				</section>
			</div>

			<aside class="spr-panel spr-inspector">
				<h2><?php esc_html_e( 'Check a product', 'smart-product-recommendations' ); ?></h2>
				<p class="spr-help"><?php esc_html_e( 'See what a product recommends and how strong each match is.', 'smart-product-recommendations' ); ?></p>
				<div class="spr-search">
					<input type="search" form="spr-none" data-spr-q placeholder="<?php esc_attr_e( 'Product ID or name', 'smart-product-recommendations' ); ?>" aria-label="<?php esc_attr_e( 'Product ID or name', 'smart-product-recommendations' ); ?>">
					<button type="button" class="spr-btn-ghost" data-spr-inspect><?php esc_html_e( 'Check', 'smart-product-recommendations' ); ?></button>
				</div>
				<div data-spr-inspect-out class="spr-inspect-out" aria-live="polite"></div>
			</aside>
		</div>
		<?php
		self::form_close();
	}

	/* ------------------------------------------------------------------ */
	/* Results                                                            */
	/* ------------------------------------------------------------------ */

	private static function totals( array $rows ) {
		$tot = array( 'impressions' => 0, 'clicks' => 0, 'orders' => 0, 'revenue' => 0.0 );
		foreach ( $rows as $r ) {
			foreach ( $tot as $k => $v ) {
				$tot[ $k ] += (float) $r[ $k ];
			}
		}
		return $tot;
	}

	private static function kpis( array $tot ) {
		$ctr   = $tot['impressions'] ? $tot['clicks'] / $tot['impressions'] * 100 : 0;
		$cards = array(
			array( __( 'Times shown', 'smart-product-recommendations' ), number_format_i18n( $tot['impressions'] ), '' ),
			array( __( 'Clicks', 'smart-product-recommendations' ), number_format_i18n( $tot['clicks'] ), sprintf( /* translators: %s: percent */ __( '%s%% of views', 'smart-product-recommendations' ), number_format_i18n( $ctr, 1 ) ) ),
			array( __( 'Orders from recommendations', 'smart-product-recommendations' ), number_format_i18n( $tot['orders'] ), '' ),
			array( __( 'Revenue from recommendations', 'smart-product-recommendations' ), html_entity_decode( wp_strip_all_tags( wc_price( $tot['revenue'] ) ), ENT_QUOTES, 'UTF-8' ), '' ),
		);
		echo '<dl class="spr-kpis">';
		foreach ( $cards as $c ) {
			echo '<div><dt>' . esc_html( $c[0] ) . '</dt><dd>' . esc_html( $c[1] ) . '</dd>' . ( $c[2] ? '<p>' . esc_html( $c[2] ) . '</p>' : '' ) . '</div>';
		}
		echo '</dl>';
	}

	private static function chart( array $daily, $days ) {
		$by = array();
		foreach ( $daily as $d ) {
			$by[ $d['day'] ] = $d;
		}
		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day      = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$series[] = array( $day, isset( $by[ $day ] ) ? (int) $by[ $day ]['clicks'] : 0, isset( $by[ $day ] ) ? (int) $by[ $day ]['orders'] : 0 );
		}
		$clicks = array_column( $series, 1 );
		if ( ! array_sum( $clicks ) ) {
			echo '<p class="spr-empty">' . esc_html__( 'No clicks yet. The chart fills in as shoppers use recommendations.', 'smart-product-recommendations' ) . '</p>';
			return;
		}
		$max = max( $clicks );
		echo '<figure class="spr-chart"><figcaption>' . esc_html__( 'Clicks per day. Days with an order from a recommendation are marked in gold.', 'smart-product-recommendations' ) . '</figcaption><div class="spr-bars">';
		foreach ( $series as $s ) {
			$label = wp_date( get_option( 'date_format' ), strtotime( $s[0] . ' 12:00:00' ) ) . ': ' . sprintf( /* translators: %s: clicks */ _n( '%s click', '%s clicks', $s[1], 'smart-product-recommendations' ), number_format_i18n( $s[1] ) );
			printf(
				'<span class="spr-bar%s" style="--h:%s%%" title="%s"><span class="screen-reader-text">%s</span></span>',
				$s[2] ? ' has-order' : '',
				esc_attr( round( 100 * $s[1] / $max ) ),
				esc_attr( $label ),
				esc_html( $label )
			);
		}
		echo '</div></figure>';
	}

	private static function tab_stats() {
		$days   = isset( $_GET['days'] ) ? max( 1, min( 365, absint( $_GET['days'] ) ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report = SPR_Tracking::report( $days );
		$tot    = self::totals( $report['rows'] );
		$labels = array();
		foreach ( SPR_Settings::placements() as $k => $p ) {
			$labels[ $k ] = $p['label'];
		}
		foreach ( SPR_Settings::sources() as $k => $l ) {
			$labels[ 'sc_' . $k ]     = sprintf( /* translators: %s: type */ __( 'Shortcode: %s', 'smart-product-recommendations' ), $l );
			$labels[ 'widget_' . $k ] = sprintf( /* translators: %s: type */ __( 'Widget: %s', 'smart-product-recommendations' ), $l );
		}
		$best = 0;
		foreach ( $report['rows'] as $r ) {
			$best = max( $best, $r['impressions'] ? $r['clicks'] / $r['impressions'] : 0 );
		}
		?>
		<section class="spr-panel">
			<div class="spr-panel-head">
				<h2><?php esc_html_e( 'Results', 'smart-product-recommendations' ); ?></h2>
				<nav class="spr-seg spr-seg-sm" aria-label="<?php esc_attr_e( 'Period', 'smart-product-recommendations' ); ?>">
					<?php foreach ( array( 7, 30, 90 ) as $d ) : ?>
						<a href="<?php echo esc_url( self::url( 'stats', array( 'days' => $d ) ) ); ?>" class="<?php echo $d === $days ? 'is-active' : ''; ?>" <?php echo $d === $days ? 'aria-current="true"' : ''; ?>><?php echo esc_html( sprintf( /* translators: %s: days */ _n( '%s day', '%s days', $d, 'smart-product-recommendations' ), number_format_i18n( $d ) ) ); ?></a>
					<?php endforeach; ?>
				</nav>
			</div>
			<?php self::kpis( $tot ); ?>
			<?php self::chart( $report['daily'], $days ); ?>
		</section>

		<section class="spr-panel">
			<h2><?php esc_html_e( 'By location', 'smart-product-recommendations' ); ?></h2>
			<?php if ( ! $report['rows'] ) : ?>
				<p class="spr-empty"><?php esc_html_e( 'Nothing to compare yet. Each location gets a row once shoppers see it.', 'smart-product-recommendations' ); ?></p>
			<?php else : ?>
				<div class="spr-table-wrap">
				<table class="spr-table">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Location', 'smart-product-recommendations' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Times shown', 'smart-product-recommendations' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Clicks', 'smart-product-recommendations' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Click rate', 'smart-product-recommendations' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Orders', 'smart-product-recommendations' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Revenue', 'smart-product-recommendations' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $report['rows'] as $r ) : $ctr = $r['impressions'] ? $r['clicks'] / $r['impressions'] : 0; ?>
						<tr>
							<th scope="row"><?php echo esc_html( isset( $labels[ $r['placement'] ] ) ? $labels[ $r['placement'] ] : $r['placement'] ); ?></th>
							<td><?php echo esc_html( number_format_i18n( $r['impressions'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $r['clicks'] ) ); ?></td>
							<td class="spr-ctr"><span class="spr-meter" style="--w:<?php echo esc_attr( $best ? round( 100 * $ctr / $best ) : 0 ); ?>%" aria-hidden="true"></span><?php echo esc_html( number_format_i18n( $ctr * 100, 1 ) ); ?>%</td>
							<td><?php echo esc_html( number_format_i18n( $r['orders'] ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $r['revenue'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			<?php endif; ?>
			<p class="spr-help"><?php esc_html_e( 'An order counts for a location when the shopper clicked a product there in the 30 days before buying it.', 'smart-product-recommendations' ); ?></p>
		</section>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Shortcode builder                                                  */
	/* ------------------------------------------------------------------ */

	private static function tab_help() {
		$sources = SPR_Settings::sources();
		$desc    = array(
			'similar'  => __( 'Like the product being viewed. Use on product pages, or set a product ID.', 'smart-product-recommendations' ),
			'bought'   => __( 'Often bought with the product being viewed.', 'smart-product-recommendations' ),
			'viewed'   => __( 'What this visitor looked at recently.', 'smart-product-recommendations' ),
			'for_you'  => __( 'Picked from this visitor\'s browsing; best sellers for new visitors. Good for the home page.', 'smart-product-recommendations' ),
			'trending' => __( 'Most viewed this week.', 'smart-product-recommendations' ),
			'cart'     => __( 'Based on what is in the cart.', 'smart-product-recommendations' ),
		);
		$rest = rest_url( SPR_REST::NS . '/recommendations' );
		?>
		<div class="spr-grid spr-grid-wide">
			<section class="spr-panel spr-span spr-builder" data-spr-builder>
				<h2><?php esc_html_e( 'Add recommendations anywhere', 'smart-product-recommendations' ); ?></h2>
				<p class="spr-help"><?php esc_html_e( 'Pick the options, copy the shortcode and paste it into a page, a post or a Shortcode block.', 'smart-product-recommendations' ); ?></p>
				<div class="spr-builder-grid">
					<fieldset class="spr-sources">
						<legend><?php esc_html_e( 'What to show', 'smart-product-recommendations' ); ?></legend>
						<?php foreach ( $sources as $k => $label ) : ?>
							<label class="spr-source">
								<input type="radio" name="b_source" value="<?php echo esc_attr( $k ); ?>" <?php checked( 'for_you', $k ); ?>>
								<span><strong><?php echo esc_html( $label ); ?></strong><small><?php echo esc_html( $desc[ $k ] ); ?></small></span>
							</label>
						<?php endforeach; ?>
					</fieldset>
					<div class="spr-builder-opts">
						<div class="spr-field"><label for="b-title"><?php esc_html_e( 'Title', 'smart-product-recommendations' ); ?></label><input type="text" id="b-title" placeholder="<?php esc_attr_e( 'Default title', 'smart-product-recommendations' ); ?>"></div>
						<div class="spr-field spr-field-inline"><label for="b-limit"><?php esc_html_e( 'Products', 'smart-product-recommendations' ); ?></label><span class="spr-num"><input type="number" id="b-limit" value="8" min="1" max="24"></span></div>
						<div class="spr-field spr-field-inline"><label for="b-cols"><?php esc_html_e( 'Per row', 'smart-product-recommendations' ); ?></label><span class="spr-num"><input type="number" id="b-cols" value="4" min="1" max="6"></span></div>
						<div class="spr-seg" role="radiogroup" aria-label="<?php esc_attr_e( 'Layout', 'smart-product-recommendations' ); ?>">
							<label><input type="radio" name="b_layout" value="" checked><span><?php esc_html_e( 'As in settings', 'smart-product-recommendations' ); ?></span></label>
							<label><input type="radio" name="b_layout" value="carousel"><span><?php esc_html_e( 'Slider', 'smart-product-recommendations' ); ?></span></label>
							<label><input type="radio" name="b_layout" value="grid"><span><?php esc_html_e( 'Grid', 'smart-product-recommendations' ); ?></span></label>
						</div>
						<div class="spr-field" data-spr-pid-field hidden><label for="b-pid"><?php esc_html_e( 'Product ID (optional)', 'smart-product-recommendations' ); ?></label><input type="number" id="b-pid" min="1" dir="ltr"></div>
						<div class="spr-code">
							<code dir="ltr" data-spr-code></code>
							<button type="button" class="spr-btn-primary" data-spr-copy><?php esc_html_e( 'Copy', 'smart-product-recommendations' ); ?></button>
						</div>
					</div>
				</div>
			</section>

			<section class="spr-panel">
				<h2><?php esc_html_e( 'In theme files', 'smart-product-recommendations' ); ?></h2>
				<pre dir="ltr"><code>&lt;?php echo do_shortcode( '[spr_recommendations source="trending"]' ); ?&gt;</code></pre>
				<p class="spr-help"><?php esc_html_e( 'Change the card markup with yourtheme/smart-product-recommendations/card.php.', 'smart-product-recommendations' ); ?></p>
			</section>

			<section class="spr-panel">
				<h2><?php esc_html_e( 'Apps and headless stores', 'smart-product-recommendations' ); ?></h2>
				<pre dir="ltr"><code>GET <?php echo esc_html( $rest ); ?>?source=similar&amp;product_id=123</code></pre>
				<pre dir="ltr"><code>wp spr rebuild
wp spr product 123</code></pre>
			</section>
		</div>
		<?php
	}
}
