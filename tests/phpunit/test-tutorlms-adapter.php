<?php
/**
 * Tutor LMS adapter tests (Feature 004, Prompt 4.4)
 *
 * Course completion plus the quiz trigger (1.0 scope addition, Ryan
 * 2026-07-23): marks-to-percent normalization, the quiz's own passing
 * grade as the pass bar, review-required deferral through the feedback
 * hook, and the course cascade over Tutor's post hierarchy. Live-Tutor
 * behavior is additionally verified on the dev site.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

// The adapters' availability gate checks Tutor's constant (matching
// Tutor LMS 4.0.0).
if ( ! defined( 'TUTOR_VERSION' ) ) {
	define( 'TUTOR_VERSION', '4.0.0-test' );
}

if ( ! function_exists( 'tutor_utils' ) ) {
	/**
	 * Stub: Tutor's utils singleton.
	 *
	 * @return PPCert_Test_Tutor_Utils
	 */
	function tutor_utils() { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- Tutor API stub for the adapters under test.
		return PPCert_Test_Tutor_Utils::instance();
	}
}

/**
 * Tutor utils double: attempt + quiz-option lookups from fixtures.
 */
class PPCert_Test_Tutor_Utils { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- API stub for the adapters under test.

	/**
	 * Attempt fixtures: id => object.
	 *
	 * @var array<int,object>
	 */
	public static $attempts = [];

	/**
	 * Quiz option fixtures: quiz id => [ option => value ].
	 *
	 * @var array<int,array>
	 */
	public static $quiz_options = [];

	/**
	 * Singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Attempt lookup (mirrors Tutor's utils contract).
	 *
	 * @param int $attempt_id Attempt id.
	 * @return object|false
	 */
	public function get_attempt( $attempt_id = 0 ) {
		return isset( self::$attempts[ (int) $attempt_id ] ) ? self::$attempts[ (int) $attempt_id ] : false;
	}

	/**
	 * Quiz option lookup (mirrors Tutor's utils contract).
	 *
	 * @param int    $post_id    Quiz id.
	 * @param string $option_key Option key.
	 * @param mixed  $default_value Fallback.
	 * @return mixed
	 */
	public function get_quiz_option( $post_id = 0, $option_key = '', $default_value = false ) {
		return isset( self::$quiz_options[ (int) $post_id ][ $option_key ] )
			? self::$quiz_options[ (int) $post_id ][ $option_key ]
			: $default_value;
	}
}

/**
 * Tutor LMS adapter test case
 *
 * @since 1.0.0
 */
class Test_TutorLMS_Adapter extends TestCase { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test case follows its doubles.

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Published template id with course + quiz tokens.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Reset state, seed users/course/topic/quiz posts + template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		PPCert_Test_Tutor_Utils::$attempts     = [];
		PPCert_Test_Tutor_Utils::$quiz_options = [
			503 => [ 'passing_grade' => 70 ],
		];

