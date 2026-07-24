<?php
/**
 * Certificate model
 *
 * Read access, status evaluation, and status transitions for the
 * wp_ppcert_certificates table.
 *
 * @package PressPrimer_Certificate
 * @subpackage Models
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate model class
 *
 * New certificate rows are written exclusively by the issuance service
 * (CODE-STRUCTURE rule 1); this model provides reads, the read-time
 * status evaluation (Feature 003 FR-005), the revoke transition (the one
 * code path for status transitions, per HOOKS.md), and the lifecycle
 * event writer.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Certificate {

	/**
	 * Get the full certificates table name
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'ppcert_certificates';
	}

	/**
	 * Get the full events table name
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'ppcert_events';
	}

	/**
	 * Get one certificate by row id
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Certificate row id.
	 * @return object|null Hydrated row, or null.
	 */
	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d',
				self::table(),
				absint( $id )
			)
		);

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Query certificates for the admin list (FR-003)
	 *
	 * Fixed-shape SQL: every filter is always present with a sentinel
	 * ("0"/'') meaning "no filter", so the prepared statement is one
	 * stable string (no clause interpolation). Recipient search resolves
	 * user ids first (name/email/login) and matches via FIND_IN_SET;
	 * credential search matches the normalized stored form. Ordering is
	 * hardcoded newest-first.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     Optional filters.
	 *
	 *     @type int    $template_id Filter to one template. Default 0 (all).
	 *     @type string $status      'issued'|'revoked'|'expired'. Default '' (all).
	 *     @type string $source_type Trigger type or 'manual'. Default '' (all).
	 *     @type string $search      Recipient name/email or credential ID.
	 *     @type int    $per_page    Page size (1-100). Default 20.
	 *     @type int    $page        1-based page. Default 1.
	 * }
	 * @return array{items:array,total:int} Hydrated rows + unpaged total.
	 */
	public static function query( array $args = [] ) {
		global $wpdb;

		$template_id = isset( $args['template_id'] ) ? absint( $args['template_id'] ) : 0;
		$status      = isset( $args['status'] ) && in_array( $args['status'], [ 'issued', 'revoked', 'expired' ], true )
			? (string) $args['status']
			: '';
		$source_type = isset( $args['source_type'] ) ? sanitize_key( (string) $args['source_type'] ) : '';
		$search      = isset( $args['search'] ) ? sanitize_text_field( (string) $args['search'] ) : '';
		$per_page    = isset( $args['per_page'] ) ? min( 100, max( 1, absint( $args['per_page'] ) ) ) : 20;
		$page        = isset( $args['page'] ) ? max( 1, absint( $args['page'] ) ) : 1;

		// Recipient matches: resolve user ids by name/email/login.
		$recipient_csv = '';

		if ( '' !== $search ) {
			$user_ids = get_users(
				[
					'search'         => '*' . $search . '*',
					'search_columns' => [ 'user_login', 'user_email', 'user_nicename', 'display_name' ],
					'fields'         => 'ID',
					'number'         => 100,
				]
			);

			$recipient_csv = implode( ',', array_map( 'absint', (array) $user_ids ) );
		}

		// Credential matches compare against the normalized stored form.
		$credential_like = '%' . $wpdb->esc_like(
			PressPrimer_Certificate_Credential_ID_Service::normalize( $search )
		) . '%';

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE ( %d = 0 OR template_id = %d ) AND ( %s = '' OR status = %s ) AND ( %s = '' OR source_type = %s ) AND ( %s = '' OR credential_id LIKE %s OR FIND_IN_SET( recipient_id, %s ) )",
				self::table(),
				$template_id,
				$template_id,
				$status,
				$status,
				$source_type,
				$source_type,
				$search,
				$credential_like,
				$recipient_csv
			)
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE ( %d = 0 OR template_id = %d ) AND ( %s = '' OR status = %s ) AND ( %s = '' OR source_type = %s ) AND ( %s = '' OR credential_id LIKE %s OR FIND_IN_SET( recipient_id, %s ) ) ORDER BY issued_at DESC, id DESC LIMIT %d OFFSET %d",
				self::table(),
				$template_id,
				$template_id,
				$status,
				$status,
				$source_type,
				$source_type,
				$search,
				$credential_like,
				$recipient_csv,
				$per_page,
				( $page - 1 ) * $per_page
			)
		);

		return [
			'items' => array_map( [ __CLASS__, 'hydrate' ], (array) $rows ),
			'total' => $total,
		];
	}

	/**
	 * Get one certificate by credential ID
	 *
	 * Input is normalized first (case, separators, confusables) so any
	 * accepted user-typed form matches the stored form. Checksum gating
	 * before the lookup is the REST layer's job (Prompt 2.6).
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential_id Credential ID in any accepted input form.
	 * @return object|null Hydrated row, or null.
	 */
	public static function get_by_credential_id( $credential_id ) {
		global $wpdb;

		$normalized = PressPrimer_Certificate_Credential_ID_Service::normalize( $credential_id );

		if ( '' === $normalized ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE credential_id = %s',
				self::table(),
				$normalized
			)
		);

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Get a certificate for public verification: one indexed lookup
	 *
	 * The single prepared query the verification endpoint runs (Feature
	 * 006 FR-003/Security Requirements) - the template title joins in for
	 * the response's subject field, so no second query is needed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential_id Credential ID in any accepted input form.
	 * @return object|null Hydrated row with a template_title property, or null.
	 */
	public static function get_for_verification( $credential_id ) {
		global $wpdb;

		$normalized = PressPrimer_Certificate_Credential_ID_Service::normalize( $credential_id );

		if ( '' === $normalized ) {
			return null;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT c.*, t.title AS template_title FROM %i c LEFT JOIN %i t ON t.id = c.template_id WHERE c.credential_id = %s',
				self::table(),
				PressPrimer_Certificate_Template::table(),
				$normalized
			)
		);

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Evaluate a certificate's effective status at read time
	 *
	 * Feature 003 FR-005: a certificate with a past expires_at reports
	 * expired everywhere without a row update (no cron in 1.0); revoked
	 * always wins. Verification, the wallet, and the admin list all read
	 * status through this method.
	 *
	 * @since 1.0.0
	 *
	 * @param object $certificate Certificate row.
	 * @return string 'issued', 'revoked', or 'expired'.
	 */
	public static function effective_status( $certificate ) {
		if ( 'revoked' === $certificate->status ) {
			return 'revoked';
		}

		if ( ! empty( $certificate->expires_at ) ) {
			$expires = strtotime( $certificate->expires_at . ' +0000' );

			if ( false !== $expires && $expires <= time() ) {
				return 'expired';
			}
		}

		return (string) $certificate->status;
	}

	/**
	 * Revoke a certificate
	 *
	 * The issued-to-revoked transition code exists in 1.0 with no UI
	 * (Educator 2.0 adds it); this is the single code path so the hook
	 * contract is stable from day one. No event row in 1.0 - the
	 * 'revoked' event type is reserved for later versions (DATABASE.md).
	 *
	 * @since 1.0.0
	 *
	 * @param int    $id     Certificate row id.
	 * @param string $reason Revocation reason (stored, max 191 chars).
	 * @return true|WP_Error True on success.
	 */
	public static function revoke( $id, $reason = '' ) {
		global $wpdb;

		$certificate = self::get( $id );

		if ( ! $certificate ) {
			return new WP_Error(
				'ppcert_invalid_certificate',
				__( 'Certificate not found.', 'pressprimer-certificate' )
			);
		}

		if ( 'revoked' === $certificate->status ) {
			return true;
		}

		$reason = substr( sanitize_text_field( (string) $reason ), 0, 191 );

		$updated = $wpdb->update(
			self::table(),
			[
				'status'        => 'revoked',
				'revoked_at'    => current_time( 'mysql', true ),
				'revoke_reason' => $reason,
				'updated_at'    => current_time( 'mysql', true ),
			],
			[ 'id' => absint( $id ) ],
			[ '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $updated ) {
			return new WP_Error(
				'ppcert_revoke_failed',
				__( 'Certificate revocation failed.', 'pressprimer-certificate' )
			);
		}

		/** This action is documented in docs/architecture/HOOKS.md */
		do_action( 'ppcert_certificate_revoked', absint( $id ), $reason );

		return true;
	}

	/**
	 * Record a lifecycle event
	 *
	 * Privacy rules per DATABASE.md: no raw IPs or user agents in meta;
	 * anonymous events pass 0/null actor and store actor_id NULL.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $certificate_id Certificate row id.
	 * @param string   $event_type     Event type slug (e.g. 'issued').
	 * @param int|null $actor_id       Acting user id; null/0 for anonymous.
	 * @param array    $meta           Optional event meta.
	 * @return int Event row id, or 0 on failure.
	 */
	public static function record_event( $certificate_id, $event_type, $actor_id = null, $meta = [] ) {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::events_table(),
			[
				'certificate_id' => absint( $certificate_id ),
				'event_type'     => sanitize_key( (string) $event_type ),
				'actor_id'       => $actor_id ? absint( $actor_id ) : null,
				'meta_json'      => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
				'created_at'     => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%d', '%s', '%s' ]
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Find an existing non-revoked duplicate
	 *
	 * Duplicate suppression key: (recipient_id, template_id, source_type,
	 * source_ref) - Feature 003 FR-001 step 2. Revoked certificates do
	 * not suppress (an admin may legitimately reissue after revocation).
	 *
	 * @since 1.0.0
	 *
	 * @param int         $recipient_id Recipient user id.
	 * @param int         $template_id  Template row id.
	 * @param string      $source_type  Source type slug.
	 * @param string|null $source_ref   Source reference, or null (manual).
	 * @return object|null Existing certificate row, or null.
	 */
	public static function find_duplicate( $recipient_id, $template_id, $source_type, $source_ref ) {
		global $wpdb;

		if ( null === $source_ref || '' === $source_ref ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE recipient_id = %d AND template_id = %d AND source_type = %s AND source_ref IS NULL AND status != 'revoked'",
					self::table(),
					absint( $recipient_id ),
					absint( $template_id ),
					sanitize_key( $source_type )
				)
			);
		} else {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE recipient_id = %d AND template_id = %d AND source_type = %s AND source_ref = %s AND status != 'revoked'",
					self::table(),
					absint( $recipient_id ),
					absint( $template_id ),
					sanitize_key( $source_type ),
					(string) $source_ref
				)
			);
		}

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * One batch of a recipient's certificates, oldest first
	 *
	 * The privacy exporter/eraser's paging query (Feature 008 FR-005):
	 * template titles join in for the export's subject field, fixed
	 * batch stride, no filtering beyond the recipient.
	 *
	 * @since 1.0.0
	 *
	 * @param int $recipient_id Recipient user id.
	 * @param int $limit        Batch size.
	 * @param int $offset       Row offset.
	 * @return object[] Hydrated rows with a template_title property.
	 */
	public static function get_batch_for_recipient( $recipient_id, $limit, $offset = 0 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.*, t.title AS template_title FROM %i c LEFT JOIN %i t ON t.id = c.template_id WHERE c.recipient_id = %d ORDER BY c.id ASC LIMIT %d OFFSET %d',
				self::table(),
				PressPrimer_Certificate_Template::table(),
				absint( $recipient_id ),
				absint( $limit ),
				absint( $offset )
			)
		);

		return array_map( [ __CLASS__, 'hydrate' ], (array) $rows );
	}

	/**
	 * A recipient's certificates, most recently earned first
	 *
	 * The user-profile listing (Phase 5B item 9): newest issued_at at
	 * the top, id as the tiebreaker, template titles joined in.
	 * Separate from get_batch_for_recipient because ORDER direction is
	 * never interpolated - each direction is its own prepared statement
	 * (CLAUDE.md SQL rules).
	 *
	 * @since 1.0.0
	 *
	 * @param int $recipient_id Recipient user id.
	 * @param int $limit        Page size.
	 * @param int $offset       Row offset.
	 * @return object[] Hydrated rows with a template_title property.
	 */
	public static function get_recent_for_recipient( $recipient_id, $limit, $offset = 0 ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.*, t.title AS template_title FROM %i c LEFT JOIN %i t ON t.id = c.template_id WHERE c.recipient_id = %d ORDER BY c.issued_at DESC, c.id DESC LIMIT %d OFFSET %d',
				self::table(),
				PressPrimer_Certificate_Template::table(),
				absint( $recipient_id ),
				absint( $limit ),
				absint( $offset )
			)
		);

		return array_map( [ __CLASS__, 'hydrate' ], (array) $rows );
	}

	/**
	 * Count a recipient's certificates
	 *
	 * @since 1.0.0
	 *
	 * @param int $recipient_id Recipient user id.
	 * @return int
	 */
	public static function count_for_recipient( $recipient_id ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE recipient_id = %d',
				self::table(),
				absint( $recipient_id )
			)
		);
	}

	/**
	 * Count every certificate ever issued
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function count_all() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() )
		);
	}

	/**
	 * Count certificates issued on or after a UTC cutoff
	 *
	 * @since 1.0.0
	 *
	 * @param string $cutoff UTC MySQL datetime.
	 * @return int
	 */
	public static function count_issued_since( $cutoff ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE issued_at >= %s',
				self::table(),
				$cutoff
			)
		);
	}

	/**
	 * Count events of one type recorded on or after a UTC cutoff
	 *
	 * Feeds the dashboard's verification counter from wp_ppcert_events.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event_type Event type slug (e.g. 'verified').
	 * @param string $cutoff     UTC MySQL datetime.
	 * @return int
	 */
	public static function count_events_since( $event_type, $cutoff ) {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE event_type = %s AND created_at >= %s',
				self::events_table(),
				sanitize_key( $event_type ),
				$cutoff
			)
		);
	}

	/**
	 * Daily issuance counts on or after a UTC cutoff
	 *
	 * Grouped by the UTC calendar day of issued_at; days with no
	 * certificates are absent (the dashboard controller zero-fills).
	 *
	 * @since 1.0.0
	 *
	 * @param string $cutoff UTC MySQL datetime.
	 * @return array Map of 'Y-m-d' => count.
	 */
	public static function get_daily_issue_counts( $cutoff ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE( issued_at ) AS day, COUNT(*) AS total FROM %i WHERE issued_at >= %s GROUP BY DATE( issued_at ) ORDER BY day ASC',
				self::table(),
				$cutoff
			)
		);

		$counts = [];

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row->day ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Templates ranked by certificates issued
	 *
	 * All-time counts; a NULL title marks a deleted template (the
	 * dashboard labels it). Ties break on template id so the order is
	 * deterministic.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Max rows.
	 * @return object[] Rows with template_id, title, total.
	 */
	public static function get_top_templates( $limit ) {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT c.template_id, t.title, COUNT(*) AS total FROM %i c LEFT JOIN %i t ON t.id = c.template_id GROUP BY c.template_id, t.title ORDER BY total DESC, c.template_id ASC LIMIT %d',
				self::table(),
				PressPrimer_Certificate_Template::table(),
				absint( $limit )
			)
		);
	}

	/**
	 * Hydrate a raw row: decode the JSON snapshot columns
	 *
	 * Adds `layout_snapshot` and `merge_data` array properties (null when
	 * the column is empty or malformed). The raw *_json strings remain
	 * untouched - the stored snapshot is never rewritten (migrate-on-read
	 * happens in the renderer path, per layout-schema.md).
	 *
	 * @since 1.0.0
	 *
	 * @param object $row Raw wpdb row.
	 * @return object Hydrated row.
	 */
	private static function hydrate( $row ) {
		$row->layout_snapshot = null;
		$row->merge_data      = null;

		if ( ! empty( $row->layout_snapshot_json ) ) {
			$decoded = json_decode( $row->layout_snapshot_json, true );

			if ( is_array( $decoded ) ) {
				$row->layout_snapshot = $decoded;
			}
		}

		if ( ! empty( $row->merge_data_json ) ) {
			$decoded = json_decode( $row->merge_data_json, true );

			if ( is_array( $decoded ) ) {
				$row->merge_data = $decoded;
			}
		}

		return $row;
	}
}
