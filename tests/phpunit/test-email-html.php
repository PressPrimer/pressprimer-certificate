<?php
/**
 * HTML email tests (2.1)
 *
 * Exercises Feature 2.1-001: the layout (logo, site-name fallback,
 * footer default and white-label), escaping of every token, plain-text
 * body conversion and HTML pass-through, {certificate_button}, headers,
 * the plain-text escape hatch, the document and block filters,
 * send_test_message(), and the Settings > Email sanitization.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * HTML email test case
 *
 * @since 2.1.0
 */
class Test_Email_Html extends TestCase {

	/**
	 * The fake wpdb for the current test.
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
	 * Seed a template and a certificate whose recipient name carries
	 * markup and token syntax.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_mail']             = [];
		$GLOBALS['ppcert_test_options']          = [];
		$GLOBALS['ppcert_test_current_user']     = 7;
		$GLOBALS['ppcert_test_attachment_urls']  = [];
		$GLOBALS['ppcert_test_attachment_meta']  = [];
		$GLOBALS['ppcert_test_image_attachments'] = [];
		$GLOBALS['ppcert_test_bloginfo']         = [
			'name'        => 'Sunrise Training Academy',
			'admin_email' => 'admin@sunrise.example',
		];
		$GLOBALS['ppcert_test_users']            = [
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana <b>Whitfield</b>',
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
				'title'                 => 'Botany & Field Work',
				'status'                => 'published',
				'layout_schema_version' => 1,
				'layout_json'           => $layout,
				'deleted_at'            => null,
			]
		);

		$this->certificate_id = $this->wpdb->seed_row(
			PressPrimer_Certificate_Certificate::table(),
			[
				'uuid'                 => 'cert-html-0001',
				'credential_id'        => '7Q4MK9P2XT3A',
				'template_id'          => 40,
				'recipient_id'         => 7,
				'source_type'          => 'manual',
				'status'               => 'issued',
				'layout_snapshot_json' => $layout,
				'merge_data_json'      => (string) wp_json_encode(
					[
						'recipient.full_name' => 'Dana <script>x</script> {{site.name}}',
						'source.course_title' => 'Botany & <i>Field</i> Work',
					]
				),
				'issued_at'            => '2026-07-18 14:30:00',
				'expires_at'           => null,
			]
		);
	}

	/**
	 * Store email settings.
	 *
	 * @param array $settings Settings.
	 * @return void
	 */
	private function settings( array $settings ) {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = $settings;
	}

	/**
	 * The award email body for the seeded certificate.
	 *
	 * @return string
	 */
	private function award_body() {
		$GLOBALS['ppcert_test_mail'] = [];
		PressPrimer_Certificate_Email_Service::send_issued( $this->certificate_id, [], true );

		$this->assertCount( 1, $GLOBALS['ppcert_test_mail'] );

		return (string) $GLOBALS['ppcert_test_mail'][0]['body'];
	}

	/**
	 * The default award email: the layout, the site-name heading, the
	 * View Certificate button to the certificate page, a linked
	 * verification URL, the default footer, and HTML headers.
	 *
	 * @return void
	 */
	public function test_default_award_email_layout() {
		$body = $this->award_body();
		$mail = $GLOBALS['ppcert_test_mail'][0];

		$this->assertStringStartsWith( '<!DOCTYPE html>', $body );
		$this->assertStringContainsString( 'width="600"', $body );
		$this->assertStringContainsString( 'background-color:#f5f5f5;', $body );
		$this->assertStringContainsString( '>Sunrise Training Academy</h1>', $body, 'No logo: the site name heading' );
		$this->assertStringContainsString( '<a href="https://test.example/certificate/7Q4M-K9P2-XT3A/" style="display:inline-block;padding:12px 30px;background-color:#3b82f6;', $body, 'The button opens the certificate page' );
		$this->assertStringContainsString( '>View Certificate</a></div>', $body );
		$this->assertStringNotContainsString( '<p style="margin:0 0 15px;"><div', $body, 'The button replaces its paragraph' );
		$this->assertMatchesRegularExpression( '#<a href="https://test.example/\?ppcert_id=7Q4MK9P2XT3A">https://test.example/\?ppcert_id=7Q4MK9P2XT3A</a>#', $body );
		$this->assertStringContainsString( 'Credential ID: 7Q4M-K9P2-XT3A<br />', $body, '2.0 line breaks survive' );
		$this->assertStringContainsString( 'This email was sent from Sunrise Training Academy', $body );
		$this->assertStringContainsString( '>https://test.example</p>', $body );
		$this->assertStringNotContainsString( '{', $body, 'No token or CSS braces remain' );
		$this->assertStringNotContainsString( '<style', $body, 'Inline styles only' );

		$this->assertSame( 'From: Sunrise Training Academy <admin@sunrise.example>', $mail['headers'][0] );
		$this->assertSame( 'Content-Type: text/html; charset=UTF-8', $mail['headers'][1] );
		$this->assertSame( 'Your certificate: Botany & Field Work', $mail['subject'], 'The subject stays plain text' );
	}

