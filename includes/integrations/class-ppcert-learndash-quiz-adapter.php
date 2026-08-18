<?php
/**
 * LearnDash quiz adapter
 *
 * Sub-course trigger (1.0 scope addition, Ryan 2026-07-23): "award this
 * certificate when a LearnDash quiz is passed." Mirrors the PPQ
 * adapter's pass/min_score semantics on LearnDash's quiz event.
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
 * LearnDash quiz adapter class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_LearnDash_Quiz_Adapter extends PressPrimer_Certificate_LearnDash_Adapter {

	/**
	 * Trigger type id
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRIGGER_TYPE = 'lms_learndash_quiz';

	/**
	 * LearnDash quiz post type
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const QUIZ_POST_TYPE = 'sfwd-quiz';

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
		return __( 'Quiz passed (LearnDash)', 'pressprimer-certificate' );
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
	 * Cascade: pick the course, then a quiz attached anywhere within it
	 * (course level, lessons, or topics).
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
	 * Level options: published LearnDash courses
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
			return $this->get_post_sources( PressPrimer_Certificate_LearnDash_Adapter::COURSE_POST_TYPE, $search );
		}

		return [];
	}

	/**
	 * Sources scoped to the chosen course: every quiz step within it
	 * plus its global (course-level) quizzes
	 *
	 * @since 1.0.0
	 *
	 * @param array  $parents Level selections (course => id).
	 * @param string $search  Search term.
	 * @return array<int,array{id:string,title:string}>
	 */
	public function get_sources_for_parents( array $parents, string $search = '' ): array {
		$course_id = isset( $parents['course'] ) ? absint( $parents['course'] ) : 0;

		if ( $course_id < 1 ) {
			return $this->get_sources( $search );
		}

		$posts = [];

		if ( function_exists( 'learndash_course_get_steps_by_type' ) ) {
			foreach ( array_filter( array_map( 'absint', (array) learndash_course_get_steps_by_type( $course_id, self::QUIZ_POST_TYPE ) ) ) as $quiz_id ) {
				$posts[ $quiz_id ] = get_post( $quiz_id );
			}
		}

		if ( function_exists( 'learndash_get_global_quiz_list' ) ) {
			foreach ( (array) learndash_get_global_quiz_list( $course_id ) as $quiz_post ) {
				if ( is_object( $quiz_post ) && ! empty( $quiz_post->ID ) ) {
					$posts[ (int) $quiz_post->ID ] = $quiz_post;
				}
			}
		}

		return $this->posts_to_options( array_values( $posts ), $search );
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
	 * Listen for completed quizzes
	 *
	 * Hook citation (LearnDash 4.23.0): `learndash_quiz_completed` fires
	 * in LD_QuizPro::wp_pro_quiz_completed()
	 * (includes/quiz/ld-quiz-pro.php:1451) AFTER the attempt statistics
	 * and user meta persist and parent steps are marked - grading state
	 * is final (TR-003). Signature: ( array $quizdata, WP_User $user ).
	 * $quizdata carries 'quiz' (WP_Post here; a raw ID at the secondary
	 * firing sites in ld-users.php:684 and ld-quiz-essays.php:708),
	 * 'pass' (0/1 against the quiz's own passingpercentage),
	 * 'percentage' (float), 'course'/'lesson'/'topic' (WP_Post or 0),
	 * and 'completed' (Unix timestamp, may be 0). The listener accepts
	 * both quiz shapes. Priority 20 keeps us after LearnDash's own
	 * default-priority listeners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		add_action( 'learndash_quiz_completed', [ $this, 'handle_quiz_completed' ], 20, 2 );
	}

	/**
	 * Selectable sources: published LearnDash quizzes
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
	 * Same semantics as the PPQ adapter: null min_score follows the
	 * quiz's own passing percentage (the listener only issues for
	 * passes); a numeric value raises the bar.
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
				'help'    => __( 'Leave blank to award on any passing score. Enter a percentage to require more than the quiz\'s own passing percentage.', 'pressprimer-certificate' ),
				'min'     => 0,
				'max'     => 100,
				'default' => null,
			],
		];
	}

	/**
	 * Contributed merge fields: the shared quiz set (quiz fields plus
	 * parent-course context)
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
	 * The quiz-completed listener (FR-003)
	 *
	 * The event fires for failing attempts too: only passes (the quiz's
	 * own bar) award, and min_score raises it.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $quizdata LearnDash quiz payload.
	 * @param object $user     WP_User.
	 * @return void
	 */
	public function handle_quiz_completed( $quizdata, $user = null ) {
		if ( ! is_array( $quizdata ) || empty( $quizdata['quiz'] ) ) {
			return;
		}

		$user_id = isset( $user->ID ) ? (int) $user->ID : 0;

		if ( $user_id < 1 || empty( $quizdata['pass'] ) ) {
			return;
		}

		// 'quiz' is a WP_Post at the primary firing site, a raw ID at
		// the essay-grading and profile sites (hook citation).
		$quiz_id = is_object( $quizdata['quiz'] ) ? (int) $quizdata['quiz']->ID : absint( $quizdata['quiz'] );

		if ( $quiz_id < 1 ) {
			return;
		}

		$triggers = PressPrimer_Certificate_Trigger::find_active( self::TRIGGER_TYPE, (string) $quiz_id );

		if ( empty( $triggers ) ) {
			return;
		}

		$quiz = is_object( $quizdata['quiz'] ) ? $quizdata['quiz'] : get_post( $quiz_id );

		if ( ! is_object( $quiz ) ) {
			return;
		}

		$percent = isset( $quizdata['percentage'] ) && is_numeric( $quizdata['percentage'] )
			? (float) $quizdata['percentage']
			: 0.0;

		$course = isset( $quizdata['course'] ) && is_object( $quizdata['course'] ) ? $quizdata['course'] : null;

		$completed_ts = isset( $quizdata['completed'] ) && is_numeric( $quizdata['completed'] ) && (int) $quizdata['completed'] > 0
			? (int) $quizdata['completed']
			: time();

		$context = $this->build_quiz_context( $quiz, $percent, $course, gmdate( 'Y-m-d H:i:s', $completed_ts ) );

		// Global quizzes may complete without a course - a scoped 'any'
		// trigger then never fires (fail closed).
		$fired_scope = [ 'course_id' => $course && ! empty( $course->ID ) ? (string) $course->ID : '' ];

		foreach ( $triggers as $trigger ) {
			if ( ! $this->trigger_scope_matches( $trigger, $fired_scope ) ) {
				continue;
			}

			// Condition: numeric min_score raises the bar above the
			// quiz's own passing percentage; null follows it.
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
}
