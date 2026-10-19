<?php
/**
 * Adapter seam tests (2.1)
 *
 * Exercises Feature 2.1-004 FR-016's adapter seams for School's Award
 * Past Completions: paged list_sources() and get_source_title() (post
 * sources, a post adapter that exposes no source post types, and table
 * sources), the best passing score on quiz adapters' past completions,
 * and Trigger_Registry::conditions_met().
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Adapter seams test case
 *
 * @since 2.1.0
 */
class Test_Adapter_Seams extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Fresh hooks, database, and posts.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb                    = ppcert_tests_reset_wpdb();
		$GLOBALS['ppcert_test_posts'] = [];
	}

	/**
	 * Seed published posts of a type.
	 *
	 * @param string $type  Post type.
	 * @param int    $count How many.
	 * @param int    $first First id.
	 * @return void
	 */
	private function seed_posts( $type, $count, $first ) {
		for ( $i = 0; $i < $count; $i++ ) {
			$id = $first + $i;

			$GLOBALS['ppcert_test_posts'][ $id ] = (object) [
				'ID'          => $id,
				'post_type'   => $type,
				'post_status' => 'publish',
				'post_title'  => 'Source ' . $id,
			];
		}
	}

	/**
	 * Post sources page through EVERY published source in id order -
	 * past the picker's 50 - and name any of them.
	 *
	 * @return void
	 */
	public function test_post_sources_list_every_page() {
		$this->seed_posts( 'sfwd-courses', 120, 1000 );
		$GLOBALS['ppcert_test_posts'][2000] = (object) [
			'ID'          => 2000,
			'post_type'   => 'sfwd-courses',
			'post_status' => 'draft',
			'post_title'  => 'Draft course',
		];

		$adapter = new PressPrimer_Certificate_LearnDash_Adapter();

		$this->assertCount( 50, $adapter->get_sources( '' ), 'The picker stays capped' );

		$seen = [];

		for ( $page = 1; $page <= 3; $page++ ) {
			$seen = array_merge( $seen, array_column( $adapter->list_sources( $page, 50 ), 'id' ) );
		}

		$this->assertCount( 120, $seen );
		$this->assertSame( '1000', $seen[0] );
		$this->assertSame( '1119', $seen[119] );
		$this->assertSame( [], $adapter->list_sources( 4, 50 ) );

		$this->assertSame( 'Source 1110', $adapter->get_source_title( '1110' ), 'A course past the first 50' );
		$this->assertSame( '', $adapter->get_source_title( '999999' ) );
		$this->assertSame( '', $adapter->get_source_title( 'abc' ) );
	}

	/**
	 * LearnDash quizzes are posts without exposed source post types; the
	 * listing still reads them, and never another type's posts.
	 *
	 * @return void
	 */
	public function test_post_adapter_without_source_post_types() {
		$this->seed_posts( 'sfwd-quiz', 3, 300 );
		$this->seed_posts( 'sfwd-courses', 2, 400 );

		$quiz = new PressPrimer_Certificate_LearnDash_Quiz_Adapter();

		$this->assertSame( [ '300', '301', '302' ], array_column( $quiz->list_sources( 1, 100 ), 'id' ) );
		$this->assertSame( 'Source 301', $quiz->get_source_title( '301' ) );
		$this->assertSame( '', $quiz->get_source_title( '400' ), 'A course is not a quiz source' );
	}

	/**
	 * Table sources (PressPrimer Quiz and Assignment) page by id and
	 * name any row.
	 *
	 * @return void
	 */
	public function test_table_sources() {
		for ( $i = 1; $i <= 60; $i++ ) {
			$this->wpdb->seed_row(
				'wp_ppq_quizzes',
				[
					'title'  => 'Quiz ' . $i,
					'status' => 30 === $i ? 'draft' : 'published',
				]
			);
		}

		$adapter = new PressPrimer_Certificate_PPQ_Adapter();

		$first  = $adapter->list_sources( 1, 50 );
		$second = $adapter->list_sources( 2, 50 );

		$this->assertCount( 50, $first );
		$this->assertCount( 9, $second, 'The draft is skipped' );
		$this->assertSame( 'Quiz 60', end( $second )['title'] );
		$this->assertSame( 'Quiz 30', $adapter->get_source_title( '30' ), 'Names a historical source whatever its status' );
		$this->assertSame( '', $adapter->get_source_title( '9999' ) );

		$this->wpdb->seed_row(
			'wp_ppa_assignments',
			[
				'title'  => 'Field Journal',
				'status' => 'published',
			]
		);

		$assignments = new PressPrimer_Certificate_PPA_Adapter();
		$this->assertSame( [ 'Field Journal' ], array_column( $assignments->list_sources( 1, 10 ), 'title' ) );
		$this->assertSame( 'Field Journal', $assignments->get_source_title( '1' ) );
	}

	/**
	 * Quiz adapters report each user's best passing score; the date
	 * stays the earliest pass, and rows without a score carry none.
	 *
	 * @return void
	 */
	public function test_past_completion_scores() {
		// PressPrimer Quiz: score_percent.
		foreach ( [ [ 7, 72.5, '2026-03-01 10:00:00' ], [ 7, 91.0, '2026-04-01 10:00:00' ], [ 8, 80.0, '2026-03-02 10:00:00' ] ] as $attempt ) {
			$this->wpdb->seed_row(
				'wp_ppq_attempts',
				[
					'quiz_id'       => 5,
					'user_id'       => $attempt[0],
					'score_percent' => $attempt[1],
					'finished_at'   => $attempt[2],
					'status'        => 'submitted',
					'passed'        => 1,
					'is_practice'   => 0,
				]
			);
		}

		$ppq = ( new PressPrimer_Certificate_PPQ_Adapter() )->get_past_completions( '5' );
		$this->assertSame( 91.0, $ppq[0]['score'], 'Best passing score' );
		$this->assertSame( 80.0, $ppq[1]['score'] );
		$this->assertStringStartsWith( '2026-03-01', $ppq[0]['completed_at'], 'Earliest pass' );

		// LearnDash quiz: the 'percentage' activity meta.
		$activity = $this->wpdb->seed_row(
			'wp_learndash_user_activity',
			[
				'activity_id'        => 31,
				'user_id'            => 7,
				'post_id'            => 307,
				'activity_type'      => 'quiz',
				'activity_status'    => 1,
				'activity_completed' => 1781256800,
			]
		);
		$this->wpdb->seed_row(
			'wp_learndash_user_activity_meta',
			[
				'activity_id'         => 31,
				'activity_meta_key'   => 'percentage',
				'activity_meta_value' => '88.5',
			]
		);
		$this->wpdb->seed_row(
			'wp_learndash_user_activity',
			[
				'activity_id'        => 32,
				'user_id'            => 9,
				'post_id'            => 307,
				'activity_type'      => 'quiz',
				'activity_status'    => 1,
				'activity_completed' => 1781256900,
			]
		);
		unset( $activity );

		$ld = ( new PressPrimer_Certificate_LearnDash_Quiz_Adapter() )->get_past_completions( '307' );
		$this->assertSame( 88.5, $ld[0]['score'] );
		$this->assertArrayNotHasKey( 'score', $ld[1], 'No percentage meta: no score' );

		$course = ( new PressPrimer_Certificate_LearnDash_Adapter() )->get_past_completions( '307' );
		$this->assertSame( [], $course, 'Courses read course rows and carry no score' );

		// LifterLMS: grade.
		$this->wpdb->seed_row(
			'wp_lifterlms_quiz_attempts',
			[
				'student_id' => 7,
				'quiz_id'    => 11,
				'status'     => 'pass',
				'grade'      => 77.25,
				'end_date'   => '2026-05-01 09:00:00',
			]
		);
		$this->assertSame( 77.25, ( new PressPrimer_Certificate_LifterLMS_Quiz_Adapter() )->get_past_completions( '11' )[0]['score'] );

		// Tutor LMS: earned_marks / total_marks.
		$this->wpdb->seed_row(
			'wp_tutor_quiz_attempts',
			[
				'user_id'          => 7,
				'quiz_id'          => 12,
				'result'           => 'pass',
				'earned_marks'     => 17,
				'total_marks'      => 20,
				'attempt_ended_at' => '2026-05-02 09:00:00',
			]
		);
		$this->assertSame( 85.0, ( new PressPrimer_Certificate_TutorLMS_Quiz_Adapter() )->get_past_completions( '12' )[0]['score'] );

		// LearnPress: the result JSON of passing quiz items.
		$this->wpdb->seed_row(
			'wp_learnpress_user_items',
			[
				'user_item_id' => 1,
				'user_id'      => 7,
				'item_id'      => 13,
				'item_type'    => 'lp_quiz',
				'status'       => 'completed',
				'graduation'   => 'passed',
				'end_time'     => '2026-05-03 09:00:00',
			]
		);
		$this->wpdb->seed_row(
			'wp_learnpress_user_item_results',
			[
				'user_item_id' => 1,
				'result'       => wp_json_encode(
					[
						'result' => 93.333,
						'pass'   => 1,
					]
				),
			]
		);
		$this->assertSame( 93.33, ( new PressPrimer_Certificate_LearnPress_Quiz_Adapter() )->get_past_completions( '13' )[0]['score'] );

		// A past quiz award prints what a live one would.
		$this->assertSame(
			[
				'src_score_display' => '91%',
				'src_grade_display' => 'Passed',
			],
			( new PressPrimer_Certificate_PPQ_Adapter() )->completion_context( $ppq[0] )
		);
		$this->assertSame( [], ( new PressPrimer_Certificate_LearnDash_Adapter() )->completion_context( [ 'user_id' => 7 ] ), 'No score, nothing added' );
	}

	/**
	 * conditions_met(): minimums need a score at or above them; no
	 * minimum always passes; no score cannot meet a minimum.
	 *
	 * @return void
	 */
	public function test_conditions_met() {
		$quiz       = [ 'conditions' => [ 'min_score' => 90 ] ];
		$assignment = (object) [ 'conditions' => [ 'min_grade' => '75' ] ];

		$this->assertTrue( PressPrimer_Certificate_Trigger_Registry::conditions_met( $quiz, [ 'score' => 90 ] ) );
		$this->assertFalse( PressPrimer_Certificate_Trigger_Registry::conditions_met( $quiz, [ 'score' => 70 ] ), 'A 70% pass does not meet a 90% minimum' );
		$this->assertFalse( PressPrimer_Certificate_Trigger_Registry::conditions_met( $quiz, [ 'user_id' => 7 ] ), 'An unknown score cannot meet a minimum' );
		$this->assertTrue( PressPrimer_Certificate_Trigger_Registry::conditions_met( $assignment, [ 'score' => 75.5 ] ) );
		$this->assertTrue( PressPrimer_Certificate_Trigger_Registry::conditions_met( [ 'conditions' => [ 'min_score' => null ] ], [] ), 'Null follows the source pass mark' );
		$this->assertTrue( PressPrimer_Certificate_Trigger_Registry::conditions_met( [ 'conditions' => null ], [] ) );
		$this->assertTrue( PressPrimer_Certificate_Trigger_Registry::conditions_met( [ 'conditions' => [ 'course_id' => '12' ] ], [] ), 'Scope keys are not minimums' );
	}
}
