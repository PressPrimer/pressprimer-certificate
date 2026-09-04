<?php
/**
 * Settings REST controller
 *
 * GET/POST /ppcert/v1/settings for the React settings page (Feature
 * 008 FR-004), following the Quiz/Assignment settings endpoint
 * pattern: field-by-field sanitization, merge-and-save into the single
 * ppcert_settings option, addon extensibility via filter.
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
 * Settings controller class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_REST_Settings_Controller {

	/**
	 * Initialize the controller
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register routes
	 *
	 * @since 1.0.0
	 */
	public function register_routes() {
		register_rest_route(
			'ppcert/v1',
			'/settings',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			'ppcert/v1',
			'/settings/test-email',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'send_test_email' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
	}

	/**
	 * POST /settings/test-email - send the current user the site-wide
	 * default issuance email with sample values (2.0; the template
	 * test-email pattern applied to the Settings > Email page)
	 *
	 * Shares the template test's per-user throttle bucket so the two
	 * buttons cannot multiply the send rate.
	 *
	 * @since 2.0.0
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test_email() {
		if ( ! PressPrimer_Certificate_Rate_Limiter::allow(
			'ppcert_testmail_' . get_current_user_id(),
			PressPrimer_Certificate_REST_Templates_Controller::TEST_EMAIL_RATE_LIMIT,
			PressPrimer_Certificate_REST_Templates_Controller::TEST_EMAIL_RATE_WINDOW
		) ) {
			return new WP_Error(
				'ppcert_test_email_throttled',
				__( 'Too many test emails. Please wait a minute and try again.', 'pressprimer-certificate' ),
				[ 'status' => 429 ]
			);
		}

		$result = PressPrimer_Certificate_Email_Service::send_test( null );

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 500 ] );

			return $result;
		}

		$user = wp_get_current_user();

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => sprintf(
					/* translators: %s: the current user's email address */
					__( 'Test email sent to %s.', 'pressprimer-certificate' ),
					(string) $user->user_email
				),
			],
			200
		);
	}

	/**
	 * Capability check
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( PressPrimer_Certificate_Capabilities::CAP_MANAGE_SETTINGS );
	}

	/**
	 * GET /settings
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => self::current_settings(),
			],
			200
		);
	}

	/**
	 * The stored settings plus the standalone uninstall flag
	 *
	 * The remove-data flag lives in its own option so uninstall.php can
	 * read it without assuming the settings array's shape (Feature 008
	 * FR-006); the settings payload carries it for the UI.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function current_settings() {
		$settings = get_option( 'ppcert_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];

		$settings['remove_data_on_uninstall'] = (bool) get_option( 'ppcert_remove_data_on_uninstall', false ) ? 1 : 0;

		return $settings;
	}

	/**
	 * POST /settings - sanitize field by field, merge, save
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function update_settings( $request ) {
		$data     = $request->get_json_params();
		$data     = is_array( $data ) ? $data : [];
		$existing = get_option( 'ppcert_settings', [] );
		$existing = is_array( $existing ) ? $existing : [];

		$sanitized = [];

		// General.
		if ( isset( $data['verification_page_id'] ) ) {
			$sanitized['verification_page_id'] = absint( $data['verification_page_id'] );
		}

		// Appearance: certificate size is a fixed choice.
		if ( isset( $data['appearance_page_size'] ) ) {
			$size = sanitize_key( (string) $data['appearance_page_size'] );

			if ( in_array( $size, [ 'a4', 'letter' ], true ) ) {
				$sanitized['appearance_page_size'] = $size;
			}
		}

		// Appearance: the default font must be a registered slug.
		if ( isset( $data['appearance_default_font'] ) ) {
			$font = sanitize_key( (string) $data['appearance_default_font'] );

			if ( '' === $font || in_array( $font, PressPrimer_Certificate_Layout_Validator::get_registered_font_slugs(), true ) ) {
				$sanitized['appearance_default_font'] = $font;
			}
		}

		foreach ( [ 'appearance_signature_id', 'appearance_logo_id' ] as $attachment_field ) {
			if ( isset( $data[ $attachment_field ] ) ) {
				$attachment_id = absint( $data[ $attachment_field ] );

				// Attachments must be images; 0 clears the default.
				if ( 0 === $attachment_id || wp_attachment_is_image( $attachment_id ) ) {
					$sanitized[ $attachment_field ] = $attachment_id;
				}
			}
		}

		foreach ( [ 'appearance_primary_color', 'appearance_accent_color', 'appearance_text_color' ] as $color_field ) {
			if ( isset( $data[ $color_field ] ) ) {
				if ( '' === $data[ $color_field ] ) {
					$sanitized[ $color_field ] = '';
				} else {
					$color = sanitize_hex_color( (string) $data[ $color_field ] );

					if ( $color ) {
						$sanitized[ $color_field ] = $color;
					}
				}
			}
		}

		// Email.
		if ( isset( $data['email_issued_enabled'] ) ) {
			$sanitized['email_issued_enabled'] = ! empty( $data['email_issued_enabled'] ) ? 1 : 0;
		}

		if ( isset( $data['email_from_name'] ) ) {
			$sanitized['email_from_name'] = sanitize_text_field( (string) $data['email_from_name'] );
		}

		if ( isset( $data['email_from_address'] ) ) {
			$email = sanitize_email( (string) $data['email_from_address'] );

			if ( is_email( $email ) ) {
				$sanitized['email_from_address'] = $email;
			}
		}

		if ( isset( $data['email_issued_subject'] ) ) {
			$sanitized['email_issued_subject'] = sanitize_text_field( (string) $data['email_issued_subject'] );
		}

		if ( isset( $data['email_issued_body'] ) ) {
			$sanitized['email_issued_body'] = sanitize_textarea_field( (string) $data['email_issued_body'] );
		}

		// Advanced: retention window for prunable event rows.
		if ( isset( $data['events_retention_days'] ) ) {
			$sanitized['events_retention_days'] = min( 3650, max( 7, absint( $data['events_retention_days'] ) ) );
		}

		// Advanced: the uninstall flag writes its standalone option
		// (uninstall.php reads it directly; default is always OFF).
		if ( isset( $data['remove_data_on_uninstall'] ) ) {
			$remove = ( true === $data['remove_data_on_uninstall']
				|| 1 === $data['remove_data_on_uninstall']
				|| '1' === $data['remove_data_on_uninstall'] );

			update_option( 'ppcert_remove_data_on_uninstall', $remove );
		}

		/**
		 * Filters the sanitized settings before saving.
		 *
		 * Premium addons add their own sanitized settings here. See
		 * docs/architecture/HOOKS.md.
		 *
		 * @since 1.0.0
		 *
		 * @param array $sanitized Sanitized settings about to merge.
		 * @param array $data      Raw request payload.
		 */
		$sanitized = apply_filters( 'ppcert_sanitize_settings', $sanitized, $data );

		$merged = array_merge( $existing, $sanitized );

		$changed_keys = [];

		foreach ( $sanitized as $key => $value ) {
			if ( ! array_key_exists( $key, $existing ) || $existing[ $key ] !== $value ) {
				$changed_keys[] = $key;
			}
		}

		update_option( 'ppcert_settings', $merged );

		if ( ! empty( $changed_keys ) ) {
			/**
			 * Fires when plugin settings are saved with changes.
			 *
			 * @since 1.0.0
			 *
			 * @param array $changed_keys Setting keys that changed.
			 * @param array $merged       Full settings array after save.
			 */
			do_action( 'ppcert_settings_saved', $changed_keys, $merged );
		}

		return new WP_REST_Response(
			[
				'success'  => true,
				'settings' => self::current_settings(),
			],
			200
		);
	}
}
