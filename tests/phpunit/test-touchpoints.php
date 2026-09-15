<?php
/**
 * Tests for the upsell touchpoint registry (2.0, Feature 2.0-005)
 *
 * Covers the default entries, the per-surface payload, the double gate
 * (tier inactive AND manage_options), and the filter contract for
 * later registrants. Sibling reference: the Assignment 2.2 touchpoint
 * registry.
 *
 * @package PressPrimer_Certificate
 */

use PHPUnit\Framework\TestCase;

/**
 * Touchpoint registry tests
 */
class Test_Touchpoints extends TestCase {

	/**
	 * The addon manager singleton.
	 *
	 * @var PressPrimer_Certificate_Addon_Manager
	 */
	private $manager;

	/**
	 * Fresh hooks, an admin user, no tiers active, defaults registered.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();

		$GLOBALS['ppcert_test_user_caps'] = true;

		$this->manager = PressPrimer_Certificate_Addon_Manager::get_instance();
		$this->manager->reset();

		add_filter( 'ppcert_upgrade_tiers', [ 'PressPrimer_Certificate_Upgrade_Page', 'register_default_tiers' ], 5 );

		$touchpoints = new PressPrimer_Certificate_Touchpoints();
		$touchpoints->init();
	}

	/**
	 * Restore the permissive default.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['ppcert_test_user_caps'] = true;
		ppcert_tests_reset_hooks();
		parent::tearDown();
	}

	/**
	 * The four approved launch touchpoints register, in order, each
	 * with the keys the payload builder needs (FR-002 as amended
	 * 2026-09-15).
	 *
	 * @return void
	 */
	public function test_defaults_register_the_launch_set() {
		$registry = $this->manager->get_upsell_touchpoints();

		$this->assertSame(
			[ 'expiry-reminder-email', 'multi-page', 'expiry-reminder-schedule', 'organization' ],
			array_keys( $registry )
		);

		foreach ( $registry as $id => $entry ) {
			foreach ( [ 'surface', 'location', 'tier', 'copy' ] as $key ) {
				$this->assertArrayHasKey( $key, $entry, sprintf( '%s declares %s.', $id, $key ) );
				$this->assertNotSame( '', $entry[ $key ], sprintf( '%s has a non-empty %s.', $id, $key ) );
			}

			$this->assertSame( $id, $entry['id'], 'The manager stamps the id.' );
		}

		$this->assertSame( 'educator', $registry['expiry-reminder-email']['tier'] );
		$this->assertSame( 'educator', $registry['multi-page']['tier'] );
		$this->assertSame( 'educator', $registry['expiry-reminder-schedule']['tier'] );
		$this->assertSame( 'school', $registry['organization']['tier'] );
		$this->assertSame( 'Organization', $registry['organization']['label'], 'The locked tab carries a feature label.' );
	}

	/**
	 * The Settings surface carries exactly the Email tab prompt, keyed
	 * by its slot, with link text and a UTM-tagged tier URL derived
	 * from the tier registry.
	 *
	 * @return void
	 */
	public function test_settings_surface_payload() {
		$eligible = PressPrimer_Certificate_Touchpoints::get_eligible_for_surface( 'settings' );

		$this->assertSame( [ 'email-tab' ], array_keys( $eligible ) );

		$payload = $eligible['email-tab'];

		$this->assertSame( 'expiry-reminder-email', $payload['key'] );
		$this->assertSame( 'Upgrade to Educator', $payload['linkText'] );
		$this->assertStringContainsString( 'expires', $payload['copy'] );
		$this->assertArrayNotHasKey( 'label', $payload, 'Only tab-style slots carry a label.' );

		$this->assertStringStartsWith( 'https://pressprimer.com/pressprimer-certificate-pricing/?', $payload['url'], 'Every upgrade link lands on the pricing page.' );
		$this->assertStringContainsString( 'utm_source=pressprimer-certificate', $payload['url'] );
		$this->assertStringContainsString( 'utm_medium=plugin', $payload['url'] );
		$this->assertStringContainsString( 'utm_campaign=touchpoint', $payload['url'] );
		$this->assertStringContainsString( 'utm_content=expiry-reminder-email', $payload['url'] );
	}

	/**
	 * The designer surface carries the rail, Award tab, and sidebar tab
	 * prompts; the sidebar tab carries School's label and link.
	 *
	 * @return void
	 */
	public function test_designer_surface_payload() {
		$eligible = PressPrimer_Certificate_Touchpoints::get_eligible_for_surface( 'designer' );

		$this->assertSame( [ 'canvas-rail', 'award-tab', 'sidebar-tab' ], array_keys( $eligible ) );

		$this->assertSame( 'multi-page', $eligible['canvas-rail']['key'] );
		$this->assertSame( 'expiry-reminder-schedule', $eligible['award-tab']['key'] );
		$this->assertSame( 'organization', $eligible['sidebar-tab']['key'] );

		$this->assertSame( 'Organization', $eligible['sidebar-tab']['label'] );
		$this->assertSame( 'Upgrade to School', $eligible['sidebar-tab']['linkText'] );
		$this->assertStringStartsWith( 'https://pressprimer.com/pressprimer-certificate-pricing/?', $eligible['sidebar-tab']['url'] );
		$this->assertStringContainsString( 'utm_content=organization', $eligible['sidebar-tab']['url'] );

		foreach ( $eligible as $payload ) {
			$this->assertSame( [ 'key', 'copy', 'linkText', 'url' ], array_slice( array_keys( $payload ), 0, 4 ) );
		}
	}

