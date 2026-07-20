<?php
/**
 * Template model
 *
 * Read access to the wp_ppcert_templates table.
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
 * Template model class
 *
 * Minimal in Phase 2: the issuance service needs template loading and
 * status verification. Create/update/list arrive with the templates REST
 * controller (Prompt 3.1); editing a template never alters issued
 * certificates (they carry their own snapshot).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Template {

	/**
	 * Get the full templates table name
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'ppcert_templates';
	}

	/**
	 * Get one template by row id
	 *
	 * Soft-deleted templates (deleted_at set) are not returned. The row's
	 * layout_json is decoded onto a `layout` property; the raw JSON string
	 * is preserved for snapshotting.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Template row id.
	 * @return object|null Row object with decoded layout, or null.
	 */
	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL',
				self::table(),
				absint( $id )
			)
		);

		if ( ! $row ) {
			return null;
		}

		$row->layout = null;

		if ( ! empty( $row->layout_json ) ) {
			$decoded = json_decode( $row->layout_json, true );

			if ( is_array( $decoded ) ) {
				$row->layout = $decoded;
			}
		}

		return $row;
	}
}
