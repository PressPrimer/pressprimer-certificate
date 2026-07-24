<?php
/**
 * LifterLMS adapter tests (Feature 004, Prompt 4.3)
 *
 * The second course adapter on the shared helpers, plus the FR-005
 * alphabetical-listing rule across registered adapters. Live-LifterLMS
 * behavior is additionally verified on the dev site per the prompt's
 * QA matrix.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

// The adapter's availability gate checks LifterLMS's main class
// (matching LifterLMS 10.0.8).
if ( ! class_exists( 'LifterLMS' ) ) {
	/**
	 * Test double for the LifterLMS main class (availability check only).
	 */
	class LifterLMS {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- Availability stub for the adapter under test.
}

// The FR-005 listing test registers the LearnDash adapter alongside.
if ( ! defined( 'LEARNDASH_VERSION' ) ) {
	define( 'LEARNDASH_VERSION', '4.23.0-test' );
}

if ( ! function_exists( 'llms_get_post' ) ) {
	/**
	 * Stub: LLMS model factory ($GLOBALS['ppcert_test_llms_posts'][id] = object).
	 *
	 * @param int $post_id Post id.
	 * @return object|null
	 */
	function llms_get_post( $post_id ) { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- LLMS API stub for the adapters under test.
		return isset( $GLOBALS['ppcert_test_llms_posts'][ (int) $post_id ] )
			? $GLOBALS['ppcert_test_llms_posts'][ (int) $post_id ]
			: null;
	}
}

/**
 * Minimal LLMS model double: get()/get_lessons() from a data map.
 */
class PPCert_Test_LLMS_Model { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Model stub for the adapters under test.

	/**
	 * Model data.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Constructor.
	 *
	 * @param array $data Data map ('lessons' key feeds get_lessons()).
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Field getter (mirrors the LLMS model contract).
	 *
	 * @param string $key Field key.
	 * @return mixed
	 */
	public function get( $key ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : null;
	}

	/**
	 * Lesson list getter (mirrors LLMS_Course).
	 *
	 * @param string $return_type Return shape.
	 * @return array
	 */
	public function get_lessons( $return_type = 'lessons' ) {
		return isset( $this->data['lessons'] ) ? $this->data['lessons'] : [];
	}
}

/**
 * Unavailable-variant double: the deactivated-LifterLMS scenario.
 */
class PPCert_Test_Unavailable_LifterLMS_Adapter extends PressPrimer_Certificate_LifterLMS_Adapter { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test double.

	/**
	 * Simulate LifterLMS absent.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return false;
	}
}

/**
 * LifterLMS adapter test case
 *
 * @since 1.0.0
 */
class Test_LifterLMS_Adapter extends TestCase { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test case follows its doubles.

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Adapter under test.
	 *
	 * @var PressPrimer_Certificate_LifterLMS_Adapter
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
			401 => (object) [
				'ID'          => 401,
				'post_type'   => 'course',
				'post_status' => 'publish',
				'post_title'  => 'Watercolor Foundations',
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
			],
		];

		$this->template_id = $this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'                  => 'tpl-llms-test',
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

