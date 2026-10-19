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
 * Since 2.1 every email is HTML in one fixed layout copied from
 * PressPrimer Assignment (Feature 2.1-001): a 600 px white card with
 * the Email logo (or the site name) on top and the site footer below.
 * Token values are escaped for HTML; {verification_url} becomes a link
 * and {certificate_button} a "View Certificate" button. The layout is
 * built from inline styles only (no <style> block), which email
 * clients, Outlook included, render most consistently.
 * `ppcert_email_format` returning 'text' restores the 2.0 plain text.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Email_Service {

	/**
	 * The layout's font stack (Assignment's)
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const FONT_STACK = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

	/**
	 * Largest logo box in pixels (Assignment's)
	 *
	 * @since 2.1.0
	 * @var int[]
	 */
	const LOGO_MAX = [ 400, 150 ];

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

		$recipient = self::recipient_for( $certificate, 'issued' );

		if ( null === $recipient ) {
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

		$content = self::assemble( $template, $tokens, $merge, $recipient['email'], 'issued', $context );

		// The PDF always attaches when rendering succeeds; the temp
		// file is deleted after sending (Feature 007 FR-006 - nothing
		// persists). A failed render sends without it.
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
	 * @since 2.1.0 The $actor_id argument.
	 *
	 * @param int      $certificate_id Certificate row id.
	 * @param int|null $actor_id       Acting user for the event (the bulk
	 *                                 service passes the job's creator);
	 *                                 null for the current user.
	 * @return bool Whether an email was sent.
	 */
	public static function resend( $certificate_id, $actor_id = null ) {
		return (bool) PressPrimer_Certificate_Certificate::with_actor(
			$actor_id,
			static function () use ( $certificate_id ) {
				$sent = self::send_issued( absint( $certificate_id ), [ 'resend' => true ], true );

				// Lifecycle event (2.0, Feature 2.0-006 FR-006): a resend
				// records as the reserved 'reissued' type - only when the
				// mail actually went out (a filter veto or mailer failure
				// records nothing).
				if ( $sent ) {
					PressPrimer_Certificate_Certificate::record_event(
						absint( $certificate_id ),
						'reissued',
						PressPrimer_Certificate_Certificate::current_actor()
					);
				}

				return $sent;
			}
		);
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
	 * Who a certificate's emails go to (Feature 2.1-004 FR-007)
	 *
	 * The recipient's account, or, for a one-off certificate (no
	 * account), its stored email; filtered through
	 * `ppcert_email_recipient`. Null means no email can be sent (no
	 * account, no stored email, or no valid address).
	 *
	 * @since 2.1.0
	 *
	 * @param object $certificate Certificate row (hydrated).
	 * @param string $email_type  Email type ('issued', 'expiry_reminder', ...).
	 * @return array|null { email, name }, or null.
	 */
	public static function recipient_for( $certificate, $email_type = 'issued' ) {
		$recipient = null;

		if ( (int) $certificate->recipient_id > 0 ) {
			$user = get_userdata( (int) $certificate->recipient_id );

			if ( $user && '' !== (string) $user->user_email ) {
				$recipient = [
					'email' => (string) $user->user_email,
					'name'  => (string) $user->display_name,
				];
			}
		} elseif ( PressPrimer_Certificate_Certificate::is_one_off( $certificate ) && ! empty( $certificate->recipient_email ) ) {
			$recipient = [
				'email' => (string) $certificate->recipient_email,
				'name'  => PressPrimer_Certificate_Certificate::recipient_name( $certificate ),
			];
		}

		/**
		 * Filters who a certificate's email goes to.
		 *
		 * @since 2.1.0
		 *
		 * @param array|null $recipient   { email, name }, or null for no email.
		 * @param object     $certificate Certificate row.
		 * @param string     $email_type  Email type.
		 */
		$recipient = apply_filters( 'ppcert_email_recipient', $recipient, $certificate, (string) $email_type );

		if ( ! is_array( $recipient ) || empty( $recipient['email'] ) || ! is_email( (string) $recipient['email'] ) ) {
			return null;
		}

		return [
			'email' => (string) $recipient['email'],
			'name'  => isset( $recipient['name'] ) ? (string) $recipient['name'] : '',
		];
	}

	/**
	 * The token map for subject/body substitution
	 *
	 * @since 1.0.0
	 * @since 2.1.0 $recipient is the { email, name } array from recipient_for().
	 *
	 * @param object      $certificate Certificate row.
	 * @param array       $recipient   Recipient { email, name }.
	 * @param object|null $template    Template row.
	 * @return array Map of {token} => value.
	 */
	private static function tokens( $certificate, $recipient, $template ) {
		$merge = is_array( $certificate->merge_data ) ? $certificate->merge_data : [];

		$name = isset( $merge['recipient.full_name'] ) && '' !== $merge['recipient.full_name']
			? (string) $merge['recipient.full_name']
			: (string) $recipient['name'];

		return [
			'{recipient_name}'     => $name,
			'{subject}'            => PressPrimer_Certificate_Certificate::display_title(
				$certificate,
				$template ? (string) $template->title : ''
			),
			'{credential_id}'      => PressPrimer_Certificate_Certificate::display_credential_id( $certificate ),
			'{verification_url}'   => ppcert_verification_url( (string) $certificate->credential_id ),
			// The certificate page, the button's target (2.1, Feature
			// 2.1-001 FR-005); the verification URL when no page URL
			// exists.
			'{certificate_button}' => self::certificate_url( $certificate ),
			'{issuer_name}'        => PressPrimer_Certificate_Merge_Field_Registry::issuer_name(
				[
					'template_id'    => (int) $certificate->template_id,
					'issuer_id'      => ! empty( $certificate->issuer_id ) ? (int) $certificate->issuer_id : 0,
					'certificate_id' => (int) $certificate->id,
				]
			),
			'{site_name}'          => (string) get_bloginfo( 'name' ),
		];
	}

	/**
	 * A certificate's page URL, else its verification URL (2.1)
	 *
	 * @since 2.1.0
	 *
	 * @param object $certificate Certificate row.
	 * @return string
	 */
	private static function certificate_url( $certificate ) {
		$url = PressPrimer_Certificate_View_Page::view_url(
			(string) $certificate->credential_id,
			isset( $certificate->credential_display ) ? (string) $certificate->credential_display : ''
		);

		return '' !== $url ? $url : ppcert_verification_url( (string) $certificate->credential_id );
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
	 * ppcert_email_content filter, and the actual wp_mail(). Since 2.1
	 * `body` is the full HTML document and `headers` carries the HTML
	 * Content-Type, so a 2.0.x caller's reminders arrive in the layout
	 * unchanged.
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

		$email_type = sanitize_key( (string) $email_type );
		$recipient  = self::recipient_for( $certificate, $email_type );

		if ( null === $recipient ) {
			return null;
		}

		$template = PressPrimer_Certificate_Template::get( (int) $certificate->template_id );
		$tokens   = self::tokens( $certificate, $recipient, $template );
		$merge    = is_array( $certificate->merge_data ) ? $certificate->merge_data : [];
		$context  = array_merge(
			[
				'certificate_id' => (int) $certificate->id,
				'template_id'    => (int) $certificate->template_id,
				'recipient_id'   => (int) $certificate->recipient_id,
			],
			$context
		);

		return self::compose( (string) $subject_template, (string) $body_template, $tokens, $merge, $recipient['email'], $email_type, $context );
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
	 * an empty footer (the default) leaves the body untouched. Since 2.1
	 * the HTML emails put the footer text in the layout footer instead
	 * (Feature 2.1-001 FR-007); this keeps its exact 2.0 behavior for
	 * the plain-text format and for 2.0.x callers (Educator 2.0.x's
	 * reminder test).
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
		$footer = self::footer_text( $email_type, $context );

		if ( '' === $footer ) {
			return (string) $body;
		}

		return rtrim( (string) $body ) . "\n\n" . $footer;
	}

	/**
	 * The filtered plain-text footer ('' by default)
	 *
	 * @since 2.1.0
	 *
	 * @param string $email_type Email type.
	 * @param array  $context    Send context.
	 * @return string
	 */
	private static function footer_text( $email_type, array $context ) {
		/** This filter is documented in includes/services/class-ppcert-email-service.php */
		$footer = apply_filters( 'ppcert_email_footer', '', (string) $email_type, $context );

		return is_string( $footer ) ? trim( sanitize_textarea_field( $footer ) ) : '';
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

		return self::compose( $subject, $body, $tokens, $merge, $to, $email_type, $context );
	}

	/**
	 * Build one email from a subject/body pair (2.1: HTML, or the 2.0
	 * plain text through `ppcert_email_format`)
	 *
	 * The one composition every send path uses (issuance, resend, the
	 * tests, build_recipient_email()). The subject is plain text in both
	 * formats.
	 *
	 * @since 2.1.0
	 *
	 * @param string $subject    Subject with tokens.
	 * @param string $body       Body with tokens (HTML, or 2.0 plain text).
	 * @param array  $tokens     Legacy single-brace token map.
	 * @param array  $merge      Merge value map ({{group.field}}).
	 * @param string $to         Recipient address.
	 * @param string $email_type Email type.
	 * @param array  $context    Send context.
	 * @param string $note       Optional closing note (test emails).
	 * @return array to / subject / body / headers / attachments.
	 */
	private static function compose( $subject, $body, array $tokens, array $merge, $to, $email_type, array $context, $note = '' ) {
		$plain = self::plain_tokens( $tokens );

		if ( 'text' === self::format( $email_type, $context ) ) {
			$text = self::substitute( self::text_from_html( $body ), $plain, $merge );

			if ( '' !== $note ) {
				$text = rtrim( $text ) . "\n\n" . $note;
			}

			return [
				'to'          => $to,
				'subject'     => self::substitute( $subject, $plain, $merge ),
				'body'        => self::apply_footer( $text, $email_type, $context ),
				'headers'     => [ self::from_header( $email_type, $context ) ],
				'attachments' => [],
			];
		}

		$message = self::substitute_html( self::body_html( $body ), $tokens, $merge );

		if ( '' !== $note ) {
			$message .= '<p>' . self::escape_text( $note ) . '</p>';
		}

		return [
			'to'          => $to,
			'subject'     => self::substitute( $subject, $plain, $merge ),
			'body'        => self::render_html( $message, $email_type, $context ),
			'headers'     => self::headers( $email_type, $context ),
			'attachments' => [],
		];
	}

	/**
	 * The email format for one send: 'html' (2.1 default) or 'text'
	 *
	 * @since 2.1.0
	 *
	 * @param string $email_type Email type.
	 * @param array  $context    Send context.
	 * @return string
	 */
	private static function format( $email_type, array $context ) {
		/**
		 * Filters the email format for one send (Feature 2.1-001 FR-009).
		 *
		 * Return 'text' to send the 2.0 plain-text email: no layout, no
		 * HTML Content-Type, HTML bodies converted to text.
		 *
		 * @since 2.1.0
		 *
		 * @param string $format     'html' (default) or 'text'.
		 * @param string $email_type Email type.
		 * @param array  $context    Send context.
		 */
		return 'text' === apply_filters( 'ppcert_email_format', 'html', (string) $email_type, $context ) ? 'text' : 'html';
	}

	/**
	 * The plain-text token map (subjects and the text format): the
	 * button becomes "View Certificate: URL"
	 *
	 * @since 2.1.0
	 *
	 * @param array $tokens Token map.
	 * @return array
	 */
	private static function plain_tokens( array $tokens ) {
		if ( isset( $tokens['{certificate_button}'] ) && '' !== (string) $tokens['{certificate_button}'] ) {
			$tokens['{certificate_button}'] = sprintf(
				/* translators: %s: certificate page URL */
				__( 'View Certificate: %s', 'pressprimer-certificate' ),
				(string) $tokens['{certificate_button}']
			);
		}

		return $tokens;
	}

	/**
	 * Substitute tokens into email HTML, escaping every value (2.1,
	 * Feature 2.1-001 FR-005)
	 *
	 * Legacy single-brace values and merge values are escaped for HTML
	 * (braces too, so a value can never be read as another token);
	 * tokens ending in `_url}` become links whose text is the URL;
	 * `{certificate_button}` (its value is the certificate page URL)
	 * becomes the "View Certificate" button, replacing a paragraph that
	 * holds nothing else. The PDF renderer's interpolation is shared
	 * and unchanged.
	 *
	 * @since 2.1.0
	 *
	 * @param string $text   Body HTML with tokens.
	 * @param array  $tokens Legacy token map ({token} => raw value).
	 * @param array  $merge  Merge value map (raw values).
	 * @return string
	 */
	public static function substitute_html( $text, array $tokens, array $merge = [] ) {
		$html = [];

		foreach ( $tokens as $token => $value ) {
			$token = (string) $token;
			$value = is_scalar( $value ) ? (string) $value : '';

			if ( '{certificate_button}' === $token ) {
				$html[ $token ] = '' === $value ? '' : self::button_html( $value, __( 'View Certificate', 'pressprimer-certificate' ) );
			} elseif ( '_url}' === substr( $token, -5 ) && '' !== $value ) {
				$html[ $token ] = '<a href="' . esc_url( $value ) . '">' . self::escape_text( $value ) . '</a>';
			} else {
				$html[ $token ] = self::escape_text( $value );
			}
		}

		// A block button inside a <p> is invalid markup: a paragraph that
		// holds only the button gives way to it.
		$text = (string) preg_replace( '#<p>\s*(\{certificate_button\})\s*</p>#i', '$1', (string) $text );

		$escaped = [];

		foreach ( $merge as $key => $value ) {
			$escaped[ $key ] = is_scalar( $value ) ? self::escape_text( (string) $value ) : '';
		}

		return PressPrimer_Certificate_PDF_Renderer::interpolate_tokens( strtr( $text, $html ), $escaped );
	}

	/**
	 * Escape a value for email HTML, braces included
	 *
	 * @since 2.1.0
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function escape_text( $value ) {
		return str_replace( [ '{', '}' ], [ '&#123;', '&#125;' ], esc_html( (string) $value ) );
	}

	/**
	 * A stored body as HTML (2.1)
	 *
	 * Bodies with HTML markup (the editor's) are used as they are;
	 * bodies without (2.0's plain text, the built-in default) keep their
	 * paragraphs and line breaks through wpautop(). Both pass kses.
	 *
	 * @since 2.1.0
	 *
	 * @param string $body Body template.
	 * @return string
	 */
	private static function body_html( $body ) {
		$body = (string) $body;

		if ( ! self::has_markup( $body ) ) {
			$body = wpautop( esc_html( $body ) );
		}

		return wp_kses_post( $body );
	}

	/**
	 * Whether a body holds HTML markup
	 *
	 * @since 2.1.0
	 *
	 * @param string $body Body.
	 * @return bool
	 */
	private static function has_markup( $body ) {
		return (bool) preg_match( '#</?[a-z][a-z0-9]*(\s[^>]*)?/?>#i', (string) $body );
	}

	/**
	 * A body as plain text (the 2.0 format): paragraphs and line breaks
	 * kept, tags removed
	 *
	 * @since 2.1.0
	 *
	 * @param string $body Body.
	 * @return string
	 */
	private static function text_from_html( $body ) {
		$body = (string) $body;

		if ( ! self::has_markup( $body ) ) {
			return $body;
		}

		$body = (string) preg_replace( '#<br\s*/?>#i', "\n", $body );
		$body = (string) preg_replace( '#</(p|div|h[1-6]|ul|ol)>#i', "\n\n", $body );
		$body = (string) preg_replace( '#</li>#i', "\n", $body );
		$body = (string) preg_replace( '#<li[^>]*>#i', '- ', $body );
		$body = html_entity_decode( wp_strip_all_tags( $body ), ENT_QUOTES, 'UTF-8' );
		$body = (string) preg_replace( "#[ \t]+\n#", "\n", $body );

		return trim( (string) preg_replace( "#\n{3,}#", "\n\n", $body ) );
	}

	/**
	 * Wrap message HTML in the email layout (2.1, Feature 2.1-001 FR-003)
	 *
	 * Assignment's look: a light gray page, a 600 px white card with 8 px
	 * corners and a soft shadow, the logo (or the site name) on top, the
	 * message in #4b5563 at line height 1.8, and the gray footer. Built
	 * as tables with inline styles. The message passes kses; the fixed
	 * markup is the service's own.
	 *
	 * @since 2.1.0
	 *
	 * @param string $message_html Message HTML (tokens already substituted).
	 * @param string $email_type   Email type.
	 * @param array  $context      Send context.
	 * @return string The full HTML document.
	 */
	public static function render_html( $message_html, $email_type, array $context = [] ) {
		/**
		 * Short-circuits the email document.
		 *
		 * Return a string to replace the whole HTML document (the layout,
		 * header, and footer); null keeps the built-in layout.
		 *
		 * @since 2.1.0
		 *
		 * @param string|null $html         Default null.
		 * @param string      $message_html The message HTML.
		 * @param string      $email_type   Email type.
		 * @param array       $context      Send context.
		 */
		$custom = apply_filters( 'ppcert_email_html', null, (string) $message_html, (string) $email_type, $context );

		if ( is_string( $custom ) ) {
			return $custom;
		}

		$message = self::style_message( wp_kses_post( (string) $message_html ) );
		$font    = 'font-family:' . self::FONT_STACK . ';';

		return '<!DOCTYPE html>'
			. '<html><head>'
			. '<meta charset="UTF-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
			. '</head>'
			. '<body' . self::style( 'margin:0;padding:0;background-color:#f5f5f5;' . $font ) . '>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"' . self::style( 'background-color:#f5f5f5;' ) . '><tr>'
			. '<td align="center"' . self::style( 'padding:20px 10px;' ) . '>'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"' . self::style( 'width:100%;max-width:600px;background-color:#ffffff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);border-collapse:separate;' ) . '>'
			. self::header_html( $email_type, $context )
			. '<tr><td' . self::style( 'padding:30px 20px;' . $font . 'font-size:16px;line-height:1.8;color:#4b5563;' ) . '>'
			. $message
			. '</td></tr>'
			. self::footer_html( $email_type, $context )
			. '</table>'
			. '</td></tr></table>'
			. '</body></html>';
	}

	/**
	 * The From header plus the HTML Content-Type (2.1)
	 *
	 * From stays at index 0, as in 2.0.
	 *
	 * @since 2.1.0
	 *
	 * @param string $email_type Email type.
	 * @param array  $context    Send context.
	 * @return string[]
	 */
	public static function headers( $email_type, array $context = [] ) {
		return [
			self::from_header( $email_type, $context ),
			'Content-Type: text/html; charset=UTF-8',
		];
	}

	/**
	 * The layout header row: the Email logo, else the site name
	 *
	 * @since 2.1.0
	 *
	 * @param string $email_type Email type.
	 * @param array  $context    Send context.
	 * @return string A <tr> of the card.
	 */
	private static function header_html( $email_type, array $context ) {
		$site = (string) get_bloginfo( 'name' );
		$logo = self::logo();

		if ( null !== $logo ) {
			$size  = $logo['width'] > 0 ? ' width="' . (int) $logo['width'] . '"' : '';
			$width = $logo['width'] > 0 ? 'width:' . (int) $logo['width'] . 'px;' : '';
			$inner = '<img src="' . esc_url( $logo['url'] ) . '"' . $size . ' alt="' . esc_attr( $site ) . '"'
				. self::style( 'display:block;margin:0 auto;border:0;' . $width . 'max-width:' . self::LOGO_MAX[0] . 'px;max-height:' . self::LOGO_MAX[1] . 'px;height:auto;' ) . '>';
		} else {
			$inner = '<h1' . self::style( 'margin:0;font-family:' . self::FONT_STACK . ';font-size:24px;font-weight:700;line-height:1.3;color:#1a1a1a;' ) . '>'
				. esc_html( $site ) . '</h1>';
		}

		$header = '<tr><td align="center"' . self::style( 'background-color:#ffffff;padding:30px 20px;text-align:center;border-bottom:1px solid #e5e5e5;border-radius:8px 8px 0 0;' ) . '>'
			. $inner . '</td></tr>';

		/**
		 * Filters the email header row (a <tr> of the 600 px card).
		 *
		 * @since 2.1.0
		 *
		 * @param string $header     Header row HTML.
		 * @param string $logo_url   The Email logo URL ('' when none).
		 * @param string $email_type Email type.
		 * @param array  $context    Send context.
		 */
		$filtered = apply_filters( 'ppcert_email_header_html', $header, null !== $logo ? $logo['url'] : '', (string) $email_type, $context );

		return is_string( $filtered ) ? $filtered : $header;
	}

	/**
	 * The layout footer row: "This email was sent from {site name}" and
	 * the site URL, or the `ppcert_email_footer` text in their place
	 * (2.1, Feature 2.1-001 FR-007)
	 *
	 * @since 2.1.0
	 *
	 * @param string $email_type Email type.
	 * @param array  $context    Send context.
	 * @return string A <tr> of the card.
	 */
	private static function footer_html( $email_type, array $context ) {
		$paragraph = self::style( 'margin:5px 0;' );
		$text      = self::footer_text( $email_type, $context );

		if ( '' !== $text ) {
			$lines = '<p' . $paragraph . '>' . nl2br( esc_html( $text ) ) . '</p>';
		} else {
			$lines = '<p' . $paragraph . '>'
				. esc_html(
					sprintf(
						/* translators: %s: site name */
						__( 'This email was sent from %s', 'pressprimer-certificate' ),
						(string) get_bloginfo( 'name' )
					)
				)
				. '</p><p' . $paragraph . '>' . esc_html( home_url() ) . '</p>';
		}

		$footer = '<tr><td align="center"' . self::style( 'background-color:#f9fafb;padding:20px;text-align:center;font-family:' . self::FONT_STACK . ';font-size:14px;line-height:1.6;color:#6b7280;border-top:1px solid #e5e5e5;border-radius:0 0 8px 8px;' ) . '>'
			. $lines . '</td></tr>';

		/**
		 * Filters the email footer row (a <tr> of the 600 px card), after
		 * the ppcert_email_footer text is applied.
		 *
		 * @since 2.1.0
		 *
		 * @param string $footer     Footer row HTML.
		 * @param string $email_type Email type.
		 * @param array  $context    Send context.
		 */
		$filtered = apply_filters( 'ppcert_email_footer_html', $footer, (string) $email_type, $context );

		return is_string( $filtered ) ? $filtered : $footer;
	}

	/**
	 * The Email logo: URL and display width, or null (2.1, TR-005)
	 *
	 * The width attribute keeps Outlook desktop, which ignores max-width,
	 * inside 400 x 150. A deleted attachment means no logo.
	 *
	 * @since 2.1.0
	 *
	 * @return array|null { url: string, width: int } (width 0 when unknown).
	 */
	private static function logo() {
		$settings = self::settings();
		$id       = isset( $settings['email_logo_id'] ) ? absint( $settings['email_logo_id'] ) : 0;
		$url      = $id > 0 ? wp_get_attachment_image_url( $id, 'full' ) : false;

		if ( ! $url ) {
			return null;
		}

		$meta   = wp_get_attachment_metadata( $id );
		$width  = is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$height = is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0;

		if ( $width > 0 && $height > 0 ) {
			$width = min( $width, self::LOGO_MAX[0], (int) floor( self::LOGO_MAX[1] * $width / $height ) );
		} else {
			$width = 0;
		}

		return [
			'url'   => (string) $url,
			'width' => max( 0, $width ),
		];
	}

	/**
	 * The "View Certificate" button (Assignment's button)
	 *
	 * @since 2.1.0
	 *
	 * @param string $url   Target URL.
	 * @param string $label Button text.
	 * @return string
	 */
	private static function button_html( $url, $label ) {
		return '<div' . self::style( 'text-align:center;margin:24px 0;' ) . '>'
			. '<a href="' . esc_url( $url ) . '"' . self::style( 'display:inline-block;padding:12px 30px;background-color:#3b82f6;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;' ) . '>'
			. esc_html( $label )
			. '</a></div>';
	}

	/**
	 * Inline paragraph and list spacing for the message (the layout has
	 * no stylesheet), and trailing empty paragraphs removed
	 *
	 * @since 2.1.0
	 *
	 * @param string $html Message HTML.
	 * @return string
	 */
	private static function style_message( $html ) {
		$styles = [
			'p'  => 'margin:0 0 15px;',
			'ul' => 'margin:0 0 15px;padding-left:24px;',
			'ol' => 'margin:0 0 15px;padding-left:24px;',
		];

		$html = (string) preg_replace_callback(
			'#<(p|ul|ol)(\s[^>]*)?>#i',
			static function ( $tag ) use ( $styles ) {
				$attributes = isset( $tag[2] ) ? $tag[2] : '';

				if ( false !== stripos( $attributes, 'style=' ) ) {
					return $tag[0];
				}

				return '<' . $tag[1] . $attributes . ' style="' . esc_attr( $styles[ strtolower( $tag[1] ) ] ) . '">';
			},
			(string) $html
		);

		return (string) preg_replace( '#(<p[^>]*>(\s|&nbsp;|<br\s*/?>)*</p>\s*)+$#i', '', $html );
	}

	/**
	 * A style attribute
	 *
	 * @since 2.1.0
	 *
	 * @param string $css Declarations.
	 * @return string
	 */
	private static function style( $css ) {
		return ' style="' . esc_attr( $css ) . '"';
	}

	/**
	 * Send the test email for a template (2.0, Feature 2.0-003)
	 *
	 * The production assembly with the designer's sample map: the
	 * resolution chain picks the subject and body, then
	 * send_test_message() sends them (2.1). The Settings > Email test
	 * passes a null template (the site-wide default, template_id 0 in
	 * the filter context).
	 *
	 * @since 2.0.0
	 * @since 2.1.0 The optional $to address (Feature 2.1-001 FR-006).
	 *
	 * @param object|null $template Template row (hydrated), or null.
	 * @param string      $to       Recipient address; '' for the current user.
	 * @return true|WP_Error True when the mail was accepted; WP_Error
	 *                       carrying the reason otherwise.
	 */
	public static function send_test( $template, $to = '' ) {
		$settings = self::settings();
		$resolved = self::resolve_content( $template );

		return self::send_test_message(
			[
				'subject'  => null !== $resolved ? $resolved['subject'] : (string) $settings['email_issued_subject'],
				'body'     => null !== $resolved ? $resolved['body'] : (string) $settings['email_issued_body'],
				'to'       => (string) $to,
				'template' => $template,
			]
		);
	}

	/**
	 * Send any subject/body through the test pipeline (2.1, Feature
	 * 2.1-004 FR-003)
	 *
	 * The shared test send: the Settings test, the designer's Award-tab
	 * test, and Educator's reminder and template tests. Sample values
	 * (the designer's sample map; the recipient name is the current
	 * user's), the [Test] subject prefix, the HTML layout, a closing note,
	 * no PDF. The recipient is the current user unless `to` names
	 * another address; callers validate that address and own the
	 * throttle.
	 *
	 * @since 2.1.0
	 *
	 * @param array $args {
	 *     @type string      $subject      Subject with tokens. Required.
	 *     @type string      $body         Body with tokens (HTML or 2.0 plain text).
	 *     @type string      $to           Recipient; '' for the current user.
	 *     @type string      $email_type   Email type for the filters. Default 'test'.
	 *     @type object|null $template     Template for samples, issuer, and context.
	 *     @type array       $extra_tokens Extra {token} => value pairs (e.g. {expiry_date}).
	 *     @type string|null $note         Closing note; null for the award-email note, '' for none.
	 *     @type array       $context      Extra filter context.
	 * }
	 * @return true|WP_Error
	 */
	public static function send_test_message( array $args ) {
		$template = isset( $args['template'] ) && is_object( $args['template'] ) ? $args['template'] : null;
		$user     = wp_get_current_user();
		$to       = isset( $args['to'] ) ? trim( (string) $args['to'] ) : '';

		if ( '' === $to ) {
			if ( ! $user || ! $user->exists() || '' === (string) $user->user_email ) {
				return new WP_Error(
					'ppcert_test_email_no_recipient',
					__( 'Your account has no email address to send the test to.', 'pressprimer-certificate' )
				);
			}

			$to = (string) $user->user_email;
		} else {
			$to = sanitize_email( $to );

			if ( '' === $to || ! is_email( $to ) ) {
				return new WP_Error(
					'ppcert_test_email_invalid_recipient',
					__( 'Enter a valid email address to send the test to.', 'pressprimer-certificate' )
				);
			}
		}

		$email_type = isset( $args['email_type'] ) && '' !== $args['email_type'] ? sanitize_key( (string) $args['email_type'] ) : 'test';
		$samples    = self::sample_merge_map( $template );

		$tokens = array_merge(
			[
				'{recipient_name}'     => $user && $user->exists() ? (string) $user->display_name : '',
				'{subject}'            => isset( $samples['certificate.title'] ) ? (string) $samples['certificate.title'] : ( $template && isset( $template->title ) ? (string) $template->title : '' ),
				'{credential_id}'      => isset( $samples['certificate.credential_id'] ) ? (string) $samples['certificate.credential_id'] : '',
				// No credential exists for a test: credential links target
				// the verification page itself.
				'{verification_url}'   => ppcert_verification_page_url(),
				'{certificate_button}' => ppcert_verification_page_url(),
				'{issuer_name}'        => PressPrimer_Certificate_Merge_Field_Registry::issuer_name(
					[
						'template_id' => $template && isset( $template->id ) ? (int) $template->id : 0,
						'issuer_id'   => $template && ! empty( $template->issuer_id ) ? (int) $template->issuer_id : 0,
						'test'        => true,
					]
				),
				'{site_name}'          => (string) get_bloginfo( 'name' ),
			],
			isset( $args['extra_tokens'] ) && is_array( $args['extra_tokens'] ) ? $args['extra_tokens'] : []
		);

		$context = array_merge(
			[
				// 0 = the settings-page test (no template context, 2.0).
				'template_id' => $template && isset( $template->id ) ? (int) $template->id : 0,
				'test'        => true,
			],
			isset( $args['context'] ) && is_array( $args['context'] ) ? $args['context'] : []
		);

		$note = array_key_exists( 'note', $args ) && null !== $args['note']
			? (string) $args['note']
			: __( 'This is a test of the award email, sent with sample values and without the PDF attachment. Real award emails include the certificate PDF.', 'pressprimer-certificate' );

		$content = self::compose(
			isset( $args['subject'] ) ? (string) $args['subject'] : '',
			isset( $args['body'] ) ? (string) $args['body'] : '',
			$tokens,
			$samples,
			$to,
			$email_type,
			$context,
			$note
		);

		/* translators: prefix marking a test email's subject line */
		$content['subject'] = __( '[Test]', 'pressprimer-certificate' ) . ' ' . $content['subject'];

		/** This filter is documented in docs/architecture/HOOKS.md */
		$content = apply_filters( 'ppcert_email_content', $content, $email_type, $context );

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
	 * The Decision 005 resolution chain. The row ID comes from
	 * `ppcert_email_template_id`, whose default is 0: free alone always
	 * sends the built-in default, and Educator returns the stored mapping
	 * (settings_json.email_template_id for `issuance`,
	 * reminder_email_template_id for `reminder`), so mappings apply only
	 * while Educator is active (2.1, Feature 2.1-004 FR-003). A row that
	 * is active, non-deleted, and of the requested context supplies the
	 * subject/body; in every other state the caller falls back to the
	 * built-in default. `ppcert_email_resolved_content` has the last word.
	 *
	 * @since 2.0.0
	 * @since 2.1.0 The ID filter (default 0), the reminder key, and the
	 *              resolved-content filter.
	 *
	 * @param object|null $template      Template row (hydrated, with
	 *                                   decoded settings), or null.
	 * @param string      $email_context Email context to match.
	 *                                   Default 'issuance'.
	 * @return array|null [ 'subject' => string, 'body' => string ], or
	 *                    null when the built-in default applies.
	 */
	public static function resolve_content( $template, $email_context = 'issuance' ) {
		$email_context = (string) $email_context;
		$settings      = $template && isset( $template->settings ) && is_array( $template->settings )
			? $template->settings
			: [];

		$key       = array_search( $email_context, PressPrimer_Certificate_Template::EMAIL_MAPPING_KEYS, true );
		$stored_id = false !== $key && isset( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;

		/**
		 * Filters which email-template row a certificate template's email
		 * uses (Decision 005).
		 *
		 * @since 2.1.0
		 *
		 * @param int         $id            Row ID to use; 0 for the built-in default.
		 *                                   Default 0 (free ignores stored mappings).
		 * @param int         $stored_id     The mapping stored in the template's settings.
		 * @param object|null $template      Template row, or null.
		 * @param string      $email_context Email context ('issuance', 'reminder').
		 */
		$mapped_id = absint( apply_filters( 'ppcert_email_template_id', 0, $stored_id, $template, $email_context ) );
		$content   = null;

		if ( $mapped_id > 0 ) {
			$row = PressPrimer_Certificate_Email_Template::get_active( $mapped_id );

			if ( $row && (string) $row->context === $email_context ) {
				$content = [
					'subject' => (string) $row->subject,
					'body'    => (string) $row->body,
				];
			}
		}

		/**
		 * Filters the resolved email content.
		 *
		 * @since 2.1.0
		 *
		 * @param array|null  $content       { subject, body }, or null for the built-in default.
		 * @param object|null $template      Template row, or null.
		 * @param string      $email_context Email context.
		 */
		$content = apply_filters( 'ppcert_email_resolved_content', $content, $template, $email_context );

		if ( ! is_array( $content ) || ! isset( $content['subject'], $content['body'] )
			|| ! is_string( $content['subject'] ) || ! is_string( $content['body'] ) ) {
			return null;
		}

		return [
			'subject' => $content['subject'],
			'body'    => $content['body'],
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
	 * Since 2.1 the tokens of every mapped email context are collected
	 * (Feature 2.1-004 FR-004): the issuance content plus the reminder
	 * content when one resolves, so a token used only in a reminder is
	 * snapshotted too and never renders empty.
	 *
	 * @since 1.1.0
	 * @since 2.0.0 Accepts the template whose effective content applies.
	 * @since 2.1.0 Includes the resolved reminder content.
	 *
	 * @param object|null $template Template row, or null for the
	 *                              settings default.
	 * @return string[] Unique token keys in inner form.
	 */
	public static function template_tokens( $template = null ) {
		$resolved = self::resolve_content( $template );

		if ( null !== $resolved ) {
			$texts = [ $resolved['subject'], $resolved['body'] ];
		} else {
			$settings = self::settings();
			$texts    = [ (string) $settings['email_issued_subject'], (string) $settings['email_issued_body'] ];
		}

		$reminder = self::resolve_content( $template, 'reminder' );

		if ( null !== $reminder ) {
			$texts[] = $reminder['subject'];
			$texts[] = $reminder['body'];
		}

		return PressPrimer_Certificate_Merge_Field_Registry::extract_tokens_from_text( implode( "\n", $texts ) );
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
			// Since 2.1 the default carries the View Certificate button
			// (Feature 2.1-001 FR-005); stored custom bodies never change.
			'email_issued_body'    => __(
				"Hi {recipient_name},\n\nCongratulations! Your certificate for {subject} is now available.\n\n{certificate_button}\n\nCredential ID: {credential_id}\nVerify it any time: {verification_url}\n\n{issuer_name}",
				'pressprimer-certificate'
			),
			'email_from_name'      => (string) get_bloginfo( 'name' ),
			'email_from_address'   => (string) get_bloginfo( 'admin_email' ),
			'email_logo_id'        => 0,
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
