<?php
/**
 * Trigger type registry
 *
 * Core consumption of the ppcert_register_trigger_types filter and the
 * conditions_json sanitizer.
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
 * Trigger registry class
 *
 * Collects trigger types registered through `ppcert_register_trigger_types`
 * (HOOKS.md) - adapters and third-party integrations register through the
 * same filter; core has no adapter-specific branches. Provides the
 * schema-walking sanitizer for conditions_json (Feature 004 TR-002):
 * unknown keys stripped, types coerced, numeric ranges clamped. The
 * triggers REST controller runs every inbound conditions object through
 * sanitize_conditions() before save.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Trigger_Registry {

	/**
	 * Get all registered trigger types, keyed by id
	 *
	 * Malformed entries (missing/invalid id) are dropped; every returned
	 * entry carries id, label, source_picker (callable|null), and
	 * conditions_schema (array).
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array> Map of trigger type id => entry.
	 */
	public static function get_types() {
		/** This filter is documented in docs/architecture/HOOKS.md */
		$raw = apply_filters( 'ppcert_register_trigger_types', [] );

		$types = [];

		foreach ( (array) $raw as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['id'] ) || ! is_string( $entry['id'] ) ) {
				continue;
			}

			$id = sanitize_key( $entry['id'] );

			if ( '' === $id ) {
				continue;
			}

			$label = isset( $entry['label'] ) && is_string( $entry['label'] ) ? $entry['label'] : $id;

			// Cascade levels ({key,label} pairs) for hierarchical source
			// pickers; malformed entries are dropped.
			$source_levels = [];

			if ( isset( $entry['source_levels'] ) && is_array( $entry['source_levels'] ) ) {
				foreach ( $entry['source_levels'] as $level ) {
					if ( is_array( $level ) && ! empty( $level['key'] ) && is_string( $level['key'] ) ) {
						$source_levels[] = [
							'key'   => sanitize_key( $level['key'] ),
							'label' => isset( $level['label'] ) && is_string( $level['label'] ) ? $level['label'] : $level['key'],
						];
					}
				}
			}

			$types[ $id ] = [
				'id'                   => $id,
				'label'                => $label,
				// Two-step picker metadata: the integration name leads,
				// then its triggers by short label.
				'integration'          => isset( $entry['integration'] ) && is_string( $entry['integration'] ) ? $entry['integration'] : $label,
				'short_label'          => isset( $entry['short_label'] ) && is_string( $entry['short_label'] ) ? $entry['short_label'] : $label,
				// Noun for the type's sources ("Quiz", "Course") - labels
				// the Award tab source line and the palette source group.
				'source_label'         => isset( $entry['source_label'] ) && is_string( $entry['source_label'] ) ? $entry['source_label'] : '',
				'source_picker'        => isset( $entry['source_picker'] ) && is_callable( $entry['source_picker'] ) ? $entry['source_picker'] : null,
				'source_levels'        => $source_levels,
				'level_picker'         => isset( $entry['level_picker'] ) && is_callable( $entry['level_picker'] ) ? $entry['level_picker'] : null,
				'scoped_picker'        => isset( $entry['scoped_picker'] ) && is_callable( $entry['scoped_picker'] ) ? $entry['scoped_picker'] : null,
				'conditions_schema'    => isset( $entry['conditions_schema'] ) && is_array( $entry['conditions_schema'] ) ? $entry['conditions_schema'] : [],
				// Optional: post types this trigger's sources live in.
				// The post-meta picker validates its post_id against the
				// union of these (Feature 002 TR-002).
				'source_post_types'    => isset( $entry['source_post_types'] ) && is_array( $entry['source_post_types'] )
					? array_values( array_filter( array_map( 'sanitize_key', $entry['source_post_types'] ) ) )
					: [],
				// Parent-scope conditions keys for 'any' triggers
				// (Feature 1.1-002): required in conditions when the
				// ref is the SOURCE_ANY sentinel; never rendered as
				// condition controls.
				'scope_condition_keys' => isset( $entry['scope_keys'] ) && is_array( $entry['scope_keys'] )
					? array_values( array_filter( array_map( 'sanitize_key', $entry['scope_keys'] ) ) )
					: [],
			];

			// Every trigger type carries the universal reissue toggle
			// (2026-07-24): by default a recipient earns a given
			// certificate once per trigger and repeat completions are
			// suppressed; compliance and recertification sites turn
			// this on per trigger so every qualifying completion issues
			// a fresh certificate. Appended here (not per adapter) so
			// addon-registered types inherit it too; 'reissue' is a
			// reserved conditions key.
			$types[ $id ]['conditions_schema']['reissue'] = [
				'type'    => 'toggle',
				'label'   => __( 'Issue again on repeat completion', 'pressprimer-certificate' ),
				'help'    => __( 'By default a recipient can earn this certificate only once from this trigger, and completing again does nothing. Turn this on for compliance or recertification courses so every completion issues a new certificate with its own credential ID.', 'pressprimer-certificate' ),
				'default' => false,
			];
		}

		return $types;
	}

	/**
	 * Get one registered trigger type
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Trigger type id.
	 * @return array|null The entry, or null when unregistered.
	 */
	public static function get_type( $id ) {
		$types = self::get_types();

		return isset( $types[ $id ] ) ? $types[ $id ] : null;
	}

	/**
	 * Sanitize a conditions object against its trigger type's schema
	 *
	 * Walks the registered conditions_schema (Feature 004 TR-002): the
	 * output contains exactly the schema's keys - unknown input keys are
	 * stripped, values are coerced per field type, numbers clamp to
	 * min/max, and absent or uncoercible values fall back to the field's
	 * default (null when no default is declared). An unregistered trigger
	 * type yields an empty array.
	 *
	 * @since 1.0.0
	 *
	 * @param string $trigger_type Trigger type id.
	 * @param mixed  $conditions   Raw conditions (decoded array).
	 * @return array Sanitized conditions.
	 */
	public static function sanitize_conditions( $trigger_type, $conditions ) {
		$type = self::get_type( $trigger_type );

		if ( null === $type || empty( $type['conditions_schema'] ) ) {
			return [];
		}

		$conditions = is_array( $conditions ) ? $conditions : [];
		$clean      = [];

		foreach ( $type['conditions_schema'] as $key => $field ) {
			if ( ! is_string( $key ) || '' === $key || ! is_array( $field ) ) {
				continue;
			}

			$default = array_key_exists( 'default', $field ) ? $field['default'] : null;
			$has     = array_key_exists( $key, $conditions );
			$value   = $has ? $conditions[ $key ] : null;

			$field_type = isset( $field['type'] ) ? (string) $field['type'] : 'text';

			switch ( $field_type ) {
				case 'number':
					$clean[ $key ] = $has && is_numeric( $value )
						? self::clamp_number( (float) $value, $field )
						: $default;
					break;

				case 'toggle':
					$clean[ $key ] = $has
						? ( true === $value || 1 === $value || '1' === $value || 'true' === $value )
						: (bool) $default;
					break;

				case 'select':
					$options       = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];
					$clean[ $key ] = ( $has && in_array( $value, $options, true ) ) ? $value : $default;
					break;

				case 'text':
				default:
					$clean[ $key ] = $has ? sanitize_text_field( (string) $value ) : $default;
					break;
			}
		}

		// Parent-scope keys ('any' triggers, Feature 1.1-002): carried in
		// conditions but never part of conditions_schema - the Award
		// tab's cascade selects write them. Object ids sanitize to
		// numeric strings; anything else stores null.
		foreach ( $type['scope_condition_keys'] as $key ) {
			$id            = isset( $conditions[ $key ] ) ? absint( $conditions[ $key ] ) : 0;
			$clean[ $key ] = $id > 0 ? (string) $id : null;
		}

		return $clean;
	}

	/**
	 * Validate a trigger's source ref against its type's scope contract
	 *
	 * An 'any' trigger of a hierarchical type must carry every declared
	 * parent-scope key in its (already sanitized) conditions - leaf-only
	 * "Any" (Feature 1.1-002 FR-002). Specific refs and flat types pass.
	 * Unregistered (inert) types pass: their contract is unknowable
	 * until the adapter returns, and the designer cannot create them.
	 *
	 * @since 1.1.0
	 *
	 * @param string $trigger_type Trigger type id.
	 * @param string $source_ref   Source ref (may be the 'any' sentinel).
	 * @param array  $conditions   Sanitized conditions.
	 * @return true|WP_Error
	 */
	public static function validate_trigger_source( $trigger_type, $source_ref, array $conditions ) {
		if ( PressPrimer_Certificate_Trigger::SOURCE_ANY !== (string) $source_ref ) {
			return true;
		}

		$type = self::get_type( $trigger_type );

		if ( null === $type ) {
			return true;
		}

		foreach ( $type['scope_condition_keys'] as $key ) {
			if ( empty( $conditions[ $key ] ) ) {
				return new WP_Error(
					'ppcert_trigger_scope_required',
					__( 'An "Any" trigger of this type must be scoped to a parent (for example, a specific course).', 'pressprimer-certificate' ),
					[
						'status' => 400,
						'key'    => $key,
					]
				);
			}
		}

		return true;
	}

	/**
	 * Clamp a number to a schema field's min/max bounds
	 *
	 * @since 1.0.0
	 *
	 * @param float $value Numeric value.
	 * @param array $field Schema field (optional min/max).
	 * @return float
	 */
	private static function clamp_number( $value, $field ) {
		if ( isset( $field['min'] ) && is_numeric( $field['min'] ) ) {
			$value = max( (float) $field['min'], $value );
		}

		if ( isset( $field['max'] ) && is_numeric( $field['max'] ) ) {
			$value = min( (float) $field['max'], $value );
		}

		return $value;
	}
}
