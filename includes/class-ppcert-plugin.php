<?php
/**
 * Main plugin class
 *
 * Coordinates the plugin initialization and component setup.
 *
 * @package PressPrimer_Certificate
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class
 *
 * Implements singleton pattern to ensure only one instance exists.
 * Initializes all plugin components on init hook (priority 0).
 *
 * Components are wired here as their classes ship; every init call is
 * guarded by class_exists() so the plugin activates and runs cleanly at
 * any stage of the 1.0 build.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Plugin {

	/**
	 * Singleton instance
	 *
	 * @since 1.0.0
	 * @var PressPrimer_Certificate_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * Returns the single instance of the plugin class.
	 * Creates the instance if it doesn't exist.
	 *
	 * Named instance() per 008-foundation FR-001.
	 *
	 * @since 1.0.0
	 *
	 * @return PressPrimer_Certificate_Plugin The plugin instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor
	 *
	 * Prevents direct instantiation. Use instance() instead.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Constructor is private for singleton
	}

	/**
	 * Initialize the plugin
	 *
	 * Initializes all plugin components in the correct order, then fires
	 * `ppcert_loaded` (008-foundation FR-001). Called from ppcert_init().
	 *
	 * @since 1.0.0
	 */
	public function init() {
		// Ensure capabilities are set up (handles cases where activation hook didn't run,
		// such as WordPress Playground or manual file installations)
		$this->ensure_capabilities();

		// Check and run migrations
		if ( class_exists( 'PressPrimer_Certificate_Migrator' ) ) {
			PressPrimer_Certificate_Migrator::maybe_migrate();
		}

		// Initialize addon manager (allows premium addons to register)
		$this->init_addon_manager();

		// Initialize components
		$this->init_admin();
		$this->init_frontend();
		$this->init_integrations();
		$this->init_rest_api();
		$this->init_blocks();
		$this->init_cron();

		/**
		 * Fires when the free plugin is fully loaded.
		 *
		 * Premium addons hook in here to initialize themselves and register
		 * via the `ppcert_register_addon` action. See docs/architecture/HOOKS.md.
		 *
		 * @since 1.0.0
		 */
		do_action( 'ppcert_loaded' );
	}

	/**
	 * Initialize addon manager
	 *
	 * Sets up the addon manager so premium addons (Educator, School,
	 * Enterprise) can register themselves via the `ppcert_register_addon`
	 * action. Ships with the Foundation feature (SCOPE.md Feature 8).
	 *
	 * @since 1.0.0
	 */
	private function init_addon_manager() {
		if ( class_exists( 'PressPrimer_Certificate_Addon_Manager' ) ) {
			$addon_manager = PressPrimer_Certificate_Addon_Manager::get_instance();
			$addon_manager->init();
		}
	}

	/**
	 * Ensure capabilities are set up
	 *
	 * Checks if plugin capabilities exist and sets them up if missing.
	 * This handles cases where the activation hook didn't run properly,
	 * such as in WordPress Playground or manual file installations.
	 *
	 * @since 1.0.0
	 */
	private function ensure_capabilities() {
		// Check if admin role has our capabilities
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( 'ppcert_manage_templates' ) ) {
			// Capabilities missing, set them up
			if ( class_exists( 'PressPrimer_Certificate_Capabilities' ) ) {
				PressPrimer_Certificate_Capabilities::setup_capabilities();
			}
		}
	}

	/**
	 * Initialize admin components
	 *
	 * Loads admin-only functionality when in wp-admin.
	 *
	 * @since 1.0.0
	 */
	private function init_admin() {
		if ( ! is_admin() ) {
			return;
		}

		// Initialize admin class (menu registration, asset loading)
		if ( class_exists( 'PressPrimer_Certificate_Admin' ) ) {
			$admin = new PressPrimer_Certificate_Admin();
			$admin->init();
		}
	}

	/**
	 * Initialize frontend components
	 *
	 * Loads public-facing functionality: shortcodes, the recipient wallet,
	 * and the public verification page.
	 *
	 * @since 1.0.0
	 */
	private function init_frontend() {
		// Initialize shortcodes (the ppcert_my_certificates wallet shortcode)
		if ( class_exists( 'PressPrimer_Certificate_Shortcodes' ) ) {
			$shortcodes = new PressPrimer_Certificate_Shortcodes();
			$shortcodes->init();
		}

		// The public verification page: shortcode, assets, admin notice.
		PressPrimer_Certificate_Verification_Page::init();

		// WordPress privacy handlers (personal data export/erase) are
		// wired here when Feature 8 (Foundation, Prompt 4.6) ships.
	}

	/**
	 * Initialize assessment and LMS integrations
	 *
	 * Adapters (PressPrimer Quiz, PressPrimer Assignment, LearnDash,
	 * LifterLMS, Tutor LMS, LearnPress) self-register through the
	 * `ppcert_register_trigger_types` and `ppcert_register_merge_fields`
	 * filters — core has no adapter-specific branches. Each adapter
	 * activates only when its source plugin is detected via
	 * PressPrimer_Certificate_LMS_Adapter::is_available().
	 *
	 * Wired here when Feature 4 (Assessment + LMS Adapters) ships; the
	 * adapter interface is locked before the first concrete adapter.
	 *
	 * @since 1.0.0
	 */
	private function init_integrations() {
		// Bundled adapters instantiate on ppcert_loaded (Feature 004
		// FR-002); each register() call no-ops unless its source plugin
		// is detected, so this list is inert on sites without them.
		add_action(
			'ppcert_loaded',
			static function () {
				$adapters = [
					'PressPrimer_Certificate_LearnDash_Adapter',
					'PressPrimer_Certificate_LearnDash_Lesson_Adapter',
					'PressPrimer_Certificate_LearnDash_Quiz_Adapter',
					'PressPrimer_Certificate_LearnDash_Topic_Adapter',
					'PressPrimer_Certificate_LifterLMS_Adapter',
					'PressPrimer_Certificate_PPA_Adapter',
					'PressPrimer_Certificate_PPQ_Adapter',
				];

				foreach ( $adapters as $adapter_class ) {
					if ( class_exists( $adapter_class ) ) {
						( new $adapter_class() )->register();
					}
				}
			}
		);
	}

	/**
	 * Initialize REST API
	 *
	 * Registers REST API controllers under the ppcert/v1 namespace.
	 * Controllers are instantiated as they ship; the verification
	 * controller is public, unauthenticated, and rate-limited — the
	 * plugin's highest-exposure surface.
	 *
	 * @since 1.0.0
	 */
	private function init_rest_api() {
		$controllers = [
			'PressPrimer_Certificate_REST_Templates_Controller',
			'PressPrimer_Certificate_REST_Certificates_Controller',
			'PressPrimer_Certificate_REST_Merge_Fields_Controller',
			'PressPrimer_Certificate_REST_Triggers_Controller',
			'PressPrimer_Certificate_REST_Verification_Controller',
		];

		foreach ( $controllers as $controller_class ) {
			if ( class_exists( $controller_class ) ) {
				$controller = new $controller_class();
				$controller->init();
			}
		}
	}

	/**
	 * Initialize Gutenberg blocks
	 *
	 * Registers block types for the block editor. The my-certificates
	 * wallet block (mirroring the [ppcert_my_certificates] shortcode)
	 * ships with Feature 5 (Recipient Wallet).
	 *
	 * @since 1.0.0
	 */
	private function init_blocks() {
		if ( class_exists( 'PressPrimer_Certificate_Blocks' ) ) {
			$blocks = new PressPrimer_Certificate_Blocks();
			$blocks->init();
		}
	}

	/**
	 * Initialize cron jobs
	 *
	 * Registers scheduled tasks. The events retention cleanup (prunable
	 * verified/viewed rows in wp_ppcert_events per DATABASE.md) is
	 * registered here when the Issuance Engine ships.
	 *
	 * @since 1.0.0
	 */
	private function init_cron() {
		// Intentionally empty until the events table cleanup lands.
	}
}
