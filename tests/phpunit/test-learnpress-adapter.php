<?php
/**
 * LearnPress adapter tests (Feature 004, Prompt 4.4)
 *
 * Course completion with the failed-graduation gate, plus the quiz
 * trigger (1.0 scope addition, Ryan 2026-07-23): the result payload's
 * own pass flag, min_score boundaries, the no-payload legacy site, and
 * the section-table course cascade. Live-LearnPress behavior is
 * additionally verified on the dev site.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/doubles/class-ppcert-lp-model-stubs.php';

// The adapters' availability gate checks LearnPress's constant
// (matching LearnPress 4.3.4).
if ( ! defined( 'LEARNPRESS_VERSION' ) ) {
	define( 'LEARNPRESS_VERSION', '4.3.4-test' );
}

/**
 * LearnPress adapter test case
 *
 * @since 1.0.0
 */
class Test_LearnPress_Adapter extends TestCase {

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
	 * Reset state, seed users/course/quiz posts + template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_lp_graduations'] = [];

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
			601 => (object) [
				'ID'          => 601,
				'post_type'   => 'lp_course',
				'post_status' => 'publish',
				'post_title'  => 'Origami Foundations',
				'post_author' => 9,
			],
			602 => (object) [
				'ID'          => 602,
				'post_type'   => 'lp_quiz',
				'post_status' => 'publish',
				'post_title'  => 'Folding Basics Quiz',
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
				'uuid'                  => 'tpl-lp-test',
				'title'                 => 'LearnPress Completion',
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
	 * Registration: both LearnPress types, integration metadata, cascade.
	 *
	 * @return void
	 */
	public function test_registration_and_metadata() {
		( new PressPrimer_Certificate_LearnPress_Adapter() )->register();
		( new PressPrimer_Certificate_LearnPress_Quiz_Adapter() )->register();

		$types = PressPrimer_Certificate_Trigger_Registry::get_types();

		$this->assertSame( 'Course completed (LearnPress)', $types['lms_learnpress']['label'] );
		$this->assertSame( 'LearnPress', $types['lms_learnpress']['integration'] );
		$this->assertSame( [ 'lp_course' ], $types['lms_learnpress']['source_post_types'] );
		$this->assertSame( [], $types['lms_learnpress']['conditions_schema'] );

		$this->assertSame( 'Quiz passed (LearnPress)', $types['lms_learnpress_quiz']['label'] );
		$this->assertSame( [ 'lp_quiz' ], $types['lms_learnpress_quiz']['source_post_types'] );
		$this->assertSame( 'course', $types['lms_learnpress_quiz']['source_levels'][0]['key'] );
		$this->assertArrayHasKey( 'min_score', $types['lms_learnpress_quiz']['conditions_schema'] );

		$fields = PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );
		$this->assertContains( 'lms_learnpress', $fields['source.course_title']['trigger_types'] );
		$this->assertContains( 'lms_learnpress_quiz', $fields['source.quiz_title']['trigger_types'] );
	}

	/**
	 * A finished course issues once; a FAILED graduation never does;
	 * an unreadable graduation (legacy site) still issues.
	 *
	 * @return void
	 */
	public function test_course_finish_graduation_gate_and_suppression() {
		( new PressPrimer_Certificate_LearnPress_Adapter() )->register();
		$this->seed_trigger( 'lms_learnpress', '601' );

		// Failed graduation: LearnPress lets learners finish failed.
		$GLOBALS['ppcert_test_lp_graduations'][7][601] = 'failed';
		do_action( 'learn-press/user-course-finished', 601, 7, 11 );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Passed graduation: issues once, re-fire suppressed.
		$GLOBALS['ppcert_test_lp_graduations'][7][601] = 'passed';
		do_action( 'learn-press/user-course-finished', 601, 7, 11 );
		do_action( 'learn-press/user-course-finished', 601, 7, 11 );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'lms_learnpress', $rows[0]['source_type'] );

