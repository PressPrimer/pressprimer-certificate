<?php
/**
 * Email opt-in service
 *
 * Consent state, eligibility resolution, and the outbound relay for
 * the plugin's single email ask (the free onboarding email course),
 * following the Assignment 2.2 opt-in model. The hard rules:
 *
 * - Nothing leaves the site until a user types an email and clicks
 *   the affirmative button. The relay carries the email address and
 *   the source surface tag ONLY - no segments, no environment data.
 * - One answer, remembered forever: opting in or declining on any
 *   surface permanently silences every surface, including tour
 *   relaunches. Per-surface dismissals are stored separately and are
 *   equally permanent.
 * - Relay failure is silent; the local consent record is still
 *   written and every surface completes identically.
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
 * Email opt-in service class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Email_Optin_Service {

	/**
	 * User meta key: consent record
	 *
	 * One record per user: [ 'status' => 'opted_in'|'declined',
	 * 'source' => surface, 'timestamp' => unix ]. First answer wins;
	 * it is never overwritten.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_CONSENT = 'ppcert_email_optin';

	/**
	 * User meta key: per-surface dismissals
	 *
	 * Map of surface => unix timestamp. Dismissing a surface (closing
	 * its card without answering) hides that surface permanently but
	 * leaves the others available.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_DISMISSALS = 'ppcert_email_optin_dismissals';

	/**
	 * Default intake endpoint on pressprimer.com
	 *
	 * A FluentCRM incoming webhook subscribing to the shared
	 * PressPrimer list with the certificate-free tag (list, tag, and
	 * status are configured on the webhook itself, matching the
	 * Assignment plugin's assignment-free webhook).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const DEFAULT_INTAKE_URL = 'https://pressprimer.com/?fluentcrm=1&route=contact&hash=819ed174-c993-48d2-8e16-168639c277a4';

	/**
	 * Valid source surfaces
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	const SOURCES = [ 'wizard', 'dashboard-card' ];

	/**
	 * Get the intake endpoint URL
	 *
	 * Overridable via the PPCERT_EMAIL_INTAKE_URL constant and the
	 * filter below. An empty value cleanly disables every opt-in
	 * surface.
	 *
	 * @since 1.0.0
	 *
	 * @return string Intake URL, or '' when disabled.
	 */
	public static function get_intake_url() {
		$url = defined( 'PPCERT_EMAIL_INTAKE_URL' )
			? PPCERT_EMAIL_INTAKE_URL
			: self::DEFAULT_INTAKE_URL;

		/**
		 * Filters the email opt-in intake endpoint URL.
		 *
		 * Return an empty string (or false) to disable every opt-in
		 * surface without errors.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url Intake endpoint URL.
		 */
		$url = apply_filters( 'ppcert_email_intake_url', $url );

		if ( empty( $url ) || ! is_string( $url ) ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * Check whether the opt-in system is enabled at all
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when an intake URL is configured.
	 */
	public static function is_enabled() {
		return '' !== self::get_intake_url();
	}

	/**
	 * Check whether a surface slug is valid
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Surface slug.
	 * @return bool True when known.
	 */
	public static function is_valid_source( $source ) {
		return in_array( $source, self::SOURCES, true );
	}

	/**
	 * Get a user's consent record
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 * @return array|null [ status, source, timestamp ] or null when unanswered.
	 */
	public static function get_consent( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return null;
		}

		$consent = get_user_meta( $user_id, self::META_CONSENT, true );

		if (
			! is_array( $consent )
			|| empty( $consent['status'] )
			|| ! in_array( $consent['status'], [ 'opted_in', 'declined' ], true )
		) {
			return null;
		}

		return $consent;
	}

	/**
	 * Check whether the user has answered the ask anywhere
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 * @return bool True when opted in or declined.
	 */
	public static function has_answered( $user_id ) {
		return null !== self::get_consent( $user_id );
	}

	/**
	 * Record an opt-in
	 *
	 * First answer wins: if the user has already answered (either
	 * way), nothing is written.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $source  Surface the answer came from.
	 * @return bool True when the record was written.
	 */
	public static function record_opt_in( $user_id, $source ) {
		return self::record_answer( $user_id, 'opted_in', $source );
	}

	/**
	 * Record a decline
	 *
	 * Same permanence as an opt-in: no surface ever asks this user
	 * again, including wizard relaunches.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $source  Surface the answer came from.
	 * @return bool True when the record was written.
	 */
	public static function record_decline( $user_id, $source ) {
		return self::record_answer( $user_id, 'declined', $source );
	}

	/**
	 * Write the consent record (first answer wins)
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $status  'opted_in' or 'declined'.
	 * @param string $source  Surface slug.
	 * @return bool True when written.
	 */
	private static function record_answer( $user_id, $status, $source ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! self::is_valid_source( $source ) ) {
			return false;
		}

		if ( self::has_answered( $user_id ) ) {
			return false;
		}

		update_user_meta(
			$user_id,
			self::META_CONSENT,
			[
				'status'    => $status,
				'source'    => $source,
				'timestamp' => time(),
			]
		);

		return true;
	}

	/**
	 * Record a per-surface dismissal
	 *
	 * Dismissing a surface (closing its card without answering) hides
	 * that surface permanently but leaves the others available.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $surface Surface slug.
	 * @return bool True when written.
	 */
	public static function record_dismissal( $user_id, $surface ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! self::is_valid_source( $surface ) ) {
			return false;
		}

		$dismissals = get_user_meta( $user_id, self::META_DISMISSALS, true );

		if ( ! is_array( $dismissals ) ) {
			$dismissals = [];
		}

		$dismissals[ $surface ] = time();
		update_user_meta( $user_id, self::META_DISMISSALS, $dismissals );

		return true;
	}

	/**
	 * Check whether the user dismissed a specific surface
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $surface Surface slug.
	 * @return bool True when dismissed.
	 */
	public static function is_dismissed( $user_id, $surface ) {
		$user_id = absint( $user_id );

		if ( ! $user_id ) {
			return false;
		}

		$dismissals = get_user_meta( $user_id, self::META_DISMISSALS, true );

		return is_array( $dismissals ) && isset( $dismissals[ $surface ] );
	}

	/**
	 * Resolve whether the ask may show on a surface for a user
	 *
	 * Administrators only, on every surface (matching the Assignment
	 * rule): teachers may see the guided tour but are never offered
	 * the opt-in - the gate lives here so no surface, current or
	 * future, can leak the ask to them.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $user_id User ID.
	 * @param string $surface Surface slug.
	 * @return bool True when the surface may ask.
	 */
	public static function is_eligible( $user_id, $surface ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! self::is_valid_source( $surface ) ) {
			return false;
		}

		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return false;
		}

		if ( ! self::is_enabled() ) {
			return false;
		}

		if ( self::has_answered( $user_id ) ) {
			return false;
		}

		if ( self::is_dismissed( $user_id, $surface ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Relay an opt-in to the pressprimer.com intake
	 *
	 * Non-blocking fire-and-forget: failure is silent by design - the
	 * local consent record is the source of truth. The receiving side
	 * is a FluentCRM incoming webhook (list/tag/status configured on
	 * the webhook itself; subscription starts immediately, every email
	 * carries an unsubscribe link). The payload is the email address
	 * and the source surface tag ONLY.
	 *
	 * @since 1.0.0
	 *
	 * @param string $email  Opted-in email address (already validated).
	 * @param string $source Surface the opt-in came from.
	 */
	public static function relay_opt_in( $email, $source ) {
		$url = self::get_intake_url();

		if ( '' === $url ) {
			return;
		}

		wp_remote_post(
			$url,
			[
				'timeout'  => 2,
				'blocking' => false,
				'body'     => [
					'email'  => $email,
					'source' => $source,
				],
			]
		);
	}
}