	/**
	 * An active tier removes its touchpoints from every surface, so a
	 * customer is never advertised to (US-3).
	 *
	 * @return void
	 */
	public function test_active_tier_removes_its_touchpoints() {
		$this->manager->register_addon( 'educator', '2.0.0', [], [ 'tier' => 'educator' ] );

		$this->assertSame( [], PressPrimer_Certificate_Touchpoints::get_eligible_for_surface( 'settings' ) );
		$this->assertSame(
			[ 'sidebar-tab' ],
			array_keys( PressPrimer_Certificate_Touchpoints::get_eligible_for_surface( 'designer' ) ),
			'School\'s tab stays until School is active.'
		);

		$this->manager->register_addon( 'school', '2.0.0', [], [ 'tier' => 'school' ] );

		$this->assertSame( [], PressPrimer_Certificate_Touchpoints::get_eligible_for_surface( 'designer' ) );
	}

	/**
	 * Non-administrators receive an empty payload on every surface, even
	 * with no tiers active: template editors and issuers see no
	 * marketing (the Assignment visibility rule).
	 *
	 * @return void
	 */
	public function test_non_admins_receive_nothing() {
		$GLOBALS['ppcert_test_user_caps'] = [ 'ppcert_manage_templates', 'ppcert_issue_certificates', 'edit_posts' ];

		$this->assertSame( [], PressPrimer_Certificate_Touchpoints::get_eligible_for_surface( 'settings' ) );
		$this->assertSame( [], PressPrimer_Certificate_Touchpoints::get_eligible_for_surface( 'designer' ) );

		$this->assertCount(
			4,
			$this->manager->get_upsell_touchpoints(),
			'The registry itself is unchanged; only the eligibility gate closes.'
		);
	}

	/**
	 * A surface nothing registers for is empty rather than an error.
	 *
	 * @return void
	 */
	public function test_unknown_surface_is_empty() {
		$this->assertSame( [], PressPrimer_Certificate_Touchpoints::get_eligible_for_surface( 'dashboard' ) );
		$this->assertSame( [], PressPrimer_Certificate_Touchpoints::get_eligible_for_surface( '' ) );
	}

	/**
	 * Later registrants add entries through the filter and receive the
	 * same derived link text and tier URL; entries missing a required
	 * key are skipped rather than rendered half-built.
	 *
	 * @return void
	 */
	public function test_filter_registrants_join_the_payload() {
		add_filter(
			'ppcert_upsell_touchpoints',
			static function ( $touchpoints ) {
				$touchpoints['partner-sync'] = [
					'surface'  => 'designer',
					'location' => 'palette',
					'tier'     => 'enterprise',
					'copy'     => 'Sync this template to your partner systems.',
				];
				$touchpoints['half-built']   = [
					'surface' => 'designer',
					'tier'    => 'enterprise',
					'copy'    => 'No location.',
				];
				$touchpoints['blank-copy']   = [
					'surface'  => 'designer',
					'location' => 'footer',
					'tier'     => 'enterprise',
					'copy'     => '   ',
				];

				return $touchpoints;
			}
		);

		$eligible = PressPrimer_Certificate_Touchpoints::get_eligible_for_surface( 'designer' );

		$this->assertSame( [ 'canvas-rail', 'award-tab', 'sidebar-tab', 'palette' ], array_keys( $eligible ) );
		$this->assertSame( 'partner-sync', $eligible['palette']['key'] );
		$this->assertSame( 'Upgrade to Enterprise', $eligible['palette']['linkText'] );
		$this->assertStringStartsWith( 'https://pressprimer.com/pressprimer-certificate-pricing/?', $eligible['palette']['url'] );
	}

	/**
	 * Without the tier registry the payload still resolves: a
	 * capitalized tier id for the link and the pricing page for the URL.
	 *
	 * @return void
	 */
	public function test_payload_falls_back_without_tier_registry() {
		ppcert_tests_reset_hooks();

		$touchpoints = new PressPrimer_Certificate_Touchpoints();
		$touchpoints->init();

		$eligible = PressPrimer_Certificate_Touchpoints::get_eligible_for_surface( 'settings' );

		$this->assertSame( 'Upgrade to Educator', $eligible['email-tab']['linkText'] );
		$this->assertStringStartsWith( PressPrimer_Certificate_Upgrade_Page::PRICING_URL, $eligible['email-tab']['url'] );
	}
}