	/**
	 * Every token value is escaped: markup in names and titles arrives
	 * as text, and token syntax inside a value is never interpolated.
	 *
	 * @return void
	 */
	public function test_token_values_are_escaped() {
		$this->settings(
			[
				'email_issued_body' => '<p>{recipient_name} | {{recipient.full_name}} | {{source.course_title}} | {subject}</p>',
			]
		);

		$body = $this->award_body();

		$this->assertStringContainsString( 'Dana &lt;script&gt;x&lt;/script&gt; &#123;&#123;site.name&#125;&#125;', $body );
		$this->assertStringContainsString( 'Botany &amp; &lt;i&gt;Field&lt;/i&gt; Work', $body );
		$this->assertStringNotContainsString( '<script>', $body );
		$this->assertStringNotContainsString( '<i>Field', $body );
		$this->assertStringNotContainsString( '| Sunrise Training Academy |', $body, 'A value never becomes a token' );
	}

	/**
	 * Editor HTML is used as it is (no doubled line breaks); a 2.0
	 * plain-text body keeps its paragraphs and line breaks.
	 *
	 * @return void
	 */
	public function test_body_formats() {
		$this->settings( [ 'email_issued_body' => "<p>First <strong>line</strong></p>\n<ul><li>One</li></ul>" ] );
		$body = $this->award_body();

		$this->assertStringContainsString( '<p style="margin:0 0 15px;">First <strong>line</strong></p>', $body );
		$this->assertStringContainsString( '<ul style="margin:0 0 15px;padding-left:24px;"><li>One</li></ul>', $body );
		$this->assertStringNotContainsString( '<br />', $body, 'No nl2br on editor HTML' );

		$this->settings( [ 'email_issued_body' => "Hi {recipient_name},\n\nLine one\nLine two & three" ] );
		$body = $this->award_body();

		$this->assertStringContainsString( '<p style="margin:0 0 15px;">Hi Dana &lt;script&gt;', $body );
		$this->assertStringContainsString( "Line one<br />\nLine two &amp; three</p>", $body );
	}

	/**
	 * The Email logo: sized for Outlook (width attribute capped by both
	 * 400 wide and 150 tall), alt text, and the heading when the
	 * attachment is gone.
	 *
	 * @return void
	 */
	public function test_logo() {
		$GLOBALS['ppcert_test_attachment_urls'][55] = 'https://test.example/uploads/logo.png';
		$GLOBALS['ppcert_test_attachment_meta'][55] = [
			'width'  => 1200,
			'height' => 300,
		];
		$this->settings( [ 'email_logo_id' => 55 ] );

		$body = $this->award_body();

		// 1200x300 into 400x150: the width cap wins (400 wide, 100 tall).
		$this->assertStringContainsString( '<img src="https://test.example/uploads/logo.png" width="400" alt="Sunrise Training Academy"', $body );
		$this->assertStringContainsString( 'max-width:400px;max-height:150px;', $body );
		$this->assertStringNotContainsString( '</h1>', $body );

		// A tall logo: the 150 height cap decides the width.
		$GLOBALS['ppcert_test_attachment_meta'][55] = [
			'width'  => 300,
			'height' => 600,
		];
		$this->assertStringContainsString( 'width="75"', $this->award_body() );

		// The attachment was deleted.
		unset( $GLOBALS['ppcert_test_attachment_urls'][55] );
		$this->assertStringContainsString( '>Sunrise Training Academy</h1>', $this->award_body() );
	}

