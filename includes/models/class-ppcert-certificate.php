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
