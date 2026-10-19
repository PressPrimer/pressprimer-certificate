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
	 *     @type int    $template_id     Published template row id.
	 *     @type int    $recipient_id    Recipient user id; 0 only for one-off
	 *                                   certificates (source_type 'one_off').
	 *     @type string $source_type     'manual', 'one_off' (2.1), or an adapter id.
	 *                                   Default 'manual'.
	 *     @type string $source_ref      Source object reference; null for manual.
	 *                                   Ignored for one-offs (each gets a UUID).
	 *     @type int    $issued_by       Acting user id; system events pass 0.
	 *     @type array  $context         Adapter-supplied resolution context. For
	 *                                   one-offs, `recipient` => { name (required),
	 *                                   email, first_name, last_name } (2.1).
	 *     @type bool   $force           Bypass duplicate suppression (manual
	 *                                   "Issue anyway" - 003 Edge Cases). Default false.
	 *     @type string $issued_at       UTC MySQL datetime override for backdated
	 *                                   issuance - any caller may pass it (manual
	 *                                   admin issue, past-completion imports,
	 *                                   third-party integrations). Default now.
	 *     @type string $expires_at      UTC MySQL datetime override. Default:
	 *                                   compute_expires_at() from issued_at.
	 *     @type array  $merge_overrides Token => value, applied before the
	 *                                   certificate name pass; limited to the
	 *                                   template's own tokens outside
	 *                                   certificate.* and site.* (2.1).
	 *     @type string $duplicate_scope 'source' (default: same source type and
	 *                                   ref) or 'template' (any certificate from
	 *                                   this template suppresses) (2.1).
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
		$is_one_off   = PressPrimer_Certificate_Certificate::SOURCE_ONE_OFF === $source_type;
		$scope        = isset( $args['duplicate_scope'] ) && 'template' === $args['duplicate_scope'] ? 'template' : 'source';

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

		// One-off certificates (2.1, Feature 2.1-004 FR-007): no account,
		// a typed recipient, a unique ref so suppression never collapses
		// two of them.
		$one_off_recipient = null;

		if ( $is_one_off ) {
			$one_off_recipient = self::one_off_recipient( $recipient_id, isset( $adapter_ctx['recipient'] ) ? $adapter_ctx['recipient'] : null );

			if ( is_wp_error( $one_off_recipient ) ) {
				return $one_off_recipient;
			}

			$source_ref = wp_generate_uuid4();
		} elseif ( $recipient_id < 1 || ! get_userdata( $recipient_id ) ) {
			return new WP_Error(
				'ppcert_invalid_recipient',
				__( 'Recipient user not found.', 'pressprimer-certificate' )
			);
		}

		// The issuance context: server-built, shared by the validation
		// filter, lifecycle hooks, and merge resolution. Adapter context
		// keys never override the server-built core keys (including the
		// one-off recipient and the merge overrides, 2.1).
		$context = array_merge(
			$adapter_ctx,
			[
				'template_id'     => $template_id,
				'recipient_id'    => $recipient_id,
				'trigger_type'    => $source_type,
				'source_type'     => $source_type,
				'source_ref'      => $source_ref,
				'issued_by'       => $issued_by,
				'recipient'       => $one_off_recipient,
				'merge_overrides' => self::clean_merge_overrides(
					$template,
					isset( $args['merge_overrides'] ) ? $args['merge_overrides'] : []
				),
			]
		);

		// Step 2: duplicate suppression - bypassed by the manual force
		// flag or by the firing trigger's reissue condition (compliance
		// and recertification sites issue a fresh credential on every
		// qualifying completion, 2026-07-24).
		//
		// The filtered ref scopes ONLY the duplicate window (2.0, Feature
		// 2.0-007 FR-005): callers whose refs are per-occurrence (e.g. an
		// automation run id) can widen suppression to a stable key. Since
		// 2.1.0 (Feature 2.1-005 FR-001) the filter may return a list of
		// refs and any match suppresses. The certificate row still records
		// the REAL $source_ref, and the reissue check reads the real ref.
		/** This filter is documented in docs/architecture/HOOKS.md */
		$duplicate_refs = apply_filters( 'ppcert_issue_duplicate_ref', $source_ref, $context );
		$duplicate_refs = PressPrimer_Certificate_Certificate::normalize_refs(
			is_array( $duplicate_refs )
				? array_map( [ __CLASS__, 'clean_ref' ], $duplicate_refs )
				: self::clean_ref( $duplicate_refs )
		);

		if ( ! $force && self::trigger_allows_reissue( $template_id, $source_type, $source_ref ) ) {
			$force = true;
		}

		if ( ! $force ) {
			// duplicate_scope 'template' (2.1, Feature 2.1-004 FR-010):
			// any certificate from this template held by this person
			// suppresses, whatever its source. Never for one-offs, which
			// have no account to compare.
			$existing = 'template' === $scope && $recipient_id > 0
				? PressPrimer_Certificate_Certificate::find_any_for_template( $recipient_id, $template_id )
				: PressPrimer_Certificate_Certificate::find_duplicate(
					$recipient_id,
					$template_id,
					$source_type,
					$duplicate_refs
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

		// Steps 5-7 inside the pre-insert error boundary. Any caller may
		// backdate via the validated issued_at override (admin manual
		// issue, past-completion imports, integrations); without one the
		// row stamps now (UTC everywhere).
		$issued_at = isset( $args['issued_at'] )
			&& preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $args['issued_at'] )
			? (string) $args['issued_at']
			: current_time( 'mysql', true );

		// Certificate validity: a manual expires_at override wins;
		// otherwise the template's validity policy applies
		// (compute_expires_at(), public since 2.1.0 so previews of
		// backdated awards can never disagree with issuance).
		if ( isset( $args['expires_at'] )
			&& preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $args['expires_at'] ) ) {
			$expires_at = (string) $args['expires_at'];
		} else {
			$expires_at = self::compute_expires_at( $template, $issued_at );
		}

		try {
			$certificate_id = self::resolve_and_insert( $template, $context, $issued_at, $expires_at );
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
				self::issued_event_meta( $is_one_off )
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
	 * The expiry a template's validity policy gives a certificate issued
	 * at a given time (Feature 2.1-004 FR-010)
	 *
	 * A period counts forward from issued_at (so a backdated issue date
	 * shortens the remaining validity, as recertification expects); a
	 * fixed calendar date expires at that day's end, site time. Absent
	 * both, certificates never expire. This is what issue() uses.
	 *
	 * @since 2.1.0
	 *
	 * @param object $template      Template row (hydrated; settings decoded).
	 * @param string $issued_at_utc UTC MySQL datetime.
	 * @return string|null UTC MySQL datetime, or null for never.
	 */
	public static function compute_expires_at( $template, $issued_at_utc ) {
		$settings = isset( $template->settings ) && is_array( $template->settings ) ? $template->settings : [];

		if ( ! isset( $settings['validity_mode'] ) ) {
			return null;
		}

		if ( 'period' === $settings['validity_mode'] && ! empty( $settings['validity_amount'] ) ) {
			$unit = isset( $settings['validity_unit'] ) && in_array( $settings['validity_unit'], [ 'days', 'months', 'years' ], true )
				? $settings['validity_unit']
				: 'months';

			return gmdate(
				'Y-m-d H:i:s',
				strtotime( (string) $issued_at_utc . ' UTC +' . absint( $settings['validity_amount'] ) . ' ' . $unit )
			);
		}

		if ( 'date' === $settings['validity_mode'] && ! empty( $settings['validity_date'] ) ) {
			return get_gmt_from_date( (string) $settings['validity_date'] . ' 23:59:59' );
		}

		return null;
	}

	/**
	 * The merge tokens an issued certificate snapshots (Feature 2.1-004 FR-008)
	 *
	 * The layout's tokens (every page, plus addon pages through
	 * `ppcert_layout_token_pages`), every mapped email's tokens
	 * (issuance and reminder, FR-004), the certificate-name pattern's
	 * tokens, and `ppcert_issue_tokens`: exactly what issue() resolves,
	 * so a form that asks for "the fields this certificate uses"
	 * (Educator's one-off form) asks for the right ones.
	 *
	 * @since 2.1.0
	 *
	 * @param object $template Template row (hydrated).
	 * @param array  $context  Issuance context ([] outside issue()).
	 * @return string[] Inner token keys, unique.
	 */
	public static function template_tokens( $template, array $context = [] ) {
		$tokens = [];

		if ( isset( $template->layout ) && is_array( $template->layout ) ) {
			$tokens = PressPrimer_Certificate_Merge_Field_Registry::extract_tokens( $template->layout );
		}

		// The issuance email's merge tokens and the certificate-name
		// pattern's tokens resolve into the same snapshot (1.1, Features
		// 005 + 006), so the email and the name can use values the
		// certificate itself does not print - and a resend still reads
		// the immutable snapshot.
		$settings     = isset( $template->settings ) && is_array( $template->settings ) ? $template->settings : [];
		$name_pattern = isset( $settings['certificate_name'] ) ? (string) $settings['certificate_name'] : '';

		$tokens = array_merge(
			$tokens,
			PressPrimer_Certificate_Email_Service::template_tokens( $template ),
			PressPrimer_Certificate_Merge_Field_Registry::extract_tokens_from_text( $name_pattern )
		);

		/**
		 * Filters the merge tokens snapshotted at issue.
		 *
		 * For addon-owned content that prints merge fields (Educator's
		 * card variants, its email templates): a token added here is
		 * resolved into the certificate's snapshot, so it never renders
		 * empty later. Entries are inner-form keys ('recipient.full_name';
		 * braces are stripped).
		 *
		 * @since 2.1.0
		 *
		 * @param string[] $tokens   Token keys.
		 * @param object   $template Template row.
		 * @param array    $context  Issuance context ([] outside issue(),
		 *                           e.g. the one-off form's field list).
		 */
		$filtered = apply_filters( 'ppcert_issue_tokens', $tokens, $template, $context );
		$clean    = [];

		foreach ( is_array( $filtered ) ? $filtered : $tokens as $token ) {
			$token = is_string( $token ) ? trim( str_replace( [ '{{', '}}' ], '', $token ) ) : '';

			if ( '' !== $token ) {
				$clean[] = $token;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Sanitize merge overrides against a template's tokens
	 *
	 * Allowed keys: tokens the template uses, outside the computed
	 * certificate.* and site.* groups. Values: sanitize_text_field, at
	 * most 500 characters. Anything else is dropped (Feature 2.1-004
	 * FR-008). Shared by issue() and the template preview route.
	 *
	 * @since 2.1.0
	 *
	 * @param object $template  Template row (hydrated).
	 * @param mixed  $overrides Raw token => value map.
	 * @return array<string,string>
	 */
	public static function clean_merge_overrides( $template, $overrides ) {
		if ( ! is_array( $overrides ) || empty( $overrides ) ) {
			return [];
		}

		$allowed = self::template_tokens( $template );
		$clean   = [];

		foreach ( $overrides as $token => $value ) {
			$token = trim( str_replace( [ '{{', '}}' ], '', (string) $token ) );

			if ( ! in_array( $token, $allowed, true ) || 0 === strpos( $token, 'certificate.' ) || 0 === strpos( $token, 'site.' ) ) {
				continue;
			}

			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $value );

			$clean[ $token ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 500 ) : substr( $value, 0, 500 );
		}

		return $clean;
	}

	/**
	 * Validate and sanitize a one-off certificate's recipient
	 *
	 * @since 2.1.0
	 *
	 * @param int   $recipient_id Must be 0 (no account).
	 * @param mixed $raw          { name, email, first_name, last_name }.
	 * @return array|WP_Error Clean recipient, or WP_Error.
	 */
	private static function one_off_recipient( $recipient_id, $raw ) {
		if ( $recipient_id > 0 ) {
			return new WP_Error(
				'ppcert_invalid_recipient',
				__( 'One-off certificates are for people without an account. Issue to the account instead.', 'pressprimer-certificate' )
			);
		}

		$raw  = is_array( $raw ) ? $raw : [];
		$name = isset( $raw['name'] ) && is_scalar( $raw['name'] ) ? trim( sanitize_text_field( (string) $raw['name'] ) ) : '';

		if ( '' === $name ) {
			return new WP_Error(
				'ppcert_invalid_recipient',
				__( 'A one-off certificate needs the recipient\'s name.', 'pressprimer-certificate' )
			);
		}

		$email = isset( $raw['email'] ) && is_scalar( $raw['email'] ) ? sanitize_email( (string) $raw['email'] ) : '';

		if ( isset( $raw['email'] ) && '' !== trim( (string) $raw['email'] ) && ( '' === $email || ! is_email( $email ) ) ) {
			return new WP_Error(
				'ppcert_invalid_recipient_email',
				__( 'The recipient\'s email address is not valid.', 'pressprimer-certificate' )
			);
		}

		$cut = static function ( $value, $length ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
		};

		$name  = $cut( $name, 200 );
		$first = isset( $raw['first_name'] ) && is_scalar( $raw['first_name'] ) ? trim( sanitize_text_field( (string) $raw['first_name'] ) ) : '';
		$last  = isset( $raw['last_name'] ) && is_scalar( $raw['last_name'] ) ? trim( sanitize_text_field( (string) $raw['last_name'] ) ) : '';

		// No explicit parts: split at the last space ("Mary Ann Smith" ->
		// "Mary Ann" + "Smith"; a one-word name is all first name).
		if ( '' === $first && '' === $last ) {
			$space = strrpos( $name, ' ' );
			$first = false === $space ? $name : substr( $name, 0, $space );
			$last  = false === $space ? '' : substr( $name, $space + 1 );
		}

		return [
			'name'       => $name,
			'email'      => $cut( $email, 190 ),
			'first_name' => $cut( $first, 100 ),
			'last_name'  => $cut( $last, 100 ),
		];
	}

	/**
	 * Sanitize one duplicate ref from the filter
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $ref Raw ref.
	 * @return string|null
	 */
	private static function clean_ref( $ref ) {
		if ( null === $ref || '' === $ref || ! is_scalar( $ref ) ) {
			return null;
		}

		return substr( sanitize_text_field( (string) $ref ), 0, 191 );
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
	 * @param object      $template   Template row (with raw layout_json).
	 * @param array       $context    Issuance context.
	 * @param string      $issued_at  UTC issuance datetime.
	 * @param string|null $expires_at UTC expiry datetime, or null for never.
	 * @return int|WP_Error New certificate row id.
	 */
	private static function resolve_and_insert( $template, $context, $issued_at, $expires_at = null ) {
		global $wpdb;

		$tokens   = self::template_tokens( $template, $context );
		$settings = is_array( $template->settings ) ? $template->settings : [];

		// A one-off has no account to fall back on, so its name is always
		// snapshotted: the certificate page, verification, the list, and
		// search read it from there (Feature 2.1-004 FR-007).
		if ( ! empty( $context['recipient'] ) && ! in_array( 'recipient.full_name', $tokens, true ) ) {
			$tokens[] = 'recipient.full_name';
		}

		// The registry's name post-pass reads these (Feature 1.1-006).
		$context['template_settings'] = $settings;
		$context['template_title']    = (string) $template->title;

		$recipient_email = isset( $context['recipient']['email'] ) && '' !== (string) $context['recipient']['email']
			? (string) $context['recipient']['email']
			: null;

		for ( $attempt = 1; $attempt <= self::MAX_INSERT_ATTEMPTS; $attempt++ ) {
			list( $credential_id, $credential_display ) = self::credential_for_attempt( $template, $context, $issued_at, $attempt );

			// Step 5: merge resolution with the candidate credential in
			// context (Feature 002 FR-005); a pattern ID's display form
			// rides along so {{certificate.credential_id}} prints it.
			$resolution_context = array_merge(
				$context,
				[
					'credential_id'      => $credential_id,
					'credential_display' => $credential_display,
					'issued_at'          => $issued_at,
					'expires_at'         => $expires_at,
					// The issuer of record (2.1, ppcert_issuer_name).
					'issuer_id'          => ! empty( $template->issuer_id ) ? (int) $template->issuer_id : 0,
				]
			);

			$merge_data = PressPrimer_Certificate_Merge_Field_Registry::resolve(
				(int) $template->id,
				$tokens,
				$resolution_context
			);

			self::$last_resolution_failures = PressPrimer_Certificate_Merge_Field_Registry::get_last_resolution_failures();

			// The search-only title column (Feature 2.0-002 TR-002): a copy
			// of the snapshot's certificate.title, which the registry's name
			// post-pass always resolves since 1.1. Display everywhere stays
			// snapshot-first via Certificate::display_title().
			$title = isset( $merge_data['certificate.title'] ) ? trim( (string) $merge_data['certificate.title'] ) : '';

			if ( '' !== $title ) {
				$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 200 ) : substr( $title, 0, 200 );
			}

			// Step 7: insert with snapshots. The layout snapshot is the
			// template's raw JSON string, byte for byte. The issuer of
			// record is stamped from the template at issue time (School
			// 2.0 contract) - reassigning the template later never
			// rewrites issued certificates.
			$inserted = $wpdb->insert(
				PressPrimer_Certificate_Certificate::table(),
				[
					'uuid'                  => wp_generate_uuid4(),
					'credential_id'         => $credential_id,
					'template_id'           => (int) $template->id,
					'issuer_id'             => ! empty( $template->issuer_id ) ? (int) $template->issuer_id : null,
					'recipient_id'          => (int) $context['recipient_id'],
					'issued_by'             => (int) $context['issued_by'],
					'source_type'           => $context['source_type'],
					'source_ref'            => $context['source_ref'],
					'status'                => 'issued',
					'title'                 => '' !== $title ? $title : null,
					'layout_schema_version' => (int) $template->layout_schema_version,
					'layout_snapshot_json'  => (string) $template->layout_json,
					'merge_data_json'       => wp_json_encode( $merge_data ),
					'issued_at'             => $issued_at,
					'expires_at'            => $expires_at,
					// The real insert time, even for a backdated issue
					// date (2.1, Feature 2.1-004 FR-010); statistics key
					// on issued_at.
					'created_at'            => current_time( 'mysql', true ),
					'updated_at'            => current_time( 'mysql', true ),
					'credential_display'    => '' !== $credential_display ? $credential_display : null,
					'recipient_email'       => $recipient_email,
				],
				[ '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);

			if ( $inserted ) {
				// Read the id first: update_option() below can insert an
				// options row and replace $wpdb->insert_id (found on the
				// dev site, 2026-10-18).
				$certificate_id = (int) $wpdb->insert_id;

				// The first non-core ID on this site switches verification
				// to the pattern-aware gate for good (Decision 006 point 6).
				if ( ! PressPrimer_Certificate_Credential_ID_Service::is_core( $credential_id )
					&& ! PressPrimer_Certificate_Credential_ID_Service::pattern_ids_in_use() ) {
					update_option( PressPrimer_Certificate_Credential_ID_Service::PATTERN_IDS_OPTION, 1, true );
				}

				return $certificate_id;
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
	 * The credential ID for one insert attempt
	 *
	 * A fresh core ID, offered to `ppcert_credential_id` (Feature 2.1-004
	 * FR-009, Decision 006) with the attempt number so a sequence can
	 * draw a new number after a collision. The filter's `{ id, display }`
	 * is used only when it passes the strict checks; otherwise the core
	 * ID stands.
	 *
	 * @since 2.1.0
	 *
	 * @param object $template  Template row.
	 * @param array  $context   Issuance context.
	 * @param string $issued_at UTC issue datetime.
	 * @param int    $attempt   1-based attempt number.
	 * @return array{0:string,1:string} [ stored ID, display form or '' ].
	 */
	private static function credential_for_attempt( $template, array $context, $issued_at, $attempt ) {
		$core = PressPrimer_Certificate_Credential_ID_Service::generate();

		/**
		 * Filters the credential ID of a certificate about to be issued.
		 *
		 * Return `[ 'id' => normalized ID, 'display' => display form ]`.
		 * Accepted only when id is normalized and 4-32 characters of
		 * [0-9A-Z], and display (optional) is at most 40 characters of
		 * letters, digits, and single hyphens that normalizes to id;
		 * otherwise the core ID is used. Called again on each retry.
		 *
		 * @since 2.1.0
		 *
		 * @param array  $candidate [ 'id' => core ID, 'display' => '' ].
		 * @param object $template  Template row.
		 * @param array  $context   Issuance context plus issued_at (UTC) and issuer_id.
		 * @param int    $attempt   1-based attempt number.
		 */
		$filtered = apply_filters(
			'ppcert_credential_id',
			[
				'id'      => $core,
				'display' => '',
			],
			$template,
			array_merge(
				$context,
				[
					'issued_at' => $issued_at,
					'issuer_id' => ! empty( $template->issuer_id ) ? (int) $template->issuer_id : 0,
				]
			),
			(int) $attempt
		);

		$accepted = PressPrimer_Certificate_Credential_ID_Service::accept_candidate( $filtered );

		if ( null === $accepted ) {
			return [ $core, '' ];
		}

		return [ $accepted['id'], $accepted['display'] ];
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
	 * Records resolver_failed notes per Feature 002 FR-005, and marks
	 * one-off certificates (source_type one_off, 2.1) so audit tools can
	 * tell them apart. Privacy rules apply: token names and reason slugs
	 * only, nothing identifying.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 The one-off marker.
	 *
	 * @param bool $is_one_off Whether the certificate is a one-off.
	 * @return array
	 */
	private static function issued_event_meta( $is_one_off = false ) {
		$meta = [];

		if ( $is_one_off ) {
			$meta['source_type'] = PressPrimer_Certificate_Certificate::SOURCE_ONE_OFF;
		}

		if ( ! empty( self::$last_resolution_failures ) ) {
			$meta['resolver_failed'] = self::$last_resolution_failures;
		}

		return $meta;
	}

	/**
	 * Whether the firing trigger opts out of duplicate suppression
	 *
	 * Resolved here (not threaded through the adapters) so the policy
	 * lives in one place and every adapter, bundled or addon, inherits
	 * it. The template's triggers are matched on type and source ref;
	 * a stored NULL ref and an empty-string ref compare equal.
	 *
	 * @since 1.0.0
	 *
	 * @param int         $template_id Template id.
	 * @param string      $source_type Trigger type ('manual' never reissues here).
	 * @param string|null $source_ref  Source reference from the firing adapter.
	 * @return bool True when the matching active trigger has reissue on.
	 */
	private static function trigger_allows_reissue( $template_id, $source_type, $source_ref ) {
		if ( 'manual' === $source_type ) {
			return false;
		}

		foreach ( PressPrimer_Certificate_Trigger::get_for_template( (int) $template_id ) as $trigger ) {
			if ( (string) $trigger->trigger_type !== (string) $source_type ) {
				continue;
			}

			$trigger_ref = isset( $trigger->source_ref ) ? (string) $trigger->source_ref : '';

			// An 'any' trigger (1.1) fires for every ref of its type, so
			// its reissue setting governs every fired object.
			if ( $trigger_ref !== (string) $source_ref && PressPrimer_Certificate_Trigger::SOURCE_ANY !== $trigger_ref ) {
				continue;
			}

			return ! empty( $trigger->is_active ) && ! empty( $trigger->conditions['reissue'] );
		}

		return false;
	}
}
