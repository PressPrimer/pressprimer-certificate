<?php
/**
 * Email opt-in tests (Phase 5B item 2)
 *
 * Consent permanence, per-surface dismissals, eligibility gating,
 * and the REST handler's record-then-relay flow.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Email opt-in test case
 *
 * @since 1.0.0
 */
class Test_Email_Optin extends TestCase {

	/**
	 * Controller under test.
	 *
	 * @var PressPrimer_Certificate_REST_Email_Optin_Controller
	 */
	private $controller;

	/**
	 * Reset state; current user 9 is an administrator, and the intake
	 * is enabled via the filter (the shipped default is empty until
	 * the certificate-free webhook exists).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();

		$GLOBALS['ppcert_test_options']      = [];
		$GLOBALS['ppcert_test_current_user'] = 9;
		$GLOBALS['ppcert_test_user_meta']    = [];
		$GLOBALS['ppcert_test_user_caps']    = [ 'manage_options' ];
		$GLOBALS['ppcert_test_remote_posts'] = [];

		add_filter(
			'ppcert_email_intake_url',
			static function () {
				return 'https://intake.test.example/webhook';
			}
		);

		$this->controller = new PressPrimer_Certificate_REST_Email_Optin_Controller();
	}

	/**
	 * The shipped default targets the certificate-free webhook; the
	 * filter cleanly disables every surface.
	 *
	 * @return void
	 */
	public function test_default_intake_and_filter_disable() {
		ppcert_tests_reset_hooks();

		// The shipped default points at the certificate-free FluentCRM
		// webhook on pressprimer.com.
		$this->assertTrue( PressPrimer_Certificate_Email_Optin_Service::is_enabled() );
		$this->assertStringContainsString(
			'pressprimer.com',
			PressPrimer_Certificate_Email_Optin_Service::get_intake_url()
		);

		// The filter cleanly disables every surface.
		add_filter(
			'ppcert_email_intake_url',
			static function () {
				return '';
			}
		);
		$this->assertFalse( PressPrimer_Certificate_Email_Optin_Service::is_enabled() );
		$this->assertFalse( PressPrimer_Certificate_Email_Optin_Service::is_eligible( 9, 'wizard' ) );
	}

	/**
	 * Eligibility gating: admins only, one answer forever, dismissals
	 * per surface.
	 *
	 * @return void
	 */
	public function test_eligibility_gating() {
		$this->assertTrue( PressPrimer_Certificate_Email_Optin_Service::is_eligible( 9, 'wizard' ) );
		$this->assertTrue( PressPrimer_Certificate_Email_Optin_Service::is_eligible( 9, 'dashboard-card' ) );
		$this->assertFalse( PressPrimer_Certificate_Email_Optin_Service::is_eligible( 9, 'unknown-surface' ) );
		$this->assertFalse( PressPrimer_Certificate_Email_Optin_Service::is_eligible( 0, 'wizard' ) );

		// Non-admins are never asked.
		$GLOBALS['ppcert_test_user_caps'] = [ 'ppcert_manage_templates' ];
		$this->assertFalse( PressPrimer_Certificate_Email_Optin_Service::is_eligible( 9, 'wizard' ) );
		$GLOBALS['ppcert_test_user_caps'] = [ 'manage_options' ];

		// Dismissing one surface leaves the other available.
		PressPrimer_Certificate_Email_Optin_Service::record_dismissal( 9, 'dashboard-card' );
		$this->assertFalse( PressPrimer_Certificate_Email_Optin_Service::is_eligible( 9, 'dashboard-card' ) );
		$this->assertTrue( PressPrimer_Certificate_Email_Optin_Service::is_eligible( 9, 'wizard' ) );

		// Answering anywhere silences every surface.
		PressPrimer_Certificate_Email_Optin_Service::record_decline( 9, 'wizard' );
		$this->assertFalse( PressPrimer_Certificate_Email_Optin_Service::is_eligible( 9, 'wizard' ) );
		$this->assertFalse( PressPrimer_Certificate_Email_Optin_Service::is_eligible( 9, 'dashboard-card' ) );
	}

