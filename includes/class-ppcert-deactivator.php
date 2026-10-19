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
 * Deactivation is a rewrite flush (without the plugin's own rules, 2.1)
 * and the cron unschedule only - never data (008-foundation FR-001).
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

		// The plugin's rules are still registered during this request,
		// so a plain flush would save them again and /certificate/...
		// would keep resolving after deactivation (2.1, Feature 2.1-007
		// FR-009): drop them from the flushed set.
		add_filter( 'rewrite_rules_array', [ __CLASS__, 'drop_rewrite_rules' ] );
		flush_rewrite_rules();
		remove_filter( 'rewrite_rules_array', [ __CLASS__, 'drop_rewrite_rules' ] );
	}

	/**
	 * Remove the plugin's rewrite rules from a rule set
	 *
	 * @since 2.1.0
	 *
	 * @param array $rules Pattern => query.
	 * @return array
	 */
	public static function drop_rewrite_rules( $rules ) {
		if ( ! is_array( $rules ) ) {
			return $rules;
		}

		foreach ( $rules as $pattern => $query ) {
			if ( false !== strpos( (string) $query, PressPrimer_Certificate_View_Page::QUERY_VAR . '=' ) ) {
				unset( $rules[ $pattern ] );
			}
		}

		return $rules;
	}
}
