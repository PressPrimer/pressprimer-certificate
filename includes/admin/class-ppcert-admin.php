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
		if ( $hook_suffix !== $this->templates_hook || '' === $this->current_designer_action() ) {
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

		if ( file_exists( PPCERT_PLUGIN_DIR . 'build/style-designer.css' ) ) {
			wp_enqueue_style(
				'ppcert-designer',
				PPCERT_PLUGIN_URL . 'build/style-designer.css',
				[],
				$asset['version']
			);
		}

		// Read-only routing context; capability enforcement happens on
		// every REST route, never from request parameters.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$template_id = isset( $_GET['template_id'] ) ? absint( wp_unslash( $_GET['template_id'] ) ) : 0;

		$manifest_path = PPCERT_PLUGIN_DIR . 'fonts/manifest.json';
		$font_manifest = [];

		if ( is_readable( $manifest_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file.
			$decoded = json_decode( (string) file_get_contents( $manifest_path ), true );

			if ( is_array( $decoded ) ) {
				$font_manifest = $decoded;
			}
		}

		$starters = [];

		foreach ( PressPrimer_Certificate_Template::get_starters() as $starter ) {
			$starters[] = [
				'slug'   => $starter['slug'],
				'label'  => $starter['label'],
				'layout' => $starter['layout'],
			];
		}

		wp_localize_script(
			'ppcert-designer',
			'ppcert_designer_data',
			[
				'template_id'   => $template_id,
				'list_url'      => add_query_arg( 'page', 'pressprimer-certificate', admin_url( 'admin.php' ) ),
				'font_manifest' => $font_manifest,
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
