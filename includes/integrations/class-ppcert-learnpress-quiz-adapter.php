<?php
/**
 * LearnPress quiz adapter
 *
 * Quiz trigger (1.0 scope addition, Ryan 2026-07-23): "award this
 * certificate when a LearnPress quiz is passed." Extends the LearnPress
 * course adapter for availability and the shared helpers.
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
 * LearnPress quiz adapter class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_LearnPress_Quiz_Adapter extends PressPrimer_Certificate_LearnPress_Adapter {

	/**
	 * Trigger type id
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRIGGER_TYPE = 'lms_learnpress_quiz';

	/**
	 * LearnPress quiz post type
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const QUIZ_POST_TYPE = 'lp_quiz';

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
		return __( 'Quiz passed (LearnPress)', 'pressprimer-certificate' );
	}

	/**
	 * Source noun for the Award tab and merge-field palette
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_source_group_label(): string {
		return __( 'Quiz', 'pressprimer-certificate' );
	}

	/**
	 * Short trigger label (integration already chosen)
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_short_label(): string {
		return __( 'Quiz passed', 'pressprimer-certificate' );
	}

	/**
	 * Quizzes are posts: unlocks the source-meta picker (Feature 002).
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_source_post_types(): array {
		return [ self::QUIZ_POST_TYPE ];
	}

	/**
	 * Cascade: pick the course, then a quiz from its sections.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array{key:string,label:string}>
	 */
	public function get_source_levels(): array {
		return [
			[
				'key'   => 'course',
				'label' => __( 'Course', 'pressprimer-certificate' ),
			],
		];
	}

	/**
	 * Level options: published LearnPress courses
	 *
	 * @since 1.0.0
	 *
	 * @param string $level   Level key.
	 * @param array  $parents Earlier level selections.
	 * @param string $search  Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_level_options( string $level, array $parents, string $search = '' ): array {
		if ( 'course' === $level ) {
			return $this->get_post_sources( PressPrimer_Certificate_LearnPress_Adapter::COURSE_POST_TYPE, $search );
		}

		return [];
	}

	/**
	 * Sources scoped to the chosen course: quiz items of its sections
	 *
	 * LearnPress stores curriculum in its own tables: sections belong
	 * to a course, section items reference quizzes by item_type.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $parents Level selections (course => id).
	 * @param string $search  Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_sources_for_parents( array $parents, string $search = '' ): array {
		global $wpdb;

		$course_id = isset( $parents['course'] ) ? absint( $parents['course'] ) : 0;

		if ( $course_id < 1 ) {
			return $this->get_sources( $search );
		}

		$quiz_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT si.item_id FROM %i si INNER JOIN %i s ON si.section_id = s.section_id WHERE s.section_course_id = %d AND si.item_type = 'lp_quiz'",
				$wpdb->prefix . 'learnpress_section_items',
				$wpdb->prefix . 'learnpress_sections',
				$course_id
			)
		);

		return $this->posts_to_options(
			array_map( 'get_post', array_filter( array_map( 'absint', (array) $quiz_ids ) ) ),
			$search
		);
	}

	/**
	 * An 'any' quiz trigger is scoped to its course (leaf-only "Any")
	 *
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	public function get_scope_condition_keys(): array {
		return [ 'course_id' ];
	}

	/**
	 * "Any" option label (leaf-only Any, Feature 1.1-002 FR-003).
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_any_source_label(): string {
		return __( 'Any quiz in this course', 'pressprimer-certificate' );
	}

	/**
	 * Listen for finished quizzes
	 *
	 * Hook citation (LearnPress 4.3.4): `learn-press/user/quiz-finished`
	 * fires in the frontend REST flow
	 * (inc/rest-api/v1/frontend/class-lp-rest-users-controller.php:363)
	 * AFTER the result is calculated, saved, and the graduation set -
	 * grading state is final when we run (TR-003). Signature there:
	 * ( int $quiz_id, int $course_id, int $user_id,
	 * UserQuizModel $user_quiz ). A legacy site
	 * (inc/user/class-lp-user.php:623) fires with THREE args and no
	 * result payload - grades are unknowable there, so the listener
	 * never awards on it. Priority 20 keeps us after LearnPress's own
	 * default-priority listeners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		add_action( 'learn-press/user/quiz-finished', [ $this, 'handle_quiz_finished' ], 20, 4 );
	}

	/**
	 * Selectable sources: published LearnPress quizzes
	 *
	 * @since 1.0.0
	 *
	 * @param string $search Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_sources( string $search = '' ): array {
		return $this->get_post_sources( self::QUIZ_POST_TYPE, $search );
	}

	/**
	 * Conditions schema (TR-002)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_conditions_schema(): array {
		return [
			'min_score' => [
				'type'    => 'number',
				'label'   => __( 'Minimum score (%)', 'pressprimer-certificate' ),
				'help'    => __( 'Leave blank to award on any passing score. Enter a percentage to require more than the quiz\'s own passing grade.', 'pressprimer-certificate' ),
				'min'     => 0,
				'max'     => 100,
				'default' => null,
			],
		];
	}

	/**
	 * Contributed merge fields: the shared quiz set plus course context
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_merge_fields(): array {
		return $this->quiz_merge_fields();
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
		return $this->resolve_quiz_merge_data( $context );
	}

	/**
	 * The quiz-finished listener (FR-003)
	 *
	 * Only passes award: the result payload's own pass flag decides,
	 * and min_score raises the bar. Without the result payload (legacy
	 * firing site) nothing awards - grades must never be guessed.
	 *
	 * @since 1.0.0
	 *
	 * @param int         $quiz_id   Finished quiz id.
	 * @param int         $course_id Course id.
	 * @param int         $user_id   Student user id.
	 * @param object|null $user_quiz UserQuizModel with the saved result.
	 * @return void
	 */
	public function handle_quiz_finished( $quiz_id, $course_id, $user_id, $user_quiz = null ) {
		$quiz_id = absint( $quiz_id );
		$user_id = absint( $user_id );

		if ( $quiz_id < 1 || $user_id < 1 ) {
			return;
		}

		if ( ! is_object( $user_quiz ) || ! method_exists( $user_quiz, 'get_result' ) ) {
			return;
		}

		try {
			$result = $user_quiz->get_result();
		} catch ( \Throwable $e ) {
			return;
		}

		if ( ! is_array( $result ) || empty( $result['pass'] ) ) {
			return;
		}

		$triggers = PressPrimer_Certificate_Trigger::find_active( self::TRIGGER_TYPE, (string) $quiz_id );

		if ( empty( $triggers ) ) {
			return;
		}

		$quiz = get_post( $quiz_id );

		if ( ! is_object( $quiz ) ) {
			return;
		}

		$percent = isset( $result['result'] ) && is_numeric( $result['result'] )
			? round( (float) $result['result'], 2 )
			: 0.0;

		$course  = get_post( absint( $course_id ) );
		$context = $this->build_quiz_context( $quiz, $percent, $course, gmdate( 'Y-m-d H:i:s' ) );

		// Scope from the hook's course id argument.
		$fired_scope = [ 'course_id' => absint( $course_id ) > 0 ? (string) absint( $course_id ) : '' ];

		foreach ( $triggers as $trigger ) {
			if ( ! $this->trigger_scope_matches( $trigger, $fired_scope ) ) {
				continue;
			}

			// Condition: numeric min_score raises the bar above the
			// quiz's own passing grade; null follows it.
			$min_score = isset( $trigger->conditions['min_score'] ) ? $trigger->conditions['min_score'] : null;

			if ( null !== $min_score && is_numeric( $min_score ) && $percent < (float) $min_score ) {
				continue;
			}

			PressPrimer_Certificate_Issuance_Service::issue(
				[
					'template_id'  => (int) $trigger->template_id,
					'recipient_id' => $user_id,
					'source_type'  => self::TRIGGER_TYPE,
					'source_ref'   => (string) $quiz_id,
					'issued_by'    => 0,
					'context'      => $context,
				]
			);
		}
	}

	/**
	 * Users who PASSED this quiz, from wp_learnpress_user_items
	 *
	 * SUPPORTED (2.0, FR-005). Quiz rows carry item_type 'lp_quiz',
	 * status 'completed', and graduation 'passed'; end_time is UTC (see
	 * the course adapter's user_item_completions()). Limitation per
	 * FR-005: min_score trigger conditions are not evaluated
	 * historically.
	 *
	 * @since 2.0.0
	 *
	 * @param string $source_ref Quiz post id.
	 * @return array [ [ 'user_id' => int, 'completed_at' => UTC ], ... ]
	 */
	public function get_past_completions( string $source_ref ) {
		return $this->user_item_completions(
			'lp_quiz',
			absint( $source_ref ),
			'completed',
			true
		);
	}
}
