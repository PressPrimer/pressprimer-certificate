<?php
/**
 * Dashboard REST controller tests (Phase 5B item 1)
 *
 * Capability gating, the stats aggregates, recent-certificates
 * shaping, top-templates ranking, and the zero-filled chart series.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Dashboard controller test case
 *
 * @since 1.0.0
 */
class Test_Dashboard_REST extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Controller under test.
	 *
	 * @var PressPrimer_Certificate_REST_Dashboard_Controller
	 */
	private $controller;

	/**
	 * Reset state, seed templates and a recipient.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options']   = [];
		$GLOBALS['ppcert_test_user_caps'] = true;
		$GLOBALS['ppcert_test_users']     = [
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana Whitfield',
			],
		];

		$this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'id'         => 1,
				'uuid'       => 'tpl-dash-1',
				'title'      => 'Formal Landscape',
				'status'     => 'published',
				'updated_at' => '2026-07-01 00:00:00',
				'deleted_at' => null,
			]
		);

		$this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'id'         => 2,
				'uuid'       => 'tpl-dash-2',
				'title'      => 'Modern Landscape',
				'status'     => 'draft',
				'updated_at' => '2026-07-02 00:00:00',
				'deleted_at' => null,
			]
		);

		$this->controller = new PressPrimer_Certificate_REST_Dashboard_Controller();
	}

	/**
	 * Seed a certificate.
	 *
	 * @param array $overrides Column overrides.
	 * @return int Row id.
	 */
	private function seed_certificate( array $overrides = [] ) {
		static $seq = 0;
		$seq++;

		return $this->wpdb->seed_row(
			'wp_ppcert_certificates',
			array_merge(
				[
					'uuid'                 => 'cert-dash-' . $seq,
					'credential_id'        => 'DASH' . str_pad( (string) $seq, 8, '0', STR_PAD_LEFT ),
					'template_id'          => 1,
					'recipient_id'         => 7,
					'source_type'          => 'manual',
					'status'               => 'issued',
					'layout_snapshot_json' => '{"layout_schema_version":1,"elements":[]}',
					'merge_data_json'      => '{}',
					'issued_at'            => gmdate( 'Y-m-d H:i:s' ),
					'expires_at'           => null,
				],
				$overrides
			)
		);
	}

	/**
	 * The endpoints require the view-certificates capability.
	 *
	 * @return void
	 */
	public function test_capability_gate() {
		$GLOBALS['ppcert_test_user_caps'] = [];
		$this->assertFalse( $this->controller->can_view() );

		$GLOBALS['ppcert_test_user_caps'] = [ 'ppcert_view_certificates' ];
		$this->assertTrue( $this->controller->can_view() );
	}

	/**
	 * Stats: totals, the 7-day window (sibling-dashboard parity),
	 * verified events, and the published-template count.
	 *
	 * @return void
	 */
	public function test_dashboard_stats() {
		$now = time();

		$this->seed_certificate();
		$this->seed_certificate( [ 'issued_at' => gmdate( 'Y-m-d H:i:s', $now - ( 3 * DAY_IN_SECONDS ) ) ] );
		$this->seed_certificate( [ 'issued_at' => gmdate( 'Y-m-d H:i:s', $now - ( 10 * DAY_IN_SECONDS ) ) ] );

		// Two verified events inside the window; one outside; one other
		// event type inside (must not count).
		$this->wpdb->seed_row(
			'wp_ppcert_events',
			[
				'certificate_id' => 1,
				'event_type'     => 'verified',
				'created_at'     => gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ),
			]
		);
		$this->wpdb->seed_row(
			'wp_ppcert_events',
			[
				'certificate_id' => 2,
				'event_type'     => 'verified',
				'created_at'     => gmdate( 'Y-m-d H:i:s', $now - ( 5 * DAY_IN_SECONDS ) ),
			]
		);
		$this->wpdb->seed_row(
			'wp_ppcert_events',
			[
				'certificate_id' => 3,
				'event_type'     => 'verified',
				'created_at'     => gmdate( 'Y-m-d H:i:s', $now - ( 12 * DAY_IN_SECONDS ) ),
			]
		);
		$this->wpdb->seed_row(
			'wp_ppcert_events',
			[
				'certificate_id' => 1,
				'event_type'     => 'downloaded',
				'created_at'     => gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ),
			]
		);

		$response = $this->controller->get_dashboard();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 3, $data['stats']['total_certificates'] );
		$this->assertSame( 2, $data['stats']['issued_recent'] );
		$this->assertSame( 2, $data['stats']['verified_recent'] );
		$this->assertSame( 1, $data['stats']['published_templates'] );
		$this->assertSame( 7, $data['stats']['window_days'] );
	}

	/**
	 * Recent certificates: newest first, display-formatted credentials,
	 * recipient names, template titles, and verification links.
	 *
	 * @return void
	 */
	public function test_recent_certificates() {
		$now = time();

		$this->seed_certificate(
			[
				'credential_id' => 'RCNT0000000A',
				'issued_at'     => gmdate( 'Y-m-d H:i:s', $now - ( 2 * DAY_IN_SECONDS ) ),
			]
		);
		$this->seed_certificate(
			[
				'credential_id' => 'RCNT0000000B',
				'template_id'   => 99,
				'recipient_id'  => 12345,
				'status'        => 'revoked',
				'issued_at'     => gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ),
			]
		);

		$response = $this->controller->get_dashboard();
		$recent   = $response->get_data()['recent'];

		$this->assertCount( 2, $recent );

		// Newest first.
		$this->assertSame( 'RCNT-0000-000B', $recent[0]['credential_id'] );
		$this->assertSame( 'RCNT-0000-000A', $recent[1]['credential_id'] );

		// The deleted template and unknown recipient degrade gracefully.
		$this->assertSame( '', $recent[0]['template_title'] );
		$this->assertSame( '', $recent[0]['recipient_name'] );
		$this->assertSame( 'revoked', $recent[0]['status'] );

		$this->assertSame( 'Formal Landscape', $recent[1]['template_title'] );
		$this->assertSame( 'Dana Whitfield', $recent[1]['recipient_name'] );
		$this->assertSame( 'issued', $recent[1]['status'] );
		$this->assertStringContainsString( 'ppcert_id=RCNT0000000A', $recent[1]['verify_url'] );
	}

	/**
	 * Top templates: ranked by count, deterministic tie-break on id,
	 * deleted templates surfaced with an empty title.
	 *
	 * @return void
	 */
	public function test_top_templates() {
		$this->seed_certificate();
		$this->seed_certificate();
		$this->seed_certificate( [ 'template_id' => 2 ] );
		$this->seed_certificate( [ 'template_id' => 99 ] );

		$response = $this->controller->get_dashboard();
		$top      = $response->get_data()['top_templates'];

		$this->assertCount( 3, $top );

		$this->assertSame( 1, $top[0]['template_id'] );
		$this->assertSame( 'Formal Landscape', $top[0]['title'] );
		$this->assertSame( 2, $top[0]['total'] );

		// One-certificate tie: template 2 before the deleted 99.
		$this->assertSame( 2, $top[1]['template_id'] );
		$this->assertSame( 99, $top[2]['template_id'] );
		$this->assertSame( '', $top[2]['title'] );
	}

	/**
	 * Chart: one zero-filled point per day through today, counts on the
	 * seeded days, and out-of-range days excluded.
	 *
	 * @return void
	 */
	public function test_chart_series() {
		$now   = time();
		$today = gmdate( 'Y-m-d' );

		$this->seed_certificate( [ 'issued_at' => $today . ' 08:00:00' ] );
		$this->seed_certificate( [ 'issued_at' => $today . ' 09:30:00' ] );
		$this->seed_certificate( [ 'issued_at' => gmdate( 'Y-m-d', $now - ( 5 * DAY_IN_SECONDS ) ) . ' 10:00:00' ] );
		$this->seed_certificate( [ 'issued_at' => gmdate( 'Y-m-d', $now - ( 40 * DAY_IN_SECONDS ) ) . ' 10:00:00' ] );

		$response = $this->controller->get_chart( new WP_REST_Request( [ 'days' => 30 ] ) );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 30, $data['days'] );
		$this->assertCount( 30, $data['data'] );

		// The series ends today and starts 29 days back.
		$this->assertSame( $today, $data['data'][29]['date'] );
		$this->assertSame( gmdate( 'Y-m-d', $now - ( 29 * DAY_IN_SECONDS ) ), $data['data'][0]['date'] );

		$by_date = [];

		foreach ( $data['data'] as $point ) {
			$by_date[ $point['date'] ] = $point['issued'];
		}

		$this->assertSame( 2, $by_date[ $today ] );
		$this->assertSame( 1, $by_date[ gmdate( 'Y-m-d', $now - ( 5 * DAY_IN_SECONDS ) ) ] );

		// Every other day zero-fills; the 40-day-old row never appears.
		$this->assertSame( 3, array_sum( $by_date ) );
	}

	/**
	 * Chart: an unknown range falls back to 90 days.
	 *
	 * @return void
	 */
	public function test_chart_range_fallback() {
		$response = $this->controller->get_chart( new WP_REST_Request( [ 'days' => 45 ] ) );
		$data     = $response->get_data();

		$this->assertSame( 90, $data['days'] );
		$this->assertCount( 90, $data['data'] );

		$missing = $this->controller->get_chart( new WP_REST_Request() );

		$this->assertSame( 90, $missing->get_data()['days'] );
	}
}
