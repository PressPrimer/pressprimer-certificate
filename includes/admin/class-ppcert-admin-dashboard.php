<?php
/**
 * Dashboard admin screen
 *
 * The React dashboard on the top-level Certificates menu slug (Phase
 * 5B item 1): welcome header, stats cards, awarded-over-time chart,
 * quick actions, recent certificates, and top templates. Menu
 * registration stays in PressPrimer_Certificate_Admin (the menu
 * owner); this class renders the page and enqueues its bundle.
 *
 * @package PressPrimer_Certificate
 * @subpackage Admin
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard screen class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Admin_Dashboard {

	/**
	 * Render the dashboard mount
	 *
	 * @since 1.0.0
	 */
	public function render_page() {
		echo '<div id="ppcert-dashboard-root"></div>';
	}

	/**
	 * Enqueue the dashboard bundle (called by Admin on its hook)
	 *
	 * @since 1.0.0
	 */
	public function enqueue() {
		$asset_file = PPCERT_PLUGIN_DIR . 'build/dashboard.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'ppcert-dashboard',
			PPCERT_PLUGIN_URL . 'build/dashboard.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'ppcert-dashboard', 'pressprimer-certificate', PPCERT_PLUGIN_DIR . 'languages' );

		if ( file_exists( PPCERT_PLUGIN_DIR . 'build/style-dashboard.css' ) ) {
			wp_enqueue_style(
				'ppcert-dashboard',
				PPCERT_PLUGIN_URL . 'build/style-dashboard.css',
				[],
				$asset['version']
			);
		}

		// wp-scripts emits a SECOND CSS file per entry - dashboard.css -
		// for styles imported by components outside the entry's own
		// style.css (the shared EmailOptinAsk.css). Without it those
		// components render unstyled.
		if ( file_exists( PPCERT_PLUGIN_DIR . 'build/dashboard.css' ) ) {
			wp_enqueue_style(
				'ppcert-dashboard-components',
				PPCERT_PLUGIN_URL . 'build/dashboard.css',
				[],
				$asset['version']
			);
		}

		wp_localize_script( 'ppcert-dashboard', 'ppcert_dashboard_data', $this->boot_data() );
	}

	/**
	 * Boot data for the dashboard bundle
	 *
	 * Branding values run through filters so the Enterprise addon can
	 * white-label them (sibling parity with the Assignment dashboard).
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function boot_data() {
		/** This filter is documented in includes/admin/class-ppcert-admin.php */
		$plugin_name = apply_filters( 'ppcert_plugin_name', __( 'PressPrimer Certificate', 'pressprimer-certificate' ) );

		/**
		 * Filters the dashboard header logo URL.
		 *
		 * Used by the Enterprise addon for white-label branding (sibling
		 * parity with pressprimer_assignment_dashboard_logo).
		 *
		 * @since 1.0.0
		 *
		 * @param string $logo_url Default logo URL.
		 */
		$dashboard_logo = apply_filters(
			'ppcert_dashboard_logo',
			PPCERT_PLUGIN_URL . 'assets/images/PressPrimer-Logo-White.svg'
		);

		/**
		 * Filters the dashboard welcome text.
		 *
		 * Used by the Enterprise addon for white-label branding.
		 *
		 * @since 1.0.0
		 *
		 * @param string $text Default welcome text.
		 * @param string $name The plugin name (filtered).
		 */
		$welcome_text = apply_filters(
			'ppcert_dashboard_welcome_text',
			sprintf(
				/* translators: %s: plugin name */
				__( 'Welcome to %s! Here\'s an overview of your certificates.', 'pressprimer-certificate' ),
				$plugin_name
			),
			$plugin_name
		);

		return [
			'pluginName'    => $plugin_name,
			'dashboardLogo' => esc_url( $dashboard_logo ),
			'welcomeText'   => $welcome_text,
			'urls'          => [
				'create_template' => add_query_arg(
					[
						'page'   => 'ppcert-templates',
						'action' => 'new',
					],
					admin_url( 'admin.php' )
				),
				'templates'       => add_query_arg( 'page', 'ppcert-templates', admin_url( 'admin.php' ) ),
				'certificates'    => add_query_arg( 'page', 'ppcert-certificates', admin_url( 'admin.php' ) ),
				'issue'           => add_query_arg(
					[
						'page'         => 'ppcert-certificates',
						'ppcert_issue' => 1,
					],
					admin_url( 'admin.php' )
				),
				'settings'        => add_query_arg( 'page', 'ppcert-settings', admin_url( 'admin.php' ) ),
			],
			'caps'          => [
				'manage_templates'   => current_user_can( PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES ),
				'issue_certificates' => current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES ),
				'manage_settings'    => current_user_can( PressPrimer_Certificate_Capabilities::CAP_MANAGE_SETTINGS ),
			],
			// The email-course card: admins only, dismissed and
			// answered states resolve server-side.
			'emailOptin'    => [
				'eligible'   => class_exists( 'PressPrimer_Certificate_Email_Optin_Service' )
					&& PressPrimer_Certificate_Email_Optin_Service::is_eligible( get_current_user_id(), 'dashboard-card' ),
				'privacyUrl' => 'https://pressprimer.com/privacy/',
			],
		];
	}
}
