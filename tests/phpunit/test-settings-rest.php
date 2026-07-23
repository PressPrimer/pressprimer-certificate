<?php
/**
 * Settings REST tests (Feature 008 FR-004, Prompt 5.2)
 *
 * Field-by-field sanitization on save, capability gating, the
 * standalone uninstall option, and the addon sanitize filter.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Settings REST test case
 *
 * @since 1.0.0
 */
class Test_Settings_REST extends TestCase {

	/**
	 * Controller under test.
	 *
	 * @var PressPrimer_Certificate_REST_Settings_Controller
	 */
	private $controller;

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options']   = [];
		$GLOBALS['ppcert_test_user_caps'] = [ 'ppcert_manage_settings' ];

		$this->controller = new PressPrimer_Certificate_REST_Settings_Controller();
	}

	/**
	 * Save sanitizes every field type and merges with existing settings.
	 *
	 * @return void
	 */
	public function test_save_sanitizes_fields() {
		update_option( 'ppcert_settings', [ 'verification_page_id' => 5 ] );

		$response = $this->controller->update_settings(
			new WP_REST_Request(
				[],
				[
					'appearance_default_font'   => 'eb-garamond',
					'appearance_primary_color'  => '#1F2A44',
					'appearance_accent_color'   => 'not-a-color',
					'email_issued_enabled'      => 0,
					'email_issued_subject'      => "  Your certificate <script>x</script>  ",
					'events_retention_days'     => 2,
				]
			)
		);

		$settings = $response->get_data()['settings'];

		// Untouched keys survive the merge.
		$this->assertSame( 5, $settings['verification_page_id'] );

		$this->assertSame( 'eb-garamond', $settings['appearance_default_font'] );
		$this->assertSame( '#1f2a44', strtolower( $settings['appearance_primary_color'] ) );

		// An invalid color never lands in storage.
		$this->assertArrayNotHasKey( 'appearance_accent_color', $settings );

		$this->assertSame( 0, $settings['email_issued_enabled'] );
		$this->assertStringNotContainsString( '<script>', $settings['email_issued_subject'] );

		// Bounds clamp: retention 7-3650.
		$this->assertSame( 7, $settings['events_retention_days'] );
	}

	/**
	 * An unregistered font slug is rejected; empty clears the setting.
	 *
	 * @return void
	 */
	public function test_font_slug_must_be_registered() {
		$response = $this->controller->update_settings(
			new WP_REST_Request( [], [ 'appearance_default_font' => 'comic-sans' ] )
		);

		$this->assertArrayNotHasKey( 'appearance_default_font', $response->get_data()['settings'] );

		$response = $this->controller->update_settings(
			new WP_REST_Request( [], [ 'appearance_default_font' => '' ] )
		);

		$this->assertSame( '', $response->get_data()['settings']['appearance_default_font'] );
	}

	/**
	 * The uninstall flag writes the standalone option uninstall.php
	 * reads, and reflects back into the settings payload.
	 *
	 * @return void
	 */
	public function test_uninstall_flag_writes_standalone_option() {
		$this->controller->update_settings(
			new WP_REST_Request( [], [ 'remove_data_on_uninstall' => 1 ] )
		);

		$this->assertTrue( (bool) get_option( 'ppcert_remove_data_on_uninstall', false ) );

		$response = $this->controller->update_settings(
			new WP_REST_Request( [], [ 'remove_data_on_uninstall' => 0 ] )
		);

		$this->assertFalse( (bool) get_option( 'ppcert_remove_data_on_uninstall', true ) );
		$this->assertSame( 0, $response->get_data()['settings']['remove_data_on_uninstall'] );
	}

	/**
	 * Capability gating and the addon sanitize filter.
	 *
	 * @return void
	 */
	public function test_capability_and_addon_filter() {
		$GLOBALS['ppcert_test_user_caps'] = [];
		$this->assertFalse( $this->controller->can_manage() );

		$GLOBALS['ppcert_test_user_caps'] = [ 'ppcert_manage_settings' ];
		$this->assertTrue( $this->controller->can_manage() );

		add_filter(
			'ppcert_sanitize_settings',
			static function ( $sanitized, $raw ) {
				if ( isset( $raw['ppcert_educator_extra'] ) ) {
					$sanitized['ppcert_educator_extra'] = sanitize_key( $raw['ppcert_educator_extra'] );
				}
				return $sanitized;
			},
			10,
			2
		);

		$response = $this->controller->update_settings(
			new WP_REST_Request( [], [ 'ppcert_educator_extra' => 'On!' ] )
		);

		$this->assertSame( 'on', $response->get_data()['settings']['ppcert_educator_extra'] );
	}
}
