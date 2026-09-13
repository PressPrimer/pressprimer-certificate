<?php
/**
 * Certificate link
 *
 * One button or link for the logged-in learner's own certificate for
 * the content they are looking at: `[ppcert_certificate_link]` plus the
 * matching dynamic block (Feature 2.0-008). Placed on a course, lesson,
 * topic, or quiz page it appears once that learner has earned a
 * certificate for it and renders nothing until then - the per-course
 * certificate link LMS plugins offer, working for every integration
 * because certificates record which object earned them.
 *
 * @package PressPrimer_Certificate
 * @subpackage Frontend
 * @since 2.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate link class
 *
 * Scope resolution (FR-001): `source` defaults to the current singular
 * post when `template` is absent and to no source when it is set; a
 * numeric source is matched by ref AND by the trigger types that
 * declare the post's post type, so a quiz never matches a course of
 * the same id. An explicit `source_type` skips inference (PressPrimer
 * Quiz and Assignment sources are not posts). The recipient is always
 * the current user - never an attribute.
 *
 * @since 2.0.0
 */
class PressPrimer_Certificate_Certificate_Link {

	/**
	 * Register the shortcode
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		add_shortcode( 'ppcert_certificate_link', [ __CLASS__, 'render_shortcode' ] );
	}

	/**
	 * Attribute defaults
	 *
	 * `source` and `new_tab` default to null so "not given" is
	 * distinguishable from an explicit value (their real defaults
	 * depend on other attributes).
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	public static function defaults() {
		return [
			'source'      => null,
			'source_type' => '',
			'template'    => 0,
			'action'      => 'view',
			'text'        => '',
			'style'       => 'button',
			'new_tab'     => null,
		];
	}

	/**
	 * Render the link
	 *
	 * Empty string (no wrapper markup) whenever there is nothing to
	 * show: logged out, no matching certificate, only revoked matches,
	 * or an unresolvable scope (FR-002).
	 *
	 * @since 2.0.0
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render_shortcode( $atts = [] ) {
		$atts = shortcode_atts( self::defaults(), (array) $atts, 'ppcert_certificate_link' );

		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return '';
		}

		$template_id = absint( $atts['template'] );
		$scope       = self::resolve_scope( $atts, $template_id );

		if ( null === $scope ) {
			return '';
		}

		$certificate = PressPrimer_Certificate_Certificate::get_latest_for_recipient(
			$user_id,
			[
				'template_id'  => $template_id,
				'source_ref'   => $scope['ref'],
				'source_types' => $scope['types'],
			]
		);

		if ( ! $certificate || 'revoked' === PressPrimer_Certificate_Certificate::effective_status( $certificate ) ) {
			return '';
		}

		$action = self::build_action( $atts, $certificate );

		/**
		 * Filters the certificate link's action entry (Feature 2.0-008).
		 *
		 * Same entry shape and escaping rules as ppcert_view_page_actions:
		 * label, url, class, new_tab. Return an empty array (or anything
		 * without a label and url) to suppress the link.
		 *
		 * @since 2.0.0
		 *
		 * @param array  $action      The link definition.
		 * @param object $certificate Hydrated certificate row.
		 * @param array  $atts        Sanitized shortcode attributes.
		 */
		$action = apply_filters( 'ppcert_certificate_link_action', $action, $certificate, $atts );

		if ( ! is_array( $action ) || empty( $action['label'] ) || empty( $action['url'] ) ) {
			return '';
		}

		wp_enqueue_style( 'ppcert-frontend' );

		$output = '<span class="ppcert-certificate-link ppcert-certificate-link--' . esc_attr( self::sanitize_action( $atts['action'] ) ) . '">'
			. PressPrimer_Certificate_View_Page::render_action_links( [ $action ] )
			. '</span>';

