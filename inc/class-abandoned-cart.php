<?php
/**
 * Abandoned Cart Event Tracking, CPT Storage, Action Scheduler & 30-Day Purge.
 *
 * Captures cart state and line items without blocking checkout.
 * Enforces GDPR data minimization with automated 30-day retention purging.
 *
 * @package Ortszeichen\Marketing
 */

namespace Ortszeichen\Marketing;

defined( 'ABSPATH' ) || exit;

class Abandoned_Cart {

	const CPT = 'oz_cart_event';
	const ACTION_CHECK = 'ortszeichen_marketing_abandoned_cart_check';
	const ACTION_PURGE = 'ortszeichen_marketing_daily_purge';
	const DEFAULT_CUTOFF_SECONDS = 3600; // 1 hour cutoff for abandonment

	/**
	 * Boot hooks and action handlers.
	 */
	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'on_cart_updated' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_processed' ), 10, 3 );
		add_action( self::ACTION_CHECK, array( __CLASS__, 'process_cart_check' ) );
		add_action( self::ACTION_PURGE, array( __CLASS__, 'purge_routine' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_restore_cart' ) );
	}

	/**
	 * Register the private Custom Post Type 'oz_cart_event'.
	 */
	public static function register_cpt(): void {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array(
					'name'          => 'Warenkorb-Ereignisse',
					'singular_name' => 'Warenkorb-Ereignis',
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'woocommerce',
				'capability_type' => 'shop_order',
				'capabilities'    => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'custom-fields' ),
			)
		);
	}

	/**
	 * Cart update listener. Extracts session hash and cart payload safely without blocking.
	 */
	public static function on_cart_updated(): void {
		try {
			if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart || WC()->cart->is_empty() ) {
				return;
			}

			$session_hash = '';
			if ( WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
				$customer_id = (string) WC()->session->get_customer_id();
				if ( ! empty( $customer_id ) ) {
					$session_hash = md5( 'oz_cart_' . $customer_id );
				}
			}

			if ( empty( $session_hash ) ) { return; }

			$cart_items = array();
			foreach ( WC()->cart->get_cart() as $cart_item_key => $item ) {
				$product = $item['data'] ?? null;
				if ( ! $product ) {
					continue;
				}

				$cart_items[] = array(
					'key'          => $cart_item_key,
					'product_id'   => $item['product_id'] ?? 0,
					'variation_id' => $item['variation_id'] ?? 0,
					'sku'          => is_object( $product ) && method_exists( $product, 'get_sku' ) ? $product->get_sku() : '',
					'name'         => is_object( $product ) && method_exists( $product, 'get_name' ) ? $product->get_name() : '',
					'quantity'     => $item['quantity'] ?? 1,
					'price'        => is_object( $product ) && method_exists( $product, 'get_price' ) ? (float) $product->get_price() : 0.0,
					'line_total'   => (float) ( $item['line_total'] ?? 0.0 ),
					'city'         => $item['oz_city'] ?? ( $item['variation']['attribute_pa_stadt'] ?? '' ),
					'format'       => $item['oz_format'] ?? ( $item['variation']['attribute_pa_format'] ?? '' ),
				);
			}

			if ( empty( $cart_items ) ) {
				return;
			}

			$total = (float) WC()->cart->get_total( 'edit' );

			$email = '';
			if ( is_user_logged_in() ) {
				$current_user = wp_get_current_user();
				$email        = $current_user->user_email ?? '';
			} elseif ( WC()->customer && method_exists( WC()->customer, 'get_billing_email' ) ) {
				$email = (string) WC()->customer->get_billing_email();
			}

			self::track_cart( $session_hash, $cart_items, $total, $email );
		} catch ( \Throwable $e ) {
			// Fail-safe: marketing tracking failure must never block customer cart or checkout
			if ( function_exists( 'error_log' ) ) {
				error_log( 'ORTSZEICHEN Marketing: Failed to track cart.' );
			}
		}
	}

	/**
	 * Record or update a cart event idempotently on cart change.
	 *
	 * @param string $session_hash Session hash identifier
	 * @param array  $cart_items   Array of item data
	 * @param float  $total        Total amount
	 * @param string $email        Customer email if captured
	 * @return int Created or updated post ID
	 */
	public static function track_cart( string $session_hash, array $cart_items, float $total, string $email = '' ): int {
		// Explicit session consent is required for marketing storage; never infer it from checkout.
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session || 'yes' !== WC()->session->get( 'oz_cart_marketing_consent' ) ) { return 0; }
		if ( empty( $session_hash ) || empty( $cart_items ) ) {
			return 0;
		}

		// Look for existing active record for this session hash
		$existing = get_posts(
			array(
				'post_type'   => self::CPT,
				'meta_key'    => '_oz_cart_hash',
				'meta_value'  => $session_hash,
				'post_status' => 'any',
			)
		);

		$post_id = 0;
		if ( ! empty( $existing ) ) {
			$post_id = $existing[0]->ID;
		} else {
			$post_id = wp_insert_post(
				array(
					'post_type'   => self::CPT,
					'post_title'  => 'Cart #' . substr( $session_hash, 0, 8 ) . ' - ' . date( 'd.m.Y H:i' ),
					'post_status' => 'publish',
					'post_date'   => date( 'Y-m-d H:i:s' ),
				)
			);
		}

		if ( ! $post_id ) {
			return 0;
		}

		// Store required metadata
		update_post_meta( $post_id, '_oz_cart_hash', $session_hash );
		update_post_meta( $post_id, '_oz_cart_items', $cart_items );
		update_post_meta( $post_id, '_oz_cart_total', $total );
		update_post_meta( $post_id, '_oz_cart_status', 'active' );
		update_post_meta( $post_id, '_oz_cart_updated', time() );

		// Recovery token for 1-click cart restoration
		$existing_token = get_post_meta( $post_id, '_oz_recovery_token', true );
		if ( empty( $existing_token ) ) {
			$recovery_token = function_exists( 'wp_generate_password' )
				? wp_generate_password( 32, false )
				: bin2hex( random_bytes( 16 ) );
			update_post_meta( $post_id, '_oz_recovery_token', $recovery_token );
		}

		if ( ! empty( $email ) ) {
			$clean_email = filter_var( $email, FILTER_SANITIZE_EMAIL );
			if ( ! empty( $clean_email ) ) {
				update_post_meta( $post_id, '_oz_cart_email', $clean_email );
			}
		}

		// Queue Action Scheduler task if not already queued
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			$hook_args = array( 'cart_event_id' => $post_id );
			if ( ! as_has_scheduled_action( self::ACTION_CHECK, $hook_args ) ) {
				as_schedule_single_action( time() + ( 30 * 60 ), self::ACTION_CHECK, $hook_args );
			}
		}

		return $post_id;
	}

	/**
	 * Transition status to abandoned if past cutoff.
	 *
	 * @param int $post_id Cart event ID
	 * @return bool True if transitioned, false otherwise
	 */
	public static function mark_abandoned( int $post_id ): bool {
		$status = get_post_meta( $post_id, '_oz_cart_status', true );
		if ( 'active' === $status ) {
			update_post_meta( $post_id, '_oz_cart_status', 'abandoned' );
			update_post_meta( $post_id, '_oz_abandoned_at', time() );
			if ( function_exists( 'do_action' ) ) {
				do_action( 'ortszeichen_marketing_cart_abandoned', $post_id );
			}
			return true;
		}
		return false;
	}

	/**
	 * Transition status to recovered upon order placement.
	 *
	 * @param int $post_id  Cart event ID
	 * @param int $order_id WooCommerce Order ID
	 * @return bool True if transitioned
	 */
	public static function mark_recovered( int $post_id, int $order_id ): bool {
		update_post_meta( $post_id, '_oz_cart_status', 'recovered' );
		update_post_meta( $post_id, '_oz_recovered_order_id', $order_id );
		update_post_meta( $post_id, '_oz_recovered_at', time() );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'ortszeichen_marketing_cart_recovered', $post_id, $order_id );
		}
		return true;
	}

	/**
	 * Hook on order processed: match cart event by session and mark recovered.
	 *
	 * @param int    $order_id    Order ID
	 * @param array  $posted_data Form data
	 * @param object $order       Order object
	 */
	public static function on_order_processed( int $order_id, array $posted_data = array(), $order = null ): void {
		try {
			$session_hash = '';
			if ( function_exists( 'WC' ) && WC() && WC()->session && method_exists( WC()->session, 'get_customer_id' ) ) {
				$customer_id = (string) WC()->session->get_customer_id();
				if ( ! empty( $customer_id ) ) {
					$session_hash = md5( 'oz_cart_' . $customer_id );
				}
			}

			if ( ! empty( $session_hash ) ) {
				$events = get_posts(
					array(
						'post_type'   => self::CPT,
						'meta_key'    => '_oz_cart_hash',
						'meta_value'  => $session_hash,
						'post_status' => 'any',
					)
				);
				foreach ( $events as $event ) {
					self::mark_recovered( $event->ID, $order_id );
				}
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( 'ORTSZEICHEN Marketing: Order recovery hook error: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Action Scheduler callback for checking a cart event or batch of events.
	 *
	 * @param array|int $args Argument array or cart event ID
	 */
	public static function process_cart_check( $args = null ): void {
		$event_id = is_array( $args ) ? ( $args['cart_event_id'] ?? 0 ) : (int) $args;
		if ( $event_id > 0 ) {
			$updated = (int) get_post_meta( $event_id, '_oz_cart_updated', true );
			if ( $updated > 0 && ( time() - $updated ) >= self::DEFAULT_CUTOFF_SECONDS ) {
				self::mark_abandoned( $event_id );
			}
		} else {
			self::scan( self::DEFAULT_CUTOFF_SECONDS );
		}
	}

	/**
	 * Scan active cart events and mark those exceeding cutoff as abandoned.
	 *
	 * @param int $cutoff_seconds Seconds of inactivity to qualify as abandoned
	 * @return int Number of carts marked abandoned
	 */
	public static function scan( int $cutoff_seconds = self::DEFAULT_CUTOFF_SECONDS ): int {
		$active_events = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'any',
				'meta_key'    => '_oz_cart_status',
				'meta_value'  => 'active',
				'numberposts' => 100,
			)
		);

		$marked = 0;
		$now    = time();
		foreach ( $active_events as $event ) {
			$last_updated = (int) get_post_meta( $event->ID, '_oz_cart_updated', true );
			if ( 0 === $last_updated ) {
				$last_updated = strtotime( $event->post_date );
			}
			if ( ( $now - $last_updated ) >= $cutoff_seconds ) {
				if ( self::mark_abandoned( $event->ID ) ) {
					++$marked;
				}
			}
		}
		return $marked;
	}

	/**
	 * Daily scheduled purge callback.
	 */
	public static function purge_routine(): void {
		self::purge_old_events( 30 );
	}

	/**
	 * Purge cart events older than $days (GDPR data minimization).
	 *
	 * @param int $days Cutoff in days (default 30)
	 * @return int Number of purged events
	 */
	public static function purge_old_events( int $days = 30 ): int {
		$cutoff_time = strtotime( "-{$days} days" );
		$all_events  = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);

		$purged = 0;
		foreach ( $all_events as $event ) {
			$created_timestamp = strtotime( $event->post_date );
			if ( $created_timestamp < $cutoff_time ) {
				wp_delete_post( $event->ID, true );
				++$purged;
			}
		}
		return $purged;
	}

	/**
	 * Restore abandoned cart contents via 1-click token link.
	 */
	public static function maybe_restore_cart(): void {
		if ( empty( $_GET['oz_recover_cart'] ) || ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return;
		}

		$raw_token = function_exists( 'wp_unslash' ) ? wp_unslash( $_GET['oz_recover_cart'] ) : $_GET['oz_recover_cart'];
		$token     = sanitize_text_field( $raw_token );
		$posts = get_posts(
			array(
				'post_type'   => self::CPT,
				'meta_key'    => '_oz_recovery_token',
				'meta_value'  => $token,
				'post_status' => 'any',
				'numberposts' => 1,
			)
		);

		if ( empty( $posts ) ) {
			return;
		}

		$cart_id    = $posts[0]->ID;
		$cart_items = get_post_meta( $cart_id, '_oz_cart_items', true );

		if ( ! is_array( $cart_items ) ) {
			return;
		}

		WC()->cart->empty_cart();
		foreach ( $cart_items as $item ) {
			$product_id   = (int) ( $item['product_id'] ?? 0 );
			$variation_id = (int) ( $item['variation_id'] ?? 0 );
			$quantity     = (int) ( $item['quantity'] ?? 1 );

			if ( $product_id > 0 ) {
				WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );
			}
		}

		$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
		wp_safe_redirect( $cart_url );
		exit;
	}

	/**
	 * Get summary statistics for WP-CLI and admin reporting.
	 *
	 * @return array
	 */
	public static function get_status_summary(): array {
		$all_events = get_posts(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);

		$summary = array(
			'total'             => count( $all_events ),
			'active'            => 0,
			'abandoned'         => 0,
			'recovered'         => 0,
			'recovered_revenue' => 0.0,
		);

		foreach ( $all_events as $event ) {
			$status = get_post_meta( $event->ID, '_oz_cart_status', true );
			$total  = (float) get_post_meta( $event->ID, '_oz_cart_total', true );

			if ( isset( $summary[ $status ] ) ) {
				++$summary[ $status ];
			}
			if ( 'recovered' === $status ) {
				$summary['recovered_revenue'] += $total;
			}
		}

		return $summary;
	}

	/**
	 * Retrieve events with optional filters.
	 *
	 * @param array $args Filter arguments
	 * @return array
	 */
	public static function get_events( array $args = array() ): array {
		$query = array(
			'post_type'   => self::CPT,
			'post_status' => 'any',
			'numberposts' => $args['limit'] ?? 20,
			'orderby'     => 'date',
			'order'       => 'DESC',
		);

		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$query['meta_key']   = '_oz_cart_status';
			$query['meta_value'] = $args['status'];
		}

		return get_posts( $query );
	}
}
