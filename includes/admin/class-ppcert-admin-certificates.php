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

		self::authorize( $certificate_id, 'revoke' );

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

		self::authorize( $certificate_id, 'reinstate' );

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

		self::authorize( $certificate_id, 'delete' );

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

		self::authorize( $certificate_id, 'resend' );

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
	 * Refuse a single-certificate action the user may not take
	 *
	 * The per-certificate check (2.1, Feature 2.1-004 FR-011): the
	 * certificate scope by default, narrowed or widened by
	 * ppcert_user_can_manage_certificate (School: delete is owner-only).
	 * Dies with the scope message when the certificate is out of scope,
	 * else with the action's own.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $certificate_id Certificate row id.
	 * @param string $action         revoke | reinstate | delete | resend.
	 * @return void
	 */
	private static function authorize( $certificate_id, $action ) {
		$certificate = PressPrimer_Certificate_Certificate::get( $certificate_id );
		$user_id     = get_current_user_id();

		if ( PressPrimer_Certificate_Certificate::user_can_manage( $certificate, $user_id, $action ) ) {
			return;
		}

		if ( ! PressPrimer_Certificate_Certificate::user_can_access( $certificate, $user_id ) ) {
			wp_die( esc_html__( 'This certificate is outside your issuing organizations.', 'pressprimer-certificate' ) );
		}

		$messages = [
			'revoke'    => __( 'You are not allowed to revoke this certificate.', 'pressprimer-certificate' ),
			'reinstate' => __( 'You are not allowed to reinstate this certificate.', 'pressprimer-certificate' ),
			'delete'    => __( 'You are not allowed to delete this certificate.', 'pressprimer-certificate' ),
			'resend'    => __( 'You are not allowed to resend this certificate\'s email.', 'pressprimer-certificate' ),
		];

		wp_die( esc_html( isset( $messages[ $action ] ) ? $messages[ $action ] : $messages['revoke'] ) );
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

		$this->render_bulk_resume_notices();

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
		// "Select all matching" context for ppcert-admin.js (2.1): only
		// for users with bulk actions.
		if ( current_user_can( PressPrimer_Certificate_Capabilities::CAP_ISSUE_CERTIFICATES )
			&& [] !== array_filter(
				PressPrimer_Certificate_Bulk_Action_Service::ACTIONS,
				static function ( $bulk_action ) {
					return PressPrimer_Certificate_Certificate::user_can_bulk_manage( get_current_user_id(), $bulk_action );
				}
			) ) {
			echo '<div id="ppcert-bulk-context" hidden data-ppcert-context="' . esc_attr( (string) wp_json_encode( $list_table->bulk_context() ) ) . '"></div>';
		}

		$list_table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Notices for the user's unfinished bulk jobs (2.1, Feature 2.1-002
	 * FR-006): "A bulk delete stopped at 140 of 312." with Resume and
	 * Discard, which ppcert-admin.js handles.
	 *
	 * @since 2.1.0
	 */
	private function render_bulk_resume_notices() {
		foreach ( array_slice( PressPrimer_Certificate_Bulk_Action_Service::unfinished_for( get_current_user_id() ), 0, 3 ) as $job ) {
			echo '<div class="notice notice-warning ppcert-bulk-resume-notice"><p>';
			echo esc_html( self::bulk_stopped_message( (string) $job['action'], (int) $job['processed'], (int) $job['total'] ) );

			if ( '' !== (string) $job['message'] ) {
				echo ' ' . esc_html( (string) $job['message'] );
			}

			echo ' <button type="button" class="button button-primary ppcert-bulk-resume" data-ppcert-job="' . esc_attr( (string) $job['id'] ) . '">'
				. esc_html__( 'Resume', 'pressprimer-certificate' ) . '</button>';
			echo ' <button type="button" class="button ppcert-bulk-discard" data-ppcert-job="' . esc_attr( (string) $job['id'] ) . '">'
				. esc_html__( 'Discard', 'pressprimer-certificate' ) . '</button>';
			echo '</p></div>';
		}
	}

	/**
	 * "A bulk delete stopped at 140 of 312 certificates."
	 *
	 * @since 2.1.0
	 *
	 * @param string $action    Bulk action.
	 * @param int    $processed Certificates processed.
	 * @param int    $total     Certificates in the job.
	 * @return string
	 */
	private static function bulk_stopped_message( $action, $processed, $total ) {
		switch ( $action ) {
			case 'reinstate':
				/* translators: 1: certificates processed, 2: certificates in the job. */
				$format = _n( 'A bulk reinstate stopped at %1$s of %2$s certificate.', 'A bulk reinstate stopped at %1$s of %2$s certificates.', $total, 'pressprimer-certificate' );
				break;

			case 'resend':
				/* translators: 1: emails processed, 2: emails in the job. */
				$format = _n( 'A bulk email resend stopped at %1$s of %2$s certificate.', 'A bulk email resend stopped at %1$s of %2$s certificates.', $total, 'pressprimer-certificate' );
				break;

			case 'delete':
				/* translators: 1: certificates processed, 2: certificates in the job. */
				$format = _n( 'A bulk delete stopped at %1$s of %2$s certificate.', 'A bulk delete stopped at %1$s of %2$s certificates.', $total, 'pressprimer-certificate' );
				break;

			default:
				/* translators: 1: certificates processed, 2: certificates in the job. */
				$format = _n( 'A bulk revoke stopped at %1$s of %2$s certificate.', 'A bulk revoke stopped at %1$s of %2$s certificates.', $total, 'pressprimer-certificate' );
		}

		return sprintf( $format, number_format_i18n( $processed ), number_format_i18n( $total ) );
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
			[ 'jquery', 'wp-api-fetch', 'wp-i18n' ],
			PPCERT_VERSION,
			true
		);

		wp_set_script_translations( 'ppcert-admin', 'pressprimer-certificate', PPCERT_PLUGIN_DIR . 'languages' );

		// Bulk actions (2.1, Feature 2.1-002): the extra sentence each
		// confirmation may carry.
		$notes = [];

		foreach ( PressPrimer_Certificate_Bulk_Action_Service::ACTIONS as $bulk_action ) {
			/**
			 * Filters an extra sentence for a bulk action's confirmation.
			 *
			 * School can say that resent emails also go to an issuer's CC
			 * and BCC addresses. Plain text.
			 *
			 * @since 2.1.0
			 *
			 * @param string $note    Default ''.
			 * @param string $action  revoke | reinstate | resend | delete.
			 * @param int    $user_id Current user id.
			 */
			$notes[ $bulk_action ] = sanitize_text_field( (string) apply_filters( 'ppcert_bulk_confirm_note', '', $bulk_action, get_current_user_id() ) );
		}

		wp_localize_script(
			'ppcert-admin',
			'ppcert_bulk_data',
			[
				'notes' => $notes,
			]
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
					'id'        => (int) $template->id,
					'title'     => (string) $template->title,
					'issuer_id' => ! empty( $template->issuer_id ) ? (int) $template->issuer_id : 0,
				];
			}
		}

		/**
		 * Filters the issue modal's template choices.
		 *
		 * The addon scoping surface (2.0, School contract): School keeps
		 * only the templates the current user may award from, so the
		 * picker matches what the award endpoint will accept.
		 *
		 * @since 2.0.0
		 *
		 * @param array $templates Template choices (id, title, issuer_id).
		 * @param int   $user_id   Current user id.
		 */
		$templates = apply_filters( 'ppcert_issue_templates', $templates, get_current_user_id() );

		wp_localize_script(
			'ppcert-issue',
			'ppcert_issue_data',
			[
				'templates' => array_values( $templates ),
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

		if ( $certificate && ! PressPrimer_Certificate_Certificate::user_can_access( $certificate, get_current_user_id() ) ) {
			wp_die( esc_html__( 'This certificate is outside your issuing organizations.', 'pressprimer-certificate' ) );
		}

		if ( ! $certificate || ! is_array( $certificate->layout_snapshot ) ) {
			wp_die( esc_html__( 'Certificate not found.', 'pressprimer-certificate' ), 404 );
		}

		// An addon render variant (2.1, Feature 2.1-004 FR-005); the
		// nonce above covers the certificate, the variant only picks a
		// presentation of it.
		$variant = isset( $_GET['variant'] ) ? sanitize_key( wp_unslash( $_GET['variant'] ) ) : '';

		$renderer = new PressPrimer_Certificate_PDF_Renderer();
		$pdf_path = $renderer->render_pdf(
			$certificate->layout_snapshot,
			is_array( $certificate->merge_data ) ? $certificate->merge_data : [],
			[
				'context'        => 'download',
				// The certificate and its recipient (2.1, Feature 2.1-007
				// FR-003): ppcert_pdf_generated gets the real id and the
				// metadata matches the emailed copy.
				'certificate_id' => (int) $certificate->id,
				'recipient_name' => PressPrimer_Certificate_Certificate::recipient_name( $certificate ),
				'credential_id'  => (string) $certificate->credential_id,
				// PDF metadata title = the certificate's own name (1.1-006).
				'title'          => PressPrimer_Certificate_Certificate::display_title( $certificate ),
				'variant'        => $variant,
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

		$filename = PressPrimer_Certificate_Certificate::pdf_filename( $certificate, $renderer->get_last_variant() );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) filesize( $pdf_path ) );

		readfile( $pdf_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a locally rendered temp file.
		wp_delete_file( $pdf_path );

		exit;
	}
}
