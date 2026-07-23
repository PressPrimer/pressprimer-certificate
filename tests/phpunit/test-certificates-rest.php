<?php
/**
 * Certificates admin REST + model query tests (Feature 003 FR-003,
 * TR-002, Prompt 4.5)
 *
 * The admin list's filters/search/pagination, capability gating on
 * every route, the recipient picker's LIMIT, manual issuance
 * end-to-end, and the duplicate 409 -> "Issue anyway" force flow.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Controller double: captures the stream instead of exiting
 *
 * @since 1.0.0
 */
class PPCert_Test_Streaming_Certificates_Controller extends PressPrimer_Certificate_REST_Certificates_Controller {

	/**
	 * The captured stream call, or null.
	 *
	 * @var array|null
	 */
	public $streamed = null;

	/**
	 * Capture instead of streaming + exiting.
	 *
	 * @param string $pdf_path Rendered temp file path.
	 * @param string $filename Download filename.
	 */
	protected function stream_pdf( $pdf_path, $filename ) {
		$this->streamed = [
			'path'     => $pdf_path,
			'filename' => $filename,
		];
	}
}

/**
 * Certificates REST test case
 *
 * @since 1.0.0
 */
class Test_Certificates_REST extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Controller under test.
	 *
	 * @var PressPrimer_Certificate_REST_Certificates_Controller
	 */
	private $controller;

	/**
	 * Published template id.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Reset state, seed users + a published template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_user_caps']    = true;
		$GLOBALS['ppcert_test_current_user'] = 1;

		$GLOBALS['ppcert_test_users'] = [
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana Whitfield',
				'first_name'   => 'Dana',
				'last_name'    => 'Whitfield',
				'user_email'   => 'dana@example.test',
				'user_login'   => 'dana',
			],
			8 => (object) [
				'ID'           => 8,
				'display_name' => 'Marco Reyes',
				'first_name'   => 'Marco',
				'last_name'    => 'Reyes',
				'user_email'   => 'marco@example.test',
				'user_login'   => 'marco',
			],
		];

		$layout = [
			'layout_schema_version' => 1,
			'page'                  => [
				'size'        => 'a4',
				'orientation' => 'landscape',
				'width'       => 842,
				'height'      => 595,
			],
			'background'            => [ 'color' => '#ffffff' ],
			'elements'              => [],
		];

		$this->template_id = $this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'                  => 'tpl-cert-admin',
				'title'                 => 'Completion Award',
				'status'                => 'published',
				'author_id'             => 1,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => wp_json_encode( $layout ),
				'updated_at'            => '2026-07-01 00:00:00',
				'deleted_at'            => null,
			]
		);

		$this->controller = new PressPrimer_Certificate_REST_Certificates_Controller();
	}

	/**
	 * Seed a certificate row.
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
					'uuid'                  => 'cert-uuid-' . $seq,
					'credential_id'         => 'CRED' . str_pad( (string) $seq, 8, '0', STR_PAD_LEFT ),
					'template_id'           => $this->template_id,
					'recipient_id'          => 7,
					'issued_by'             => 1,
					'source_type'           => 'manual',
					'source_ref'            => null,
					'status'                => 'issued',
					'layout_schema_version' => 1,
					'layout_snapshot_json'  => '{"layout_schema_version":1,"elements":[]}',
					'merge_data_json'       => '{}',
					'issued_at'             => '2026-07-0' . min( 9, $seq ) . ' 12:00:00',
					'expires_at'            => null,
				],
				$overrides
			)
		);
	}

	/**
	 * Filters and search narrow the list; pagination reports the
	 * unpaged total; ordering is newest-first.
	 *
	 * @return void
	 */
	public function test_list_filters_search_and_pagination() {
		$this->seed_certificate();
		$this->seed_certificate(
			[
				'recipient_id' => 8,
				'source_type'  => 'ppq_quiz',
				'source_ref'   => '7',
			]
		);
		$this->seed_certificate( [ 'status' => 'revoked' ] );

		// Unfiltered: all three, newest first.
		$response = $this->controller->get_list( new WP_REST_Request( [] ) );
		$data     = $response->get_data();
		$this->assertSame( 3, $data['total'] );
		$this->assertSame( 'CRED00000003', str_replace( '-', '', $data['items'][0]['credential_id'] ) );

		// Status filter.
		$response = $this->controller->get_list( new WP_REST_Request( [ 'status' => 'revoked' ] ) );
		$this->assertSame( 1, $response->get_data()['total'] );

		// Source filter.
		$response = $this->controller->get_list( new WP_REST_Request( [ 'source_type' => 'ppq_quiz' ] ) );
		$data     = $response->get_data();
		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'Marco Reyes', $data['items'][0]['recipient']['name'] );

		// Recipient search by name fragment.
		$response = $this->controller->get_list( new WP_REST_Request( [ 'search' => 'marco' ] ) );
		$this->assertSame( 1, $response->get_data()['total'] );

		// Credential search, any input form (dashes/case tolerated).
		$response = $this->controller->get_list( new WP_REST_Request( [ 'search' => 'cred-0000 0002' ] ) );
		$this->assertSame( 1, $response->get_data()['total'] );

		// Pagination: page 2 of size 2 carries the third row.
		$response = $this->controller->get_list(
			new WP_REST_Request(
				[
					'per_page' => 2,
					'page'     => 2,
				]
			)
		);
		$data = $response->get_data();
		$this->assertSame( 3, $data['total'] );
		$this->assertCount( 1, $data['items'] );
	}

	/**
	 * Capability gating: list needs view, issuance + picker need issue,
	 * detail allows the recipient themselves.
	 *
	 * @return void
	 */
	public function test_capability_gating() {
		$id = $this->seed_certificate();

		$GLOBALS['ppcert_test_user_caps'] = [];
		$this->assertFalse( $this->controller->can_view() );
		$this->assertFalse( $this->controller->can_issue() );

		// Own certificate: recipient 7 may read their detail.
		$GLOBALS['ppcert_test_current_user'] = 7;
		$this->assertTrue(
			$this->controller->can_view_detail( new WP_REST_Request( [ 'id' => $id ] ) )
		);

		// A different user without capabilities may not.
		$GLOBALS['ppcert_test_current_user'] = 8;
		$this->assertFalse(
			$this->controller->can_view_detail( new WP_REST_Request( [ 'id' => $id ] ) )
		);

		$GLOBALS['ppcert_test_user_caps'] = [ 'ppcert_view_certificates', 'ppcert_issue_certificates' ];
		$this->assertTrue( $this->controller->can_view() );
		$this->assertTrue( $this->controller->can_issue() );
	}

	/**
	 * Manual issuance end-to-end: row, snapshot, credential in the 201
	 * response; the duplicate returns 409 with the existing credential;
	 * force issues anyway.
	 *
	 * @return void
	 */
	public function test_manual_issue_duplicate_and_force() {
		$request = new WP_REST_Request(
			[
				'template_id'  => $this->template_id,
				'recipient_id' => 7,
			]
		);

		$response = $this->controller->issue( $request );
		$this->assertSame( 201, $response->get_status() );

		$item = $response->get_data();
		$this->assertSame( 'manual', $item['source_type'] );
		$this->assertSame( 7, $item['recipient']['id'] );
		$this->assertNotSame( '', $item['credential_id'] );

		$rows = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 1, (int) $rows[0]['issued_by'] );
		$this->assertNotEmpty( $rows[0]['layout_snapshot_json'] );

		// Duplicate without force: 409 + the existing credential.
		$duplicate = $this->controller->issue( $request );
		$this->assertInstanceOf( WP_Error::class, $duplicate );
		$this->assertSame( 'ppcert_duplicate_certificate', $duplicate->get_error_code() );
		$this->assertSame( 409, $duplicate->get_error_data()['status'] );
		$this->assertNotSame( '', $duplicate->get_error_data()['credential_id'] );
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_certificates' ) );

		// Issue anyway.
		$request_force = new WP_REST_Request(
			[
				'template_id'  => $this->template_id,
				'recipient_id' => 7,
				'force'        => true,
			]
		);

		$forced = $this->controller->issue( $request_force );
		$this->assertSame( 201, $forced->get_status() );
		$this->assertCount( 2, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * Issuing from a draft template fails with a 400-mapped error.
	 *
	 * @return void
	 */
	public function test_issue_requires_published_template() {
		$draft_id = $this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'                  => 'tpl-draft',
				'title'                 => 'Draft',
				'status'                => 'draft',
				'author_id'             => 1,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => '{"layout_schema_version":1,"page":{"size":"a4","orientation":"landscape","width":842,"height":595},"background":{"color":"#ffffff"},"elements":[]}',
				'updated_at'            => '2026-07-01 00:00:00',
				'deleted_at'            => null,
			]
		);

		$result = $this->controller->issue(
			new WP_REST_Request(
				[
					'template_id'  => $draft_id,
					'recipient_id' => 7,
				]
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ppcert_template_not_published', $result->get_error_code() );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_certificates' ) );
	}

	/**
	 * Detail: staff or the recipient; 404 for missing rows; effective
	 * status computes expiry.
	 *
	 * @return void
	 */
	public function test_detail_shape_and_effective_status() {
		$id = $this->seed_certificate(
			[
				'expires_at' => '2020-01-01 00:00:00',
			]
		);

		$response = $this->controller->get_detail( new WP_REST_Request( [ 'id' => $id ] ) );
		$item     = $response->get_data();

		$this->assertSame( 'expired', $item['status'] );
		$this->assertSame( 'Completion Award', $item['template']['title'] );
		$this->assertArrayNotHasKey( 'layout_snapshot', $item );
		$this->assertArrayNotHasKey( 'merge_data', $item );

		$missing = $this->controller->get_detail( new WP_REST_Request( [ 'id' => 99999 ] ) );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 404, $missing->get_error_data()['status'] );
	}

	/**
	 * Public download (005 FR-004): a renderable credential streams a
	 * real PDF with the expected filename and records the event with an
	 * anonymous actor.
	 *
	 * @return void
	 */
	public function test_public_download_streams_pdf_and_records_event() {
		$snapshot = (string) file_get_contents( PPCERT_PLUGIN_DIR . 'tests/phpunit/fixtures/sample-document.json' );

		$this->seed_certificate(
			[
				'credential_id'        => 'D0WN00000001',
				'layout_snapshot_json' => $snapshot,
			]
		);

		// Logged out: public-by-URL, anonymous event actor.
		$GLOBALS['ppcert_test_current_user'] = 0;
		$GLOBALS['ppcert_test_user_caps']    = [];

		$controller = new PPCert_Test_Streaming_Certificates_Controller();
		$controller->download_pdf( new WP_REST_Request( [ 'credential_id' => 'D0WN-0000-0001' ] ) );

		$this->assertNotNull( $controller->streamed );
		$this->assertSame( 'certificate-d0wn00000001.pdf', $controller->streamed['filename'] );

		$this->assertFileExists( $controller->streamed['path'] );
		$head = (string) file_get_contents( $controller->streamed['path'], false, null, 0, 5 );
		$this->assertSame( '%PDF-', $head );
		unlink( $controller->streamed['path'] );

		$events = $this->wpdb->rows( 'wp_ppcert_events' );
		$this->assertCount( 1, $events );
		$this->assertSame( 'downloaded', $events[0]['event_type'] );
		$this->assertNull( $events[0]['actor_id'] );
	}

	/**
	 * Public download: unknown credentials 404, revoked certificates 410.
	 *
	 * @return void
	 */
	public function test_public_download_rejects_missing_and_revoked() {
		$controller = new PPCert_Test_Streaming_Certificates_Controller();

		$missing = $controller->download_pdf( new WP_REST_Request( [ 'credential_id' => 'XXXX00000000' ] ) );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 404, $missing->get_error_data()['status'] );

		$snapshot = (string) file_get_contents( PPCERT_PLUGIN_DIR . 'tests/phpunit/fixtures/sample-document.json' );

		$this->seed_certificate(
			[
				'credential_id'        => 'REV000000001',
				'status'               => 'revoked',
				'layout_snapshot_json' => $snapshot,
			]
		);

		$revoked = $controller->download_pdf( new WP_REST_Request( [ 'credential_id' => 'REV000000001' ] ) );
		$this->assertInstanceOf( WP_Error::class, $revoked );
		$this->assertSame( 'ppcert_certificate_revoked', $revoked->get_error_code() );
		$this->assertSame( 410, $revoked->get_error_data()['status'] );

		$this->assertNull( $controller->streamed );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_events' ) );
	}

	/**
	 * The recipient picker searches name/email and caps at 20.
	 *
	 * @return void
	 */
	public function test_user_search_matches_and_limit() {
		// Fill past the limit.
		for ( $i = 100; $i < 130; $i++ ) {
			$GLOBALS['ppcert_test_users'][ $i ] = (object) [
				'ID'           => $i,
				'display_name' => 'Student ' . $i,
				'user_email'   => 'student' . $i . '@example.test',
				'user_login'   => 'student' . $i,
			];
		}

		$response = $this->controller->search_users( new WP_REST_Request( [ 'search' => 'student' ] ) );
		$items    = $response->get_data();

		$this->assertCount( 20, $items );
		$this->assertArrayHasKey( 'email', $items[0] );

		// Email fragment match.
		$response = $this->controller->search_users( new WP_REST_Request( [ 'search' => 'dana@example' ] ) );
		$items    = $response->get_data();

		$this->assertCount( 1, $items );
		$this->assertSame( 'Dana Whitfield', $items[0]['name'] );
	}
}
