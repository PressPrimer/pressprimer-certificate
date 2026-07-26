<?php
/**
 * Activation redirect tests
 *
 * The one-shot dashboard redirect after a fresh-install activation
 * (PressPrimer Assignment 2.2 pattern): every guard in
 * get_setup_redirect_url(), flag consumption, and the reinstall guard.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Activation redirect test case
 *
 * @since 1.0.0
 */
class Test_Activation_Redirect extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Reset state: user 9 holds the manage-templates capability, no
	 * templates exist, and the flag records user 9.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_transients']    = [];
		$GLOBALS['ppcert_test_current_user']  = 9;
		$GLOBALS['ppcert_test_user_caps']     = [ PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES ];
		$GLOBALS['ppcert_test_doing_ajax']    = false;
		$GLOBALS['ppcert_test_doing_cron']    = false;
		$GLOBALS['ppcert_test_network_admin'] = false;
		unset( $_GET['activate-multi'] );

		set_transient( 'ppcert_setup_redirect', 9, 300 );
	}

	/**
	 * Resolve the redirect URL via the private guard method.
	 *
	 * @return string|null
	 */
	private function resolve() {
		$admin  = new PressPrimer_Certificate_Admin();
		$method = new ReflectionMethod( PressPrimer_Certificate_Admin::class, 'get_setup_redirect_url' );
		$method->setAccessible( true );

		return $method->invoke( $admin );
	}

	/**
	 * Happy path: fresh install, matching user, capability held, no
	 * templates - redirects to the dashboard and consumes the flag.
	 *
	 * @return void
	 */
	public function test_redirects_once_to_dashboard() {
		$url = $this->resolve();

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'page=pressprimer-certificate', $url );

		// Single-shot: the flag is gone, a second resolve is null.
		$this->assertFalse( get_transient( 'ppcert_setup_redirect' ) );
		$this->assertNull( $this->resolve() );
	}

	/**
	 * The guards: each terminal condition resolves null.
	 *
	 * @return void
	 */
	public function test_guards_block_redirect() {
		// AJAX request.
		$GLOBALS['ppcert_test_doing_ajax'] = true;
		$this->assertNull( $this->resolve() );
		$GLOBALS['ppcert_test_doing_ajax'] = false;

		// Cron request.
		$GLOBALS['ppcert_test_doing_cron'] = true;
		$this->assertNull( $this->resolve() );
		$GLOBALS['ppcert_test_doing_cron'] = false;

		// Network admin.
		$GLOBALS['ppcert_test_network_admin'] = true;
		$this->assertNull( $this->resolve() );
		$GLOBALS['ppcert_test_network_admin'] = false;

		// Another user's flag survives for them.
		$GLOBALS['ppcert_test_current_user'] = 5;
		$this->assertNull( $this->resolve() );
		$this->assertSame( 9, get_transient( 'ppcert_setup_redirect' ), 'Flag left for its owner' );
		$GLOBALS['ppcert_test_current_user'] = 9;

		// Bulk activation consumes the flag without redirecting.
		$_GET['activate-multi'] = '1';
		$this->assertNull( $this->resolve() );
		$this->assertFalse( get_transient( 'ppcert_setup_redirect' ) );
		unset( $_GET['activate-multi'] );

		// Missing capability consumes the flag without redirecting.
		set_transient( 'ppcert_setup_redirect', 9, 300 );
		$GLOBALS['ppcert_test_user_caps'] = [];
		$this->assertNull( $this->resolve() );
		$this->assertFalse( get_transient( 'ppcert_setup_redirect' ) );
		$GLOBALS['ppcert_test_user_caps'] = [ PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES ];

		// No flag at all.
		$this->assertNull( $this->resolve() );
	}

	/**
	 * Reinstall guard: existing templates suppress the redirect even
	 * with a valid flag.
	 *
	 * @return void
	 */
	public function test_existing_templates_block_redirect() {
		$this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'title'  => 'Existing Template',
				'status' => 'published',
			]
		);

		$this->assertNull( $this->resolve() );
		$this->assertFalse( get_transient( 'ppcert_setup_redirect' ), 'Flag still consumed' );
	}
}
