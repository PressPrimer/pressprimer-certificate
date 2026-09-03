<?php
/**
 * My Certificates list
 *
 * The logged-in recipient's certificate list: `[ppcert_my_certificates]`
 * shortcode plus the matching dynamic block (Feature 005; returned to
 * free 1.0 on 2026-07-26 - the Educator "wallet" is wallet-SIZED
 * printable variants, not this list).
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
 * My Certificates class
 *
 * Renders each certificate's name, earned date, expiry (when set),
 * status, and verify/view/download links, styled by the shared
 * front-end token sheet. Revoked certificates stay listed (marked)
 * but never offer a download.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_My_Certificates {

	/**
	 * Certificates per page.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const PER_PAGE = 10;

	/**
	 * Register the shortcode
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_shortcode( 'ppcert_my_certificates', [ __CLASS__, 'render_shortcode' ] );
	}

	/**
	 * Render the list
	 *
	 * @since 1.0.0
	 *
	 * @param array $atts Shortcode attributes (none in 1.0).
	 * @return string
	 */
	public static function render_shortcode( $atts = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Shortcode signature; attributes arrive in a later version.
		wp_enqueue_style( 'ppcert-frontend' );

		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return '<div class="ppcert-my-certificates"><p class="ppcert-my-certificates__login">'
				. esc_html__( 'Log in to see your certificates.', 'pressprimer-certificate' )
				. '</p></div>';
		}

		// The controls render whenever the recipient has ANY
		// certificates; the empty message below reflects the filter.
		$overall = PressPrimer_Certificate_Certificate::count_for_recipient( $user_id );

		if ( $overall < 1 ) {
			return '<div class="ppcert-my-certificates"><p class="ppcert-my-certificates__empty">'
				. esc_html__( 'No certificates yet. Certificates you earn will appear here.', 'pressprimer-certificate' )
				. '</p></div>';
		}

		// Visitor-driven view state; read-only display parameters with
		// allowlisted values (like core pagination).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only view state.
		$status = isset( $_GET['ppcert_status'] ) ? sanitize_key( wp_unslash( $_GET['ppcert_status'] ) ) : 'all';
		$status = in_array( $status, [ 'valid', 'expired' ], true ) ? $status : 'all';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only view state.
		$sort = isset( $_GET['ppcert_sort'] ) ? sanitize_key( wp_unslash( $_GET['ppcert_sort'] ) ) : 'newest';
		$sort = in_array( $sort, [ 'expiring', 'name' ], true ) ? $sort : 'newest';

		$total = PressPrimer_Certificate_Certificate::count_list_for_recipient( $user_id, $status );

		$output = '<div class="ppcert-my-certificates">';

		$output .= self::render_controls( $status, $sort );

		if ( $total < 1 ) {
			$output .= '<p class="ppcert-my-certificates__empty">'
				. esc_html__( 'No certificates match this view.', 'pressprimer-certificate' )
				. '</p></div>';

			return $output;
		}

		$total_pages = (int) ceil( $total / self::PER_PAGE );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only pagination.
		$page = isset( $_GET['ppcert_certs_page'] ) ? absint( wp_unslash( $_GET['ppcert_certs_page'] ) ) : 1;
		$page = min( max( 1, $page ), $total_pages );

		$certificates = PressPrimer_Certificate_Certificate::get_list_for_recipient(
			$user_id,
			[
				'status' => $status,
				'sort'   => $sort,
				'limit'  => self::PER_PAGE,
				'offset' => ( $page - 1 ) * self::PER_PAGE,
			]
		);

		$output .= '<ul class="ppcert-my-certificates__list">';

		foreach ( $certificates as $certificate ) {
			$output .= self::render_item( $certificate );
		}

		$output .= '</ul>';
		$output .= self::render_pagination( $page, $total_pages );
		$output .= '</div>';

		// Return-time allowlist pass (shortcode/block returns are
		// rendered by WordPress; every value is escaped at build time
		// and this proves it structurally).
		return wp_kses( $output, self::allowed_output_tags() );
	}

	/**
	 * The explicit allowed-tags array for the list's returned markup
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function allowed_output_tags() {
		return [
			'div'    => [ 'class' => true ],
			'ul'     => [ 'class' => true ],
			'li'     => [ 'class' => true ],
			'h3'     => [ 'class' => true ],
			'p'      => [ 'class' => true ],
			'nav'    => [
				'class'      => true,
				'aria-label' => true,
			],
			'a'      => [
				'href'         => true,
				'class'        => true,
				'target'       => true,
				'rel'          => true,
				'aria-current' => true,
			],
			'span'   => [
				'class'        => true,
				'aria-hidden'  => true,
				'role'         => true,
				'aria-label'   => true,
				'aria-current' => true,
			],
			'strong' => [],
		];
	}

	/**
	 * Render the filter + sort control row
	 *
	 * Server-rendered chip links driven by GET parameters; changing
	 * either control resets pagination. No JavaScript, no shortcode
	 * attributes (visitor-driven view state, not author configuration).
	 *
	 * @since 1.0.0
	 *
	 * @param string $status Active status filter.
	 * @param string $sort   Active sort.
	 * @return string
	 */
	private static function render_controls( $status, $sort ) {
		$statuses = [
			'all'     => __( 'All', 'pressprimer-certificate' ),
			'valid'   => __( 'Valid', 'pressprimer-certificate' ),
			'expired' => __( 'Expired', 'pressprimer-certificate' ),
		];

		$sorts = [
			'newest'   => __( 'Newest', 'pressprimer-certificate' ),
			'expiring' => __( 'Expiring soonest', 'pressprimer-certificate' ),
			'name'     => __( 'Name', 'pressprimer-certificate' ),
		];

		$output = '<div class="ppcert-my-certificates__controls">';

		$output .= '<span class="ppcert-my-certificates__control-group" role="group" aria-label="'
			. esc_attr__( 'Filter certificates by status', 'pressprimer-certificate' ) . '">';
		$output .= '<span class="ppcert-my-certificates__control-label">' . esc_html__( 'Show', 'pressprimer-certificate' ) . '</span>';

		foreach ( $statuses as $key => $label ) {
			$output .= self::render_chip( $label, [ 'ppcert_status' => $key ], $key === $status );
		}

		$output .= '</span>';

		$output .= '<span class="ppcert-my-certificates__control-group" role="group" aria-label="'
			. esc_attr__( 'Sort certificates', 'pressprimer-certificate' ) . '">';
		$output .= '<span class="ppcert-my-certificates__control-label">' . esc_html__( 'Sort', 'pressprimer-certificate' ) . '</span>';

		foreach ( $sorts as $key => $label ) {
			$output .= self::render_chip( $label, [ 'ppcert_sort' => $key ], $key === $sort );
		}

		$output .= '</span></div>';

		return $output;
	}

	/**
	 * Render one control chip link
	 *
	 * Preserves the other control's parameter, drops defaults so the
	 * canonical view has a clean URL, and always resets pagination.
	 *
	 * @since 1.0.0
	 *
	 * @param string $label  Chip label.
	 * @param array  $change Parameter to change (one key => value).
	 * @param bool   $active Whether this chip is the active view.
	 * @return string
	 */
	private static function render_chip( $label, array $change, $active ) {
		$url = remove_query_arg( [ 'ppcert_certs_page' ] );

		foreach ( $change as $key => $value ) {
			$is_default = ( 'ppcert_status' === $key && 'all' === $value )
				|| ( 'ppcert_sort' === $key && 'newest' === $value );

			$url = $is_default ? remove_query_arg( $key, $url ) : add_query_arg( $key, $value, $url );
		}

		return '<a class="ppcert-my-certificates__chip' . ( $active ? ' is-active' : '' ) . '"'
			. ( $active ? ' aria-current="true"' : '' )
			. ' href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Render one certificate row
	 *
	 * @since 1.0.0
	 *
	 * @param object $certificate Hydrated row (with template_title).
	 * @return string
	 */
	private static function render_item( $certificate ) {
		$status     = PressPrimer_Certificate_Certificate::effective_status( $certificate );
		$credential = (string) $certificate->credential_id;

		$title = PressPrimer_Certificate_Certificate::display_title(
			$certificate,
			__( '(deleted template)', 'pressprimer-certificate' )
		);

		$pills = [
			'issued'  => [ 'valid', __( 'Valid', 'pressprimer-certificate' ) ],
			'expired' => [ 'expired', __( 'Expired', 'pressprimer-certificate' ) ],
			'revoked' => [ 'revoked', __( 'Revoked', 'pressprimer-certificate' ) ],
		];
		$pill  = isset( $pills[ $status ] ) ? $pills[ $status ] : $pills['issued'];

		$view_url = PressPrimer_Certificate_View_Page::view_url( $credential );

		$output = '<li class="ppcert-my-certificates__item"><div>';

		$output .= '<h3 class="ppcert-my-certificates__title">';

		if ( '' !== $view_url ) {
			$output .= '<a href="' . esc_url( $view_url ) . '">' . esc_html( $title ) . '</a>';
		} else {
			$output .= esc_html( $title );
		}

		$output .= ' <span class="ppcert-pill ppcert-pill--' . esc_attr( $pill[0] ) . '">' . esc_html( $pill[1] ) . '</span>';
		$output .= '</h3>';

		$output .= '<p class="ppcert-my-certificates__meta">';
		$output .= '<span>' . esc_html__( 'Earned', 'pressprimer-certificate' ) . ' <strong>'
			. esc_html( get_date_from_gmt( (string) $certificate->issued_at, get_option( 'date_format' ) ) )
			. '</strong></span>';

		if ( ! empty( $certificate->expires_at ) ) {
			// Past dates read "Expired", future dates "Expires" (UTC).
			$expires_label = (string) $certificate->expires_at <= gmdate( 'Y-m-d H:i:s' )
				? __( 'Expired', 'pressprimer-certificate' )
				: __( 'Expires', 'pressprimer-certificate' );

			$output .= '<span>' . esc_html( $expires_label ) . ' <strong>'
				. esc_html( get_date_from_gmt( (string) $certificate->expires_at, get_option( 'date_format' ) ) )
				. '</strong></span>';
		}

		$output .= '</p></div>';

		$actions = [];

		// Revoked certificates are not served by the download route.
		if ( 'revoked' !== $status ) {
			$actions['download'] = [
				'label' => __( 'Download PDF', 'pressprimer-certificate' ),
				'url'   => rest_url( 'ppcert/v1/certificates/' . rawurlencode( $credential ) . '/pdf' ),
				'class' => 'ppcert-button-primary',
			];
		}

		// New tab: verifying is a side trip, the list stays put.
		$actions['verify'] = [
			'label'   => __( 'Verify', 'pressprimer-certificate' ),
			'url'     => ppcert_verification_url( $credential ),
			'class'   => 'ppcert-button-secondary',
			'new_tab' => true,
		];

		/**
		 * Filters a My Certificates row's action links (2.0, Feature
		 * 2.0-006 addon contract - Educator's share controls render
		 * here). Same entry shape and escaping rules as
		 * ppcert_view_page_actions.
		 *
		 * @since 2.0.0
		 *
		 * @param array  $actions Map of action id => link definition.
		 * @param object $certificate Certificate row.
		 * @param string $status  Effective status (issued|expired|revoked).
		 */
		$actions = apply_filters( 'ppcert_my_certificates_row_actions', $actions, $certificate, $status );

		$output .= '<span class="ppcert-my-certificates__links">';
		$output .= PressPrimer_Certificate_View_Page::render_action_links( is_array( $actions ) ? $actions : [] );
		$output .= '</span></li>';

		return $output;
	}

	/**
	 * Render pagination links
	 *
	 * @since 1.0.0
	 *
	 * @param int $page        Current 1-based page.
	 * @param int $total_pages Total pages.
	 * @return string
	 */
	private static function render_pagination( $page, $total_pages ) {
		if ( $total_pages < 2 ) {
			return '';
		}

		$links = paginate_links(
			[
				'base'      => add_query_arg( 'ppcert_certs_page', '%#%' ),
				'format'    => '',
				'current'   => max( 1, (int) $page ),
				'total'     => (int) $total_pages,
				'add_args'  => false,
				'prev_text' => __( '&laquo; Previous', 'pressprimer-certificate' ),
				'next_text' => __( 'Next &raquo;', 'pressprimer-certificate' ),
				'type'      => 'plain',
			]
		);

		if ( ! $links ) {
			return '';
		}

		return '<nav class="ppcert-my-certificates__pagination" aria-label="'
			. esc_attr__( 'Certificates pagination', 'pressprimer-certificate' ) . '">'
			. wp_kses_post( $links ) . '</nav>';
	}
}
