<?php
/**
 * Plugin Name:       PressPrimer Certificate
 * Plugin URI:        https://pressprimer.com/certificate
 * Description:       Design, issue, and verify certificates on your own site. Build them in a drag-and-drop designer, award them automatically through integrations with popular LMS plugins, and let anyone scan a QR code to confirm a certificate is real.
 * Version:           1.1.0
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
define( 'PPCERT_VERSION', '1.1.0' );
define( 'PPCERT_PLUGIN_FILE', __FILE__ );
define( 'PPCERT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PPCERT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PPCERT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'PPCERT_DB_VERSION', '2.0.0' );

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

// TCPDF font lookup roots in the plugin's own converted-fonts directory.
// The release ZIP strips TCPDF's 24 MB bundled font collection (unused -
// every SetFont call passes an explicit file); the core-font metrics
// TCPDF needs internally ship in fonts/tcpdf/ instead. Must be defined
// before tcpdf_autoconfig.php runs (first TCPDF class load).
if ( ! defined( 'K_PATH_FONTS' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- TCPDF's own configuration constant; it must carry this exact name and be defined before tcpdf_autoconfig.php loads.
	define( 'K_PATH_FONTS', PPCERT_PLUGIN_DIR . 'fonts/tcpdf/' );
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
 * Build the public verification URL for a credential ID
 *
 * The canonical builder (Feature 006 FR-002): query-arg form against the
 * configured verification page, home-URL fallback when none is set. The
 * QR on every rendered certificate encodes this URL, so upgrading to
 * pretty permalinks later changes only this function (and only affects
 * newly rendered PDFs).
 *
 * @since 1.0.0
 *
 * @param string $credential_id Credential ID (any accepted form).
 * @return string Verification URL.
 */
function ppcert_verification_url( $credential_id ) {
	$normalized = PressPrimer_Certificate_Credential_ID_Service::normalize( $credential_id );

	return add_query_arg( 'ppcert_id', rawurlencode( $normalized ), ppcert_verification_page_url() );
}

/**
 * Get the verification page's base URL (no credential)
 *
 * The test email's link target (2.0, Feature 2.0-003 FR-004): no
 * credential exists for a test, so credential-dependent links point at
 * the page itself.
 *
 * @since 2.0.0
 *
 * @return string
 */
function ppcert_verification_page_url() {
	$settings = get_option( 'ppcert_settings', [] );
	$page_id  = is_array( $settings ) && isset( $settings['verification_page_id'] ) ? absint( $settings['verification_page_id'] ) : 0;

	$base = $page_id > 0 ? get_permalink( $page_id ) : '';

	if ( ! $base ) {
		$base = home_url( '/' );
	}

	return $base;
}

/*
 * --------------------------------------------------------------------
 * The public integration API (2.0, Feature 2.0-007 FR-001).
 *
 * These functions - plus the registration filters documented in the
 * public hooks reference - are the SUPPORTED surface for third-party
 * plugins. Class internals may change at any time; these signatures
 * follow the deprecation policy (a deprecation notice one version
 * before any removal).
 * --------------------------------------------------------------------
 */

/**
 * Issue a certificate
 *
 * Wraps the issuance service - the plugin's ONE writer of certificate
 * rows. Duplicate suppression, validation filters, snapshotting, the
 * issued event, and email dispatch all apply exactly as for bundled
 * triggers. `issued_at` (UTC `Y-m-d H:i:s`) is supported for any caller
 * recording a completion moment - manual backdating and integrations
 * alike.
 *
 * @since 2.0.0
 *
 * @param array $args {
 *     Issuance arguments.
 *
 *     @type int    $template_id  Published template row id.
 *     @type int    $recipient_id Recipient user id.
 *     @type string $source_type  'manual' or an integration id. Default 'manual'.
 *     @type string $source_ref   Source object reference; null for manual.
 *     @type int    $issued_by    Acting user id; system events pass 0.
 *     @type array  $context      Integration-supplied resolution context.
 *     @type bool   $force        Bypass duplicate suppression. Default false.
 *     @type string $issued_at    UTC MySQL datetime override. Default now.
 *     @type string $expires_at   UTC MySQL datetime override. Default: the
 *                                template's validity settings, or never.
 * }
 * @return int|WP_Error Certificate row id (the existing id when
 *                      duplicate-suppressed), or WP_Error on abort.
 */
function ppcert_issue_certificate( array $args ) {
	return PressPrimer_Certificate_Issuance_Service::issue( $args );
}

/**
 * Render an issued certificate to a PDF temp file
 *
 * @since 2.0.0
 *
 * @param int    $certificate_id Certificate row id.
 * @param string $context        'download' | 'email' | 'preview'. Default 'download'.
 * @return string|WP_Error Absolute temp path (the caller owns the file:
 *                         attach or stream, then wp_delete_file()).
 */
function ppcert_render_certificate_pdf( $certificate_id, $context = 'download' ) {
	return PressPrimer_Certificate_PDF_Renderer::render_certificate( $certificate_id, $context );
}

/**
 * Public share URL for a certificate's view page
 *
 * @since 2.0.0
 *
 * @param string $credential_id Credential ID (any accepted input form).
 * @return string URL, or '' for an invalid credential.
 */
function ppcert_certificate_view_url( $credential_id ) {
	return PressPrimer_Certificate_View_Page::view_url( $credential_id );
}

/**
 * Public PDF download URL for a certificate
 *
 * @since 2.0.0
 *
 * @param string $credential_id Credential ID (any accepted input form).
 * @return string URL, or '' for an invalid credential.
 */
function ppcert_certificate_pdf_url( $credential_id ) {
	return PressPrimer_Certificate_View_Page::pdf_url( $credential_id );
}

/**
 * Template summaries for integration pickers
 *
 * @since 2.0.0
 *
 * @param array $args {
 *     Optional filters.
 *
 *     @type string $status Restrict to one status ('published' for
 *                          award-ready templates). Default '' (all
 *                          non-deleted templates).
 * }
 * @return array[] Summaries: [ 'id' => int, 'title' => string,
 *                 'status' => string ].
 */
function ppcert_get_templates( array $args = [] ) {
	$status    = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
	$summaries = [];

	foreach ( PressPrimer_Certificate_Template::get_all() as $template ) {
		if ( '' !== $status && (string) $template->status !== $status ) {
			continue;
		}

		$summaries[] = [
			'id'     => (int) $template->id,
			'title'  => (string) $template->title,
			'status' => (string) $template->status,
		];
	}

	return $summaries;
}

/**
 * Find a recipient's existing certificate for a source
 *
 * The duplicate-suppression lookup, exposed so integrations can link an
 * earned certificate to its credential page: the newest non-revoked row
 * matching (recipient, template, source_type, source_ref).
 *
 * @since 2.0.0
 *
 * @param int         $recipient_id Recipient user id.
 * @param int         $template_id  Template row id.
 * @param string      $source_type  Source type id.
 * @param string|null $source_ref   Source reference (null when none).
 * @return object|null Hydrated certificate row, or null.
 */
function ppcert_find_certificate( $recipient_id, $template_id, $source_type, $source_ref = null ) {
	return PressPrimer_Certificate_Certificate::find_duplicate(
		absint( $recipient_id ),
		absint( $template_id ),
		sanitize_key( (string) $source_type ),
		null === $source_ref || '' === $source_ref ? null : (string) $source_ref
	);
}

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
