<?php
/**
 * Admin controller
 *
 * Menu registration, admin page routing, and designer asset loading.
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
 * Admin class
 *
 * The top-level menu is labeled "Certificates" at position 32 - directly
 * beneath PressPrimer Quiz (30) and Assignments (31) whenever they are
 * active (CONVENTIONS.md Terminology exception, 2026-07-21). Page titles
 * and in-page copy keep the PPCert/PressPrimer terminology.
 *
 * The Templates page routes between the WP_List_Table (list view) and
 * the React designer mount (?action=new opens the gallery; ?action=edit
 * with a template loads it), per the ecosystem list + React-detail
 * pattern.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Admin {

	/**
	 * The Templates page hook suffix (asset targeting)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $templates_hook = '';

	/**
	 * Initialize
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'admin_menu', [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init', [ $this, 'handle_trash_action' ] );
	}

	/**
	 * Handle the list table's Trash row action
	 *
	 * Nonce-verified, capability-gated soft delete, then a redirect back
	 * to the list with a notice flag.
	 *
	 * @since 1.0.0
	 */
	public function handle_trash_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; the nonce verifies below before any action.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing check only; the nonce verifies below before any action.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		if ( 'pressprimer-certificate' !== $page || 'trash' !== $action ) {
			return;
		}

		if ( ! current_user_can( PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES ) ) {
			wp_die( esc_html__( 'You are not allowed to trash certificate templates.', 'pressprimer-certificate' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified by check_admin_referer immediately below.
		$template_id = isset( $_GET['template_id'] ) ? absint( wp_unslash( $_GET['template_id'] ) ) : 0;

		check_admin_referer( 'ppcert_trash_template_' . $template_id );

		$result  = PressPrimer_Certificate_Template::trash( $template_id );
		$trashed = ! is_wp_error( $result ) ? 1 : 0;

		wp_safe_redirect(
			add_query_arg(
				[
					'page'           => 'pressprimer-certificate',
					'ppcert_trashed' => $trashed,
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Register the admin menu
	 *
	 * @since 1.0.0
	 */
	public function register_menus() {
		/**
		 * Filters the plugin name displayed in the admin menu.
		 *
		 * Used by the Enterprise addon for white-label branding (sibling
		 * parity with pressprimer_quiz_plugin_name).
		 *
		 * @since 1.0.0
		 *
		 * @param string $name Default menu label.
		 */
		$menu_label = apply_filters( 'ppcert_plugin_name', __( 'Certificates', 'pressprimer-certificate' ) );

		add_menu_page(
			$menu_label,
			$menu_label,
			PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES,
			'pressprimer-certificate',
			[ $this, 'render_templates_page' ],
			$this->get_menu_icon(),
			32 // Beneath PressPrimer Quiz (30) and Assignments (31).
		);

		$this->templates_hook = add_submenu_page(
			'pressprimer-certificate',
			__( 'PPCert Templates', 'pressprimer-certificate' ),
			__( 'Templates', 'pressprimer-certificate' ),
			PressPrimer_Certificate_Capabilities::CAP_MANAGE_TEMPLATES,
			'pressprimer-certificate',
			[ $this, 'render_templates_page' ]
		);

		/**
		 * Fires after the core admin menu items are registered.
		 *
		 * Premium addons append their submenus here.
		 *
		 * @since 1.0.0
		 *
		 * @param string $parent_slug The top-level menu slug.
		 */
		do_action( 'ppcert_admin_menu_registered', 'pressprimer-certificate' );
	}

	/**
	 * Render the Templates page: list view or designer mount
	 *
	 * @since 1.0.0
	 */
	public function render_templates_page() {
		if ( '' !== $this->current_designer_action() ) {
			echo '<div class="wrap ppcert-designer-wrap"><div id="ppcert-designer-root"></div></div>';
			return;
		}

		$list_table = new PressPrimer_Certificate_Templates_List_Table();
		$list_table->prepare_items();

		$add_new_url = add_query_arg(
			[
				'page'   => 'pressprimer-certificate',
				'action' => 'new',
			],
			admin_url( 'admin.php' )
		);

		echo '<div class="wrap">';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag set by the nonce-verified trash handler.
		if ( isset( $_GET['ppcert_trashed'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
			$trashed = absint( wp_unslash( $_GET['ppcert_trashed'] ) );

			echo '<div class="notice notice-' . ( $trashed ? 'success' : 'error' ) . ' is-dismissible"><p>';
			echo $trashed
				? esc_html__( 'Template moved to the trash.', 'pressprimer-certificate' )
				: esc_html__( 'The template could not be trashed.', 'pressprimer-certificate' );
			echo '</p></div>';
		}

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'PPCert Templates', 'pressprimer-certificate' ) . '</h1> ';
		echo '<a href="' . esc_url( $add_new_url ) . '" class="page-title-action">' . esc_html__( 'Add New', 'pressprimer-certificate' ) . '</a>';
		echo '<hr class="wp-header-end" />';
		$list_table->display();
		echo '</div>';
	}

	/**
	 * Enqueue the designer app on its screen only
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( $hook_suffix !== $this->templates_hook ) {
			return;
		}

		// Global WP-admin / Ant Design conflict overrides on every plugin
		// admin page (ecosystem pattern - see the Quiz/Assignment
		// admin.css twins).
		wp_enqueue_style(
			'ppcert-admin',
			PPCERT_PLUGIN_URL . 'assets/css/ppcert-admin.css',
			[],
			PPCERT_VERSION
		);

		if ( '' === $this->current_designer_action() ) {
			return;
		}

		$asset_file = PPCERT_PLUGIN_DIR . 'build/designer.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'ppcert-designer',
			PPCERT_PLUGIN_URL . 'build/designer.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'ppcert-designer', 'pressprimer-certificate', PPCERT_PLUGIN_DIR . 'languages' );

		// The image/signature/background pickers use the WP media modal
		// (attachment IDs only - never raw file handling).
		wp_enqueue_media();

		$fonts = PressPrimer_Certificate_Layout_Validator::get_registered_fonts();

		if ( file_exists( PPCERT_PLUGIN_DIR . 'build/style-designer.css' ) ) {
			wp_enqueue_style(
				'ppcert-designer',
				PPCERT_PLUGIN_URL . 'build/style-designer.css',
				[],
				$asset['version']
			);

			// Canvas text must render the real bundled variants - the PDF
			// uses them, and synthetic styling would break parity.
			wp_add_inline_style( 'ppcert-designer', $this->build_font_face_css( $fonts ) );
		}

		// Read-only routing context; capability enforcement happens on
		// every REST route, never from request parameters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$template_id = isset( $_GET['template_id'] ) ? absint( wp_unslash( $_GET['template_id'] ) ) : 0;

		$starters = [];

		foreach ( PressPrimer_Certificate_Template::get_starters() as $starter ) {
			$starters[] = [
				'slug'   => $starter['slug'],
				'label'  => $starter['label'],
				'layout' => $starter['layout'],
			];
		}

		// The canvas paints the PHP encoder's sample matrix - it never
		// encodes QR codes itself (one encoder, ADR-004 / parity).
		$sample_credential = 'SAMPLE000000';

		foreach ( PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' ) as $field_key => $field ) {
			if ( 'certificate.credential_id' === $field_key ) {
				$sample_credential = preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) $field['sample'] ) );
				break;
			}
		}

		$sample_qr = PressPrimer_Certificate_QR_Service::generate( ppcert_verification_url( $sample_credential ) );

		wp_localize_script(
			'ppcert-designer',
			'ppcert_designer_data',
			[
				'template_id'   => $template_id,
				'list_url'      => add_query_arg( 'page', 'pressprimer-certificate', admin_url( 'admin.php' ) ),
				'fonts'         => $fonts,
				'element_types' => PressPrimer_Certificate_Element_Types::get_types(),
				'fitting'       => PressPrimer_Certificate_PDF_Renderer::fitting_thresholds(),
				'sample_qr'     => is_wp_error( $sample_qr ) ? null : $sample_qr,
				'starters'      => $starters,
				'page_presets'  => [
					'a4'     => [
						'landscape' => [ 842, 595 ],
						'portrait'  => [ 595, 842 ],
					],
					'letter' => [
						'landscape' => [ 792, 612 ],
						'portrait'  => [ 612, 792 ],
					],
				],
			]
		);
	}

	/**
	 * Build @font-face CSS for the registered fonts
	 *
	 * One rule per bundled variant so the canvas uses the same real
	 * bold/italic files as the PDF renderer. Values come from the
	 * validated manifest / font filter, sanitized per rule: family names
	 * via sanitize_key(), URLs via esc_url().
	 *
	 * @since 1.0.0
	 *
	 * @param array $fonts Map of font slug => family definition.
	 * @return string CSS.
	 */
	private function build_font_face_css( array $fonts ) {
		$variant_face = [
			'regular'     => [ 400, 'normal' ],
			'bold'        => [ 700, 'normal' ],
			'italic'      => [ 400, 'italic' ],
			'bold_italic' => [ 700, 'italic' ],
		];

		$css = '';

		foreach ( $fonts as $slug => $family ) {
			if ( empty( $family['variants'] ) || ! is_array( $family['variants'] ) ) {
				continue;
			}

			foreach ( $variant_face as $variant => $face ) {
				if ( empty( $family['variants'][ $variant ] ) ) {
					continue;
				}

				$def = $family['variants'][ $variant ];

				if ( ! empty( $def['url'] ) ) {
					// Filter-registered fonts (Educator custom fonts)
					// provide their own absolute URL.
					$url = esc_url( $def['url'] );
				} elseif ( ! empty( $def['ttf'] ) ) {
					$url = esc_url( PPCERT_PLUGIN_URL . 'fonts/' . ltrim( (string) $def['ttf'], '/' ) );
				} else {
					continue;
				}

				$css .= sprintf(
					'@font-face{font-family:"%1$s";src:url("%2$s") format("truetype");font-weight:%3$d;font-style:%4$s;font-display:block;}',
					sanitize_key( $slug ),
					$url,
					(int) $face[0],
					'italic' === $face[1] ? 'italic' : 'normal'
				);
			}
		}

		return $css;
	}

	/**
	 * The current designer action ('new', 'edit', or '' for the list)
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function current_designer_action() {
		// Read-only view routing - no state changes from this parameter.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';

		return in_array( $action, [ 'new', 'edit' ], true ) ? $action : '';
	}

	/**
	 * The menu icon (inline SVG data URI, sibling pattern)
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function get_menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#a7aaad">'
			. '<path d="M4 4h16a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-6.5l-1.5 3-1.5-3H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zm1 4v1.5h14V8H5zm0 3.5V13h9v-1.5H5z"/>'
			. '<path d="M15.75 14.5a3.25 3.25 0 1 1 0 6.5 3.25 3.25 0 0 1 0-6.5zm0 1.5a1.75 1.75 0 1 0 0 3.5 1.75 1.75 0 0 0 0-3.5z"/>'
			. '</svg>';

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required for the data URI in add_menu_page() (sibling pattern).
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}
}
