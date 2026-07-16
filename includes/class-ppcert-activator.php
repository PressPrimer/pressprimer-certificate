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
 * Contains all functionality for plugin activation.
 * Sets up database tables, default options, and capabilities.
 * Supports both single site and multisite network activation.
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
			self::activate_for_network();
			return;
		}

		// Single site activation
		self::activate_single_site();
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
		// Check WordPress version
		if ( version_compare( get_bloginfo( 'version' ), '6.4', '<' ) ) {
			wp_die(
				'PressPrimer Certificate requires WordPress 6.4 or higher.',
				'Plugin Activation Error',
				[ 'back_link' => true ]
			);
		}

		// Check PHP version
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			wp_die(
				'PressPrimer Certificate requires PHP 7.4 or higher.',
				'Plugin Activation Error',
				[ 'back_link' => true ]
			);
		}

		// Set default options
		self::set_default_options();

		// Ensure database tables exist - always check on activation
		// This handles both fresh installs and reinstalls after data removal
		self::ensure_database_tables();

		// Run database migrations for version upgrades
		if ( class_exists( 'PressPrimer_Certificate_Migrator' ) ) {
			PressPrimer_Certificate_Migrator::maybe_migrate();
		}

		// Setup capabilities
		if ( class_exists( 'PressPrimer_Certificate_Capabilities' ) ) {
			PressPrimer_Certificate_Capabilities::setup_capabilities();
		}

		// The public verification page is created here when Feature 6
		// (Verification + QR) ships, and its ID stored in ppcert_settings.

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
			if ( class_exists( 'PressPrimer_Certificate_Schema' ) ) {
				$sql = PressPrimer_Certificate_Schema::get_schema();
				dbDelta( $sql );

				// Update database version
				update_option( 'ppcert_db_version', PPCERT_DB_VERSION );
			}
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
			'verification_page_id'  => 0, // Assigned when the verification page is created (Feature 6).
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
		// ALWAYS reset it to false on activation - a critical safety measure
		// to prevent accidental data loss (mirrors the Quiz precedent).
		update_option( 'ppcert_remove_data_on_uninstall', false );
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
