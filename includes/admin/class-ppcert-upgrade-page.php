<?php
/**
 * Upgrade Page Controller
 *
 * Registers and renders the "Upgrade" submenu presenting the Educator,
 * School, and Enterprise tiers (2.0, Feature 2.0-004). The page skeleton
 * (hero, tier cards, comparison table, footer CTA, sticky bar) is the
 * house pattern ported from PressPrimer Assignment 2.2's upgrade page
 * (class-ppa-upgrade-page.php), which itself ports PressPrimer Quiz 3.0.
 *
 * Certificate divergences from the sibling pattern, per Feature 2.0-004:
 * - Tier copy lives in the filterable ppcert_upgrade_tiers registry
 *   (FR-002) and reaches the page through the addon manager, which
 *   computes each tier's `active` flag from registered addons. Feature
 *   2.0-005's touchpoints consume the same registry, so copy is written
 *   once.
 * - Ownership states (US-2/US-3): an owned tier's card shows an active
 *   state with a documentation link instead of a buy CTA, the featured
 *   emphasis moves to the lowest unowned tier, and with every tier owned
 *   the page renders calm - no CTAs, no sticky bar. The menu item itself
 *   hides at the top tier exactly like the siblings.
 *
 * @package PressPrimer_Certificate
 * @subpackage Admin
 * @since 2.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upgrade page class
 *
 * @since 2.0.0
 */
class PressPrimer_Certificate_Upgrade_Page {

	/**
	 * Menu slug for the Upgrade page.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const MENU_SLUG = 'ppcert-upgrade';

	/**
	 * Pricing page URL on pressprimer.com.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const PRICING_URL = 'https://pressprimer.com/pressprimer-certificate-pricing/';

	/**
	 * Knowledge Base URL for owned-tier documentation links.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const KB_URL = 'https://pressprimer.com/knowledge-base/pressprimer-certificate/';

	/**
	 * Initialize hooks.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function init() {
		// The free plugin's tier copy enters the registry early so other
		// registrants see the defaults (FR-002: copy written once).
		add_filter( 'ppcert_upgrade_tiers', [ __CLASS__, 'register_default_tiers' ], 5 );

		// After Dashboard / Templates / Certificates / Settings (30), but
		// before WP's default 100 - Upgrade lands last (sibling pattern).
		add_action( 'admin_menu', [ $this, 'register_menu' ], 99 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_menu_styles' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_page_styles' ] );
	}

	/**
	 * Register the free plugin's tier definitions (FR-002)
	 *
	 * Positioning lines and feature lists for the three tiers. Content
	 * reflects the shipped 2.0 tier scope; the one roadmap item present
	 * (Educator premium template pack) appears only in the comparison
	 * table, marked as coming. Copy guardrails apply: "free on
	 * WordPress.org", award/send/issue verbs, no prices.
	 *
	 * @since 2.0.0
	 *
	 * @param array $tiers Registered tiers.
	 * @return array
	 */
	public static function register_default_tiers( $tiers ) {
		$tiers = is_array( $tiers ) ? $tiers : [];

		$defaults = [
			'educator'   => [
				'name'        => __( 'Educator', 'pressprimer-certificate' ),
				'tagline'     => __( 'For individual instructors and course creators', 'pressprimer-certificate' ),
				'description' => __( 'Design without limits and award at scale — custom fonts, multi-page certificates, bulk awarding, and automatic expiry reminders.', 'pressprimer-certificate' ),
				'highlights'  => [
					__( 'Custom font uploads', 'pressprimer-certificate' ),
					__( 'Multi-page certificates', 'pressprimer-certificate' ),
					__( 'Bulk awarding from user lists or CSV', 'pressprimer-certificate' ),
					__( 'LinkedIn and social sharing', 'pressprimer-certificate' ),
					__( 'Expiry reminder emails', 'pressprimer-certificate' ),
					__( 'Branded verification page', 'pressprimer-certificate' ),
				],
				'url'         => 'https://pressprimer.com/pressprimer-certificate-educator/',
			],
			'school'     => [
				'name'        => __( 'School', 'pressprimer-certificate' ),
				'tagline'     => __( 'For programs and organizations that issue as a brand', 'pressprimer-certificate' ),
				'description' => __( 'Everything in Educator plus issuer profiles with roles and permissions, public program pages, a credential directory, and awards for past completions.', 'pressprimer-certificate' ),
				'highlights'  => [
					__( 'Everything in Educator', 'pressprimer-certificate' ),
					__( 'Multiple issuers with roles and permissions', 'pressprimer-certificate' ),
					__( 'Public issuer and program pages', 'pressprimer-certificate' ),
					__( 'Searchable credential directory', 'pressprimer-certificate' ),
					__( 'Award certificates for past completions', 'pressprimer-certificate' ),
					__( 'CC and BCC on certificate emails', 'pressprimer-certificate' ),
				],
				'url'         => 'https://pressprimer.com/pressprimer-certificate-school/',
			],
			'enterprise' => [
				'name'        => __( 'Enterprise', 'pressprimer-certificate' ),
				'tagline'     => __( 'For organizations with compliance requirements', 'pressprimer-certificate' ),
				'description' => __( 'Everything in School plus a complete audit log, white-label branding, and a verification API for the systems you already run.', 'pressprimer-certificate' ),
				'highlights'  => [
					__( 'Everything in School', 'pressprimer-certificate' ),
					__( 'Audit log of every change, exportable', 'pressprimer-certificate' ),
					__( 'White-label the entire plugin', 'pressprimer-certificate' ),
					__( 'Verification API for your other systems', 'pressprimer-certificate' ),
					__( 'Show live certificate checks on any website', 'pressprimer-certificate' ),
				],
				'url'         => 'https://pressprimer.com/pressprimer-certificate-enterprise/',
			],
		];

		// Registered entries win over defaults key-by-key (an addon or a
		// site owner may adjust a tier without redefining all three).
		return array_replace( $defaults, $tiers );
	}

