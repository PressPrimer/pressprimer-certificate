<?php
/**
 * User profile certificates section
 *
 * A PressPrimer Certificate section on the standard user-edit screen
 * (Phase 5B item 9): every certificate the user has earned, with
 * verification and PDF download links.
 *
 * @package PressPrimer_Certificate
 * @subpackage Admin
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User profile section class
 *
 * Visible to staff holding ppcert_view_certificates and to users
 * viewing their own profile. Downloads go through the public
 * credential-addressed PDF route (the same public-by-URL model as the
 * view page), so both audiences use one link.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Admin_User_Profile {

	/**
	 * Certificates per page
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const PER_PAGE = 10;

	/**
	 * Initialize: both profile screens
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'show_user_profile', [ $this, 'render_section' ] );
		add_action( 'edit_user_profile', [ $this, 'render_section' ] );
	}

	/**
	 * Whether the current viewer may see a user's certificates
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id Profile user id.
	 * @return bool
	 */
	private function can_view( $user_id ) {
		if ( current_user_can( PressPrimer_Certificate_Capabilities::CAP_VIEW_CERTIFICATES ) ) {
			return true;
		}

		return get_current_user_id() > 0 && get_current_user_id() === absint( $user_id );
	}

	/**
	 * Render the earned-certificates section
	 *
	 * @since 1.0.0
	 *
	 * @param WP_User $user The profile user.
	 */
	public function render_section( $user ) {
		if ( ! $this->can_view( (int) $user->ID ) ) {
			return;
		}

		$total = PressPrimer_Certificate_Certificate::count_for_recipient( (int) $user->ID );

		if ( $total < 1 ) {
			return;
		}

		$total_pages = (int) ceil( $total / self::PER_PAGE );

		// Read-only paging parameter, like core list tables.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only pagination.
		$page = isset( $_GET['ppcert_page'] ) ? absint( wp_unslash( $_GET['ppcert_page'] ) ) : 1;
		$page = min( max( 1, $page ), $total_pages );

		$certificates = PressPrimer_Certificate_Certificate::get_recent_for_recipient(
			(int) $user->ID,
			self::PER_PAGE,
			( $page - 1 ) * self::PER_PAGE
		);

		echo wp_kses( $this->build_section( $certificates, $page, $total_pages ), self::allowed_section_tags() );
	}

	/**
	 * The explicit allowed-tags array for the section markup
	 *
	 * Every element build_section() emits, and nothing else. The style
	 * attributes carry only max-width/margin (safecss-permitted).
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function allowed_section_tags() {
		return [
			'h2'    => [ 'id' => true ],
			'table' => [
				'class' => true,
				'style' => true,
			],
			'thead' => [],
			'tbody' => [],
			'tr'    => [],
			'th'    => [ 'scope' => true ],
			'td'    => [],
			'a'     => [
				'href'   => true,
				'class'  => true,
				'target' => true,
				'rel'    => true,
			],
			'span'  => [
				'class'       => true,
				'aria-hidden' => true,
			],
			'div'   => [
				'class' => true,
				'style' => true,
			],
		];
	}

	/**
	 * Build the section markup (exhaustively escaped)
	 *
	 * @since 1.0.0
	 *
	 * @param object[] $certificates Hydrated rows with template_title.
	 * @param int      $page         Current 1-based page.
	 * @param int      $total_pages  Total pages.
	 * @return string
	 */
	public function build_section( array $certificates, $page = 1, $total_pages = 1 ) {
		$status_labels = [
			'issued'  => __( 'Issued', 'pressprimer-certificate' ),
			'revoked' => __( 'Revoked', 'pressprimer-certificate' ),
			'expired' => __( 'Expired', 'pressprimer-certificate' ),
		];

		$output  = '<h2 id="ppcert-user-certificates">' . esc_html__( 'PressPrimer Certificates', 'pressprimer-certificate' ) . '</h2>';
		$output .= '<table class="widefat striped" style="max-width:800px">';
		$output .= '<thead><tr>';
		$output .= '<th scope="col">' . esc_html__( 'Credential ID', 'pressprimer-certificate' ) . '</th>';
		$output .= '<th scope="col">' . esc_html__( 'Certificate', 'pressprimer-certificate' ) . '</th>';
		$output .= '<th scope="col">' . esc_html__( 'Status', 'pressprimer-certificate' ) . '</th>';
		$output .= '<th scope="col">' . esc_html__( 'Earned', 'pressprimer-certificate' ) . '</th>';
		$output .= '<th scope="col">' . esc_html__( 'Expires', 'pressprimer-certificate' ) . '</th>';
		$output .= '<th scope="col">' . esc_html__( 'Links', 'pressprimer-certificate' ) . '</th>';
		$output .= '</tr></thead><tbody>';

		foreach ( $certificates as $certificate ) {
			$credential = (string) $certificate->credential_id;
			$status     = PressPrimer_Certificate_Certificate::effective_status( $certificate );

			$links = [
				'<a href="' . esc_url( ppcert_verification_url( $credential ) ) . '" target="_blank" rel="noopener">'
					. esc_html__( 'Verify', 'pressprimer-certificate' )
					. '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'pressprimer-certificate' ) . '</span></a>',
			];

			// Revoked certificates are not served by the download route.
			if ( 'revoked' !== $status ) {
				$links[] = '<a href="'
					. esc_url( rest_url( 'ppcert/v1/certificates/' . rawurlencode( $credential ) . '/pdf' ) )
					. '">' . esc_html__( 'Download PDF', 'pressprimer-certificate' ) . '</a>';
			}

			$output .= '<tr>';
			$output .= '<td>' . esc_html( PressPrimer_Certificate_Credential_ID_Service::format_display( $credential ) ) . '</td>';
			$output .= '<td>' . esc_html( isset( $certificate->template_title ) && null !== $certificate->template_title ? (string) $certificate->template_title : __( '(deleted template)', 'pressprimer-certificate' ) ) . '</td>';
			$output .= '<td>' . esc_html( isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status ) . '</td>';
			// UTC in, localized out (CLAUDE.md Datetime Standard).
			$output .= '<td>' . esc_html( get_date_from_gmt( (string) $certificate->issued_at, get_option( 'date_format' ) ) ) . '</td>';

			$output .= '<td>' . (
				empty( $certificate->expires_at )
					? '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__( 'Never expires', 'pressprimer-certificate' ) . '</span>'
					: esc_html( get_date_from_gmt( (string) $certificate->expires_at, get_option( 'date_format' ) ) )
			) . '</td>';

			$output .= '<td>' . implode( ' | ', $links ) . '</td>';
			$output .= '</tr>';
		}

		$output .= '</tbody></table>';

		if ( $total_pages > 1 ) {
			$links = paginate_links(
				[
					'base'      => add_query_arg( 'ppcert_page', '%#%' ) . '#ppcert-user-certificates',
					'format'    => '',
					'current'   => max( 1, (int) $page ),
					'total'     => (int) $total_pages,
					'add_args'  => false,
					'prev_text' => __( '&laquo; Previous', 'pressprimer-certificate' ),
					'next_text' => __( 'Next &raquo;', 'pressprimer-certificate' ),
					'type'      => 'plain',
				]
			);

			if ( $links ) {
				$output .= '<div class="tablenav"><div class="tablenav-pages" style="margin:8px 0">'
					. wp_kses_post( $links ) . '</div></div>';
			}
		}

		return $output;
	}
}
