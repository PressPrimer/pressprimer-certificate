<?php
/**
 * Certificates admin screen
 *
 * The issued-certificates list + manual issuance (Feature 003 FR-003)
 * and the capability-gated admin PDF download.
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
 * Certificates admin class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Admin_Certificates {

	/**
	 * The screen's hook suffix
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $hook = '';

	/**
	 * Initialize: menu, assets, download handler
	 *
	 * @since 1.0.0
	 */
	public function init() {
		// After the main menu registration (default 10) so the submenu
		// lands beneath Templates.
		add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_ppcert_download_certificate', [ $this, 'handle_download' ] );
		add_action( 'admin_init', [ $this, 'handle_revoke_action' ] );
		add_action( 'admin_init', [ $this, 'handle_reinstate_action' ] );
		add_action( 'admin_init', [ $this, 'handle_delete_action' ] );
		add_action( 'admin_init', [ $this, 'handle_resend_action' ] );
	}

	/**
	 * Handle the confirmed Revoke action
	 *
	 * Nonce-verified, capability-gated status flip with an optional
	 * reason, submitted by the on-page confirmation banner, then a
	 * redirect back to the list with a notice flag.
	 *
	 * @since 1.0.0
	 */
	public function handle_revoke_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; the nonce verifies below before any action.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; the nonce verifies below before any action.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'ppcert-certificates' !== $page || 'ppcert-revoke' !== $action ) {
			return;
		}

		if ( ! current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES ) ) {
			wp_die( esc_html__( 'You are not allowed to revoke certificates.', 'pressprimer-certificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer immediately below.
		$certificate_id = isset( $_GET['certificate_id'] ) ? absint( wp_unslash( $_GET['certificate_id'] ) ) : 0;

		check_admin_referer( 'ppcert_revoke_certificate_' . $certificate_id );

		$reason = isset( $_GET['revoke_reason'] )
			? sanitize_text_field( wp_unslash( $_GET['revoke_reason'] ) )
			: '';

		$result  = PressPrimer_Certificate_Certificate::revoke( $certificate_id, $reason );
		$revoked = ! is_wp_error( $result ) ? 1 : 0;

		wp_safe_redirect(
			add_query_arg(
				[
					'page'           => 'ppcert-certificates',
					'ppcert_revoked' => $revoked,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the Reinstate action
	 *
	 * Nonce-verified, capability-gated undo for a mistaken revocation,
	 * then a redirect back to the list with a notice flag.
	 *
	 * @since 1.0.0
	 */
	public function handle_reinstate_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; the nonce verifies below before any action.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; the nonce verifies below before any action.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'ppcert-certificates' !== $page || 'ppcert-reinstate' !== $action ) {
			return;
		}

		if ( ! current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES ) ) {
			wp_die( esc_html__( 'You are not allowed to reinstate certificates.', 'pressprimer-certificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer immediately below.
		$certificate_id = isset( $_GET['certificate_id'] ) ? absint( wp_unslash( $_GET['certificate_id'] ) ) : 0;

		check_admin_referer( 'ppcert_reinstate_certificate_' . $certificate_id );

		$result     = PressPrimer_Certificate_Certificate::reinstate( $certificate_id );
		$reinstated = ! is_wp_error( $result ) ? 1 : 0;

		wp_safe_redirect(
			add_query_arg(
				[
					'page'              => 'ppcert-certificates',
					'ppcert_reinstated' => $reinstated,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the confirmed Delete action
	 *
	 * Nonce-verified, capability-gated permanent deletion (test data
	 * and mistakes), then a redirect back to the list with a notice
	 * flag. The list table's confirmation modal points earned-credential
	 * cases at Revoke instead.
	 *
	 * @since 1.0.0
	 */
	public function handle_delete_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; the nonce verifies below before any action.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; the nonce verifies below before any action.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'ppcert-certificates' !== $page || 'ppcert-delete' !== $action ) {
			return;
		}

		if ( ! current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES ) ) {
			wp_die( esc_html__( 'You are not allowed to delete certificates.', 'pressprimer-certificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer immediately below.
		$certificate_id = isset( $_GET['certificate_id'] ) ? absint( wp_unslash( $_GET['certificate_id'] ) ) : 0;

		check_admin_referer( 'ppcert_delete_certificate_' . $certificate_id );

		$result  = PressPrimer_Certificate_Certificate::delete( $certificate_id );
		$deleted = ! is_wp_error( $result ) ? 1 : 0;

		wp_safe_redirect(
			add_query_arg(
				[
					'page'           => 'ppcert-certificates',
					'ppcert_deleted' => $deleted,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the Resend email action
	 *
	 * Nonce-verified, capability-gated resend of the delivery email,
	 * then a redirect back to the list with a notice flag.
	 *
	 * @since 1.0.0
	 */
	public function handle_resend_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; the nonce verifies below before any action.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; the nonce verifies below before any action.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'ppcert-certificates' !== $page || 'ppcert-resend-email' !== $action ) {
			return;
		}

		if ( ! current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES ) ) {
			wp_die( esc_html__( 'You are not allowed to resend certificate emails.', 'pressprimer-certificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer immediately below.
		$certificate_id = isset( $_GET['certificate_id'] ) ? absint( wp_unslash( $_GET['certificate_id'] ) ) : 0;

		check_admin_referer( 'ppcert_resend_email_' . $certificate_id );

		$sent = PressPrimer_Certificate_Email_Service::resend( $certificate_id ) ? 1 : 0;

		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => 'ppcert-certificates',
					'ppcert_resent' => $sent,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Register the Certificates submenu
	 *
	 * @since 1.0.0
	 */
	public function register_menu() {
		$this->hook = add_submenu_page(
			'pressprimer-certificate',
			__( 'Certificates', 'pressprimer-certificate' ),
			__( 'Certificates', 'pressprimer-certificate' ),
			PressPrimer_Certificate_Capabilities::CAP_VIEW_CERTIFICATES,
			'ppcert-certificates',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the list screen with the Issue modal mount
	 *
	 * @since 1.0.0
	 */
	public function render_page() {
		$list_table = new PressPrimer_Certificate_Certificates_List_Table();
		$list_table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Certificates', 'pressprimer-certificate' ) . '</h1> ';

		// The core page-title-action button (house pattern for buttons
		// beside list-table titles); the React app binds its click to
		// open the Issue modal, whose portal mounts in the span.
		// Issuance capability gates both server-side.
		if ( current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES ) ) {
			echo '<a href="#" class="page-title-action" id="ppcert-issue-open">' . esc_html__( 'Issue Certificate', 'pressprimer-certificate' ) . '</a>';
			echo '<span id="ppcert-issue-root"></span>';
		}

		echo '<hr class="wp-header-end" />';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by the nonce-verified revoke handler.
		if ( isset( $_GET['ppcert_revoked'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
			$revoked = absint( wp_unslash( $_GET['ppcert_revoked'] ) );

			echo '<div class="notice notice-' . ( $revoked ? 'success' : 'error' ) . ' is-dismissible"><p>';
			echo $revoked
				? esc_html__( 'Certificate revoked. It now verifies as invalid and its PDF download is disabled. Use Reinstate to undo.', 'pressprimer-certificate' )
				: esc_html__( 'The certificate could not be revoked.', 'pressprimer-certificate' );
			echo '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by the nonce-verified reinstate handler.
		if ( isset( $_GET['ppcert_reinstated'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
			$reinstated = absint( wp_unslash( $_GET['ppcert_reinstated'] ) );

			echo '<div class="notice notice-' . ( $reinstated ? 'success' : 'error' ) . ' is-dismissible"><p>';
			echo $reinstated
				? esc_html__( 'Certificate reinstated. It verifies as valid again.', 'pressprimer-certificate' )
				: esc_html__( 'The certificate could not be reinstated.', 'pressprimer-certificate' );
			echo '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by the nonce-verified delete handler.
		if ( isset( $_GET['ppcert_deleted'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
			$deleted = absint( wp_unslash( $_GET['ppcert_deleted'] ) );

			echo '<div class="notice notice-' . ( $deleted ? 'success' : 'error' ) . ' is-dismissible"><p>';
			echo $deleted
				? esc_html__( 'Certificate permanently deleted. Its credential ID no longer verifies.', 'pressprimer-certificate' )
				: esc_html__( 'The certificate could not be deleted.', 'pressprimer-certificate' );
			echo '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by the nonce-verified resend handler.
		if ( isset( $_GET['ppcert_resent'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
			$resent = absint( wp_unslash( $_GET['ppcert_resent'] ) );

			echo '<div class="notice notice-' . ( $resent ? 'success' : 'error' ) . ' is-dismissible"><p>';
			echo $resent
				? esc_html__( 'Certificate email sent to the recipient.', 'pressprimer-certificate' )
				: esc_html__( 'The certificate email could not be sent. Check that the recipient has a valid email address.', 'pressprimer-certificate' );
			echo '</p></div>';
		}

		// One GET form wraps search, the extra_tablenav filter controls,
		// and the table (the ecosystem list pattern - Assignment's
		// Submissions screen is the reference), so filter state lives in
		// the URL, survives reload, and can be shared between admins
		// (Feature 2.0-002 FR-003).
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="ppcert-certificates" />';
		$list_table->search_box( __( 'Search certificates', 'pressprimer-certificate' ), 'ppcert-certificates' );
		echo '<p class="description ppcert-certificates-search-help">'
			. esc_html__( 'Search matches recipient name or email, a full credential ID, and certificate titles.', 'pressprimer-certificate' )
			. '</p>';
		$list_table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Enqueue the Issue modal app on this screen only
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		// Global WP-admin / Ant Design conflict overrides (ecosystem
		// pattern - same sheet the designer screen loads).
		wp_enqueue_style(
			'ppcert-admin',
			PPCERT_PLUGIN_URL . 'assets/css/ppcert-admin.css',
			[],
			PPCERT_VERSION
		);

		// The house confirmation modal for link-driven list actions
		// (the Revoke flow) - the Quiz admin.js pattern.
		wp_enqueue_script(
			'ppcert-admin',
			PPCERT_PLUGIN_URL . 'assets/js/ppcert-admin.js',
			[ 'jquery' ],
			PPCERT_VERSION,
			true
		);

		if ( ! current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES ) ) {
			return;
		}

		$asset_file = PPCERT_PLUGIN_DIR . 'build/issue.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'ppcert-issue',
			PPCERT_PLUGIN_URL . 'build/issue.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'ppcert-issue', 'pressprimer-certificate', PPCERT_PLUGIN_DIR . 'languages' );

		if ( file_exists( PPCERT_PLUGIN_DIR . 'build/style-issue.css' ) ) {
			wp_enqueue_style(
				'ppcert-issue',
				PPCERT_PLUGIN_URL . 'build/style-issue.css',
				[],
				$asset['version']
			);
		}

		// Published templates for the modal's template select - read
		// server-side so the modal opens instantly.
		$templates = [];

		foreach ( PressPrimer_Certificate_Template::get_all() as $template ) {
			if ( 'published' === (string) $template->status ) {
				$templates[] = [
					'id'    => (int) $template->id,
					'title' => (string) $template->title,
				];
			}
		}

		wp_localize_script(
			'ppcert-issue',
			'ppcert_issue_data',
			[
				'templates' => $templates,
			]
		);
	}


	/**
	 * Stream a certificate PDF to staff (row action Download PDF)
	 *
	 * Nonce + capability gated; renders from the immutable snapshot.
	 * The public download route (recipients, view page) ships in
	 * Prompt 4.6 - this admin path is separate by design.
	 *
	 * @since 1.0.0
	 */
	public function handle_download() {
		$certificate_id = isset( $_GET['certificate_id'] ) ? absint( wp_unslash( $_GET['certificate_id'] ) ) : 0;

		check_admin_referer( 'ppcert_download_certificate_' . $certificate_id );

		if ( ! current_user_can( PressPrimer_Certificate_Capabilities::CAP_VIEW_CERTIFICATES ) ) {
			wp_die( esc_html__( 'You are not allowed to download certificates.', 'pressprimer-certificate' ), 403 );
		}

		$certificate = PressPrimer_Certificate_Certificate::get( $certificate_id );

		if ( ! $certificate || ! is_array( $certificate->layout_snapshot ) ) {
			wp_die( esc_html__( 'Certificate not found.', 'pressprimer-certificate' ), 404 );
		}

		$renderer = new PressPrimer_Certificate_PDF_Renderer();
		$pdf_path = $renderer->render_pdf(
			$certificate->layout_snapshot,
			is_array( $certificate->merge_data ) ? $certificate->merge_data : [],
			[
				'context'       => 'download',
				'credential_id' => (string) $certificate->credential_id,
				// PDF metadata title = the certificate's own name (1.1-006).
				'title'         => PressPrimer_Certificate_Certificate::display_title( $certificate ),
			]
		);

		if ( is_wp_error( $pdf_path ) ) {
			wp_die( esc_html( $pdf_path->get_error_message() ), 500 );
		}

		PressPrimer_Certificate_Certificate::record_event(
			(int) $certificate->id,
			'downloaded',
			get_current_user_id()
		);

		$filename = 'certificate-' . strtolower( (string) $certificate->credential_id ) . '.pdf';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $pdf_path ) );

		readfile( $pdf_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a locally rendered temp file.
		unlink( $pdf_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removing the temp render.

		exit;
	}
}
