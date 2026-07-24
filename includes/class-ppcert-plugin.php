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

		// Certificates screen: issued list + manual issuance (FR-003).
		if ( class_exists( 'PressPrimer_Certificate_Admin_Certificates' ) ) {
			$certificates_admin = new PressPrimer_Certificate_Admin_Certificates();
			$certificates_admin->init();
		}

		// Settings screen (Feature 008 FR-004).
		if ( class_exists( 'PressPrimer_Certificate_Admin_Settings' ) ) {
			$settings_admin = new PressPrimer_Certificate_Admin_Settings();
			$settings_admin->init();
		}

		// Earned certificates on the user-edit screen (Phase 5B item 9).
		if ( class_exists( 'PressPrimer_Certificate_Admin_User_Profile' ) ) {
			$user_profile = new PressPrimer_Certificate_Admin_User_Profile();
			$user_profile->init();
		}

		// The guided setup tour (Phase 5B item 2).
		if ( class_exists( 'PressPrimer_Certificate_Onboarding' ) ) {
			PressPrimer_Certificate_Onboarding::get_instance();
		}
	}

	/**
	 * Initialize frontend components
	 *
	 * Loads public-facing functionality: the certificate view page and
	 * the public verification page. (The recipient wallet is an
	 * Educator 2.0 paid feature - scope decision 2026-07-23.)
	 *
	 * @since 1.0.0
	 */
	private function init_frontend() {
		if ( class_exists( 'PressPrimer_Certificate_Shortcodes' ) ) {
			$shortcodes = new PressPrimer_Certificate_Shortcodes();
			$shortcodes->init();
		}

		// The public verification page: shortcode, assets, admin notice.
		PressPrimer_Certificate_Verification_Page::init();

		// The public certificate view page: /certificate/{credential_id}/.
		PressPrimer_Certificate_View_Page::init();

		// WordPress privacy handlers (personal data export/erase) - the
		// filters run in admin and cron contexts, so this registers
		// unconditionally.
		PressPrimer_Certificate_Privacy::init();
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
				foreach ( self::get_adapter_classes() as $adapter_class ) {
					if ( class_exists( $adapter_class ) ) {
						( new $adapter_class() )->register();
					}
				}
			}
		);
	}

	/**
	 * The bundled adapter classes
	 *
	 * Shared with the triggers REST controller, which asks unregistered
	 * (deactivated-plugin) trigger types for their integration name so
	 * the Award tab can say which plugin must be reactivated.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Class names.
	 */
	public static function get_adapter_classes() {
		return [
			'PressPrimer_Certificate_LearnDash_Adapter',
			'PressPrimer_Certificate_LearnDash_Lesson_Adapter',
			'PressPrimer_Certificate_LearnDash_Quiz_Adapter',
			'PressPrimer_Certificate_LearnDash_Topic_Adapter',
			'PressPrimer_Certificate_LearnPress_Adapter',
			'PressPrimer_Certificate_LearnPress_Quiz_Adapter',
			'PressPrimer_Certificate_LifterLMS_Adapter',
			'PressPrimer_Certificate_LifterLMS_Quiz_Adapter',
			'PressPrimer_Certificate_PPA_Adapter',
			'PressPrimer_Certificate_PPQ_Adapter',
			'PressPrimer_Certificate_TutorLMS_Adapter',
			'PressPrimer_Certificate_TutorLMS_Quiz_Adapter',
		];
	}

	/**
	 * Map integrations to their trigger type ids
	 *
	 * Built from the bundled adapter classes WITHOUT requiring their
	 * source plugins to be active - the templates list must name and
	 * filter by integrations whose plugins are currently deactivated
	 * (their trigger rows still exist).
	 *
	 * @since 1.0.0
	 *
	 * @return array Map of integration label => string[] trigger type ids.
	 */
	public static function get_integration_map() {
		$map = [];

		foreach ( self::get_adapter_classes() as $adapter_class ) {
			if ( ! class_exists( $adapter_class ) ) {
				continue;
			}

			$adapter = new $adapter_class();

			$map[ $adapter->get_integration_label() ][] = $adapter->get_id();
		}

		ksort( $map );

		return $map;
	}

	/**
	 * Per-trigger-type display details (integration + short label)
	 *
	 * Same adapter-class derivation as get_integration_map(): works for
	 * deactivated integrations whose trigger rows still exist.
	 *
	 * @since 1.0.0
	 *
	 * @return array Map of trigger type id => [ integration, short_label ].
	 */
	public static function get_trigger_type_details() {
		$details = [];

		foreach ( self::get_adapter_classes() as $adapter_class ) {
			if ( ! class_exists( $adapter_class ) ) {
				continue;
			}

			$adapter = new $adapter_class();

			$details[ $adapter->get_id() ] = [
				'integration' => $adapter->get_integration_label(),
				'short_label' => $adapter->get_short_label(),
			];
		}

		return $details;
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
			'PressPrimer_Certificate_REST_Settings_Controller',
			'PressPrimer_Certificate_REST_Dashboard_Controller',
			'PressPrimer_Certificate_REST_Email_Optin_Controller',
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
	 * Registers block types for the block editor. (The my-certificates
	 * wallet block moved to Educator 2.0 with the wallet - scope
	 * decision 2026-07-23.)
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
	 * The daily ppcert_prune_events retention cleanup (prunable
	 * verified/viewed rows in wp_ppcert_events per DATABASE.md);
	 * scheduled by the activator, cleared by the deactivator.
	 *
	 * @since 1.0.0
	 */
	private function init_cron() {
		PressPrimer_Certificate_Event_Pruner::init();
	}
}
