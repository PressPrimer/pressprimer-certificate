<?php
/**
 * PressPrimer Assignment adapter tests (Feature 004, Prompt 4.2)
 *
 * Follows the PPQ reference suite: availability gating, registration
 * through the public filters only, pass/fail gating (PPA's graded event
 * fires for failures too), grade-threshold boundary behavior with
 * points-to-percent normalization, regrade suppression, and merge-data
 * correctness. Live-PPA behavior is additionally verified on the dev
 * site per the prompt's QA matrix.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

// The adapter's availability gate checks PPA's constant + submission
// class; both are stubbed here (matching PPA 2.2.0 shapes).
if ( ! defined( 'PRESSPRIMER_ASSIGNMENT_VERSION' ) ) {
	define( 'PRESSPRIMER_ASSIGNMENT_VERSION', '2.2.0-test' );
}

if ( ! class_exists( 'PressPrimer_Assignment_Submission' ) ) {
	/**
	 * Test double for PPA's submission model: fixture-backed get().
	 */
	class PressPrimer_Assignment_Submission { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Universal.Files.SeparateFunctionsFromOO.Mixed -- Model stub for the adapter under test.

		/**
		 * Fixture map: id => object.
		 *
		 * @var array<int,object>
		 */
		public static $fixtures = [];

		/**
		 * Fixture-backed lookup (mirrors the PPA model contract).
		 *
		 * @param int $id Submission id.
		 * @return object|null
		 */
		public static function get( $id ) {
			return isset( self::$fixtures[ (int) $id ] ) ? self::$fixtures[ (int) $id ] : null;
		}
	}
}

if ( ! class_exists( 'PressPrimer_Assignment_Assignment' ) ) {
	/**
	 * Test double for PPA's assignment model: fixture-backed get().
	 */
	class PressPrimer_Assignment_Assignment { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Model stub for the adapter under test.

		/**
		 * Fixture map: id => object.
		 *
		 * @var array<int,object>
		 */
		public static $fixtures = [];

		/**
		 * Fixture-backed lookup (mirrors the PPA model contract).
		 *
		 * @param int $id Assignment id.
		 * @return object|null
		 */
		public static function get( $id ) {
			return isset( self::$fixtures[ (int) $id ] ) ? self::$fixtures[ (int) $id ] : null;
		}
	}
}

/**
 * Unavailable-variant double: the deactivated-PPA scenario.
 */
class PPCert_Test_Unavailable_PPA_Adapter extends PressPrimer_Certificate_PPA_Adapter { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test double.

	/**
	 * Simulate PPA absent.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return false;
	}
}

/**
 * PPA adapter test case
 *
 * @since 1.0.0
 */
class Test_PPA_Adapter extends TestCase { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test case follows its doubles.

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Adapter under test.
	 *
	 * @var PressPrimer_Certificate_PPA_Adapter
	 */
	private $adapter;

