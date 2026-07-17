<?php
/**
 * Plugin Name:       PressPrimer Certificate
 * Plugin URI:        https://pressprimer.com/certificate
 * Description:       Design, issue, and verify certificates on your own site. Drag-and-drop designer, credential IDs, QR verification, and LMS-agnostic issuance.
 * Version:           1.0.0-dev
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
define( 'PPCERT_VERSION', '1.0.0-dev' );
define( 'PPCERT_PLUGIN_FILE', __FILE__ );
define( 'PPCERT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PPCERT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PPCERT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'PPCERT_DB_VERSION', '1.0.0' );

/**
 * Whether the server meets the plugin's minimum requirements
 *
 * @since 1.0.0
 *
 * @return bool True when both the WordPress and PHP floors are met.
 */
function ppcert_requirements_met() {
	return version_compare( PHP_VERSION, '7.4', '>=' )
		&& version_compare( get_bloginfo( 'version' ), '6.4', '>=' );
}

/**
 * Render the unmet-requirements admin notice
 *
 * @since 1.0.0
 */
function ppcert_requirements_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html(
		sprintf(
			/* translators: 1: required WordPress version, 2: required PHP version */
			__( 'PressPrimer Certificate requires WordPress %1$s or higher and PHP %2$s or higher. The plugin is inactive until the server meets these requirements.', 'pressprimer-certificate' ),
			'6.4',
			'7.4'
		)
	);
	echo '</p></div>';
}

// Requirements guard: on an unmet floor the plugin becomes a notice-only
// stub - nothing else loads and nothing ever fatals (008-foundation FR-001).
// This file must stay parseable on old PHP: no PHP 7.4+-only syntax here.
if ( ! ppcert_requirements_met() ) {
	add_action( 'admin_notices', 'ppcert_requirements_notice' );
	return;
}

// Composer autoloader (for vendor dependencies such as TCPDF and the QR library)
if ( file_exists( PPCERT_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once PPCERT_PLUGIN_DIR . 'vendor/autoload.php';
}

// Autoloader
require_once PPCERT_PLUGIN_DIR . 'includes/class-ppcert-autoloader.php';
PressPrimer_Certificate_Autoloader::register();

// Activation/Deactivation hooks
register_activation_hook( __FILE__, [ 'PressPrimer_Certificate_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'PressPrimer_Certificate_Deactivator', 'deactivate' ] );

// Multisite: Hook for new site creation to set up tables
add_action( 'wp_initialize_site', [ 'PressPrimer_Certificate_Activator', 'activate_new_site' ], 10, 1 );

/**
 * Initialize plugin
 *
 * Hooked to 'init' to comply with WordPress 6.7+ translation loading
 * requirements. The plugin instance fires `ppcert_loaded` when it has
 * finished loading (008-foundation FR-001).
 *
 * @since 1.0.0
 */
function ppcert_init() {
	PressPrimer_Certificate_Plugin::instance()->init();
}
add_action( 'init', 'ppcert_init', 0 );

/**
 * Get the addon manager instance
 *
 * Returns null until the addon manager class ships (Prompt 1.8).
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
