<?php
/**
 * LifterLMS quiz adapter
 *
 * Quiz trigger (1.0 scope addition, Ryan 2026-07-23): "award this
 * certificate when a LifterLMS quiz is passed." Extends the LifterLMS
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
 * LifterLMS quiz adapter class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_LifterLMS_Quiz_Adapter extends PressPrimer_Certificate_LifterLMS_Adapter {

	/**
	 * Trigger type id
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRIGGER_TYPE = 'lms_lifterlms_quiz';

	/**
	 * LifterLMS quiz post type
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const QUIZ_POST_TYPE = 'llms_quiz';

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
		return __( 'Quiz passed (LifterLMS)', 'pressprimer-certificate' );
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
	 * Cascade: pick the course, then a quiz from its lessons.
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
	 * Level options: published LifterLMS courses
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
			return $this->get_post_sources( PressPrimer_Certificate_LifterLMS_Adapter::COURSE_POST_TYPE, $search );
		}

		return [];
	}

	/**
	 * Sources scoped to the chosen course: quizzes attached to its
	 * lessons (LifterLMS attaches quizzes lesson-by-lesson)
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

		if ( ! function_exists( 'llms_get_post' ) ) {
			return [];
		}

		$course = llms_get_post( $course_id );

		if ( ! is_object( $course ) || ! method_exists( $course, 'get_lessons' ) ) {
			return [];
		}

		$posts = [];

		foreach ( (array) $course->get_lessons( 'lessons' ) as $lesson ) {
			if ( ! is_object( $lesson ) || ! method_exists( $lesson, 'get' ) ) {
				continue;
			}

			$quiz_id = absint( $lesson->get( 'quiz' ) );

			if ( $quiz_id > 0 ) {
				$posts[ $quiz_id ] = get_post( $quiz_id );
			}
		}

		return $this->posts_to_options( array_values( $posts ), $search );
	}

	/**
	 * Listen for passed quizzes
	 *
	 * Hook citation (LifterLMS 10.0.8): `lifterlms_quiz_passed` fires in
	 * LLMS_Quiz_Attempt::do_completion_actions()
	 * (includes/models/model.llms.quiz.attempt.php:212) ONLY when the
	 * attempt's status is 'pass' - graded against the quiz's own minimum
	 * grade in end_attempt() before the actions run, so grading state is
	 * final (TR-003). Signature: ( int $student_id, int $quiz_id,
	 * LLMS_Quiz_Attempt $attempt ). Attempts needing manual grading go
	 * 'pending' first and this hook fires once the review lands them on
	 * 'pass'. Priority 20 keeps us after LifterLMS's own
	 * default-priority listeners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		add_action( 'lifterlms_quiz_passed', [ $this, 'handle_quiz_passed' ], 20, 3 );
	}

	/**
	 * Selectable sources: published LifterLMS quizzes
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
				'help'    => __( 'Leave blank to award on any passing grade. Enter a percentage to require more than the quiz\'s own minimum grade.', 'pressprimer-certificate' ),
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
	 * The quiz-passed listener (FR-003)
	 *
	 * @since 1.0.0
	 *
	 * @param int    $student_id Student user id.
	 * @param int    $quiz_id    Quiz post id.
	 * @param object $attempt    LLMS_Quiz_Attempt (grade final).
	 * @return void
	 */
	public function handle_quiz_passed( $student_id, $quiz_id, $attempt = null ) {
		$user_id = absint( $student_id );
		$quiz_id = absint( $quiz_id );

		if ( $user_id < 1 || $quiz_id < 1 ) {
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

		$percent = 0.0;

		if ( is_object( $attempt ) && method_exists( $attempt, 'get' ) && is_numeric( $attempt->get( 'grade' ) ) ) {
			$percent = round( (float) $attempt->get( 'grade' ), 2 );
		}

		// Course context from the attempt's lesson parent.
		$course = null;

		if ( is_object( $attempt ) && method_exists( $attempt, 'get' ) && function_exists( 'llms_get_post' ) ) {
			$lesson = llms_get_post( absint( $attempt->get( 'lesson_id' ) ) );

			if ( is_object( $lesson ) && method_exists( $lesson, 'get' ) ) {
				$course = get_post( absint( $lesson->get( 'parent_course' ) ) );
			}
		}

		$context = $this->build_quiz_context( $quiz, $percent, $course, gmdate( 'Y-m-d H:i:s' ) );

		foreach ( $triggers as $trigger ) {
			// Condition: numeric min_score raises the bar above the
			// quiz's own minimum grade; null follows it.
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
