<?php
/**
 * Issuance seam tests (2.1)
 *
 * Exercises Feature 2.1-004 FR-007 (one-off issuance end to end: the
 * row, the snapshot name, the PDF, the certificate page, verification,
 * the email to the stored address, the list, search, and the privacy
 * tools), FR-008 (merge overrides before the name pattern, and the
 * preview route), FR-010 (backdated created_at, the any-source lookup,
 * duplicate_scope), and Feature 2.1-005 FR-001 (duplicate ref lists).
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Issuance seams test case
 *
 * @since 2.1.0
 */
class Test_Issuance_Seams extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Template printing the display name and the credential.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Template printing nothing personal, with a name pattern.
	 *
	 * @var int
	 */
	private $plain_template_id;

	/**
	 * Seed templates and a recipient.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_transients();
		$this->wpdb = ppcert_tests_reset_wpdb();

		PressPrimer_Certificate_View_Page::reset();

		$GLOBALS['ppcert_test_options']      = [ 'ppcert_settings' => [ 'email_issued_enabled' => 1 ] ];
		$GLOBALS['ppcert_test_mail']         = [];
		$GLOBALS['ppcert_test_current_user'] = 0;
		$GLOBALS['ppcert_test_user_caps']    = [];
		$GLOBALS['ppcert_test_bloginfo']     = [
			'name'        => 'Sunrise Training Academy',
			'admin_email' => 'admin@sunrise.example',
		];
		$GLOBALS['ppcert_test_users']        = [
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana Whitfield',
				'first_name'   => 'Dana',
				'last_name'    => 'Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];
		$GLOBALS['ppcert_test_user_meta'] = [];
		$_SERVER['REMOTE_ADDR']           = '203.0.113.40';

		$this->template_id = $this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'                  => 'tmpl-issue-seams-1',
				'title'                 => 'Completion Certificate',
				'status'                => 'published',
				'author_id'             => 1,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => ppcert_tests_seams_layout(),
				'deleted_at'            => null,
			]
		);

		$plain = json_decode( ppcert_tests_seams_layout(), true );

		$plain['elements'][0]['props']['token'] = '{{source.course_title}}';

		$this->plain_template_id = $this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'                  => 'tmpl-issue-seams-2',
				'title'                 => 'Workshop Certificate',
				'status'                => 'published',
				'author_id'             => 1,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => (string) wp_json_encode( $plain ),
				'settings_json'         => (string) wp_json_encode( [ 'certificate_name' => '{{source.course_title}} Certificate' ] ),
				'deleted_at'            => null,
			]
		);
	}

	/**
	 * Clear the request address.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/**
	 * Issue a one-off.
	 *
	 * @param array $recipient Recipient context.
	 * @param array $overrides Issue arg overrides.
	 * @return int|WP_Error
	 */
	private function one_off( array $recipient, array $overrides = [] ) {
		return PressPrimer_Certificate_Issuance_Service::issue(
			array_merge(
				[
					'template_id'  => $this->template_id,
					'recipient_id' => 0,
					'source_type'  => 'one_off',
					'issued_by'    => 1,
					'context'      => [ 'recipient' => $recipient ],
				],
				$overrides
			)
		);
	}

	/**
	 * Issue to user 7.
	 *
	 * @param array $overrides Issue arg overrides.
	 * @return int|WP_Error
	 */
	private function issue( array $overrides = [] ) {
		return PressPrimer_Certificate_Issuance_Service::issue(
			array_merge(
				[
					'template_id'  => $this->template_id,
					'recipient_id' => 7,
					'source_type'  => 'ppq_quiz',
					'source_ref'   => '89',
					'issued_by'    => 1,
				],
				$overrides
			)
		);
	}

	/**
	 * The stored row for an id.
	 *
	 * @param int $id Certificate id.
	 * @return array
	 */
	private function row( $id ) {
		foreach ( $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) as $row ) {
			if ( (int) $row['id'] === (int) $id ) {
				return $row;
			}
		}

		$this->fail( 'Certificate row ' . $id . ' not found.' );
	}

	/**
	 * A one-off is a full credential: row, snapshot, PDF, page,
	 * verification, and the award email to the stored address.
	 *
	 * @return void
	 */
	public function test_one_off_end_to_end() {
		$id = $this->one_off(
			[
				'name'  => 'Dana Reyes',
				'email' => 'dana.reyes@example.test',
			]
		);

		$this->assertIsInt( $id );

		$row   = $this->row( $id );
		$merge = json_decode( $row['merge_data_json'], true );

		$this->assertSame( 0, (int) $row['recipient_id'] );
		$this->assertSame( 'one_off', $row['source_type'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $row['source_ref'] );
		$this->assertSame( 'dana.reyes@example.test', $row['recipient_email'] );
		$this->assertSame( 'Dana Reyes', $merge['recipient.display_name'] );
		$this->assertSame( 'Dana Reyes', $merge['recipient.full_name'], 'The name is always snapshotted' );

		$events = $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() );
		$this->assertSame( 'issued', $events[0]['event_type'] );
		$this->assertSame( [ 'source_type' => 'one_off' ], json_decode( (string) $events[0]['meta_json'], true ) );

		// The award email went to the stored address.
		$this->assertCount( 1, $GLOBALS['ppcert_test_mail'] );
		$this->assertSame( 'dana.reyes@example.test', $GLOBALS['ppcert_test_mail'][0]['to'] );
		$this->assertStringContainsString( 'Dana Reyes', $GLOBALS['ppcert_test_mail'][0]['body'] );

		// The PDF.
		$path = PressPrimer_Certificate_PDF_Renderer::render_certificate( $id );
		$this->assertIsString( $path );
		$this->assertStringEndsWith( PressPrimer_Certificate_Certificate::pdf_filename( (object) $row ), $path );
		$this->assertSame( '%PDF-', (string) file_get_contents( $path, false, null, 0, 5 ) );
		wp_delete_file( $path );

		// The certificate page and verification read the snapshot name.
		$certificate = PressPrimer_Certificate_Certificate::get_for_verification( $row['credential_id'] );
		$this->assertStringContainsString( '<dd>Dana Reyes</dd>', PressPrimer_Certificate_View_Page::render_content( $certificate ) );
		PressPrimer_Certificate_Preview_Service::delete( $row['credential_id'] );

		$result = PressPrimer_Certificate_REST_Verification_Controller::lookup( $row['credential_id'] );
		$this->assertSame( 'valid', $result['status'] );
		$this->assertSame( 'Dana Reyes', $result['recipient_name'] );

		// The email seam resolves for resends and reminders too.
		$this->assertSame(
			[
				'email' => 'dana.reyes@example.test',
				'name'  => 'Dana Reyes',
			],
			PressPrimer_Certificate_Email_Service::recipient_for( $certificate, 'expiry_reminder' )
		);
	}

	/**
	 * The name is snapshotted even when the design prints none; no email
	 * means no award email; two one-offs never collapse.
	 *
	 * @return void
	 */
	public function test_one_off_without_email_or_name_token() {
		$first  = $this->one_off( [ 'name' => 'Sam Okafor' ], [ 'template_id' => $this->plain_template_id ] );
		$second = $this->one_off( [ 'name' => 'Sam Okafor' ], [ 'template_id' => $this->plain_template_id ] );

		$this->assertIsInt( $first );
		$this->assertNotSame( $first, $second, 'Unique refs: one-offs never suppress each other' );

		$row = $this->row( $first );
		$this->assertSame( 'Sam Okafor', json_decode( $row['merge_data_json'], true )['recipient.full_name'] );
		$this->assertNull( $row['recipient_email'] );
		$this->assertCount( 0, $GLOBALS['ppcert_test_mail'], 'No address, no award email' );
		$this->assertNull( PressPrimer_Certificate_Email_Service::recipient_for( (object) $row, 'issued' ) );
		$this->assertSame( 'Sam Okafor', PressPrimer_Certificate_Certificate::recipient_name( (object) $row ) );
	}

	/**
	 * Rejections: a one-off needs a name and a valid email when given;
	 * recipient 0 is refused for every other source type.
	 *
	 * @return void
	 */
	public function test_one_off_rejections() {
		$this->assertSame( 'ppcert_invalid_recipient', $this->one_off( [ 'name' => '  ' ] )->get_error_code() );
		$this->assertSame( 'ppcert_invalid_recipient', $this->one_off( [] )->get_error_code() );
		$this->assertSame(
			'ppcert_invalid_recipient_email',
			$this->one_off(
				[
					'name'  => 'Dana Reyes',
					'email' => 'not-an-address',
				]
			)->get_error_code()
		);
		$this->assertSame( 'ppcert_invalid_recipient', $this->issue( [ 'recipient_id' => 0 ] )->get_error_code() );
		$this->assertCount( 0, $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) );
	}

	/**
	 * The ppcert_email_recipient filter can redirect or veto; an invalid
	 * address counts as no recipient.
	 *
	 * @return void
	 */
	public function test_email_recipient_filter() {
		$row = (object) $this->row( $this->issue() );

		$this->assertSame( 'dana@example.test', PressPrimer_Certificate_Email_Service::recipient_for( $row, 'issued' )['email'] );

		add_filter(
			'ppcert_email_recipient',
			static function ( $recipient, $certificate, $type ) {
				return 'expiry_reminder' === $type ? null : [
					'email' => 'manager@example.test',
					'name'  => 'Manager',
				];
			},
			10,
			3
		);

		$this->assertSame( 'manager@example.test', PressPrimer_Certificate_Email_Service::recipient_for( $row, 'issued' )['email'] );
		$this->assertNull( PressPrimer_Certificate_Email_Service::recipient_for( $row, 'expiry_reminder' ) );

		ppcert_tests_reset_hooks();
		add_filter(
			'ppcert_email_recipient',
			static function () {
				return [ 'email' => 'nope' ];
			}
		);

		$this->assertNull( PressPrimer_Certificate_Email_Service::recipient_for( $row, 'issued' ) );
	}

	/**
	 * The Certificates list finds one-offs by stored email and by the
	 * snapshot name (one-off rows only), and offers the source filter.
	 *
	 * @return void
	 */
	public function test_list_search_and_source_filter() {
		$account = $this->issue();
		$one_off = $this->one_off(
			[
				'name'  => 'Zoë Brennan',
				'email' => 'zoe@example.test',
			]
		);

		$ids = static function ( $result ) {
			return array_map( 'intval', array_column( $result['items'], 'id' ) );
		};

		$this->assertSame( [ $one_off ], $ids( PressPrimer_Certificate_Certificate::query( [ 'search' => 'zoe@example' ] ) ) );
		$this->assertSame( [ $one_off ], $ids( PressPrimer_Certificate_Certificate::query( [ 'search' => 'Zoë Bren' ] ) ), 'Non-ASCII names match the JSON-encoded snapshot' );
		$this->assertSame( [ $one_off ], $ids( PressPrimer_Certificate_Certificate::query( [ 'source_type' => 'one_off' ] ) ) );

		// Snapshot JSON is searched on one-off rows only: an account
		// certificate's snapshot never matches by name this way.
		$this->assertNotContains( $account, $ids( PressPrimer_Certificate_Certificate::query( [ 'search' => 'ppq_quiz' ] ) ) );

		$this->assertTrue( PressPrimer_Certificate_Certificate::has_source_type( 'one_off' ) );
		$this->assertFalse( PressPrimer_Certificate_Certificate::has_source_type( 'lms_learndash' ) );
	}

	/**
	 * Privacy tools match one-offs by email, with or without an account.
	 *
	 * @return void
	 */
	public function test_privacy_matches_one_offs_by_email() {
		$account = $this->issue();
		$one_off = $this->one_off(
			[
				'name'  => 'Dana Reyes',
				'email' => 'Dana.Reyes@Example.test',
			]
		);

		// An email with no account still exports its one-offs.
		$export = PressPrimer_Certificate_Privacy::export( 'dana.reyes@example.test' );
		$this->assertCount( 1, $export['data'] );
		$this->assertSame( 'ppcert-certificate-' . $one_off, $export['data'][0]['item_id'] );

		// A one-off issued to an account holder's address exports with
		// their account's certificates.
		$this->one_off(
			[
				'name'  => 'Dana Whitfield',
				'email' => 'dana@example.test',
			]
		);
		$this->assertCount( 2, PressPrimer_Certificate_Privacy::export( 'dana@example.test' )['data'] );

		$erased = PressPrimer_Certificate_Privacy::erase( 'dana.reyes@example.test' );
		$this->assertSame( 1, $erased['items_removed'] );

		$left = array_map( 'intval', array_column( $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ), 'id' ) );
		$this->assertNotContains( $one_off, $left );
		$this->assertContains( $account, $left );
	}

	/**
	 * Merge overrides feed the name pattern; disallowed keys drop.
	 *
	 * @return void
	 */
	public function test_merge_overrides_feed_the_name() {
		$id = $this->one_off(
			[ 'name' => 'Dana Reyes' ],
			[
				'template_id'     => $this->plain_template_id,
				'merge_overrides' => [
					'source.course_title'       => 'Field Botany <b>Workshop</b>',
					'certificate.credential_id' => 'FORGED',
					'site.name'                 => 'Elsewhere',
					'recipient.meta.unused'     => 'not on this template',
				],
			]
		);

		$row   = $this->row( $id );
		$merge = json_decode( $row['merge_data_json'], true );

		$this->assertSame( 'Field Botany Workshop', $merge['source.course_title'] );
		$this->assertSame( 'Field Botany Workshop Certificate', $merge['certificate.title'], 'Overrides apply before the name pattern' );
		$this->assertSame( 'Field Botany Workshop Certificate', $row['title'] );
		$this->assertNotSame( 'FORGED', isset( $merge['certificate.credential_id'] ) ? $merge['certificate.credential_id'] : '' );
		$this->assertArrayNotHasKey( 'recipient.meta.unused', $merge );

		$template = PressPrimer_Certificate_Template::get( $this->plain_template_id );
		$this->assertSame(
			[ 'source.course_title' => str_repeat( 'x', 500 ) ],
			PressPrimer_Certificate_Issuance_Service::clean_merge_overrides( $template, [ 'source.course_title' => str_repeat( 'x', 600 ) ] )
		);
		$this->assertSame( [], PressPrimer_Certificate_Issuance_Service::clean_merge_overrides( $template, 'not an array' ) );
	}

	/**
	 * The preview route: overrides layer over samples; issuers may
	 * preview a published template's saved design, never unsaved
	 * layouts or drafts.
	 *
	 * @return void
	 */
	public function test_preview_permission_and_overrides() {
		$controller = new PressPrimer_Certificate_REST_Templates_Controller();
		$request    = new WP_REST_Request( [ 'id' => $this->plain_template_id ] );

		$GLOBALS['ppcert_test_user_caps'] = [ PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES ];

		$this->assertTrue( $controller->can_preview_template( $request ) );
		$this->assertFalse( $controller->can_preview_template( new WP_REST_Request( [ 'id' => $this->plain_template_id, 'layout' => [] ] ) ), 'Unsaved layouts stay with managers' );

		add_filter(
			'ppcert_user_can_issue_from_template',
			static function () {
				return new WP_Error( 'ppcert_school_scope', 'Not your issuer.' );
			}
		);
		$this->assertFalse( $controller->can_preview_template( $request ), 'The per-template award rule applies' );
		ppcert_tests_reset_hooks();

		$this->wpdb->mutate_row( PressPrimer_Certificate_Template::table(), $this->plain_template_id, [ 'status' => 'draft' ] );
		$this->assertFalse( $controller->can_preview_template( $request ), 'Drafts stay with managers' );

		$GLOBALS['ppcert_test_user_caps'] = [];
		$this->assertFalse( $controller->can_preview_template( $request ) );

		$GLOBALS['ppcert_test_user_caps'] = [ PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES ];
		$this->assertTrue( $controller->can_preview_template( $request ), 'Managers preview drafts' );

		// Overrides layer over the samples before the name pattern.
		$template = PressPrimer_Certificate_Template::get( $this->plain_template_id );
		$merge    = PressPrimer_Certificate_REST_Templates_Controller::preview_merge_data(
			$template,
			[
				'source.course_title' => 'Typed Workshop',
				'site.name'           => 'Elsewhere',
			]
		);

		$this->assertSame( 'Typed Workshop', $merge['source.course_title'] );
		$this->assertSame( 'Typed Workshop Certificate', $merge['certificate.title'] );
		$this->assertNotSame( 'Elsewhere', $merge['site.name'] );
		$samples = PressPrimer_Certificate_REST_Templates_Controller::preview_merge_data( $template );
		$this->assertNotSame( 'Typed Workshop Certificate', $samples['certificate.title'], 'Without overrides: the 2.0 samples' );

		$response = $controller->preview_template(
			new WP_REST_Request(
				[
					'id'              => $this->plain_template_id,
					'merge_overrides' => [ 'source.course_title' => 'Typed Workshop' ],
				]
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $response, is_wp_error( $response ) ? $response->get_error_message() : '' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['url'] );
	}

	/**
	 * A backdated issue date never moves created_at/updated_at.
	 *
	 * @return void
	 */
	public function test_backdated_issue_keeps_real_created_at() {
		$before = gmdate( 'Y-m-d H:i:s', time() - 5 );
		$row    = $this->row( $this->issue( [ 'issued_at' => '2025-01-10 08:00:00' ] ) );

		$this->assertSame( '2025-01-10 08:00:00', $row['issued_at'] );
		$this->assertGreaterThanOrEqual( $before, $row['created_at'] );
		$this->assertSame( $row['created_at'], $row['updated_at'] );

		$template = PressPrimer_Certificate_Template::get( $this->template_id );
		$this->assertNull( PressPrimer_Certificate_Issuance_Service::compute_expires_at( $template, '2025-01-10 08:00:00' ) );
	}

	/**
	 * Ref lists: any entry suppresses (null entries match NULL refs);
	 * a single string keeps the 2.0 query; the row keeps the real ref.
	 *
	 * @return void
	 */
	public function test_duplicate_ref_lists() {
		$first = $this->issue( [ 'source_ref' => '89' ] );

		add_filter(
			'ppcert_issue_duplicate_ref',
			static function ( $ref ) {
				return [ 'lesson-3', null, '', 89, [ 'nested' ] ];
			}
		);

		$second = $this->issue( [ 'source_ref' => '90' ] );
		$this->assertSame( $first, $second, 'The list entry 89 suppresses' );

		ppcert_tests_reset_hooks();
		$null_ref = $this->issue( [ 'source_ref' => null ] );

		add_filter(
			'ppcert_issue_duplicate_ref',
			static function () {
				return [ 'nothing-here', null ];
			}
		);

		$this->assertSame( $null_ref, $this->issue( [ 'source_ref' => '91' ] ), 'A null entry matches the NULL-ref row' );

		ppcert_tests_reset_hooks();
		add_filter(
			'ppcert_issue_duplicate_ref',
			static function () {
				return [ 'nothing-here', 'nor-here' ];
			}
		);

		$fresh = $this->issue( [ 'source_ref' => '92' ] );
		$this->assertNotSame( $first, $fresh );
		$this->assertSame( '92', $this->row( $fresh )['source_ref'], 'The row records the real ref' );

		$this->assertSame( [ null ], PressPrimer_Certificate_Certificate::normalize_refs( [] ) );
		$this->assertCount( 20, PressPrimer_Certificate_Certificate::normalize_refs( range( 1, 30 ) ) );
	}

	/**
	 * Any-source lookups and duplicate_scope 'template'.
	 *
	 * @return void
	 */
	public function test_any_source_and_template_scope() {
		$quiz = $this->issue( [ 'source_ref' => '89' ] );

		$this->assertSame( $quiz, (int) ppcert_find_certificate( 7, $this->template_id, null )->id, 'No ref, any source: the newest from the template' );
		$this->assertSame( $quiz, (int) ppcert_find_certificate( 7, $this->template_id, null, [ 'x', '89' ] )->id );
		$this->assertNull( ppcert_find_certificate( 7, $this->template_id, null, 'x' ) );
		$this->assertNull( ppcert_find_certificate( 7, $this->template_id, 'lms_learndash', '89' ), 'A typed lookup still keys on the type' );

		// A different source normally issues a second certificate...
		$lms = $this->issue(
			[
				'source_type' => 'lms_learndash',
				'source_ref'  => '12',
			]
		);
		$this->assertNotSame( $quiz, $lms );

		// ...but duplicate_scope 'template' returns what they hold.
		$scoped = $this->issue(
			[
				'source_type'     => 'lms_tutorlms',
				'source_ref'      => '13',
				'duplicate_scope' => 'template',
			]
		);
		$this->assertSame( $lms, $scoped, 'The newest certificate from the template suppresses' );

		// Revoked certificates never suppress.
		foreach ( [ $quiz, $lms ] as $id ) {
			$this->wpdb->mutate_row( PressPrimer_Certificate_Certificate::table(), $id, [ 'status' => 'revoked' ] );
		}

		$this->assertNull( ppcert_find_certificate( 7, $this->template_id, null ) );
		$this->assertNotSame(
			$lms,
			$this->issue(
				[
					'source_type'     => 'lms_tutorlms',
					'source_ref'      => '13',
					'duplicate_scope' => 'template',
				]
			)
		);
	}
}
