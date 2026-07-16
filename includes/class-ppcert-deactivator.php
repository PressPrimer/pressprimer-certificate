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
 * Contains all functionality for plugin deactivation.
 * Performs cleanup tasks that should run when plugin is deactivated.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Deactivator {

	/**
	 * Deactivate the plugin
	 *
	 * Runs when the plugin is deactivated.
	 * Cleans up temporary data and flushes rewrite rules.
	 *
	 * Note: This does NOT delete database tables or permanent data.
	 * That only happens on uninstall. See uninstall.php.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		// Scheduled cron jobs (events retention cleanup) are unscheduled
		// here once they exist.

		// Clear transients
		self::clear_plugin_transients();

		// Flush rewrite rules
		flush_rewrite_rules();

		// Set deactivation flag
		update_option( 'ppcert_deactivation_time', time() );
	}

	/**
	 * Clear plugin transients
	 *
	 * Removes all transients created by the plugin.
	 *
	 * @since 1.0.0
	 */
	private static function clear_plugin_transients() {
		global $wpdb;

		// Delete all transients that start with ppcert_
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s
				OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_ppcert_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_ppcert_' ) . '%'
			)
		);

		// If using site transients (multisite)
		if ( is_multisite() ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta}
					WHERE meta_key LIKE %s
					OR meta_key LIKE %s",
					$wpdb->esc_like( '_site_transient_ppcert_' ) . '%',
					$wpdb->esc_like( '_site_transient_timeout_ppcert_' ) . '%'
				)
			);
		}
	}
}
