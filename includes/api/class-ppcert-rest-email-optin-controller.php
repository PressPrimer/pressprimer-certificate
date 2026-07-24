<?php
/**
 * Email opt-in REST controller
 *
 * One route for the plugin's single email ask: records an opt-in
 * (with the relay to pressprimer.com), a decline, or a per-surface
 * dismissal. Follows the same controller pattern as the other
 * /ppcert/v1 endpoints.
 *
 * @package PressPrimer_Certificate
 * @subpackage API
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email opt-in controller class
 *
 * Registers POST /ppcert/v1/email-optin.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_REST_Email_Optin_Controller {

	/**
	 * Initialize the controller
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register routes
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			'ppcert/v1',
			'/email-optin',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => [
					'decision' => [
						'required' => true,
						'type'     => 'string',
						'enum'     => [ 'opt_in', 'decline', 'dismiss' ],
					],
					'source'   => [
						'required' => true,
						'type'     => 'string',
						'enum'     => PressPrimer_Certificate_Email_Optin_Service::SOURCES,
					],
					'email'    => [
						'required' => false,
						'type'     => 'string',
					],
				],
			]
		);
	}

	/**
	 * Check permission for the opt-in endpoint
	 *
	 * Administrators only - the same audience the ask surfaces render
	 * for.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if the user may answer.
	 */
	public function check_permission() {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * Handle an opt-in, decline, or dismissal
	 *
	 * Opt-in order matters: the local consent record is written FIRST
	 * (it is the source of truth), then the non-blocking relay fires,
	 * then the action - so an unreachable pressprimer.com changes
	 * nothing about the user's experience or their recorded consent.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle( $request ) {
		if ( ! PressPrimer_Certificate_Email_Optin_Service::is_enabled() ) {
			return new WP_Error(
				'ppcert_optin_disabled',
				__( 'The email opt-in is not available on this site.', 'pressprimer-certificate' ),
				[ 'status' => 400 ]
			);
		}

		$decision = sanitize_key( $request->get_param( 'decision' ) );
		$source   = sanitize_key( $request->get_param( 'source' ) );
		$user_id  = get_current_user_id();

		if ( ! PressPrimer_Certificate_Email_Optin_Service::is_valid_source( $source ) ) {
			return new WP_Error(
				'ppcert_optin_invalid_source',
				__( 'Unknown opt-in surface.', 'pressprimer-certificate' ),
				[ 'status' => 400 ]
			);
		}

		// Dismissal: hide this one surface, permanently. Separate from
		// answering - the other surfaces stay available.
		if ( 'dismiss' === $decision ) {
			PressPrimer_Certificate_Email_Optin_Service::record_dismissal( $user_id, $source );

			return new WP_REST_Response(
				[
					'success' => true,
					'status'  => 'dismissed',
				],
				200
			);
		}

		// One answer per user, forever: a second answer is a no-op
		// that reports the existing state.
		$existing = PressPrimer_Certificate_Email_Optin_Service::get_consent( $user_id );

		if ( $existing ) {
			return new WP_REST_Response(
				[
					'success'          => true,
					'status'           => $existing['status'],
					'already_answered' => true,
				],
				200
			);
		}

		if ( 'decline' === $decision ) {
			PressPrimer_Certificate_Email_Optin_Service::record_decline( $user_id, $source );

			return new WP_REST_Response(
				[
					'success' => true,
					'status'  => 'declined',
				],
				200
			);
		}

		// Opt-in: a typed, valid email is required.
		$email = sanitize_email( wp_unslash( (string) $request->get_param( 'email' ) ) );

		if ( empty( $email ) || ! is_email( $email ) ) {
			return new WP_Error(
				'ppcert_optin_invalid_email',
				__( 'Please enter a valid email address.', 'pressprimer-certificate' ),
				[ 'status' => 400 ]
			);
		}

		PressPrimer_Certificate_Email_Optin_Service::record_opt_in( $user_id, $source );
		PressPrimer_Certificate_Email_Optin_Service::relay_opt_in( $email, $source );

		/**
		 * Fires after a user's email opt-in is recorded locally and relayed.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $user_id User ID.
		 * @param string $source  Surface: wizard | dashboard-card.
		 */
		do_action( 'ppcert_email_optin_submitted', $user_id, $source );

		return new WP_REST_Response(
			[
				'success' => true,
				'status'  => 'opted_in',
			],
			200
		);
	}
}
