<?php
/**
 * Addon manager tests
 *
 * The 2.0 registration surface (Feature 2.0-006 FR-007): the
 * minimum-core-version gate with its refusal notice, the tier flag, and
 * the upgrade-tier / upsell-touchpoint registries.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Addon manager test case
 *
 * @since 2.0.0
 */
class Test_Addon_Manager extends TestCase {

	/**
	 * The manager singleton, reset per test.
	 *
	 * @var PressPrimer_Certificate_Addon_Manager
	 */
	private $manager;

	/**
	 * Reset hooks and the singleton's registrations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();

		$GLOBALS['ppcert_test_user_caps'] = [ 'activate_plugins' ];

		$this->manager = PressPrimer_Certificate_Addon_Manager::get_instance();
		$this->manager->reset();
	}

	/**
	 * The 1.0 three-argument registration keeps working unchanged, and
	 * the action wiring accepts the new fourth argument.
	 *
	 * @return void
	 */
	public function test_registration_compatibility_and_action_wiring() {
		$this->manager->init();

		do_action( 'ppcert_register_addon', 'educator', '2.0.0', [ 'custom_fonts' ] );

		$this->assertTrue( $this->manager->has_addon( 'educator' ) );
		$this->assertTrue( $this->manager->feature_enabled( 'custom_fonts' ) );
		$this->assertFalse( $this->manager->is_tier_active( 'educator' ), 'No tier flag, no tier.' );

		do_action(
			'ppcert_register_addon',
			'school',
			'2.0.0',
			[ 'issuer_profiles' ],
			[
				'tier' => 'school',
				'name' => 'PressPrimer Certificate School',
			]
		);

		$this->assertTrue( $this->manager->is_tier_active( 'school' ) );
	}

	/**
	 * The minimum-core-version gate: too-new requirements refuse with a
	 * recorded, rendered notice; satisfied requirements register.
	 *
	 * @return void
	 */
	public function test_min_core_version_gate() {
		$this->manager->register_addon(
			'enterprise',
			'2.0.0',
			[ 'audit_log' ],
			[
				'min_core_version' => '99.0.0',
				'tier'             => 'enterprise',
				'name'             => 'PressPrimer Certificate Enterprise',
			]
		);

		$this->assertFalse( $this->manager->has_addon( 'enterprise' ), 'Refused registrations never register.' );
		$this->assertFalse( $this->manager->is_tier_active( 'enterprise' ) );
		$this->assertFalse( $this->manager->feature_enabled( 'audit_log' ) );
		$this->assertArrayHasKey( 'enterprise', $this->manager->get_refused() );
		$this->assertSame( '99.0.0', $this->manager->get_refused()['enterprise']['required'] );

		ob_start();
		$this->manager->render_refusal_notices();
		$notice = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'PressPrimer Certificate Enterprise', $notice );
		$this->assertStringContainsString( '99.0.0', $notice );

		// A satisfiable requirement registers normally.
		$this->manager->register_addon(
			'educator',
			'2.0.0',
			[],
			[
				'min_core_version' => '0.9.0',
				'tier'             => 'educator',
			]
		);

		$this->assertTrue( $this->manager->has_addon( 'educator' ) );
		$this->assertTrue( $this->manager->is_tier_active( 'educator' ) );
	}

	/**
	 * Refusal notices render only for users who can act on them.
	 *
	 * @return void
	 */
	public function test_refusal_notice_capability_gated() {
		$GLOBALS['ppcert_test_user_caps'] = [];

		$this->manager->register_addon( 'educator', '2.0.0', [], [ 'min_core_version' => '99.0.0' ] );

		ob_start();
		$this->manager->render_refusal_notices();

		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * ppcert_upgrade_tiers: filter content flows through; the active flag
	 * is computed by the manager AFTER the filter, so filter callbacks
	 * cannot fake ownership.
	 *
	 * @return void
	 */
	public function test_upgrade_tiers_registry() {
		add_filter(
			'ppcert_upgrade_tiers',
			static function ( $tiers ) {
				$tiers['educator'] = [
					'name'   => 'Educator',
					'active' => true, // Must be overridden by the manager.
				];
				$tiers['school']   = [ 'name' => 'School' ];

				return $tiers;
			}
		);

		$this->manager->register_addon( 'school-addon', '2.0.0', [], [ 'tier' => 'school' ] );

		$tiers = $this->manager->get_upgrade_tiers();

		$this->assertSame( [ 'educator', 'school' ], array_keys( $tiers ) );
		$this->assertFalse( $tiers['educator']['active'], 'The filter cannot set active.' );
		$this->assertTrue( $tiers['school']['active'], 'A registered tier flag activates its card.' );
		$this->assertSame( 'educator', $tiers['educator']['id'] );
	}

	/**
	 * ppcert_upsell_touchpoints: entries for an active tier are removed
	 * by the manager, so no consumer can advertise to a customer.
	 *
	 * @return void
	 */
	public function test_upsell_touchpoints_hide_for_active_tiers() {
		add_filter(
			'ppcert_upsell_touchpoints',
			static function ( $touchpoints ) {
				$touchpoints['bulk-award']    = [
					'tier'  => 'educator',
					'title' => 'Bulk Award',
				];
				$touchpoints['audit-logging'] = [
					'tier'  => 'enterprise',
					'title' => 'Audit Logging',
				];

				return $touchpoints;
			}
		);

		$this->assertSame(
			[ 'bulk-award', 'audit-logging' ],
			array_keys( $this->manager->get_upsell_touchpoints() ),
			'With no tiers active, every touchpoint shows.'
		);

		$this->manager->register_addon( 'educator', '2.0.0', [], [ 'tier' => 'educator' ] );

		$this->assertSame(
			[ 'audit-logging' ],
			array_keys( $this->manager->get_upsell_touchpoints() ),
			'An active tier removes its touchpoints.'
		);
	}
}