	/**
	 * White-label footer text replaces the default lines; the block and
	 * document filters work.
	 *
	 * @return void
	 */
	public function test_footer_and_layout_filters() {
		add_filter(
			'ppcert_email_footer',
			static function () {
				return "Acme <Academy>\nPrivacy: acme.example/privacy";
			}
		);

		$body = $this->award_body();
		$this->assertStringContainsString( 'Acme <br />' . "\n" . 'Privacy: acme.example/privacy', str_replace( '&lt;Academy&gt;', '', $body ) );
		$this->assertStringNotContainsString( 'This email was sent from', $body );

		add_filter(
			'ppcert_email_header_html',
			static function ( $header, $logo_url, $type ) {
				return '<tr><td>HEADER ' . $type . ' [' . $logo_url . ']</td></tr>';
			},
			10,
			3
		);
		add_filter(
			'ppcert_email_footer_html',
			static function ( $footer ) {
				return str_replace( '</td></tr>', ' EXTRA</td></tr>', $footer );
			}
		);

		$body = $this->award_body();
		$this->assertStringContainsString( '<tr><td>HEADER issued []</td></tr>', $body );
		$this->assertStringContainsString( 'acme.example/privacy</p> EXTRA</td></tr>', $body );

		add_filter(
			'ppcert_email_html',
			static function ( $html, $message ) {
				return '<html>CUSTOM ' . strlen( $message ) . '</html>';
			},
			10,
			2
		);
		$this->assertStringStartsWith( '<html>CUSTOM ', $this->award_body() );
	}

	/**
	 * The escape hatch: 'text' sends the 2.0 plain-text email for that
	 * send (no layout, no HTML Content-Type, editor HTML as text).
	 *
	 * @return void
	 */
	public function test_plain_text_format() {
		add_filter(
			'ppcert_email_format',
			static function ( $format, $type ) {
				return 'issued' === $type ? 'text' : $format;
			},
			10,
			2
		);

		$this->settings( [ 'email_issued_body' => "Hi {recipient_name},\n\nVerify: {verification_url}" ] );
		$body = $this->award_body();
		$mail = $GLOBALS['ppcert_test_mail'][0];

		// Byte for byte the 2.0 output, including 2.0's reading of token
		// syntax inside a value (unknown merge tokens print empty); the
		// HTML format escapes braces instead.
		$this->assertSame( "Hi Dana <script>x</script> ,\n\nVerify: https://test.example/?ppcert_id=7Q4MK9P2XT3A", $body, 'Byte for byte the 2.0 output' );
		$this->assertSame( [ 'From: Sunrise Training Academy <admin@sunrise.example>' ], $mail['headers'] );

		$this->settings( [ 'email_issued_body' => '<p>Hello <strong>{recipient_name}</strong></p><ul><li>One</li><li>Two</li></ul><p>{certificate_button}</p>' ] );
		$this->assertSame(
			"Hello Dana <script>x</script> \n\n- One\n- Two\n\nView Certificate: https://test.example/certificate/7Q4M-K9P2-XT3A/",
			$this->award_body()
		);
	}