	/**
	 * The tier registry with computed active flags
	 *
	 * @since 2.0.0
	 *
	 * @return array Map of tier id => tier definition + `active` bool.
	 */
	public static function get_tiers() {
		$manager = ppcert_addon_manager();

		if ( $manager ) {
			return $manager->get_upgrade_tiers();
		}

		// Defensive - without the manager no tier can be active.
		$tiers = self::register_default_tiers( [] );

		foreach ( $tiers as $tier_id => $tier ) {
			$tiers[ $tier_id ]['id']     = $tier_id;
			$tiers[ $tier_id ]['active'] = false;
		}

		return $tiers;
	}

	/**
	 * The tier whose card gets the featured emphasis
	 *
	 * The lowest unowned paid tier: School by default ("Most Popular",
	 * sibling pattern); with School owned the emphasis moves up to
	 * Enterprise (US-2); with everything owned nothing is featured.
	 * Educator is never featured - tier stacking means an unowned
	 * Educator always accompanies an unowned School.
	 *
	 * @since 2.0.0
	 *
	 * @param array $tiers Tier registry with active flags.
	 * @return string Featured tier id, or '' when all tiers are owned.
	 */
	public static function featured_tier( array $tiers ) {
		foreach ( [ 'school', 'enterprise' ] as $tier_id ) {
			if ( isset( $tiers[ $tier_id ] ) && empty( $tiers[ $tier_id ]['active'] ) ) {
				return $tier_id;
			}
		}

		return '';
	}