		$this->adapter = new PressPrimer_Certificate_LifterLMS_Adapter();
		$this->adapter->register();
	}

	/**
	 * Seed an active trigger for course 401.
	 *
	 * @return int Trigger row id.
	 */
	private function seed_trigger() {
		return $this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'uuid'            => 'trg-llms-test',
				'template_id'     => $this->template_id,
				'trigger_type'    => 'lms_lifterlms',
				'source_ref'      => '401',
				'conditions_json' => null,
				'is_active'       => 1,
			]
		);
	}

	/**
	 * Registration goes through the public filters only, and an
	 * unavailable adapter contributes nothing (Edge US-5).
	 *
	 * @return void
	 */
	public function test_registration_and_availability_gating() {
		$types = PressPrimer_Certificate_Trigger_Registry::get_types();

		$this->assertArrayHasKey( 'lms_lifterlms', $types );
		$this->assertSame( 'Course completed (LifterLMS)', $types['lms_lifterlms']['label'] );
		$this->assertSame( 'Course', $types['lms_lifterlms']['source_label'] );
		$this->assertSame( [ 'course' ], $types['lms_lifterlms']['source_post_types'] );
		$this->assertSame( [ 'reissue' ], array_keys( $types['lms_lifterlms']['conditions_schema'] ), 'Only the universal reissue toggle' );

		$fields = PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );
		$this->assertArrayHasKey( 'source.course_title', $fields );
		$this->assertContains( 'lms_lifterlms', $fields['source.course_title']['trigger_types'] );

		// Deactivated LifterLMS: a fresh registry sees nothing from the
		// unavailable variant.
		ppcert_tests_reset_hooks();
		( new PPCert_Test_Unavailable_LifterLMS_Adapter() )->register();

		$this->assertArrayNotHasKey(
			'lms_lifterlms',
			PressPrimer_Certificate_Trigger_Registry::get_types()
		);
	}

	/**
	 * A completion issues once with correct source data and merge
	 * values built from the persisted course post.
	 *
	 * @return void
	 */
	public function test_completion_issues_certificate_with_merge_data() {
		$this->seed_trigger();

		do_action( 'lifterlms_course_completed', 7, 401 );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'lms_lifterlms', $rows[0]['source_type'] );
		$this->assertSame( '401', $rows[0]['source_ref'] );
		$this->assertSame( 7, (int) $rows[0]['recipient_id'] );

		$merge = json_decode( $rows[0]['merge_data_json'], true );
		$this->assertSame( 'Watercolor Foundations', $merge['source.course_title'] );
	}

	/**
	 * A source_ref pointing at a non-course post never issues: the
	 * listener validates the post type before building context.
	 *
	 * @return void
	 */
	public function test_non_course_post_is_ignored() {
		$GLOBALS['ppcert_test_posts'][402] = (object) [
			'ID'          => 402,
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'About Us',
			'post_author' => 9,
		];

		$this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'uuid'            => 'trg-llms-page',
				'template_id'     => $this->template_id,
				'trigger_type'    => 'lms_lifterlms',
				'source_ref'      => '402',
				'conditions_json' => null,
				'is_active'       => 1,
			]
		);

		do_action( 'lifterlms_course_completed', 7, 402 );

		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * A re-fired completion (LifterLMS cascades and recalcs) is
	 * suppressed by the engine.
	 *
	 * @return void
	 */
	public function test_refire_is_suppressed() {
		$this->seed_trigger();

		do_action( 'lifterlms_course_completed', 7, 401 );
		do_action( 'lifterlms_course_completed', 7, 401 );

		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * Sources: published LifterLMS courses, searchable by title.
	 *
	 * @return void
	 */
	public function test_sources_lists_published_courses() {
		$GLOBALS['ppcert_test_posts'][403] = (object) [
			'ID'          => 403,
			'post_type'   => 'course',
			'post_status' => 'publish',
			'post_title'  => 'Acrylic Landscapes',
			'post_author' => 9,
		];

		$all = $this->adapter->get_sources();
		$this->assertSame(
			[ 'Acrylic Landscapes', 'Watercolor Foundations' ],
			array_column( $all, 'title' )
		);

		$searched = $this->adapter->get_sources( 'water' );
		$this->assertCount( 1, $searched );
		$this->assertSame( 'Watercolor Foundations', $searched[0]['title'] );
	}

	/**
	 * FR-005 positioning: the trigger-types REST listing is alphabetical
	 * by label no matter the registration order.
	 *
	 * @return void
	 */
	public function test_rest_types_listing_is_alphabetical() {
		// Register two more adapters deliberately out of order. Sorting
		// is integration-first, then short label (two-step picker,
		// Ryan 2026-07-23): 'Double LMS' < 'LearnDash' < 'LifterLMS'.
		( new PPCert_Test_Double_Adapter() )->register();
		( new PressPrimer_Certificate_LearnDash_Adapter() )->register();

		$GLOBALS['ppcert_test_user_caps'] = true;

		$controller = new PressPrimer_Certificate_REST_Triggers_Controller();
		$response   = $controller->get_types( new WP_REST_Request( [] ) );
		$data       = $response->get_data();

		$this->assertSame(
			[ 'Double LMS', 'LearnDash', 'LifterLMS' ],
			array_column( $data, 'integration' )
		);
		$this->assertSame(
			[
				'Double LMS',
				'Course completed (LearnDash)',
				'Course completed (LifterLMS)',
			],
			array_column( $data, 'label' )
		);

		// The two-step picker metadata reaches the client.
		$this->assertSame( 'Course completed', $data[1]['short_label'] );
		$this->assertSame( [], $data[1]['source_levels'] );
	}

	/*
	 * ------------------------------------------------------------------
	 * Quiz trigger (1.0 scope addition, Ryan 2026-07-23).
	 * ------------------------------------------------------------------
	 */

	/**
	 * An LLMS attempt double for the quiz-passed hook.
	 *
	 * @param float $grade Grade percent.
	 * @return PPCert_Test_LLMS_Model
	 */
	private function llms_attempt( $grade ) {
		return new PPCert_Test_LLMS_Model(
			[
				'grade'     => $grade,
				'lesson_id' => 410,
			]
		);
	}

	/**
	 * Seed the quiz fixture posts + LLMS relationship models.
	 *
	 * @return void
	 */
	private function seed_quiz_fixtures() {
		$GLOBALS['ppcert_test_posts'][402] = (object) [
			'ID'          => 402,
			'post_type'   => 'llms_quiz',
			'post_status' => 'publish',
			'post_title'  => 'Watercolor Final Quiz',
			'post_author' => 9,
		];
		$GLOBALS['ppcert_test_posts'][410] = (object) [
			'ID'          => 410,
			'post_type'   => 'lesson',
			'post_status' => 'publish',
			'post_title'  => 'Wet on Wet',
			'post_author' => 9,
		];

		$GLOBALS['ppcert_test_llms_posts'] = [
			401 => new PPCert_Test_LLMS_Model(
				[
					'lessons' => [
						new PPCert_Test_LLMS_Model( [ 'quiz' => 402 ] ),
						new PPCert_Test_LLMS_Model( [ 'quiz' => 0 ] ),
					],
				]
			),
			410 => new PPCert_Test_LLMS_Model( [ 'parent_course' => 401 ] ),
		];
	}

	/**
	 * Quiz registration: cascade declared, fields tagged, min_score help.
	 *
	 * @return void
	 */
	public function test_quiz_registration_and_cascade_declaration() {
		$adapter = new PressPrimer_Certificate_LifterLMS_Quiz_Adapter();
		$adapter->register();

		$types = PressPrimer_Certificate_Trigger_Registry::get_types();
		$this->assertArrayHasKey( 'lms_lifterlms_quiz', $types );
		$this->assertSame( 'Quiz passed (LifterLMS)', $types['lms_lifterlms_quiz']['label'] );
		$this->assertSame( 'LifterLMS', $types['lms_lifterlms_quiz']['integration'] );
		$this->assertSame( [ 'llms_quiz' ], $types['lms_lifterlms_quiz']['source_post_types'] );
		$this->assertSame( 'course', $types['lms_lifterlms_quiz']['source_levels'][0]['key'] );
		$this->assertArrayHasKey( 'min_score', $types['lms_lifterlms_quiz']['conditions_schema'] );

		$fields = PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );
		$this->assertContains( 'lms_lifterlms_quiz', $fields['source.quiz_title']['trigger_types'] );
		$this->assertContains( 'lms_lifterlms_quiz', $fields['source.course_title']['trigger_types'] );
	}

	/**
	 * A passed quiz issues once with merge data resolved from the
	 * attempt and its parent course; min_score gates the boundary and
	 * retakes suppress.
	 *
	 * @return void
	 */
	public function test_quiz_pass_min_score_boundary_and_suppression() {
		$this->seed_quiz_fixtures();
		$adapter = new PressPrimer_Certificate_LifterLMS_Quiz_Adapter();
		$adapter->register();

		$this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'uuid'            => 'trg-llmsq-test',
				'template_id'     => $this->template_id,
				'trigger_type'    => 'lms_lifterlms_quiz',
				'source_ref'      => '402',
				'conditions_json' => wp_json_encode( [ 'min_score' => 80.0 ] ),
				'is_active'       => 1,
			]
		);

		// The hook is pass-only, but the trigger bar still applies.
		do_action( 'lifterlms_quiz_passed', 7, 402, $this->llms_attempt( 79.99 ) );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		do_action( 'lifterlms_quiz_passed', 7, 402, $this->llms_attempt( 80.0 ) );
		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'lms_lifterlms_quiz', $rows[0]['source_type'] );
		$this->assertSame( '402', $rows[0]['source_ref'] );

		// Retake pass: suppressed.
		do_action( 'lifterlms_quiz_passed', 7, 402, $this->llms_attempt( 95.0 ) );
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// The issued certificate's merge data carries the parent course
		// resolved through the attempt's lesson.
		$merge = json_decode( $rows[0]['merge_data_json'], true );
		$this->assertSame( 'Watercolor Foundations', $merge['source.course_title'] );

		// Resolvers on a shared-contract context.
		$resolved = $adapter->resolve_merge_data(
			[
				'src_quiz_title'    => 'Watercolor Final Quiz',
				'src_score_display' => '80%',
				'src_grade_display' => 'Passed',
				'lms_course_title'  => 'Watercolor Foundations',
				'src_completed_at'  => '2026-06-12 09:30:00',
				'lms_instructor'    => 'Prof. Marisol Vega',
			]
		);
		$this->assertSame( 'Watercolor Final Quiz', $resolved['source.quiz_title'] );
		$this->assertSame( '80%', $resolved['source.score'] );
		$this->assertSame( 'Watercolor Foundations', $resolved['source.course_title'] );
	}

	/**
	 * The quiz cascade scopes to the chosen course's lesson quizzes.
	 *
	 * @return void
	 */
	public function test_quiz_cascade_scopes_to_course_lessons() {
		$this->seed_quiz_fixtures();
		$adapter = new PressPrimer_Certificate_LifterLMS_Quiz_Adapter();

		$this->assertSame(
			[ 'Watercolor Foundations' ],
			array_column( $adapter->get_level_options( 'course', [] ), 'title' )
		);

		$this->assertSame(
			[ '402' ],
			array_column( $adapter->get_sources_for_parents( [ 'course' => 401 ] ), 'id' )
		);

		// Unknown course: empty, never the global list.
		$this->assertSame( [], $adapter->get_sources_for_parents( [ 'course' => 999 ] ) );
	}
}
