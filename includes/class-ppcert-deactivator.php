<?php
/**
 * Plugin deactivation handler
 *
 * Handles tasks that run when the plugin is deactivated.
 *
 * @package PressPrimer_Certificate
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivator class
 *
 * Deactivation is rewrite flush only - never data (008-foundation FR-001).
 * Tables, options, and transients are untouched; permanent removal happens
 * only through uninstall.php's explicit opt-in. The ppcert_prune_events
 * cron is unscheduled here once it exists (Prompt 5.2, per 008 TR-003).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Deactivator {

	/**
	 * Deactivate the plugin
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		PressPrimer_Certificate_Event_Pruner::unschedule();

		flush_rewrite_rules();
	}
}
