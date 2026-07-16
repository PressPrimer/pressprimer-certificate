<?php
/**
 * Plugin Name:       PressPrimer Certificate
 * Plugin URI:        https://pressprimer.com/certificate
 * Description:       Design, issue, and verify certificates on your own site. Drag-and-drop designer, credential IDs, QR verification, and LMS-agnostic issuance.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            PressPrimer
 * Author URI:        https://pressprimer.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pressprimer-certificate
 * Domain Path:       /languages
 *
 * Source Code:        https://github.com/PressPrimer/pressprimer-certificate
 *
 * @package PressPrimer_Certificate
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants
define( 'PPCERT_VERSION', '1.0.0' );
define( 'PPCERT_PLUGIN_FILE', __FILE__ );
define( 'PPCERT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'PPCERT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PPCERT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'PPCERT_DB_VERSION', '1.0.0' );

// Composer autoloader (for vendor dependencies such as TCPDF and the QR library)
if ( file_exists( PPCERT_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
	require_once PPCERT_PLUGIN_PATH . 'vendor/autoload.php';
}

// Autoloader
require_once PPCERT_PLUGIN_PATH . 'includes/class-ppcert-autoloader.php';
PressPrimer_Certificate_Autoloader::register();

// Activation/Deactivation hooks
register_activation_hook( __FILE__, [ 'PressPrimer_Certificate_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'PressPrimer_Certificate_Deactivator', 'deactivate' ] );

// Multisite: Hook for new site creation to set up tables
add_action( 'wp_initialize_site', [ 'PressPrimer_Certificate_Activator', 'activate_new_site' ], 10, 1 );

/**
 * Initialize plugin
 *
 * Hooked to 'init' to comply with WordPress 6.7+ translation loading requirements.
 *
 * @since 1.0.0
 */
function ppcert_init() {
	$plugin = PressPrimer_Certificate_Plugin::get_instance();
	$plugin->run();

	/**
	 * Fires when the free plugin is fully loaded.
	 *
	 * Premium addons hook in here to initialize themselves and register
	 * via the `ppcert_register_addon` action. See docs/architecture/HOOKS.md.
	 *
	 * @since 1.0.0
	 */
	do_action( 'ppcert_loaded' );
}
add_action( 'init', 'ppcert_init', 0 );

/**
 * Get the addon manager instance
 *
 * Returns null until the addon manager class ships (Foundation feature).
 *
 * @since 1.0.0
 *
 * @return PressPrimer_Certificate_Addon_Manager|null The addon manager instance, or null if unavailable.
 */
function ppcert_addon_manager() {
	if ( ! class_exists( 'PressPrimer_Certificate_Addon_Manager' ) ) {
		return null;
	}

	return PressPrimer_Certificate_Addon_Manager::get_instance();
}

/**
 * Check whether a premium addon is registered and active
 *
 * @since 1.0.0
 *
 * @param string $addon_id Addon identifier ('educator', 'school', 'enterprise').
 * @return bool True if the addon is registered, false otherwise.
 */
function ppcert_has_addon( $addon_id ) {
	$manager = ppcert_addon_manager();

	if ( ! $manager ) {
		return false;
	}

	return $manager->has_addon( $addon_id );
}

/**
 * Check whether a premium feature is enabled by any registered addon
 *
 * @since 1.0.0
 *
 * @param string $feature Feature slug as registered via `ppcert_register_addon`.
 * @return bool True if a registered addon provides the feature, false otherwise.
 */
function ppcert_feature_enabled( $feature ) {
	$manager = ppcert_addon_manager();

	if ( ! $manager ) {
		return false;
	}

	return $manager->feature_enabled( $feature );
}
