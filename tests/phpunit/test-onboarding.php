<?php
/**
 * Onboarding guided tour tests (Phase 5B item 2)
 *
 * The per-user state machine (show/start/step/skip/complete/reset),
 * capability gating, and the boot data the React tour consumes.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Onboarding test case
 *
 * @since 1.0.0
 */
class Test_Onboarding extends TestCase {

	/**
	 * Instance under test.
	 *
	 * @var PressPrimer_Certificate_Onboarding
	 */
	private $onboarding;

	/**
	 * Reset state; current user 9 with the wizard capabilities.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();

		$GLOBALS['ppcert_test_options']      = [];
		$GLOBALS['ppcert_test_current_user'] = 9;
		$GLOBALS['ppcert_test_user_meta']    = [];
		$GLOBALS['ppcert_test_user_caps']    = [
			'ppcert_manage_templates',
			'ppcert_issue_certificates',
		];

		$this->onboarding = PressPrimer_Certificate_Onboarding::get_instance();

		// The singleton persists across tests; state lives in user meta,
		// which the globals reset above wipes.
	}

	/**
	 * should_show gating: capabilities, completion, permanent skip.
	 *
	 * @return void
	 */
	public function test_should_show_gating() {
		$this->assertTrue( $this->onboarding->should_show_onboarding() );

		// Both capabilities are required (the tour creates AND issues).
		$GLOBALS['ppcert_test_user_caps'] = [ 'ppcert_manage_templates' ];
		$this->assertFalse( $this->onboarding->should_show_onboarding() );

		$GLOBALS['ppcert_test_user_caps'] = [
			'ppcert_manage_templates',
			'ppcert_issue_certificates',
		];

		// Logged-out users never see the tour.
		$GLOBALS['ppcert_test_current_user'] = 0;
		$this->assertFalse( $this->onboarding->should_show_onboarding() );
		$GLOBALS['ppcert_test_current_user'] = 9;

		// Completed hides it.
		$this->onboarding->complete_onboarding();
		$this->assertFalse( $this->onboarding->should_show_onboarding() );

		// Reset restores it.
		$this->onboarding->reset_onboarding();
		$this->assertTrue( $this->onboarding->should_show_onboarding() );

		// A permanent skip hides it.
		$this->onboarding->skip_onboarding( true );
		$this->assertFalse( $this->onboarding->should_show_onboarding() );
	}

	/**
	 * The lifecycle: start, step clamping, skip, complete, reset.
	 *
	 * @return void
	 */
	public function test_state_lifecycle() {
		$this->onboarding->start_onboarding();

		$state = $this->onboarding->get_onboarding_state();
		$this->assertTrue( $state['started'] );
		$this->assertSame( 1, $state['current_step'] );
		$this->assertSame( 7, $state['total_steps'] );

		// Steps clamp into 1..TOTAL_STEPS.
		$this->onboarding->update_step( 5 );
		$this->assertSame( 5, $this->onboarding->get_onboarding_state()['current_step'] );

		$this->onboarding->update_step( 99 );
		$this->assertSame( 7, $this->onboarding->get_onboarding_state()['current_step'] );

		$this->onboarding->update_step( 0 );
		$this->assertSame( 1, $this->onboarding->get_onboarding_state()['current_step'] );

		// A temporary skip marks completed (no reappearing on
		// navigation) but not the permanent flag - a relaunch resets it.
		$this->onboarding->skip_onboarding( false );
		$this->assertTrue( $this->onboarding->get_onboarding_state()['completed'] );
		$this->assertFalse( $this->onboarding->should_show_onboarding() );

		$this->onboarding->reset_onboarding();
		$this->assertTrue( $this->onboarding->should_show_onboarding() );

		// Starting again clears a previous permanent skip.
		$this->onboarding->skip_onboarding( true );
		$this->onboarding->start_onboarding();
		$this->assertSame( '', get_user_meta( 9, PressPrimer_Certificate_Onboarding::META_SKIPPED, true ) );
	}

	/**
	 * Completion fires the lifecycle hook with the user id.
	 *
	 * @return void
	 */
	public function test_completed_hook_fires() {
		$captured = [];

		add_action(
			'ppcert_onboarding_completed',
			static function ( $user_id ) use ( &$captured ) {
				$captured[] = $user_id;
			}
		);

		$this->onboarding->complete_onboarding();

		$this->assertSame( [ 9 ], $captured );
	}

	/**
	 * Boot data: tour URLs on the real slugs and credential URL
	 * templates the modal fills client-side.
	 *
	 * @return void
	 */
	public function test_js_data_shape() {
		$data = $this->onboarding->get_js_data();

		$this->assertStringContainsString( 'page=ppcert-templates&action=new', $data['urls']['gallery'] );
		$this->assertStringContainsString( 'page=ppcert-certificates', $data['urls']['certificates'] );
		$this->assertStringContainsString( 'page=pressprimer-certificate', $data['urls']['dashboard'] );

		// The PPCERTCRED token slots the credential in client-side.
		$this->assertStringContainsString( 'ppcert/v1/certificates/PPCERTCRED/pdf', $data['pdfUrlTemplate'] );

		// The email ask resolves server-side; the shipped default is
		// disabled (no intake URL), so the wizard slot stays hidden.
		$this->assertFalse( $data['emailOptin']['eligible'] );

		// The relaunch link is nonce'd and points at the dashboard.
		$this->assertStringContainsString( 'ppcert-relaunch=1', $data['relaunchUrl'] );
		$this->assertStringContainsString( '_wpnonce=', $data['relaunchUrl'] );

		$this->assertSame( 7, $data['state']['total_steps'] );
	}
}
