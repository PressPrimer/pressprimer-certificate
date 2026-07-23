<?php
/**
 * Events retention pruner
 *
 * The ppcert_prune_events daily cron (Feature 008 FR-005/TR-003,
 * DATABASE.md growth control): verified/viewed event rows older than
 * the retention window delete in shared-hosting-friendly batches;
 * issued/downloaded rows are lifecycle records and are never pruned.
 * Follows the Quiz/Assignment scheduled-cleanup pattern (activation
 * schedules, deactivation unschedules).
 *
 * @package PressPrimer_Certificate
 * @subpackage Services
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event pruner class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Event_Pruner {

	/**
	 * Cron hook name
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CRON_HOOK = 'ppcert_prune_events';

	/**
	 * Event types eligible for pruning (DATABASE.md)
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const PRUNABLE_TYPES = [ 'verified', 'viewed' ];

	/**
	 * Rows deleted per batch
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const BATCH_SIZE = 500;

	/**
	 * Safety cap on batches per run
	 *
	 * A run deletes at most BATCH_SIZE * MAX_BATCHES rows; anything
	 * beyond that waits for the next daily run.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_BATCHES = 20;

	/**
	 * Register the cron callback
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( self::CRON_HOOK, [ __CLASS__, 'prune' ] );
	}

	/**
	 * Schedule the daily run (activation)
	 *
	 * @since 1.0.0
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the schedule (deactivation)
	 *
	 * @since 1.0.0
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Delete prunable events older than the retention window
	 *
	 * @since 1.0.0
	 *
	 * @return int Rows deleted.
	 */
	public static function prune() {
		global $wpdb;

		$settings = get_option( 'ppcert_settings', [] );
		$days     = isset( $settings['events_retention_days'] ) ? absint( $settings['events_retention_days'] ) : 90;
		$days     = min( 3650, max( 7, $days ) );

		// UTC everywhere (CLAUDE.md Datetime Standard).
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$deleted = 0;

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM %i WHERE event_type IN ( 'verified', 'viewed' ) AND created_at < %s LIMIT %d",
					PressPrimer_Certificate_Certificate::events_table(),
					$cutoff,
					self::BATCH_SIZE
				)
			);

			$deleted += (int) $affected;

			if ( (int) $affected < self::BATCH_SIZE ) {
				break;
			}
		}

		return $deleted;
	}
}
