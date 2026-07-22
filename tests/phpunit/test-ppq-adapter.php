<?php
/**
 * PressPrimer Quiz adapter tests (Feature 004, Prompt 4.1)
 *
 * The reference adapter: availability gating, registration through the
 * public filters only, threshold behavior at the boundary, retake
 * suppression, and merge-data correctness. Live-PPQ behavior is
 * additionally verified on the dev site per the prompt's QA matrix.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

// The adapter's availability gate checks PPQ's constant + attempt
// class; both are stubbed here (matching PPQ 3.0.3 shapes).
if ( ! defined( 'PRESSPRIMER_QUIZ_VERSION' ) ) {
	define( 'PRESSPRIMER_QUIZ_VERSION', '3.0.3-test' );
}

if ( ! class_exists( 'PressPrimer_Quiz_Attempt' ) ) {
	/**
	 * Test double for the PPQ attempt class (availability check only).
	 */
	class PressPrimer_Quiz_Attempt {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- Availability stub for the adapter under test.
}

/**
 * Unavailable-variant double: the deactivated-PPQ scenario.
 */
class PPCert_Test_Unavailable_PPQ_Adapter extends PressPrimer_Certificate_PPQ_Adapter { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test double.

	/**
	 * Simulate PPQ absent.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return false;
	}
}

/**
 * PPQ adapter test case
 *
 * @since 1.0.0
 */
class Test_PPQ_Adapter extends TestCase { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test case follows its doubles.

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Adapter under test.
	 *
	 * @var PressPrimer_Certificate_PPQ_Adapter
	 */
	private $adapter;

