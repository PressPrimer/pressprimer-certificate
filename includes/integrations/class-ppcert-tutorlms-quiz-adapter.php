<?php
/**
 * Tutor LMS quiz adapter
 *
 * Quiz trigger (1.0 scope addition, Ryan 2026-07-23): "award this
 * certificate when a Tutor LMS quiz is passed." Extends the Tutor
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
 * Tutor LMS quiz adapter class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_TutorLMS_Quiz_Adapter extends PressPrimer_Certificate_TutorLMS_Adapter {

	/**
	 * Trigger type id
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TRIGGER_TYPE = 'lms_tutorlms_quiz';

	/**
	 * Tutor quiz post type
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const QUIZ_POST_TYPE = 'tutor_quiz';

	/**
	 * Tutor topic post type (quizzes hang off topics, topics off courses)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TOPIC_POST_TYPE = 'topics';

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
		return __( 'Quiz passed (Tutor LMS)', 'pressprimer-certificate' );
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
	 * Cascade: pick the course, then a quiz from its topics.
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
	 * Level options: published Tutor courses
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
			return $this->get_post_sources( PressPrimer_Certificate_TutorLMS_Adapter::COURSE_POST_TYPE, $search );
		}

		return [];
	}

	/**
	 * Sources scoped to the chosen course: quizzes under its topics
	 *
	 * Tutor's content tree is plain post hierarchy: quizzes are child
	 * posts of topics, topics child posts of the course.
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

		$topics = get_posts(
			[
				'post_type'   => self::TOPIC_POST_TYPE,
				'post_status' => 'publish',
				'post_parent' => $course_id,
				'numberposts' => -1,
			]
		);

		$topic_ids = array_map(
			static function ( $topic ) {
				return (int) $topic->ID;
			},
			(array) $topics
		);

		if ( empty( $topic_ids ) ) {
			return [];
		}

		$quizzes = get_posts(
			[
				'post_type'       => self::QUIZ_POST_TYPE,
				'post_status'     => 'publish',
				'post_parent__in' => $topic_ids,
				'numberposts'     => -1,
			]
		);

		return $this->posts_to_options( (array) $quizzes, $search );
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
	 * Listen for ended quiz attempts
	 *
	 * Hook citations (Tutor LMS 4.0.0): `tutor_quiz/attempt_ended`
	 * fires in Tutor\Quiz::attempt_ended() (classes/Quiz.php:973) AFTER
	 * the attempt row updates and QuizModel::update_attempt_result()
	 * runs - grading state is final for auto-graded attempts (TR-003).
	 * Signature: ( int $attempt_id, int $course_id, int $user_id ).
	 * Attempts with open-ended answers land in 'review_required' and are
	 * skipped; `tutor_quiz/attempt/submitted/feedback`
	 * (classes/Quiz.php:502, signature ( int $attempt_id )) fires after
	 * the instructor's review, and the listener re-enters through it.
	 * Priority 20 keeps us after Tutor's own default-priority listeners.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_listeners(): void {
		add_action( 'tutor_quiz/attempt_ended', [ $this, 'handle_attempt_ended' ], 20, 3 );
		add_action( 'tutor_quiz/attempt/submitted/feedback', [ $this, 'handle_attempt_reviewed' ], 20, 1 );
	}

	/**
	 * Selectable sources: published Tutor quizzes
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
	 * The attempt-ended listener (FR-003)
	 *
	 * @since 1.0.0
	 *
	 * @param int $attempt_id Ended attempt id.
	 * @param int $course_id  Course id (unused; the attempt row is authoritative).
	 * @param int $user_id    User id (unused; the attempt row is authoritative).
	 * @return void
	 */
	public function handle_attempt_ended( $attempt_id, $course_id = 0, $user_id = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		$this->process_attempt( absint( $attempt_id ) );
	}

	/**
	 * The post-review listener: gradable answers were reviewed
	 *
	 * @since 1.0.0
	 *
	 * @param int $attempt_id Reviewed attempt id.
	 * @return void
	 */
	public function handle_attempt_reviewed( $attempt_id ) {
		$this->process_attempt( absint( $attempt_id ) );
	}

	/**
	 * Evaluate one attempt and issue for passing scores
	 *
	 * @since 1.0.0
	 *
	 * @param int $attempt_id Attempt id.
	 * @return void
	 */
	protected function process_attempt( $attempt_id ) {
		if ( $attempt_id < 1 || ! function_exists( 'tutor_utils' ) ) {
			return;
		}

		$attempt = tutor_utils()->get_attempt( $attempt_id );

		if ( ! is_object( $attempt ) || empty( $attempt->quiz_id ) || empty( $attempt->user_id ) ) {
			return;
		}

		// Open-ended answers pending instructor review: the feedback
		// hook re-enters once the grade is final.
		if ( isset( $attempt->attempt_status ) && 'review_required' === (string) $attempt->attempt_status ) {
			return;
		}

		$quiz_id = absint( $attempt->quiz_id );
		$user_id = absint( $attempt->user_id );

		$triggers = PressPrimer_Certificate_Trigger::find_active( self::TRIGGER_TYPE, (string) $quiz_id );

		if ( empty( $triggers ) ) {
			return;
		}

		$quiz = get_post( $quiz_id );

		if ( ! is_object( $quiz ) ) {
			return;
		}

		// Tutor grades in marks: normalize to a percentage.
		$earned = isset( $attempt->earned_marks ) && is_numeric( $attempt->earned_marks ) ? (float) $attempt->earned_marks : 0.0;
		$total  = isset( $attempt->total_marks ) && is_numeric( $attempt->total_marks ) ? (float) $attempt->total_marks : 0.0;

		$percent = $total > 0 ? round( ( $earned / $total ) * 100, 2 ) : 0.0;

		// Pass/fail against the quiz's own passing grade option.
		$passing_grade = (float) tutor_utils()->get_quiz_option( $quiz_id, 'passing_grade', 80 );

		if ( $percent < $passing_grade ) {
			return;
		}

		// Course context: the attempt row carries the course id.
		$course = isset( $attempt->course_id ) ? get_post( absint( $attempt->course_id ) ) : null;

		// Tutor stamps attempt_ended_at in site-local time (tutor_time());
		// convert to UTC per the shared contract.
		$completed_at = isset( $attempt->attempt_ended_at ) && '' !== (string) $attempt->attempt_ended_at
			? (string) get_gmt_from_date( (string) $attempt->attempt_ended_at )
			: gmdate( 'Y-m-d H:i:s' );

		$context = $this->build_quiz_context( $quiz, $percent, $course, $completed_at );

		// Scope from the attempt row's course id (authoritative even
		// when the course post cannot be loaded).
		$fired_scope = [ 'course_id' => isset( $attempt->course_id ) && absint( $attempt->course_id ) > 0 ? (string) absint( $attempt->course_id ) : '' ];

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
}
