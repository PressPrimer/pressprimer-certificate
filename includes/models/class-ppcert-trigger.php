<?php
/**
 * Trigger model
 *
 * CRUD for the wp_ppcert_triggers table.
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
 * Trigger model class
 *
 * Maps templates to issuance events (DATABASE.md wp_ppcert_triggers).
 * Saves are replace-set per template - the designer's trigger panel sends
 * the complete trigger list and the previous set is replaced atomically
 * from the caller's perspective. Conditions are sanitized by
 * PressPrimer_Certificate_Trigger_Registry::sanitize_conditions() in the
 * REST layer before they reach this model.
 *
 * All datetimes are written UTC via current_time( 'mysql', true )
 * (CLAUDE.md Datetime Standard).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Trigger {

	/**
	 * Get the full triggers table name
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'ppcert_triggers';
	}

	/**
	 * Trigger types actually in use by any template
	 *
	 * Drives the Templates list's integration filter: only integrations
	 * with at least one trigger row appear, so the dropdown never lists
	 * plugins the site does not use (Ryan, 2026-07-30).
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Distinct trigger_type ids.
	 */
	public static function get_used_types() {
		global $wpdb;

		$types = $wpdb->get_col(
			$wpdb->prepare( 'SELECT DISTINCT trigger_type FROM %i', self::table() )
		);

		return array_map( 'strval', (array) $types );
	}

	/**
	 * Get one trigger by row id
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Trigger row id.
	 * @return object|null Row object, or null when not found.
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
	 * Get all triggers for a template
	 *
	 * @since 1.0.0
	 *
	 * @param int $template_id Template row id.
	 * @return object[] Rows, in insertion order.
	 */
	public static function get_for_template( $template_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE template_id = %d ORDER BY id ASC',
				self::table(),
				absint( $template_id )
			)
		);

		return array_map( [ __CLASS__, 'hydrate' ], (array) $rows );
	}

	/**
	 * Batch-fetch triggers for a set of templates
	 *
	 * One query for the templates list's Trigger column (never a query
	 * per row). FIND_IN_SET keeps the prepared shape fixed regardless of
	 * how many ids the page holds.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $template_ids Template row ids.
	 * @return array Map of template_id => trigger rows.
	 */
	public static function get_for_templates( array $template_ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $template_ids ) );

		if ( empty( $ids ) ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE FIND_IN_SET( template_id, %s ) ORDER BY id ASC',
				self::table(),
				implode( ',', $ids )
			)
		);

		$map = [];

		foreach ( array_map( [ __CLASS__, 'hydrate' ], (array) $rows ) as $trigger ) {
			$map[ (int) $trigger->template_id ][] = $trigger;
		}

		return $map;
	}

	/**
	 * Reserved source ref matching every source of the trigger's type
	 *
	 * "Any" triggers (1.1, Feature 002): one trigger row fires for every
	 * object of its type. Adapter source ids are numeric strings (post
	 * ids / custom-table ids), so the sentinel cannot collide with a
	 * real ref. Hierarchical types additionally scope 'any' through
	 * parent-scope conditions keys (registry scope_condition_keys),
	 * verified at fire time by the adapter. Certificates always record
	 * the REAL fired ref - this value never reaches a certificate row.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const SOURCE_ANY = 'any';

	/**
	 * Find active triggers for a source event
	 *
	 * The hot path: issuance listeners call this on every candidate event.
	 * The query is served by the trigger_lookup index
	 * (trigger_type, source_ref, is_active). Matches the exact fired ref
	 * plus the reserved SOURCE_ANY sentinel (1.1) - a specific trigger
	 * and an 'any' trigger on different templates both fire from one
	 * event; on the same template the duplicate engine arbitrates.
	 *
	 * @since 1.0.0
	 *
	 * @param string $trigger_type Trigger type id (e.g. 'ppq_quiz').
	 * @param string $source_ref   Source object reference.
	 * @return object[] Matching active trigger rows.
	 */
	public static function find_active( $trigger_type, $source_ref ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE trigger_type = %s AND ( source_ref = %s OR source_ref = %s ) AND is_active = 1',
				self::table(),
				sanitize_key( $trigger_type ),
				sanitize_text_field( (string) $source_ref ),
				self::SOURCE_ANY
			)
		);

		return array_map( [ __CLASS__, 'hydrate' ], (array) $rows );
	}

	/**
	 * Replace a template's trigger set
	 *
	 * Deletes the template's existing triggers and inserts the given set
	 * (the designer's replace-set save model). Each trigger:
	 * - trigger_type (string, required - a registered trigger type id)
	 * - source_ref   (string|null)
	 * - conditions   (array|null - pre-sanitized by the trigger registry)
	 * - is_active    (bool, default true)
	 *
	 * @since 1.0.0
	 *
	 * @param int   $template_id Template row id.
	 * @param array $triggers    New trigger set.
	 * @return object[] The inserted rows.
	 */
	public static function replace_for_template( $template_id, array $triggers ) {
		global $wpdb;

		$template_id = absint( $template_id );
		$now         = current_time( 'mysql', true );

		self::delete_for_template( $template_id );

		foreach ( $triggers as $trigger ) {
			if ( ! is_array( $trigger ) || empty( $trigger['trigger_type'] ) ) {
				continue;
			}

			$source_ref = isset( $trigger['source_ref'] ) && '' !== $trigger['source_ref']
				? substr( sanitize_text_field( (string) $trigger['source_ref'] ), 0, 191 )
				: null;

			$conditions = isset( $trigger['conditions'] ) && is_array( $trigger['conditions'] ) && ! empty( $trigger['conditions'] )
				? wp_json_encode( $trigger['conditions'] )
				: null;

			$wpdb->insert(
				self::table(),
				[
					'uuid'            => wp_generate_uuid4(),
					'template_id'     => $template_id,
					'trigger_type'    => sanitize_key( (string) $trigger['trigger_type'] ),
					'source_ref'      => $source_ref,
					'conditions_json' => $conditions,
					'is_active'       => ( ! isset( $trigger['is_active'] ) || $trigger['is_active'] ) ? 1 : 0,
					'created_at'      => $now,
					'updated_at'      => $now,
				],
				[ '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
			);
		}

		return self::get_for_template( $template_id );
	}

	/**
	 * Delete all triggers for a template
	 *
	 * @since 1.0.0
	 *
	 * @param int $template_id Template row id.
	 * @return int Number of rows deleted.
	 */
	public static function delete_for_template( $template_id ) {
		global $wpdb;

		return (int) $wpdb->delete(
			self::table(),
			[ 'template_id' => absint( $template_id ) ],
			[ '%d' ]
		);
	}

	/**
	 * Hydrate a raw row: decode conditions_json to an array
	 *
	 * Adds a `conditions` property (array|null). json_decode is not
	 * sanitization - stored conditions were sanitized against their
	 * trigger type's schema before save, and consumers re-validate types
	 * when evaluating.
	 *
	 * @since 1.0.0
	 *
	 * @param object $row Raw wpdb row.
	 * @return object Row with decoded conditions.
	 */
	private static function hydrate( $row ) {
		$row->conditions = null;

		if ( ! empty( $row->conditions_json ) ) {
			$decoded = json_decode( $row->conditions_json, true );

			if ( is_array( $decoded ) ) {
				$row->conditions = $decoded;
			}
		}

		return $row;
	}
}
