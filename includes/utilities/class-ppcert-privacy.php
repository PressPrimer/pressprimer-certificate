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
 * Suggested privacy policy text (2.0, release review): registered
 * through wp_add_privacy_policy_content() so Settings -> Privacy ->
 * Policy Guide tells site owners what the plugin stores and that
 * certificate pages, verification, and PDF downloads are public to
 * anyone with the link or credential ID.
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

		// Core requires the policy content to register on admin_init or
		// later, in the admin.
		add_action( 'admin_init', [ __CLASS__, 'register_policy_content' ] );
	}

	/**
	 * Register the suggested privacy policy text
	 *
	 * Appears under Settings -> Privacy -> Policy Guide. The section is
	 * titled with the (white-label aware) product name.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function register_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$name = class_exists( 'PressPrimer_Certificate_Admin' )
			? (string) PressPrimer_Certificate_Admin::branding()['name']
			: __( 'PressPrimer Certificate', 'pressprimer-certificate' );

		wp_add_privacy_policy_content( $name, self::policy_content() );
	}

	/**
	 * The suggested privacy policy content
	 *
	 * Guidance paragraphs for the site owner carry the core
	 * `privacy-policy-tutorial` class (shown in the guide, dropped when
	 * the text is copied into the policy); the suggested text follows.
	 * The retention figure reads the live setting so the suggestion is
	 * true for this site.
	 *
	 * @since 2.0.0
	 *
	 * @return string Sanitized HTML.
	 */
	public static function policy_content() {
		$settings  = get_option( 'ppcert_settings', [] );
		$retention = isset( $settings['events_retention_days'] ) ? absint( $settings['events_retention_days'] ) : 90;
		$retention = min( 3650, max( 7, $retention ) );

		$tutorial = [
			__( 'This plugin issues certificates to users of your site and keeps a record of each one in your database. Nothing is sent to outside services. Use the text below as a starting point and adjust it to match how you use certificates.', 'pressprimer-certificate' ),
			__( 'Certificates are public to anyone who has the link or the credential ID. Every certificate has a share page, a verification result, and a PDF download that open without signing in. They show the recipient\'s name, the certificate title, the issuer, the issue and expiry dates, and the certificate image and PDF with every field placed on the design, which can include the recipient\'s email address if your template uses that field. Long random credential IDs and rate limiting make guessing impractical, but anyone a recipient shares the link with, or who scans the QR code, sees the same information.', 'pressprimer-certificate' ),
			__( 'The optional email-course opt-in on the dashboard sends only an administrator\'s own address, typed in and submitted by that administrator. It does not involve your users\' data and needs no mention in your policy.', 'pressprimer-certificate' ),
		];

		$suggested = [
			[
				__( 'Certificates', 'pressprimer-certificate' ),
				__( 'When you earn a certificate on this site, we keep a record of it: your name as it appears on the certificate, the certificate title, the date it was issued and, where applicable, the date it expires, a unique credential ID, the course, quiz, or other activity that earned it, and the details placed on the certificate design, which may include your name and email address. We keep this record so that we can show you your certificates, let you download them, and confirm to others that they are genuine.', 'pressprimer-certificate' ),
			],
			[
				__( 'Public verification and sharing', 'pressprimer-certificate' ),
				__( 'Each certificate has a public web page, a verification result, and a PDF download that anyone with its link or credential ID can open without signing in. These show the recipient\'s name, the certificate title, the issuer, the issue and expiry dates, and the full certificate image. Share your certificate link only with people you want to see it. If a certificate is revoked, its page and verification result say so and the download is withdrawn.', 'pressprimer-certificate' ),
			],
			[
				__( 'Usage records', 'pressprimer-certificate' ),
				sprintf(
					/* translators: %d: number of days view and verification records are kept */
					_n(
						'When a certificate is viewed, verified, or downloaded, we record the event and the time, and who did it if they were signed in. We do not record IP addresses or browser details for these events. View and verification records are deleted after %d day; issue and download records are kept with the certificate.',
						'When a certificate is viewed, verified, or downloaded, we record the event and the time, and who did it if they were signed in. We do not record IP addresses or browser details for these events. View and verification records are deleted after %d days; issue and download records are kept with the certificate.',
						$retention,
						'pressprimer-certificate'
					),
					$retention
				),
			],
			[
				__( 'Your rights', 'pressprimer-certificate' ),
				__( 'You can ask us to export or erase the certificate records we hold about you. Erasing them removes your certificates, their public pages, and their downloads, and they can no longer be verified.', 'pressprimer-certificate' ),
			],
		];

		$content = '';

		foreach ( $tutorial as $paragraph ) {
			$content .= '<p class="privacy-policy-tutorial">' . esc_html( $paragraph ) . '</p>';
		}

		$content .= '<p><strong class="privacy-policy-tutorial">' . esc_html__( 'Suggested text:', 'pressprimer-certificate' ) . '</strong></p>';

		foreach ( $suggested as $section ) {
			$content .= '<p><strong>' . esc_html( $section[0] ) . '</strong> ' . esc_html( $section[1] ) . '</p>';
		}

		/**
		 * Filters the suggested privacy policy content.
		 *
		 * Addons append their own paragraphs (a public credential
		 * directory, social preview images) so the guide describes the
		 * whole suite as installed. Return HTML; it is run through
		 * wp_kses_post() after the filter.
		 *
		 * @since 2.0.0
		 *
		 * @param string $content   Suggested policy HTML.
		 * @param int    $retention Days that view and verification events are kept.
		 */
		$content = apply_filters( 'ppcert_privacy_policy_content', $content, $retention );

		return wp_kses_post( $content );
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
