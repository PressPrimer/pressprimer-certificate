<?php
/**
 * Certificate view page
 *
 * The public share URL /certificate/{credential_id}/ (Feature 005
 * FR-002): a virtual page injected into the main query and rendered
 * through the theme's normal page template, showing the cached preview
 * PNG, the certificate facts, a verification link, and the PDF
 * download.
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
 * View page class
 *
 * Public by URL (share-ready by design - the URL embeds the
 * non-guessable credential ID); resolves strictly by full normalized
 * credential, never partial match. Every rendered value is escaped:
 * recipient names and template titles are attacker-influenced in
 * principle (CLAUDE.md output-escaping rules).
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_View_Page {

	/**
	 * Query var carrying the requested credential ID
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const QUERY_VAR = 'ppcert_credential';

	/**
	 * The certificate being rendered on this request
	 *
	 * @since 1.0.0
	 * @var object|null
	 */
	private static $certificate = null;

	/**
	 * Initialize: rewrite, query var, virtual page injection
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		self::register_rewrites();

		add_filter( 'query_vars', [ __CLASS__, 'register_query_var' ] );
		add_filter( 'the_posts', [ __CLASS__, 'inject_virtual_page' ], 10, 2 );
		add_filter( 'the_content', [ __CLASS__, 'filter_content' ], 20 );
	}

	/**
	 * Register the /certificate/{credential_id}/ rewrite rule
	 *
	 * Also called by the activator immediately before its rewrite flush
	 * so the route works from first activation.
	 *
	 * @since 1.0.0
	 */
	public static function register_rewrites() {
		add_rewrite_rule(
			'^certificate/([A-Za-z0-9\-]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Register the credential query var
	 *
	 * @since 1.0.0
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Inject the virtual page into the main query
	 *
	 * When the credential resolves, the main query becomes a singular
	 * page holding a stub post, so the active theme (classic or block)
	 * renders it through its ordinary page template. A credential that
	 * does not resolve 404s - no partial matching, no suggestions
	 * (Security Requirements, Feature 005).
	 *
	 * @since 1.0.0
	 *
	 * @param array    $posts Queried posts.
	 * @param WP_Query $query The query.
	 * @return array
	 */
	public static function inject_virtual_page( $posts, $query ) {
		if ( ! $query->is_main_query() ) {
			return $posts;
		}

		$credential = (string) $query->get( self::QUERY_VAR );

		if ( '' === $credential ) {
			return $posts;
		}

		$certificate = PressPrimer_Certificate_Certificate::get_for_verification( $credential );

		if ( ! $certificate ) {
			$query->set_404();
			status_header( 404 );
			nocache_headers();

			return [];
		}

		self::$certificate = $certificate;

		wp_enqueue_style( 'ppcert-frontend' );

		$post = self::build_stub_post( $certificate );

		$query->posts       = [ $post ];
		$query->post        = $post;
		$query->post_count  = 1;
		$query->found_posts = 1;

		$query->is_page     = true;
		$query->is_singular = true;
		$query->is_single   = false;
		$query->is_home     = false;
		$query->is_archive  = false;
		$query->is_404      = false;

		$query->queried_object    = $post;
		$query->queried_object_id = $post->ID;

		return [ $post ];
	}

	/**
	 * Swap in the rendered certificate content for the stub post
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function filter_content( $content ) {
		if ( null === self::$certificate || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		return self::render_content( self::$certificate );
	}

	/**
	 * Build the certificate view markup
	 *
	 * Pure and unit-tested: status banner, preview image (or the revoked
	 * notice, or the text-card fallback when rendering fails), the fact
	 * list, and the action links.
	 *
	 * @since 1.0.0
	 *
	 * @param object $certificate Hydrated certificate row (with template_title).
	 * @return string Escaped HTML.
	 */
	public static function render_content( $certificate ) {
		$status     = PressPrimer_Certificate_Certificate::effective_status( $certificate );
		$credential = (string) $certificate->credential_id;
		$display_id = PressPrimer_Certificate_Credential_ID_Service::format_display( $credential );
		$recipient  = self::recipient_name( $certificate );
		$subject    = isset( $certificate->template_title ) ? (string) $certificate->template_title : '';

		$output = '<div class="ppcert-view">';

		if ( 'revoked' === $status ) {
			$output .= '<div class="ppcert-view__banner ppcert-view__banner--revoked" role="status">'
				. esc_html__( 'This certificate has been revoked.', 'pressprimer-certificate' )
				. '</div>';
		} elseif ( 'expired' === $status ) {
			$output .= '<div class="ppcert-view__banner ppcert-view__banner--expired" role="status">'
				. esc_html__( 'This certificate has expired.', 'pressprimer-certificate' )
				. '</div>';
		}

		// Revoked certificates show the notice instead of the artifact
		// (Feature 005 FR-002).
		if ( 'revoked' !== $status ) {
			$output .= self::render_preview( $certificate, $recipient );
		}

		$rows = [
			[ __( 'Recipient', 'pressprimer-certificate' ), $recipient ],
			[ __( 'Certificate', 'pressprimer-certificate' ), $subject ],
			[ __( 'Credential ID', 'pressprimer-certificate' ), $display_id ],
			[ __( 'Issued', 'pressprimer-certificate' ), self::display_date( $certificate->issued_at ) ],
			[ __( 'Expires', 'pressprimer-certificate' ), self::display_date( isset( $certificate->expires_at ) ? $certificate->expires_at : null ) ],
		];

		$output .= '<dl class="ppcert-view__details">';

		foreach ( $rows as $row ) {
			if ( '' === $row[1] ) {
				continue;
			}

			$output .= '<dt>' . esc_html( $row[0] ) . '</dt><dd>' . esc_html( $row[1] ) . '</dd>';
		}

		$output .= '</dl>';

		$output .= '<p class="ppcert-view__actions">';

		if ( 'revoked' !== $status ) {
			$output .= '<a class="ppcert-view__download button" href="'
				. esc_url( self::download_url( $credential ) ) . '">'
				. esc_html__( 'Download PDF', 'pressprimer-certificate' ) . '</a> ';
		}

		$output .= '<a class="ppcert-view__verify" href="'
			. esc_url( ppcert_verification_url( $credential ) ) . '">'
			. esc_html__( 'Verify this certificate', 'pressprimer-certificate' ) . '</a>';

		$output .= '</p>';
		$output .= '</div>';

		return $output;
	}

	/**
	 * Render the preview image, or the text-card fallback
	 *
	 * @since 1.0.0
	 *
	 * @param object $certificate Hydrated certificate row.
	 * @param string $recipient   Recipient display name.
	 * @return string
	 */
	private static function render_preview( $certificate, $recipient ) {
		$preview = PressPrimer_Certificate_Preview_Service::get_or_create( $certificate );

		if ( ! is_wp_error( $preview ) ) {
			$alt = sprintf(
				/* translators: %s: recipient name */
				__( 'Certificate preview for %s', 'pressprimer-certificate' ),
				$recipient
			);

			return '<figure class="ppcert-view__preview"><img src="' . esc_url( $preview )
				. '" alt="' . esc_attr( $alt ) . '" /></figure>';
		}

		// Render failure: styled text card with the certificate facts
		// (Feature 005 Edge Cases); the error was logged via the
		// ppcert_preview_render_failed action.
		$subject = isset( $certificate->template_title ) ? (string) $certificate->template_title : '';

		$card  = '<div class="ppcert-view__card">';
		$card .= '<p class="ppcert-view__card-recipient">' . esc_html( $recipient ) . '</p>';

		if ( '' !== $subject ) {
			$card .= '<p class="ppcert-view__card-subject">' . esc_html( $subject ) . '</p>';
		}

		$card .= '<p class="ppcert-view__card-date">' . esc_html( self::display_date( $certificate->issued_at ) ) . '</p>';
		$card .= '</div>';

		return $card;
	}

	/**
	 * Build the stub post the theme renders
	 *
	 * @since 1.0.0
	 *
	 * @param object $certificate Hydrated certificate row.
	 * @return WP_Post
	 */
	private static function build_stub_post( $certificate ) {
		$subject = isset( $certificate->template_title ) && '' !== (string) $certificate->template_title
			? (string) $certificate->template_title
			: __( 'Certificate', 'pressprimer-certificate' );

		return new WP_Post(
			(object) [
				'ID'                    => 0,
				'post_author'           => 0,
				'post_date'             => (string) $certificate->issued_at,
				'post_date_gmt'         => (string) $certificate->issued_at,
				'post_content'          => '',
				'post_title'            => $subject,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'comment_status'        => 'closed',
				'ping_status'           => 'closed',
				'post_password'         => '',
				'post_name'             => 'certificate-' . strtolower( (string) $certificate->credential_id ),
				'to_ping'               => '',
				'pinged'                => '',
				'post_modified'         => (string) $certificate->issued_at,
				'post_modified_gmt'     => (string) $certificate->issued_at,
				'post_content_filtered' => '',
				'post_parent'           => 0,
				'guid'                  => '',
				'menu_order'            => 0,
				'post_type'             => 'page',
				'post_mime_type'        => '',
				'comment_count'         => 0,
				'filter'                => 'raw',
			]
		);
	}

	/**
	 * The recipient's display name, snapshot-first
	 *
	 * The merge snapshot's recipient name is what the certificate shows;
	 * the live user record is the fallback (the account may be renamed
	 * or gone).
	 *
	 * @since 1.0.0
	 *
	 * @param object $certificate Hydrated certificate row.
	 * @return string
	 */
	private static function recipient_name( $certificate ) {
		if ( is_array( $certificate->merge_data ) && ! empty( $certificate->merge_data['recipient.name'] ) ) {
			return (string) $certificate->merge_data['recipient.name'];
		}

		$user = get_userdata( (int) $certificate->recipient_id );

		return $user ? (string) $user->display_name : '';
	}

	/**
	 * The public PDF download URL for a credential
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential_id Normalized credential ID.
	 * @return string
	 */
	private static function download_url( $credential_id ) {
		return rest_url( 'ppcert/v1/certificates/' . rawurlencode( $credential_id ) . '/pdf' );
	}

	/**
	 * Format a UTC datetime in the site date format
	 *
	 * UTC in, localized out (CLAUDE.md Datetime Standard).
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $utc MySQL UTC datetime.
	 * @return string
	 */
	private static function display_date( $utc ) {
		if ( empty( $utc ) ) {
			return '';
		}

		return (string) get_date_from_gmt( (string) $utc, get_option( 'date_format' ) );
	}

	/**
	 * Reset request state (tests)
	 *
	 * @since 1.0.0
	 */
	public static function reset() {
		self::$certificate = null;
	}
}
