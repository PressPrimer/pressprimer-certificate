<?php
/**
 * Review prompt tests (2.1)
 *
 * Feature 2.1-006 FR-001, FR-003, FR-004: the threshold and the
 * capability gate, snooze and retire (every answer but snooze retires
 * the prompt for the whole site), white-label hiding, the due filter,
 * and the notice markup. (The milestone email ask was pulled before
 * release, Ryan 2026-10-19.)
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Engagement prompts test case
 *
 * @since 2.1.0
 */
class Test_Engagement_Prompts extends TestCase {

	/**
	 * The acting administrator.
	 *
	 * @var int
	 */
	const USER = 9;

	/**
	 * An administrator on the Certificates screen.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_transients();

		$GLOBALS['ppcert_test_options']      = [];
		$GLOBALS['ppcert_test_current_user'] = self::USER;
		$GLOBALS['ppcert_test_user_caps']    = [
			'manage_options',
			PressPrimer_Certificate_Capabilities::CAP_MANAGE_SETTINGS,
			PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES,
			PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES,
		];
		$_GET                                = [ 'page' => 'ppcert-certificates' ];

		PressPrimer_Certificate_Admin::branding( true );
		PressPrimer_Certificate_Review_Notice::init();
	}

	/**
	 * Clear the request.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$_GET = [];
		PressPrimer_Certificate_Admin::branding( true );
		parent::tearDown();
	}

	/**
	 * Set the cached site certificate count.
	 *
	 * @param int $count Count.
	 * @return void
	 */
	private function issued( $count ) {
		set_transient( PressPrimer_Certificate_Review_Notice::COUNT_TRANSIENT, $count, HOUR_IN_SECONDS );
	}

	/**
	 * The review prompt appears at 50 certificates, only for users who
	 * manage settings; the count is the site's, cached.
	 *
	 * @return void
	 */
	public function test_review_threshold_and_capability() {
		$this->issued( 49 );
		$this->assertFalse( PressPrimer_Certificate_Review_Notice::is_due( self::USER ) );

		$this->issued( 50 );
		$this->assertTrue( PressPrimer_Certificate_Review_Notice::is_due( self::USER ) );

		add_filter( 'ppcert_review_prompt_threshold', static fn() => 75 );
		$this->assertFalse( PressPrimer_Certificate_Review_Notice::is_due( self::USER ) );
		ppcert_tests_reset_hooks();
		PressPrimer_Certificate_Review_Notice::init();

		$GLOBALS['ppcert_test_user_caps'] = [ 'manage_options' ];
		$this->assertFalse( PressPrimer_Certificate_Review_Notice::is_due( self::USER ), 'Needs ppcert_manage_settings' );
	}

	/**
	 * Snooze hides it for 30 days; every other answer retires it for
	 * the whole site.
	 *
	 * @return void
	 */
	public function test_snooze_and_retire() {
		$this->issued( 60 );

		PressPrimer_Certificate_Review_Notice::record( 'snooze' );
		$this->assertSame( 'snoozed', get_option( PressPrimer_Certificate_Review_Notice::STATE_OPTION ) );
		$until = (string) get_option( PressPrimer_Certificate_Review_Notice::SNOOZED_UNTIL_OPTION );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, strtotime( $until . ' UTC' ), 5 );
		$this->assertFalse( PressPrimer_Certificate_Review_Notice::is_due( self::USER ) );

		update_option( PressPrimer_Certificate_Review_Notice::SNOOZED_UNTIL_OPTION, gmdate( 'Y-m-d H:i:s', time() - 60 ) );
		$this->assertTrue( PressPrimer_Certificate_Review_Notice::is_due( self::USER ), 'The snooze ran out' );

		foreach ( [ 'review', 'feedback', 'dismiss' ] as $answer ) {
			update_option( PressPrimer_Certificate_Review_Notice::STATE_OPTION, 'active' );
			PressPrimer_Certificate_Review_Notice::record( $answer );
			$this->assertSame( 'retired', get_option( PressPrimer_Certificate_Review_Notice::STATE_OPTION ), $answer );
			$this->assertFalse( PressPrimer_Certificate_Review_Notice::is_due( self::USER ), $answer );
		}

		PressPrimer_Certificate_Review_Notice::record( 'bogus' );
		$this->assertSame( 'retired', get_option( PressPrimer_Certificate_Review_Notice::STATE_OPTION ), 'Unknown answers change nothing' );
	}

	/**
	 * White-label branding, or the filter, hides the prompt; the due
	 * filter can hold it.
	 *
	 * @return void
	 */
	public function test_white_label_and_filters() {
		$this->issued( 60 );
		$this->assertTrue( PressPrimer_Certificate_Review_Notice::is_due( self::USER ) );

		add_filter(
			'ppcert_admin_branding',
			static function ( $branding ) {
				$branding['name'] = 'Acme Credentials';
				return $branding;
			}
		);
		PressPrimer_Certificate_Admin::branding( true );

		$this->assertFalse( PressPrimer_Certificate_Review_Notice::engagement_prompts_allowed() );
		$this->assertFalse( PressPrimer_Certificate_Review_Notice::is_due( self::USER ) );

		ppcert_tests_reset_hooks();
		PressPrimer_Certificate_Admin::branding( true );
		$this->assertTrue( PressPrimer_Certificate_Review_Notice::is_due( self::USER ) );

		add_filter( 'ppcert_show_engagement_prompts', static fn() => false );
		$this->assertFalse( PressPrimer_Certificate_Review_Notice::is_due( self::USER ) );

		ppcert_tests_reset_hooks();
		$seen = [];
		add_filter(
			'ppcert_review_prompt_due',
			static function ( $due, $user_id ) use ( &$seen ) {
				$seen[] = [ $due, $user_id ];
				return false;
			},
			10,
			2
		);
		$this->assertFalse( PressPrimer_Certificate_Review_Notice::is_due( self::USER ), 'Another ask can hold it' );
		$this->assertSame( [ [ true, self::USER ] ], $seen );
	}

	/**
	 * The notice prints on Certificate screens only, with real links
	 * for the two outbound answers.
	 *
	 * @return void
	 */
	public function test_notice_markup() {
		$this->issued( 50 );

		ob_start();
		PressPrimer_Certificate_Review_Notice::maybe_render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Congratulations! Your site has issued 50 certificates with PressPrimer Certificate!', $html );
		$this->assertStringContainsString( 'href="' . PressPrimer_Certificate_Review_Notice::REVIEW_URL . '" target="_blank" rel="noopener noreferrer" data-ppcert-review="review"', $html );
		$this->assertStringContainsString( 'data-ppcert-review="feedback"', $html );
		$this->assertStringContainsString( 'data-ppcert-review="snooze"', $html );
		$this->assertStringContainsString( 'data-ppcert-review="dismiss"', $html );
		$this->assertStringContainsString( 'data-ppcert-review-nonce="', $html );

		$_GET = [ 'page' => 'some-other-plugin' ];
		ob_start();
		PressPrimer_Certificate_Review_Notice::maybe_render();
		$this->assertSame( '', (string) ob_get_clean(), 'Never on other screens' );
	}
}
