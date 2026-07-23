<?php
/**
 * Event pruner tests (Feature 008 FR-005/TR-003, Prompt 5.2)
 *
 * Retention honoring, prunable-type selection (verified/viewed only),
 * batch looping, and schedule/unschedule.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Event pruner test case
 *
 * @since 1.0.0
 */
class Test_Event_Pruner extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options'] = [];
		$GLOBALS['ppcert_test_cron']    = [];
	}

	/**
	 * Seed an event row.
	 *
	 * @param string $type     Event type.
	 * @param string $created  Created datetime (UTC).
	 * @return int Row id.
	 */
	private function seed_event( $type, $created ) {
		return $this->wpdb->seed_row(
			'wp_ppcert_events',
			[
				'certificate_id' => 1,
				'event_type'     => $type,
				'actor_id'       => null,
				'created_at'     => $created,
			]
		);
	}

	/**
	 * Prune removes old verified/viewed rows, keeps recent ones and all
	 * lifecycle types regardless of age.
	 *
	 * @return void
	 */
	public function test_prune_honors_retention_and_types() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [ 'events_retention_days' => 30 ];

		$old    = gmdate( 'Y-m-d H:i:s', time() - ( 60 * DAY_IN_SECONDS ) );
		$recent = gmdate( 'Y-m-d H:i:s', time() - ( 5 * DAY_IN_SECONDS ) );

		$this->seed_event( 'verified', $old );
		$this->seed_event( 'viewed', $old );
		$this->seed_event( 'verified', $recent );
		$this->seed_event( 'issued', $old );
		$this->seed_event( 'downloaded', $old );

		$deleted = PressPrimer_Certificate_Event_Pruner::prune();

		$this->assertSame( 2, $deleted );

		$remaining = array_map(
			static function ( $row ) {
				return $row['event_type'];
			},
			$this->wpdb->rows( 'wp_ppcert_events' )
		);

		sort( $remaining );
		$this->assertSame( [ 'downloaded', 'issued', 'verified' ], $remaining );
	}

	/**
	 * Deletion loops in batches until a short batch signals completion.
	 *
	 * @return void
	 */
	public function test_prune_batches_past_the_batch_size() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [ 'events_retention_days' => 7 ];

		$old   = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$total = PressPrimer_Certificate_Event_Pruner::BATCH_SIZE + 25;

		for ( $i = 0; $i < $total; $i++ ) {
			$this->seed_event( 'viewed', $old );
		}

		$deleted = PressPrimer_Certificate_Event_Pruner::prune();

		$this->assertSame( $total, $deleted );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_events' ) );
	}

	/**
	 * A missing setting falls back to the 90-day default.
	 *
	 * @return void
	 */
	public function test_default_retention_is_90_days() {
		$this->seed_event( 'verified', gmdate( 'Y-m-d H:i:s', time() - ( 80 * DAY_IN_SECONDS ) ) );
		$this->seed_event( 'verified', gmdate( 'Y-m-d H:i:s', time() - ( 100 * DAY_IN_SECONDS ) ) );

		$this->assertSame( 1, PressPrimer_Certificate_Event_Pruner::prune() );
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_events' ) );
	}

	/**
	 * Scheduling is idempotent and unscheduling clears the hook.
	 *
	 * @return void
	 */
	public function test_schedule_and_unschedule() {
		PressPrimer_Certificate_Event_Pruner::schedule();
		$first = $GLOBALS['ppcert_test_cron']['ppcert_prune_events'];

		$this->assertSame( 'daily', $GLOBALS['ppcert_test_cron_recurrence']['ppcert_prune_events'] );

		// Re-scheduling never doubles up.
		PressPrimer_Certificate_Event_Pruner::schedule();
		$this->assertSame( $first, $GLOBALS['ppcert_test_cron']['ppcert_prune_events'] );

		PressPrimer_Certificate_Event_Pruner::unschedule();
		$this->assertArrayNotHasKey( 'ppcert_prune_events', $GLOBALS['ppcert_test_cron'] );
	}
}
