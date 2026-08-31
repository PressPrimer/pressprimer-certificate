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
	 * Status filter matches the pill semantics (2.0, Feature 2.0-002
	 * FR-002): 'issued' means currently valid, 'expired' means issued
	 * but past expiry - the filter and the pill can never disagree.
	 *
	 * @return void
	 */
	public function test_list_status_filter_matches_pill_semantics() {
		$this->seed_certificate();
		$this->seed_certificate( [ 'expires_at' => '2020-01-01 00:00:00' ] );
		$this->seed_certificate( [ 'status' => 'revoked' ] );
		$this->seed_certificate( [ 'expires_at' => '2099-01-01 00:00:00' ] );

		$issued = $this->controller->get_list( new WP_REST_Request( [ 'status' => 'issued' ] ) )->get_data();
		$this->assertSame( 2, $issued['total'], "'Issued' excludes expired and revoked rows." );

		$expired = $this->controller->get_list( new WP_REST_Request( [ 'status' => 'expired' ] ) )->get_data();
		$this->assertSame( 1, $expired['total'], "'Expired' is issued rows past their expiry only." );

		$revoked = $this->controller->get_list( new WP_REST_Request( [ 'status' => 'revoked' ] ) )->get_data();
		$this->assertSame( 1, $revoked['total'] );
	}

	/**
	 * Title search (2.0, FR-001/TR-002): partial match against the
	 * search-only title column; pre-1.1 rows (NULL title) cannot match
	 * by title but stay findable through the template filter.
	 *
	 * @return void
	 */
	public function test_list_title_search_and_pre_1_1_fallback() {
		$this->seed_certificate( [ 'title' => 'Advanced Botany Certificate' ] );
		$this->seed_certificate( [ 'title' => 'Chemistry Certificate' ] );
		$pre_1_1 = $this->seed_certificate(); // No title column value.

		$botany = $this->controller->get_list( new WP_REST_Request( [ 'search' => 'botany' ] ) )->get_data();
		$this->assertSame( 1, $botany['total'] );

		$certificate = $this->controller->get_list( new WP_REST_Request( [ 'search' => 'certificate' ] ) )->get_data();
		$this->assertSame( 2, $certificate['total'], 'NULL-title rows never match a title search.' );

		$by_template = $this->controller->get_list( new WP_REST_Request( [ 'template_id' => $this->template_id ] ) )->get_data();
		$this->assertSame( 3, $by_template['total'], 'The template filter still finds pre-1.1 rows.' );
		$this->assertNotEmpty( $pre_1_1 );
	}

	/**
	 * Credential-shaped input resolves as an EXACT lookup (FR-001):
	 * 12 alphabet characters match one credential or nothing; partial
	 * fragments fall through to recipient/title matching instead.
	 *
	 * @return void
	 */
	public function test_list_credential_shaped_search_is_exact() {
		$this->seed_certificate(); // CRED0000000X-style ids from the seeder.

		// A shaped-but-nonexistent credential matches nothing - never a
		// partial credential scan.
		$response = $this->controller->get_list( new WP_REST_Request( [ 'search' => 'ZZZZ-ZZZZ-ZZZZ' ] ) )->get_data();
		$this->assertSame( 0, $response['total'] );

		// An unshaped fragment searches recipients/titles, not credentials.
		$fragment = $this->controller->get_list( new WP_REST_Request( [ 'search' => 'CRED0000' ] ) )->get_data();
		$this->assertSame( 0, $fragment['total'], 'Partial credential fragments do not scan credential ids.' );
	}

	/**
	 * Date range (FR-002): Y-m-d bounds interpreted in SITE timezone
	 * with inclusive day edges, converted to UTC for the query; filters
	 * combine with AND; a reversed range is a 400, not an empty list.
	 *
	 * @return void
	 */
	public function test_list_date_range_boundaries_and_combination() {
		// UTC+2 site: a certificate issued 22:30 UTC on July 1 is July 2
		// in site time, so a July 2 range must include it.
		$GLOBALS['ppcert_test_gmt_offset'] = 2 * 3600;

		$this->seed_certificate( [ 'issued_at' => '2026-07-01 22:30:00' ] );
		$this->seed_certificate(
			[
				'issued_at'   => '2026-07-01 10:00:00',
				'source_type' => 'ppq_quiz',
				'source_ref'  => '9',
			]
		);

		$july_second = $this->controller->get_list(
			new WP_REST_Request(
				[
					'issued_after'  => '2026-07-02',
					'issued_before' => '2026-07-02',
				]
			)
		)->get_data();
		$this->assertSame( 1, $july_second['total'], 'Site-timezone day includes the late-UTC row.' );

		$july_first = $this->controller->get_list(
			new WP_REST_Request(
				[
					'issued_after'  => '2026-07-01',
					'issued_before' => '2026-07-01',
				]
			)
		)->get_data();
		$this->assertSame( 1, $july_first['total'], 'The late-UTC row belongs to July 2, not July 1.' );

		// AND combination: the July 1 row is ppq_quiz-sourced.
		$combined = $this->controller->get_list(
			new WP_REST_Request(
				[
					'issued_before' => '2026-07-01',
					'source_type'   => 'manual',
				]
			)
		)->get_data();
		$this->assertSame( 0, $combined['total'], 'Filters combine with AND.' );

		unset( $GLOBALS['ppcert_test_gmt_offset'] );

		// Reversed range: a validation error, never an empty query.
		$reversed = $this->controller->get_list(
			new WP_REST_Request(
				[
					'issued_after'  => '2026-07-02',
					'issued_before' => '2026-07-01',
				]
			)
		);
		$this->assertInstanceOf( WP_Error::class, $reversed );
		$this->assertSame( 'ppcert_invalid_date_range', $reversed->get_error_code() );

		// Parameter validators.
		$this->assertTrue( $this->controller->validate_filter_date( '2026-07-01' ) );
		$this->assertFalse( $this->controller->validate_filter_date( '01/07/2026' ) );
		$this->assertFalse( $this->controller->validate_filter_date( '2026-07-01 12:00:00' ) );
		$this->assertTrue( $this->controller->validate_status_filter( 'expired' ) );
		$this->assertFalse( $this->controller->validate_status_filter( 'draft' ) );
	}

	/**
	 * Deleted-user and deleted-template cases (Feature 2.0-002 Edge
	 * Cases): a deleted recipient cannot match by name but the row stays
	 * findable by credential and template; soft-deleted templates with
	 * certificates appear in the filter options.
	 *
	 * @return void
	 */
	public function test_list_deleted_user_and_template_cases() {
		$this->seed_certificate( [ 'recipient_id' => 99 ] ); // User 99 does not exist.

		$rows       = $this->wpdb->rows( 'wp_ppcert_certificates' );
		$credential = (string) end( $rows )['credential_id'];

		$by_name = $this->controller->get_list( new WP_REST_Request( [ 'search' => 'ghost' ] ) )->get_data();
		$this->assertSame( 0, $by_name['total'], 'Deleted users cannot match a name search (documented).' );

		$by_credential = $this->controller->get_list( new WP_REST_Request( [ 'search' => $credential ] ) )->get_data();
		$this->assertSame( 1, $by_credential['total'], 'The credential still finds the row.' );

		// Soft-delete the template: its certificates stay findable and it
		// stays in the filter options, flagged deleted.
		$this->wpdb->mutate_row( 'wp_ppcert_templates', $this->template_id, [ 'deleted_at' => '2026-08-01 00:00:00' ] );

		$by_template = $this->controller->get_list( new WP_REST_Request( [ 'template_id' => $this->template_id ] ) )->get_data();
		$this->assertSame( 1, $by_template['total'] );

		$options = PressPrimer_Certificate_Template::get_certificate_filter_templates();
		$this->assertCount( 1, $options );
		$this->assertNotEmpty( $options[0]->deleted_at, 'Deleted-with-certificates templates surface for labeling.' );

		// A deleted template with NO certificates disappears from options.
		$empty_template = $this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'       => 'tpl-deleted-empty',
				'title'      => 'Never Used',
				'status'     => 'draft',
				'deleted_at' => '2026-08-01 00:00:00',
			]
		);

		$options = PressPrimer_Certificate_Template::get_certificate_filter_templates();
		$this->assertCount( 1, $options );
		$this->assertNotSame( $empty_template, (int) $options[0]->id );
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
