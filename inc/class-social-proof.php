<?php
/**
 * Ethical Social Proof Aggregator with 1800s Transient Caching and REST API.
 *
 * Compliant with UWG § 5b and DSGVO data minimization:
 * - Aggregates only authentic completed/processing WooCommerce orders.
 * - Strictly strips all PII (names, street addresses, order numbers).
 * - Formats notification: "Ein Motiv aus [Stadt] hat ein Format [Format] bestellt".
 * - Caches in transient for 1800 seconds to eliminate database overhead.
 * - REST API endpoint: GET /wp-json/ortszeichen-marketing/v1/social-proof
 *
 * @package Ortszeichen\Marketing
 */

namespace Ortszeichen\Marketing;

defined( 'ABSPATH' ) || exit;

class Social_Proof {

	const TRANSIENT_KEY = 'oz_marketing_social_proof';
	const TRANSIENT_EXPIRATION = 1800; // 30 minutes (1800s)
	const REST_NAMESPACE = 'ortszeichen-marketing/v1';
	const REST_ROUTE = '/social-proof';

	/**
	 * Boot hooks.
	 */
	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'invalidate_cache' ) );
		add_action( 'woocommerce_new_order', array( __CLASS__, 'invalidate_cache' ) );
	}

	/**
	 * Register REST API route.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_social_proof_endpoint' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST API endpoint callback.
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response
	 */
	public static function get_social_proof_endpoint( $request = null ): \WP_REST_Response {
		$data = self::get_social_proof_data();
		return new \WP_REST_Response(
			array(
				'success'          => true,
				'recent_purchases' => $data,
				'cached'           => ( false !== get_transient( self::TRANSIENT_KEY ) ),
			),
			200
		);
	}

	/**
	 * Retrieve social proof data, utilizing 1800s transient cache.
	 *
	 * @param bool $force_refresh Skip cache if true
	 * @return array Array of anonymized social proof items
	 */
	public static function get_social_proof_data( bool $force_refresh = false ): array {
		if ( 'yes' !== get_option( 'oz_social_proof_operator_approved', 'no' ) ) { return array(); }
		if ( ! $force_refresh ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( false !== $cached && is_array( $cached ) ) {
				return $cached;
			}
		}

		$orders = array();
		if ( function_exists( 'wc_get_orders' ) ) {
			try {
				$orders = wc_get_orders(
					array(
						'status'  => array( 'wc-completed', 'wc-processing' ),
						'limit'   => 10,
						'orderby' => 'date',
						'order'   => 'DESC',
					)
				);
			} catch ( \Throwable $e ) {
				$orders = array();
			}
		}

		$items = array();
		$now   = time();

		if ( ! empty( $orders ) ) {
			foreach ( $orders as $order ) {
				if ( ! is_object( $order ) ) {
					continue;
				}

				// Show the product city only; never disclose customer address data.
				$city = '';
				// Extract items
				$order_items = method_exists( $order, 'get_items' ) ? $order->get_items() : array();
				$product_title = 'Stadtposter';
				$format        = '';

				foreach ( $order_items as $order_item ) {
					if ( ! is_object( $order_item ) ) {
						continue;
					}

					$name = method_exists( $order_item, 'get_name' ) ? $order_item->get_name() : '';
					if ( ! empty( $name ) ) {
						$product_title = $name;
					}

					// Attempt to extract format from variation attributes or item name
					$variation_format = '';
					if ( method_exists( $order_item, 'get_meta' ) ) {
						$variation_format = (string) $order_item->get_meta( 'pa_format' );
						if ( empty( $variation_format ) ) {
							$variation_format = (string) $order_item->get_meta( 'format' );
						}
					}

					if ( ! empty( $variation_format ) ) {
						$format = $variation_format;
					} elseif ( preg_match( '/\b(50\s?[x×]\s?70|70\s?[x×]\s?100|A[1-4])\b/i', $name, $matches ) ) {
						$format = str_replace( ' ', '', $matches[1] );
					}

					// If order has no city yet, attempt to extract from product's oz_city taxonomy
					if ( empty( $city ) && method_exists( $order_item, 'get_product_id' ) ) {
						$pid   = $order_item->get_product_id();
						$terms = function_exists( 'get_the_terms' ) ? get_the_terms( $pid, 'oz_city' ) : false;
						if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
							$city = $terms[0]->name;
						}
					}
					break;
				}

				if ( empty( $city ) || empty( $format ) ) { continue; }

				// Sanitize city and format against XSS
				$city   = htmlspecialchars( $city, ENT_QUOTES, 'UTF-8' );
				$format = htmlspecialchars( $format, ENT_QUOTES, 'UTF-8' );

				// Relative time calculation
				$date_created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
				$timestamp    = $date_created && method_exists( $date_created, 'getTimestamp' ) ? $date_created->getTimestamp() : $now;
				$diff         = max( 60, $now - $timestamp );

				if ( $diff < 3600 ) {
					$time_ago = sprintf( 'vor %d Minuten', max( 1, (int) floor( $diff / 60 ) ) );
				} elseif ( $diff < 86400 ) {
					$hours    = (int) floor( $diff / 3600 );
					$time_ago = ( 1 === $hours ) ? 'vor 1 Stunde' : sprintf( 'vor %d Stunden', $hours );
				} else {
					$days     = (int) floor( $diff / 86400 );
					$time_ago = ( 1 === $days ) ? 'gestern' : sprintf( 'vor %d Tagen', $days );
				}

				// Standardized message per specification
				$message = sprintf( 'Ein Motiv aus %s wurde im Format %s bestellt', $city, $format );

				$items[] = array(
					'city'      => $city,
					'format'    => $format,
					'product'   => htmlspecialchars( $product_title, ENT_QUOTES, 'UTF-8' ),
					'message'   => $message,
					'time_ago'  => $time_ago,
					'timestamp' => $timestamp,
				);
			}
		}

		// Cache in transient for 1800s
		set_transient( self::TRANSIENT_KEY, $items, self::TRANSIENT_EXPIRATION );

		return $items;
	}

	/**
	 * Honest store facts fallback (UWG § 5b compliant: no fake customer names or fake orders).
	 *
	 * @return array
	 */
	public static function get_authentic_store_facts(): array {
		return array(); // No unverified paper, manufacturer or shipping claims.
	}

	/**
	 * Invalidate transient cache.
	 */
	public static function invalidate_cache(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}