	/**
	 * Published template id with source tokens.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Reset state, register the adapter, seed a template + trigger.
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
					'id'    => 'el_srctitle',
					'type'  => 'merge_field',
					'x'     => 100,
					'y'     => 100,
					'w'     => 400,
					'h'     => 30,
					'z'     => 1,
					'props' => [
						'token'       => '{{source.quiz_title}}',
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
					'id'    => 'el_srcscore',
					'type'  => 'merge_field',
					'x'     => 100,
					'y'     => 160,
					'w'     => 400,
					'h'     => 30,
					'z'     => 2,
					'props' => [
						'token'       => '{{source.score}}',
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
				'uuid'                  => 'tpl-ppq-test',
				'title'                 => 'Quiz Completion',
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

		$this->adapter = new PressPrimer_Certificate_PPQ_Adapter();
		$this->adapter->register();
	}

	/**
	 * Seed an active trigger for quiz 7.
	 *
	 * @param array|null $conditions Conditions (null = none stored).
	 * @return int Trigger row id.
	 */
	private function seed_trigger( $conditions = null ) {
		return $this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'uuid'            => 'trg-ppq-test',
				'template_id'     => $this->template_id,
				'trigger_type'    => 'ppq_quiz',
				'source_ref'      => '7',
				'conditions_json' => null === $conditions ? null : wp_json_encode( $conditions ),
				'is_active'       => 1,
			]
		);
	}

	/**
	 * A PPQ attempt/quiz pair as the hook would deliver them.
	 *
	 * @param float $score Score percent.
	 * @return array [ attempt, quiz ].
	 */
	private function passed_attempt( $score ) {
		$attempt = (object) [
			'user_id'       => 7,
			'quiz_id'       => 7,
			'score_percent' => $score,
			'passed'        => 1,
			'finished_at'   => '2026-06-12 09:30:00',
		];

		$quiz = (object) [
			'id'           => 7,
			'title'        => 'Advanced Botany Quiz',
			'pass_percent' => 70.0,
		];

		return [ $attempt, $quiz ];
	}

	/**
	 * Registration goes through the public filters only, and an
	 * unavailable adapter contributes nothing (Edge US-5).
	 *
	 * @return void
	 */
	public function test_registration_and_availability_gating() {
		$types = PressPrimer_Certificate_Trigger_Registry::get_types();

		$this->assertArrayHasKey( 'ppq_quiz', $types );
		$this->assertSame( 'Quiz passed (PressPrimer Quiz)', $types['ppq_quiz']['label'] );
		$this->assertSame( [], $types['ppq_quiz']['source_post_types'] );
		$this->assertArrayHasKey( 'min_score', $types['ppq_quiz']['conditions_schema'] );

		$fields = PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );
		$this->assertArrayHasKey( 'source.quiz_title', $fields );
		$this->assertArrayHasKey( 'source.score', $fields );
		$this->assertArrayHasKey( 'source.grade', $fields );
		$this->assertArrayHasKey( 'source.pass_date', $fields );

		// Deactivated PPQ: a fresh registry sees nothing from the
		// unavailable variant.
		ppcert_tests_reset_hooks();
		( new PPCert_Test_Unavailable_PPQ_Adapter() )->register();

		$this->assertArrayNotHasKey(
			'ppq_quiz',
			PressPrimer_Certificate_Trigger_Registry::get_types()
		);
	}

	/**
	 * A pass issues once with correct source data and merge values.
	 *
	 * @return void
	 */
	public function test_pass_issues_certificate_with_merge_data() {
		$this->seed_trigger();

		list( $attempt, $quiz ) = $this->passed_attempt( 86.5 );
		do_action( 'pressprimer_quiz_quiz_passed', $attempt, $quiz );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ppq_quiz', $rows[0]['source_type'] );
		$this->assertSame( '7', $rows[0]['source_ref'] );
		$this->assertSame( 7, (int) $rows[0]['recipient_id'] );

		$merge = json_decode( $rows[0]['merge_data_json'], true );
		$this->assertSame( 'Advanced Botany Quiz', $merge['source.quiz_title'] );
		$this->assertSame( '86.5%', $merge['source.score'] );
	}

	/**
	 * min_score boundary: below issues nothing, at threshold issues.
	 *
	 * @return void
	 */
	public function test_min_score_boundary() {
		$this->seed_trigger( [ 'min_score' => 80.0 ] );

		// 79.99: passed the quiz (70) but under the trigger's bar.
		list( $attempt, $quiz ) = $this->passed_attempt( 79.99 );
		do_action( 'pressprimer_quiz_quiz_passed', $attempt, $quiz );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Exactly 80: issues.
		list( $attempt, $quiz ) = $this->passed_attempt( 80.0 );
		do_action( 'pressprimer_quiz_quiz_passed', $attempt, $quiz );
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * Null min_score follows the quiz's own threshold: any pass issues.
	 *
	 * @return void
	 */
	public function test_null_min_score_follows_quiz_threshold() {
		$this->seed_trigger( [ 'min_score' => null ] );

		list( $attempt, $quiz ) = $this->passed_attempt( 70.0 );
		do_action( 'pressprimer_quiz_quiz_passed', $attempt, $quiz );

		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * A retake pass is suppressed by the engine (idempotency backstop).
	 *
	 * @return void
	 */
	public function test_retake_pass_is_suppressed() {
		$this->seed_trigger();

		list( $attempt, $quiz ) = $this->passed_attempt( 86.5 );
		do_action( 'pressprimer_quiz_quiz_passed', $attempt, $quiz );

		list( $retake, $quiz2 ) = $this->passed_attempt( 95.0 );
		do_action( 'pressprimer_quiz_quiz_passed', $retake, $quiz2 );

		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * Two active triggers on the same quiz issue independently.
	 *
	 * @return void
	 */
	public function test_multiple_triggers_issue_per_template() {
		$this->seed_trigger();

		$second_template = $this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'                  => 'tpl-ppq-second',
				'title'                 => 'Honor Roll',
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

		$this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'uuid'            => 'trg-ppq-second',
				'template_id'     => $second_template,
				'trigger_type'    => 'ppq_quiz',
				'source_ref'      => '7',
				'conditions_json' => wp_json_encode( [ 'min_score' => 90 ] ),
				'is_active'       => 1,
			]
		);

		// 86.5 clears the unconditioned trigger but not the 90 bar.
		list( $attempt, $quiz ) = $this->passed_attempt( 86.5 );
		do_action( 'pressprimer_quiz_quiz_passed', $attempt, $quiz );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( $this->template_id, (int) $rows[0]['template_id'] );

		// 95 clears both; the first template's is suppressed as a dupe.
		list( $attempt, $quiz ) = $this->passed_attempt( 95.0 );
		do_action( 'pressprimer_quiz_quiz_passed', $attempt, $quiz );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 2, $rows );
	}

	/**
	 * Sources: published quizzes, searchable by title.
	 *
	 * @return void
	 */
	public function test_sources_lists_published_quizzes() {
		$this->wpdb->seed_row(
			'wp_ppq_quizzes',
			[
				'title'  => 'Advanced Botany Quiz',
				'status' => 'published',
			]
		);
		$this->wpdb->seed_row(
			'wp_ppq_quizzes',
			[
				'title'  => 'Draft Quiz',
				'status' => 'draft',
			]
		);
		$this->wpdb->seed_row(
			'wp_ppq_quizzes',
			[
				'title'  => 'Chemistry Basics',
				'status' => 'published',
			]
		);

		$all = $this->adapter->get_sources();
		$this->assertSame(
			[ 'Advanced Botany Quiz', 'Chemistry Basics' ],
			array_column( $all, 'title' )
		);

		$searched = $this->adapter->get_sources( 'botany' );
		$this->assertCount( 1, $searched );
		$this->assertSame( 'Advanced Botany Quiz', $searched[0]['title'] );
	}

	/**
	 * Resolvers return display-ready scalars from the listener context.
	 *
	 * @return void
	 */
	public function test_merge_resolvers() {
		$context = [
			'ppq_quiz_id'       => 7,
			'ppq_quiz_title'    => 'Advanced Botany Quiz',
			'ppq_score_percent' => 92.0,
			'ppq_finished_at'   => '2026-06-12 09:30:00',
		];

		$resolved = $this->adapter->resolve_merge_data( $context );

		$this->assertSame( 'Advanced Botany Quiz', $resolved['source.quiz_title'] );
		$this->assertSame( '92%', $resolved['source.score'] );
		$this->assertSame( 'Passed', $resolved['source.grade'] );
		$this->assertNotSame( '', $resolved['source.pass_date'] );

		// Manual issuance without PPQ context: source fields empty, never
		// the raw token (Feature 002 Edge US-5).
		$empty = $this->adapter->resolve_merge_data( [] );
		$this->assertSame( '', $empty['source.quiz_title'] );
		$this->assertSame( '', $empty['source.grade'] );
	}
}
