<?php
/**
 * Bulk certificate actions REST controller
 *
 * The Certificates screen's bulk actions (Feature 2.1-002 TR-004):
 * create a job from the checked rows or from every certificate matching
 * the list filters, run it chunk by chunk from the
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
					'action'  => [
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => [ $this, 'validate_action' ],
					],
					'ids'     => [ 'validate_callback' => [ $this, 'validate_ids' ] ],
					'filters' => [ 'validate_callback' => [ $this, 'validate_filters' ] ],
					'reason'  => [ 'sanitize_callback' => 'sanitize_text_field' ],
					'confirm' => [ 'sanitize_callback' => 'sanitize_text_field' ],
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
	 * Validate the filters object: GET /certificates' names and rules
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $value Parameter value.
	 * @return bool
	 */
	public function validate_filters( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}

		$list = new PressPrimer_Certificate_REST_Certificates_Controller();

		foreach ( $value as $key => $filter ) {
			switch ( $key ) {
				case 'template_id':
					$valid = is_numeric( $filter ) || '' === $filter;
					break;

				case 'status':
					$valid = $list->validate_status_filter( $filter );
					break;

				case 'issued_after':
				case 'issued_before':
					$valid = $list->validate_filter_date( $filter );
					break;

				case 'source_type':
				case 'search':
					$valid = is_string( $filter );
					break;

				default:
					$valid = false;
			}

			if ( ! $valid ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * POST /certificates/bulk - confirm a job
	 *
	 * Either the checked rows (`ids`) or every certificate matching the
	 * list filters (`filters`, resolved now with the user's bulk scope:
	 * the set is fixed at confirmation, FR-004). Deleting everything
	 * that matches needs the typed confirmation `confirm: DELETE`.
	 *
	 * @since 2.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_job( $request ) {
		$user_id = get_current_user_id();
		$action  = (string) $request->get_param( 'action' );
		$ids     = $request->get_param( 'ids' );
		$filters = $request->get_param( 'filters' );

		if ( null !== $ids && null !== $filters ) {
			return new WP_Error( 'ppcert_bulk_ambiguous', __( 'Send either the selected certificates or the filters, not both.', 'pressprimer-certificate' ), [ 'status' => 400 ] );
		}

		$criteria = [ 'type' => 'selected' ];

		if ( null !== $filters ) {
			$filters = $this->clean_filters( (array) $filters );

			if ( '' !== $filters['issued_after'] && '' !== $filters['issued_before'] && $filters['issued_after'] > $filters['issued_before'] ) {
				return new WP_Error(
					'ppcert_invalid_date_range',
					__( 'The issued-date range is reversed: the from date is after the to date.', 'pressprimer-certificate' ),
					[ 'status' => 400 ]
				);
			}

			if ( 'delete' === $action && ! hash_equals( 'DELETE', (string) $request->get_param( 'confirm' ) ) ) {
				return new WP_Error( 'ppcert_bulk_confirm_required', __( 'Type DELETE to confirm.', 'pressprimer-certificate' ), [ 'status' => 400 ] );
			}

			// One past the cap, so the service can refuse an oversized set.
			$ids = PressPrimer_Certificate_Certificate::query_ids(
				$filters,
				PressPrimer_Certificate_Certificate::bulk_scope( $user_id ),
				PressPrimer_Certificate_Bulk_Action_Service::max_items() + 1
			);

			if ( empty( $ids ) ) {
				return new WP_Error( 'ppcert_bulk_empty', __( 'No certificates match these filters.', 'pressprimer-certificate' ), [ 'status' => 400 ] );
			}

			$criteria = [
				'type'    => 'filters',
				'filters' => $filters,
			];
		}

		$job = PressPrimer_Certificate_Bulk_Action_Service::create(
			$user_id,
			$action,
			array_map( 'intval', (array) $ids ),
			[
				'reason'   => (string) $request->get_param( 'reason' ),
				'criteria' => $criteria,
			]
		);

		return is_wp_error( $job ) ? $job : new WP_REST_Response( $job, 201 );
	}

	/**
	 * Sanitize a validated filters object to the list's six filters
	 *
	 * @since 2.1.0
	 *
	 * @param array $filters Raw filters.
	 * @return array
	 */
	private function clean_filters( array $filters ) {
		$text = static function ( $key ) use ( $filters ) {
			return isset( $filters[ $key ] ) ? sanitize_text_field( (string) $filters[ $key ] ) : '';
		};

		return [
			'template_id'   => isset( $filters['template_id'] ) ? absint( $filters['template_id'] ) : 0,
			'status'        => $text( 'status' ),
			'source_type'   => sanitize_key( $text( 'source_type' ) ),
			'search'        => $text( 'search' ),
			'issued_after'  => $text( 'issued_after' ),
			'issued_before' => $text( 'issued_before' ),
		];
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