		$GLOBALS['ppcert_test_users'] = [
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana Whitfield',
				'first_name'   => 'Dana',
				'last_name'    => 'Whitfield',
				'user_email'   => 'dana@example.test',
			],
			9 => (object) [
				'ID'           => 9,
				'display_name' => 'Prof. Marisol Vega',
				'first_name'   => 'Marisol',
				'last_name'    => 'Vega',
				'user_email'   => 'vega@example.test',
			],
		];

		$GLOBALS['ppcert_test_posts'] = [
			501 => (object) [
				'ID'          => 501,
				'post_type'   => 'courses',
				'post_status' => 'publish',
				'post_title'  => 'Pottery Basics',
				'post_author' => 9,
			],
			502 => (object) [
				'ID'          => 502,
				'post_type'   => 'topics',
				'post_status' => 'publish',
				'post_title'  => 'Glazing',
				'post_author' => 9,
				'post_parent' => 501,
			],
			503 => (object) [
				'ID'          => 503,
				'post_type'   => 'tutor_quiz',
				'post_status' => 'publish',
				'post_title'  => 'Glazing Quiz',
				'post_author' => 9,
				'post_parent' => 502,
			],
		];

		$layout = [
			'layout_schema_version' => 1,
			'page'                  => [
				'size'        => 'a4',
				'orientation' => 'landscape',
				'width'       => 842,
				'height'      => 595,
			],
			'background'            => [ 'color' => '#ffffff' ],
			'elements'              => [
				[
					'id'    => 'el_crstitle',
					'type'  => 'merge_field',
					'x'     => 100,
					'y'     => 100,
					'w'     => 400,
					'h'     => 30,
					'z'     => 1,
					'props' => [
						'token'       => '{{source.course_title}}',
						'font_family' => 'source-sans-3',
						'font_size'   => 18,
						'color'       => '#111111',
						'align'       => 'left',
						'line_height' => 1.2,
						'bold'        => false,
						'italic'      => false,
					],
				],
			],
		];

		$this->template_id = $this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'                  => 'tpl-tutor-test',
				'title'                 => 'Tutor Completion',
				'status'                => 'published',
				'author_id'             => 1,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => wp_json_encode( $layout ),
				'updated_at'            => '2026-07-01 00:00:00',
				'deleted_at'            => null,
			]
		);
	}

	/**
	 * Seed an active trigger.
	 *
	 * @param string     $type       Trigger type id.
	 * @param string     $ref        Source ref.
	 * @param array|null $conditions Conditions.
	 * @return void
	 */
	private function seed_trigger( $type, $ref, $conditions = null ) {
		$this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'uuid'            => 'trg-' . $type,
				'template_id'     => $this->template_id,
				'trigger_type'    => $type,
				'source_ref'      => $ref,
				'conditions_json' => null === $conditions ? null : wp_json_encode( $conditions ),
				'is_active'       => 1,
			]
		);
	}

	/**
	 * Seed an attempt fixture and fire the attempt-ended hook.
	 *
	 * @param float  $earned Earned marks.
	 * @param float  $total  Total marks.
	 * @param string $status Attempt status.
	 * @return int Attempt id.
	 */
	private function fire_attempt( $earned, $total = 100.0, $status = 'attempt_ended' ) {
		static $next_id = 900;
		$next_id++;

		PPCert_Test_Tutor_Utils::$attempts[ $next_id ] = (object) [
			'attempt_id'       => $next_id,
			'quiz_id'          => 503,
			'course_id'        => 501,
			'user_id'          => 7,
			'earned_marks'     => $earned,
			'total_marks'      => $total,
			'attempt_status'   => $status,
			'attempt_ended_at' => '2026-06-12 09:30:00',
		];

		do_action( 'tutor_quiz/attempt_ended', $next_id, 501, 7 );

		return $next_id;
	}

	/**
	 * Registration: both Tutor types, integration metadata, cascades.
	 *
	 * @return void
	 */
	public function test_registration_and_metadata() {
		( new PressPrimer_Certificate_TutorLMS_Adapter() )->register();
		( new PressPrimer_Certificate_TutorLMS_Quiz_Adapter() )->register();

		$types = PressPrimer_Certificate_Trigger_Registry::get_types();

		$this->assertSame( 'Course completed (Tutor LMS)', $types['lms_tutorlms']['label'] );
		$this->assertSame( 'Tutor LMS', $types['lms_tutorlms']['integration'] );
		$this->assertSame( [ 'courses' ], $types['lms_tutorlms']['source_post_types'] );
		$this->assertSame( [ 'reissue' ], array_keys( $types['lms_tutorlms']['conditions_schema'] ), 'Only the universal reissue toggle' );

		$this->assertSame( 'Quiz passed (Tutor LMS)', $types['lms_tutorlms_quiz']['label'] );
		$this->assertSame( [ 'tutor_quiz' ], $types['lms_tutorlms_quiz']['source_post_types'] );
		$this->assertSame( 'course', $types['lms_tutorlms_quiz']['source_levels'][0]['key'] );
		$this->assertArrayHasKey( 'min_score', $types['lms_tutorlms_quiz']['conditions_schema'] );

		$fields = PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );
		$this->assertContains( 'lms_tutorlms', $fields['source.course_title']['trigger_types'] );
		$this->assertContains( 'lms_tutorlms_quiz', $fields['source.quiz_title']['trigger_types'] );
	}

	/**
	 * A course completion issues once with merge data; re-fires suppress.
	 *
	 * @return void
	 */
	public function test_course_completion_issues_and_suppresses() {
		( new PressPrimer_Certificate_TutorLMS_Adapter() )->register();
		$this->seed_trigger( 'lms_tutorlms', '501' );

		do_action( 'tutor_course_complete_after', 501, 7 );
		do_action( 'tutor_course_complete_after', 501, 7 );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'lms_tutorlms', $rows[0]['source_type'] );
		$this->assertSame( '501', $rows[0]['source_ref'] );

		$merge = json_decode( $rows[0]['merge_data_json'], true );
		$this->assertSame( 'Pottery Basics', $merge['source.course_title'] );
	}

	/**
	 * Quiz attempts normalize marks to percent, gate on the quiz's own
	 * passing grade, honor min_score at the boundary, and suppress
	 * retakes.
	 *
	 * @return void
	 */
	public function test_quiz_marks_normalization_pass_bar_and_min_score() {
		( new PressPrimer_Certificate_TutorLMS_Quiz_Adapter() )->register();
		$this->seed_trigger( 'lms_tutorlms_quiz', '503', [ 'min_score' => 80.0 ] );

		// 60% clears nothing (quiz bar 70, trigger bar 80).
		$this->fire_attempt( 30.0, 50.0 );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// 75% clears the quiz bar but not the trigger bar.
		$this->fire_attempt( 37.5, 50.0 );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// 80% exactly: issues (marks normalized 40/50).
		$this->fire_attempt( 40.0, 50.0 );
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Retake: suppressed.
		$this->fire_attempt( 48.0, 50.0 );
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * Review-required attempts wait for the instructor: nothing on
	 * attempt_ended, issuance through the feedback hook once graded.
	 *
	 * @return void
	 */
	public function test_review_required_defers_to_feedback_hook() {
		( new PressPrimer_Certificate_TutorLMS_Quiz_Adapter() )->register();
		$this->seed_trigger( 'lms_tutorlms_quiz', '503' );

		$attempt_id = $this->fire_attempt( 45.0, 50.0, 'review_required' );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Instructor reviews: status finalizes, feedback hook fires.
		PPCert_Test_Tutor_Utils::$attempts[ $attempt_id ]->attempt_status = 'attempt_ended';
		do_action( 'tutor_quiz/attempt/submitted/feedback', $attempt_id );

		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * The quiz cascade walks Tutor's post hierarchy: course -> topics ->
	 * quizzes.
	 *
	 * @return void
	 */
	public function test_quiz_cascade_scopes_to_course_topics() {
		$adapter = new PressPrimer_Certificate_TutorLMS_Quiz_Adapter();

		$this->assertSame(
			[ 'Pottery Basics' ],
			array_column( $adapter->get_level_options( 'course', [] ), 'title' )
		);

		$this->assertSame(
			[ '503' ],
			array_column( $adapter->get_sources_for_parents( [ 'course' => 501 ] ), 'id' )
		);

		$this->assertSame( [], $adapter->get_sources_for_parents( [ 'course' => 999 ] ) );
	}
}
