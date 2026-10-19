<?php
/**
 * Review prompt
 *
 * The one-time "Are you enjoying the plugin?" notice (Feature 2.1-006
 * FR-001), modeled on PressPrimer Quiz's celebration notice: once the
 * site has issued 50 certificates, users who manage settings see it on
 * Certificate's own screens. It retires site-wide once answered or
 * closed, or snoozes for 30 days. Never shown under white-label
 * branding (FR-004). (The 10-certificate email-course ask in the same
 * feature was pulled before release, Ryan 2026-10-19.)
 *
 * @package PressPrimer_Certificate
 * @subpackage Admin
 * @since 2.1.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Review notice class
 *
 * @since 2.1.0
 */
class PressPrimer_Certificate_Review_Notice {

	/**
	 * Default certificates issued before the prompt shows
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const THRESHOLD = 50;

	/**
	 * Snooze length in days
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const SNOOZE_DAYS = 30;

	/**
	 * Option: active | snoozed | retired
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const STATE_OPTION = 'ppcert_review_prompt_state';

	/**
	 * Option: snoozed until (UTC Y-m-d H:i:s)
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const SNOOZED_UNTIL_OPTION = 'ppcert_review_prompt_snoozed_until';

	/**
	 * Transient: the site's certificate count (12 hours)
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const COUNT_TRANSIENT = 'ppcert_issued_count';

	/**
	 * The WordPress.org review form
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const REVIEW_URL = 'https://wordpress.org/support/plugin/pressprimer-certificate/reviews/#new-post';

	/**
	 * The feedback form
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const FEEDBACK_URL = 'https://pressprimer.com/contact/';

	/**
	 * AJAX responses and the state each leaves
	 *
	 * @since 2.1.0
	 * @var string[]
	 */
	const RESPONSES = [
		'review'   => 'retired',
		'feedback' => 'retired',
		'snooze'   => 'snoozed',
		'dismiss'  => 'retired',
	];

	/**
	 * Hook the notice, its script, and its AJAX actions
	 *
	 * @since 2.1.0
	 */
	public static function init() {
		add_action( 'admin_notices', [ __CLASS__, 'maybe_render' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'maybe_enqueue' ] );

		foreach ( array_keys( self::RESPONSES ) as $response ) {
			add_action( 'wp_ajax_ppcert_review_prompt_' . $response, [ __CLASS__, 'handle_response' ] );
		}
	}

