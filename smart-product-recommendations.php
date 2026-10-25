<?php
/**
 * Plugin Name:       Smart Product Recommendations for WooCommerce
 * Description:       Fast, scalable product recommendations for large WooCommerce stores: similar products, frequently bought together, recently viewed, personalized "for you" and trending — computed in the background, loaded via AJAX, with click/conversion analytics.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Moh
 * Text Domain:       smart-product-recommendations
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   10.2
 *
 * @package SPR
 */

defined( 'ABSPATH' ) || exit;

define( 'SPR_VERSION', '1.1.0' );
define( 'SPR_DB_VERSION', '1' );
define( 'SPR_FILE', __FILE__ );
define( 'SPR_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPR_URL', plugin_dir_url( __FILE__ ) );

// Relation types stored in {prefix}spr_relations.type.
define( 'SPR_REL_SIMILAR', 1 );
define( 'SPR_REL_BOUGHT', 2 );

require_once SPR_DIR . 'includes/class-spr-install.php';
require_once SPR_DIR . 'includes/class-spr-settings.php';
require_once SPR_DIR . 'includes/class-spr-tokenizer.php';
require_once SPR_DIR . 'includes/class-spr-indexer.php';
require_once SPR_DIR . 'includes/class-spr-engine.php';
require_once SPR_DIR . 'includes/class-spr-renderer.php';
require_once SPR_DIR . 'includes/class-spr-tracking.php';
require_once SPR_DIR . 'includes/class-spr-rest.php';
require_once SPR_DIR . 'includes/class-spr-frontend.php';
require_once SPR_DIR . 'includes/class-spr-widget.php';
require_once SPR_DIR . 'includes/class-spr-admin.php';

register_activation_hook( __FILE__, array( 'SPR_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SPR_Install', 'deactivate' ) );

// Declare compatibility with WooCommerce HPOS (custom order tables) and the block cart/checkout.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'smart-product-recommendations', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Smart Product Recommendations requires WooCommerce to be installed and active.', 'smart-product-recommendations' ) . '</p></div>';
				}
			);
			return;
		}

		SPR_Install::maybe_upgrade();
		SPR_Indexer::init();
		SPR_Tracking::init();
		SPR_REST::init();
		SPR_Frontend::init();
		SPR_Admin::init();

		add_action(
			'widgets_init',
			function () {
				register_widget( 'SPR_Widget' );
			}
		);

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once SPR_DIR . 'includes/class-spr-cli.php';
			WP_CLI::add_command( 'spr', 'SPR_CLI' );
		}
	},
	20
);
