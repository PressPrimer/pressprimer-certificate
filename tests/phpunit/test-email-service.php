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
				'merge_data_json'      => '{"recipient.full_name":"Dana Whitfield","source.course_title":"Advanced Botany"}',
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
	 * Merge tokens resolve from the certificate's snapshot in subject
	 * and body, unknown tokens render empty, and both syntaxes mix in
	 * one template (Feature 1.1-005).
	 *
	 * @return void
	 */
	public function test_merge_tokens_resolve_from_snapshot() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [
			'email_issued_enabled' => 1,
			'email_issued_subject' => 'Your {{source.course_title}} certificate ({credential_id})',
			'email_issued_body'    => "For {{recipient.full_name}}\nMissing: ({{source.nothing_here}})\nLegacy: {verification_url}",
		];

		$sent = PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [ 'recipient_id' => 7 ] );

		$this->assertTrue( $sent );

		$mail = $GLOBALS['ppcert_test_mail'][0];

		// Both syntaxes resolve in the subject.
		$this->assertSame( 'Your Advanced Botany certificate (7Q4M-K9P2-XT3A)', $mail['subject'] );

		// Snapshot value, empty unknown, legacy token - all in the body.
		$this->assertStringContainsString( 'For Dana Whitfield', $mail['body'] );
		$this->assertStringContainsString( 'Missing: ()', $mail['body'] );
		$this->assertStringContainsString( 'ppcert_id=7Q4MK9P2XT3A', $mail['body'] );
	}

	/**
	 * The {subject} token is the certificate's own stored name when it
	 * has one (Feature 1.1-006), and the PDF attachment title follows.
	 *
	 * @return void
	 */
	public function test_subject_token_uses_stored_certificate_name() {
		$this->wpdb->mutate_row(
			PressPrimer_Certificate_Certificate::table(),
			$this->certificate_id,
			[ 'merge_data_json' => '{"recipient.full_name":"Dana Whitfield","certificate.title":"Advanced Botany Certificate"}' ]
		);

		PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [ 'recipient_id' => 7 ] );

		$mail = $GLOBALS['ppcert_test_mail'][0];
		$this->assertSame( 'Your certificate: Advanced Botany Certificate', $mail['subject'] );
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

	/**
	 * Seed an email-template row and map the setUp template to it.
	 *
	 * @param array $overrides Row overrides.
	 * @return int Email template row id.
	 */
	private function seed_mapped_email_template( array $overrides = [] ) {
		$row_id = $this->wpdb->seed_row(
			PressPrimer_Certificate_Email_Template::table(),
			array_merge(
				[
					'uuid'       => 'emailtpl-mapped-' . wp_rand( 1000, 9999 ),
					'title'      => 'Custom welcome',
					'context'    => 'issuance',
					'subject'    => 'Well done, {{recipient.full_name}}',
					'body'       => "Your {{source.course_title}} certificate is ready.\nLegacy: {credential_id}",
					'status'     => 'active',
					'deleted_at' => null,
				],
				$overrides
			)
		);

		$this->wpdb->mutate_row(
			PressPrimer_Certificate_Template::table(),
			40,
			[ 'settings_json' => wp_json_encode( [ 'email_template_id' => $row_id ] ) ]
		);

		return $row_id;
	}

	/**
	 * The Decision 005 resolution chain: a mapped active issuance row's
	 * subject/body replace the built-in default, with both token syntaxes
	 * substituting exactly as before.
	 *
	 * @return void
	 */
	public function test_mapped_email_template_row_wins() {
		$this->seed_mapped_email_template();

		$sent = PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [ 'recipient_id' => 7 ] );

		$this->assertTrue( $sent );

		$mail = $GLOBALS['ppcert_test_mail'][0];
		$this->assertSame( 'Well done, Dana Whitfield', $mail['subject'] );
		$this->assertStringContainsString( 'Your Advanced Botany certificate is ready.', $mail['body'] );
		$this->assertStringContainsString( 'Legacy: 7Q4M-K9P2-XT3A', $mail['body'], 'Legacy tokens substitute in mapped content too' );
	}

	/**
	 * Every fallback state of the resolution chain lands on the built-in
	 * default: soft-deleted mapping, archived row, wrong context, and a
	 * mapping to a row that never existed. The chain never fails a send.
	 *
	 * @return void
	 */
	public function test_mapped_row_fallback_states() {
		$row_id = $this->seed_mapped_email_template();

		$states = [
			'soft-deleted'  => [ 'deleted_at' => '2026-08-15 00:00:00', 'status' => 'active', 'context' => 'issuance' ],
			'archived'      => [ 'deleted_at' => null, 'status' => 'archived', 'context' => 'issuance' ],
			'wrong context' => [ 'deleted_at' => null, 'status' => 'active', 'context' => 'reminder' ],
		];

		foreach ( $states as $label => $mutation ) {
			$GLOBALS['ppcert_test_mail'] = [];
			$this->wpdb->mutate_row( PressPrimer_Certificate_Email_Template::table(), $row_id, $mutation );

			PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [ 'recipient_id' => 7 ] );

			$this->assertSame(
				'Your certificate: Advanced Botany Certification',
				$GLOBALS['ppcert_test_mail'][0]['subject'],
				"A {$label} mapping must fall back to the default subject."
			);
		}

		// A mapping pointing at a row that never existed.
		$GLOBALS['ppcert_test_mail'] = [];
		$this->wpdb->mutate_row(
			PressPrimer_Certificate_Template::table(),
			40,
			[ 'settings_json' => wp_json_encode( [ 'email_template_id' => 4040 ] ) ]
		);

		PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [ 'recipient_id' => 7 ] );

		$this->assertSame(
			'Your certificate: Advanced Botany Certification',
			$GLOBALS['ppcert_test_mail'][0]['subject'],
			'A dangling mapping must fall back to the default subject.'
		);
	}

	/**
	 * A resend records the 'reissued' lifecycle event (2.0, FR-006) -
	 * only when the mail actually went out.
	 *
	 * @return void
	 */
	public function test_resend_records_reissued_event() {
		$GLOBALS['ppcert_test_current_user'] = 5;

		$this->assertTrue( PressPrimer_Certificate_Email_Service::resend( $this->certificate_id ) );

		$events = $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() );
		$this->assertCount( 1, $events );
		$this->assertSame( 'reissued', $events[0]['event_type'] );
		$this->assertSame( 5, (int) $events[0]['actor_id'] );

		// A vetoed resend records nothing.
		add_filter( 'ppcert_email_enabled', '__return_false_ppcert_test' );

		$this->assertFalse( PressPrimer_Certificate_Email_Service::resend( $this->certificate_id ) );
		$this->assertCount( 1, $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() ) );
	}

	/**
	 * send_test (2.0, Feature 2.0-003): production assembly with the
	 * sample map - [Test] prefix, current-user recipient, pattern-driven
	 * certificate.title, verification links at the page base, no
	 * attachment plus the explanatory note, and the same From header the
	 * real send uses (TR-002 shared-builder parity).
	 *
	 * @return void
	 */
	public function test_send_test_uses_samples_and_production_assembly() {
		$GLOBALS['ppcert_test_current_user'] = 7;

		// A display-name pattern proves FR-003's title chain runs.
		$this->wpdb->mutate_row(
			PressPrimer_Certificate_Template::table(),
			40,
			[ 'settings_json' => wp_json_encode( [ 'certificate_name' => '{{site.name}} Certificate' ] ) ]
		);

		$result = PressPrimer_Certificate_Email_Service::send_test(
			PressPrimer_Certificate_Template::get( 40 )
		);

		$this->assertTrue( $result );
		$this->assertCount( 1, $GLOBALS['ppcert_test_mail'] );

		$mail = $GLOBALS['ppcert_test_mail'][0];

		$this->assertSame( 'dana@example.test', $mail['to'], 'The recipient is always the current user.' );
		$this->assertStringStartsWith( '[Test] ', $mail['subject'] );
		$this->assertStringContainsString( ' Certificate', $mail['subject'], 'The {subject} token resolves the certificate_name pattern with samples.' );
		$this->assertSame( [], $mail['attachments'], 'Tests never attach the PDF.' );
		$this->assertStringContainsString( 'without the PDF attachment', $mail['body'], 'The no-attachment note is appended (FR-004).' );
		$this->assertStringNotContainsString( 'ppcert_id=', $mail['body'], 'Credential-dependent links target the verification page base.' );
		$this->assertStringContainsString( 'From: Sunrise Training Academy <admin@sunrise.example>', $mail['headers'][0], 'Shared assembly: the production From header.' );

		// Shared-builder parity: the real send's From header matches.
		PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [ 'recipient_id' => 7 ] );
		$this->assertSame( $mail['headers'], $GLOBALS['ppcert_test_mail'][1]['headers'] );
	}

	/**
	 * send_test honors the Decision 005 resolution chain: a mapped
	 * active email-template row's subject is what the test sends -
	 * exactly what a real send would use.
	 *
	 * @return void
	 */
	public function test_send_test_respects_resolution_chain() {
		$GLOBALS['ppcert_test_current_user'] = 7;

		$this->seed_mapped_email_template( [ 'subject' => 'Well earned, {recipient_name}' ] );

		$this->assertTrue(
			PressPrimer_Certificate_Email_Service::send_test( PressPrimer_Certificate_Template::get( 40 ) )
		);

		$this->assertSame(
			'[Test] Well earned, Dana Whitfield',
			$GLOBALS['ppcert_test_mail'][0]['subject']
		);
	}

	/**
	 * A mailer failure reports its reason honestly (FR-001, Edge Cases) -
	 * never a pretended success.
	 *
	 * @return void
	 */
	public function test_send_test_reports_mailer_failure() {
		$GLOBALS['ppcert_test_current_user'] = 7;
		$GLOBALS['ppcert_test_mail_fail']    = 'SMTP connection refused';

		$result = PressPrimer_Certificate_Email_Service::send_test(
			PressPrimer_Certificate_Template::get( 40 )
		);

		unset( $GLOBALS['ppcert_test_mail_fail'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ppcert_test_email_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'SMTP connection refused', $result->get_error_message() );
	}

	/**
	 * template_tokens() extracts from the EFFECTIVE content: the mapped
	 * row's tokens when the chain selects one, the settings default's
	 * tokens otherwise.
	 *
	 * @return void
	 */
	public function test_template_tokens_follow_resolution_chain() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [
			'email_issued_subject' => 'Default {{site.name}}',
			'email_issued_body'    => 'Default body',
		];

		$this->assertSame(
			[ 'site.name' ],
			PressPrimer_Certificate_Email_Service::template_tokens( PressPrimer_Certificate_Template::get( 40 ) ),
			'Unmapped template: tokens come from the settings default.'
		);

		$row_id = $this->seed_mapped_email_template(
			[
				'subject' => 'Ready, {{recipient.first_name}}',
				'body'    => 'See {{source.quiz_title}}',
			]
		);

		$this->assertSame(
			[ 'recipient.first_name', 'source.quiz_title' ],
			PressPrimer_Certificate_Email_Service::template_tokens( PressPrimer_Certificate_Template::get( 40 ) ),
			'Mapped template: tokens come from the mapped row.'
		);

		$this->wpdb->mutate_row( PressPrimer_Certificate_Email_Template::table(), $row_id, [ 'status' => 'archived' ] );

		$this->assertSame(
			[ 'site.name' ],
			PressPrimer_Certificate_Email_Service::template_tokens( PressPrimer_Certificate_Template::get( 40 ) ),
			'Archived mapping: token collection follows the fallback.'
		);
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
