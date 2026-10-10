<?php
/**
 * Plugin Name: ORTSZEICHEN Marketing
 * Description: DSGVO-konforme Marketing-Automation: Abandoned Cart Recovery, dezent-authentischer Social Proof und Newsletter Double Opt-In.
 * Version: 1.0.0
 * Requires at least: 7.1
 * Requires PHP: 8.2
 * Requires Plugins: woocommerce
 * Text Domain: ortszeichen-marketing
 * License: GPL-2.0-or-later
 *
 * @package Ortszeichen\Marketing
 */

namespace Ortszeichen\Marketing;

defined( 'ABSPATH' ) || exit;

define( 'OZ_MARKETING_VERSION', '1.0.0' );
define( 'OZ_MARKETING_FILE', __FILE__ );
define( 'OZ_MARKETING_DIR', __DIR__ );
define( 'OZ_MARKETING_URL', plugin_dir_url( __FILE__ ) );

// Require module classes
foreach ( array( 'class-abandoned-cart', 'class-social-proof', 'class-newsletter', 'class-privacy' ) as $oz_module ) {
	$oz_module_path = __DIR__ . '/inc/' . $oz_module . '.php';
	if ( file_exists( $oz_module_path ) ) {
		require_once $oz_module_path;
	}
}

// Declare WooCommerce HPOS and Block compatibility
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

// Boot plugin on plugins_loaded
add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'ortszeichen-marketing', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		Abandoned_Cart::boot();
		Social_Proof::boot();
		Newsletter::boot();
		Privacy::boot();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once __DIR__ . '/inc/class-cli.php';
			CLI::register();
		}
	}
);

// Activation Hook
register_activation_hook(
	__FILE__,
	static function () {
		Abandoned_Cart::register_cpt();

		// Schedule recurring Action Scheduler jobs if available
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( Abandoned_Cart::ACTION_CHECK ) ) {
				as_schedule_recurring_action( time() + 900, 900, Abandoned_Cart::ACTION_CHECK, array(), 'ortszeichen-marketing' );
			}
			if ( ! as_has_scheduled_action( Abandoned_Cart::ACTION_PURGE ) ) {
				as_schedule_recurring_action( time() + 86400, 86400, Abandoned_Cart::ACTION_PURGE, array(), 'ortszeichen-marketing' );
			}
		} else {
			// Fallback to standard WP-Cron
			if ( ! wp_next_scheduled( Abandoned_Cart::ACTION_CHECK ) ) {
				wp_schedule_event( time() + 900, 'hourly', Abandoned_Cart::ACTION_CHECK );
			}
			if ( ! wp_next_scheduled( Abandoned_Cart::ACTION_PURGE ) ) {
				wp_schedule_event( time() + 86400, 'daily', Abandoned_Cart::ACTION_PURGE );
			}
		}

		flush_rewrite_rules();
	}
);

// Deactivation Hook
register_deactivation_hook(
	__FILE__,
	static function () {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Abandoned_Cart::ACTION_CHECK, array(), 'ortszeichen-marketing' );
			as_unschedule_all_actions( Abandoned_Cart::ACTION_PURGE, array(), 'ortszeichen-marketing' );
		}

		wp_clear_scheduled_hook( 'oz_newsletter_pending_cleanup' );
		wp_clear_scheduled_hook( Abandoned_Cart::ACTION_CHECK );
		wp_clear_scheduled_hook( Abandoned_Cart::ACTION_PURGE );

		delete_transient( Social_Proof::TRANSIENT_KEY );

		flush_rewrite_rules();
	}
);

// Enqueue lightweight frontend assets (suppressed on cart/checkout/account)
add_action(
	'wp_enqueue_scripts',
	static function () {
		// Strict suppression on conversion-critical checkout pages to prevent distraction
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return;
		}

		$css_file = __DIR__ . '/assets/css/marketing-frontend.css';
		$js_file  = __DIR__ . '/assets/js/marketing-frontend.js';

		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'oz-marketing-frontend',
				OZ_MARKETING_URL . 'assets/css/marketing-frontend.css',
				array(),
				OZ_MARKETING_VERSION
			);
		}

		if ( file_exists( $js_file ) ) {
			wp_enqueue_script(
				'oz-marketing-frontend',
				OZ_MARKETING_URL . 'assets/js/marketing-frontend.js',
				array(),
				OZ_MARKETING_VERSION,
				true
			);

			wp_localize_script(
				'oz-marketing-frontend',
				'ozMarketingData',
				array(
					'restUrl'       => esc_url_raw( rest_url( 'ortszeichen-marketing/v1/' ) ),
					'nonce'         => wp_create_nonce( 'wp_rest' ),
					'initialDelay'  => 45000, // 45s initial delay per UX benchmark
					'toastDuration' => 6000,
				)
			);
		}
	}
);
