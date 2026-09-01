<?php
/**
 * WordPress privacy integration
 *
 * Personal data exporter and eraser (Feature 008 FR-005): certificates
 * (credential ID, subject, source, status, dates, merge data) plus any
 * credit rows export through Tools -> Export Personal Data; erasure
 * removes the user's certificate rows, their events, credits, and
 * cached preview PNGs - and with them, verification.
 *
 * @package PressPrimer_Certificate
 * @subpackage Utilities
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Privacy class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Privacy {

	/**
	 * Rows handled per exporter/eraser page
	 *
	 * WordPress calls the handlers repeatedly with an incrementing page
	 * until `done`; small batches keep each request shared-hosting safe.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const BATCH_SIZE = 25;

	/**
	 * Register the exporter and eraser
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', [ __CLASS__, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ __CLASS__, 'register_eraser' ] );
	}

	/**
	 * Register the certificates exporter
	 *
	 * @since 1.0.0
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['ppcert-certificates'] = [
			'exporter_friendly_name' => __( 'PressPrimer Certificates', 'pressprimer-certificate' ),
			'callback'               => [ __CLASS__, 'export' ],
		];

		return $exporters;
	}

	/**
	 * Register the certificates eraser
	 *
	 * @since 1.0.0
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['ppcert-certificates'] = [
			'eraser_friendly_name' => __( 'PressPrimer Certificates', 'pressprimer-certificate' ),
			'callback'             => [ __CLASS__, 'erase' ],
		];

		return $erasers;
	}

	/**
	 * Export a user's certificate and credit data
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Email address being exported.
	 * @param int    $page  Batch page (1-based).
	 * @return array { data: array, done: bool }
	 */
	public static function export( $email, $page = 1 ) {
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		$page         = max( 1, (int) $page );
		$certificates = self::user_certificates( (int) $user->ID, $page );
		$items        = [];

		foreach ( $certificates as $certificate ) {
			$data = [
				[
					'name'  => __( 'Credential ID', 'pressprimer-certificate' ),
					'value' => PressPrimer_Certificate_Credential_ID_Service::format_display( (string) $certificate->credential_id ),
				],
				[
					'name'  => __( 'Certificate', 'pressprimer-certificate' ),
					'value' => isset( $certificate->template_title ) ? (string) $certificate->template_title : '',
				],
				[
					'name'  => __( 'Source', 'pressprimer-certificate' ),
					'value' => (string) $certificate->source_type,
				],
				[
					'name'  => __( 'Status', 'pressprimer-certificate' ),
					'value' => PressPrimer_Certificate_Certificate::effective_status( $certificate ),
				],
				[
					'name'  => __( 'Issued', 'pressprimer-certificate' ),
					'value' => self::display_date( $certificate->issued_at ),
				],
			];

			if ( ! empty( $certificate->expires_at ) ) {
				$data[] = [
					'name'  => __( 'Expires', 'pressprimer-certificate' ),
					'value' => self::display_date( $certificate->expires_at ),
				];
			}

			if ( is_array( $certificate->merge_data ) ) {
				foreach ( $certificate->merge_data as $key => $value ) {
					if ( ! is_scalar( $value ) || '' === (string) $value ) {
						continue;
					}

					$data[] = [
						'name'  => (string) $key,
						'value' => (string) $value,
					];
				}
			}

			$items[] = [
				'group_id'    => 'ppcert-certificates',
				'group_label' => __( 'Certificates', 'pressprimer-certificate' ),
				'item_id'     => 'ppcert-certificate-' . (int) $certificate->id,
				'data'        => $data,
			];
		}

		// Credit rows (dormant foundation table) export on the first page -
		// the ledger is small while no UI writes to it.
		if ( 1 === $page ) {
			foreach ( self::user_credits( (int) $user->ID ) as $credit ) {
				$items[] = [
					'group_id'    => 'ppcert-credits',
					'group_label' => __( 'Certificate Credits', 'pressprimer-certificate' ),
					'item_id'     => 'ppcert-credit-' . (int) $credit->id,
					'data'        => [
						[
							'name'  => __( 'Credit type', 'pressprimer-certificate' ),
							'value' => isset( $credit->credit_type_name ) && '' !== (string) $credit->credit_type_name
								? (string) $credit->credit_type_name
								: (string) $credit->credit_type_id,
						],
						[
							'name'  => __( 'Amount', 'pressprimer-certificate' ),
							'value' => (string) $credit->amount,
						],
						[
							'name'  => __( 'Awarded', 'pressprimer-certificate' ),
							'value' => self::display_date( $credit->awarded_at ),
						],
					],
				];
			}
		}

		return [
			'data' => $items,
			'done' => count( $certificates ) < self::BATCH_SIZE,
		];
	}

	/**
	 * Erase a user's certificate data
	 *
	 * Removes certificate rows, their events, the user's credits, and
	 * cached preview PNGs. Erased certificates can no longer be verified
	 * (Feature 006 edge case) - the response message says so.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email Email address being erased.
	 * @param int    $page  Batch page (1-based).
	 * @return array { items_removed: int, items_retained: int, messages: string[], done: bool }
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;

		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return [
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => [],
				'done'           => true,
			];
		}

		// Batches always query page 1: each pass deletes what it fetched,
		// so the next pass sees the next rows.
		$certificates = self::user_certificates( (int) $user->ID, 1 );
		$removed      = 0;

		foreach ( $certificates as $certificate ) {
			PressPrimer_Certificate_Preview_Service::delete( (string) $certificate->credential_id );

			$wpdb->delete(
				PressPrimer_Certificate_Certificate::events_table(),
				[ 'certificate_id' => (int) $certificate->id ],
				[ '%d' ]
			);

			$deleted = $wpdb->delete(
				PressPrimer_Certificate_Certificate::table(),
				[ 'id' => (int) $certificate->id ],
				[ '%d' ]
			);

			if ( $deleted ) {
				++$removed;
			}
		}

		$done = count( $certificates ) < self::BATCH_SIZE;

		// The credit ledger clears once, on the final pass.
		if ( $done ) {
			$credits_removed = $wpdb->delete(
				$wpdb->prefix . 'ppcert_credits',
				[ 'user_id' => (int) $user->ID ],
				[ '%d' ]
			);

			if ( $credits_removed ) {
				$removed += (int) $credits_removed;
			}
		}

		$messages = [];

		if ( $removed > 0 || $done ) {
			$messages[] = __( 'Erased certificates are permanently removed and can no longer be verified.', 'pressprimer-certificate' );
		}

		return [
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => $messages,
			'done'           => $done,
		];
	}

	/**
	 * One batch of a user's certificates, oldest first
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User id.
	 * @param int $page    Batch page (1-based).
	 * @return object[] Hydrated rows with template_title.
	 */
	private static function user_certificates( $user_id, $page ) {
		return PressPrimer_Certificate_Certificate::get_batch_for_recipient(
			$user_id,
			self::BATCH_SIZE,
			( max( 1, $page ) - 1 ) * self::BATCH_SIZE
		);
	}

	/**
	 * A user's credit rows with their type names
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User id.
	 * @return object[]
	 */
	private static function user_credits( $user_id ) {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT cr.*, ct.name AS credit_type_name FROM %i cr LEFT JOIN %i ct ON ct.id = cr.credit_type_id WHERE cr.user_id = %d ORDER BY cr.id ASC',
				$wpdb->prefix . 'ppcert_credits',
				$wpdb->prefix . 'ppcert_credit_types',
				absint( $user_id )
			)
		);
	}

	/**
	 * Format a UTC datetime in the site date format
	 *
	 * UTC in, localized out (CLAUDE.md Datetime Standard).
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $utc MySQL UTC datetime.
	 * @return string
	 */
	private static function display_date( $utc ) {
		if ( empty( $utc ) ) {
			return '';
		}

		return (string) get_date_from_gmt( (string) $utc, get_option( 'date_format' ) );
	}
}
