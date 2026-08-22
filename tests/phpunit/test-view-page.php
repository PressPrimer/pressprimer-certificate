<?php
/**
 * Certificate view page tests (Feature 005 FR-002/FR-003, Prompt 4.6)
 *
 * The /certificate/{credential_id}/ content builder across issued,
 * revoked, and expired states; preview PNG caching; the text-card
 * fallback on render failure; and the rewrite registration.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * View page test case
 *
 * @since 1.0.0
 */
class Test_View_Page extends TestCase {

	/**
	 * Credentials whose cached previews need cleanup.
	 *
	 * @var string[]
	 */
	private $preview_credentials = [];

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_wpdb();
		PressPrimer_Certificate_View_Page::reset();

		$GLOBALS['ppcert_test_options']  = [];
		$GLOBALS['ppcert_test_rewrites'] = [];
		$GLOBALS['ppcert_test_users']    = [
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];
	}

	/**
	 * Remove cached preview files created during a test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ( $this->preview_credentials as $credential ) {
			PressPrimer_Certificate_Preview_Service::delete( $credential );
		}

		$this->preview_credentials = [];
		parent::tearDown();
	}

	/**
	 * Build a hydrated-shape certificate object.
	 *
	 * @param array $overrides Property overrides.
	 * @return object
	 */
	private function certificate( array $overrides = [] ) {
		$snapshot = json_decode(
			(string) file_get_contents( PPCERT_PLUGIN_DIR . 'tests/phpunit/fixtures/sample-document.json' ),
			true
		);

		return (object) array_merge(
			[
				'id'              => 42,
				'credential_id'   => 'CRED00000042',
				'recipient_id'    => 7,
				'template_id'     => 1,
				'template_title'  => 'Completion Award',
				'status'          => 'issued',
				'issued_at'       => '2026-07-01 12:00:00',
				'expires_at'      => null,
				'layout_snapshot' => $snapshot,
				'merge_data'      => [ 'recipient.name' => 'Dana Whitfield' ],
			],
			$overrides
		);
	}

	/**
	 * The rewrite rule and query var register.
	 *
	 * @return void
	 */
	public function test_rewrite_and_query_var() {
		PressPrimer_Certificate_View_Page::register_rewrites();

		$this->assertArrayHasKey( '^certificate/([A-Za-z0-9\-]+)/?$', $GLOBALS['ppcert_test_rewrites'] );
		$this->assertSame(
			'index.php?ppcert_credential=$matches[1]',
			$GLOBALS['ppcert_test_rewrites']['^certificate/([A-Za-z0-9\-]+)/?$']['query']
		);
		$this->assertSame( 'top', $GLOBALS['ppcert_test_rewrites']['^certificate/([A-Za-z0-9\-]+)/?$']['position'] );

		$vars = PressPrimer_Certificate_View_Page::register_query_var( [ 'p' ] );
		$this->assertContains( 'ppcert_credential', $vars );
	}

	/**
	 * Issued certificate: preview image with alt text, the fact list,
	 * download and verify links, no status banner.
	 *
	 * @return void
	 */
	public function test_issued_certificate_renders_preview_and_actions() {
		$certificate                 = $this->certificate();
		$this->preview_credentials[] = 'CRED00000042';

		$html = PressPrimer_Certificate_View_Page::render_content( $certificate );

		$this->assertStringContainsString( 'ppcert-view__preview', $html );
		$this->assertStringContainsString( 'alt="Certificate preview for Dana Whitfield"', $html );
		$this->assertStringContainsString( 'CRED00000042.png', $html );

		$this->assertStringContainsString( '<dt>Recipient</dt><dd>Dana Whitfield</dd>', $html );
		$this->assertStringContainsString( '<dt>Certificate</dt><dd>Completion Award</dd>', $html );
		$this->assertStringContainsString( 'CRED-0000-0042', $html );

		$this->assertStringContainsString( 'ppcert/v1/certificates/CRED00000042/pdf', $html );
		$this->assertStringContainsString( 'ppcert_id=CRED00000042', $html );

		$this->assertStringNotContainsString( 'ppcert-view__banner', $html );

		// The preview PNG landed in the uploads cache.
		$path = PressPrimer_Certificate_Preview_Service::preview_path( 'CRED00000042' );
		$this->assertFileExists( $path );

		$info = getimagesize( $path );
		$this->assertSame( 'image/png', $info['mime'] );
	}

	/**
	 * A second render reuses the cached PNG instead of re-rendering.
	 *
	 * @return void
	 */
	public function test_preview_is_cached() {
		$certificate                 = $this->certificate();
		$this->preview_credentials[] = 'CRED00000042';

		PressPrimer_Certificate_View_Page::render_content( $certificate );

		$path  = PressPrimer_Certificate_Preview_Service::preview_path( 'CRED00000042' );
		$mtime = filemtime( $path );

		// A cached hit must not touch the file - even with a snapshot
		// that could no longer render.
		$certificate->layout_snapshot = null;
		$html                         = PressPrimer_Certificate_View_Page::render_content( $certificate );

		clearstatcache();
		$this->assertStringContainsString( 'CRED00000042.png', $html );
		$this->assertSame( $mtime, filemtime( $path ) );
	}

	/**
	 * Revoked: notice banner instead of the preview, no download link,
	 * verify link stays.
	 *
	 * @return void
	 */
	public function test_revoked_certificate_hides_preview_and_download() {
		$html = PressPrimer_Certificate_View_Page::render_content(
			$this->certificate( [ 'status' => 'revoked' ] )
		);

		$this->assertStringContainsString( 'ppcert-view__banner--revoked', $html );
		$this->assertStringContainsString( 'This certificate has been revoked.', $html );

		$this->assertStringNotContainsString( 'ppcert-view__preview', $html );
		$this->assertStringNotContainsString( '/pdf', $html );

		$this->assertStringContainsString( 'ppcert_id=CRED00000042', $html );
	}

	/**
	 * Expired: banner plus preview and download (the artifact existed;
	 * verification is where invalidity is authoritative).
	 *
	 * @return void
	 */
	public function test_expired_certificate_keeps_preview_and_download() {
		$this->preview_credentials[] = 'CRED00000042';

		$html = PressPrimer_Certificate_View_Page::render_content(
			$this->certificate( [ 'expires_at' => '2020-01-01 00:00:00' ] )
		);

		$this->assertStringContainsString( 'ppcert-view__banner--expired', $html );
		$this->assertStringContainsString( 'This certificate has expired.', $html );
		$this->assertStringContainsString( 'ppcert-view__preview', $html );
		$this->assertStringContainsString( 'ppcert/v1/certificates/CRED00000042/pdf', $html );

		// A past expiry labels its detail row "Expired", not "Expires".
		$this->assertStringContainsString( '<dt>Expired</dt>', $html );
		$this->assertStringNotContainsString( '<dt>Expires</dt>', $html );
	}

	/**
	 * Render failure: the text-card fallback with the certificate facts,
	 * and the logging action fires.
	 *
	 * @return void
	 */
	public function test_render_failure_falls_back_to_text_card() {
		$captured = [];

		add_action(
			'ppcert_preview_render_failed',
			function ( $error, $certificate_id ) use ( &$captured ) {
				$captured[] = [ $error, $certificate_id ];
			},
			10,
			2
		);

		// An invalid snapshot cannot rasterize.
		$html = PressPrimer_Certificate_View_Page::render_content(
			$this->certificate( [ 'layout_snapshot' => [ 'layout_schema_version' => 1 ] ] )
		);

		$this->assertStringContainsString( 'ppcert-view__card', $html );
		$this->assertStringContainsString( 'Dana Whitfield', $html );
		$this->assertStringContainsString( 'Completion Award', $html );
		$this->assertStringNotContainsString( '<img', $html );

		// Download stays available - the PDF route renders independently.
		$this->assertStringContainsString( 'ppcert/v1/certificates/CRED00000042/pdf', $html );

		$this->assertCount( 1, $captured );
		$this->assertInstanceOf( WP_Error::class, $captured[0][0] );
		$this->assertSame( 42, $captured[0][1] );
	}

	/**
	 * Escaping: an attacker-influenced recipient name renders inert.
	 *
	 * @return void
	 */
	public function test_recipient_name_is_escaped() {
		$html = PressPrimer_Certificate_View_Page::render_content(
			$this->certificate(
				[
					'status'     => 'revoked',
					'merge_data' => [ 'recipient.name' => '<script>alert(1)</script>' ],
				]
			)
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	/**
	 * The recipient name falls back to the live user record when the
	 * snapshot has none.
	 *
	 * @return void
	 */
	public function test_recipient_name_falls_back_to_user_record() {
		$html = PressPrimer_Certificate_View_Page::render_content(
			$this->certificate(
				[
					'status'     => 'revoked',
					'merge_data' => [],
				]
			)
		);

		$this->assertStringContainsString( '<dt>Recipient</dt><dd>Dana Whitfield</dd>', $html );
	}

	/**
	 * The stored certificate name (Feature 1.1-006) replaces the template
	 * title in the details, escaped.
	 *
	 * @return void
	 */
	public function test_stored_certificate_name_leads_the_page() {
		$certificate             = $this->certificate();
		$certificate->merge_data = [
			'recipient.full_name' => 'Dana Whitfield',
			'certificate.title'   => 'Botany 101 <b>Certificate</b>',
		];

		$html = PressPrimer_Certificate_View_Page::render_content( $certificate );

		$this->assertStringContainsString( '<dt>Certificate</dt><dd>Botany 101 &lt;b&gt;Certificate&lt;/b&gt;</dd>', $html );
		$this->assertStringNotContainsString( '<dd>Completion Award</dd>', $html );
	}
}
