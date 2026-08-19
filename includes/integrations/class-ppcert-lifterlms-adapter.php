<?php
/**
 * LifterLMS adapter
 *
 * Course-completion adapter (Feature 004 FR-004 lms_lifterlms row).
 * Sources are posts, so the shared course helpers in the abstract class
 * do the field work; this class is the LifterLMS-specific listener.
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
 * LifterLMS adapter class
 *
 * Monitor-only, like every adapter: LifterLMS marks the course
 * complete, then we issue. The listener performs no LifterLMS writes.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_LifterLMS_Adapter extends PressPrimer_Certificate_LMS_Adapter {

	/**
	 * Trigger type id (FR-004 contract)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRIGGER_TYPE = 'lms_lifterlms';

	/**
	 * LifterLMS course post type
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const COURSE_POST_TYPE = 'course';

	/**
	 * Adapter id
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return self::TRIGGER_TYPE;
	}

	/**
	 * Trigger type label for the designer's Award tab
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Course completed (LifterLMS)', 'pressprimer-certificate' );
	}

	/**
	 * Source noun for the Award tab and merge-field palette
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_source_group_label(): string {
		return __( 'Course', 'pressprimer-certificate' );
	}

	/**
	 * "Any" option label (leaf-only Any, Feature 1.1-002 FR-003).
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_any_source_label(): string {
		return __( 'Any course', 'pressprimer-certificate' );
	}

	/**
	 * Integration name for the two-step trigger picker
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_integration_label(): string {
		return __( 'LifterLMS', 'pressprimer-certificate' );
	}

	/**
	 * Short trigger label (integration already chosen)
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_short_label(): string {
		return __( 'Course completed', 'pressprimer-certificate' );
	}

	/**
	 * Courses are posts: unlocks the source-meta picker (Feature 002).
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_source_post_types(): array {
		return [ self::COURSE_POST_TYPE ];
	}

	/**
	 * Availability: cheap class check (FR-002)
	 *
	 * The LifterLMS main class loads whenever the plugin is active
	 * (verified against LifterLMS 10.0.8).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( 'LifterLMS' );
	}

	/**
	 * Listen for completed courses
	 *
	 * Hook citation (LifterLMS 10.0.8): `lifterlms_course_completed`
	 * fires via do_action( "lifterlms_{$object_type}_{$status}d" ) in
	 * LLMS_Student::update_completion_status()
	 * (includes/models/model.llms.student.php:1887) AFTER the
	 * completion postmeta is inserted - completion state is final when
	 * we run (TR-003). Signature: ( int $student_id, int $course_id ).
	 * LifterLMS cascades child completions upward, which can re-fire
	 * completion events on progress recalculation - idempotent listener
	 * + engine suppression yield exactly one certificate (Edge Cases).
	 * Priority 20 keeps us after LifterLMS's own default-priority
	 * listeners (e.g. its engagements engine).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		add_action( 'lifterlms_course_completed', [ $this, 'handle_course_completed' ], 20, 2 );
	}

	/**
	 * Selectable sources: published LifterLMS courses
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_sources( string $search = '' ): array {
		return $this->get_post_sources( self::COURSE_POST_TYPE, $search );
	}

	/**
	 * Conditions schema: none in 1.0 (FR-004)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_conditions_schema(): array {
		return [];
	}

	/**
	 * Contributed merge fields: the shared course set (FR-004)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_merge_fields(): array {
		return $this->course_merge_fields();
	}

	/**
	 * Bulk merge resolution (abstract contract)
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Issuance context.
	 * @return array<string,string>
	 */
	public function resolve_merge_data( array $context ): array {
		return $this->resolve_course_merge_data( $context );
	}

	/**
	 * The course-completed listener (FR-003)
	 *
	 * Context is built server-side from the persisted course post -
	 * never from request data (Security Requirements). LifterLMS's
	 * event carries no completion moment, so the listener stamps the
	 * event time in UTC; a suppressed re-fire never overwrites it.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id   Student user id.
	 * @param int $course_id Completed course id.
	 * @return void
	 */
	public function handle_course_completed( $user_id, $course_id ) {
		$user_id   = absint( $user_id );
		$course_id = absint( $course_id );

		if ( $user_id < 1 || $course_id < 1 ) {
			return;
		}

		$triggers = PressPrimer_Certificate_Trigger::find_active( self::TRIGGER_TYPE, (string) $course_id );

		if ( empty( $triggers ) ) {
			return;
		}

		$course = get_post( $course_id );

		if ( ! is_object( $course ) || self::COURSE_POST_TYPE !== (string) $course->post_type ) {
			return;
		}

		$context = $this->build_course_context( $course, gmdate( 'Y-m-d H:i:s' ) );

		foreach ( $triggers as $trigger ) {
			// No conditions in 1.0: every completion issues, and the
			// engine suppresses duplicates (Feature 003).
			PressPrimer_Certificate_Issuance_Service::issue(
				[
					'template_id'  => (int) $trigger->template_id,
					'recipient_id' => $user_id,
					'source_type'  => self::TRIGGER_TYPE,
					'source_ref'   => (string) $course_id,
					'issued_by'    => 0,
					'context'      => $context,
				]
			);
		}
	}
}
