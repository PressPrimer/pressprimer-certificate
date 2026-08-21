<?php
/**
 * LearnDash adapter tests (Feature 004, Prompt 4.3)
 *
 * Course adapters ride the shared course helpers in the abstract class:
 * post-backed sources, the course merge-field set, and the instructor
 * resolution. Live-LearnDash behavior is additionally verified on the
 * dev site per the prompt's QA matrix.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

// The adapter's availability gate checks LearnDash's constant
// (matching LearnDash 4.23.0).
if ( ! defined( 'LEARNDASH_VERSION' ) ) {
	define( 'LEARNDASH_VERSION', '4.23.0-test' );
}

if ( ! function_exists( 'learndash_course_get_steps_by_type' ) ) {
	/**
	 * Stub: LD course steps ($GLOBALS['ppcert_test_ld_steps'][course][type] = ids).
	 *
	 * @param int    $course_id Course id.
	 * @param string $step_type Step post type.
	 * @return array
	 */
	function learndash_course_get_steps_by_type( $course_id = 0, $step_type = '' ) { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- LD API stub for the adapters under test.
		return isset( $GLOBALS['ppcert_test_ld_steps'][ $course_id ][ $step_type ] )
			? $GLOBALS['ppcert_test_ld_steps'][ $course_id ][ $step_type ]
			: [];
	}
}

if ( ! function_exists( 'learndash_get_topic_list' ) ) {
	/**
	 * Stub: LD topics of a lesson ($GLOBALS['ppcert_test_ld_topics'][lesson] = posts).
	 *
	 * @param int $lesson_id Lesson id.
	 * @param int $course_id Course id.
	 * @return array
	 */
	function learndash_get_topic_list( $lesson_id = null, $course_id = null ) {
		return isset( $GLOBALS['ppcert_test_ld_topics'][ (int) $lesson_id ] )
			? $GLOBALS['ppcert_test_ld_topics'][ (int) $lesson_id ]
			: [];
	}
}

if ( ! function_exists( 'learndash_get_global_quiz_list' ) ) {
	/**
	 * Stub: LD global quizzes of a course ($GLOBALS['ppcert_test_ld_global_quizzes'][course] = posts).
	 *
	 * @param int $id Course id.
	 * @return array
	 */
	function learndash_get_global_quiz_list( $id = null ) {
		return isset( $GLOBALS['ppcert_test_ld_global_quizzes'][ (int) $id ] )
			? $GLOBALS['ppcert_test_ld_global_quizzes'][ (int) $id ]
			: [];
	}
}

/**
 * Unavailable-variant double: the deactivated-LearnDash scenario.
 */
class PPCert_Test_Unavailable_LearnDash_Adapter extends PressPrimer_Certificate_LearnDash_Adapter { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- Test double.

	/**
	 * Simulate LearnDash absent.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return false;
	}
}

/**
 * LearnDash adapter test case
 *
 * @since 1.0.0
 */
class Test_LearnDash_Adapter extends TestCase { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test case follows its double.

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Adapter under test.
	 *
	 * @var PressPrimer_Certificate_LearnDash_Adapter
	 */
	private $adapter;

