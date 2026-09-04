<?php
/**
 * Uninstall handler
 *
 * Handles complete removal of plugin data when uninstalled.
 * This file is called by WordPress when the user deletes the plugin.
 *
 * IMPORTANT: By default, this script preserves ALL data to prevent accidental
 * data loss. Data is only removed for a site when that site has explicitly
 * enabled "Remove all data on uninstall" in the plugin settings. On multisite,
 * each site is evaluated independently (per-site opt-in). Removable data:
 * - Database tables
 * - Options
 * - User meta
 * - Post meta
 * - Capabilities
 * - Transients
 *
 * @package PressPrimer_Certificate
 * @since 1.0.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Define plugin path constant if not already defined.
if ( ! defined( 'PPCERT_PLUGIN_DIR' ) ) {
	define( 'PPCERT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Remove plugin data.
 *
 * On multisite, each site is evaluated independently: a site's data is removed
 * only if that site has explicitly opted in. Network-global data (user meta and
 * network options) is shared across all sites and cannot be attributed to one
 * site, so it is removed once if at least one site opted in. By default all data
 * is preserved to prevent accidental loss.
 *
 * @since 1.0.0
 */
function ppcert_uninstall() {
	if ( is_multisite() ) {
		$site_ids    = get_sites( array( 'fields' => 'ids' ) );
		$any_removed = false;

		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );

			if ( ppcert_site_wants_removal() ) {
				ppcert_remove_site_data();
				$any_removed = true;
			}

			restore_current_blog();
		}

		// User meta and network options are network-global, so remove them once
		// if any site opted in.
		if ( $any_removed ) {
			ppcert_remove_user_meta();
			ppcert_remove_network_options();
		}
	} elseif ( ppcert_site_wants_removal() ) {
		ppcert_remove_site_data();
		ppcert_remove_user_meta();
	}
}

/**
 * Whether the current site has explicitly opted in to full data removal.
 *
 * Must be evaluated in the target site's context (e.g. after switch_to_blog()).
 * Defaults to false so data is preserved unless explicitly enabled. The opt-in
 * is the standalone ppcert_remove_data_on_uninstall option (DATABASE.md).
 *
 * @since 1.0.0
 *
 * @return bool True if the current site opted in, false otherwise.
 */
function ppcert_site_wants_removal() {
	$value = get_option( 'ppcert_remove_data_on_uninstall', false );

	return ( true === $value || '1' === $value || 1 === $value );
}

/**
 * Remove all of the current site's plugin data.
 *
 * Operates on the current blog only; the caller is responsible for the site
 * context (switch_to_blog() on multisite).
 *
 * @since 1.0.0
 */
function ppcert_remove_site_data() {
	ppcert_drop_tables();
	ppcert_remove_options();
	ppcert_remove_post_meta();
	ppcert_remove_capabilities();
	ppcert_clear_transients();
	ppcert_remove_uploads_data();
}

/**
 * Remove the current site's plugin data from the uploads directory.
 *
 * Three plugin-managed locations: ppcert-fonts (TTFs inflated from the
 * bundled .z files), ppcert/previews (view-page PNGs, which render
 * recipient names - personal data), and ppcert-previews (short-lived
 * designer preview PDFs). Everything here is either regenerable or
 * derived, and the readme promises a clean uninstall.
 *
 * @since 1.0.0
 */
function ppcert_remove_uploads_data() {
	$uploads = wp_upload_dir();

	if ( ! empty( $uploads['error'] ) ) {
		return;
	}

	$base = trailingslashit( $uploads['basedir'] );

	foreach ( array( 'ppcert-fonts', 'ppcert/previews', 'ppcert-previews' ) as $subdir ) {
		$dir = $base . $subdir;

		if ( ! is_dir( $dir ) ) {
			continue;
		}

		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			wp_delete_file( $file );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the emptied plugin cache directory.
		rmdir( $dir );
	}

	// The now-empty ppcert parent (previews' container), if removable.
	if ( is_dir( $base . 'ppcert' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir,WordPress.PHP.NoSilencedErrors.Discouraged -- Removing the emptied plugin cache directory; @ because foreign files inside fail it harmlessly.
		@rmdir( $base . 'ppcert' );
	}
}

/**
 * Drop the current site's plugin database tables.
 *
 * Keep this list in sync with includes/database/class-ppcert-schema.php
 * and docs/architecture/DATABASE.md.
 *
 * @since 1.0.0
 */
function ppcert_drop_tables() {
	global $wpdb;

	$table_names = array(
		'ppcert_templates',
		'ppcert_certificates',
		'ppcert_triggers',
		'ppcert_issuers',
		'ppcert_issuer_members',
		'ppcert_credit_types',
		'ppcert_credits',
		'ppcert_events',
		'ppcert_email_templates',
	);

	foreach ( $table_names as $table_name ) {
		// %i identifier placeholder (WP 6.2+; the plugin floor is 6.4).
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table_name ) );
	}
}

/**
 * Remove the current site's plugin options.
 *
 * @since 1.0.0
 */
function ppcert_remove_options() {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( 'ppcert_' ) . '%'
		)
	);
}

/**
 * Remove the current site's plugin post meta.
 *
 * @since 1.0.0
 */
function ppcert_remove_post_meta() {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s',
			$wpdb->postmeta,
			$wpdb->esc_like( 'ppcert_' ) . '%'
		)
	);
}

/**
 * Remove plugin capabilities from the current site's roles.
 *
 * Removes every capability with the ppcert_ prefix from every role, so this
 * stays in sync with the capabilities utility automatically. The plugin
 * registers no custom roles in 1.0.
 *
 * @since 1.0.0
 */
function ppcert_remove_capabilities() {
	$roles = wp_roles();

	foreach ( $roles->roles as $role_name => $role_info ) {
		$role = get_role( $role_name );

		if ( ! $role || empty( $role_info['capabilities'] ) ) {
			continue;
		}

		foreach ( array_keys( $role_info['capabilities'] ) as $capability ) {
			if ( 0 === strpos( $capability, 'ppcert_' ) ) {
				$role->remove_cap( $capability );
			}
		}
	}
}

/**
 * Clear the current site's plugin transients.
 *
 * @since 1.0.0
 */
function ppcert_clear_transients() {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_ppcert_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_ppcert_' ) . '%'
		)
	);
}

/**
 * Remove network-global plugin user meta.
 *
 * User meta is shared across all sites in a network, so this runs once.
 *
 * @since 1.0.0
 */
function ppcert_remove_user_meta() {
	global $wpdb;

	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s',
			$wpdb->usermeta,
			$wpdb->esc_like( 'ppcert_' ) . '%'
		)
	);
}

/**
 * Remove network-wide plugin options and site transients (multisite).
 *
 * @since 1.0.0
 */
function ppcert_remove_network_options() {
	global $wpdb;

	// Network-wide site options.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s',
			$wpdb->sitemeta,
			$wpdb->esc_like( 'ppcert_' ) . '%'
		)
	);

	// Network site transients.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE meta_key LIKE %s OR meta_key LIKE %s',
			$wpdb->sitemeta,
			$wpdb->esc_like( '_site_transient_ppcert_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_ppcert_' ) . '%'
		)
	);
}

// Run the uninstall.
ppcert_uninstall();
