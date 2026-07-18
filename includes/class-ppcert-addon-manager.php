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
	 * Each entry: [ 'id' => string, 'version' => string, 'features' => string[] ].
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $addons = [];

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
		add_action( 'ppcert_register_addon', [ $this, 'register_addon' ], 10, 3 );
	}

	/**
	 * Action callback: register an addon
	 *
	 * Signature per HOOKS.md ppcert_register_addon. First registration of
	 * an id wins; malformed registrations are ignored.
	 *
	 * @since 1.0.0
	 *
	 * @param string $addon_id Addon identifier ('educator', 'school', 'enterprise').
	 * @param string $version  Addon version number.
	 * @param array  $features Feature slugs this addon provides.
	 */
	public function register_addon( $addon_id, $version = '', $features = [] ) {
		$addon_id = sanitize_key( (string) $addon_id );

		if ( '' === $addon_id || isset( $this->addons[ $addon_id ] ) ) {
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
		];
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
