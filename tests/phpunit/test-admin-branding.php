<?php
/**
 * Admin branding tests (2.0, Enterprise contract item 11)
 *
 * ppcert_admin_branding is the one structured map behind the menu
 * label and icon, the dashboard heading and logo, and the tour copy;
 * the legacy single-value filters still run afterward and keep
 * winning; values are sanitized and empties fall back.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Admin branding test case
 *
 * @since 2.0.0
 */
class Test_Admin_Branding extends TestCase {

	/**
	 * Reset hooks and the per-request branding cache.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$GLOBALS['ppcert_test_options'] = [];
		PressPrimer_Certificate_Admin::branding( true );
	}

	/**
	 * Defaults: PressPrimer naming, the bundled logo, the data-URI icon.
	 *
	 * @return void
	 */
	public function test_defaults() {
		$branding = PressPrimer_Certificate_Admin::branding( true );

		$this->assertSame( [ 'name', 'menu_label', 'logo_url', 'menu_icon' ], array_keys( $branding ) );
		$this->assertSame( 'PressPrimer Certificate', $branding['name'] );
		$this->assertSame( 'Certificates', $branding['menu_label'] );
		$this->assertStringEndsWith( 'assets/images/PressPrimer-Logo-White.svg', $branding['logo_url'] );
		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $branding['menu_icon'] );
	}

	/**
	 * The filter re-brands every key; values are sanitized; empties and
	 * junk fall back to the defaults.
	 *
	 * @return void
	 */
	public function test_filter_sanitizes_and_falls_back() {
		add_filter(
			'ppcert_admin_branding',
			static function ( $branding ) {
				return [
					'name'       => 'Acme <b>Credentials</b>',
					'menu_label' => 'Credentials',
					'logo_url'   => 'https://acme.example/logo.svg',
					'menu_icon'  => 'dashicons-awards',
				];
			}
		);

		$branding = PressPrimer_Certificate_Admin::branding( true );

		$this->assertSame( 'Acme Credentials', $branding['name'] );
		$this->assertSame( 'Credentials', $branding['menu_label'] );
		$this->assertSame( 'https://acme.example/logo.svg', $branding['logo_url'] );
		$this->assertSame( 'dashicons-awards', $branding['menu_icon'] );

		ppcert_tests_reset_hooks();
		add_filter(
			'ppcert_admin_branding',
			static function () {
				return [
					'name'     => '',
					'logo_url' => 'javascript:alert(1)',
					'junk'     => 'ignored',
				];
			}
		);

		$branding = PressPrimer_Certificate_Admin::branding( true );

		$this->assertSame( 'PressPrimer Certificate', $branding['name'], 'Empty falls back' );
		$this->assertSame( 'Certificates', $branding['menu_label'], 'Missing key falls back' );
		$this->assertStringEndsWith( 'PressPrimer-Logo-White.svg', $branding['logo_url'], 'Unsafe URL falls back' );
		$this->assertArrayNotHasKey( 'junk', $branding );

		ppcert_tests_reset_hooks();
		add_filter( 'ppcert_admin_branding', '__return_true' );
		$this->assertSame( 'PressPrimer Certificate', PressPrimer_Certificate_Admin::branding( true )['name'], 'A non-array result is ignored' );
	}

	/**
	 * The dashboard reads the map, and the legacy single-value filters
	 * run afterward with the map's value as input - so an existing
	 * ppcert_plugin_name or ppcert_dashboard_logo callback still wins.
	 *
	 * @return void
	 */
	public function test_dashboard_reads_branding_and_legacy_filters_still_win() {
		add_filter(
			'ppcert_admin_branding',
			static function ( $branding ) {
				$branding['name']     = 'Acme Credentials';
				$branding['logo_url'] = 'https://acme.example/logo.svg';

				return $branding;
			}
		);

		PressPrimer_Certificate_Admin::branding( true );

		$dashboard = new PressPrimer_Certificate_Admin_Dashboard();
		$data      = $dashboard->boot_data();

		$this->assertSame( 'Acme Credentials', $data['pluginName'] );
		$this->assertSame( 'https://acme.example/logo.svg', $data['dashboardLogo'] );

		add_filter(
			'ppcert_plugin_name',
			static function ( $name ) {
				return 'Legacy ' . $name;
			}
		);
		add_filter(
			'ppcert_dashboard_logo',
			static function () {
				return '';
			}
		);
		PressPrimer_Certificate_Admin::branding( true );

		$data = $dashboard->boot_data();

		$this->assertSame( 'Legacy Acme Credentials', $data['pluginName'], 'The legacy filter receives the branding value and wins' );
		$this->assertSame( '', $data['dashboardLogo'] );
	}
}