	/**
	 * Published template id with source tokens.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Reset state, register the adapter, seed a template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		PressPrimer_Assignment_Submission::$fixtures = [];
		PressPrimer_Assignment_Assignment::$fixtures = [
			11 => (object) [
				'id'         => 11,
				'title'      => 'Field Research Essay',
				'max_points' => 100.0,
			],
		];

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
						'token'       => '{{source.assignment_title}}',
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
					'id'    => 'el_srcgrade',
					'type'  => 'merge_field',
					'x'     => 100,
					'y'     => 160,
					'w'     => 400,
					'h'     => 30,
					'z'     => 2,
					'props' => [
						'token'       => '{{source.grade}}',
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
				'uuid'                  => 'tpl-ppa-test',
				'title'                 => 'Assignment Completion',
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

		$this->adapter = new PressPrimer_Certificate_PPA_Adapter();
		$this->adapter->register();
	}

	/**
	 * Seed an active trigger for assignment 11.
	 *
	 * @param array|null $conditions Conditions (null = none stored).
	 * @return int Trigger row id.
	 */
	private function seed_trigger( $conditions = null ) {
		return $this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'uuid'            => 'trg-ppa-test',
				'template_id'     => $this->template_id,
				'trigger_type'    => 'ppa_assignment',
				'source_ref'      => '11',
				'conditions_json' => null === $conditions ? null : wp_json_encode( $conditions ),
				'is_active'       => 1,
			]
		);
	}

	/**
	 * Seed a graded submission fixture and fire the graded hook.
	 *
	 * @param float $score  Final score in points.
	 * @param float $max    max_points_at_grading snapshot.
	 * @param bool  $passed Whether PPA marked it passed.
	 * @return void
	 */
	private function fire_graded( $score, $max = 100.0, $passed = true ) {
		static $next_id = 500;
		$next_id++;

		PressPrimer_Assignment_Submission::$fixtures[ $next_id ] = (object) [
			'id'                    => $next_id,
			'assignment_id'         => 11,
			'user_id'               => 7,
			'status'                => 'graded',
			'score'                 => $score,
			'max_points_at_grading' => $max,
			'passed'                => $passed ? 1 : 0,
			'graded_at'             => '2026-06-12 14:30:00',
		];

		do_action( 'pressprimer_assignment_submission_graded', $next_id, $score );
	}

	/**
	 * Registration goes through the public filters only, and an
	 * unavailable adapter contributes nothing (Edge US-5).
	 *
	 * @return void
	 */
	public function test_registration_and_availability_gating() {
		$types = PressPrimer_Certificate_Trigger_Registry::get_types();

		$this->assertArrayHasKey( 'ppa_assignment', $types );
		$this->assertSame( 'Assignment passed (PressPrimer Assignment)', $types['ppa_assignment']['label'] );
		$this->assertSame( 'Assignment', $types['ppa_assignment']['source_label'] );
		$this->assertSame( [], $types['ppa_assignment']['source_post_types'] );
		$this->assertArrayHasKey( 'min_grade', $types['ppa_assignment']['conditions_schema'] );
		$this->assertNotSame( '', $types['ppa_assignment']['conditions_schema']['min_grade']['help'] );

		$fields = PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );
		$this->assertArrayHasKey( 'source.assignment_title', $fields );
		$this->assertArrayHasKey( 'source.grade', $fields );
		$this->assertArrayHasKey( 'source.completion_date', $fields );
		$this->assertContains( 'ppa_assignment', $fields['source.grade']['trigger_types'] );

		// Deactivated PPA: a fresh registry sees nothing from the
		// unavailable variant.
		ppcert_tests_reset_hooks();
		( new PPCert_Test_Unavailable_PPA_Adapter() )->register();

		$this->assertArrayNotHasKey(
			'ppa_assignment',
			PressPrimer_Certificate_Trigger_Registry::get_types()
		);
	}

	/**
	 * A passing grade issues once with correct source data and merge
	 * values.
	 *
	 * @return void
	 */
	public function test_passing_grade_issues_certificate_with_merge_data() {
		$this->seed_trigger();

		$this->fire_graded( 86.5 );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'ppa_assignment', $rows[0]['source_type'] );
		$this->assertSame( '11', $rows[0]['source_ref'] );
		$this->assertSame( 7, (int) $rows[0]['recipient_id'] );

		$merge = json_decode( $rows[0]['merge_data_json'], true );
		$this->assertSame( 'Field Research Essay', $merge['source.assignment_title'] );
		$this->assertSame( '86.5%', $merge['source.grade'] );
	}

	/**
	 * The graded event fires for failures too: a failed submission never
	 * issues, no matter the conditions.
	 *
	 * @return void
	 */
	public function test_failed_submission_never_issues() {
		$this->seed_trigger();

		$this->fire_graded( 40.0, 100.0, false );

		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * min_grade boundary: below issues nothing, at threshold issues.
	 *
	 * @return void
	 */
	public function test_min_grade_boundary() {
		$this->seed_trigger( [ 'min_grade' => 80.0 ] );

		// 79.99%: passed the assignment (60) but under the trigger's bar.
		$this->fire_graded( 79.99 );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Exactly 80%: issues.
		$this->fire_graded( 80.0 );
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * min_grade is a percentage of max points, not raw points: 45/50
	 * clears a 85% bar.
	 *
	 * @return void
	 */
	public function test_min_grade_normalizes_points_to_percent() {
		$this->seed_trigger( [ 'min_grade' => 85.0 ] );

		$this->fire_graded( 45.0, 50.0 );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );

		$merge = json_decode( $rows[0]['merge_data_json'], true );
		$this->assertSame( '90%', $merge['source.grade'] );
	}

	/**
	 * Null min_grade follows the assignment's own passing score: any
	 * pass issues.
	 *
	 * @return void
	 */
	public function test_null_min_grade_follows_assignment_threshold() {
		$this->seed_trigger( [ 'min_grade' => null ] );

		$this->fire_graded( 60.0 );

		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * A resubmission regrade is suppressed by the engine (idempotency
	 * backstop).
	 *
	 * @return void
	 */
	public function test_regrade_is_suppressed() {
		$this->seed_trigger();

		$this->fire_graded( 86.5 );
		$this->fire_graded( 95.0 );

		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * Sources: published assignments, searchable by title.
	 *
	 * @return void
	 */
	public function test_sources_lists_published_assignments() {
		$this->wpdb->seed_row(
			'wp_ppa_assignments',
			[
				'title'  => 'Field Research Essay',
				'status' => 'published',
			]
		);
		$this->wpdb->seed_row(
			'wp_ppa_assignments',
			[
				'title'  => 'Draft Assignment',
				'status' => 'draft',
			]
		);
		$this->wpdb->seed_row(
			'wp_ppa_assignments',
			[
				'title'  => 'Lab Report 3',
				'status' => 'published',
			]
		);

		$all = $this->adapter->get_sources();
		$this->assertSame(
			[ 'Field Research Essay', 'Lab Report 3' ],
			array_column( $all, 'title' )
		);

		$searched = $this->adapter->get_sources( 'essay' );
		$this->assertCount( 1, $searched );
		$this->assertSame( 'Field Research Essay', $searched[0]['title'] );
	}

	/**
	 * Resolvers return display-ready scalars from the listener context.
	 *
	 * @return void
	 */
	public function test_merge_resolvers() {
		// Shared source-context contract: display strings precomputed.
		$context = [
			'ppa_assignment_title' => 'Field Research Essay',
			'src_grade_display'    => '92%',
			'src_completed_at'     => '2026-06-12 14:30:00',
		];

		$resolved = $this->adapter->resolve_merge_data( $context );

		$this->assertSame( 'Field Research Essay', $resolved['source.assignment_title'] );
		$this->assertSame( '92%', $resolved['source.grade'] );
		$this->assertNotSame( '', $resolved['source.completion_date'] );

		// Manual issuance without PPA context: source fields empty, never
		// the raw token (Feature 002 Edge US-5).
		$empty = $this->adapter->resolve_merge_data( [] );
		$this->assertSame( '', $empty['source.assignment_title'] );
		$this->assertSame( '', $empty['source.grade'] );
		$this->assertSame( '', $empty['source.completion_date'] );
	}

	/**
	 * Past completions (2.0, FR-005): earliest passing grade per user;
	 * passed = 1 includes rows later moved to 'returned'; graded_at is
	 * already UTC (PPA convention) so a site offset must NOT shift it.
	 *
	 * @return void
	 */
	public function test_past_completions_from_submissions() {
		$this->assertTrue( $this->adapter->supports_past_completions() );

		// A UTC+2 site: PPA timestamps are UTC and must pass through.
		$GLOBALS['ppcert_test_gmt_offset'] = 2 * 3600;

		$submissions = [
			[ 'user_id' => 7, 'assignment_id' => 11, 'status' => 'graded', 'passed' => 1, 'graded_at' => '2026-06-12 14:30:00' ],
			// Returned AFTER passing keeps the pass (statistics parity).
			[ 'user_id' => 8, 'assignment_id' => 11, 'status' => 'returned', 'passed' => 1, 'graded_at' => '2026-06-13 14:30:00' ],
			// Failed grade - excluded.
			[ 'user_id' => 9, 'assignment_id' => 11, 'status' => 'graded', 'passed' => 0, 'graded_at' => '2026-06-14 14:30:00' ],
			// Other assignment - filtered by ref.
			[ 'user_id' => 10, 'assignment_id' => 12, 'status' => 'graded', 'passed' => 1, 'graded_at' => '2026-06-15 14:30:00' ],
			// User 7's later resubmission pass - earliest wins.
			[ 'user_id' => 7, 'assignment_id' => 11, 'status' => 'graded', 'passed' => 1, 'graded_at' => '2026-07-02 14:30:00' ],
		];

		foreach ( $submissions as $submission ) {
			$this->wpdb->seed_row( 'wp_ppa_submissions', $submission );
		}

		$completions = $this->adapter->get_past_completions( '11' );

		unset( $GLOBALS['ppcert_test_gmt_offset'] );

		$this->assertSame(
			[
				[
					'user_id'      => 7,
					'completed_at' => '2026-06-12 14:30:00',
				],
				[
					'user_id'      => 8,
					'completed_at' => '2026-06-13 14:30:00',
				],
			],
			$completions,
			'UTC storage passes through unshifted; returned rows keep their pass.'
		);
	}
}
