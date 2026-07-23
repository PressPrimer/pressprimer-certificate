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

		// Public download (Feature 005 FR-004): resolves strictly by full
		// credential ID - the same public-by-URL model as the view page.
		register_rest_route(
			'ppcert/v1',
			'/certificates/(?P<credential_id>[A-Za-z0-9\-]+)/pdf',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'download_pdf' ],
				'permission_callback' => '__return_true',
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
		$earned_date  = sanitize_text_field( (string) $request->get_param( 'earned_date' ) );

		// Earned date (Phase 5B item 6): a Y-m-d site-local date. Today
		// stamps the exact current moment; a backdate stores that date
		// at local noon, converted to UTC (never a future date).
		$issued_at_override = null;

		if ( '' !== $earned_date ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $earned_date ) ) {
				return new WP_Error(
					'ppcert_invalid_earned_date',
					__( 'The earned date must be a valid date.', 'pressprimer-certificate' ),
					[ 'status' => 400 ]
				);
			}

			$today_local = get_date_from_gmt( current_time( 'mysql', true ), 'Y-m-d' );

			if ( $earned_date > $today_local ) {
				return new WP_Error(
					'ppcert_invalid_earned_date',
					__( 'The earned date cannot be in the future.', 'pressprimer-certificate' ),
					[ 'status' => 400 ]
				);
			}

			if ( $earned_date !== $today_local ) {
				$issued_at_override = get_gmt_from_date( $earned_date . ' 12:00:00' );
			}
		}

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

		$issue_args = [
			'template_id'  => $template_id,
			'recipient_id' => $recipient_id,
			'source_type'  => 'manual',
			'source_ref'   => null,
			'issued_by'    => get_current_user_id(),
			'force'        => $force,
		];

		if ( null !== $issued_at_override ) {
			$issue_args['issued_at'] = $issued_at_override;
		}

		$result = PressPrimer_Certificate_Issuance_Service::issue( $issue_args );

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 400 ] );

			return $result;
		}

		$certificate = PressPrimer_Certificate_Certificate::get( (int) $result );

		return new WP_REST_Response( $this->prepare_item( $certificate ), 201 );
	}

	/**
	 * GET /certificates/{credential_id}/pdf - public download (005 FR-004)
	 *
	 * Public by URL: the non-guessable credential ID is the access token,
	 * matching the view page's visibility model. Renders from the
	 * immutable snapshot and records a `downloaded` event (anonymous
	 * actor when logged out). Revoked certificates are not served - the
	 * artifact was administratively withdrawn.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_Error On failure (success streams the file and exits).
	 */
	public function download_pdf( $request ) {
		$certificate = PressPrimer_Certificate_Certificate::get_by_credential_id(
			(string) $request->get_param( 'credential_id' )
		);

		if ( ! $certificate || ! is_array( $certificate->layout_snapshot ) ) {
			return new WP_Error(
				'ppcert_certificate_not_found',
				__( 'Certificate not found.', 'pressprimer-certificate' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'revoked' === PressPrimer_Certificate_Certificate::effective_status( $certificate ) ) {
			return new WP_Error(
				'ppcert_certificate_revoked',
				__( 'This certificate has been revoked.', 'pressprimer-certificate' ),
				[ 'status' => 410 ]
			);
		}

		$renderer = new PressPrimer_Certificate_PDF_Renderer();
		$pdf_path = $renderer->render_pdf(
			$certificate->layout_snapshot,
			is_array( $certificate->merge_data ) ? $certificate->merge_data : [],
			[
				'context'       => 'download',
				'credential_id' => (string) $certificate->credential_id,
			]
		);

		if ( is_wp_error( $pdf_path ) ) {
			$pdf_path->add_data( [ 'status' => 500 ] );

			return $pdf_path;
		}

		PressPrimer_Certificate_Certificate::record_event(
			(int) $certificate->id,
			'downloaded',
			get_current_user_id() > 0 ? get_current_user_id() : null
		);

		$this->stream_pdf(
			$pdf_path,
			'certificate-' . strtolower( (string) $certificate->credential_id ) . '.pdf'
		);
	}

	/**
	 * Stream a rendered PDF and exit
	 *
	 * Separated so tests can override streaming; production always exits
	 * here (REST response serialization never sees the binary).
	 *
	 * @since 1.0.0
	 *
	 * @param string $pdf_path Rendered temp file path.
	 * @param string $filename Download filename.
	 */
	protected function stream_pdf( $pdf_path, $filename ) {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $pdf_path ) );

		readfile( $pdf_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a locally rendered temp file.
		wp_delete_file( $pdf_path );

		exit;
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
