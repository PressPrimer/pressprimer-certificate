<?php
/**
 * Settings admin page
 *
 * The React settings page (Feature 008 FR-004) following the
 * Quiz/Assignment settings-panel pattern: vertical tabs, batch save
 * through /ppcert/v1/settings, Status diagnostics with the repair
 * button, and the Advanced danger zone.
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
 * Settings admin class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Admin_Settings {

	/**
	 * The screen's hook suffix
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $hook = '';

	/**
	 * Initialize: menu, assets, repair AJAX
	 *
	 * @since 1.0.0
	 */
	public function init() {
		// After Certificates (20) so Settings lands last in the submenu.
		add_action( 'admin_menu', [ $this, 'register_menu' ], 30 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_ppcert_repair_database_tables', [ $this, 'ajax_repair_tables' ] );
	}

	/**
	 * Register the Settings submenu
	 *
	 * @since 1.0.0
	 */
	public function register_menu() {
		$this->hook = add_submenu_page(
			'pressprimer-certificate',
			__( 'Settings', 'pressprimer-certificate' ),
			__( 'Settings', 'pressprimer-certificate' ),
			PressPrimer_Certificate_Capabilities::CAP_MANAGE_SETTINGS,
			'ppcert-settings',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the React root
	 *
	 * @since 1.0.0
	 */
	public function render_page() {
		echo '<div id="ppcert-settings-root"></div>';
	}

	/**
	 * Enqueue the settings app on this screen only
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( '' === $this->hook || $hook_suffix !== $this->hook ) {
			return;
		}

		// Media library for the signature/logo pickers.
		wp_enqueue_media();

		// The email body's rich-text editor (2.1, Feature 2.1-001 FR-002)
		// and the shared components for addon sections mounted here
		// (Educator's reminder email).
		wp_enqueue_editor();
		PressPrimer_Certificate_Admin::register_components();
		wp_enqueue_script( 'ppcert-components' );
		wp_enqueue_style( 'ppcert-components' );

		// Global WP-admin / Ant Design conflict overrides.
		wp_enqueue_style(
			'ppcert-admin',
			PPCERT_PLUGIN_URL . 'assets/css/ppcert-admin.css',
			[],
			PPCERT_VERSION
		);

		$asset_file = PPCERT_PLUGIN_DIR . 'build/settings.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'ppcert-settings',
			PPCERT_PLUGIN_URL . 'build/settings.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'ppcert-settings', 'pressprimer-certificate', PPCERT_PLUGIN_DIR . 'languages' );

		// Real webfonts for the Appearance tab's font picker and preview -
		// choosing a face you cannot see is no choice at all.
		wp_add_inline_style(
			'ppcert-admin',
			PressPrimer_Certificate_Admin::build_font_face_css(
				PressPrimer_Certificate_Layout_Validator::get_registered_fonts()
			)
		);

		foreach ( [ 'style-settings.css', 'settings.css' ] as $css ) {
			if ( file_exists( PPCERT_PLUGIN_DIR . 'build/' . $css ) ) {
				wp_enqueue_style(
					'ppcert-settings-' . $css,
					PPCERT_PLUGIN_URL . 'build/' . $css,
					[],
					$asset['version']
				);
			}
		}

		wp_localize_script( 'ppcert-settings', 'ppcert_settings_data', $this->settings_data() );
	}

	/**
	 * Build the localized settings data
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function settings_data() {
		/**
		 * Filters the settings tabs.
		 *
		 * Premium addons register extra tabs (isAddon => true) here.
		 * See docs/architecture/HOOKS.md.
		 *
		 * @since 1.0.0
		 *
		 * @param array $tabs Map of tab id => [ label, order ].
		 */
		$settings_tabs = apply_filters(
			'ppcert_settings_tabs',
			[
				'general'    => [
					'label' => __( 'General', 'pressprimer-certificate' ),
					'order' => 10,
				],
				'appearance' => [
					'label' => __( 'Appearance', 'pressprimer-certificate' ),
					'order' => 20,
				],
				'email'      => [
					'label' => __( 'Email', 'pressprimer-certificate' ),
					'order' => 30,
				],
				'status'     => [
					'label' => __( 'Status', 'pressprimer-certificate' ),
					'order' => 100,
				],
				'advanced'   => [
					'label' => __( 'Advanced', 'pressprimer-certificate' ),
					'order' => 110,
				],
			]
		);

		/**
		 * Filters the mascot image URL in React admin headers.
		 *
		 * The Enterprise addon replaces or empties it for white-label.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url Mascot image URL.
		 */
		$mascot = apply_filters(
			'ppcert_mascot_url',
			PPCERT_PLUGIN_URL . 'assets/images/construction-mascot.png'
		);

		$email_settings = PressPrimer_Certificate_Email_Service::settings();

		$fonts = [];

		foreach ( PressPrimer_Certificate_Layout_Validator::get_registered_fonts() as $slug => $family ) {
			$fonts[] = [
				'slug'  => (string) $slug,
				'label' => isset( $family['label'] ) ? (string) $family['label'] : (string) $slug,
			];
		}

		// The Appearance size toggle and preview shape (2.1): registry
		// sizes in menu order, with landscape dimensions in points.
		$page_sizes = [];

		foreach ( PressPrimer_Certificate_Page_Sizes::all() as $key => $preset ) {
			$page_sizes[] = [
				'value'  => (string) $key,
				'label'  => (string) $preset['label'],
				'width'  => (int) $preset['landscape'][0],
				'height' => (int) $preset['landscape'][1],
			];
		}

		return [
			'settings'          => PressPrimer_Certificate_REST_Settings_Controller::current_settings(),
			'settingsTabs'      => $settings_tabs,
			'settingsMascot'    => $mascot,
			'pluginUrl'         => PPCERT_PLUGIN_URL,
			'pages'             => $this->page_choices(),
			// Whether the configured page still exists, from the page
			// itself (2.1).
			'verificationPage'  => PressPrimer_Certificate_REST_Settings_Controller::verification_page(
				(int) ( PressPrimer_Certificate_REST_Settings_Controller::current_settings()['verification_page_id'] ?? 0 )
			),
			'fonts'             => $fonts,
			'pageSizes'         => $page_sizes,
			'appearance'        => PressPrimer_Certificate_Appearance_Service::get(),
			// Effective email templates: what actually sends today, so
			// the fields never open empty.
			'emailDefaults'     => [
				'subject' => (string) $email_settings['email_issued_subject'],
				'body'    => (string) $email_settings['email_issued_body'],
			],
			// What an empty From field falls back to, and the test
			// email's prefilled Send to address (2.1).
			'emailPlaceholders' => [
				'siteName'         => (string) get_bloginfo( 'name' ),
				'adminEmail'       => (string) get_bloginfo( 'admin_email' ),
				'currentUserEmail' => (string) wp_get_current_user()->user_email,
			],
			'systemInfo'        => $this->system_info(),
			'integrations'      => $this->integrations_status(),
			'databaseTables'    => self::table_status(),
			'nonces'            => [
				'repairTables' => wp_create_nonce( 'ppcert_repair_tables' ),
			],
			// Upsell touchpoints this user may see on this surface
			// (2.0, Feature 2.0-005): resolved server-side, empty for
			// non-admins and for active tiers.
			'touchpoints'       => class_exists( 'PressPrimer_Certificate_Touchpoints' )
				? PressPrimer_Certificate_Touchpoints::get_eligible_for_surface( PressPrimer_Certificate_Touchpoints::SURFACE_SETTINGS )
				: [],
		];
	}

	/**
	 * The verification page picker's first choices
	 *
	 * The first 50 published pages by title plus the configured page
	 * wherever it sorts (2.1, Feature 2.1-007 FR-005: the picker searches
	 * every page through GET /settings/pages; the 2.0 list stopped at
	 * 200 and a configured page past them read as deleted).
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Fewer first choices; the configured page always included.
	 *
	 * @return array[] `[ id, title ]`.
	 */
	private function page_choices() {
		$choices  = PressPrimer_Certificate_REST_Settings_Controller::page_matches( '', 50 );
		$settings = PressPrimer_Certificate_REST_Settings_Controller::current_settings();
		$current  = PressPrimer_Certificate_REST_Settings_Controller::verification_page( isset( $settings['verification_page_id'] ) ? $settings['verification_page_id'] : 0 );

		if ( $current['id'] > 0 && '' !== $current['title'] && ! in_array( $current['id'], array_column( $choices, 'id' ), true ) ) {
			array_unshift(
				$choices,
				[
					'id'    => $current['id'],
					'title' => $current['title'],
				]
			);
		}

		return $choices;
	}

	/**
	 * System information for the Status tab
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function system_info() {
		global $wpdb;

		$theme  = wp_get_theme();
		$addons = [];

		if ( function_exists( 'ppcert_addon_manager' ) && ppcert_addon_manager() ) {
			foreach ( (array) ppcert_addon_manager()->get_addons() as $id => $addon ) {
				$addons[ $id ] = isset( $addon['version'] ) ? (string) $addon['version'] : '';
			}
		}

		$imagick_pdf = false;

		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			try {
				$imagick_pdf = 0 < count( ( new Imagick() )->queryFormats( 'PDF' ) );
			} catch ( Throwable $e ) {
				$imagick_pdf = false;
			}
		}

		$manifest_families = PressPrimer_Certificate_Layout_Validator::get_registered_fonts();
		// The renderer's own allowlist (Feature 2.0-010): what the admin
		// sees here is exactly what the PDF and preview paths accept.
		$image_mimes = PressPrimer_Certificate_PDF_Renderer::renderable_image_mimes();

		return [
			'pluginVersion'      => PPCERT_VERSION,
			'dbVersion'          => (string) get_option( 'ppcert_db_version', '' ),
			'addonVersions'      => $addons,
			'siteUrl'            => home_url(),
			'wpVersion'          => get_bloginfo( 'version' ),
			'isMultisite'        => is_multisite(),
			'memoryLimit'        => (string) ini_get( 'memory_limit' ),
			'activeTheme'        => $theme ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : '',
			'phpVersion'         => PHP_VERSION,
			'postMaxSize'        => (string) ini_get( 'post_max_size' ),
			'uploadMaxSize'      => (string) ini_get( 'upload_max_filesize' ),
			'maxExecutionTime'   => (string) ini_get( 'max_execution_time' ),
			'mysqlVersion'       => method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '',
			'renderCapabilities' => [
				'gd'          => extension_loaded( 'gd' ),
				'imagick'     => extension_loaded( 'imagick' ),
				'imagick_pdf' => $imagick_pdf,
				'webp'        => in_array( 'image/webp', $image_mimes, true ),
				'avif'        => in_array( 'image/avif', $image_mimes, true ),
				'fonts'       => count( $manifest_families ),
			],
			'statistics'         => $this->statistics(),
			'activePlugins'      => $this->active_plugins(),
		];
	}

	/**
	 * Row counts for the Status statistics section
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function statistics() {
		global $wpdb;

		$counts = [];

		$tables = [
			'templates'    => PressPrimer_Certificate_Template::table(),
			'certificates' => PressPrimer_Certificate_Certificate::table(),
			'events'       => PressPrimer_Certificate_Certificate::events_table(),
		];

		foreach ( $tables as $key => $table ) {
			$counts[ $key ] = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table )
			);
		}

		return $counts;
	}

	/**
	 * Active plugin names for diagnostics
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	private function active_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', [] );
		$names  = [];

		foreach ( $active as $file ) {
			if ( isset( $all[ $file ]['Name'] ) ) {
				$version = isset( $all[ $file ]['Version'] ) ? ' ' . $all[ $file ]['Version'] : '';
				$names[] = $all[ $file ]['Name'] . $version;
			}
		}

		return $names;
	}

	/**
	 * Detection status for the six certificate sources
	 *
	 * Reuses the adapters' own is_available() so this section always
	 * agrees with trigger availability.
	 *
	 * @since 1.0.0
	 *
	 * @return array[] [ label, active, version ] rows.
	 */
	private function integrations_status() {
		$versions = [
			'PressPrimer Quiz'       => defined( 'PRESSPRIMER_QUIZ_VERSION' ) ? PRESSPRIMER_QUIZ_VERSION : '',
			'PressPrimer Assignment' => defined( 'PRESSPRIMER_ASSIGNMENT_VERSION' ) ? PRESSPRIMER_ASSIGNMENT_VERSION : '',
			'LearnDash'              => defined( 'LEARNDASH_VERSION' ) ? LEARNDASH_VERSION : '',
			'LifterLMS'              => defined( 'LLMS_VERSION' ) ? LLMS_VERSION : '',
			'Tutor LMS'              => defined( 'TUTOR_VERSION' ) ? TUTOR_VERSION : '',
			'LearnPress'             => defined( 'LEARNPRESS_VERSION' ) ? LEARNPRESS_VERSION : '',
		];

		$rows = [];

		foreach ( PressPrimer_Certificate_Plugin::get_adapter_classes() as $adapter_class ) {
			if ( ! class_exists( $adapter_class ) ) {
				continue;
			}

			$adapter = new $adapter_class();
			$label   = $adapter->get_integration_label();

			if ( isset( $rows[ $label ] ) ) {
				continue;
			}

			$rows[ $label ] = [
				'label'   => $label,
				'active'  => $adapter->is_available(),
				'version' => isset( $versions[ $label ] ) ? $versions[ $label ] : '',
			];
		}

		return array_values( $rows );
	}

	/**
	 * Presence + row counts for every plugin table
	 *
	 * @since 1.0.0
	 *
	 * @return array[]
	 */
	public static function table_status() {
		global $wpdb;

		$status = [];

		foreach ( PressPrimer_Certificate_Schema::get_table_names() as $short_name ) {
			$table  = $wpdb->prefix . $short_name;
			$exists = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
			) === $table;

			$status[] = [
				'name'      => $table,
				'exists'    => $exists,
				'row_count' => $exists
					? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) )
					: 0,
			];
		}

		return $status;
	}

	/**
	 * AJAX: recreate missing tables (Status repair button)
	 *
	 * @since 1.0.0
	 */
	public function ajax_repair_tables() {
		check_ajax_referer( 'ppcert_repair_tables', 'nonce' );

		if ( ! current_user_can( PressPrimer_Certificate_Capabilities::CAP_MANAGE_SETTINGS ) ) {
			wp_send_json_error(
				[ 'message' => __( 'You are not allowed to repair tables.', 'pressprimer-certificate' ) ],
				403
			);
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( PressPrimer_Certificate_Schema::get_schema() );

		wp_send_json_success(
			[
				'message'     => __( 'Database tables repaired.', 'pressprimer-certificate' ),
				'tableStatus' => self::table_status(),
			]
		);
	}
}
