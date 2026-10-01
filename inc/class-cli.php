<?php
/**
 * WP-CLI Commands for ORTSZEICHEN Marketing Plugin.
 *
 * Provides:
 * - wp ortszeichen-marketing status
 * - wp ortszeichen-marketing carts [--status=<status>] [--limit=<limit>] [--format=<format>]
 * - wp ortszeichen-marketing scan [--cutoff=<seconds>] [--purge]
 *
 * @package Ortszeichen\Marketing
 */

namespace Ortszeichen\Marketing;

defined( 'ABSPATH' ) || exit;

class CLI {

	/**
	 * Register WP-CLI commands.
	 */
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'ortszeichen-marketing', __CLASS__ );
	}

	/**
	 * Display plugin operational status, conversion metrics, and queue status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ortszeichen-marketing status
	 *     wp ortszeichen-marketing status --format=json
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Associative arguments
	 */
	public function status( $args = array(), $assoc_args = array() ): void {
		$cart_summary = Abandoned_Cart::get_status_summary();
		$nl_summary   = Newsletter::get_subscriber_counts();

		$cached_social = get_transient( Social_Proof::TRANSIENT_KEY );
		$social_status = ( false !== $cached_social && is_array( $cached_social ) )
			? sprintf( 'Warm (%d Einträge im Cache)', count( $cached_social ) )
			: 'Kalt (wird bei nächster Anfrage generiert)';

		$format = $assoc_args['format'] ?? 'table';

		$data = array(
			array(
				'Metric' => 'Warenkörbe gesamt',
				'Value'  => (string) $cart_summary['total'],
			),
			array(
				'Metric' => 'Warenkörbe aktiv',
				'Value'  => (string) $cart_summary['active'],
			),
			array(
				'Metric' => 'Warenkörbe abgebrochen',
				'Value'  => (string) $cart_summary['abandoned'],
			),
			array(
				'Metric' => 'Warenkörbe gerettet',
				'Value'  => (string) $cart_summary['recovered'],
			),
			array(
				'Metric' => 'Geretteter Umsatz',
				'Value'  => number_format( $cart_summary['recovered_revenue'], 2, ',', '.' ) . ' EUR',
			),
			array(
				'Metric' => 'Newsletter Abonnenten (bestätigt)',
				'Value'  => (string) $nl_summary['confirmed'],
			),
			array(
				'Metric' => 'Newsletter DOI ausstehend',
				'Value'  => (string) $nl_summary['pending_doi'],
			),
			array(
				'Metric' => 'Social Proof Cache',
				'Value'  => $social_status,
			),
		);

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		} else {
			\WP_CLI\Utils\format_items( $format, $data, array( 'Metric', 'Value' ) );
		}
	}

	/**
	 * List tracked cart events.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by cart status (active, abandoned, recovered, all).
	 * ---
	 * default: all
	 * ---
	 *
	 * [--limit=<limit>]
	 * : Maximum number of events to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv, ids, count).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp ortszeichen-marketing carts
	 *     wp ortszeichen-marketing carts --status=abandoned
	 *     wp ortszeichen-marketing carts --format=json
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Associative arguments
	 */
	public function carts( $args = array(), $assoc_args = array() ): void {
		$status = $assoc_args['status'] ?? 'all';
		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 20;
		$format = $assoc_args['format'] ?? 'table';

		$events = Abandoned_Cart::get_events(
			array(
				'status' => $status,
				'limit'  => $limit,
			)
		);

		if ( 'count' === $format ) {
			\WP_CLI::line( (string) count( $events ) );
			return;
		}

		if ( 'ids' === $format ) {
			$ids = array_map( static fn( $post ) => $post->ID, $events );
			\WP_CLI::line( implode( ' ', $ids ) );
			return;
		}

		$rows = array();
		foreach ( $events as $event ) {
			$items   = get_post_meta( $event->ID, '_oz_cart_items', true );
			$total   = (float) get_post_meta( $event->ID, '_oz_cart_total', true );
			$cstatus = get_post_meta( $event->ID, '_oz_cart_status', true );
			$updated = (int) get_post_meta( $event->ID, '_oz_cart_updated', true );

			$rows[] = array(
				'ID'      => $event->ID,
				'Title'   => $event->post_title,
				'Status'  => $cstatus,
				'Items'   => is_array( $items ) ? count( $items ) : 0,
				'Total'   => number_format( $total, 2, '.', '' ) . ' EUR',
				'Updated' => $updated ? date( 'Y-m-d H:i', $updated ) : $event->post_date,
			);
		}

		if ( empty( $rows ) ) {
			\WP_CLI::line( 'Keine Warenkorb-Ereignisse gefunden.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $rows, array( 'ID', 'Title', 'Status', 'Items', 'Total', 'Updated' ) );
	}

	/**
	 * Run abandoned cart detection scan and optional GDPR 30-day purge.
	 *
	 * ## OPTIONS
	 *
	 * [--cutoff=<seconds>]
	 * : Cutoff in seconds since last cart update to mark as abandoned.
	 * ---
	 * default: 3600
	 * ---
	 *
	 * [--purge]
	 * : Also execute the GDPR 30-day purge routine for stale records.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ortszeichen-marketing scan
	 *     wp ortszeichen-marketing scan --cutoff=1800 --purge
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Associative arguments
	 */
	public function scan( $args = array(), $assoc_args = array() ): void {
		$cutoff = isset( $assoc_args['cutoff'] ) ? (int) $assoc_args['cutoff'] : Abandoned_Cart::DEFAULT_CUTOFF_SECONDS;

		\WP_CLI::line( sprintf( 'Starte Überprüfung auf verlassene Warenkörbe (Cutoff: %d Sekunden)...', $cutoff ) );
		$marked = Abandoned_Cart::scan( $cutoff );
		\WP_CLI::success( sprintf( '%d Warenkorb-Ereignis(se) als "abandoned" markiert.', $marked ) );

		if ( isset( $assoc_args['purge'] ) ) {
			\WP_CLI::line( 'Starte DSGVO 30-Tage Bereinigungsroutine...' );
			$purged = Abandoned_Cart::purge_old_events( 30 );
			\WP_CLI::success( sprintf( '%d Warenkorb-Ereignis(se) älter als 30 Tage datenschutzkonform gelöscht.', $purged ) );
		}
	}
}