	/**
	 * send_test_message(): another address, validation, extra tokens,
	 * a custom note and type, samples, and the verification page as the
	 * button target.
	 *
	 * @return void
	 */
	public function test_send_test_message() {
		$types = [];

		add_filter(
			'ppcert_email_content',
			static function ( $content, $type ) use ( &$types ) {
				$types[] = $type;
				return $content;
			},
			10,
			2
		);

		$result = PressPrimer_Certificate_Email_Service::send_test_message(
			[
				'subject'      => 'Expiring {expiry_date}',
				'body'         => '<p>Renew by {expiry_date}.</p><p>{certificate_button}</p>',
				'to'           => ' manager@example.test ',
				'email_type'   => 'expiry_reminder_test',
				'extra_tokens' => [ '{expiry_date}' => 'May 1 <2027>' ],
				'note'         => 'Reminder test note.',
			]
		);

		$this->assertTrue( $result );

		$mail = end( $GLOBALS['ppcert_test_mail'] );
		$this->assertSame( 'manager@example.test', $mail['to'] );
		$this->assertSame( '[Test] Expiring May 1 <2027>', $mail['subject'] );
		$this->assertStringContainsString( 'Renew by May 1 &lt;2027&gt;.', $mail['body'] );
		$this->assertStringContainsString( '<a href="https://test.example/"', $mail['body'], 'No credential: the verification page' );
		$this->assertStringContainsString( 'Reminder test note.', $mail['body'] );
		$this->assertSame( [], $mail['attachments'], 'No PDF' );
		$this->assertSame( [ 'expiry_reminder_test' ], $types );

		$invalid = PressPrimer_Certificate_Email_Service::send_test_message(
			[
				'subject' => 'S',
				'body'    => 'B',
				'to'      => 'not-an-address',
			]
		);
		$this->assertSame( 'ppcert_test_email_invalid_recipient', $invalid->get_error_code() );

		// The Settings test: the current user, the award note.
		$this->assertTrue( PressPrimer_Certificate_Email_Service::send_test( null ) );
		$mail = end( $GLOBALS['ppcert_test_mail'] );
		$this->assertSame( 'dana@example.test', $mail['to'] );
		$this->assertStringContainsString( 'This is a test of the award email', $mail['body'] );
		$this->assertStringContainsString( 'Hi Dana &lt;b&gt;Whitfield&lt;/b&gt;,', $mail['body'] );

		$this->assertTrue( PressPrimer_Certificate_Email_Service::send_test( null, 'second@example.test' ) );
		$this->assertSame( 'second@example.test', end( $GLOBALS['ppcert_test_mail'] )['to'] );
	}

	/**
	 * Settings > Email sanitization: the logo must be an image, the
	 * body keeps safe HTML, and the From address clears or errors.
	 *
	 * @return void
	 */
	public function test_settings_sanitization() {
		$GLOBALS['ppcert_test_user_caps']         = [ 'ppcert_manage_settings' ];
		$GLOBALS['ppcert_test_image_attachments'] = [ 55 ];

		$controller = new PressPrimer_Certificate_REST_Settings_Controller();

		$response = $controller->update_settings(
			new WP_REST_Request(
				[],
				[
					'email_logo_id'      => '55',
					'email_issued_body'  => '<p>Hello <strong>{recipient_name}</strong></p>',
					'email_from_address' => '',
				]
			)
		);

		$saved = $response->get_data()['settings'];
		$this->assertSame( 55, $saved['email_logo_id'] );
		$this->assertSame( '<p>Hello <strong>{recipient_name}</strong></p>', $saved['email_issued_body'], 'HTML is kept (wp_kses_post)' );
		$this->assertSame( '', $saved['email_from_address'], 'Empty clears the address' );
		$this->assertSame( 'admin@sunrise.example', PressPrimer_Certificate_Email_Service::settings()['email_from_address'], 'The administration email applies' );

		$controller->update_settings( new WP_REST_Request( [], [ 'email_logo_id' => 77 ] ) );
		$this->assertSame( 55, get_option( 'ppcert_settings' )['email_logo_id'], 'A non-image is ignored' );

		$controller->update_settings( new WP_REST_Request( [], [ 'email_logo_id' => 0 ] ) );
		$this->assertSame( 0, get_option( 'ppcert_settings' )['email_logo_id'], '0 removes the logo' );

		$error = $controller->update_settings( new WP_REST_Request( [], [ 'email_from_address' => 'nope' ] ) );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertSame( 'email_from_address', $error->get_error_data()['field'] );
	}
}
