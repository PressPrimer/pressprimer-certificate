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

		// The verification page picker's search (2.1, Feature 2.1-007
		// FR-005; the users-search pattern).
		register_rest_route(
			'ppcert/v1',
			'/settings/pages',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'search_pages' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'search' => [ 'sanitize_callback' => 'sanitize_text_field' ],
				],
			]
		);
	}

	/**
	 * GET /settings/pages - published pages matching a search
	 *
	 * @since 2.1.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function search_pages( $request ) {
		return new WP_REST_Response( self::page_matches( (string) $request->get_param( 'search' ), 20 ), 200 );
	}

	/**
	 * Published pages for the verification page picker, by title
	 *
	 * Every published page is reachable by search; the empty search
	 * returns the first ones alphabetically.
	 *
	 * @since 2.1.0
	 *
	 * @param string $search Title search ('' for the first pages).
	 * @param int    $limit  Most pages (1-100).
	 * @return array[] `[ id, title ]`.
	 */
	public static function page_matches( $search, $limit ) {
		$args = [
			'post_type'   => 'page',
			'post_status' => 'publish',
			'numberposts' => min( 100, max( 1, absint( $limit ) ) ),
			'orderby'     => 'title',
			'order'       => 'ASC',
		];

		if ( '' !== trim( (string) $search ) ) {
			// Titles only: core's default also searches content, which
			// lists pages whose names do not match what was typed.
			$args['s']              = trim( (string) $search );
			$args['search_columns'] = [ 'post_title' ];
		}

		return array_map( [ __CLASS__, 'page_choice' ], (array) get_posts( $args ) );
	}

	/**
	 * The configured verification page, for the picker
	 *
	 * Whether it still exists comes from the page itself, not from
	 * whether it is among the pages listed (2.1: on large sites the
	 * configured page fell outside the first 200 and read as deleted).
	 *
	 * @since 2.1.0
	 *
	 * @param int $page_id Configured page id (0 = none).
	 * @return array `[ id, title, exists ]`.
	 */
	public static function verification_page( $page_id ) {
		$page_id = absint( $page_id );
		$page    = $page_id > 0 ? get_post( $page_id ) : null;
		$exists  = $page && 'page' === $page->post_type && 'publish' === $page->post_status;

		return [
			'id'     => $page_id,
			'title'  => $exists ? self::page_choice( $page )['title'] : '',
			'exists' => 0 === $page_id || $exists,
		];
	}

	/**
	 * One page as a picker choice
	 *
	 * @since 2.1.0
	 *
	 * @param WP_Post|object $page Page.
	 * @return array `[ id, title ]`.
	 */
	private static function page_choice( $page ) {
		$title = (string) $page->post_title;

		return [
			'id'    => (int) $page->ID,
			'title' => '' !== $title
				? $title
				/* translators: %d: page ID. */
				: sprintf( __( '(no title) #%d', 'pressprimer-certificate' ), (int) $page->ID ),
		];
	}

	/**
	 * POST /settings/test-email - send the site-wide default issuance
	 * email with sample values (2.0; the template test-email pattern
	 * applied to the Settings > Email page)
	 *
	 * Since 2.1 (Feature 2.1-001 FR-006, Q1) the optional `to` sends it
	 * to another address (Assignment's Send to); without it, the current
	 * user. Administrator-only, REST nonce, and the template test's
	 * per-user throttle bucket, so the two buttons cannot multiply the
	 * send rate.
	 *
	 * @since 2.0.0
	 * @since 2.1.0 The `to` parameter.
	 *
	 * @param WP_REST_Request|null $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_test_email( $request = null ) {
		$to = '';

		if ( $request instanceof WP_REST_Request && null !== $request->get_param( 'to' ) ) {
			$to = sanitize_email( (string) $request->get_param( 'to' ) );

			if ( '' === $to || ! is_email( $to ) ) {
				return new WP_Error(
					'ppcert_test_email_invalid_recipient',
					__( 'Enter a valid email address to send the test to.', 'pressprimer-certificate' ),
					[
						'status' => 400,
						'field'  => 'to',
					]
				);
			}
		}

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

		$result = PressPrimer_Certificate_Email_Service::send_test( null, $to );

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 500 ] );

			return $result;
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => sprintf(
					/* translators: %s: the address the test was sent to */
					__( 'Test email sent to %s.', 'pressprimer-certificate' ),
					'' !== $to ? $to : (string) wp_get_current_user()->user_email
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
	 * @return WP_REST_Response|WP_Error A WP_Error (400, with the field)
	 *                                   for an invalid From address.
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

		// Appearance: the default certificate size is a page-size
		// registry slug (never `custom`, which has no fixed dimensions).
		if ( isset( $data['appearance_page_size'] ) ) {
			$size = sanitize_key( (string) $data['appearance_page_size'] );

			if ( PressPrimer_Certificate_Page_Sizes::is_preset( $size ) ) {
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

		// Empty clears the address (the administration email applies);
		// anything else must be a valid address - a field error, never
		// a silent drop (2.1, Feature 2.1-001 FR-008).
		if ( isset( $data['email_from_address'] ) ) {
			$raw = trim( (string) $data['email_from_address'] );

			if ( '' === $raw ) {
				$sanitized['email_from_address'] = '';
			} else {
				$email = sanitize_email( $raw );

				if ( '' === $email || ! is_email( $email ) ) {
					return new WP_Error(
						'ppcert_invalid_from_address',
						__( 'Enter a valid From address, or leave it empty to use the site\'s administration email.', 'pressprimer-certificate' ),
						[
							'status' => 400,
							'field'  => 'email_from_address',
						]
					);
				}

				$sanitized['email_from_address'] = $email;
			}
		}

		// The Email logo (2.1): an image attachment id; 0 removes it.
		if ( isset( $data['email_logo_id'] ) ) {
			$logo_id = absint( $data['email_logo_id'] );

			if ( 0 === $logo_id || wp_attachment_is_image( $logo_id ) ) {
				$sanitized['email_logo_id'] = $logo_id;
			}
		}

		if ( isset( $data['email_issued_subject'] ) ) {
			$sanitized['email_issued_subject'] = sanitize_text_field( (string) $data['email_issued_subject'] );
		}

		// The body is HTML from the rich-text editor since 2.1.
		if ( isset( $data['email_issued_body'] ) ) {
			$sanitized['email_issued_body'] = wp_kses_post( (string) $data['email_issued_body'] );
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
