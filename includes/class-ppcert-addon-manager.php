<?php
/**
 * Addon manager
 *
 * Central registry for premium addon registration and feature detection.
 *
 * @package PressPrimer_Certificate
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Addon manager class
 *
 * Mirrors the Quiz addon manager (the reference implementation) adapted
 * to Certificate's registration contract: addons initialize on
 * `ppcert_loaded` and fire the `ppcert_register_addon` action with
 * ( $addon_id, $version, $features ) - see docs/architecture/HOOKS.md.
 * The manager listens for that action; the companion globals
 * ppcert_has_addon() and ppcert_feature_enabled() (bootstrap) read from
 * it. The addon list surfaces on the settings Status section (Prompt 5.2).
 *
 * Core never checks addon capabilities and contains no addon-specific
 * branches; feature flags are the only coupling surface.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Addon_Manager {

	/**
	 * Singleton instance
	 *
	 * @since 1.0.0
	 * @var PressPrimer_Certificate_Addon_Manager|null
	 */
	private static $instance = null;

	/**
	 * Registered addons, keyed by addon id
	 *
	 * Each entry: [ 'id' => string, 'version' => string, 'features' =>
	 * string[], 'tier' => string, 'name' => string ].
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $addons = [];

	/**
	 * Registrations refused for requiring a newer core (2.0, FR-007)
	 *
	 * Keyed by addon id: [ 'name' => string, 'required' => string ].
	 * Rendered as admin error notices so the refusal is never silent.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private $refused = [];

	/**
	 * Get singleton instance
	 *
	 * @since 1.0.0
	 *
	 * @return PressPrimer_Certificate_Addon_Manager The addon manager instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor
	 *
	 * Prevents direct instantiation. Use get_instance() instead.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Constructor is private for singleton
	}

	/**
	 * Initialize the addon manager
	 *
	 * Hooks the registration action. Runs during plugin init, before
	 * `ppcert_loaded` fires - so addons initializing on ppcert_loaded and
	 * firing ppcert_register_addon are always heard.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		add_action( 'ppcert_register_addon', [ $this, 'register_addon' ], 10, 4 );
		add_action( 'admin_notices', [ $this, 'render_refusal_notices' ] );
	}

	/**
	 * Action callback: register an addon
	 *
	 * Signature per HOOKS.md ppcert_register_addon. First registration of
	 * an id wins; malformed registrations are ignored. Since 2.0 the
	 * optional fourth argument declares:
	 *
	 * - min_core_version: the oldest PPCERT_VERSION the addon supports.
	 *   Below it the registration is REFUSED with an admin notice - the
	 *   addon stays inert instead of running against a core missing its
	 *   extension points (FR-007).
	 * - tier: the commercial tier this addon activates ('educator',
	 *   'school', 'enterprise') - feeds the upgrade page and upsell
	 *   touchpoint registries' active flags. Addons mark tiers active
	 *   ONLY through registration, never by filtering registry copy.
	 * - name: human-readable name for notices ("PressPrimer Certificate
	 *   Educator"); defaults to a readable form of the id.
	 *
	 * @since 1.0.0
	 * @since 2.0.0 Added the $args parameter.
	 *
	 * @param string $addon_id Addon identifier ('educator', 'school', 'enterprise').
	 * @param string $version  Addon version number.
	 * @param array  $features Feature slugs this addon provides.
	 * @param array  $args     Optional. min_core_version, tier, name.
	 */
	public function register_addon( $addon_id, $version = '', $features = [], $args = [] ) {
		$addon_id = sanitize_key( (string) $addon_id );

		if ( '' === $addon_id || isset( $this->addons[ $addon_id ] ) ) {
			return;
		}

		$args = is_array( $args ) ? $args : [];
		$name = isset( $args['name'] ) && is_string( $args['name'] ) && '' !== $args['name']
			? sanitize_text_field( $args['name'] )
			: ucwords( str_replace( [ '_', '-' ], ' ', $addon_id ) );

		// Minimum-core-version gate (2.0, FR-007): refuse with a notice,
		// never register a version pairing the addon does not support.
		$min_core = isset( $args['min_core_version'] ) && is_scalar( $args['min_core_version'] )
			? (string) $args['min_core_version']
			: '';

		if ( '' !== $min_core && defined( 'PPCERT_VERSION' )
			&& version_compare( PPCERT_VERSION, $min_core, '<' ) ) {
			$this->refused[ $addon_id ] = [
				'name'     => $name,
				'required' => $min_core,
			];

			return;
		}

		$clean_features = [];

		foreach ( (array) $features as $feature ) {
			if ( is_string( $feature ) ) {
				$feature = sanitize_key( $feature );

				if ( '' !== $feature && ! in_array( $feature, $clean_features, true ) ) {
					$clean_features[] = $feature;
				}
			}
		}

		$this->addons[ $addon_id ] = [
			'id'       => $addon_id,
			'version'  => is_scalar( $version ) ? (string) $version : '',
			'features' => $clean_features,
			'tier'     => isset( $args['tier'] ) ? sanitize_key( (string) $args['tier'] ) : '',
			'name'     => $name,
		];
	}

	/**
	 * Render an admin error notice per refused registration
	 *
	 * @since 2.0.0
	 */
	public function render_refusal_notices() {
		if ( empty( $this->refused ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		foreach ( $this->refused as $refusal ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: 1: addon name, 2: required core version */
				esc_html__( '%1$s requires PressPrimer Certificate %2$s or newer and has been paused. Please update PressPrimer Certificate.', 'pressprimer-certificate' ),
				esc_html( $refusal['name'] ),
				esc_html( $refusal['required'] )
			);
			echo '</p></div>';
		}
	}

	/**
	 * Registrations refused by the minimum-core-version gate
	 *
	 * @since 2.0.0
	 *
	 * @return array Map of addon id => [ name, required ].
	 */
	public function get_refused() {
		return $this->refused;
	}

	/**
	 * Whether a commercial tier is active (a registered addon declares it)
	 *
	 * @since 2.0.0
	 *
	 * @param string $tier Tier slug ('educator', 'school', 'enterprise').
	 * @return bool
	 */
	public function is_tier_active( $tier ) {
		$tier = sanitize_key( (string) $tier );

		if ( '' === $tier ) {
			return false;
		}

		foreach ( $this->addons as $addon ) {
			if ( $addon['tier'] === $tier ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The upgrade-page tier registry (2.0, Feature 2.0-004 FR-002)
	 *
	 * Tier copy (name, positioning line, feature list, pricing URL) is
	 * defined in free and filterable; the Upgrade page (Prompt 3.1) and
	 * the upsell touchpoints (Prompt 4.1) both consume this registry so
	 * copy is written once. The `active` flag is computed HERE from
	 * registered addons after the filter runs - addons mark themselves
	 * active through registration, never by filtering.
	 *
	 * @since 2.0.0
	 *
	 * @return array Map of tier id => tier definition + `active` bool.
	 */
	public function get_upgrade_tiers() {
		/** This filter is documented in docs/architecture/HOOKS.md */
		$tiers = apply_filters( 'ppcert_upgrade_tiers', [] );
		$tiers = is_array( $tiers ) ? $tiers : [];

		$registry = [];

		foreach ( $tiers as $tier_id => $tier ) {
			$tier_id = sanitize_key( (string) $tier_id );

			if ( '' === $tier_id || ! is_array( $tier ) ) {
				continue;
			}

			$tier['id']           = $tier_id;
			$tier['active']       = $this->is_tier_active( $tier_id );
			$registry[ $tier_id ] = $tier;
		}

		return $registry;
	}

	/**
	 * The upsell touchpoint registry (2.0, Feature 2.0-005 FR-001)
	 *
	 * Entries: id, location, tier, title, benefit line, upgrade link
	 * anchor. Touchpoints whose tier is active are removed HERE, so no
	 * consumer can accidentally advertise to a customer. Free's launch
	 * entries land in Prompt 4.1 (after the premium UIs exist to mirror).
	 *
	 * @since 2.0.0
	 *
	 * @return array Map of touchpoint id => definition (inactive tiers only).
	 */
	public function get_upsell_touchpoints() {
		/** This filter is documented in docs/architecture/HOOKS.md */
		$touchpoints = apply_filters( 'ppcert_upsell_touchpoints', [] );
		$touchpoints = is_array( $touchpoints ) ? $touchpoints : [];

		$registry = [];

		foreach ( $touchpoints as $touchpoint_id => $touchpoint ) {
			$touchpoint_id = sanitize_key( (string) $touchpoint_id );

			if ( '' === $touchpoint_id || ! is_array( $touchpoint ) ) {
				continue;
			}

			$tier = isset( $touchpoint['tier'] ) ? sanitize_key( (string) $touchpoint['tier'] ) : '';

			if ( '' !== $tier && $this->is_tier_active( $tier ) ) {
				continue;
			}

			$touchpoint['id']           = $touchpoint_id;
			$registry[ $touchpoint_id ] = $touchpoint;
		}

		return $registry;
	}

	/**
	 * Whether an addon is registered
	 *
	 * @since 1.0.0
	 *
	 * @param string $addon_id Addon identifier.
	 * @return bool
	 */
	public function has_addon( $addon_id ) {
		return isset( $this->addons[ sanitize_key( (string) $addon_id ) ] );
	}

	/**
	 * Whether any registered addon provides a feature
	 *
	 * @since 1.0.0
	 *
	 * @param string $feature Feature slug.
	 * @return bool
	 */
	public function feature_enabled( $feature ) {
		$feature = sanitize_key( (string) $feature );

		if ( '' === $feature ) {
			return false;
		}

		foreach ( $this->addons as $addon ) {
			if ( in_array( $feature, $addon['features'], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get all registered addons
	 *
	 * Consumed by the settings Status section (Prompt 5.2).
	 *
	 * @since 1.0.0
	 *
	 * @return array Map of addon id => [ id, version, features ].
	 */
	public function get_addons() {
		return $this->addons;
	}

	/**
	 * Reset registered addons
	 *
	 * Test isolation helper; production code never calls this.
	 *
	 * @since 1.0.0
	 */
	public function reset() {
		$this->addons = [];
	}
}
