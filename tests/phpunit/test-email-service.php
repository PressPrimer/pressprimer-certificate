<?php
/**
 * Email service tests
 *
 * The issued email: toggle, token substitution, attach-with-threshold,
 * content filter, and the issuance pipeline wiring.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Email service test case
 *
 * @since 1.0.0
 */
class Test_Email_Service extends TestCase {

	/**
	 * The fake wpdb.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Seeded certificate id.
	 *
	 * @var int
	 */
	private $certificate_id;

	/**
	 * Seed a certificate with a renderable snapshot.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_mail']     = [];
		$GLOBALS['ppcert_test_options']  = [];
		$GLOBALS['ppcert_test_posts']    = [];
		$GLOBALS['ppcert_test_bloginfo'] = [
			'name'        => 'Sunrise Training Academy',
			'admin_email' => 'admin@sunrise.example',
		];
		$GLOBALS['ppcert_test_users']    = [
			7 => (object) [
				'display_name' => 'Dana Whitfield',
				'first_name'   => 'Dana',
				'last_name'    => 'Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];

		$layout = wp_json_encode(
			[
				'layout_schema_version' => 1,
				'page'                  => [
					'size'        => 'a4',
					'orientation' => 'landscape',
					'width'       => 842,
					'height'      => 595,
				],
				'background'            => [
					'color'         => '#ffffff',
					'attachment_id' => 0,
				],
				'elements'              => [],
			]
		);

		$this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'id'                    => 40,
				'title'                 => 'Advanced Botany Certification',
				'status'                => 'published',
				'layout_schema_version' => 1,
				'layout_json'           => $layout,
				'deleted_at'            => null,
			]
		);

		$this->certificate_id = $this->wpdb->seed_row(
			PressPrimer_Certificate_Certificate::table(),
			[
				'uuid'                 => 'cert-email-0001',
				'credential_id'        => '7Q4MK9P2XT3A',
				'template_id'          => 40,
				'recipient_id'         => 7,
				'issued_by'            => 1,
				'source_type'          => 'manual',
				'status'               => 'issued',
				'layout_snapshot_json' => $layout,
				'merge_data_json'      => '{"recipient.full_name":"Dana Whitfield"}',
				'issued_at'            => '2026-07-18 14:30:00',
				'expires_at'           => null,
			]
		);
	}

	/**
	 * The issued email sends with substituted tokens and the PDF attached.
	 *
	 * @return void
	 */
	public function test_sends_with_tokens_and_attachment() {
		$sent = PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [ 'recipient_id' => 7 ] );

		$this->assertTrue( $sent );
		$this->assertCount( 1, $GLOBALS['ppcert_test_mail'] );