	/**
	 * Whether every tier is owned (US-3: the page renders calm)
	 *
	 * @since 2.0.0
	 *
	 * @param array $tiers Tier registry with active flags.
	 * @return bool
	 */
	public static function all_tiers_active( array $tiers ) {
		if ( empty( $tiers ) ) {
			return false;
		}

		foreach ( $tiers as $tier ) {
			if ( empty( $tier['active'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the Enterprise addon is active
	 *
	 * The Upgrade menu hides itself only at the top tier - users on
	 * Educator or School still see "Upgrade" because higher tiers are
	 * available to them (sibling pattern).
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public function enterprise_addon_active() {
		$manager = ppcert_addon_manager();

		return $manager ? $manager->is_tier_active( 'enterprise' ) : false;
	}

	/**
	 * Register the Upgrade submenu, conditional on Enterprise not active.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function register_menu() {
		if ( $this->enterprise_addon_active() ) {
			return;
		}

		add_submenu_page(
			'pressprimer-certificate',
			__( 'Upgrade PressPrimer Certificate', 'pressprimer-certificate' ),
			__( 'Upgrade', 'pressprimer-certificate' ),
			PressPrimer_Certificate_Capabilities::CAP_MANAGE_SETTINGS,
			self::MENU_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Enqueue the admin menu highlight styles for the Upgrade item.
	 *
	 * Targets the submenu link by its slug-anchor href so unrelated
	 * submenu items are not painted. Uses wp_add_inline_style() on a
	 * dedicated registered handle (no raw style tags) and loads on every
	 * admin page - the flyout submenu is visible site-wide in wp-admin.
	 * Skipped entirely when Enterprise is active (no menu item exists).
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function enqueue_menu_styles() {
		if ( $this->enterprise_addon_active() ) {
			return;
		}

		// CSS is static apart from the class-constant slug - no user input.
		$css = sprintf(
			'#adminmenu .wp-submenu a[href$="%1$s"],
			#adminmenu .wp-submenu li.current a[href$="%1$s"] {
				background-color: #1f7a3a;
				color: #fff !important;
				font-weight: 700;
			}
			#adminmenu .wp-submenu a[href$="%1$s"]:hover,
			#adminmenu .wp-submenu a[href$="%1$s"]:focus {
				background-color: #186730;
				color: #fff !important;
			}',
			self::MENU_SLUG
		);

		wp_register_style( 'ppcert-upgrade-menu', false, [], PPCERT_VERSION );
		wp_enqueue_style( 'ppcert-upgrade-menu' );
		wp_add_inline_style( 'ppcert-upgrade-menu', $css );
	}

	/**
	 * Enqueue the page stylesheet on the Upgrade screen only.
	 *
	 * @since 2.0.0
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public function enqueue_page_styles( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'ppcert-upgrade-page',
			PPCERT_PLUGIN_URL . 'assets/css/ppcert-upgrade-page.css',
			[],
			PPCERT_VERSION
		);
	}

	/**
	 * Render the upgrade page.
	 *
	 * Loads the view file with the prepared data in scope. The view file
	 * uses esc_url(), esc_html(), etc. on every dynamic value. With every
	 * tier owned the page renders its calm all-active state (US-3) - the
	 * menu item is gone by then, but the direct URL stays useful as a
	 * documentation jump-off rather than failing closed.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( PressPrimer_Certificate_Capabilities::CAP_MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'pressprimer-certificate' ),
				esc_html__( 'Permission Denied', 'pressprimer-certificate' ),
				[ 'response' => 403 ]
			);
		}

		$features   = self::get_comparison_features();
		$tiers      = self::get_tiers();
		$featured   = self::featured_tier( $tiers );
		$all_active = self::all_tiers_active( $tiers );

		// UTM-tag every outbound buy link with the page and its position;
		// owned tiers link to their documentation instead.
		foreach ( $tiers as $tier_id => $tier ) {
			$tiers[ $tier_id ]['url'] = empty( $tier['active'] )
				? self::utm_url( isset( $tier['url'] ) ? $tier['url'] : self::PRICING_URL, 'tier-card-' . $tier_id )
				: self::KB_URL;
		}

		$pricing_hero_url   = self::get_pricing_url( 'hero-cta' );
		$pricing_footer_url = self::get_pricing_url( 'footer-cta' );
		$pricing_sticky_url = self::get_pricing_url( 'sticky-bar' );
		$kb_url             = self::KB_URL;

		$logo_url          = PPCERT_PLUGIN_URL . 'assets/images/PressPrimer-Logo-White.svg';
		$hero_mascot_url   = PPCERT_PLUGIN_URL . 'assets/images/mascot-waving.png';
		$footer_mascot_url = PPCERT_PLUGIN_URL . 'assets/images/mascot-celebrating-confetti.png';

		// Make $this available to the view so render_cell_value() is callable.
		$upgrade_page = $this;

		include PPCERT_PLUGIN_DIR . 'includes/admin/views/upgrade-page.php';
	}

	/**
	 * Append UTM parameters identifying the page and position to a URL.
	 *
	 * Shared by every marketing surface in the plugin (Feature 2.0-005's
	 * touchpoints reuse it) so outbound links carry a consistent tag set:
	 * - utm_source:   the plugin
	 * - utm_medium:   in-product placement
	 * - utm_campaign: the surface (page) the link lives on
	 * - utm_content:  the position within that surface
	 *
	 * @since 2.0.0
	 *
	 * @param string $url      Base pressprimer.com URL.
	 * @param string $position Position slug within the surface (e.g. 'hero-cta').
	 * @param string $campaign Optional. Surface slug. Default 'upgrade-page'.
	 * @return string URL with UTM parameters appended.
	 */
	public static function utm_url( $url, $position, $campaign = 'upgrade-page' ) {
		return add_query_arg(
			[
				'utm_source'   => 'pressprimer-certificate',
				'utm_medium'   => 'plugin',
				'utm_campaign' => $campaign,
				'utm_content'  => $position,
			],
			$url
		);
	}

	/**
	 * Get the UTM-tagged pricing page URL for a given position.
	 *
	 * The #pricing fragment is appended after the query string so the
	 * browser lands on the plans (sibling pattern).
	 *
	 * @since 2.0.0
	 *
	 * @param string $position Position slug within the surface.
	 * @param string $campaign Optional. Surface slug. Default 'upgrade-page'.
	 * @return string UTM-tagged pricing URL.
	 */
	public static function get_pricing_url( $position, $campaign = 'upgrade-page' ) {
		return self::utm_url( self::PRICING_URL, $position, $campaign ) . '#pricing';
	}

	/**
	 * Render a single comparison-table cell value.
	 *
	 * Converts the raw row value into display HTML:
	 *   - true   → checkmark
	 *   - false  → em dash
	 *   - string → escaped text (row-specific notes like "1 site")
	 *
	 * Output contains only static spans and escaped text; the view runs
	 * it through wp_kses() with a matching allowlist.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $value Raw value from a feature row's tier column.
	 * @return string HTML to render inside the cell.
	 */
	public function render_cell_value( $value ) {
		if ( true === $value ) {
			return '<span class="ppcert-upgrade-cell-yes" aria-label="' . esc_attr__( 'Included', 'pressprimer-certificate' ) . '">&#10003;</span>';
		}

		if ( false === $value ) {
			return '<span class="ppcert-upgrade-cell-no" aria-label="' . esc_attr__( 'Not included', 'pressprimer-certificate' ) . '">&mdash;</span>';
		}

		return '<span class="ppcert-upgrade-cell-note">' . esc_html( (string) $value ) . '</span>';
	}

	/**
	 * Curated comparison table contents.
	 *
	 * Every row reflects the shipped 2.0 tier scope (FR-003); the one
	 * roadmap item ("Premium template pack") is marked as coming. Rows
	 * are updated by code change as part of each release - when a tier
	 * boundary changes for any feature, update this array in the same
	 * release that ships the change.
	 *
	 * Order is meaningful: it is the display order on the page. Category
	 * headers are emitted by the view whenever the `category` value
	 * changes between consecutive rows.
	 *
	 * Each row's tier value is:
	 *   - true   : feature included in that tier
	 *   - false  : not included
	 *   - string : included with a caveat (e.g., "1 site", "Coming soon")
	 *
	 * Tiers are cumulative (School includes Educator; Enterprise includes
	 * School), so a feature available at a tier is true for every higher
	 * tier.
	 *
	 * @since 2.0.0
	 *
	 * @return array<int, array<string, mixed>> Array of feature row arrays.
	 */
	public static function get_comparison_features() {
		$design       = __( 'Design & Templates', 'pressprimer-certificate' );
		$awarding     = __( 'Awarding & Delivery', 'pressprimer-certificate' );
		$sharing      = __( 'Sharing & Verification', 'pressprimer-certificate' );
		$organization = __( 'Organization & Compliance', 'pressprimer-certificate' );
		$coming       = __( 'Coming soon', 'pressprimer-certificate' );

		return [
			// Design & Templates.
			[
				'category'   => $design,
				'feature'    => __( 'Drag-and-drop certificate designer', 'pressprimer-certificate' ),
				'free'       => true,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $design,
				'feature'    => __( 'Starter template gallery with brand colors and logo', 'pressprimer-certificate' ),
				'free'       => true,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $design,
				'feature'    => __( 'Merge fields from your quiz, course, and site data', 'pressprimer-certificate' ),
				'free'       => true,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $design,
				'feature'    => __( 'Custom font uploads', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $design,
				'feature'    => __( 'Multi-page certificates', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $design,
				'feature'    => __( 'Premium template pack', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => $coming,
				'school'     => $coming,
				'enterprise' => $coming,
			],

			// Awarding & Delivery.
			[
				'category'   => $awarding,
				'feature'    => __( 'Automatic awards from your quiz and course plugins', 'pressprimer-certificate' ),
				'free'       => true,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $awarding,
				'feature'    => __( 'Manual awarding with backdating and expiry dates', 'pressprimer-certificate' ),
				'free'       => true,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $awarding,
				'feature'    => __( 'Award emails with the certificate PDF attached', 'pressprimer-certificate' ),
				'free'       => true,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $awarding,
				'feature'    => __( 'Bulk awarding from user lists or CSV', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $awarding,
				'feature'    => __( 'Award for past completions, retroactively', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => false,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $awarding,
				'feature'    => __( 'Expiry reminder emails', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $awarding,
				'feature'    => __( 'CC and BCC on certificate emails', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => false,
				'school'     => true,
				'enterprise' => true,
			],

			// Sharing & Verification.
			[
				'category'   => $sharing,
				'feature'    => __( 'QR-verified certificates with a public verification page', 'pressprimer-certificate' ),
				'free'       => true,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $sharing,
				'feature'    => __( 'My Certificates page with PDF downloads', 'pressprimer-certificate' ),
				'free'       => true,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $sharing,
				'feature'    => __( 'LinkedIn and social sharing', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $sharing,
				'feature'    => __( 'Branded verification page', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => __( 'Site-level', 'pressprimer-certificate' ),
				'school'     => __( 'Per issuer', 'pressprimer-certificate' ),
				'enterprise' => __( 'White-label', 'pressprimer-certificate' ),
			],
			[
				'category'   => $sharing,
				'feature'    => __( 'Public issuer and program pages', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => false,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $sharing,
				'feature'    => __( 'Searchable credential directory', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => false,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $sharing,
				'feature'    => __( 'Verification API and website embed', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => false,
				'school'     => false,
				'enterprise' => true,
			],

			// Organization & Compliance.
			[
				'category'   => $organization,
				'feature'    => __( 'Multiple issuers with roles and permissions', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => false,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $organization,
				'feature'    => __( 'Audit logging with retention controls', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => false,
				'school'     => false,
				'enterprise' => true,
			],
			[
				'category'   => $organization,
				'feature'    => __( 'White-label branding (remove PressPrimer references)', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => false,
				'school'     => false,
				'enterprise' => true,
			],
			[
				'category'   => $organization,
				'feature'    => __( 'Priority support', 'pressprimer-certificate' ),
				'free'       => false,
				'educator'   => true,
				'school'     => true,
				'enterprise' => true,
			],
			[
				'category'   => $organization,
				'feature'    => __( 'Site licenses included', 'pressprimer-certificate' ),
				'free'       => __( 'Unlimited', 'pressprimer-certificate' ),
				'educator'   => __( '1 site', 'pressprimer-certificate' ),
				'school'     => __( '2 sites', 'pressprimer-certificate' ),
				'enterprise' => __( '5 sites', 'pressprimer-certificate' ),
			],
		];
	}
}
