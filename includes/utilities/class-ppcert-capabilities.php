<?php
/**
 * Capabilities handler
 *
 * Manages custom capabilities for PressPrimer Certificate.
 *
 * @package PressPrimer_Certificate
 * @subpackage Utilities
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capabilities class
 *
 * Handles setup and removal of the plugin's custom capabilities per
 * Feature 003 TR-003: registered on activation, granted to administrator.
 * Addons extend this set; core never checks addon capabilities. The plugin
 * registers no custom roles in 1.0.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Capabilities {

	/**
	 * Capability: manage certificate templates (designer, gallery)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CAP_MANAGE_TEMPLATES = 'ppcert_manage_templates';

	/**
	 * Capability: issue certificates (manual issuance, trigger management)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CAP_ISSUE_CERTIFICATES = 'ppcert_issue_certificates';

	/**
	 * Capability: view issued certificates (admin list, detail)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CAP_VIEW_CERTIFICATES = 'ppcert_view_certificates';

	/**
	 * Capability: manage plugin settings
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CAP_MANAGE_SETTINGS = 'ppcert_manage_settings';

	/**
	 * Setup capabilities
	 *
	 * Adds all plugin capabilities to the administrator role.
	 * Called during plugin activation (and self-healed from the main
	 * plugin class when the activation hook did not run).
	 *
	 * @since 1.0.0
	 */
	public static function setup_capabilities() {
		$admin = get_role( 'administrator' );

		if ( ! $admin ) {
			return;
		}

		foreach ( self::get_all_capabilities() as $capability ) {
			$admin->add_cap( $capability );
		}
	}

	/**
	 * Remove capabilities
	 *
	 * Removes all plugin capabilities from all roles. Called during
	 * uninstall (uninstall.php also removes by prefix as a fallback,
	 * since plugin classes may not be loadable there).
	 *
	 * @since 1.0.0
	 */
	public static function remove_capabilities() {
		$roles = wp_roles();

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::get_all_capabilities() as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}

	/**
	 * Get all plugin capabilities
	 *
	 * @since 1.0.0
	 *
	 * @return string[] List of capability keys.
	 */
	public static function get_all_capabilities() {
		return [
			self::CAP_MANAGE_TEMPLATES,
			self::CAP_ISSUE_CERTIFICATES,
			self::CAP_VIEW_CERTIFICATES,
			self::CAP_MANAGE_SETTINGS,
		];
	}
}
