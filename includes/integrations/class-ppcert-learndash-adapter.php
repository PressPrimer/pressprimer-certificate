<?php
/**
 * LearnDash adapter
 *
 * Course-completion adapter (Feature 004 FR-004 lms_learndash row).
 * Sources are posts, so the shared course helpers in the abstract class
 * do the field work; this class is the LearnDash-specific listener.
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
 * LearnDash adapter class
 *
 * Monitor-only, like every adapter: LearnDash marks the course
 * complete, then we issue. The listener performs no LearnDash writes.
 * Supported identically to every other LMS and never headlined (FR-005).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_LearnDash_Adapter extends PressPrimer_Certificate_LMS_Adapter {

	/**
	 * Trigger type id (FR-004 contract)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRIGGER_TYPE = 'lms_learndash';

	/**
	 * LearnDash course post type
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const COURSE_POST_TYPE = 'sfwd-courses';

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
		return __( 'Course completed (LearnDash)', 'pressprimer-certificate' );
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
	 * Integration name for the two-step trigger picker
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_integration_label(): string {
		return __( 'LearnDash', 'pressprimer-certificate' );
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
	 * Availability: cheap constant check (FR-002)
	 *
	 * LEARNDASH_VERSION is defined unconditionally in sfwd_lms.php
	 * (verified against LearnDash 4.23.0).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return defined( 'LEARNDASH_VERSION' );
	}

	/**
	 * Steps of one type within a course, as picker options
	 *
	 * Uses LearnDash's course-steps API (includes nested steps and
	 * shared course steps); available whenever this adapter is, since it
	 * only registers with LearnDash active.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $course_id Course post id.
	 * @param string $post_type Step post type.
	 * @param string $search    Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	protected function ld_step_sources( int $course_id, string $post_type, string $search = '' ): array {
		if ( ! function_exists( 'learndash_course_get_steps_by_type' ) ) {
			return [];
		}

		$ids = learndash_course_get_steps_by_type( $course_id, $post_type );

		return $this->posts_to_options( array_map( 'get_post', array_filter( array_map( 'absint', (array) $ids ) ) ), $search );
	}

	/**
	 * Listen for completed courses
	 *
	 * Hook citation (LearnDash 4.23.0): `learndash_course_completed`
	 * fires in learndash_process_mark_complete()
	 * (includes/course/ld-course-progress.php:974) with ONE array arg:
	 * [ 'user' => WP_User, 'course' => WP_Post, 'progress' => array,
	 * 'course_completed' => Unix timestamp ]. It is guarded by
	 * $do_course_complete_action, which is set only when progress
	 * reaches 100% AND the stored activity was not already complete -
	 * the user activity row is written BEFORE the action, so completion
	 * state is final when we run (TR-003). Re-marking an already
	 * complete course does not re-fire; the engine's duplicate
	 * suppression backstops any recalc path that does. Priority 20
	 * keeps us after LearnDash's own default-priority listeners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		add_action( 'learndash_course_completed', [ $this, 'handle_course_completed' ], 20, 1 );
	}

	/**
	 * Selectable sources: published LearnDash courses
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
	 * Context is built server-side from LearnDash's own event payload -
	 * never from request data (Security Requirements).
	 *
	 * @since 1.0.0
	 *
	 * @param array $course_data LearnDash payload (user, course, course_completed).
	 * @return void
	 */
	public function handle_course_completed( $course_data ) {
		if ( ! is_array( $course_data ) || empty( $course_data['course'] ) || ! is_object( $course_data['course'] ) ) {
			return;
		}

		$course  = $course_data['course'];
		$user_id = isset( $course_data['user']->ID ) ? (int) $course_data['user']->ID : 0;

		if ( $user_id < 1 || empty( $course->ID ) ) {
			return;
		}

		$triggers = PressPrimer_Certificate_Trigger::find_active( self::TRIGGER_TYPE, (string) $course->ID );

		if ( empty( $triggers ) ) {
			return;
		}

		// LearnDash hands us the completion moment as a Unix timestamp;
		// gmdate() renders it UTC per the context contract.
		$completed_ts = isset( $course_data['course_completed'] ) && is_numeric( $course_data['course_completed'] )
			? (int) $course_data['course_completed']
			: time();

		$context = $this->build_course_context( $course, gmdate( 'Y-m-d H:i:s', $completed_ts ) );

		foreach ( $triggers as $trigger ) {
			// No conditions in 1.0: every completion issues, and the
			// engine suppresses duplicates (Feature 003).
			PressPrimer_Certificate_Issuance_Service::issue(
				[
					'template_id'  => (int) $trigger->template_id,
					'recipient_id' => $user_id,
					'source_type'  => self::TRIGGER_TYPE,
					'source_ref'   => (string) $course->ID,
					'issued_by'    => 0,
					'context'      => $context,
				]
			);
		}
	}
}