		// Return-time allowlist pass, like every shortcode/block return.
		return wp_kses( $output, self::allowed_output_tags() );
	}

	/**
	 * Resolve the source scope from the attributes
	 *
	 * @since 2.0.0
	 *
	 * @param array $atts        Attributes (shortcode_atts output).
	 * @param int   $template_id Sanitized template id.
	 * @return array|null [ 'ref' => string, 'types' => string[] ] with
	 *                    '' / [] meaning "no source scope", or null when
	 *                    the scope cannot be resolved (renders nothing).
	 */
	private static function resolve_scope( array $atts, $template_id ) {
		$raw = $atts['source'];

		if ( null === $raw || '' === $raw ) {
			$raw = $template_id > 0 ? 'none' : 'current';
		}

		$raw = is_string( $raw ) ? sanitize_key( $raw ) : absint( $raw );

		if ( 'none' === $raw ) {
			if ( $template_id < 1 ) {
				return null;
			}

			return [
				'ref'   => '',
				'types' => [],
			];
		}

		if ( 'current' === $raw ) {
			$post_id = is_singular() ? absint( get_queried_object_id() ) : 0;
		} else {
			$post_id = absint( $raw );
		}

		if ( $post_id < 1 ) {
			return null;
		}

		$explicit_type = sanitize_key( (string) $atts['source_type'] );

		if ( '' !== $explicit_type ) {
			return [
				'ref'   => (string) $post_id,
				'types' => [ $explicit_type ],
			];
		}

		$post_type = get_post_type( $post_id );
		$types     = $post_type ? PressPrimer_Certificate_Trigger_Registry::get_types_for_post_type( $post_type ) : [];

		if ( empty( $types ) ) {
			return null;
		}

		return [
			'ref'   => (string) $post_id,
			'types' => $types,
		];
	}

	/**
	 * Build the action entry for a certificate
	 *
	 * @since 2.0.0
	 *
	 * @param array  $atts        Attributes.
	 * @param object $certificate Hydrated certificate row.
	 * @return array label, url, class, new_tab.
	 */
	private static function build_action( array $atts, $certificate ) {
		$action     = self::sanitize_action( $atts['action'] );
		$credential = (string) $certificate->credential_id;

		$urls = [
			'view'     => PressPrimer_Certificate_View_Page::view_url( $credential ),
			'download' => PressPrimer_Certificate_View_Page::pdf_url( $credential ),
			'verify'   => ppcert_verification_url( $credential ),
		];

		$labels = [
			'view'     => __( 'View your certificate', 'pressprimer-certificate' ),
			'download' => __( 'Download your certificate', 'pressprimer-certificate' ),
			'verify'   => __( 'Verify your certificate', 'pressprimer-certificate' ),
		];

		$text  = sanitize_text_field( (string) $atts['text'] );
		$style = 'link' === sanitize_key( (string) $atts['style'] ) ? 'link' : 'button';

		if ( 'link' === $style ) {
			$class = 'ppcert-certificate-link__link';
		} else {
			$class = 'verify' === $action ? 'ppcert-button-secondary' : 'ppcert-button-primary';
		}

		$new_tab = null === $atts['new_tab'] || '' === $atts['new_tab']
			? 'verify' === $action
			: self::to_bool( $atts['new_tab'] );

		return [
			'label'   => '' !== $text ? $text : $labels[ $action ],
			'url'     => $urls[ $action ],
			'class'   => $class,
			'new_tab' => $new_tab,
		];
	}

	/**
	 * Allowlisted action value
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $value Raw attribute.
	 * @return string view | download | verify.
	 */
	private static function sanitize_action( $value ) {
		$action = sanitize_key( (string) $value );

		return in_array( $action, [ 'view', 'download', 'verify' ], true ) ? $action : 'view';
	}

	/**
	 * Shortcode-style boolean ("1", "true", "yes" are true)
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $value Raw attribute.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on' ], true );
	}

	/**
	 * The explicit allowed-tags array for the returned markup
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	private static function allowed_output_tags() {
		return [
			'span' => [ 'class' => true ],
			'a'    => [
				'href'   => true,
				'class'  => true,
				'target' => true,
				'rel'    => true,
			],
		];
	}
}
