<?php
/**
 * Issuance service
 *
 * The one code path from evidence event to immutable issued certificate.
 *
 * @package PressPrimer_Certificate
 * @subpackage Services
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issuance service class
 *
 * Implements the Feature 003 FR-001 pipeline exactly. This class is the
 * ONLY writer of new wp_ppcert_certificates rows (CODE-STRUCTURE rule 1);
 * manual issuance and every adapter call issue() and nothing else.
 *
 * Pipeline (all-or-nothing through the insert):
 *  1. Template published + recipient exists
 *  2. Duplicate suppression: same (recipient, template, source_type,
 *     source_ref) non-revoked -> return the existing id with a
 *     duplicate_suppressed event (its own event row - TR-004 gives the
 *     implementer the call; a dedicated row keeps issued rows 1:1 with
 *     actual issuances)
 *  3. ppcert_issue_validation filter (WP_Error aborts)
 *  4. ppcert_before_issue action
 *  5. Merge resolution / 6. credential ID: interleaved - the candidate
 *     credential ID and issued_at are generated first and passed into the
 *     resolution context so {{certificate.credential_id}} and
 *     {{certificate.issue_date}} snapshot real values; on the
 *     astronomically unlikely unique-key collision the ID regenerates and
 *     resolution re-runs with the new candidate. Externally identical to
 *     the documented 5-then-6 order.
 *  7. Insert row with snapshots, issued_at UTC, status issued
 *  8. issued event, email dispatch point (hooks fire now; sending arrives
 *     in Prompt 2.7), ppcert_certificate_issued
 *
 * Error boundary (FR-001): any failure before the insert aborts cleanly
 * with no rows; failures after the insert log but never roll back the
 * certificate.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Issuance_Service {

	/**
	 * Maximum credential-ID collision retries before giving up
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_INSERT_ATTEMPTS = 5;

	/**
	 * Issue a certificate
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     Issuance arguments (Feature 003 FR-001).
	 *
	 *     @type int    $template_id  Published template row id.
	 *     @type int    $recipient_id Recipient user id.
	 *     @type string $source_type  'manual' or an adapter id. Default 'manual'.
	 *     @type string $source_ref   Source object reference; null for manual.
	 *     @type int    $issued_by    Acting user id; system events pass 0.
	 *     @type array  $context      Adapter-supplied resolution context.
	 *     @type bool   $force        Bypass duplicate suppression (manual
	 *                                "Issue anyway" - 003 Edge Cases). Default false.
	 * }
	 * @return int|WP_Error Certificate row id (existing id when suppressed),
	 *                      or WP_Error on abort.
	 */
	public static function issue( array $args ) {
		$template_id  = isset( $args['template_id'] ) ? absint( $args['template_id'] ) : 0;
		$recipient_id = isset( $args['recipient_id'] ) ? absint( $args['recipient_id'] ) : 0;
		$source_type  = isset( $args['source_type'] ) && '' !== $args['source_type']
			? sanitize_key( (string) $args['source_type'] )
			: 'manual';
		$source_ref   = isset( $args['source_ref'] ) && '' !== $args['source_ref'] && null !== $args['source_ref']
			? substr( sanitize_text_field( (string) $args['source_ref'] ), 0, 191 )
			: null;
		$issued_by    = isset( $args['issued_by'] ) ? absint( $args['issued_by'] ) : 0;
		$adapter_ctx  = isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : [];
		$force        = ! empty( $args['force'] );

		// Step 1: template is published; recipient exists.
		$template = PressPrimer_Certificate_Template::get( $template_id );

		if ( ! $template ) {
			return new WP_Error(
				'ppcert_invalid_template',
				__( 'Template not found.', 'pressprimer-certificate' )
			);
		}

		if ( 'published' !== $template->status ) {
			return new WP_Error(
				'ppcert_template_not_published',
				__( 'Certificates can only be issued from published templates.', 'pressprimer-certificate' )
			);
		}

		if ( $recipient_id < 1 || ! get_userdata( $recipient_id ) ) {
			return new WP_Error(
				'ppcert_invalid_recipient',
				__( 'Recipient user not found.', 'pressprimer-certificate' )
			);
		}

		// The issuance context: server-built, shared by the validation
		// filter, lifecycle hooks, and merge resolution. Adapter context
		// keys never override the server-built core keys.
		$context = array_merge(
			$adapter_ctx,
			[
				'template_id'  => $template_id,
				'recipient_id' => $recipient_id,
				'trigger_type' => $source_type,
				'source_type'  => $source_type,
				'source_ref'   => $source_ref,
				'issued_by'    => $issued_by,
			]
		);

		// Step 2: duplicate suppression (bypassed by manual force).
		if ( ! $force ) {
			$existing = PressPrimer_Certificate_Certificate::find_duplicate(
				$recipient_id,
				$template_id,
				$source_type,
				$source_ref
			);

			if ( $existing ) {
				PressPrimer_Certificate_Certificate::record_event(
					(int) $existing->id,
					'duplicate_suppressed',
					$issued_by,
					[
						'source_type' => $source_type,
						'source_ref'  => $source_ref,
					]
				);

				return (int) $existing->id;
			}
		}

		// Step 3: validation pipeline. A WP_Error aborts with no rows.
		/** This filter is documented in docs/architecture/HOOKS.md */
		$validation = apply_filters( 'ppcert_issue_validation', true, $context );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Step 4: pre-issue lifecycle point.
		/** This action is documented in docs/architecture/HOOKS.md */
		do_action( 'ppcert_before_issue', $context );

		// Steps 5-7 inside the pre-insert error boundary.
		$issued_at = current_time( 'mysql', true );

		try {
			$certificate_id = self::resolve_and_insert( $template, $context, $issued_at );
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'ppcert_issue_failed',
				__( 'Certificate issuance failed before completion; no certificate was created.', 'pressprimer-certificate' )
			);
		}

		if ( is_wp_error( $certificate_id ) ) {
			return $certificate_id;
		}

		// Step 8: post-insert side effects. Failures here log but never
		// roll back the issued certificate (FR-001 error boundary).
		try {
			PressPrimer_Certificate_Certificate::record_event(
				$certificate_id,
				'issued',
				$issued_by,
				self::issued_event_meta()
			);

			self::dispatch_email( $certificate_id, $context );

			/** This action is documented in docs/architecture/HOOKS.md */
			do_action( 'ppcert_certificate_issued', $certificate_id, $context );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Post-insert boundary: the certificate exists; the failure must be visible somewhere.
				error_log( 'ppcert: post-issuance side effect failed for certificate ' . $certificate_id . ': ' . $e->getMessage() );
			}
		}

		return $certificate_id;
	}

	/**
	 * Steps 5-7: resolve merge data and insert the certificate row
	 *
	 * The candidate credential ID is generated before resolution so the
	 * certificate.* fields snapshot real values; a unique-key collision
	 * regenerates the ID and re-resolves with the new candidate.
	 *
	 * @since 1.0.0
	 *
	 * @param object $template  Template row (with raw layout_json).
	 * @param array  $context   Issuance context.
	 * @param string $issued_at UTC issuance datetime.
	 * @return int|WP_Error New certificate row id.
	 */
	private static function resolve_and_insert( $template, $context, $issued_at ) {
		global $wpdb;

		$tokens = [];

		if ( is_array( $template->layout ) ) {
			$tokens = PressPrimer_Certificate_Merge_Field_Registry::extract_tokens( $template->layout );
		}

		for ( $attempt = 1; $attempt <= self::MAX_INSERT_ATTEMPTS; $attempt++ ) {
			$credential_id = PressPrimer_Certificate_Credential_ID_Service::generate();

			// Step 5: merge resolution with the candidate credential in
			// context (Feature 002 FR-005).
			$resolution_context = array_merge(
				$context,
				[
					'credential_id' => $credential_id,
					'issued_at'     => $issued_at,
				]
			);

			$merge_data = PressPrimer_Certificate_Merge_Field_Registry::resolve(
				(int) $template->id,
				$tokens,
				$resolution_context
			);

			self::$last_resolution_failures = PressPrimer_Certificate_Merge_Field_Registry::get_last_resolution_failures();

			// Step 7: insert with snapshots. The layout snapshot is the
			// template's raw JSON string, byte for byte.
			$inserted = $wpdb->insert(
				PressPrimer_Certificate_Certificate::table(),
				[
					'uuid'                  => wp_generate_uuid4(),
					'credential_id'         => $credential_id,
					'template_id'           => (int) $template->id,
					'issuer_id'             => null,
					'recipient_id'          => (int) $context['recipient_id'],
					'issued_by'             => (int) $context['issued_by'],
					'source_type'           => $context['source_type'],
					'source_ref'            => $context['source_ref'],
					'status'                => 'issued',
					'layout_schema_version' => (int) $template->layout_schema_version,
					'layout_snapshot_json'  => (string) $template->layout_json,
					'merge_data_json'       => wp_json_encode( $merge_data ),
					'issued_at'             => $issued_at,
					'expires_at'            => null,
					'created_at'            => $issued_at,
					'updated_at'            => $issued_at,
				],
				[ '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);

			if ( $inserted ) {
				return (int) $wpdb->insert_id;
			}

			// Insert failed: retry covers the unique-key collision case;
			// anything persistent falls out after MAX_INSERT_ATTEMPTS.
		}

		return new WP_Error(
			'ppcert_insert_failed',
			__( 'Certificate could not be written to the database.', 'pressprimer-certificate' )
		);
	}

	/**
	 * The email dispatch point (Feature 003 FR-004)
	 *
	 * Delegates to the email service, which fires the documented
	 * ppcert_email_enabled / ppcert_email_content filters and sends via
	 * wp_mail(). Runs inside the post-insert boundary: failures log,
	 * never roll back.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $certificate_id Certificate row id.
	 * @param array $context        Issuance context.
	 */
	private static function dispatch_email( $certificate_id, $context ) {
		PressPrimer_Certificate_Email_Service::send_issued( $certificate_id, $context );
	}

	/**
	 * Resolution failures captured for the issued event meta
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private static $last_resolution_failures = [];

	/**
	 * Build the issued event meta
	 *
	 * Records resolver_failed notes per Feature 002 FR-005. Privacy rules
	 * apply: token names and reason slugs only, nothing identifying.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function issued_event_meta() {
		if ( empty( self::$last_resolution_failures ) ) {
			return [];
		}

		return [ 'resolver_failed' => self::$last_resolution_failures ];
	}
}
