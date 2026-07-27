<?php
/**
 * Verification page tests
 *
 * The [ppcert_verify] shortcode, server-side result rendering with
 * exhaustive escaping, idempotent page creation, the deleted-page
 * notice, and the canonical verification URL builder.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Verification page test case
 *
 * @since 1.0.0
 */
class Test_Verification_Page extends TestCase {

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_transients();
		ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options']    = [];
		$GLOBALS['ppcert_test_posts']      = [];
		$GLOBALS['ppcert_test_localized']  = [];
		$GLOBALS['ppcert_test_shortcodes'] = [];
		$_SERVER['REMOTE_ADDR']            = '203.0.113.20';
		unset( $_GET['ppcert_id'] );
	}

	/**
	 * The shortcode renders the form, the aria-live region, and localizes
	 * the REST URL.
	 *
	 * @return void
	 */
	public function test_shortcode_renders_form() {
		$html = PressPrimer_Certificate_Verification_Page::render_shortcode();

		$this->assertStringContainsString( 'class="ppcert-verify__form"', $html );
		$this->assertStringContainsString( 'name="ppcert_id"', $html );
		$this->assertStringContainsString( 'aria-live="polite"', $html );
		$this->assertStringContainsString( 'role="status"', $html );

		$localized = $GLOBALS['ppcert_test_localized']['ppcert_verify_data'];
		$this->assertStringContainsString( 'ppcert/v1/verify/', $localized['rest_url'] );
	}

	/**
	 * A ppcert_id query param renders the result server-side (direct
	 * links and the no-JS fallback), through the shared lookup path.
	 *
	 * @return void
	 */
	public function test_direct_link_renders_server_side() {
		global $wpdb;

		$credential = PressPrimer_Certificate_Credential_ID_Service::generate();

		$wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'id'    => 40,
				'title' => 'Advanced Botany Certification',
			]
		);
		$wpdb->seed_row(
			PressPrimer_Certificate_Certificate::table(),
			[
				'credential_id'        => $credential,
				'template_id'          => 40,
				'recipient_id'         => 7,
				'issued_by'            => 1,
				'source_type'          => 'manual',
				'status'               => 'issued',
				'layout_snapshot_json' => '{}',
				'merge_data_json'      => '{"recipient.full_name":"Dana Whitfield"}',
				'issued_at'            => '2026-07-18 14:30:00',
				'expires_at'           => null,
			]
		);

		$_GET['ppcert_id'] = strtolower( PressPrimer_Certificate_Credential_ID_Service::format_display( $credential ) );

		$html = PressPrimer_Certificate_Verification_Page::render_shortcode();

		$this->assertStringContainsString( 'ppcert-verify__status--valid', $html );
		$this->assertStringContainsString( 'Dana Whitfield', $html );
		$this->assertStringContainsString( 'Advanced Botany Certification', $html );
		$this->assertStringContainsString( 'value="' . $credential . '"', $html, 'Input pre-fills with the normalized ID' );
	}

	/**
	 * Every rendered value is escaped - the page displays
	 * attacker-influenced data by definition.
	 *
	 * @return void
	 */
	public function test_result_rendering_escapes_everything() {
		$html = PressPrimer_Certificate_Verification_Page::render_result(
			[
				'valid'          => true,
				'status'         => 'valid',
				'recipient_name' => 'Dana <script>alert(1)</script>',
				'subject'        => 'Botany & "Advanced" <b>Cert</b>',
				'issuer_name'    => "O'Sunrise <img src=x>",
				'issued_at'      => '2026-07-18T14:30:00Z',
				'expires_at'     => null,
			]
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( 'Botany &amp; &quot;Advanced&quot;', $html );
	}

	/**
	 * All four result states render their headings.
	 *
	 * @return void
	 */
	public function test_result_states() {
		$base = [
			'recipient_name' => 'Dana',
			'subject'        => 'Botany',
			'issuer_name'    => 'Sunrise',
			'issued_at'      => '2026-07-18T14:30:00Z',
			'expires_at'     => null,
		];

		$valid = PressPrimer_Certificate_Verification_Page::render_result( array_merge( $base, [ 'valid' => true, 'status' => 'valid' ] ) );
		$this->assertStringContainsString( 'ppcert-verify__status--valid', $valid );
		$this->assertStringContainsString( 'Dana', $valid );

		$expired = PressPrimer_Certificate_Verification_Page::render_result( array_merge( $base, [ 'valid' => false, 'status' => 'expired', 'expires_at' => '2026-01-01T00:00:00Z' ] ) );
		$this->assertStringContainsString( 'ppcert-verify__status--expired', $expired );

		// A past date reads "Expired"; a future date reads "Expires".
		$this->assertStringContainsString( '<dt>Expired</dt>', $expired );
		$future = PressPrimer_Certificate_Verification_Page::render_result( array_merge( $base, [ 'valid' => true, 'status' => 'valid', 'expires_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + YEAR_IN_SECONDS ) ] ) );
		$this->assertStringContainsString( '<dt>Expires</dt>', $future );
		$this->assertStringNotContainsString( '<dt>Expired</dt>', $future );

		$revoked = PressPrimer_Certificate_Verification_Page::render_result( array_merge( $base, [ 'valid' => false, 'status' => 'revoked' ] ) );
		$this->assertStringContainsString( 'ppcert-verify__status--revoked', $revoked );
		$this->assertStringNotContainsString( 'Dana', $revoked, 'Revoked reveals no certificate details' );

		$not_found = PressPrimer_Certificate_Verification_Page::render_result( [ 'valid' => false, 'status' => 'not_found' ] );
		$this->assertStringContainsString( 'No certificate found', $not_found );
	}

	/**
	 * Page creation is idempotent: the stored ID short-circuits, a
	 * trashed page is replaced.
	 *
	 * @return void
	 */
	public function test_create_page_idempotent() {
		$first = PressPrimer_Certificate_Verification_Page::create_page();
		$this->assertGreaterThan( 0, $first );

		$post = get_post( $first );
		$this->assertSame( '[ppcert_verify]', $post->post_content );
		$this->assertSame( $first, $GLOBALS['ppcert_test_options']['ppcert_settings']['verification_page_id'] );

		$second = PressPrimer_Certificate_Verification_Page::create_page();
		$this->assertSame( $first, $second, 'Re-activation reuses the existing page' );

		// Trashed page: a fresh one is created and stored.
		$GLOBALS['ppcert_test_posts'][ $first ]->post_status = 'trash';
		$third = PressPrimer_Certificate_Verification_Page::create_page();
		$this->assertNotSame( $first, $third );
	}

	/**
	 * The deleted-page notice appears only when a configured page is
	 * missing or trashed.
	 *
	 * @return void
	 */
	public function test_deleted_page_notice() {
		// No page configured: silent.
		ob_start();
		PressPrimer_Certificate_Verification_Page::maybe_deleted_page_notice();
		$this->assertSame( '', ob_get_clean() );

		// Healthy page: silent.
		$page_id = PressPrimer_Certificate_Verification_Page::create_page();
		ob_start();
		PressPrimer_Certificate_Verification_Page::maybe_deleted_page_notice();
		$this->assertSame( '', ob_get_clean() );

		// Deleted page: notice.
		unset( $GLOBALS['ppcert_test_posts'][ $page_id ] );
		ob_start();
		PressPrimer_Certificate_Verification_Page::maybe_deleted_page_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'notice-warning', $notice );
		$this->assertStringContainsString( 'verification page has been deleted', $notice );
	}

	/**
	 * ppcert_verification_url: page permalink + query arg, home fallback,
	 * normalized credential.
	 *
	 * @return void
	 */
	public function test_verification_url_builder() {
		// No page configured: home fallback.
		$url = ppcert_verification_url( '7q4m-k9p2-xt3a' );
		$this->assertSame( 'https://test.example/?ppcert_id=7Q4MK9P2XT3A', $url );

		// Configured page: its permalink.
		$page_id = PressPrimer_Certificate_Verification_Page::create_page();
		$url     = ppcert_verification_url( '7Q4MK9P2XT3A' );
		$this->assertStringContainsString( 'page_id=' . $page_id, $url );
		$this->assertStringContainsString( 'ppcert_id=7Q4MK9P2XT3A', $url );
	}

	/**
	 * The ppcert_verification_page_data filter fires with the documented
	 * ( array $data, object|null $certificate ) signature.
	 *
	 * @return void
	 */
	public function test_page_data_filter_contract() {
		$captured = [];

		add_filter(
			'ppcert_verification_page_data',
			static function ( ...$args ) use ( &$captured ) {
				$captured[]        = $args;
				$args[0]['banner'] = 'Branded';
				return $args[0];
			},
			10,
			2
		);

		$html = PressPrimer_Certificate_Verification_Page::render_shortcode();

		$this->assertCount( 1, $captured );
		$this->assertCount( 2, $captured[0] );
		$this->assertIsArray( $captured[0][0] );
		$this->assertArrayHasKey( 'prefill', $captured[0][0] );
		$this->assertNotEmpty( $html );
	}
}
