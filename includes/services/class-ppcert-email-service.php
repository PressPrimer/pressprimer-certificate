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
	 * @return bool Whether an email was sent.
	 */
	public static function send_issued( $certificate_id, $context ) {
		$settings = self::settings();

		$enabled = ! empty( $settings['email_issued_enabled'] );

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

		$content = [
			'to'          => (string) $recipient->user_email,
			'subject'     => self::substitute( $settings['email_issued_subject'], $tokens ),
			'body'        => self::substitute( $settings['email_issued_body'], $tokens ),
			'headers'     => [
				'From: ' . $settings['email_from_name'] . ' <' . $settings['email_from_address'] . '>',
			],
			'attachments' => [],
		];

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
				'title'          => $template ? (string) $template->title : '',
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
			'{subject}'          => $template ? (string) $template->title : '',
			'{credential_id}'    => PressPrimer_Certificate_Credential_ID_Service::format_display( (string) $certificate->credential_id ),
			'{verification_url}' => ppcert_verification_url( (string) $certificate->credential_id ),
			'{issuer_name}'      => (string) get_bloginfo( 'name' ),
			'{site_name}'        => (string) get_bloginfo( 'name' ),
		];
	}

	/**
	 * Substitute {tokens} into a template string
	 *
	 * @since 1.0.0
	 *
	 * @param string $text   Template text.
	 * @param array  $tokens Token map.
	 * @return string
	 */
	private static function substitute( $text, $tokens ) {
		return strtr( (string) $text, $tokens );
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