		$mail = $GLOBALS['ppcert_test_mail'][0];
		$this->assertSame( 'dana@example.test', $mail['to'] );
		$this->assertSame( 'Your certificate: Advanced Botany Certification', $mail['subject'] );
		$this->assertStringContainsString( 'Hi Dana Whitfield', $mail['body'] );
		$this->assertStringContainsString( '7Q4M-K9P2-XT3A', $mail['body'], 'Display-form credential in the body' );
		$this->assertStringContainsString( 'ppcert_id=7Q4MK9P2XT3A', $mail['body'], 'Working verification link' );
		$this->assertStringContainsString( 'From: Sunrise Training Academy <admin@sunrise.example>', $mail['headers'][0] );
		$this->assertCount( 1, $mail['attachments'], 'PDF always attaches' );
		$this->assertStringEndsWith( 'certificate-7Q4M-K9P2-XT3A.pdf', $mail['attachments'][0], 'Recipient-friendly attachment filename' );
	}

	/**
	 * The settings toggle and the ppcert_email_enabled filter both stop
	 * sending.
	 *
	 * @return void
	 */
	public function test_toggle_and_filter_disable() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [ 'email_issued_enabled' => 0 ];

		$this->assertFalse( PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [] ) );
		$this->assertCount( 0, $GLOBALS['ppcert_test_mail'] );

		$GLOBALS['ppcert_test_options'] = [];
		add_filter( 'ppcert_email_enabled', '__return_false_ppcert_test' );

		$this->assertFalse( PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [] ) );
		$this->assertCount( 0, $GLOBALS['ppcert_test_mail'] );
	}

	/**
	 * resend() bypasses a disabled automatic-email setting (the staff
	 * click is explicit consent) while the code-level filter still
	 * vetoes.
	 *
	 * @return void
	 */
	public function test_resend_bypasses_setting_but_not_filter() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [ 'email_issued_enabled' => 0 ];

		$this->assertFalse( PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [] ), 'Automatic send stays off' );
		$this->assertTrue( PressPrimer_Certificate_Email_Service::resend( $this->certificate_id ) );

		$this->assertCount( 1, $GLOBALS['ppcert_test_mail'] );
		$this->assertSame( 'dana@example.test', $GLOBALS['ppcert_test_mail'][0]['to'] );

		// The filter veto still wins over a resend.
		add_filter( 'ppcert_email_enabled', '__return_false_ppcert_test' );
		$this->assertFalse( PressPrimer_Certificate_Email_Service::resend( $this->certificate_id ) );
		$this->assertCount( 1, $GLOBALS['ppcert_test_mail'] );
	}

	/**
	 * A broken snapshot degrades to link-only: the email still sends with
	 * the verification URL in the body (no size threshold exists - the
	 * PDF attaches whenever rendering succeeds).
	 *
	 * @return void
	 */
	public function test_render_failure_degrades_to_link_only() {
		$this->wpdb->mutate_row(
			'wp_ppcert_certificates',
			$this->certificate_id,
			[ 'layout_snapshot_json' => '{"broken":true}' ]
		);

		$sent = PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [] );

		$this->assertTrue( $sent );
		$mail = $GLOBALS['ppcert_test_mail'][0];
		$this->assertCount( 0, $mail['attachments'], 'Unrenderable snapshot sends link-only' );
		$this->assertStringContainsString( 'ppcert_id=', $mail['body'] );
	}

	/**
	 * ppcert_email_content can rewrite any part of the email.
	 *
	 * @return void
	 */
	public function test_content_filter() {
		add_filter(
			'ppcert_email_content',
			static function ( $content, $email_type, $context ) {
				$content['subject'] = 'Overridden subject';
				return $content;
			},
			10,
			3
		);

		PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [] );

		$this->assertSame( 'Overridden subject', $GLOBALS['ppcert_test_mail'][0]['subject'] );
	}

	/**
	 * The issuance pipeline dispatches the email end-to-end: issue() with
	 * the email service live produces one captured mail.
	 *
	 * @return void
	 */
	public function test_issuance_pipeline_dispatches() {
		// A distinct source_ref: the seeded certificate in setUp() shares
		// recipient+template+manual, and duplicate suppression correctly
		// short-circuits before the email step otherwise.
		$id = PressPrimer_Certificate_Issuance_Service::issue(
			[
				'template_id'  => 40,
				'recipient_id' => 7,
				'source_type'  => 'manual',
				'source_ref'   => 'pipeline-e2e',
				'issued_by'    => 1,
			]
		);

		$this->assertIsInt( $id );
		$this->assertNotSame( $this->certificate_id, $id );
		$this->assertCount( 1, $GLOBALS['ppcert_test_mail'] );
		$this->assertSame( 'dana@example.test', $GLOBALS['ppcert_test_mail'][0]['to'] );

		// Suppressed issuance sends no second email.
		PressPrimer_Certificate_Issuance_Service::issue(
			[
				'template_id'  => 40,
				'recipient_id' => 7,
				'source_type'  => 'manual',
				'source_ref'   => 'pipeline-e2e',
				'issued_by'    => 1,
			]
		);
		$this->assertCount( 1, $GLOBALS['ppcert_test_mail'], 'Duplicate suppression must not re-email' );
	}
}

/**
 * Named callback returning false (avoids core __return_false collision).
 *
 * @return bool
 */
function __return_false_ppcert_test() { // phpcs:ignore PHPCompatibility.FunctionNameRestrictions.ReservedFunctionNames.FunctionDoubleUnderscore -- Test helper.
	return false;
}
