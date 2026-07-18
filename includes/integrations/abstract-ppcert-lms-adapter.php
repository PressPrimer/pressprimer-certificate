<?php
/**
 * LMS adapter interface
 *
 * THE adapter contract all six 1.0 adapters implement (PPQ, PPA,
 * LearnDash, LifterLMS, Tutor LMS, LearnPress).
 *
 * @package PressPrimer_Certificate
 * @subpackage Integrations
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract LMS adapter
 *
 * LOCKED at the Prompt 1.5 checkpoint (Feature 004 FR-001). The seven
 * abstract methods below are the contract from CODE-STRUCTURE.md "The LMS
 * Adapter Interface"; changing any signature after the lock requires
 * explicit approval and touches all adapters.
 *
 * The concrete methods are shared registration glue: adapters instantiate
 * on `ppcert_loaded` and call register(), which no-ops unless
 * is_available() - so an adapter whose source plugin is missing registers
 * nothing and its triggers go inert (Feature 004 FR-002, Edge US-5).
 * Registration happens only through the two public filters; adapters are
 * architecturally identical to third-party integrations and core has no
 * adapter-specific branches.
 *
 * @since 1.0.0
 */
abstract class PressPrimer_Certificate_LMS_Adapter {
	/*
	 * ------------------------------------------------------------------
	 * THE LOCKED CONTRACT (CODE-STRUCTURE.md). Do not change signatures.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Unique adapter id, e.g. 'lms_learndash'. Doubles as trigger_type.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	abstract public function get_id(): string;

	/**
	 * Whether the source plugin is active on this site.
	 *
	 * Must be a cheap constant/class/function check (Feature 004 FR-002).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	abstract public function is_available(): bool;

	/**
	 * Register runtime listeners for the source plugin's completion events.
	 *
	 * Listeners must be idempotent and run after the source plugin's own
	 * completion state is final (Feature 004 TR-003); each adapter's build
	 * prompt documents the chosen hook and priority with a source citation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	abstract public function register_listeners(): void;

	/**
	 * Selectable sources for the designer's trigger panel (courses, quizzes...).
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Optional search term.
	 * @return array List of sources, each [ 'id' => scalar, 'title' => string ].
	 */
	abstract public function get_sources( string $search = '' ): array;

	/**
	 * Merge field definitions this adapter contributes (ppcert_register_merge_fields).
	 *
	 * Shape: [ group => [ field_key => definition ] ] where each definition
	 * carries key/label/sample/resolver per HOOKS.md. Adapter course/quiz
	 * fields conventionally live in the 'source' group.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	abstract public function get_merge_fields(): array;

	/**
	 * Resolve merge field values for an issuance context.
	 *
	 * The context is built server-side inside the listener - never from
	 * request data (Feature 004 Security Requirements).
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return array Map of token key => resolved value.
	 */
	abstract public function resolve_merge_data( array $context ): array;

	/**
	 * Validation schema for this adapter's trigger conditions_json.
	 *
	 * Declarative format per Feature 004 TR-002; the trigger panel renders
	 * it and the trigger registry sanitizes conditions against it.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	abstract public function get_conditions_schema(): array;

	/*
	 * ------------------------------------------------------------------
	 * Shared registration glue (concrete; not part of the abstract
	 * contract - see CODE-STRUCTURE.md "The LMS Adapter Interface").
	 * ------------------------------------------------------------------
	 */

	/**
	 * Register this adapter with core
	 *
	 * Called on `ppcert_loaded`. No-ops when the source plugin is absent:
	 * no trigger type, no merge fields, no listeners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! $this->is_available() ) {
			return;
		}

		add_filter( 'ppcert_register_trigger_types', [ $this, 'register_trigger_type' ] );
		add_filter( 'ppcert_register_merge_fields', [ $this, 'register_merge_fields' ], 10, 2 );

		$this->register_listeners();
	}

	/**
	 * Human-readable trigger type label for the designer's trigger panel
	 *
	 * Adapters override this with a translated label; the default derives
	 * a readable form from the id.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return ucwords( str_replace( [ '_', '-' ], ' ', $this->get_id() ) );
	}

	/**
	 * Filter callback: contribute this adapter's trigger type entry
	 *
	 * Entry shape per HOOKS.md ppcert_register_trigger_types.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $types Registered trigger types.
	 * @return array
	 */
	public function register_trigger_type( $types ): array {
		$types = is_array( $types ) ? $types : [];

		$types[ $this->get_id() ] = [
			'id'                => $this->get_id(),
			'label'             => $this->get_label(),
			'source_picker'     => [ $this, 'get_sources' ],
			'conditions_schema' => $this->get_conditions_schema(),
		];

		return $types;
	}

	/**
	 * Filter callback: contribute this adapter's merge fields
	 *
	 * Merges get_merge_fields() group-wise into the registry array.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $fields  Registered merge fields.
	 * @param string $context 'designer' (palette) or 'issue' (resolution).
	 * @return array
	 */
	public function register_merge_fields( $fields, $context = 'designer' ): array {
		$fields = is_array( $fields ) ? $fields : [];

		foreach ( $this->get_merge_fields() as $group => $group_fields ) {
			foreach ( (array) $group_fields as $field_key => $definition ) {
				$fields[ $group ][ $field_key ] = $definition;
			}
		}

		return $fields;
	}
}