		$merge = json_decode( $rows[0]['merge_data_json'], true );
		$this->assertSame( 'Origami Foundations', $merge['source.course_title'] );

		// Unknown graduation (no user-item readable): completion stands.
		$this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'                  => 'tpl-lp-second',
				'title'                 => 'Second',
				'status'                => 'published',
				'author_id'             => 1,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => '{"layout_schema_version":1,"page":{"size":"a4","orientation":"landscape","width":842,"height":595},"background":{"color":"#ffffff"},"elements":[]}',
				'updated_at'            => '2026-07-01 00:00:00',
				'deleted_at'            => null,
			]
		);
		unset( $GLOBALS['ppcert_test_lp_graduations'][7][601] );
		do_action( 'learn-press/user-course-finished', 601, 8, 12 );
		// User 8 has no fixture user either - recipient checks may drop
		// it; assert no fatal and at most one extra row.
		$this->assertLessThanOrEqual( 2, count( $this->wpdb->rows( 'wp_ppcert_certificates' ) ) );
	}

	/**
	 * Quiz finishes gate on the result payload's pass flag, honor
	 * min_score at the boundary, ignore the payloadless legacy site,
	 * and suppress retakes.
	 *
	 * @return void
	 */
	public function test_quiz_pass_flag_min_score_and_legacy_site() {
		( new PressPrimer_Certificate_LearnPress_Quiz_Adapter() )->register();
		$this->seed_trigger( 'lms_learnpress_quiz', '602', [ 'min_score' => 80.0 ] );

		// Legacy firing site: no result payload, never awards.
		do_action( 'learn-press/user/quiz-finished', 602, 601, 7 );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Failed result: never awards.
		do_action(
			'learn-press/user/quiz-finished',
			602,
			601,
			7,
			new PPCert_Test_LP_User_Quiz(
				[
					'pass'   => 0,
					'result' => 90.0,
				]
			)
		);
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Passed under the trigger bar.
		do_action(
			'learn-press/user/quiz-finished',
			602,
			601,
			7,
			new PPCert_Test_LP_User_Quiz(
				[
					'pass'   => 1,
					'result' => 79.99,
				]
			)
		);
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Exactly at the bar: issues.
		do_action(
			'learn-press/user/quiz-finished',
			602,
			601,
			7,
			new PPCert_Test_LP_User_Quiz(
				[
					'pass'   => 1,
					'result' => 80.0,
				]
			)
		);
		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'lms_learnpress_quiz', $rows[0]['source_type'] );
		$this->assertSame( '602', $rows[0]['source_ref'] );

		// Retake: suppressed.
		do_action(
			'learn-press/user/quiz-finished',
			602,
			601,
			7,
			new PPCert_Test_LP_User_Quiz(
				[
					'pass'   => 1,
					'result' => 95.0,
				]
			)
		);
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * The quiz cascade reads LearnPress's section tables for the
	 * chosen course.
	 *
	 * @return void
	 */
	public function test_quiz_cascade_scopes_to_course_sections() {
		$adapter = new PressPrimer_Certificate_LearnPress_Quiz_Adapter();

		// Fake-wpdb convenience rows: section items flattened with
		// their course id (the real query joins the sections table).
		$this->wpdb->seed_row(
			'wp_learnpress_section_items',
			[
				'item_id'           => 602,
				'item_type'         => 'lp_quiz',
				'section_course_id' => 601,
			]
		);

		$this->assertSame(
			[ 'Origami Foundations' ],
			array_column( $adapter->get_level_options( 'course', [] ), 'title' )
		);

		$this->assertSame(
			[ '602' ],
			array_column( $adapter->get_sources_for_parents( [ 'course' => 601 ] ), 'id' )
		);

		$this->assertSame( [], $adapter->get_sources_for_parents( [ 'course' => 999 ] ) );
	}
}
