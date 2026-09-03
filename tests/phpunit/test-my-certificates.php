<?php
/**
 * My Certificates list tests
 *
 * The front-end [ppcert_my_certificates] shortcode: login gating,
 * empty state, row fields (title, status pill, earned, expiry,
 * verify/view/download links), the revoked download suppression, and
 * escaping.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * My Certificates test case
 *
 * @since 1.0.0
 */
class Test_My_Certificates extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Reset state; user 7 is logged in; seed a template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options']      = [];
		$GLOBALS['ppcert_test_current_user'] = 7;

		$this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'       => 'tpl-wallet',
				'title'      => 'Advanced Botany <b>Certification</b>',
				'status'     => 'published',
				'deleted_at' => null,
			]
		);
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
					'uuid'                 => 'cert-wallet-' . $seq,
					'credential_id'        => 'WALT' . str_pad( (string) $seq, 8, '0', STR_PAD_LEFT ),
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
	 * Logged-out visitors get the login prompt; no data leaks.
	 *
	 * @return void
	 */
	public function test_logged_out_prompt() {
		$this->seed_certificate();
		$GLOBALS['ppcert_test_current_user'] = 0;

		$html = PressPrimer_Certificate_My_Certificates::render_shortcode();

		$this->assertStringContainsString( 'ppcert-my-certificates__login', $html );
		$this->assertStringNotContainsString( 'WALT', $html );
	}

	/**
	 * A recipient with no certificates gets the empty state.
	 *
	 * @return void
	 */
	public function test_empty_state() {
		$html = PressPrimer_Certificate_My_Certificates::render_shortcode();

		$this->assertStringContainsString( 'ppcert-my-certificates__empty', $html );
	}

	/**
	 * Rows carry the five promised fields, escape titles, and suppress
	 * downloads for revoked certificates.
	 *
	 * @return void
	 */
	public function test_rows_fields_and_revoked_download_suppression() {
		// Explicit credentials from the Crockford alphabet (no I/L/O/U -
		// normalize() rewrites confusables, which is not under test here).
		$valid_id = $this->seed_certificate(
			[
				'credential_id' => 'CERTAAAA0001',
				'expires_at'    => '2027-07-01 12:00:00',
			]
		);
		$this->seed_certificate(
			[
				'credential_id' => 'CERTAAAA0002',
				'status'        => 'revoked',
			]
		);

		$html = PressPrimer_Certificate_My_Certificates::render_shortcode();

		// Title escaped (attacker-influenced surface).
		$this->assertStringContainsString( 'Advanced Botany &lt;b&gt;Certification&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>Certification</b>', $html );

		// Status pills for both rows.
		$this->assertStringContainsString( 'ppcert-pill--valid', $html );
		$this->assertStringContainsString( 'ppcert-pill--revoked', $html );

		// Earned + expiry dates render.
		$this->assertStringContainsString( 'Earned', $html );
		$this->assertStringContainsString( 'Expires', $html );

		// Verify links for both; view-page link on the title.
		$this->assertStringContainsString( 'ppcert_id=CERTAAAA0001', $html );
		$this->assertStringContainsString( 'ppcert_id=CERTAAAA0002', $html );
		$this->assertStringContainsString( '/certificate/CERT-AAAA-0001/', $html );

		// Download only for the non-revoked certificate.
		$this->assertStringContainsString( 'certificates/CERTAAAA0001/pdf', $html );
		$this->assertStringNotContainsString( 'certificates/CERTAAAA0002/pdf', $html );

		// Verify opens in a new tab, announced to screen readers.
		$this->assertStringContainsString( 'target="_blank" rel="noopener"', $html );
		$this->assertStringContainsString( '(opens in a new tab)', $html );

		$this->assertNotEmpty( $valid_id );
	}

	/**
	 * Name sort orders alphabetically by certificate name with
	 * deleted-template rows last.
	 *
	 * @return void
	 */
	public function test_name_sort() {
		$this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'       => 'tpl-wallet-2',
				'title'      => 'Zebra Handling Basics',
				'status'     => 'published',
				'deleted_at' => null,
			]
		);

		$this->seed_certificate( [ 'credential_id' => 'NAMEAAAA0001' ] ); // Advanced Botany (template 1).
		$this->seed_certificate(
			[
				'credential_id' => 'NAMEAAAA0002',
				'template_id'   => 2,
			]
		); // Zebra Handling.
		$this->seed_certificate(
			[
				'credential_id' => 'NAMEAAAA0003',
				'template_id'   => 999,
			]
		); // Deleted template.

		$_GET['ppcert_sort'] = 'name';
		$html                = PressPrimer_Certificate_My_Certificates::render_shortcode();
		unset( $_GET['ppcert_sort'] );

		$pos_botany  = strpos( $html, 'NAME-AAAA-0001' );
		$pos_zebra   = strpos( $html, 'NAME-AAAA-0002' );
		$pos_deleted = strpos( $html, 'NAME-AAAA-0003' );

		$this->assertNotFalse( $pos_botany );
		$this->assertLessThan( $pos_zebra, $pos_botany, 'Alphabetical: Advanced Botany before Zebra' );
		$this->assertLessThan( $pos_deleted, $pos_zebra, 'Deleted-template rows last' );
	}

	/**
	 * Eleven certificates paginate at ten per page.
	 *
	 * @return void
	 */
	public function test_pagination_appears_past_ten() {
		for ( $i = 0; $i < 11; $i++ ) {
			$this->seed_certificate();
		}

		$html = PressPrimer_Certificate_My_Certificates::render_shortcode();

		$this->assertStringContainsString( 'ppcert-my-certificates__pagination', $html );
		$this->assertSame( 10, substr_count( $html, 'ppcert-my-certificates__item' ) );
	}

	/**
	 * The control row renders with the active chips marked, and the
	 * status filter narrows the list.
	 *
	 * @return void
	 */
	public function test_controls_and_status_filter() {
		$this->seed_certificate( [ 'credential_id' => 'CTRAAAAA0001' ] );
		$this->seed_certificate(
			[
				'credential_id' => 'CTRAAAAA0002',
				'expires_at'    => '2020-01-01 00:00:00',
			]
		);

		// Default view: controls present, All + Newest active, both rows.
		$html = PressPrimer_Certificate_My_Certificates::render_shortcode();
		$this->assertStringContainsString( 'ppcert-my-certificates__controls', $html );
		$this->assertStringContainsString( 'aria-current="true"', $html );
		$this->assertSame( 2, substr_count( $html, 'ppcert-my-certificates__item' ) );

		// Expired filter: only the expired certificate remains, and its
		// past date labels as "Expired" rather than "Expires".
		$_GET['ppcert_status'] = 'expired';
		$html                  = PressPrimer_Certificate_My_Certificates::render_shortcode();
		$this->assertSame( 1, substr_count( $html, 'ppcert-my-certificates__item' ) );
		$this->assertStringContainsString( 'CTRA-AAAA-0002', $html );
		$this->assertStringNotContainsString( 'CTRA-AAAA-0001', $html );
		$this->assertStringContainsString( 'ppcert-pill--expired', $html );
		$this->assertStringContainsString( 'Expired <strong>', $html );
		$this->assertStringNotContainsString( 'Expires <strong>', $html );

		// Valid filter: only the unexpired one.
		$_GET['ppcert_status'] = 'valid';
		$html                  = PressPrimer_Certificate_My_Certificates::render_shortcode();
		$this->assertSame( 1, substr_count( $html, 'ppcert-my-certificates__item' ) );
		$this->assertStringContainsString( 'CTRA-AAAA-0001', $html );

		// Unknown value falls back to All.
		$_GET['ppcert_status'] = 'bogus';
		$html                  = PressPrimer_Certificate_My_Certificates::render_shortcode();
		$this->assertSame( 2, substr_count( $html, 'ppcert-my-certificates__item' ) );

		unset( $_GET['ppcert_status'] );
	}

	/**
	 * Expiring sort puts the soonest expiry first and never-expiring
	 * certificates last; a filtered-empty view keeps the controls.
	 *
	 * @return void
	 */
	public function test_expiring_sort_and_filtered_empty() {
		$this->seed_certificate( [ 'credential_id' => 'SRTAAAAA0001' ] );
		$this->seed_certificate(
			[
				'credential_id' => 'SRTAAAAA0002',
				'expires_at'    => '2028-01-01 00:00:00',
			]
		);
		$this->seed_certificate(
			[
				'credential_id' => 'SRTAAAAA0003',
				'expires_at'    => '2027-01-01 00:00:00',
			]
		);

		$_GET['ppcert_sort'] = 'expiring';
		$html                = PressPrimer_Certificate_My_Certificates::render_shortcode();

		$pos_soonest = strpos( $html, 'SRTA-AAAA-0003' );
		$pos_later   = strpos( $html, 'SRTA-AAAA-0002' );
		$pos_never   = strpos( $html, 'SRTA-AAAA-0001' );

		$this->assertNotFalse( $pos_soonest );
		$this->assertLessThan( $pos_later, $pos_soonest, 'Soonest expiry first' );
		$this->assertLessThan( $pos_never, $pos_later, 'Never-expiring certificates last' );

		// A filter with no matches keeps the controls so the visitor
		// can switch back.
		$_GET['ppcert_status'] = 'expired';
		$html                  = PressPrimer_Certificate_My_Certificates::render_shortcode();
		$this->assertStringContainsString( 'ppcert-my-certificates__controls', $html );
		$this->assertStringContainsString( 'No certificates match this view.', $html );
		$this->assertSame( 0, substr_count( $html, 'ppcert-my-certificates__item' ) );

		unset( $_GET['ppcert_sort'], $_GET['ppcert_status'] );
	}

	/**
	 * A stored certificate name (Feature 1.1-006) leads the row; rows
	 * without one keep showing the template title.
	 *
	 * @return void
	 */
	public function test_rows_show_stored_certificate_name() {
		$this->seed_certificate(
			[
				'credential_id'   => 'CERTAAAA0003',
				'merge_data_json' => '{"certificate.title":"Botany 101 Certificate"}',
			]
		);

		$html = PressPrimer_Certificate_My_Certificates::render_shortcode();

		$this->assertStringContainsString( 'Botany 101 Certificate', $html );
	}

	/**
	 * The row action links filter (2.0, Feature 2.0-006): added entries
	 * render per row with escaping; defaults survive.
	 *
	 * @return void
	 */
	public function test_row_actions_filter() {
		$this->seed_certificate();

		add_filter(
			'ppcert_my_certificates_row_actions',
			static function ( $actions, $certificate, $status ) {
				$actions['share'] = [
					'label' => 'Share',
					'url'   => 'https://example.test/share?c=' . $certificate->credential_id . '&s=' . $status,
					'class' => 'ppcert-educator-share',
				];

				return $actions;
			},
			10,
			3
		);

		$html = PressPrimer_Certificate_My_Certificates::render_shortcode();

		$this->assertStringContainsString( 'ppcert-educator-share', $html );
		$this->assertStringContainsString( 'Share', $html );
		$this->assertStringContainsString( 'Download PDF', $html );
		$this->assertStringContainsString( 'Verify', $html );
	}
}
