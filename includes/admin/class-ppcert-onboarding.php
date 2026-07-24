<?php
/**
 * Onboarding guided tour
 *
 * The activation walkthrough (Phase 5B item 2), modeled on the
 * Assignment 2.2 guided build: instead of describing the product, the
 * tour walks the user through creating, publishing, and issuing a
 * real certificate in the real admin UI - first certificate in about
 * five minutes.
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
 * Onboarding class
 *
 * Per-user tour state in user meta; progress persists via AJAX so the
 * tour survives the page navigations it choreographs (gallery ->
 * designer -> certificates screen).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Onboarding {

	/**
	 * User meta key: onboarding completed flag
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_COMPLETED = 'ppcert_onboarding_completed';

	/**
	 * User meta key: onboarding skipped flag
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_SKIPPED = 'ppcert_onboarding_skipped';

	/**
	 * User meta key: current onboarding step
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_STEP = 'ppcert_onboarding_step';

	/**
	 * User meta key: onboarding started flag
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_STARTED = 'ppcert_onboarding_started';

	/**
	 * Total number of onboarding steps
	 *
	 * Guided build: welcome, pick a design, the canvas, publish,
	 * issue, your certificate, complete.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const TOTAL_STEPS = 7;

	/**
	 * Singleton instance
	 *
	 * @since 1.0.0
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 *
	 * @return self Singleton instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor: AJAX endpoints, asset loading, relaunch links
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_action( 'wp_ajax_ppcert_onboarding_progress', [ $this, 'handle_progress_ajax' ] );
		add_action( 'wp_ajax_ppcert_get_onboarding_state', [ $this, 'handle_get_state_ajax' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_assets' ] );

		// Nonce'd relaunch links (dashboard) reset the tour state
		// server-side before the tour auto-opens.
		add_action( 'admin_init', [ $this, 'maybe_handle_relaunch' ] );
	}

	/**
	 * Check whether the current user can use the tour
	 *
	 * The guided build creates a template and issues a certificate, so
	 * it needs both capabilities - in 1.0 that means administrators.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the user can run the tour.
	 */
	private function user_can_use_wizard() {
		return current_user_can( PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES )
			&& current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES );
	}

	/**
	 * Get the tour relaunch URL
	 *
	 * Points at the dashboard with a nonce'd parameter; arriving with
	 * it resets the user's tour state, and the tour auto-opens there.
	 *
	 * @since 1.0.0
	 *
	 * @return string Relaunch URL.
	 */
	public static function get_relaunch_url() {
		return wp_nonce_url(
			add_query_arg(
				'ppcert-relaunch',
				'1',
				admin_url( 'admin.php?page=pressprimer-certificate' )
			),
			'ppcert_setup_relaunch'
		);
	}

	/**
	 * Check whether the onboarding should show for the current user
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if onboarding should display.
	 */
	public function should_show_onboarding() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		if ( ! $this->user_can_use_wizard() ) {
			return false;
		}

		if ( get_user_meta( $user_id, self::META_COMPLETED, true ) ) {
			return false;
		}

		if ( 'permanent' === get_user_meta( $user_id, self::META_SKIPPED, true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Mark the onboarding as completed for the current user
	 *
	 * @since 1.0.0
	 */
	public function complete_onboarding() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		update_user_meta( $user_id, self::META_COMPLETED, true );
		update_user_meta( $user_id, self::META_STEP, self::TOTAL_STEPS );

		/**
		 * Fires when a user completes the setup tour.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id The user who completed the tour.
		 */
		do_action( 'ppcert_onboarding_completed', $user_id );
	}

	/**
	 * Skip the onboarding for the current user
	 *
	 * @since 1.0.0
	 *
	 * @param bool $permanent Whether to permanently skip (don't show again).
	 */
	public function skip_onboarding( $permanent = false ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		if ( $permanent ) {
			update_user_meta( $user_id, self::META_SKIPPED, 'permanent' );
		}

		// Always mark as completed so the tour doesn't reappear on
		// navigation.
		update_user_meta( $user_id, self::META_COMPLETED, true );

		/**
		 * Fires when a user skips the setup tour.
		 *
		 * @since 1.0.0
		 *
		 * @param int  $user_id   The user who skipped.
		 * @param bool $permanent Whether the skip is permanent.
		 */
		do_action( 'ppcert_onboarding_skipped', $user_id, (bool) $permanent );
	}

	/**
	 * Reset the onboarding for the current user (relaunch)
	 *
	 * @since 1.0.0
	 */
	public function reset_onboarding() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		delete_user_meta( $user_id, self::META_COMPLETED );
		delete_user_meta( $user_id, self::META_SKIPPED );
		delete_user_meta( $user_id, self::META_STEP );
		delete_user_meta( $user_id, self::META_STARTED );

		/**
		 * Fires when a user's setup tour state is reset (relaunch).
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id The user whose tour state was reset.
		 */
		do_action( 'ppcert_onboarding_reset', $user_id );
	}

	/**
	 * Mark the onboarding as started for the current user
	 *
	 * @since 1.0.0
	 */
	public function start_onboarding() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		update_user_meta( $user_id, self::META_STARTED, true );
		update_user_meta( $user_id, self::META_STEP, 1 );

		// Clear any previous skip.
		delete_user_meta( $user_id, self::META_SKIPPED );

		/**
		 * Fires when a user starts the setup tour.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id The user who started the tour.
		 */
		do_action( 'ppcert_onboarding_started', $user_id );
	}

	/**
	 * Update the current step for the user
	 *
	 * @since 1.0.0
	 *
	 * @param int $step Step number.
	 */
	public function update_step( $step ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$step = max( 1, min( self::TOTAL_STEPS, absint( $step ) ) );
		update_user_meta( $user_id, self::META_STEP, $step );
	}

	/**
	 * Get the current onboarding state for the user
	 *
	 * @since 1.0.0
	 *
	 * @return array State array with should_show, current_step, etc.
	 */
	public function get_onboarding_state() {
		$user_id = get_current_user_id();

		return [
			'should_show'  => $this->should_show_onboarding(),
			'current_step' => $user_id ? absint( get_user_meta( $user_id, self::META_STEP, true ) ) : 0,
			'total_steps'  => self::TOTAL_STEPS,
			'completed'    => $user_id ? (bool) get_user_meta( $user_id, self::META_COMPLETED, true ) : false,
			'started'      => $user_id ? (bool) get_user_meta( $user_id, self::META_STARTED, true ) : false,
		];
	}

	/**
	 * Get the JavaScript data object for the React onboarding bundle
	 *
	 * @since 1.0.0
	 *
	 * @return array Data passed via wp_localize_script().
	 */
	public function get_js_data() {
		/** This filter is documented in includes/admin/class-ppcert-admin.php */
		$plugin_name = apply_filters( 'ppcert_plugin_name', __( 'PressPrimer Certificate', 'pressprimer-certificate' ) );

		return [
			'state'             => $this->get_onboarding_state(),
			'nonce'             => wp_create_nonce( 'ppcert_onboarding' ),
			'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
			'relaunchUrl'       => self::get_relaunch_url(),
			'pluginUrl'         => PPCERT_PLUGIN_URL,
			'urls'              => [
				'dashboard'    => admin_url( 'admin.php?page=pressprimer-certificate' ),
				'templates'    => admin_url( 'admin.php?page=ppcert-templates' ),
				'gallery'      => admin_url( 'admin.php?page=ppcert-templates&action=new' ),
				'certificates' => admin_url( 'admin.php?page=ppcert-certificates' ),
				'settings'     => admin_url( 'admin.php?page=ppcert-settings' ),
			],
			// The credential slots into this template client-side
			// ('PPCERTCRED' is a plain alphanumeric token).
			'pdfUrlTemplate' => rest_url( 'ppcert/v1/certificates/PPCERTCRED/pdf' ),
			// The email ask on the finish stop. Eligibility resolves
			// server-side: silently skipped once the user has answered
			// anywhere or when the intake is disabled.
			'emailOptin'     => [
				'eligible'   => class_exists( 'PressPrimer_Certificate_Email_Optin_Service' )
					&& PressPrimer_Certificate_Email_Optin_Service::is_eligible( get_current_user_id(), 'wizard' ),
				'privacyUrl' => 'https://pressprimer.com/privacy/',
			],
			'i18n'           => [
				'pluginName' => $plugin_name,
			],
		];
	}

	/**
	 * Conditionally enqueue the guided-tour React bundle
	 *
	 * Loads on Certificate admin pages: the tour overlays the REAL
	 * admin UI and auto-opens while should_show is true. The JS init
	 * checks should_show before rendering, and the relaunch links
	 * depend on the bundle being present on the dashboard.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function maybe_enqueue_assets( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		// Only load on Certificate admin pages (the top-level
		// pressprimer-certificate slug and the ppcert-* submenu slugs).
		$is_ppcert_page = false !== strpos( $hook, 'pressprimer-certificate' )
			|| 'pressprimer-certificate' === $current_page
			|| 0 === strpos( $current_page, 'ppcert-' );

		if ( ! $is_ppcert_page ) {
			return;
		}

		if ( ! $this->user_can_use_wizard() ) {
			return;
		}

		$asset_file = PPCERT_PLUGIN_DIR . 'build/onboarding.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'ppcert-onboarding',
			PPCERT_PLUGIN_URL . 'build/onboarding.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'ppcert-onboarding', 'pressprimer-certificate', PPCERT_PLUGIN_DIR . 'languages' );

		if ( file_exists( PPCERT_PLUGIN_DIR . 'build/style-onboarding.css' ) ) {
			wp_enqueue_style(
				'ppcert-onboarding',
				PPCERT_PLUGIN_URL . 'build/style-onboarding.css',
				[],
				$asset['version']
			);
		}

		// wp-scripts emits a SECOND CSS file per entry - onboarding.css -
		// for styles imported by components outside the entry's own
		// style.css (the shared EmailOptinAsk.css). Without it those
		// components render unstyled.
		if ( file_exists( PPCERT_PLUGIN_DIR . 'build/onboarding.css' ) ) {
			wp_enqueue_style(
				'ppcert-onboarding-components',
				PPCERT_PLUGIN_URL . 'build/onboarding.css',
				[],
				$asset['version']
			);
		}

		wp_localize_script( 'ppcert-onboarding', 'ppcert_onboarding_data', $this->get_js_data() );
	}

	/**
	 * Handle AJAX request for onboarding progress updates
	 *
	 * Accepts actions: start, next, prev, skip, complete, reset.
	 *
	 * @since 1.0.0
	 */
	public function handle_progress_ajax() {
		check_ajax_referer( 'ppcert_onboarding', 'nonce' );

		if ( ! $this->user_can_use_wizard() ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'pressprimer-certificate' ) ] );
		}

		$action_type = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : '';
		$step        = isset( $_POST['step'] ) ? absint( wp_unslash( $_POST['step'] ) ) : 0;
		$permanent   = isset( $_POST['permanent'] ) && 'true' === sanitize_text_field( wp_unslash( $_POST['permanent'] ) );

		switch ( $action_type ) {
			case 'start':
				$this->start_onboarding();
				// The tour navigates to the gallery immediately after
				// starting, so the landing step must persist before the
				// page unloads - otherwise the welcome modal reappears.
				if ( $step > 0 ) {
					$this->update_step( $step );
				}
				break;

			case 'next':
			case 'prev':
				if ( $step > 0 ) {
					$this->update_step( $step );
				}
				break;

			case 'skip':
				$this->skip_onboarding( $permanent );
				break;

			case 'complete':
				$this->complete_onboarding();
				break;

			case 'reset':
				$this->reset_onboarding();
				break;

			default:
				wp_send_json_error( [ 'message' => __( 'Invalid action type.', 'pressprimer-certificate' ) ] );
				break;
		}

		wp_send_json_success( $this->get_onboarding_state() );
	}

	/**
	 * Handle AJAX request to retrieve onboarding state
	 *
	 * @since 1.0.0
	 */
	public function handle_get_state_ajax() {
		check_ajax_referer( 'ppcert_onboarding', 'nonce' );

		if ( ! $this->user_can_use_wizard() ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'pressprimer-certificate' ) ] );
		}

		wp_send_json_success( $this->get_onboarding_state() );
	}

	/**
	 * Handle a tour relaunch request
	 *
	 * The dashboard's replay link points at the dashboard with a
	 * nonce'd relaunch parameter; arriving with it resets the user's
	 * tour state so the tour auto-opens fresh.
	 *
	 * @since 1.0.0
	 */
	public function maybe_handle_relaunch() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check; nonce verified below.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check; nonce verified below.
		if ( 'pressprimer-certificate' !== $current_page || ! isset( $_GET['ppcert-relaunch'] ) ) {
			return;
		}

		check_admin_referer( 'ppcert_setup_relaunch' );

		if ( ! $this->user_can_use_wizard() ) {
			return;
		}

		$this->reset_onboarding();
	}
}
