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

		// The Issue button + modal mount as React (Ant Design) beside
		// the title; issuance capability gates the mount server-side.
		if ( current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES ) ) {
			echo '<span id="ppcert-issue-root"></span>';
		}

		echo '<hr class="wp-header-end" />';

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="ppcert-certificates" />';
		$list_table->search_box( __( 'Search certificates', 'pressprimer-certificate' ), 'ppcert-certificates' );
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
