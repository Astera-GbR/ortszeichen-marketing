<?php
/**
 * GDPR / DSGVO Privacy Integration: Data Exporter & Data Eraser.
 *
 * Implements WordPress Privacy API hooks (Art. 15 Right of Access, Art. 17 Right to Erasure).
 *
 * @package Ortszeichen\Marketing
 */

namespace Ortszeichen\Marketing;

defined( 'ABSPATH' ) || exit;

class Privacy {

	/**
	 * Boot hooks.
	 */
	public static function boot(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );
	}

	/**
	 * Register personal data exporters.
	 *
	 * @param array $exporters Array of exporters
	 * @return array
	 */
	public static function register_exporters( array $exporters ): array {
		$exporters['ortszeichen-marketing'] = array(
			'exporter_friendly_name' => 'ORTSZEICHEN Marketing (Warenkörbe & Newsletter)',
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register personal data erasers.
	 *
	 * @param array $erasers Array of erasers
	 * @return array
	 */
	public static function register_erasers( array $erasers ): array {
		$erasers['ortszeichen-marketing'] = array(
			'eraser_friendly_name' => 'ORTSZEICHEN Marketing (Warenkörbe & Newsletter)',
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Export customer personal data for an email address.
	 *
	 * @param string $email_address Email address
	 * @param int    $page          Pagination page
	 * @return array
	 */
	public static function export_personal_data( string $email_address, int $page = 1 ): array {
		$export_items = array();

		// Export cart events
		$cart_events = get_posts(
			array(
				'post_type'   => Abandoned_Cart::CPT,
				'meta_key'    => '_oz_cart_email',
				'meta_value'  => $email_address,
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);

		foreach ( $cart_events as $event ) {
			$total   = get_post_meta( $event->ID, '_oz_cart_total', true );
			$status  = get_post_meta( $event->ID, '_oz_cart_status', true );
			$updated = get_post_meta( $event->ID, '_oz_cart_updated', true );

			$export_items[] = array(
				'group_id'    => 'ortszeichen_marketing_carts',
				'group_label' => 'Warenkorb-Ereignisse',
				'item_id'     => 'cart-' . $event->ID,
				'data'        => array(
					array(
						'name'  => 'Status',
						'value' => $status,
					),
					array(
						'name'  => 'Warenkorbwert',
						'value' => $total . ' EUR',
					),
					array(
						'name'  => 'Zuletzt aktualisiert',
						'value' => $updated ? date( 'Y-m-d H:i:s', (int) $updated ) : $event->post_date,
					),
				),
			);
		}

		// Export newsletter consent
		$email_hash  = hash( 'sha256', strtolower( trim( $email_address ) ) );
		$subscribers = get_option( Newsletter::OPTION_SUBSCRIBERS, array() );

		if ( isset( $subscribers[ $email_hash ] ) ) {
			$sub = $subscribers[ $email_hash ];
			$export_items[] = array(
				'group_id'    => 'ortszeichen_marketing_newsletter',
				'group_label' => 'Newsletter Double Opt-In',
				'item_id'     => 'newsletter-' . $email_hash,
				'data'        => array(
					array(
						'name'  => 'Status',
						'value' => $sub['status'] ?? '',
					),
					array(
						'name'  => 'Einwilligungstext',
						'value' => $sub['consent_text'] ?? '',
					),
					array(
						'name'  => 'Registrierungszeitpunkt',
						'value' => ! empty( $sub['registered_at'] ) ? date( 'Y-m-d H:i:s', $sub['registered_at'] ) : '',
					),
					array(
						'name'  => 'Bestätigungszeitpunkt',
						'value' => ! empty( $sub['confirmed_at'] ) ? date( 'Y-m-d H:i:s', $sub['confirmed_at'] ) : 'Ausstehend',
					),
				),
			);
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Erase customer personal data for an email address.
	 *
	 * @param string $email_address Email address
	 * @param int    $page          Pagination page
	 * @return array
	 */
	public static function erase_personal_data( string $email_address, int $page = 1 ): array {
		$items_removed = 0;

		// Erase/anonymize cart events
		$cart_events = get_posts(
			array(
				'post_type'   => Abandoned_Cart::CPT,
				'meta_key'    => '_oz_cart_email',
				'meta_value'  => $email_address,
				'post_status' => 'any',
				'numberposts' => -1,
			)
		);

		foreach ( $cart_events as $event ) {
			wp_delete_post( $event->ID, true );
			++$items_removed;
		}

		// Erase newsletter subscriber record
		$email_hash  = hash( 'sha256', strtolower( trim( $email_address ) ) );
		$subscribers = get_option( Newsletter::OPTION_SUBSCRIBERS, array() );

		if ( isset( $subscribers[ $email_hash ] ) ) {
			unset( $subscribers[ $email_hash ] );
			update_option( Newsletter::OPTION_SUBSCRIBERS, $subscribers );
			++$items_removed;
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
