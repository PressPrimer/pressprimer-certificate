<?php
/**
 * LearnPress adapter
 *
 * Course-completion adapter (Feature 004 FR-004 lms_learnpress row).
 * Sources are posts, so the shared course helpers in the abstract class
 * do the field work; this class is the LearnPress-specific listener.
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
 * LearnPress adapter class
 *
 * Monitor-only, like every adapter: LearnPress marks the course
 * finished, then we issue. The listener performs no LearnPress writes.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_LearnPress_Adapter extends PressPrimer_Certificate_LMS_Adapter {

	/**
	 * Trigger type id (FR-004 contract)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRIGGER_TYPE = 'lms_learnpress';

	/**
	 * LearnPress course post type
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const COURSE_POST_TYPE = 'lp_course';

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
		return __( 'Course completed (LearnPress)', 'pressprimer-certificate' );
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
		return __( 'LearnPress', 'pressprimer-certificate' );
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
	 * LEARNPRESS_VERSION is LearnPress's canonical version constant
	 * (verified against LearnPress 4.3.4).
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return defined( 'LEARNPRESS_VERSION' );
	}

	/**
	 * Listen for finished courses
	 *
	 * Hook citation (LearnPress 4.3.4): `learn-press/user-course-finished`
	 * fires in LearnPress\Models\UserItems\UserCourseModel::finish()
	 * (inc/Models/UserItems/UserCourseModel.php:1034) AFTER the item's
	 * status, graduation, and results are saved - completion state is
	 * final when we run (TR-003); a legacy site fires the same hook in
	 * abstract-lp-user.php:649. Signature: ( int $course_id,
	 * int $user_id, int $user_item_id ). LearnPress lets learners
	 * finish a course with a FAILED graduation, so the listener skips
	 * failed graduations when the user-item is readable. Priority 20
	 * keeps us after LearnPress's own default-priority listeners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		add_action( 'learn-press/user-course-finished', [ $this, 'handle_course_finished' ], 20, 3 );
	}

	/**
	 * Selectable sources: published LearnPress courses
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
	 * The course-finished listener (FR-003)
	 *
	 * @since 1.0.0
	 *
	 * @param int $course_id    Finished course id.
	 * @param int $user_id      Student user id.
	 * @param int $user_item_id User-item row id (unused).
	 * @return void
	 */
	public function handle_course_finished( $course_id, $user_id, $user_item_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		$course_id = absint( $course_id );
		$user_id   = absint( $user_id );

		if ( $course_id < 1 || $user_id < 1 ) {
			return;
		}

		// A finished LearnPress course may carry a FAILED graduation:
		// skip it when the user-item is readable; when it is not
		// (legacy firing site), completion stands on its own.
		if ( 'failed' === $this->get_course_graduation( $user_id, $course_id ) ) {
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
			// No conditions in 1.0: every (non-failed) completion
			// issues, and the engine suppresses duplicates (Feature 003).
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

	/**
	 * The learner's graduation for a course ('' when unreadable)
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id   User id.
	 * @param int $course_id Course id.
	 * @return string 'passed' | 'failed' | '' (unknown).
	 */
	protected function get_course_graduation( $user_id, $course_id ): string {
		$model_class = '\\LearnPress\\Models\\UserItems\\UserCourseModel';

		if ( ! class_exists( $model_class ) || ! method_exists( $model_class, 'find' ) ) {
			return '';
		}

		try {
			$item = $model_class::find( (int) $user_id, (int) $course_id, true );
		} catch ( \Throwable $e ) {
			return '';
		}

		return is_object( $item ) && isset( $item->graduation ) ? (string) $item->graduation : '';
	}

	/**
	 * Past completions: SUPPORTED (2.0, Feature 2.0-006 FR-005)
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function supports_past_completions(): bool {
		return true;
	}

	/**
	 * Users who finished this course, from wp_learnpress_user_items
	 *
	 * Course rows carry item_type 'lp_course' and status 'finished';
	 * mirroring the fire-time graduation gate, 'failed' graduations are
	 * excluded (NULL and legacy empty graduations count). end_time is
	 * stored in UTC by LearnPress 4 (gmdate()/LP_Datetime, verified
	 * against LearnPress 4.4.4 sources, 2026-08-30) and is read
	 * directly.
	 *
	 * @since 2.0.0
	 *
	 * @param string $source_ref Course post id.
	 * @return array [ [ 'user_id' => int, 'completed_at' => UTC ], ... ]
	 */
	public function get_past_completions( string $source_ref ) {
		return $this->user_item_completions(
			'lp_course',
			absint( $source_ref ),
			'finished',
			false
		);
	}

	/**
	 * Earliest qualifying wp_learnpress_user_items rows per user
	 *
	 * Shared by the course and quiz adapters (same table, different
	 * item types and graduation predicates).
	 *
	 * @since 2.0.0
	 *
	 * @param string $item_type       'lp_course' or 'lp_quiz'.
	 * @param int    $item_id         Source post id.
	 * @param string $status          Qualifying status value.
	 * @param bool   $require_passed  True to require graduation
	 *                                'passed' (quiz); false to exclude
	 *                                only 'failed' (course).
	 * @return array [ [ 'user_id' => int, 'completed_at' => UTC ], ... ]
	 */
	protected function user_item_completions( string $item_type, int $item_id, string $status, bool $require_passed ) {
		global $wpdb;

		if ( $item_id < 1 ) {
			return [];
		}

		if ( $require_passed ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, MIN(end_time) AS completed_at FROM %i WHERE item_type = %s AND item_id = %d AND status = %s AND graduation = 'passed' AND user_id > 0 AND end_time IS NOT NULL GROUP BY user_id ORDER BY user_id ASC",
					$wpdb->prefix . 'learnpress_user_items',
					$item_type,
					$item_id,
					$status
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT user_id, MIN(end_time) AS completed_at FROM %i WHERE item_type = %s AND item_id = %d AND status = %s AND ( graduation IS NULL OR graduation <> 'failed' ) AND user_id > 0 AND end_time IS NOT NULL GROUP BY user_id ORDER BY user_id ASC",
					$wpdb->prefix . 'learnpress_user_items',
					$item_type,
					$item_id,
					$status
				)
			);
		}

		$completions = [];

		foreach ( (array) $rows as $row ) {
			$completions[] = [
				'user_id'      => (int) $row->user_id,
				// end_time is already UTC (LearnPress 4 convention).
				'completed_at' => (string) $row->completed_at,
			];
		}

		return $completions;
	}
}
