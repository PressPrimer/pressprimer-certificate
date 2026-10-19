<?php
/**
 * Bulk certificate actions REST controller
 *
 * The Certificates screen's bulk actions (Feature 2.1-002 TR-004):
 * create a job from the checked rows, run it chunk by chunk from the
 * open page, read its status, and cancel or discard it. Every route
 * needs the bulk permission check for the action; run, status, and
 * delete also need the job to be the caller's own.
 *
 * @package PressPrimer_Certificate
 * @subpackage API
 * @since 2.1.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk controller class
 *
 * @since 2.1.0
 */
class PressPrimer_Certificate_REST_Bulk_Controller {

	/**
	 * Job id route pattern (a UUID v4)
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const JOB_PATTERN = '(?P<job>[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})';

	/**
	 * Initialize the controller
	 *
	 * @since 2.1.0
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register routes
	 *
	 * @since 2.1.0
	 */
	public function register_routes() {
		register_rest_route(
			'ppcert/v1',
			'/certificates/bulk',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_job' ],
				'permission_callback' => [ $this, 'can_create' ],
				'args'                => [
					'action' => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => [ $this, 'validate_action' ],
					],
					'ids'    => [
						'required'          => true,
						'validate_callback' => [ $this, 'validate_ids' ],
					],
					'reason' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);

		register_rest_route(
			'ppcert/v1',
			'/certificates/bulk/' . self::JOB_PATTERN . '/run',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'run_job' ],
				'permission_callback' => [ $this, 'can_use_jobs' ],
			]
		);

		register_rest_route(
			'ppcert/v1',
			'/certificates/bulk/' . self::JOB_PATTERN,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_job' ],
					'permission_callback' => [ $this, 'can_use_jobs' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'cancel_job' ],
					'permission_callback' => [ $this, 'can_use_jobs' ],
				],
			]
		);
	}

	/**
	 * Permission: the current user may run this bulk action
	 *
	 * @since 2.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool
	 */
	public function can_create( $request ) {
		return is_user_logged_in()
			&& PressPrimer_Certificate_Certificate::user_can_bulk_manage( get_current_user_id(), (string) $request->get_param( 'action' ) );
	}

	/**
	 * Permission: a logged-in issuer (job ownership is checked by the
	 * service, which answers another user's job as not found)
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public function can_use_jobs() {
		return is_user_logged_in() && current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES );
	}

	/**
	 * Validate the action enum
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public function validate_action( $value ) {
		return is_string( $value ) && in_array( $value, PressPrimer_Certificate_Bulk_Action_Service::ACTIONS, true );
	}

	/**
	 * Validate the id list: an array of positive integers
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public function validate_ids( $value ) {
		if ( ! is_array( $value ) || empty( $value ) ) {
			return false;
		}

		foreach ( $value as $id ) {
			if ( ! is_numeric( $id ) || (int) $id < 1 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * POST /certificates/bulk - confirm a job for the checked rows
	 *
	 * @since 2.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_job( $request ) {
		$ids = array_map( 'intval', (array) $request->get_param( 'ids' ) );

		$job = PressPrimer_Certificate_Bulk_Action_Service::create(
			get_current_user_id(),
			(string) $request->get_param( 'action' ),
			$ids,
			[
				'reason'   => (string) $request->get_param( 'reason' ),
				'criteria' => [ 'type' => 'selected' ],
			]
		);

		return is_wp_error( $job ) ? $job : new WP_REST_Response( $job, 201 );
	}

	/**
	 * POST /certificates/bulk/{job}/run - process the next rows
	 *
	 * @since 2.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_job( $request ) {
		$job = PressPrimer_Certificate_Bulk_Action_Service::run( (string) $request->get_param( 'job' ), get_current_user_id() );

		return is_wp_error( $job ) ? $job : new WP_REST_Response( $job, 200 );
	}

	/**
	 * GET /certificates/bulk/{job} - status
	 *
	 * @since 2.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_job( $request ) {
		$job = PressPrimer_Certificate_Bulk_Action_Service::status( (string) $request->get_param( 'job' ), get_current_user_id() );

		return is_wp_error( $job ) ? $job : new WP_REST_Response( $job, 200 );
	}

	/**
	 * DELETE /certificates/bulk/{job} - cancel or discard
	 *
	 * @since 2.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_job( $request ) {
		$job = PressPrimer_Certificate_Bulk_Action_Service::cancel( (string) $request->get_param( 'job' ), get_current_user_id() );

		return is_wp_error( $job ) ? $job : new WP_REST_Response( $job, 200 );
	}
}
