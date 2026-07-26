<?php
/**
 * Plugin activation handler
 *
 * Handles tasks that run when the plugin is activated.
 *
 * @package PressPrimer_Certificate
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activator class
 *
 * Contains all functionality for plugin activation: database tables,
 * default options, and capabilities (008-foundation FR-001). Supports both
 * single site and multisite network activation.
 *
 * The WP/PHP requirements guard lives in the bootstrap
 * (pressprimer-certificate.php): on an unmet floor the plugin is a
 * notice-only stub and this class never loads, so activation cannot fatal.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Activator {

	/**
	 * Activate the plugin
	 *
	 * Runs when the plugin is activated.
	 * Sets up database tables, default options, and user capabilities.
	 * Handles both single site and network-wide activation.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 */
	public static function activate( $network_wide = false ) {
		// Handle network-wide activation in multisite
		if ( is_multisite() && $network_wide ) {
			// Never set the setup-redirect flag for network-wide
			// activation (wp.org compliance: no redirect in that case).
			self::activate_for_network();
			return;
		}

		// Fresh-install detection must happen before activation writes
		// the version option below.
		$is_fresh_install = false === get_option( 'ppcert_version' );

		// Single site activation
		self::activate_single_site();

		self::maybe_set_setup_redirect_flag( $is_fresh_install );
	}

	/**
	 * Set the one-time dashboard/setup-tour redirect flag
	 *
	 * Records the activating user in a short-lived transient; the
	 * guarded admin_init handler in PressPrimer_Certificate_Admin
	 * consumes it on the next page load - once, ever. Set only for a
	 * fresh install activated by a real logged-in user in a normal web
	 * request; updates, reinstalls, WP-CLI, cron, and AJAX activations
	 * never trigger a redirect. Mirrors the PressPrimer Assignment 2.2
	 * activation flow.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $is_fresh_install Whether the version option was
	 *                               absent before this activation ran.
	 */
	private static function maybe_set_setup_redirect_flag( $is_fresh_install ) {
		if ( ! $is_fresh_install ) {
			return;
		}

		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		set_transient( 'ppcert_setup_redirect', $user_id, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Activate for entire network
	 *
	 * Runs activation on all sites in a multisite network.
	 *
	 * @since 1.0.0
	 */
	private static function activate_for_network() {
		// Get all site IDs
		$site_ids = get_sites( [ 'fields' => 'ids' ] );

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			self::activate_single_site();
			restore_current_blog();
		}
	}

	/**
	 * Activate for a single site
	 *
	 * Performs all activation tasks for one site.
	 *
	 * @since 1.0.0
	 */
	private static function activate_single_site() {
		// Set default options
		self::set_default_options();

		// Ensure database tables exist - always check on activation
		// This handles both fresh installs and reinstalls after data removal
		self::ensure_database_tables();

		// Run the migration chain. The migrator owns ppcert_db_version and
		// only advances it after verifying each step's tables are present
		// (008-foundation FR-002).
		PressPrimer_Certificate_Migrator::maybe_migrate();

		// Setup capabilities (administrator; Feature 003 TR-003)
		PressPrimer_Certificate_Capabilities::setup_capabilities();

		// Create the public verification page (idempotent - an existing
		// page is detected via the stored ID).
		PressPrimer_Certificate_Verification_Page::create_page();

		// Starter templates are file-based (templates/starter-*.json read
		// by Template::get_starters()): the gallery lists them and
		// creation clones the layout into a user row, so plugin updates
		// refresh starters automatically and no DB seeding is needed.
		// The is_starter column stays dormant for premium template packs
		// (resolved at Prompt 5.1; supersedes the 1.2 seeding TODO).

		// Schedule the daily events-retention cleanup (008 TR-003).
		PressPrimer_Certificate_Event_Pruner::schedule();

		// Register the view-page rewrite before flushing so
		// /certificate/{credential_id}/ resolves from first activation.
		PressPrimer_Certificate_View_Page::register_rewrites();

		// Flush rewrite rules
		flush_rewrite_rules();

		// Set activation flag
		update_option( 'ppcert_activation_time', time() );
		update_option( 'ppcert_version', PPCERT_VERSION );
	}

	/**
	 * Ensure database tables exist
	 *
	 * Checks if tables are missing and creates them if needed.
	 * This runs on every activation to handle reinstalls after data removal.
	 * The stored DB version is deliberately NOT written here - only the
	 * migrator advances it, after verification.
	 *
	 * @since 1.0.0
	 */
	private static function ensure_database_tables() {
		global $wpdb;

		// Check if at least one critical table exists
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->prefix . 'ppcert_templates'
			)
		);

		// If tables don't exist, force create them
		if ( ! $table_exists ) {
			// Load WordPress upgrade functions
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			// Load and run schema
			dbDelta( PressPrimer_Certificate_Schema::get_schema() );
		}
	}

	/**
	 * Set default options
	 *
	 * Creates default plugin settings if they don't exist.
	 *
	 * @since 1.0.0
	 */
	private static function set_default_options() {
		// Default settings (see docs/architecture/DATABASE.md, Options)
		$default_settings = [
			'verification_page_id'  => 0, // Assigned when the verification page is created (Prompt 2.7).
			'email_from_name'       => get_bloginfo( 'name' ),
			'email_from_address'    => get_bloginfo( 'admin_email' ),
			'events_retention_days' => 90, // Anonymous event pruning window per DATABASE.md.
		];

		$existing_settings = get_option( 'ppcert_settings' );

		if ( false === $existing_settings ) {
			// Fresh install - set all defaults
			add_option( 'ppcert_settings', $default_settings );
		}

		// Opt-in uninstall data removal is a standalone option (DATABASE.md).
		// ALWAYS reset it off on activation - a critical safety measure to
		// prevent accidental data loss (mirrors the Quiz precedent). Stored
		// as 0/1 rather than a boolean: update_option( $option, false ) on a
		// missing option early-returns without storing anything.
		update_option( 'ppcert_remove_data_on_uninstall', 0 );
	}

	/**
	 * Activate for a new site in multisite
	 *
	 * Called when a new site is created in a multisite network
	 * and the plugin is network-activated.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Site $new_site New site object.
	 */
	public static function activate_new_site( $new_site ) {
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Only run if plugin is network-activated
		if ( ! is_plugin_active_for_network( PPCERT_PLUGIN_BASENAME ) ) {
			return;
		}

		switch_to_blog( $new_site->blog_id );
		self::activate_single_site();
		restore_current_blog();
	}
}
