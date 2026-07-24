<?php
/**
 * Dashboard REST controller
 *
 * GET /ppcert/v1/dashboard for the admin dashboard's stats cards,
 * recent-certificates list, and top-templates panel, plus
 * GET /ppcert/v1/dashboard/chart for the certificates-awarded-over-time
 * series (Phase 5B item 1, mirroring the Assignment statistics
 * endpoints).
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
 * Dashboard controller class
 *
 * All aggregates read UTC ppcert columns and return UTC values; the
 * React side localizes for display (CLAUDE.md Datetime Standard).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_REST_Dashboard_Controller {

	/**
	 * Rows in the recent-certificates list
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const RECENT_LIMIT = 10;

	/**
	 * Rows in the top-templates panel
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const TOP_TEMPLATES_LIMIT = 5;

	/**
	 * Chart ranges offered by the dashboard (days)
	 *
	 * @since 1.0.0
	 * @var int[]
	 */
	const CHART_RANGES = [ 30, 90, 180, 365, 730 ];

	/**
	 * Window for the "recent" stat cards (days)
	 *
	 * Seven days, matching the Quiz and Assignment dashboard cards.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const STATS_WINDOW_DAYS = 7;

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
			'/dashboard',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_dashboard' ],
				'permission_callback' => [ $this, 'can_view' ],
			]
		);

		register_rest_route(
			'ppcert/v1',
			'/dashboard/chart',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_chart' ],
				'permission_callback' => [ $this, 'can_view' ],
				'args'                => [
					'days' => [
						'type'    => 'integer',
						'default' => 90,
					],
				],
			]
		);
	}

	/**
	 * Capability check
	 *
	 * The dashboard reports issuance data, so it requires the same
	 * capability as the Certificates screen.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function can_view() {
		return current_user_can( PressPrimer_Certificate_Capabilities::CAP_VIEW_CERTIFICATES );
	}

	/**
	 * GET /dashboard: stats, recent certificates, top templates
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_dashboard() {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::STATS_WINDOW_DAYS * DAY_IN_SECONDS ) );

		$published = PressPrimer_Certificate_Template::query(
			[
				'status'   => 'published',
				'per_page' => 1,
			]
		);

		return new WP_REST_Response(
			[
				'success'       => true,
				'stats'         => [
					'total_certificates'  => PressPrimer_Certificate_Certificate::count_all(),
					'issued_recent'       => PressPrimer_Certificate_Certificate::count_issued_since( $cutoff ),
					'verified_recent'     => PressPrimer_Certificate_Certificate::count_events_since( 'verified', $cutoff ),
					'published_templates' => (int) $published['total'],
					'window_days'         => self::STATS_WINDOW_DAYS,
				],
				'recent'        => $this->recent_certificates(),
				'top_templates' => $this->top_templates(),
			],
			200
		);
	}

	/**
	 * GET /dashboard/chart: zero-filled daily awarded counts
	 *
	 * The series covers the requested range through today in UTC days -
	 * the same day boundaries the stored issued_at values use.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function get_chart( $request ) {
		$days = absint( $request->get_param( 'days' ) );

		if ( ! in_array( $days, self::CHART_RANGES, true ) ) {
			$days = 90;
		}

		$first_day = gmdate( 'Y-m-d', time() - ( ( $days - 1 ) * DAY_IN_SECONDS ) );
		$counts    = PressPrimer_Certificate_Certificate::get_daily_issue_counts( $first_day . ' 00:00:00' );

		$series = [];

		for ( $i = 0; $i < $days; $i++ ) {
			$day = gmdate( 'Y-m-d', strtotime( $first_day . ' 00:00:00 UTC' ) + ( $i * DAY_IN_SECONDS ) );

			$series[] = [
				'date'   => $day,
				'issued' => isset( $counts[ $day ] ) ? $counts[ $day ] : 0,
			];
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'days'    => $days,
				'data'    => $series,
			],
			200
		);
	}

	/**
	 * The newest certificates, shaped for the dashboard list
	 *
	 * @since 1.0.0
	 *
	 * @return array[]
	 */
	private function recent_certificates() {
		$result = PressPrimer_Certificate_Certificate::query( [ 'per_page' => self::RECENT_LIMIT ] );

		$titles = [];
		$rows   = [];

		foreach ( $result['items'] as $certificate ) {
			$template_id = (int) $certificate->template_id;

			if ( ! array_key_exists( $template_id, $titles ) ) {
				$template = PressPrimer_Certificate_Template::get( $template_id );

				$titles[ $template_id ] = $template ? (string) $template->title : '';
			}

			$recipient  = get_userdata( (int) $certificate->recipient_id );
			$credential = (string) $certificate->credential_id;

			$rows[] = [
				'id'             => (int) $certificate->id,
				'credential_id'  => PressPrimer_Certificate_Credential_ID_Service::format_display( $credential ),
				'recipient_name' => $recipient ? (string) $recipient->display_name : '',
				'template_title' => $titles[ $template_id ],
				'status'         => PressPrimer_Certificate_Certificate::effective_status( $certificate ),
				'issued_at'      => (string) $certificate->issued_at,
				'verify_url'     => ppcert_verification_url( $credential ),
			];
		}

		return $rows;
	}

	/**
	 * The top templates, shaped for the dashboard panel
	 *
	 * @since 1.0.0
	 *
	 * @return array[]
	 */
	private function top_templates() {
		$rows = [];

		foreach ( PressPrimer_Certificate_Certificate::get_top_templates( self::TOP_TEMPLATES_LIMIT ) as $row ) {
			$rows[] = [
				'template_id' => (int) $row->template_id,
				'title'       => isset( $row->title ) && null !== $row->title ? (string) $row->title : '',
				'total'       => (int) $row->total,
			];
		}

		return $rows;
	}
}