	/**
	 * Whether PressPrimer engagement prompts may show at all (FR-004)
	 *
	 * Never under white-label branding (the admin branding name differs
	 * from the default), and never when `ppcert_show_engagement_prompts`
	 * says no.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public static function engagement_prompts_allowed() {
		$branding = PressPrimer_Certificate_Admin::branding();
		$allowed  = __( 'PressPrimer Certificate', 'pressprimer-certificate' ) === (string) $branding['name'];

		/**
		 * Filters whether PressPrimer's review prompt may show.
		 *
		 * @since 2.1.0
		 *
		 * @param bool $allowed Default: true unless white-label branding is active.
		 */
		return (bool) apply_filters( 'ppcert_show_engagement_prompts', $allowed );
	}

	/**
	 * The site's certificate count, cached for 12 hours
	 *
	 * @since 2.1.0
	 *
	 * @return int
	 */
	public static function issued_count() {
		$count = get_transient( self::COUNT_TRANSIENT );

		if ( false === $count ) {
			$count = PressPrimer_Certificate_Certificate::count_site();
			set_transient( self::COUNT_TRANSIENT, (int) $count, 12 * HOUR_IN_SECONDS );
		}

		return (int) $count;
	}

	/**
	 * Whether the prompt is due for a user (any screen)
	 *
	 * @since 2.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_due( $user_id ) {
		$user_id = absint( $user_id );

		if ( ! $user_id || ! user_can( $user_id, PressPrimer_Certificate_Capabilities::CAP_MANAGE_SETTINGS ) ) {
			return false;
		}

		if ( ! self::engagement_prompts_allowed() ) {
			return false;
		}

		$state = (string) get_option( self::STATE_OPTION, 'active' );

		if ( 'retired' === $state ) {
			return false;
		}

		if ( 'snoozed' === $state && (string) get_option( self::SNOOZED_UNTIL_OPTION, '' ) > current_time( 'mysql', true ) ) {
			return false;
		}

		/**
		 * Filters whether the review prompt is due.
		 *
		 * Lets another ask hold it (one ask at a time).
		 *
		 * @since 2.1.0
		 *
		 * @param bool $due     Whether the prompt is due.
		 * @param int  $user_id Current user ID.
		 */
		return (bool) apply_filters( 'ppcert_review_prompt_due', self::issued_count() >= self::threshold(), $user_id );
	}

	/**
	 * Certificates the site issues before the prompt
	 *
	 * @since 2.1.0
	 *
	 * @return int
	 */
	public static function threshold() {
		/**
		 * Filters how many certificates the site issues before the review
		 * prompt.
		 *
		 * @since 2.1.0
		 *
		 * @param int $threshold Default 50.
		 */
		return max( 1, absint( apply_filters( 'ppcert_review_prompt_threshold', self::THRESHOLD ) ) );
	}

	/**
	 * Whether this request is one of Certificate's own screens
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	private static function on_certificate_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 'pressprimer-certificate' === $page || 0 === strpos( $page, 'ppcert-' );
	}

	/**
	 * Print the notice on Certificate screens when due
	 *
	 * @since 2.1.0
	 */
	public static function maybe_render() {
		if ( ! self::on_certificate_screen() || ! self::is_due( get_current_user_id() ) ) {
			return;
		}

		$threshold = self::threshold();

		echo '<div class="notice ppcert-review-notice" data-ppcert-review-nonce="' . esc_attr( wp_create_nonce( 'ppcert_review_prompt' ) ) . '">';
		echo '<button type="button" class="ppcert-review-notice__close" data-ppcert-review="dismiss" aria-label="' . esc_attr__( 'Dismiss this notice', 'pressprimer-certificate' ) . '"><span aria-hidden="true">&times;</span></button>';
		echo '<div class="ppcert-review-notice__content">';
		echo '<div class="ppcert-review-notice__icon" aria-hidden="true">🎉</div>';
		echo '<div class="ppcert-review-notice__text">';
		echo '<p class="ppcert-review-notice__title">' . esc_html(
			sprintf(
				/* translators: %s: number of certificates issued. */
				_n(
					'Congratulations! Your site has issued %s certificate with PressPrimer Certificate!',
					'Congratulations! Your site has issued %s certificates with PressPrimer Certificate!',
					$threshold,
					'pressprimer-certificate'
				),
				number_format_i18n( $threshold )
			)
		) . '</p>';
		echo '<p class="ppcert-review-notice__message">' . esc_html__( "We'd love to hear your feedback. Are you enjoying the plugin?", 'pressprimer-certificate' ) . '</p>';
		echo '<p class="ppcert-review-notice__actions">';
		echo '<a class="button button-primary" href="' . esc_url( self::REVIEW_URL ) . '" target="_blank" rel="noopener noreferrer" data-ppcert-review="review">' . esc_html__( 'Yes, I love it!', 'pressprimer-certificate' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( self::FEEDBACK_URL ) . '" target="_blank" rel="noopener noreferrer" data-ppcert-review="feedback">' . esc_html__( 'It could be better', 'pressprimer-certificate' ) . '</a> ';
		echo '<button type="button" class="button-link" data-ppcert-review="snooze">' . esc_html__( 'Remind me later', 'pressprimer-certificate' ) . '</button>';
		echo '</p></div></div></div>';
	}

	/**
	 * Enqueue the notice script when the notice will print
	 *
	 * @since 2.1.0
	 */
	public static function maybe_enqueue() {
		if ( ! self::on_certificate_screen() || ! self::is_due( get_current_user_id() ) ) {
			return;
		}

		wp_enqueue_script(
			'ppcert-review-notice',
			PPCERT_PLUGIN_URL . 'assets/js/ppcert-review-notice.js',
			[ 'jquery' ],
			PPCERT_VERSION,
			true
		);
	}

	/**
	 * AJAX: record an answer (review, feedback, snooze, dismiss)
	 *
	 * The two outbound buttons are real links opening in a new tab; this
	 * only records the answer.
	 *
	 * @since 2.1.0
	 */
	public static function handle_response() {
		check_ajax_referer( 'ppcert_review_prompt', 'nonce' );

		if ( ! current_user_can( PressPrimer_Certificate_Capabilities::CAP_MANAGE_SETTINGS ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to do that.', 'pressprimer-certificate' ) ], 403 );
		}

		$action   = current_action();
		$response = (string) substr( $action, strlen( 'wp_ajax_ppcert_review_prompt_' ) );

		if ( ! isset( self::RESPONSES[ $response ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Unknown response.', 'pressprimer-certificate' ) ], 400 );
		}

		self::record( $response );

		wp_send_json_success( [ 'state' => self::RESPONSES[ $response ] ] );
	}

	/**
	 * Record an answer: retire, or snooze for 30 days
	 *
	 * @since 2.1.0
	 *
	 * @param string $response review | feedback | snooze | dismiss.
	 * @return void
	 */
	public static function record( $response ) {
		if ( ! isset( self::RESPONSES[ $response ] ) ) {
			return;
		}

		update_option( self::STATE_OPTION, self::RESPONSES[ $response ], false );

		if ( 'snooze' === $response ) {
			update_option( self::SNOOZED_UNTIL_OPTION, gmdate( 'Y-m-d H:i:s', time() + ( self::SNOOZE_DAYS * DAY_IN_SECONDS ) ), false );
		}
	}
}
