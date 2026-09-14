<?php
/**
 * Email service
 *
 * The issuance email (Feature 003 FR-004).
 *
 * @package PressPrimer_Certificate
 * @subpackage Services
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email service class
 *
 * Sends the issued email through wp_mail(): toggleable in settings
 * (default on), subject/body with token substitution, and the PDF
 * always attached when rendering succeeds. Every part is
 * filterable via ppcert_email_enabled / ppcert_email_content (HOOKS.md) -
 * the issuance service's dispatch point calls send_issued(), and the two
 * filters fire HERE with their documented signatures.
 *
 * Substitution (1.1, Feature 1.1-005): the legacy single-brace map
 * ({subject}, {recipient_name}, ...) applies first, unchanged; then
 * {{group.field}} merge tokens resolve from the certificate's
 * merge_data snapshot - the email says exactly what the certificate
 * says, and a resend after template edits still matches the ORIGINAL
 * certificate. Unknown merge tokens render empty, never as syntax.
 *
 * The email links to the verification URL in 1.0; Prompt 4.6's view page
 * becomes the primary link and verification moves secondary (TODO there).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Email_Service {

	/**
	 * Send the issued email for a certificate
	 *
	 * Called from the issuance pipeline's dispatch point (step 8);
	 * failures there log without rolling back the certificate.
	 *
	 * @since 1.0.0
	 *
	 * @param int   $certificate_id Certificate row id.
	 * @param array $context        Issuance context.
	 * @param bool  $force_enabled  Bypass the automatic-email setting
	 *                              (explicit staff resend). Default false.
	 * @return bool Whether an email was sent.
	 */
	public static function send_issued( $certificate_id, $context, $force_enabled = false ) {
		$settings = self::settings();

		// An explicit staff resend bypasses the automatic-email
		// setting (the click IS the consent); the filter below can
		// still veto in code.
		$enabled = $force_enabled || ! empty( $settings['email_issued_enabled'] );

		/** This filter is documented in docs/architecture/HOOKS.md */
		$enabled = apply_filters( 'ppcert_email_enabled', $enabled, 'issued', $context );

		if ( ! $enabled ) {
			return false;
		}

		$certificate = PressPrimer_Certificate_Certificate::get( absint( $certificate_id ) );

		if ( ! $certificate ) {
			return false;
		}

		$recipient = get_userdata( (int) $certificate->recipient_id );

		if ( ! $recipient || '' === (string) $recipient->user_email ) {
			return false;
		}

		$template = PressPrimer_Certificate_Template::get( (int) $certificate->template_id );
		$tokens   = self::tokens( $certificate, $recipient, $template );
		$merge    = is_array( $certificate->merge_data ) ? $certificate->merge_data : [];

		// The context always carries the certificate, template, and
		// recipient ids (2.0, S-007): resends and other thin callers pass
		// none of them, but filter consumers (School's copy headers,
		// Enterprise's white-label From/footer) need them. Caller keys
		// win on conflict.
		$context = array_merge(
			[
				'certificate_id' => (int) $certificate->id,
				'template_id'    => (int) $certificate->template_id,
				'recipient_id'   => (int) $certificate->recipient_id,
			],
			(array) $context
		);

		$content = self::assemble( $template, $tokens, $merge, (string) $recipient->user_email, 'issued', $context );

		// The PDF always attaches when rendering succeeds; the temp
		// file is deleted after sending (Feature 007 FR-006 - nothing
		// persists).
		$attachment_path = self::render_attachment( $certificate, $template );

		if ( '' !== $attachment_path ) {
			$content['attachments'][] = $attachment_path;
		}

		// The footer is the body's last line, after any attachment
		// fallback note (ppcert_email_footer, 2.0).
		$content['body'] = self::apply_footer( $content['body'], 'issued', $context );

		/** This filter is documented in docs/architecture/HOOKS.md */
		$content = apply_filters( 'ppcert_email_content', $content, 'issued', $context );

		$sent = false;

		if ( is_array( $content ) && ! empty( $content['to'] ) ) {
			$sent = wp_mail(
				$content['to'],
				(string) $content['subject'],
				(string) $content['body'],
				isset( $content['headers'] ) ? $content['headers'] : [],
				isset( $content['attachments'] ) ? $content['attachments'] : []
			);
		}

		if ( '' !== $attachment_path ) {
			wp_delete_file( $attachment_path );
		}

		return (bool) $sent;
	}

	/**
	 * Resend the delivery email for an existing certificate
	 *
	 * The Certificates screen's Resend action: rebuilds the email from
	 * the stored row and current settings, bypassing the
	 * automatic-email toggle because the staff click is explicit.
	 *
	 * @since 1.0.0
	 *
	 * @param int $certificate_id Certificate row id.
	 * @return bool Whether an email was sent.
	 */
	public static function resend( $certificate_id ) {
		$sent = self::send_issued( absint( $certificate_id ), [ 'resend' => true ], true );

		// Lifecycle event (2.0, Feature 2.0-006 FR-006): a resend records
		// as the reserved 'reissued' type - only when the mail actually
		// went out (a filter veto or mailer failure records nothing).
		if ( $sent ) {
			$actor = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;

			PressPrimer_Certificate_Certificate::record_event(
				absint( $certificate_id ),
				'reissued',
				$actor > 0 ? $actor : null
			);
		}

		return $sent;
	}

	/**
	 * Render the PDF attachment
	 *
	 * Always attached when rendering succeeds (Ryan, 2026-07-23: no
	 * size threshold - the email carries the certificate no matter
	 * what); a render failure degrades to link-only via the body's
	 * verification URL.
	 *
	 * @since 1.0.0
	 *
	 * @param object      $certificate Certificate row (hydrated).
	 * @param object|null $template    Template row.
	 * @return string Attachment temp path, or '' for link-only.
	 */
	private static function render_attachment( $certificate, $template ) {
		// Delegates to the public render step (2.0, Feature 2.0-007
		// FR-001) - one implementation for the email attachment and for
		// integrations attaching the PDF to their own emails. $template
		// is no longer consulted: the renderer loads it for the title.
		unset( $template );

		$path = PressPrimer_Certificate_PDF_Renderer::render_certificate( (int) $certificate->id, 'email' );

		return is_wp_error( $path ) ? '' : $path;
	}

	/**
	 * The token map for subject/body substitution
	 *
	 * @since 1.0.0
	 *
	 * @param object      $certificate Certificate row.
	 * @param object      $recipient   Recipient user.
	 * @param object|null $template    Template row.
	 * @return array Map of {token} => value.
	 */
	private static function tokens( $certificate, $recipient, $template ) {
		$merge = is_array( $certificate->merge_data ) ? $certificate->merge_data : [];

		$name = isset( $merge['recipient.full_name'] ) && '' !== $merge['recipient.full_name']
			? (string) $merge['recipient.full_name']
			: (string) $recipient->display_name;

		return [
			'{recipient_name}'   => $name,
			'{subject}'          => PressPrimer_Certificate_Certificate::display_title(
				$certificate,
				$template ? (string) $template->title : ''
			),
			'{credential_id}'    => PressPrimer_Certificate_Credential_ID_Service::format_display( (string) $certificate->credential_id ),
			'{verification_url}' => ppcert_verification_url( (string) $certificate->credential_id ),
			'{issuer_name}'      => (string) get_bloginfo( 'name' ),
			'{site_name}'        => (string) get_bloginfo( 'name' ),
		];
	}

	/**
	 * Substitute {tokens} into a template string
	 *
	 * Legacy single-brace tokens first (strtr, unchanged since 1.0),
	 * then {{group.field}} merge tokens from the certificate's snapshot
	 * via the renderer's shared grammar helper (1.1, Feature 1.1-005) -
	 * one substitution implementation across PDF text and email.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text   Template text.
	 * @param array  $tokens Token map.
	 * @param array  $merge  Merge data snapshot (token key => value).
	 * @return string
	 */
	private static function substitute( $text, $tokens, array $merge = [] ) {
		return PressPrimer_Certificate_PDF_Renderer::interpolate_tokens(
			strtr( (string) $text, $tokens ),
			$merge
		);
	}

	/**
	 * Build a recipient email for a certificate from caller-supplied
	 * subject/body templates (2.0, the shared builder addons send
	 * through - Educator's expiry reminders are the first consumer)
	 *
	 * The production token map and substitution - both token syntaxes,
	 * snapshot-first values, the configured From header - applied to
	 * ANY subject/body pair. The caller owns enablement, the
	 * ppcert_email_content filter, and the actual wp_mail().
	 *
	 * @since 2.0.0
	 *
	 * @param int    $certificate_id   Certificate row id.
	 * @param string $subject_template Subject with tokens.
	 * @param string $body_template    Body with tokens.
	 * @param string $email_type       Email type for the From/footer filters
	 *                                 (Educator reminders pass 'expiry_reminder').
	 *                                 Default 'recipient'.
	 * @param array  $context          Extra send context for the filters; the
	 *                                 certificate, template, and recipient ids
	 *                                 are always added.
	 * @return array|null Content array (to, subject, body, headers,
	 *                    attachments), or null when the certificate or
	 *                    its recipient cannot be resolved.
	 */
	public static function build_recipient_email( $certificate_id, $subject_template, $body_template, $email_type = 'recipient', array $context = [] ) {
		$certificate = PressPrimer_Certificate_Certificate::get( absint( $certificate_id ) );

		if ( ! $certificate ) {
			return null;
		}

		$recipient = get_userdata( (int) $certificate->recipient_id );

		if ( ! $recipient || '' === (string) $recipient->user_email ) {
			return null;
		}

		$template   = PressPrimer_Certificate_Template::get( (int) $certificate->template_id );
		$tokens     = self::tokens( $certificate, $recipient, $template );
		$merge      = is_array( $certificate->merge_data ) ? $certificate->merge_data : [];
		$email_type = sanitize_key( (string) $email_type );
		$context    = array_merge(
			[
				'certificate_id' => (int) $certificate->id,
				'template_id'    => (int) $certificate->template_id,
				'recipient_id'   => (int) $certificate->recipient_id,
			],
			$context
		);

		return [
			'to'          => (string) $recipient->user_email,
			'subject'     => self::substitute( (string) $subject_template, $tokens, $merge ),
			'body'        => self::apply_footer( self::substitute( (string) $body_template, $tokens, $merge ), $email_type, $context ),
			'headers'     => [ self::from_header( $email_type, $context ) ],
			'attachments' => [],
		];
	}

	/**
	 * The From header for one send, through the ppcert_email_from filter
	 * (2.0, Enterprise contract item 9)
	 *
	 * Every send path builds its From here - issuance, resend, the test
	 * send, the shared recipient builder (Educator reminders, School and
	 * Enterprise callers) - so a white-label identity applies once. The
	 * default is the Settings > Email from name and address; the filter
	 * receives that pair with the email type and context, and invalid
	 * results fall back field by field.
	 *
	 * @since 2.0.0
	 *
	 * @param string $email_type Email type (issued, test, expiry_reminder, ...).
	 * @param array  $context    Send context (certificate/template/recipient ids where known).
	 * @return string The From header line.
	 */
	public static function from_header( $email_type, array $context = [] ) {
		$settings = self::settings();
		$default  = [
			'name'    => (string) $settings['email_from_name'],
			'address' => (string) $settings['email_from_address'],
		];

		/**
		 * Filters the sender identity of every email the suite sends
		 * (2.0, Enterprise contract item 9 - white-label email identity).
		 *
		 * @since 2.0.0
		 *
		 * @param array  $from       { name: string, address: string } - the Settings > Email values.
		 * @param string $email_type Email type (issued, test, expiry_reminder, recipient, ...).
		 * @param array  $context    Send context.
		 */
		$from = apply_filters( 'ppcert_email_from', $default, (string) $email_type, $context );

		$name    = is_array( $from ) && isset( $from['name'] ) ? sanitize_text_field( (string) $from['name'] ) : '';
		$address = is_array( $from ) && isset( $from['address'] ) ? sanitize_email( (string) $from['address'] ) : '';

		if ( '' === $name ) {
			$name = $default['name'];
		}

		if ( '' === $address || ! is_email( $address ) ) {
			$address = $default['address'];
		}

		return 'From: ' . $name . ' <' . $address . '>';
	}

	/**
	 * Append the filtered footer to an email body (2.0, Enterprise
	 * contract item 9)
	 *
	 * Plain text, appended after a blank line as the body's LAST line;
	 * an empty footer (the default) leaves the body untouched. Applied
	 * by every send path as its final body step (after attachment
	 * fallback notes and test notes) and by build_recipient_email().
	 *
	 * @since 2.0.0
	 *
	 * @param string $body       Email body.
	 * @param string $email_type Email type.
	 * @param array  $context    Send context.
	 * @return string
	 */
	public static function apply_footer( $body, $email_type, array $context = [] ) {
		/**
		 * Filters the footer appended to every email the suite sends
		 * (2.0, Enterprise contract item 9). Plain text; empty means no
		 * footer.
		 *
		 * @since 2.0.0
		 *
		 * @param string $footer     Footer text. Default ''.
		 * @param string $email_type Email type.
		 * @param array  $context    Send context.
		 */
		$footer = apply_filters( 'ppcert_email_footer', '', (string) $email_type, $context );
		$footer = is_string( $footer ) ? trim( sanitize_textarea_field( $footer ) ) : '';

		if ( '' === $footer ) {
			return (string) $body;
		}

		return rtrim( (string) $body ) . "\n\n" . $footer;
	}

	/**
	 * The one email assembly (2.0, Feature 2.0-003 TR-002)
	 *
	 * Production issuance and the test send share this builder: the
	 * Decision 005 resolution chain picks the subject/body source, both
	 * token syntaxes substitute identically, and the From header comes
	 * from the same settings. The paths differ only in recipient, the
	 * [Test] prefix, and the data map (snapshot vs samples) - exactly
	 * the contract US-2 promises.
	 *
	 * @since 2.0.0
	 *
	 * @param object|null $template   Template row (hydrated), or null.
	 * @param array       $tokens     Legacy single-brace token map.
	 * @param array       $merge      Merge value map ({{group.field}}).
	 * @param string      $to         Recipient address.
	 * @param string      $email_type Email type for the From filter. Default 'issued'.
	 * @param array       $context    Send context for the From filter.
	 * @return array to / subject / body / headers / attachments.
	 */
	private static function assemble( $template, array $tokens, array $merge, $to, $email_type = 'issued', array $context = [] ) {
		$settings = self::settings();

		// Decision 005 resolution chain: the template's mapped active
		// email-template row when present, otherwise the built-in
		// default from settings. Substitution is identical either way.
		$resolved = self::resolve_content( $template );
		$subject  = null !== $resolved ? $resolved['subject'] : (string) $settings['email_issued_subject'];
		$body     = null !== $resolved ? $resolved['body'] : (string) $settings['email_issued_body'];

		return [
			'to'          => $to,
			'subject'     => self::substitute( $subject, $tokens, $merge ),
			'body'        => self::substitute( $body, $tokens, $merge ),
			'headers'     => [ self::from_header( $email_type, $context ) ],
			'attachments' => [],
		];
	}

	/**
	 * Send the test email for a template to the current user (2.0,
	 * Feature 2.0-003)
	 *
	 * The production assembly with the designer's sample map: the
	 * resolution chain, both token syntaxes, and the From header are the
	 * real send path. Differences, per the spec: the recipient is always
	 * the CURRENT USER (never request input), the subject carries a
	 * [Test] prefix, credential-dependent links target the verification
	 * page base (no credential exists), and no PDF attaches - a body
	 * note says so, since real award emails include it.
	 *
	 * @since 2.0.0
	 *
	 * @param object $template Template row (hydrated).
	 * @since 2.0.0 $template accepts null: the Settings > Email page's
	 *              test of the site-wide default (no template mapping,
	 *              the settings subject/body, template_id 0 in the
	 *              filter context).
	 *
	 * @return true|WP_Error True when the mail was accepted; WP_Error
	 *                       carrying the mailer's reason otherwise.
	 */
	public static function send_test( $template ) {
		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() || '' === (string) $user->user_email ) {
			return new WP_Error(
				'ppcert_test_email_no_recipient',
				__( 'Your account has no email address to send the test to.', 'pressprimer-certificate' )
			);
		}

		$samples = self::sample_merge_map( $template );

		$tokens = [
			'{recipient_name}'   => (string) $user->display_name,
			'{subject}'          => isset( $samples['certificate.title'] ) ? (string) $samples['certificate.title'] : ( $template && isset( $template->title ) ? (string) $template->title : '' ),
			'{credential_id}'    => isset( $samples['certificate.credential_id'] ) ? (string) $samples['certificate.credential_id'] : '',
			'{verification_url}' => ppcert_verification_page_url(),
			'{issuer_name}'      => (string) get_bloginfo( 'name' ),
			'{site_name}'        => (string) get_bloginfo( 'name' ),
		];

		$test_context = [
			// 0 = the settings-page test (no template context, 2.0).
			'template_id' => $template && isset( $template->id ) ? (int) $template->id : 0,
			'test'        => true,
		];

		$content = self::assemble( $template, $tokens, $samples, (string) $user->user_email, 'test', $test_context );

		/* translators: prefix marking a test email's subject line */
		$content['subject'] = __( '[Test]', 'pressprimer-certificate' ) . ' ' . $content['subject'];

		$content['body'] .= "\n\n" . __( 'This is a test of the award email, sent with sample values and without the PDF attachment. Real award emails include the certificate PDF.', 'pressprimer-certificate' );

		$content['body'] = self::apply_footer( $content['body'], 'test', $test_context );

		/** This filter is documented in docs/architecture/HOOKS.md */
		$content = apply_filters( 'ppcert_email_content', $content, 'test', $test_context );

		if ( ! is_array( $content ) || empty( $content['to'] ) ) {
			return new WP_Error(
				'ppcert_test_email_vetoed',
				__( 'The test email was blocked by a filter.', 'pressprimer-certificate' )
			);
		}

		// Capture the mailer's failure reason so the UI can report it
		// honestly (FR-001) instead of a generic shrug.
		$mail_error = null;
		$capture    = static function ( $wp_error ) use ( &$mail_error ) {
			$mail_error = $wp_error;
		};

		add_action( 'wp_mail_failed', $capture );

		$sent = wp_mail(
			$content['to'],
			(string) $content['subject'],
			(string) $content['body'],
			isset( $content['headers'] ) ? $content['headers'] : [],
			[]
		);

		remove_action( 'wp_mail_failed', $capture );

		if ( $sent ) {
			return true;
		}

		return new WP_Error(
			'ppcert_test_email_failed',
			is_wp_error( $mail_error ) && '' !== $mail_error->get_error_message()
				? $mail_error->get_error_message()
				: __( 'The site could not send the email. Check the WordPress email configuration.', 'pressprimer-certificate' )
		);
	}

	/**
	 * The designer's sample map, keyed for substitution (FR-003)
	 *
	 * Identical source to the canvas's Samples mode: every registered
	 * merge field's sample value, all groups (a template without a
	 * trigger still tests - source tokens show generic samples).
	 * certificate.title resolves from the template's certificate_name
	 * pattern against the samples, falling back to the template title -
	 * the same chain a real issuance runs.
	 *
	 * @since 2.0.0
	 *
	 * @param object $template Template row (hydrated).
	 * @return array Map of token key => sample value.
	 */
	private static function sample_merge_map( $template ) {
		$samples = [];

		foreach ( PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' ) as $field ) {
			if ( isset( $field['key'] ) && array_key_exists( 'sample', (array) $field ) ) {
				$samples[ (string) $field['key'] ] = (string) $field['sample'];
			}
		}

		$samples['certificate.title'] = PressPrimer_Certificate_Merge_Field_Registry::resolve_title(
			[
				'template_settings' => $template && isset( $template->settings ) && is_array( $template->settings ) ? $template->settings : [],
				'template_title'    => $template && isset( $template->title ) ? (string) $template->title : (string) get_bloginfo( 'name' ),
			],
			$samples
		);

		return $samples;
	}

	/**
	 * Resolve the effective email subject/body for a certificate template
	 *
	 * The Decision 005 resolution chain: when the template maps to an
	 * email-template row (settings_json.email_template_id) and that row
	 * is active, non-deleted, and of the requested context, its stored
	 * subject/body win; in every other state - no mapping, missing row,
	 * soft-deleted, archived, wrong context - the caller falls back to
	 * the built-in default. Works with the table empty and with no
	 * addons installed, and behaves identically for the future reminder
	 * context.
	 *
	 * @since 2.0.0
	 *
	 * @param object|null $template      Template row (hydrated, with
	 *                                   decoded settings), or null.
	 * @param string      $email_context Email context to match.
	 *                                   Default 'issuance'.
	 * @return array|null [ 'subject' => string, 'body' => string ], or
	 *                    null when the built-in default applies.
	 */
	public static function resolve_content( $template, $email_context = 'issuance' ) {
		$settings = $template && isset( $template->settings ) && is_array( $template->settings )
			? $template->settings
			: [];

		$mapped_id = isset( $settings['email_template_id'] ) ? absint( $settings['email_template_id'] ) : 0;

		if ( $mapped_id < 1 ) {
			return null;
		}

		$row = PressPrimer_Certificate_Email_Template::get_active( $mapped_id );

		if ( ! $row || (string) $row->context !== (string) $email_context ) {
			return null;
		}

		return [
			'subject' => (string) $row->subject,
			'body'    => (string) $row->body,
		];
	}

	/**
	 * Merge tokens referenced by the current email templates
	 *
	 * The issuance engine resolves these alongside the layout's tokens
	 * so the certificate's snapshot covers the email too (1.1, Feature
	 * 1.1-005). Tokens added to the settings AFTER a certificate was
	 * issued are not in its snapshot and render empty on resend -
	 * snapshot semantics, by design.
	 *
	 * Since 2.0 the tokens come from the EFFECTIVE content for the given
	 * template - the mapped email-template row when the resolution chain
	 * selects one, else the settings default - so a mapped row's tokens
	 * are collected at issue time too (the 1.1 lesson: every token
	 * surface must feed the issuance-path collection, because previews
	 * substitute the sample map and cannot catch collection gaps).
	 *
	 * @since 1.1.0
	 * @since 2.0.0 Accepts the template whose effective content applies.
	 *
	 * @param object|null $template Template row, or null for the
	 *                              settings default.
	 * @return string[] Unique token keys in inner form.
	 */
	public static function template_tokens( $template = null ) {
		$resolved = self::resolve_content( $template );

		if ( null !== $resolved ) {
			$subject = $resolved['subject'];
			$body    = $resolved['body'];
		} else {
			$settings = self::settings();
			$subject  = (string) $settings['email_issued_subject'];
			$body     = (string) $settings['email_issued_body'];
		}

		return PressPrimer_Certificate_Merge_Field_Registry::extract_tokens_from_text(
			$subject . "\n" . $body
		);
	}

	/**
	 * Effective email settings (stored values over defaults)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = [
			'email_issued_enabled' => 1,
			'email_issued_subject' => __( 'Your certificate: {subject}', 'pressprimer-certificate' ),
			'email_issued_body'    => __(
				"Hi {recipient_name},\n\nCongratulations! Your certificate for {subject} is now available.\n\nCredential ID: {credential_id}\nVerify it any time: {verification_url}\n\n{issuer_name}",
				'pressprimer-certificate'
			),
			'email_from_name'      => (string) get_bloginfo( 'name' ),
			'email_from_address'   => (string) get_bloginfo( 'admin_email' ),
		];

		$stored = get_option( 'ppcert_settings', [] );
		$stored = is_array( $stored ) ? $stored : [];

		foreach ( $defaults as $key => $default_value ) {
			if ( ! isset( $stored[ $key ] ) || '' === $stored[ $key ] ) {
				$stored[ $key ] = $default_value;
			}
		}

		return $stored;
	}
}
