<?php
/**
 * Email template model
 *
 * Read access to the wp_ppcert_email_templates table (Decision 005).
 *
 * @package PressPrimer_Certificate
 * @subpackage Models
 * @since 2.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email template model class
 *
 * Minimal in free 2.0: the table ships schema-only, and the free plugin
 * needs exactly two answers from it - "does this row exist?" (save-time
 * validation of settings_json.email_template_id) and "give me the mapped
 * active row" (the email builder's resolution chain). Create/update/list
 * arrive with the Educator addon's manager UI; Educator writes reminder
 * rows through its own code against this same model.
 *
 * @since 2.0.0
 */
class PressPrimer_Certificate_Email_Template {

	/**
	 * Get the full email templates table name
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'ppcert_email_templates';
	}

	/**
	 * Get one email template by row id
	 *
	 * Soft-deleted rows (deleted_at set) are not returned; archived rows
	 * are (archiving hides a template from pickers without invalidating
	 * existing mappings - the resolution chain skips them separately).
	 *
	 * @since 2.0.0
	 *
	 * @param int $id Email template row id.
	 * @return object|null Row object, or null.
	 */
	public static function get( $id ) {
		global $wpdb;

		$id = absint( $id );

		if ( $id < 1 ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL',
				self::table(),
				$id
			)
		);
	}

	/**
	 * Whether a non-deleted row with this id exists
	 *
	 * The save-time validation for settings_json.email_template_id
	 * (Feature 2.0-006 FR-001): archived rows still validate - only
	 * soft-deleted rows reject the mapping.
	 *
	 * @since 2.0.0
	 *
	 * @param int $id Email template row id.
	 * @return bool
	 */
	public static function exists( $id ) {
		return null !== self::get( $id );
	}

	/**
	 * Get one ACTIVE email template by row id
	 *
	 * The resolution chain's lookup (Decision 005): soft-deleted and
	 * archived rows are treated as absent, so callers fall back to the
	 * built-in default email.
	 *
	 * @since 2.0.0
	 *
	 * @param int $id Email template row id.
	 * @return object|null Active row object, or null.
	 */
	public static function get_active( $id ) {
		$row = self::get( $id );

		if ( ! $row || 'active' !== (string) $row->status ) {
			return null;
		}

		return $row;
	}
}
