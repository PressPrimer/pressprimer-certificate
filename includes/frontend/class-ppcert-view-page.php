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
		add_action( 'wp_head', [ __CLASS__, 'fire_head_action' ] );
	}

	/**
	 * Fire ppcert_view_page_head during head output (2.0, Feature
	 * 2.0-006 FR-004)
	 *
	 * Runs on every wp_head but does nothing unless this request resolved
	 * a certificate view page - Educator's OG-image/social feature hooks
	 * the action to print its meta tags with the certificate in hand.
	 *
	 * @since 2.0.0
	 */
	public static function fire_head_action() {
		if ( null === self::$certificate ) {
			return;
		}

		/** This action is documented in docs/architecture/HOOKS.md */
		do_action( 'ppcert_view_page_head', self::$certificate );
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

		// Return-time allowlist pass: the_content callbacks are rendered
		// by WordPress; every value inside is escaped at build time and
		// this proves it structurally.
		return wp_kses( self::render_content( self::$certificate ), self::allowed_output_tags() );
	}

	/**
	 * The explicit allowed-tags array for the view page's markup
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function allowed_output_tags() {
		return [
			'div'    => [
				'class' => true,
				'role'  => true,
			],
			'p'      => [ 'class' => true ],
			'dl'     => [ 'class' => true ],
			'dt'     => [],
			'dd'     => [],
			'img'    => [
				'src'   => true,
				'alt'   => true,
				'class' => true,
			],
			'a'      => [
				'href'   => true,
				'class'  => true,
				'target' => true,
				'rel'    => true,
			],
			'span'   => [
				'class'       => true,
				'aria-hidden' => true,
			],
			'strong' => [],
		];
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
		$subject    = PressPrimer_Certificate_Certificate::display_title( $certificate );

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

		// A date already in the past reads "Expired", not "Expires";
		// stored values are UTC, compared against UTC now.
		$expires_at    = isset( $certificate->expires_at ) ? (string) $certificate->expires_at : '';
		$expires_label = '' !== $expires_at && $expires_at <= gmdate( 'Y-m-d H:i:s' )
			? __( 'Expired', 'pressprimer-certificate' )
			: __( 'Expires', 'pressprimer-certificate' );

		$rows = [
			[ __( 'Recipient', 'pressprimer-certificate' ), $recipient ],
			[ __( 'Certificate', 'pressprimer-certificate' ), $subject ],
			[ __( 'Credential ID', 'pressprimer-certificate' ), $display_id ],
			[ __( 'Issued', 'pressprimer-certificate' ), self::display_date( $certificate->issued_at ) ],
			[ $expires_label, self::display_date( '' !== $expires_at ? $expires_at : null ) ],
		];

		$output .= '<dl class="ppcert-view__details">';

		foreach ( $rows as $row ) {
			if ( '' === $row[1] ) {
				continue;
			}

			$output .= '<dt>' . esc_html( $row[0] ) . '</dt><dd>' . esc_html( $row[1] ) . '</dd>';
		}

		$output .= '</dl>';

		$actions = [];

		if ( 'revoked' !== $status ) {
			$actions['download'] = [
				'label' => __( 'Download PDF', 'pressprimer-certificate' ),
				'url'   => self::download_url( $credential ),
				'class' => 'ppcert-view__download button',
			];
		}

		// New tab: verification is the side trip, the certificate stays put.
		$actions['verify'] = [
			'label'   => __( 'Verify this certificate', 'pressprimer-certificate' ),
			'url'     => ppcert_verification_url( $credential ),
			'class'   => 'ppcert-view__verify',
			'new_tab' => true,
		];

		/**
		 * Filters the view page's action links (2.0, Feature 2.0-006
		 * addon contract - Educator's share controls render here).
		 *
		 * Each entry: label, url, class (CSS classes), new_tab (bool).
		 * Everything escapes at output; malformed entries are dropped.
		 * Revoked certificates arrive without the download entry - added
		 * entries should respect the same status semantics.
		 *
		 * @since 2.0.0
		 *
		 * @param array  $actions     Map of action id => link definition.
		 * @param object $certificate Hydrated certificate row.
		 * @param string $status      Effective status (issued|expired|revoked).
		 */
		$actions = apply_filters( 'ppcert_view_page_actions', $actions, $certificate, $status );

		$output .= '<p class="ppcert-view__actions">';
		$output .= self::render_action_links( is_array( $actions ) ? $actions : [] );
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
		$subject = PressPrimer_Certificate_Certificate::display_title( $certificate );

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
		$subject = PressPrimer_Certificate_Certificate::display_title(
			$certificate,
			__( 'Certificate', 'pressprimer-certificate' )
		);

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
	 * Render a filtered action-link set, escaping every value
	 *
	 * Shared by the view page and My Certificates rows (the two
	 * front-end surfaces the ppcert_*_actions filters extend). Entries
	 * without a non-empty label and url are dropped; new-tab links gain
	 * rel="noopener" and the screen-reader suffix.
	 *
	 * @since 2.0.0
	 *
	 * @param array $actions Map of action id => [ label, url, class, new_tab ].
	 * @return string HTML anchors.
	 */
	public static function render_action_links( array $actions ) {
		$output = '';

		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || empty( $action['label'] ) || empty( $action['url'] ) ) {
				continue;
			}

			$class   = isset( $action['class'] ) ? (string) $action['class'] : '';
			$new_tab = ! empty( $action['new_tab'] );

			$output .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( (string) $action['url'] ) . '"'
				. ( $new_tab ? ' target="_blank" rel="noopener"' : '' ) . '>'
				. esc_html( (string) $action['label'] );

			if ( $new_tab ) {
				$output .= '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'pressprimer-certificate' ) . '</span>';
			}

			$output .= '</a> ';
		}

		return rtrim( $output );
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
	 * Public PDF download URL for a credential (2.0, Feature 2.0-007)
	 *
	 * The canonical public PDF route URL, mirroring view_url()'s
	 * semantics: '' for input that does not normalize.
	 *
	 * @since 2.0.0
	 *
	 * @param string $credential_id Credential ID (any accepted form).
	 * @return string URL, or '' for an invalid credential.
	 */
	public static function pdf_url( $credential_id ) {
		$normalized = PressPrimer_Certificate_Credential_ID_Service::normalize( (string) $credential_id );

		if ( ! PressPrimer_Certificate_Credential_ID_Service::is_credential_shaped( $normalized ) ) {
			return '';
		}

		return self::download_url( $normalized );
	}

	/**
	 * Public share URL for a credential's view page
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential_id Credential ID (any accepted form).
	 * @return string URL, or '' for an invalid credential.
	 */
	public static function view_url( $credential_id ) {
		$normalized = PressPrimer_Certificate_Credential_ID_Service::normalize( (string) $credential_id );

		// Shape-gated, not checksummed - the same deliberate choice as
		// the list search (Feature 2.0-002 FR-001): integration callers
		// get '' for input that cannot be a credential (Feature 2.0-007
		// FR-001), while anything credential-shaped builds its canonical
		// URL and simply 404s if no row exists.
		if ( ! PressPrimer_Certificate_Credential_ID_Service::is_credential_shaped( $normalized ) ) {
			return '';
		}

		$display = PressPrimer_Certificate_Credential_ID_Service::format_display( $normalized );

		return home_url( '/certificate/' . rawurlencode( $display ) . '/' );
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
