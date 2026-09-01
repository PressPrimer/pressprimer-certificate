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

		$content = self::assemble( $template, $tokens, $merge, (string) $recipient->user_email );

		// The PDF always attaches when rendering succeeds; the temp
		// file is deleted after sending (Feature 007 FR-006 - nothing
		// persists).
		$attachment_path = self::render_attachment( $certificate, $template );

		if ( '' !== $attachment_path ) {
			$content['attachments'][] = $attachment_path;
		}

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
		if ( ! is_array( $certificate->layout_snapshot ) ) {
			return '';
		}

		$renderer = new PressPrimer_Certificate_PDF_Renderer();

		$path = $renderer->render_pdf(
			$certificate->layout_snapshot,
			is_array( $certificate->merge_data ) ? $certificate->merge_data : [],
			[
				'context'        => 'email',
				'certificate_id' => (int) $certificate->id,
				'credential_id'  => (string) $certificate->credential_id,
				'title'          => PressPrimer_Certificate_Certificate::display_title(
					$certificate,
					$template ? (string) $template->title : ''
				),
				'recipient_name' => isset( $certificate->merge_data['recipient.full_name'] ) ? (string) $certificate->merge_data['recipient.full_name'] : '',
			]
		);

		if ( is_wp_error( $path ) ) {
			return '';
		}

		// A recipient-friendly filename: mail clients type the attachment
		// by extension (the raw wp_tempnam name is a .tmp).
		$friendly = dirname( $path ) . '/certificate-'
			. PressPrimer_Certificate_Credential_ID_Service::format_display( (string) $certificate->credential_id )
			. '.pdf';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Temp-to-temp rename in the same directory; WP_Filesystem is for user-visible storage.
		if ( rename( $path, $friendly ) ) {
			return $friendly;
		}

		return $path;
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
	 * @param object|null $template Template row (hydrated), or null.
	 * @param array       $tokens   Legacy single-brace token map.
	 * @param array       $merge    Merge value map ({{group.field}}).
	 * @param string      $to       Recipient address.
	 * @return array to / subject / body / headers / attachments.
	 */
	private static function assemble( $template, array $tokens, array $merge, $to ) {
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
			'headers'     => [
				'From: ' . $settings['email_from_name'] . ' <' . $settings['email_from_address'] . '>',
			],
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
			'{subject}'          => isset( $samples['certificate.title'] ) ? (string) $samples['certificate.title'] : (string) $template->title,
			'{credential_id}'    => isset( $samples['certificate.credential_id'] ) ? (string) $samples['certificate.credential_id'] : '',
			'{verification_url}' => ppcert_verification_page_url(),
			'{issuer_name}'      => (string) get_bloginfo( 'name' ),
			'{site_name}'        => (string) get_bloginfo( 'name' ),
		];

		$content = self::assemble( $template, $tokens, $samples, (string) $user->user_email );

		/* translators: prefix marking a test email's subject line */
		$content['subject'] = __( '[Test]', 'pressprimer-certificate' ) . ' ' . $content['subject'];

		$content['body'] .= "\n\n" . __( 'This is a test of the award email, sent with sample values and without the PDF attachment. Real award emails include the certificate PDF.', 'pressprimer-certificate' );

		/** This filter is documented in docs/architecture/HOOKS.md */
		$content = apply_filters(
			'ppcert_email_content',
			$content,
			'test',
			[
				'template_id' => (int) $template->id,
				'test'        => true,
			]
		);

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
				'template_settings' => isset( $template->settings ) && is_array( $template->settings ) ? $template->settings : [],
				'template_title'    => (string) $template->title,
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
