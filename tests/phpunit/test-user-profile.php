<?php
/**
 * User profile certificates section tests (Phase 5B item 9)
 *
 * Capability/self gating, escaped markup with verification and
 * download links, and the revoked-certificate download suppression.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * User profile section test case
 *
 * @since 1.0.0
 */
class Test_User_Profile extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Section under test.
	 *
	 * @var PressPrimer_Certificate_Admin_User_Profile
	 */
	private $section;

	/**
	 * Reset state, seed a template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options'] = [];

		$this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'       => 'tpl-profile',
				'title'      => 'Advanced Botany <b>Certification</b>',
				'status'     => 'published',
				'deleted_at' => null,
			]
		);

		$this->section = new PressPrimer_Certificate_Admin_User_Profile();
	}

	/**
	 * Seed a certificate for user 7.
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
					'uuid'                 => 'cert-profile-' . $seq,
					'credential_id'        => 'PR0F' . str_pad( (string) $seq, 8, '0', STR_PAD_LEFT ),
					'template_id'          => 1,
					'recipient_id'         => 7,
					'source_type'          => 'manual',
					'status'               => 'issued',
					'layout_snapshot_json' => '{"layout_schema_version":1,"elements":[]}',
					'merge_data_json'      => '{}',
					'issued_at'            => '2026-07-01 12:00:00',
					'expires_at'           => null,
				],
				$overrides
			)
		);
	}

	/**
	 * The section lists certificates with verify + download links, and
	 * escapes attacker-influenced titles.
	 *
	 * @return void
	 */
	public function test_section_markup() {
		$this->seed_certificate();
		$this->seed_certificate( [ 'status' => 'revoked' ] );

		$certificates = PressPrimer_Certificate_Certificate::get_recent_for_recipient( 7, 10 );
		$html         = $this->section->build_section( $certificates );

		$this->assertStringContainsString( 'PressPrimer Certificates', $html );
		$this->assertStringContainsString( 'PR0F-0000-0001', $html );
		$this->assertStringContainsString( 'ppcert_id=PR0F00000001', $html, 'Verification link' );
		$this->assertStringContainsString( 'ppcert/v1/certificates/PR0F00000001/pdf', $html, 'Download link' );

		// Template titles escape.
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;', $html );

		// The revoked row keeps Verify but drops the download.
		$this->assertStringContainsString( 'Revoked', $html );
		$this->assertStringNotContainsString( 'PR0F00000002/pdf', $html );
		$this->assertStringContainsString( 'ppcert_id=PR0F00000002', $html );
	}

	/**
	 * Ordering and pagination: newest earned first, ten per page, and
	 * the page parameter clamps.
	 *
	 * @return void
	 */
	public function test_order_and_pagination() {
		// Twelve certificates on distinct days (oldest seeded first).
		for ( $i = 1; $i <= 12; $i++ ) {
			$this->seed_certificate(
				[ 'issued_at' => sprintf( '2026-06-%02d 12:00:00', $i ) ]
			);
		}

		// Page 1: the ten newest, most recent at the top.
		$page_one = PressPrimer_Certificate_Certificate::get_recent_for_recipient( 7, 10, 0 );
		$this->assertCount( 10, $page_one );
		$this->assertStringStartsWith( '2026-06-12', $page_one[0]->issued_at );
		$this->assertStringStartsWith( '2026-06-03', $page_one[9]->issued_at );

		// Page 2: the remaining two oldest.
		$page_two = PressPrimer_Certificate_Certificate::get_recent_for_recipient( 7, 10, 10 );
		$this->assertCount( 2, $page_two );
		$this->assertStringStartsWith( '2026-06-02', $page_two[0]->issued_at );

		$this->assertSame( 12, PressPrimer_Certificate_Certificate::count_for_recipient( 7 ) );

		// The rendered section carries pagination and clamps the page.
		$GLOBALS['ppcert_test_user_caps']    = [ 'ppcert_view_certificates' ];
		$GLOBALS['ppcert_test_current_user'] = 1;

		$_GET['ppcert_page'] = '99';
		ob_start();
		$this->section->render_section( (object) [ 'ID' => 7 ] );
		$html = ob_get_clean();
		unset( $_GET['ppcert_page'] );

		// Clamped to the last page: the two oldest rows.
		$this->assertStringContainsString( '2026', $html );
		$this->assertStringContainsString( 'page-numbers', $html, 'Pagination renders' );
		$this->assertSame( 2, substr_count( $html, '<tr>' ) - 1, 'Last page holds the remaining rows' );
	}

	/**
	 * Gating: staff and the profile owner see the section; others do
	 * not, and empty profiles render nothing.
	 *
	 * @return void
	 */
	public function test_render_gating() {
		$this->seed_certificate();
		$user = (object) [ 'ID' => 7 ];

		// A viewer with the capability.
		$GLOBALS['ppcert_test_user_caps']    = [ 'ppcert_view_certificates' ];
		$GLOBALS['ppcert_test_current_user'] = 1;
		ob_start();
		$this->section->render_section( $user );
		$this->assertStringContainsString( 'PressPrimer Certificates', ob_get_clean() );

		// The user themselves, without the capability.
		$GLOBALS['ppcert_test_user_caps']    = [];
		$GLOBALS['ppcert_test_current_user'] = 7;
		ob_start();
		$this->section->render_section( $user );
		$this->assertStringContainsString( 'PressPrimer Certificates', ob_get_clean() );

		// A third party without the capability sees nothing.
		$GLOBALS['ppcert_test_current_user'] = 8;
		ob_start();
		$this->section->render_section( $user );
		$this->assertSame( '', ob_get_clean() );

		// No certificates: nothing renders even for staff.
		$GLOBALS['ppcert_test_user_caps']    = [ 'ppcert_view_certificates' ];
		$GLOBALS['ppcert_test_current_user'] = 1;
		ob_start();
		$this->section->render_section( (object) [ 'ID' => 99 ] );
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * The profile section shows the stored certificate name (1.1-006).
	 *
	 * @return void
	 */
	public function test_section_shows_stored_certificate_name() {
		$this->seed_certificate(
			[
				'recipient_id'    => 7,
				'merge_data_json' => '{"certificate.title":"Botany 101 Certificate"}',
			]
		);

		ob_start();
		$this->section->render_section( (object) [ 'ID' => 7 ] );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Botany 101 Certificate', $html );
	}
}
