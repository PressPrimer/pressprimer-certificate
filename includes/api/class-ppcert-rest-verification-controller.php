<?php
/**
 * Verification REST controller
 *
 * The public credential lookup endpoint.
 *
 * @package PressPrimer_Certificate
 * @subpackage API
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verification REST controller class
 *
 * THE PLUGIN'S HIGHEST-EXPOSURE SURFACE (CODE-STRUCTURE rule 2, Feature
 * 006): public, unauthenticated, and therefore paranoid. Request flow:
 *
 *  1. Rate limit - 429 before any other work (FR-004; cheap by design)
 *  2. Normalize + checksum gate - a malformed credential never reaches
 *     the database, and its response is byte-identical in shape to a
 *     missing one (no oracle distinguishing the two)
 *  3. One prepared, indexed lookup (template title joined - no 2nd query)
 *  4. Authoritative status precedence: revoked > expired > valid
 *  5. ppcert_verification_result filter, then core RE-ASSERTS valid and
 *     status - addons may brand or extend, never weaken
 *  6. verified event: privacy-minimal (no IP, actor null unless logged in)
 *
 * Responses carry Cache-Control: no-store (TR-003 - status can change).
 * The response shape is locked by a snapshot test: exactly valid, status,
 * recipient_name, subject, issuer_name, issued_at (ISO 8601 UTC),
 * expires_at (nullable).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_REST_Verification_Controller {

	/**
	 * Default lookups allowed per window (override: PPCERT_VERIFY_RATE_LIMIT)
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const DEFAULT_RATE_LIMIT = 30;

	/**
	 * Default rate window in seconds (override: PPCERT_VERIFY_RATE_WINDOW)
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const DEFAULT_RATE_WINDOW = 60;

	/**
	 * Initialize the controller
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the public verification route
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			'ppcert/v1',
			'/verify/(?P<credential_id>[A-Za-z0-9\-_.]{1,40})',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'verify' ],
				'permission_callback' => '__return_true', // Public by design (FR-003).
				'args'                => [
					'credential_id' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);
	}

	/**
	 * Handle a verification lookup
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function verify( $request ) {
		// Step 1: rate limit before ANY other work (FR-004).
		if ( ! self::within_rate_limit() ) {
			$response = new WP_REST_Response(
				[ 'message' => __( 'Too many verification requests. Please wait a minute and try again.', 'pressprimer-certificate' ) ],
				429
			);
			$response->header( 'Cache-Control', 'no-store' );
			$response->header( 'Retry-After', (string) self::rate_window() );

			return $response;
		}

		return $this->respond( self::lookup( (string) $request->get_param( 'credential_id' ) ) );
	}

	/**
	 * The lookup pipeline (FR-003) - shared by the REST route and the
	 * verification page's server-side render (no-JS fallback and direct
	 * links), so exactly one code path resolves credentials
	 *
	 * NOT rate-limited itself: callers gate first (the REST route above;
	 * the page render via check_rate_limit()).
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw Raw credential input.
	 * @return array The locked-shape result array.
	 */
	public static function lookup( $raw ) {
		// Checksum gate. A malformed ID cannot exist, so it skips the
		// database and returns the identical not-found shape (the "check
		// for typos" UX runs client-side in the shortcode, never here -
		// no oracle).
		if ( ! PressPrimer_Certificate_Credential_ID_Service::is_well_formed( $raw ) ) {
			return self::not_found_result();
		}

		// The single prepared, indexed lookup.
		$certificate = PressPrimer_Certificate_Certificate::get_for_verification( $raw );

		if ( ! $certificate ) {
			return self::not_found_result();
		}

		// Authoritative status.
		$effective = PressPrimer_Certificate_Certificate::effective_status( $certificate );
		$status    = 'issued' === $effective ? 'valid' : $effective;
		$valid     = 'valid' === $status;

		$result = [
			'valid'          => $valid,
			'status'         => $status,
			'recipient_name' => self::snapshot_recipient_name( $certificate ),
			'subject'        => PressPrimer_Certificate_Certificate::display_title( $certificate ),
			'issuer_name'    => self::snapshot_issuer_name( $certificate ),
			'issued_at'      => self::to_iso8601( (string) $certificate->issued_at ),
			'expires_at'     => ! empty( $certificate->expires_at ) ? self::to_iso8601( (string) $certificate->expires_at ) : null,
		];

		// Brand/extend filter with post-filter re-assertion - addons can
		// never weaken the verdict.
		/** This filter is documented in docs/architecture/HOOKS.md */
		$filtered = apply_filters( 'ppcert_verification_result', $result, $certificate );

		if ( ! is_array( $filtered ) ) {
			$filtered = $result;
		}

		$filtered['valid']  = $valid;
		$filtered['status'] = $status;

		// Privacy-minimal verified event (no IP, no user agent; actor
		// only when a logged-in user performed the lookup). Site admins
		// never record: browsers preload the verify links that admin
		// screens list, writing phantom verifications while an admin
		// merely browses (observed 2026-07-24). Teacher and public
		// checks always count.
		if ( ! current_user_can( 'manage_options' ) ) {
			$actor = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
			PressPrimer_Certificate_Certificate::record_event(
				(int) $certificate->id,
				'verified',
				$actor > 0 ? $actor : null
			);
		}

		return $filtered;
	}

	/**
	 * Public rate-limit check for non-REST callers (the page's
	 * server-side render path)
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the request is within the limit.
	 */
	public static function check_rate_limit() {
		return self::within_rate_limit();
	}

	/**
	 * The not-found result - byte-identical shape to a found result
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function not_found_result() {
		return [
			'valid'          => false,
			'status'         => 'not_found',
			'recipient_name' => '',
			'subject'        => '',
			'issuer_name'    => '',
			'issued_at'      => null,
			'expires_at'     => null,
		];
	}

	/**
	 * Build the response with the mandatory cache header
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Response payload.
	 * @return WP_REST_Response
	 */
	private function respond( $data ) {
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Rate limit check: salted-hash keyed transient counter (FR-004)
	 *
	 * The raw IP is never stored - the key is wp_hash( ip . wp_salt() ).
	 * Limits are constants-configurable.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the request is within the limit.
	 */
	private static function within_rate_limit() {
		$limit = defined( 'PPCERT_VERIFY_RATE_LIMIT' ) ? (int) PPCERT_VERIFY_RATE_LIMIT : self::DEFAULT_RATE_LIMIT;

		if ( $limit < 1 ) {
			return true; // Explicitly disabled.
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		$key   = 'ppcert_verify_' . wp_hash( $ip . wp_salt() );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, self::rate_window() );

		return true;
	}

	/**
	 * The configured rate window in seconds
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	private static function rate_window() {
		return defined( 'PPCERT_VERIFY_RATE_WINDOW' ) ? (int) PPCERT_VERIFY_RATE_WINDOW : self::DEFAULT_RATE_WINDOW;
	}

	/**
	 * Recipient name from the issuance snapshot
	 *
	 * The snapshotted name displays even if the WP user was later deleted
	 * (Feature 003 Edge Cases); a live lookup is only the fallback for
	 * templates that carried no name token.
	 *
	 * @since 1.0.0
	 *
	 * @param object $certificate Certificate row.
	 * @return string
	 */
	private static function snapshot_recipient_name( $certificate ) {
		$merge = is_array( $certificate->merge_data ) ? $certificate->merge_data : [];

		foreach ( [ 'recipient.full_name', 'recipient.display_name' ] as $token ) {
			if ( isset( $merge[ $token ] ) && '' !== (string) $merge[ $token ] ) {
				return (string) $merge[ $token ];
			}
		}

		$user = get_userdata( (int) $certificate->recipient_id );

		return $user ? (string) $user->display_name : '';
	}

	/**
	 * Issuer name from the snapshot, site name fallback (1.0 semantics)
	 *
	 * @since 1.0.0
	 *
	 * @param object $certificate Certificate row.
	 * @return string
	 */
	private static function snapshot_issuer_name( $certificate ) {
		$merge = is_array( $certificate->merge_data ) ? $certificate->merge_data : [];

		if ( isset( $merge['certificate.issuer_name'] ) && '' !== (string) $merge['certificate.issuer_name'] ) {
			return (string) $merge['certificate.issuer_name'];
		}

		return (string) get_bloginfo( 'name' );
	}

	/**
	 * Convert a stored UTC datetime to ISO 8601 (TR-001)
	 *
	 * Stored values are UTC by the datetime standard; never mysql2date.
	 *
	 * @since 1.0.0
	 *
	 * @param string $datetime UTC datetime (Y-m-d H:i:s).
	 * @return string|null ISO 8601 UTC (Y-m-d\TH:i:sZ), or null when empty.
	 */
	private static function to_iso8601( $datetime ) {
		if ( '' === $datetime ) {
			return null;
		}

		return str_replace( ' ', 'T', $datetime ) . 'Z';
	}
}
