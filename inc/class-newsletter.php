<?php
/**
 * Newsletter Double Opt-In (DOI) Handler, Consent Logger & REST API.
 *
 * Compliant with UWG § 7 and DSGVO / GDPR:
 * - Requires affirmative, unambiguous consent.
 * - Records consent audit trail (timestamp, anonymized IP, consent copy).
 * - Issues cryptographically secure Double Opt-In verification tokens.
 * - REST API endpoint: POST /wp-json/ortszeichen-marketing/v1/newsletter
 *
 * @package Ortszeichen\Marketing
 */

namespace Ortszeichen\Marketing;

defined( 'ABSPATH' ) || exit;

class Newsletter {

	const OPTION_SUBSCRIBERS = 'oz_newsletter_subscribers';
	const REST_NAMESPACE = 'ortszeichen-marketing/v1';
	const REST_ROUTE = '/newsletter';
	const RATE_LIMIT_MAX = 5; // Max 5 submissions per IP per hour

	/**
	 * Boot hooks.
	 */
	public static function boot(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'init', array( __CLASS__, 'maybe_handle_doi_confirmation' ) );
	}

	/**
	 * Register REST API route.
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_rest_registration' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'      => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => static function ( $param ) {
							return is_string( $param ) && is_email( trim( $param ) );
						},
					),
					'consent'    => array(
						'required' => true,
					),
					'first_name' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Handle REST API newsletter signup submission.
	 *
	 * @param \WP_REST_Request $request Request object
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_rest_registration( $request ) {
		$email      = sanitize_email( $request->get_param( 'email' ) );
		$consent    = $request->get_param( 'consent' );
		$first_name = sanitize_text_field( $request->get_param( 'first_name' ) ?? '' );

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new \WP_Error(
				'invalid_email',
				'Bitte geben Sie eine gültige E-Mail-Adresse ein.',
				array( 'status' => 400 )
			);
		}

		$consent_valid = ( true === $consent || '1' === $consent || 1 === $consent || 'true' === $consent || 'yes' === $consent );
		if ( ! $consent_valid ) {
			return new \WP_Error(
				'consent_required',
				'Die Einwilligung zur Datenschutzerklärung ist erforderlich (UWG § 7).',
				array( 'status' => 400 )
			);
		}

		// Rate limiting check by client IP
		$client_ip = self::get_client_ip();
		if ( self::is_rate_limited( $client_ip ) ) {
			return new \WP_Error(
				'rate_limited',
				'Zu viele Anfragen. Bitte versuchen Sie es später erneut.',
				array( 'status' => 429 )
			);
		}

		// Register subscriber and generate DOI token
		$result = self::register_subscriber( $email, $first_name, $client_ip, true );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => 'Vielen Dank! Wir haben Ihnen einen Bestätigungslink per E-Mail geschickt.',
			),
			200
		);
	}

	/**
	 * Register a pending subscriber and send DOI confirmation email.
	 *
	 * @param string $email      Email address
	 * @param string $first_name First name
	 * @param string $client_ip  Client IP address
	 * @return array|\WP_Error
	 */
	public static function register_subscriber( string $email, string $first_name = '', string $client_ip = '', bool $consent = false ) {
		if ( ! $consent ) { return new \WP_Error( 'consent_required', 'Ausdr�ckliche Einwilligung erforderlich.', array( 'status' => 400 ) ); }
		$clean_email = sanitize_email( strtolower( trim( $email ) ) );
		if ( empty( $clean_email ) || ! is_email( $clean_email ) ) {
			return new \WP_Error( 'invalid_email', 'Ungültige E-Mail-Adresse.', array( 'status' => 400 ) );
		}

		$email_hash   = hash( 'sha256', $clean_email );
		$subscribers  = get_option( self::OPTION_SUBSCRIBERS, array() );
		if ( ! is_array( $subscribers ) ) {
			$subscribers = array();
		}

		// If already confirmed, notify gently without leaking
		if ( isset( $subscribers[ $email_hash ] ) && 'confirmed' === $subscribers[ $email_hash ]['status'] ) {
			return array(
				'status'  => 'pending_doi',
				'message' => 'Diese E-Mail-Adresse ist bereits für den Newsletter angemeldet.',
			);
		}

		// Generate secure cryptographic verification token
		$token = function_exists( 'wp_generate_password' )
			? wp_generate_password( 32, false )
			: bin2hex( random_bytes( 16 ) );

		$record = array(
			'email'         => $clean_email,
			'first_name'    => sanitize_text_field( $first_name ),
			'status'        => 'pending_doi',
			'token_hash'    => hash( 'sha256', $token ),
			'expires_at'    => time() + 86400,
			'registered_at' => time(),
			'registered_ip' => self::anonymize_ip( $client_ip ),
			'consent_given' => true,
			'consent_text'  => 'Ich möchte über neue Städte und Angebote informiert werden. Abmeldung jederzeit möglich.',
			'confirmed_at'  => null,
			'confirmed_ip'  => null,
		);

		foreach ( $subscribers as $key => $entry ) {
			if ( 'pending_doi' === ( $entry['status'] ?? '' ) && ( $entry['expires_at'] ?? 0 ) < time() ) { unset( $subscribers[$key] ); }
		}
		$subscribers[ $email_hash ] = $record;
		update_option( self::OPTION_SUBSCRIBERS, $subscribers );

		// Dispatch Double Opt-In Email
		if ( ! self::send_doi_email( $clean_email, $token, $first_name ) ) {
			unset( $subscribers[ $email_hash ] );
			update_option( self::OPTION_SUBSCRIBERS, $subscribers );
			return new \WP_Error( 'doi_delivery_failed', 'Best�tigungsnachricht konnte nicht versendet werden.', array( 'status' => 503 ) );
		}

		return array(
			'status'  => 'pending_doi',
			'message' => 'Bestätigungslink versendet.',
		);
	}

	/**
	 * Verify a subscriber via verification token.
	 *
	 * @param string $token Verification token
	 * @return bool True if confirmed successfully
	 */
	public static function confirm_subscriber( string $token ): bool {
		if ( empty( $token ) ) {
			return false;
		}

		$subscribers = get_option( self::OPTION_SUBSCRIBERS, array() );
		if ( ! is_array( $subscribers ) ) {
			return false;
		}

		foreach ( $subscribers as $hash => $record ) {
			if ( 'pending_doi' === ( $record['status'] ?? '' ) && ( $record['expires_at'] ?? 0 ) >= time() && hash_equals( (string) ( $record['token_hash'] ?? '' ), hash( 'sha256', $token ) ) ) {
				unset( $subscribers[ $hash ]['token_hash'], $subscribers[ $hash ]['token'] );
				$subscribers[ $hash ]['status']       = 'confirmed';
				$subscribers[ $hash ]['confirmed_at'] = time();
				$subscribers[ $hash ]['confirmed_ip'] = self::anonymize_ip( self::get_client_ip() );
				update_option( self::OPTION_SUBSCRIBERS, $subscribers );

				if ( function_exists( 'do_action' ) ) {
					do_action( 'ortszeichen_marketing_newsletter_confirmed', $subscribers[ $hash ] );
				}
				return true;
			}
		}

		return false;
	}

	/**
	 * Catch Double Opt-In verification link in request.
	 */
	public static function maybe_handle_doi_confirmation(): void {
		if ( isset( $_GET['oz_action'] ) && 'confirm_newsletter' === $_GET['oz_action'] && ! empty( $_GET['token'] ) ) {
			$raw_token = function_exists( 'wp_unslash' ) ? wp_unslash( $_GET['token'] ) : $_GET['token'];
			$token     = sanitize_text_field( $raw_token );
			$success   = self::confirm_subscriber( $token );

			$redirect_url = add_query_arg(
				array( 'oz_newsletter_confirmed' => $success ? '1' : '0' ),
				home_url( '/' )
			);

			wp_safe_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Dispatch Double Opt-In verification email.
	 *
	 * @param string $to_email   Recipient email
	 * @param string $token      Verification token
	 * @param string $first_name Optional first name
	 * @return bool
	 */
	public static function send_doi_email( string $to_email, string $token, string $first_name = '' ): bool {
		$confirm_url = add_query_arg(
			array(
				'oz_action' => 'confirm_newsletter',
				'token'     => $token,
			),
			home_url( '/' )
		);

		$subject = 'Bitte bestätigen Sie Ihre Anmeldung bei ORTSZEICHEN';
		$greeting = ! empty( $first_name ) ? sprintf( 'Hallo %s,', esc_html( $first_name ) ) : 'Hallo,';

		$message  = "{$greeting}\n\n";
		$message .= "vielen Dank für Ihr Interesse an ORTSZEICHEN. Um Ihre Anmeldung abzuschließen und den Newsletter zu aktivieren, klicken Sie bitte auf den folgenden Link:\n\n";
		$message .= "{$confirm_url}\n\n";
		$message .= "Falls Sie sich nicht angemeldet haben, können Sie diese E-Mail ignorieren. Es werden keine weiteren Nachrichten versendet.\n\n";
		$message .= "Herzliche Grüße,\nIhr ORTSZEICHEN Team\n";

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		if ( function_exists( 'wp_mail' ) ) {
			return wp_mail( $to_email, $subject, $message, $headers );
		}

		return false;
	}

	/**
	 * Rate limiting check.
	 *
	 * @param string $ip Client IP
	 * @return bool True if limit exceeded
	 */
	protected static function is_rate_limited( string $ip ): bool {
		$key   = 'oz_nl_rate_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return true;
		}

		set_transient( $key, $count + 1, 3600 );
		return false;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	public static function get_client_ip(): string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
		$clean_ip = function_exists( 'wp_unslash' ) ? wp_unslash( $ip ) : $ip;
		return sanitize_text_field( $clean_ip );
	}

	/**
	 * Anonymize IP address for GDPR compliance.
	 *
	 * @param string $ip IP address
	 * @return string Anonymized IP
	 */
	public static function anonymize_ip( string $ip ): string {
		if ( function_exists( 'wp_privacy_anonymize_ip' ) ) {
			return wp_privacy_anonymize_ip( $ip );
		}

		// Fallback: mask last octet (IPv4) or last 80 bits (IPv6)
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return preg_replace( '/:[0-9a-f]{1,4}$/i', ':0000', $ip );
		}
		return '127.0.0.0';
	}

	/**
	 * Get subscribers list with optional status filter.
	 *
	 * @param string|null $status Filter by status ('confirmed', 'pending_doi')
	 * @return array
	 */
	public static function get_subscribers( ?string $status = null ): array {
		$subscribers = get_option( self::OPTION_SUBSCRIBERS, array() );
		if ( ! is_array( $subscribers ) ) {
			return array();
		}

		if ( null === $status || 'all' === $status ) {
			return array_values( $subscribers );
		}

		return array_values(
			array_filter(
				$subscribers,
				static function ( $item ) use ( $status ) {
					return isset( $item['status'] ) && $item['status'] === $status;
				}
			)
		);
	}

	/**
	 * Get subscriber counts for status reporting.
	 *
	 * @return array
	 */
	public static function get_subscriber_counts(): array {
		$subscribers = get_option( self::OPTION_SUBSCRIBERS, array() );
		$counts      = array(
			'total'       => 0,
			'confirmed'   => 0,
			'pending_doi' => 0,
		);

		if ( is_array( $subscribers ) ) {
			$counts['total'] = count( $subscribers );
			foreach ( $subscribers as $sub ) {
				$st = $sub['status'] ?? '';
				if ( isset( $counts[ $st ] ) ) {
					++$counts[ $st ];
				}
			}
		}

		return $counts;
	}
}
