<?php
/**
 * Premium touchpoint registry
 *
 * The free plugin's default in-context upsell touchpoints (2.0, Feature
 * 2.0-005) and the one place that decides who sees them. Each touchpoint
 * is one sentence plus one link, rendered where the premium feature
 * mounts once its addon is active, and every one of them is subject to
 * the double gate the Assignment plugin established (its 2.2 touchpoint
 * registry is the reference implementation): the providing tier is
 * inactive AND the current user is an administrator (`manage_options`).
 * Template editors and issuers on a free site can neither buy an
 * upgrade nor reach the locked feature, so they receive no marketing.
 *
 * The gate is resolved server-side: each React surface receives only
 * the touchpoints the current user may see, in its localized boot data,
 * so no client code ever decides visibility.
 *
 * Defaults register on the `ppcert_upsell_touchpoints` filter at
 * priority 5, the same shape as the tier registry, so integrations can
 * add or adjust entries; the addon manager removes active tiers after
 * the filter runs.
 *
 * @package PressPrimer_Certificate
 * @subpackage Admin
 * @since 2.0.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Touchpoint registry class
 *
 * @since 2.0.0
 */
class PressPrimer_Certificate_Touchpoints {

	/**
	 * Surface: the Settings page (boot key `touchpoints` in ppcert_settings_data).
	 *
	 * @var string
	 */
	const SURFACE_SETTINGS = 'settings';

	/**
	 * Surface: the template designer (boot key `touchpoints` in ppcert_designer_data).
	 *
	 * @var string
	 */
	const SURFACE_DESIGNER = 'designer';

	/**
	 * Hook the default entries
	 *
	 * @since 2.0.0
	 */
	public function init() {
		add_filter( 'ppcert_upsell_touchpoints', [ __CLASS__, 'register_default_touchpoints' ], 5 );
	}

	/**
	 * Register the free plugin's touchpoints (FR-001 / FR-002)
	 *
	 * Every entry declares:
	 * - surface:  Which admin surface carries it ('settings' or
	 *             'designer'); matches the boot payload it ships in.
	 * - location: Slot within the surface. The React app renders the
	 *             touchpoint at the slot with this name, which is where
	 *             the real addon UI mounts once the tier is active.
	 * - tier:     The tier that provides the feature.
	 * - copy:     The one-sentence prompt.
	 * - label:    Optional. A short feature name for slots that need one
	 *             (the locked designer tab).
	 *
	 * Link text and URL are derived from the tier registry so tier copy
	 * stays written once (Feature 2.0-004).
	 *
	 * @since 2.0.0
	 *
	 * @param array $touchpoints Touchpoints registered so far.
	 * @return array Touchpoints with the defaults merged in.
	 */
	public static function register_default_touchpoints( $touchpoints ) {
		$touchpoints = is_array( $touchpoints ) ? $touchpoints : [];

		$defaults = [
			// Settings > Email, at the slot where Educator's reminder
			// email section mounts.
			'expiry-reminder-email'    => [
				'surface'  => self::SURFACE_SETTINGS,
				'location' => 'email-tab',
				'tier'     => 'educator',
				'copy'     => __( 'Remind recipients before their certificate expires, with an editable reminder email and a test send.', 'pressprimer-certificate' ),
			],
			// Designer canvas, at the slot where Educator's page rail
			// mounts.
			'multi-page'               => [
				'surface'  => self::SURFACE_DESIGNER,
				'location' => 'canvas-rail',
				'tier'     => 'educator',
				'copy'     => __( 'Design certificates with more than one page, each rendered in the PDF.', 'pressprimer-certificate' ),
			],
			// Designer Award tab, at the slot where Educator's reminder
			// schedule section mounts.
			'expiry-reminder-schedule' => [
				'surface'  => self::SURFACE_DESIGNER,
				'location' => 'award-tab',
				'tier'     => 'educator',
				'copy'     => __( 'Remind recipients before certificates from this template expire, on a schedule you set here.', 'pressprimer-certificate' ),
			],
			// Designer sidebar, as a locked tab where School's
			// Organization tab appears.
			'organization'             => [
				'surface'  => self::SURFACE_DESIGNER,
				'location' => 'sidebar-tab',
				'tier'     => 'school',
				'label'    => __( 'Organization', 'pressprimer-certificate' ),
				'copy'     => __( 'Assign this template to an issuing organization, publish its program page, and copy award emails to staff.', 'pressprimer-certificate' ),
			],
		];

		// Earlier registrants win per key (the tier-registry merge rule).
		return array_replace( $defaults, $touchpoints );
	}

	/**
	 * The touchpoints the current user may see on a surface
	 *
	 * Applies the double gate server-side, uniformly, for every entry:
	 *
	 * 1. The providing tier must be inactive (the addon manager removes
	 *    active tiers; an active tier replaces the prompt with the real
	 *    feature UI).
	 * 2. The current user must be an administrator (`manage_options`).
	 *
	 * Returns a map of location slot => payload, ready to localize. For
	 * non-admins the map is always empty, so the boot data a template
	 * editor receives contains no marketing at all.
	 *
	 * @since 2.0.0
	 *
	 * @param string $surface Surface slug ('settings' or 'designer').
	 * @return array<string, array<string, string>> Map of location => payload
	 *                                              (key, copy, linkText, url, optional label).
	 */
	public static function get_eligible_for_surface( $surface ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return [];
		}

		$manager = function_exists( 'ppcert_addon_manager' ) ? ppcert_addon_manager() : null;

		if ( ! $manager ) {
			return [];
		}

		$surface  = sanitize_key( (string) $surface );
		$tiers    = $manager->get_upgrade_tiers();
		$eligible = [];

		foreach ( $manager->get_upsell_touchpoints() as $id => $touchpoint ) {
			if ( ! isset( $touchpoint['surface'], $touchpoint['location'], $touchpoint['tier'], $touchpoint['copy'] ) ) {
				continue;
			}

			if ( $surface !== sanitize_key( (string) $touchpoint['surface'] ) ) {
				continue;
			}

			$location = sanitize_key( (string) $touchpoint['location'] );
			$copy     = trim( (string) $touchpoint['copy'] );

			if ( '' === $location || '' === $copy ) {
				continue;
			}

			$tier_id   = sanitize_key( (string) $touchpoint['tier'] );
			$tier      = isset( $tiers[ $tier_id ] ) && is_array( $tiers[ $tier_id ] ) ? $tiers[ $tier_id ] : [];
			$tier_name = isset( $tier['name'] ) && '' !== (string) $tier['name'] ? (string) $tier['name'] : ucfirst( $tier_id );
			$tier_url  = isset( $tier['url'] ) && '' !== (string) $tier['url'] ? (string) $tier['url'] : PressPrimer_Certificate_Upgrade_Page::PRICING_URL;

			$payload = [
				'key'      => (string) $id,
				'copy'     => $copy,
				'linkText' => sprintf(
					/* translators: %s: premium tier name (Educator, School, or Enterprise). */
					__( 'Upgrade to %s', 'pressprimer-certificate' ),
					$tier_name
				),
				'url'      => PressPrimer_Certificate_Upgrade_Page::utm_url( $tier_url, (string) $id, 'touchpoint' ),
			];

			if ( isset( $touchpoint['label'] ) && '' !== trim( (string) $touchpoint['label'] ) ) {
				$payload['label'] = trim( (string) $touchpoint['label'] );
			}

			$eligible[ $location ] = $payload;
		}

		return $eligible;
	}
}