	/**
	 * First answer wins: a second answer never overwrites.
	 *
	 * @return void
	 */
	public function test_first_answer_wins() {
		$this->assertTrue( PressPrimer_Certificate_Email_Optin_Service::record_opt_in( 9, 'wizard' ) );
		$this->assertFalse( PressPrimer_Certificate_Email_Optin_Service::record_decline( 9, 'dashboard-card' ) );

		$consent = PressPrimer_Certificate_Email_Optin_Service::get_consent( 9 );
		$this->assertSame( 'opted_in', $consent['status'] );
		$this->assertSame( 'wizard', $consent['source'] );
	}

	/**
	 * REST opt-in: writes the local record first, then relays exactly
	 * the email and source tag, then fires the action.
	 *
	 * @return void
	 */
	public function test_rest_opt_in_records_and_relays() {
		$fired = [];

		add_action(
			'ppcert_email_optin_submitted',
			static function ( $user_id, $source ) use ( &$fired ) {
				$fired[] = [ $user_id, $source ];
			},
			10,
			2
		);

		$response = $this->controller->handle(
			new WP_REST_Request(
				[
					'decision' => 'opt_in',
					'source'   => 'wizard',
					'email'    => 'ryan@example.com',
				]
			)
		);

		$this->assertSame( 'opted_in', $response->get_data()['status'] );
		$this->assertSame( 'opted_in', PressPrimer_Certificate_Email_Optin_Service::get_consent( 9 )['status'] );

		// The relay carries the email and the source tag ONLY.
		$this->assertCount( 1, $GLOBALS['ppcert_test_remote_posts'] );
		$post = $GLOBALS['ppcert_test_remote_posts'][0];
		$this->assertSame( 'https://intake.test.example/webhook', $post['url'] );
		$this->assertSame(
			[
				'email'  => 'ryan@example.com',
				'source' => 'wizard',
			],
			$post['args']['body']
		);
		$this->assertFalse( $post['args']['blocking'], 'The relay is non-blocking' );

		$this->assertSame( [ [ 9, 'wizard' ] ], $fired );
	}

	/**
	 * REST validation: a missing or invalid email rejects without
	 * writing consent or relaying anything.
	 *
	 * @return void
	 */
	public function test_rest_opt_in_requires_valid_email() {
		$response = $this->controller->handle(
			new WP_REST_Request(
				[
					'decision' => 'opt_in',
					'source'   => 'wizard',
					'email'    => 'not-an-email',
				]
			)
		);

		$this->assertInstanceOf( 'WP_Error', $response );
		$this->assertSame( 'ppcert_optin_invalid_email', $response->get_error_code() );
		$this->assertNull( PressPrimer_Certificate_Email_Optin_Service::get_consent( 9 ) );
		$this->assertCount( 0, $GLOBALS['ppcert_test_remote_posts'] );
	}

	/**
	 * REST decline and dismissal: neither relays; a second answer is
	 * a no-op reporting the existing state.
	 *
	 * @return void
	 */
	public function test_rest_decline_and_dismiss() {
		$dismiss = $this->controller->handle(
			new WP_REST_Request(
				[
					'decision' => 'dismiss',
					'source'   => 'dashboard-card',
				]
			)
		);

		$this->assertSame( 'dismissed', $dismiss->get_data()['status'] );
		$this->assertNull( PressPrimer_Certificate_Email_Optin_Service::get_consent( 9 ), 'Dismissal is not an answer' );

		$decline = $this->controller->handle(
			new WP_REST_Request(
				[
					'decision' => 'decline',
					'source'   => 'wizard',
				]
			)
		);

		$this->assertSame( 'declined', $decline->get_data()['status'] );

		$again = $this->controller->handle(
			new WP_REST_Request(
				[
					'decision' => 'opt_in',
					'source'   => 'wizard',
					'email'    => 'ryan@example.com',
				]
			)
		);

		$this->assertSame( 'declined', $again->get_data()['status'] );
		$this->assertTrue( $again->get_data()['already_answered'] );
		$this->assertCount( 0, $GLOBALS['ppcert_test_remote_posts'], 'No relay after a decline' );
	}
}