	/**
	 * Published template id with course tokens.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Reset state, register the adapter, seed users/course/template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

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
			301 => (object) [
				'ID'          => 301,
				'post_type'   => 'sfwd-courses',
				'post_status' => 'publish',
				'post_title'  => 'Advanced Botany',
				'post_author' => 9,
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
				[
					'id'    => 'el_crsinstr',
					'type'  => 'merge_field',
					'x'     => 100,
					'y'     => 160,
					'w'     => 400,
					'h'     => 30,
					'z'     => 2,
					'props' => [
						'token'       => '{{source.instructor}}',
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
				'uuid'                  => 'tpl-ld-test',
				'title'                 => 'Course Completion',
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

		$this->adapter = new PressPrimer_Certificate_LearnDash_Adapter();
		$this->adapter->register();
	}

	/**
	 * Seed an active trigger for course 301.
	 *
	 * @return int Trigger row id.
	 */
	private function seed_trigger() {
		return $this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'uuid'            => 'trg-ld-test',
				'template_id'     => $this->template_id,
				'trigger_type'    => 'lms_learndash',
				'source_ref'      => '301',
				'conditions_json' => null,
				'is_active'       => 1,
			]
		);
	}

	/**
	 * The LearnDash completion payload (single array arg).
	 *
	 * @return array
	 */
	private function completion_payload() {
		return [
			'user'             => (object) [ 'ID' => 7 ],
			'course'           => $GLOBALS['ppcert_test_posts'][301],
			'progress'         => [],
			'course_completed' => 1781256600, // 2026-06-12 09:30:00 UTC.
		];
	}

	/**
	 * Registration goes through the public filters only, and an
	 * unavailable adapter contributes nothing (Edge US-5).
	 *
	 * @return void
	 */
	public function test_registration_and_availability_gating() {
		$types = PressPrimer_Certificate_Trigger_Registry::get_types();

		$this->assertArrayHasKey( 'lms_learndash', $types );
		$this->assertSame( 'Course completed (LearnDash)', $types['lms_learndash']['label'] );
		$this->assertSame( 'Course', $types['lms_learndash']['source_label'] );
		$this->assertSame( [ 'sfwd-courses' ], $types['lms_learndash']['source_post_types'] );

		// No conditions in 1.0 (FR-004).
		$this->assertSame( [ 'reissue' ], array_keys( $types['lms_learndash']['conditions_schema'] ), 'Only the universal reissue toggle' );

		$fields = PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );
		$this->assertArrayHasKey( 'source.course_title', $fields );
		$this->assertArrayHasKey( 'source.completion_date', $fields );
		$this->assertArrayHasKey( 'source.instructor', $fields );
		$this->assertContains( 'lms_learndash', $fields['source.course_title']['trigger_types'] );

		// Deactivated LearnDash: a fresh registry sees nothing from the
		// unavailable variant.
		ppcert_tests_reset_hooks();
		( new PPCert_Test_Unavailable_LearnDash_Adapter() )->register();

		$this->assertArrayNotHasKey(
			'lms_learndash',
			PressPrimer_Certificate_Trigger_Registry::get_types()
		);
	}

	/**
	 * A completion issues once with correct source data and merge
	 * values, including the author-as-instructor resolution.
	 *
	 * @return void
	 */
	public function test_completion_issues_certificate_with_merge_data() {
		$this->seed_trigger();

		do_action( 'learndash_course_completed', $this->completion_payload() );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'lms_learndash', $rows[0]['source_type'] );
		$this->assertSame( '301', $rows[0]['source_ref'] );
		$this->assertSame( 7, (int) $rows[0]['recipient_id'] );

		$merge = json_decode( $rows[0]['merge_data_json'], true );
		$this->assertSame( 'Advanced Botany', $merge['source.course_title'] );
		$this->assertSame( 'Prof. Marisol Vega', $merge['source.instructor'] );
	}

	/**
	 * A re-completion event is suppressed by the engine (Edge Cases:
	 * several LMSs re-fire on progress recalculation).
	 *
	 * @return void
	 */
	public function test_recompletion_is_suppressed() {
		$this->seed_trigger();

		do_action( 'learndash_course_completed', $this->completion_payload() );
		do_action( 'learndash_course_completed', $this->completion_payload() );

		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * Malformed payloads (no course, no user) never issue and never
	 * error.
	 *
	 * @return void
	 */
	public function test_malformed_payload_is_ignored() {
		$this->seed_trigger();

		do_action( 'learndash_course_completed', 'not-an-array' );
		do_action( 'learndash_course_completed', [ 'course' => null ] );
		do_action( 'learndash_course_completed', [ 'course' => $GLOBALS['ppcert_test_posts'][301] ] );

		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * Sources: published courses only, searchable by title.
	 *
	 * @return void
	 */
	public function test_sources_lists_published_courses() {
		$GLOBALS['ppcert_test_posts'][302] = (object) [
			'ID'          => 302,
			'post_type'   => 'sfwd-courses',
			'post_status' => 'draft',
			'post_title'  => 'Draft Course',
			'post_author' => 9,
		];
		$GLOBALS['ppcert_test_posts'][303] = (object) [
			'ID'          => 303,
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'Not a Course',
			'post_author' => 9,
		];
		$GLOBALS['ppcert_test_posts'][304] = (object) [
			'ID'          => 304,
			'post_type'   => 'sfwd-courses',
			'post_status' => 'publish',
			'post_title'  => 'Botany Field Methods',
			'post_author' => 9,
		];

		$all = $this->adapter->get_sources();
		$this->assertSame(
			[ 'Advanced Botany', 'Botany Field Methods' ],
			array_column( $all, 'title' )
		);

		$searched = $this->adapter->get_sources( 'field' );
		$this->assertCount( 1, $searched );
		$this->assertSame( 'Botany Field Methods', $searched[0]['title'] );
	}

	/**
	 * Resolvers return display-ready scalars from the listener context.
	 *
	 * @return void
	 */
	public function test_merge_resolvers() {
		$context = [
			'source_post_id'   => 301,
			'lms_course_title' => 'Advanced Botany',
			'src_completed_at' => '2026-06-12 09:30:00',
			'lms_instructor'   => 'Prof. Marisol Vega',
		];

		$resolved = $this->adapter->resolve_merge_data( $context );

		$this->assertSame( 'Advanced Botany', $resolved['source.course_title'] );
		$this->assertSame( 'Prof. Marisol Vega', $resolved['source.instructor'] );
		$this->assertNotSame( '', $resolved['source.completion_date'] );

		// Manual issuance without LMS context: source fields empty,
		// never the raw token (Feature 002 Edge US-5).
		$empty = $this->adapter->resolve_merge_data( [] );
		$this->assertSame( '', $empty['source.course_title'] );
		$this->assertSame( '', $empty['source.completion_date'] );
		$this->assertSame( '', $empty['source.instructor'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * Sub-course triggers (1.0 scope addition, Ryan 2026-07-23):
	 * lessons, topics, and quizzes.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Seed the LD lesson/topic/quiz fixture posts.
	 *
	 * @return void
	 */
	private function seed_sub_course_posts() {
		$GLOBALS['ppcert_test_posts'][305] = (object) [
			'ID'          => 305,
			'post_type'   => 'sfwd-lessons',
			'post_status' => 'publish',
			'post_title'  => 'Leaf Structure and Function',
			'post_author' => 9,
		];
		$GLOBALS['ppcert_test_posts'][306] = (object) [
			'ID'          => 306,
			'post_type'   => 'sfwd-topic',
			'post_status' => 'publish',
			'post_title'  => 'Stomata Up Close',
			'post_author' => 9,
		];
		$GLOBALS['ppcert_test_posts'][307] = (object) [
			'ID'          => 307,
			'post_type'   => 'sfwd-quiz',
			'post_status' => 'publish',
			'post_title'  => 'Botany Final Quiz',
			'post_author' => 9,
		];
	}

	/**
	 * Seed an active trigger of any LD type.
	 *
	 * @param string     $type       Trigger type id.
	 * @param string     $ref        Source ref.
	 * @param array|null $conditions Conditions.
	 * @return void
	 */
	private function seed_typed_trigger( $type, $ref, $conditions = null ) {
		$this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'uuid'            => 'trg-' . $type . '-' . $ref,
				'template_id'     => $this->template_id,
				'trigger_type'    => $type,
				'source_ref'      => $ref,
				'conditions_json' => null === $conditions ? null : wp_json_encode( $conditions ),
				'is_active'       => 1,
			]
		);
	}

	/**
	 * Lesson completion issues once with lesson + parent-course data;
	 * re-fires are suppressed.
	 *
	 * @return void
	 */
	public function test_lesson_completion_issues_and_suppresses() {
		$this->seed_sub_course_posts();
		$adapter = new PressPrimer_Certificate_LearnDash_Lesson_Adapter();
		$adapter->register();
		$this->seed_typed_trigger( 'lms_learndash_lesson', '305' );

		$payload = [
			'user'     => (object) [ 'ID' => 7 ],
			'course'   => $GLOBALS['ppcert_test_posts'][301],
			'lesson'   => $GLOBALS['ppcert_test_posts'][305],
			'progress' => [],
		];

		do_action( 'learndash_lesson_completed', $payload );
		do_action( 'learndash_lesson_completed', $payload );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'lms_learndash_lesson', $rows[0]['source_type'] );
		$this->assertSame( '305', $rows[0]['source_ref'] );

		$resolved = $adapter->resolve_merge_data(
			[
				'lms_lesson_title' => 'Leaf Structure and Function',
				'lms_course_title' => 'Advanced Botany',
				'src_completed_at' => '2026-06-12 09:30:00',
				'lms_instructor'   => 'Prof. Marisol Vega',
			]
		);
		$this->assertSame( 'Leaf Structure and Function', $resolved['source.lesson_title'] );
		$this->assertSame( 'Advanced Botany', $resolved['source.course_title'] );
	}

	/**
	 * An 'any lesson in this course' trigger fires for every lesson in
	 * its course, never outside it, and certificates record the REAL
	 * fired lesson - never the sentinel (Feature 1.1-002).
	 *
	 * @return void
	 */
	public function test_any_lesson_trigger_scoped_to_course() {
		$this->seed_sub_course_posts();

		// A second course with its own lesson.
		$GLOBALS['ppcert_test_posts'][401] = (object) [
			'ID'          => 401,
			'post_type'   => 'sfwd-courses',
			'post_status' => 'publish',
			'post_title'  => 'Intro to Zoology',
			'post_author' => 9,
		];
		$GLOBALS['ppcert_test_posts'][405] = (object) [
			'ID'          => 405,
			'post_type'   => 'sfwd-lessons',
			'post_status' => 'publish',
			'post_title'  => 'Vertebrates',
			'post_author' => 9,
		];

		$adapter = new PressPrimer_Certificate_LearnDash_Lesson_Adapter();
		$adapter->register();
		$this->seed_typed_trigger( 'lms_learndash_lesson', 'any', [ 'course_id' => '301' ] );

		// In-course lesson issues.
		do_action(
			'learndash_lesson_completed',
			[
				'user'     => (object) [ 'ID' => 7 ],
				'course'   => $GLOBALS['ppcert_test_posts'][301],
				'lesson'   => $GLOBALS['ppcert_test_posts'][305],
				'progress' => [],
			]
		);

		// Out-of-course lesson does not.
		do_action(
			'learndash_lesson_completed',
			[
				'user'     => (object) [ 'ID' => 7 ],
				'course'   => $GLOBALS['ppcert_test_posts'][401],
				'lesson'   => $GLOBALS['ppcert_test_posts'][405],
				'progress' => [],
			]
		);

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '305', $rows[0]['source_ref'] );
	}

	/**
	 * An 'any' trigger's reissue setting governs every fired object:
	 * with reissue on, completing the same lesson twice issues twice.
	 *
	 * @return void
	 */
	public function test_any_lesson_trigger_honors_reissue() {
		$this->seed_sub_course_posts();
		$adapter = new PressPrimer_Certificate_LearnDash_Lesson_Adapter();
		$adapter->register();
		$this->seed_typed_trigger(
			'lms_learndash_lesson',
			'any',
			[
				'course_id' => '301',
				'reissue'   => true,
			]
		);

		$payload = [
			'user'     => (object) [ 'ID' => 7 ],
			'course'   => $GLOBALS['ppcert_test_posts'][301],
			'lesson'   => $GLOBALS['ppcert_test_posts'][305],
			'progress' => [],
		];

		do_action( 'learndash_lesson_completed', $payload );
		do_action( 'learndash_lesson_completed', $payload );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 2, $rows );
		$this->assertSame( '305', $rows[1]['source_ref'] );
	}

	/**
	 * A scoped 'any' trigger fails closed when the payload cannot prove
	 * its course.
	 *
	 * @return void
	 */
	public function test_any_lesson_trigger_fails_closed_without_course() {
		$this->seed_sub_course_posts();
		$adapter = new PressPrimer_Certificate_LearnDash_Lesson_Adapter();
		$adapter->register();
		$this->seed_typed_trigger( 'lms_learndash_lesson', 'any', [ 'course_id' => '301' ] );

		do_action(
			'learndash_lesson_completed',
			[
				'user'     => (object) [ 'ID' => 7 ],
				'lesson'   => $GLOBALS['ppcert_test_posts'][305],
				'progress' => [],
			]
		);

		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * Topic completion issues once with topic + lesson + course data.
	 *
	 * @return void
	 */
	public function test_topic_completion_issues() {
		$this->seed_sub_course_posts();
		$adapter = new PressPrimer_Certificate_LearnDash_Topic_Adapter();
		$adapter->register();
		$this->seed_typed_trigger( 'lms_learndash_topic', '306' );

		do_action(
			'learndash_topic_completed',
			[
				'user'     => (object) [ 'ID' => 7 ],
				'course'   => $GLOBALS['ppcert_test_posts'][301],
				'lesson'   => $GLOBALS['ppcert_test_posts'][305],
				'topic'    => $GLOBALS['ppcert_test_posts'][306],
				'progress' => [],
			]
		);

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'lms_learndash_topic', $rows[0]['source_type'] );
		$this->assertSame( '306', $rows[0]['source_ref'] );

		$resolved = $adapter->resolve_merge_data(
			[
				'lms_topic_title'  => 'Stomata Up Close',
				'lms_lesson_title' => 'Leaf Structure and Function',
				'lms_course_title' => 'Advanced Botany',
				'src_completed_at' => '2026-06-12 09:30:00',
				'lms_instructor'   => 'Prof. Marisol Vega',
			]
		);
		$this->assertSame( 'Stomata Up Close', $resolved['source.topic_title'] );
		$this->assertSame( 'Leaf Structure and Function', $resolved['source.lesson_title'] );
	}

	/**
	 * A LearnDash quiz payload builder.
	 *
	 * @param float $percentage Score percent.
	 * @param bool  $pass       LD's own pass flag.
	 * @param bool  $quiz_as_id Send 'quiz' as a raw ID (secondary firing sites).
	 * @return array
	 */
	private function quiz_payload( $percentage, $pass = true, $quiz_as_id = false ) {
		return [
			'quiz'       => $quiz_as_id ? 307 : $GLOBALS['ppcert_test_posts'][307],
			'pass'       => $pass ? 1 : 0,
			'percentage' => $percentage,
			'course'     => $GLOBALS['ppcert_test_posts'][301],
			'lesson'     => 0,
			'topic'      => 0,
			'completed'  => 1781256600,
		];
	}

	/**
	 * Quiz passes gate on LD's own pass flag, min_score raises the bar
	 * at the boundary, and retakes are suppressed.
	 *
	 * @return void
	 */
	public function test_quiz_pass_gating_min_score_and_suppression() {
		$this->seed_sub_course_posts();
		$adapter = new PressPrimer_Certificate_LearnDash_Quiz_Adapter();
		$adapter->register();
		$this->seed_typed_trigger( 'lms_learndash_quiz', '307', [ 'min_score' => 80.0 ] );

		$user = (object) [ 'ID' => 7 ];

		// Failed attempt: never issues, even above the trigger bar.
		do_action( 'learndash_quiz_completed', $this->quiz_payload( 90.0, false ), $user );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Passed under the trigger bar.
		do_action( 'learndash_quiz_completed', $this->quiz_payload( 79.99 ), $user );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Exactly at the bar: issues.
		do_action( 'learndash_quiz_completed', $this->quiz_payload( 80.0 ), $user );
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Retake: suppressed.
		do_action( 'learndash_quiz_completed', $this->quiz_payload( 95.0 ), $user );
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		$resolved = $adapter->resolve_merge_data(
			[
				'src_quiz_title'    => 'Botany Final Quiz',
				'src_score_display' => '86.5%',
				'src_grade_display' => 'Passed',
				'src_completed_at'  => '2026-06-12 09:30:00',
				'lms_course_title'  => 'Advanced Botany',
				'lms_instructor'    => 'Prof. Marisol Vega',
			]
		);
		$this->assertSame( 'Botany Final Quiz', $resolved['source.quiz_title'] );
		$this->assertSame( '86.5%', $resolved['source.score'] );
		$this->assertSame( 'Advanced Botany', $resolved['source.course_title'] );
	}

	/**
	 * The secondary firing sites (essay grading, profile edits) send
	 * 'quiz' as a raw ID: the listener loads the post.
	 *
	 * @return void
	 */
	public function test_quiz_payload_with_raw_id_issues() {
		$this->seed_sub_course_posts();
		$adapter = new PressPrimer_Certificate_LearnDash_Quiz_Adapter();
		$adapter->register();
		$this->seed_typed_trigger( 'lms_learndash_quiz', '307' );

		do_action(
			'learndash_quiz_completed',
			$this->quiz_payload( 88.0, true, true ),
			(object) [ 'ID' => 7 ]
		);

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( '307', $rows[0]['source_ref'] );
	}

	/**
	 * Hierarchical source cascades (Ryan 2026-07-23: same-named
	 * lessons/topics/quizzes need context): course level options, then
	 * parent-scoped sources, including LD's global course quizzes.
	 *
	 * @return void
	 */
	public function test_hierarchical_source_pickers() {
		$this->seed_sub_course_posts();

		$GLOBALS['ppcert_test_ld_steps'] = [
			301 => [
				'sfwd-lessons' => [ 305 ],
				'sfwd-quiz'    => [ 307 ],
			],
		];
		$GLOBALS['ppcert_test_ld_topics'] = [
			305 => [ $GLOBALS['ppcert_test_posts'][306] ],
		];
		$GLOBALS['ppcert_test_ld_global_quizzes'] = [];

		$lesson = new PressPrimer_Certificate_LearnDash_Lesson_Adapter();
		$topic  = new PressPrimer_Certificate_LearnDash_Topic_Adapter();
		$quiz   = new PressPrimer_Certificate_LearnDash_Quiz_Adapter();

		// Declared cascades.
		$this->assertSame( 'course', $lesson->get_source_levels()[0]['key'] );
		$this->assertSame(
			[ 'course', 'lesson' ],
			array_column( $topic->get_source_levels(), 'key' )
		);
		$this->assertSame( 'course', $quiz->get_source_levels()[0]['key'] );

		// Course level options list published courses.
		$courses = $lesson->get_level_options( 'course', [] );
		$this->assertSame( [ 'Advanced Botany' ], array_column( $courses, 'title' ) );

		// Lesson sources scope to the chosen course's lesson steps.
		$lessons = $lesson->get_sources_for_parents( [ 'course' => 301 ] );
		$this->assertSame( [ '305' ], array_column( $lessons, 'id' ) );

		// Topic level options: the course's lessons; sources: the
		// lesson's topics.
		$this->assertSame(
			[ '305' ],
			array_column( $topic->get_level_options( 'lesson', [ 'course' => 301 ] ), 'id' )
		);
		$this->assertSame(
			[ '306' ],
			array_column(
				$topic->get_sources_for_parents(
					[
						'course' => 301,
						'lesson' => 305,
					]
				),
				'id'
			)
		);

		// Quiz sources: quiz steps plus global course quizzes.
		$this->assertSame(
			[ '307' ],
			array_column( $quiz->get_sources_for_parents( [ 'course' => 301 ] ), 'id' )
		);

		// REST routing: ?level= hits the level picker, ?parents= the
		// scoped picker.
		$lesson->register();
		$GLOBALS['ppcert_test_user_caps'] = true;

		$controller = new PressPrimer_Certificate_REST_Triggers_Controller();

		$level_response = $controller->get_types(
			new WP_REST_Request(
				[
					'type'  => 'lms_learndash_lesson',
					'level' => 'course',
				]
			)
		);
		$this->assertSame( [ 'Advanced Botany' ], array_column( $level_response->get_data(), 'title' ) );

		$scoped_response = $controller->get_types(
			new WP_REST_Request(
				[
					'type'    => 'lms_learndash_lesson',
					'parents' => [ 'course' => '301' ],
				]
			)
		);
		$this->assertSame( [ '305' ], array_column( $scoped_response->get_data(), 'id' ) );
	}

	/**
	 * Shared keys carry every contributing trigger type's tag: scoping
	 * to one LD trigger keeps the shared course fields and drops the
	 * other types' own fields.
	 *
	 * @return void
	 */
	public function test_shared_field_keys_scope_across_ld_trigger_types() {
		( new PressPrimer_Certificate_LearnDash_Lesson_Adapter() )->register();
		( new PressPrimer_Certificate_LearnDash_Quiz_Adapter() )->register();

		// Unscoped: the shared course_title carries all three tags.
		$all = PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );
		$this->assertContains( 'lms_learndash', $all['source.course_title']['trigger_types'] );
		$this->assertContains( 'lms_learndash_lesson', $all['source.course_title']['trigger_types'] );
		$this->assertContains( 'lms_learndash_quiz', $all['source.course_title']['trigger_types'] );

		// Scoped to the lesson trigger: lesson fields + shared course
		// fields, no quiz fields.
		$lesson_scope = PressPrimer_Certificate_Merge_Field_Registry::get_fields(
			'designer',
			[ 'trigger_types' => [ 'lms_learndash_lesson' ] ]
		);
		$this->assertArrayHasKey( 'source.lesson_title', $lesson_scope );
		$this->assertArrayHasKey( 'source.course_title', $lesson_scope );
		$this->assertArrayHasKey( 'source.instructor', $lesson_scope );
		$this->assertArrayNotHasKey( 'source.quiz_title', $lesson_scope );

		// Scoped to the quiz trigger: quiz fields + shared course
		// fields, no lesson fields.
		$quiz_scope = PressPrimer_Certificate_Merge_Field_Registry::get_fields(
			'designer',
			[ 'trigger_types' => [ 'lms_learndash_quiz' ] ]
		);
		$this->assertArrayHasKey( 'source.quiz_title', $quiz_scope );
		$this->assertArrayHasKey( 'source.score', $quiz_scope );
		$this->assertArrayHasKey( 'source.course_title', $quiz_scope );
		$this->assertArrayNotHasKey( 'source.lesson_title', $quiz_scope );
	}
}
