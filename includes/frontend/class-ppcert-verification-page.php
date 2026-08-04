<?php
/**
 * Verification page
 *
 * The public [ppcert_verify] shortcode, activation page creation, and
 * the deleted-page admin notice.
 *
 * @package PressPrimer_Certificate
 * @subpackage Frontend
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verification page class
 *
 * Feature 006 FR-001/FR-002. The shortcode renders the lookup form with
 * an aria-live result region; submissions go to the REST endpoint via
 * vanilla-JS fetch (client-side checksum shows typo help without a
 * request). Direct links and the no-JS fallback submit ?ppcert_id as GET
 * and render server-side through the same controller lookup path -
 * rate-limited identically, escaped exhaustively (this page displays
 * attacker-influenced data by definition).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Verification_Page {

	/**
	 * Initialize: shortcode, assets, admin notice
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_shortcode( 'ppcert_verify', [ __CLASS__, 'render_shortcode' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
		add_action( 'admin_notices', [ __CLASS__, 'maybe_deleted_page_notice' ] );
	}

	/**
	 * Register (not enqueue) the front-end assets
	 *
	 * Enqueued only when the shortcode actually renders.
	 *
	 * @since 1.0.0
	 */
	public static function register_assets() {
		wp_register_script(
			'ppcert-verify',
			PPCERT_PLUGIN_URL . 'assets/js/ppcert-verify.js',
			[],
			PPCERT_VERSION,
			true
		);

		wp_register_style(
			'ppcert-frontend',
			PPCERT_PLUGIN_URL . 'assets/css/frontend.css',
			[],
			PPCERT_VERSION
		);
	}

	/**
	 * Render the [ppcert_verify] shortcode
	 *
	 * @since 1.0.0
	 *
	 * @param array $atts Shortcode attributes (none in 1.0).
	 * @return string
	 */
	public static function render_shortcode( $atts = [] ) {
		wp_enqueue_script( 'ppcert-verify' );
		wp_enqueue_style( 'ppcert-frontend' );

		wp_localize_script(
			'ppcert-verify',
			'ppcert_verify_data',
			[
				'rest_url' => rest_url( 'ppcert/v1/verify/' ),
				'i18n'     => [
					'valid'        => __( 'Valid certificate', 'pressprimer-certificate' ),
					'expired'      => __( 'Expired certificate', 'pressprimer-certificate' ),
					'revoked'      => __( 'This certificate has been revoked.', 'pressprimer-certificate' ),
					'not_found'    => __( 'No certificate found for that credential ID.', 'pressprimer-certificate' ),
					'typo'         => __( 'That credential ID is invalid. Please check for typos and try again.', 'pressprimer-certificate' ),
					'checking'     => __( 'Checking…', 'pressprimer-certificate' ),
					'rate_limited' => __( 'Too many checks - please wait a minute and try again.', 'pressprimer-certificate' ),
					'error'        => __( 'Verification is temporarily unavailable. Please try again.', 'pressprimer-certificate' ),
					'recipient'    => __( 'Recipient', 'pressprimer-certificate' ),
					'subject'      => __( 'Certificate', 'pressprimer-certificate' ),
					'issuer'       => __( 'Issuer', 'pressprimer-certificate' ),
					'issued'       => __( 'Issued', 'pressprimer-certificate' ),
					'expires'      => __( 'Expires', 'pressprimer-certificate' ),
					'expires_past' => __( 'Expired', 'pressprimer-certificate' ),
				],
			]
		);

		// Direct links and the no-JS fallback: ?ppcert_id renders the
		// result server-side through the same lookup path.
		$prefill       = '';
		$server_result = '';

		// Public lookup parameter - read-only, sanitized immediately, no
		// nonce by design (this is an unauthenticated public page).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['ppcert_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$raw     = sanitize_text_field( wp_unslash( $_GET['ppcert_id'] ) );
			$prefill = PressPrimer_Certificate_Credential_ID_Service::normalize( $raw );

			if ( PressPrimer_Certificate_REST_Verification_Controller::check_rate_limit() ) {
				$server_result = self::render_result(
					PressPrimer_Certificate_REST_Verification_Controller::lookup( $raw )
				);
			} else {
				$server_result = '<p class="ppcert-verify__status">'
					. esc_html__( 'Too many checks - please wait a minute and try again.', 'pressprimer-certificate' )
					. '</p>';
			}
		}

		$data = [
			'prefill'       => $prefill,
			'server_result' => $server_result,
		];

		/** This filter is documented in docs/architecture/HOOKS.md */
		$data = apply_filters( 'ppcert_verification_page_data', $data, null );

		$output  = '<div class="ppcert-verify">';
		$output .= '<form class="ppcert-verify__form" method="get" action="">';
		$output .= '<label class="ppcert-verify__label" for="ppcert-verify-input">'
			. esc_html__( 'Credential ID', 'pressprimer-certificate' ) . '</label> ';
		$output .= '<input type="text" id="ppcert-verify-input" class="ppcert-verify__input" name="ppcert_id" '
			. 'value="' . esc_attr( isset( $data['prefill'] ) ? $data['prefill'] : '' ) . '" '
			. 'placeholder="XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false" required /> ';
		$output .= '<button type="submit" class="ppcert-verify__submit">'
			. esc_html__( 'Verify', 'pressprimer-certificate' ) . '</button>';
		$output .= '</form>';
		$output .= '<div class="ppcert-verify__result" role="status" aria-live="polite">'
			. ( isset( $data['server_result'] ) ? $data['server_result'] : '' ) . '</div>';
		$output .= '</div>';

		// Return-time allowlist pass: every plugin-built value above is
		// already escaped in context, but server_result passes through
		// the ppcert_verification_page_data filter - addons may add
		// branding markup, never scripts (WordPress.org review, round 1).
		return wp_kses( $output, self::allowed_output_tags() );
	}

	/**
	 * The explicit allowed-tags array for the page's returned markup
	 *
	 * Covers the form, the escaped result markup, and reasonable
	 * branding elements addons may add via the page-data filter.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function allowed_output_tags() {
		return [
			'div'    => [
				'class'     => true,
				'role'      => true,
				'aria-live' => true,
			],
			'form'   => [
				'class'  => true,
				'method' => true,
				'action' => true,
			],
			'label'  => [
				'class' => true,
				'for'   => true,
			],
			'input'  => [
				'type'         => true,
				'id'           => true,
				'class'        => true,
				'name'         => true,
				'value'        => true,
				'placeholder'  => true,
				'autocomplete' => true,
				'spellcheck'   => true,
				'required'     => true,
			],
			'button' => [
				'type'  => true,
				'class' => true,
			],
			'p'      => [ 'class' => true ],
			'dl'     => [ 'class' => true ],
			'dt'     => [],
			'dd'     => [],
			'span'   => [
				'class'       => true,
				'aria-hidden' => true,
			],
			'a'      => [
				'href'   => true,
				'class'  => true,
				'target' => true,
				'rel'    => true,
			],
			'img'    => [
				'src'    => true,
				'alt'    => true,
				'class'  => true,
				'width'  => true,
				'height' => true,
			],
			'h2'     => [ 'class' => true ],
			'h3'     => [ 'class' => true ],
			'strong' => [],
			'em'     => [],
			'br'     => [],
		];
	}

	/**
	 * Render a lookup result as escaped HTML (server-side path)
	 *
	 * Every value is escaped: this page displays attacker-influenced data
	 * in principle (CLAUDE.md output-escaping rules; no exceptions).
	 *
	 * @since 1.0.0
	 *
	 * @param array $result Locked-shape lookup result.
	 * @return string
	 */
	public static function render_result( $result ) {
		$status = isset( $result['status'] ) ? (string) $result['status'] : 'not_found';

		$headings = [
			'valid'     => __( 'Valid certificate', 'pressprimer-certificate' ),
			'expired'   => __( 'Expired certificate', 'pressprimer-certificate' ),
			'revoked'   => __( 'This certificate has been revoked.', 'pressprimer-certificate' ),
			'not_found' => __( 'No certificate found for that credential ID.', 'pressprimer-certificate' ),
		];

		$heading = isset( $headings[ $status ] ) ? $headings[ $status ] : $headings['not_found'];

		$output = '<p class="ppcert-verify__status ppcert-verify__status--' . esc_attr( $status ) . '">'
			. esc_html( $heading ) . '</p>';

		if ( 'valid' !== $status && 'expired' !== $status ) {
			return $output;
		}

		// A date already in the past reads "Expired", not "Expires"
		// (Ryan, 2026-07-26); both values are UTC.
		$expires_at    = isset( $result['expires_at'] ) ? (string) $result['expires_at'] : '';
		$expires_label = '' !== $expires_at && strtotime( $expires_at ) < time()
			? __( 'Expired', 'pressprimer-certificate' )
			: __( 'Expires', 'pressprimer-certificate' );

		$rows = [
			[ __( 'Recipient', 'pressprimer-certificate' ), isset( $result['recipient_name'] ) ? (string) $result['recipient_name'] : '' ],
			[ __( 'Certificate', 'pressprimer-certificate' ), isset( $result['subject'] ) ? (string) $result['subject'] : '' ],
			[ __( 'Issuer', 'pressprimer-certificate' ), isset( $result['issuer_name'] ) ? (string) $result['issuer_name'] : '' ],
			[ __( 'Issued', 'pressprimer-certificate' ), self::display_date( isset( $result['issued_at'] ) ? $result['issued_at'] : null ) ],
			[ $expires_label, self::display_date( '' !== $expires_at ? $expires_at : null ) ],
		];

		$output .= '<dl class="ppcert-verify__details">';

		foreach ( $rows as $row ) {
			if ( '' === $row[1] ) {
				continue;
			}

			$output .= '<dt>' . esc_html( $row[0] ) . '</dt><dd>' . esc_html( $row[1] ) . '</dd>';
		}

		$output .= '</dl>';

		return $output;
	}

	/**
	 * Create the verification page on activation (idempotent)
	 *
	 * Wired into the activator (the 1.2 TODO). Detects an existing page
	 * via the stored ID so re-activation never duplicates.
	 *
	 * @since 1.0.0
	 *
	 * @return int The verification page ID (0 on failure).
	 */
	public static function create_page() {
		$settings = get_option( 'ppcert_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];

		$existing = isset( $settings['verification_page_id'] ) ? absint( $settings['verification_page_id'] ) : 0;

		if ( $existing > 0 ) {
			$page = get_post( $existing );

			if ( $page && 'trash' !== $page->post_status ) {
				return $existing;
			}
		}

		$page_id = wp_insert_post(
			[
				'post_title'   => __( 'Verify Certificate', 'pressprimer-certificate' ),
				'post_content' => '[ppcert_verify]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			]
		);

		if ( ! is_int( $page_id ) || $page_id < 1 ) {
			return 0;
		}

		$settings['verification_page_id'] = $page_id;
		update_option( 'ppcert_settings', $settings );

		return $page_id;
	}

	/**
	 * Admin notice when the configured verification page is missing
	 *
	 * @since 1.0.0
	 */
	public static function maybe_deleted_page_notice() {
		$settings = get_option( 'ppcert_settings', [] );
		$page_id  = isset( $settings['verification_page_id'] ) ? absint( $settings['verification_page_id'] ) : 0;

		if ( $page_id < 1 ) {
			return;
		}

		$page = get_post( $page_id );

		if ( $page && 'trash' !== $page->post_status ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'The PressPrimer Certificates verification page has been deleted. Certificate QR codes and links will not resolve until you assign a new page in the plugin settings.', 'pressprimer-certificate' );
		echo '</p></div>';
	}

	/**
	 * Format an ISO 8601 UTC value in the site date format
	 *
	 * UTC in, localized out (CLAUDE.md Datetime Standard).
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $iso ISO 8601 UTC datetime.
	 * @return string
	 */
	private static function display_date( $iso ) {
		if ( empty( $iso ) ) {
			return '';
		}

		$mysql_utc = str_replace( [ 'T', 'Z' ], [ ' ', '' ], (string) $iso );

		return (string) get_date_from_gmt( $mysql_utc, get_option( 'date_format' ) );
	}
}
