<?php
/**
 * Certificates REST controller
 *
 * The Certificates admin screen's data layer (Feature 003 TR-002):
 * capability-gated list/detail, manual issuance with the duplicate
 * "Issue anyway" flow, and the recipient picker search.
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
 * Certificates controller class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_REST_Certificates_Controller {

	/**
	 * Recipient picker page size (TR-002)
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const USER_SEARCH_LIMIT = 20;

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
			'/certificates',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_list' ],
					'permission_callback' => [ $this, 'can_view' ],
					'args'                => [
						'template_id' => [ 'sanitize_callback' => 'absint' ],
						'status'      => [ 'sanitize_callback' => 'sanitize_key' ],
						'source_type' => [ 'sanitize_callback' => 'sanitize_key' ],
						'search'      => [ 'sanitize_callback' => 'sanitize_text_field' ],
						'page'        => [ 'sanitize_callback' => 'absint' ],
						'per_page'    => [ 'sanitize_callback' => 'absint' ],
					],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'issue' ],
					'permission_callback' => [ $this, 'can_issue' ],
				],
			]
		);

		register_rest_route(
			'ppcert/v1',
			'/certificates/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_detail' ],
				'permission_callback' => [ $this, 'can_view_detail' ],
			]
		);

		register_rest_route(
			'ppcert/v1',
			'/users/search',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'search_users' ],
				'permission_callback' => [ $this, 'can_issue' ],
				'args'                => [
					'search' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);
	}

	/**
	 * Capability check: list viewing
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function can_view() {
		return current_user_can( PressPrimer_Certificate_Capabilities::CAP_VIEW_CERTIFICATES );
	}

	/**
	 * Capability check: manual issuance + recipient picker
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function can_issue() {
		return current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES );
	}

	/**
	 * Capability check: detail is viewable by staff or the recipient
	 * themselves (TR-002 "capability or own-certificate")
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return bool
	 */
	public function can_view_detail( $request ) {
		if ( $this->can_view() ) {
			return true;
		}

		$certificate = PressPrimer_Certificate_Certificate::get( absint( $request->get_param( 'id' ) ) );

		return $certificate
			&& get_current_user_id() > 0
			&& (int) $certificate->recipient_id === get_current_user_id();
	}

	/**
	 * GET /certificates - the admin list
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_list( $request ) {
		$result = PressPrimer_Certificate_Certificate::query(
			[
				'template_id' => absint( $request->get_param( 'template_id' ) ),
				'status'      => (string) $request->get_param( 'status' ),
				'source_type' => (string) $request->get_param( 'source_type' ),
				'search'      => (string) $request->get_param( 'search' ),
				'page'        => absint( $request->get_param( 'page' ) ),
				'per_page'    => absint( $request->get_param( 'per_page' ) ),
			]
		);

		return new WP_REST_Response(
			[
				'items' => array_map( [ $this, 'prepare_item' ], $result['items'] ),
				'total' => $result['total'],
			],
			200
		);
	}

	/**
	 * GET /certificates/{id} - detail
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_detail( $request ) {
		$certificate = PressPrimer_Certificate_Certificate::get( absint( $request->get_param( 'id' ) ) );

		if ( ! $certificate ) {
			return new WP_Error(
				'ppcert_certificate_not_found',
				__( 'Certificate not found.', 'pressprimer-certificate' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response( $this->prepare_item( $certificate ), 200 );
	}

	/**
	 * POST /certificates - manual issuance (FR-003)
	 *
	 * Duplicate handling per 003 Edge Cases: without `force`, an
	 * existing certificate for the same recipient + template returns a
	 * 409 carrying the existing credential so the UI can offer "Issue
	 * anyway"; with `force`, the engine bypasses suppression.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function issue( $request ) {
		$template_id  = absint( $request->get_param( 'template_id' ) );
		$recipient_id = absint( $request->get_param( 'recipient_id' ) );
		$force        = (bool) $request->get_param( 'force' );

		if ( ! $force ) {
			$existing = PressPrimer_Certificate_Certificate::find_duplicate(
				$recipient_id,
				$template_id,
				'manual',
				null
			);

			if ( $existing ) {
				return new WP_Error(
					'ppcert_duplicate_certificate',
					__( 'This recipient already has a certificate from this template.', 'pressprimer-certificate' ),
					[
						'status'        => 409,
						'existing_id'   => (int) $existing->id,
						'credential_id' => PressPrimer_Certificate_Credential_ID_Service::format_display( (string) $existing->credential_id ),
					]
				);
			}
		}

		$result = PressPrimer_Certificate_Issuance_Service::issue(
			[
				'template_id'  => $template_id,
				'recipient_id' => $recipient_id,
				'source_type'  => 'manual',
				'source_ref'   => null,
				'issued_by'    => get_current_user_id(),
				'force'        => $force,
			]
		);

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 400 ] );

			return $result;
		}

		$certificate = PressPrimer_Certificate_Certificate::get( (int) $result );

		return new WP_REST_Response( $this->prepare_item( $certificate ), 201 );
	}

	/**
	 * GET /users/search - the recipient picker (TR-002: LIMIT 20)
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function search_users( $request ) {
		$search = (string) $request->get_param( 'search' );

		$users = get_users(
			[
				'search'         => '*' . $search . '*',
				'search_columns' => [ 'user_login', 'user_email', 'user_nicename', 'display_name' ],
				'number'         => self::USER_SEARCH_LIMIT,
			]
		);

		$items = [];

		foreach ( (array) $users as $user ) {
			$items[] = [
				'id'    => (int) $user->ID,
				'name'  => (string) $user->display_name,
				'email' => (string) $user->user_email,
			];
		}

		return new WP_REST_Response( $items, 200 );
	}

	/**
	 * Shape a certificate row for clients
	 *
	 * Snapshots and merge data never serialize into list/detail
	 * responses - they are render-time inputs, not admin data.
	 *
	 * @since 1.0.0
	 *
	 * @param object $certificate Hydrated row.
	 * @return array
	 */
	private function prepare_item( $certificate ) {
		$recipient = get_userdata( (int) $certificate->recipient_id );
		$template  = PressPrimer_Certificate_Template::get( (int) $certificate->template_id );

		return [
			'id'                => (int) $certificate->id,
			'credential_id'     => PressPrimer_Certificate_Credential_ID_Service::format_display( (string) $certificate->credential_id ),
			'recipient'         => [
				'id'   => (int) $certificate->recipient_id,
				'name' => $recipient ? (string) $recipient->display_name : __( '(deleted user)', 'pressprimer-certificate' ),
			],
			'template'          => [
				'id'    => (int) $certificate->template_id,
				'title' => $template ? (string) $template->title : __( '(deleted template)', 'pressprimer-certificate' ),
			],
			'source_type'       => (string) $certificate->source_type,
			'status'            => PressPrimer_Certificate_Certificate::effective_status( $certificate ),
			// UTC in, localized out (CLAUDE.md Datetime Standard).
			'issued_at'         => (string) $certificate->issued_at,
			'issued_at_display' => get_date_from_gmt(
				(string) $certificate->issued_at,
				get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
			),
		];
	}
}
