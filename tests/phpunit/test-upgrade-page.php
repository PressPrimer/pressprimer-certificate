<?php
/**
 * Upgrade page tests (2.0, Feature 2.0-004)
 *
 * Exercises the tier registry defaults and active-flag behavior
 * (FR-002), the featured-emphasis and all-active ownership logic
 * (US-2/US-3), the UTM link builders, and the rendered page in all
 * three ownership states.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Upgrade page test case
 *
 * @since 2.0.0
 */
class Test_Upgrade_Page extends TestCase {

	/**
	 * The addon manager singleton.
	 *
	 * @var PressPrimer_Certificate_Addon_Manager
	 */
	private $manager;

	/**
	 * The page under test.
	 *
	 * @var PressPrimer_Certificate_Upgrade_Page
	 */
	private $page;

	/**
	 * Reset hooks, the manager singleton, and register the free tier
	 * defaults the way init() does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();

		$GLOBALS['ppcert_test_user_caps'] = true;

		$this->manager = PressPrimer_Certificate_Addon_Manager::get_instance();
		$this->manager->reset();

		add_filter( 'ppcert_upgrade_tiers', [ 'PressPrimer_Certificate_Upgrade_Page', 'register_default_tiers' ], 5 );

		$this->page = new PressPrimer_Certificate_Upgrade_Page();
	}

	/**
	 * Activate a tier through the addon manager, the way addons do.
	 *
	 * @param string $tier Tier slug.
	 * @return void
	 */
	private function activate_tier( $tier ) {
		$this->manager->register_addon( 'ppcert-' . $tier, '2.0.0', [], [ 'tier' => $tier ] );
	}

	/**
	 * Render the page and return its HTML.
	 *
	 * @return string
	 */
	private function render() {
		ob_start();
		$this->page->render_page();

		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------
	// FR-002: registry defaults and active flags
	// -------------------------------------------------------------------

	/**
	 * The free defaults register all three tiers with the FR-002 entry
	 * shape, and the addon manager computes every active flag false on a
	 * bare site.
	 *
	 * @return void
	 */
	public function test_registry_defaults() {
		$tiers = $this->manager->get_upgrade_tiers();

		$this->assertSame( [ 'educator', 'school', 'enterprise' ], array_keys( $tiers ) );

		foreach ( $tiers as $tier_id => $tier ) {
			$this->assertSame( $tier_id, $tier['id'] );
			$this->assertFalse( $tier['active'] );
			$this->assertNotSame( '', (string) $tier['name'] );
			$this->assertNotSame( '', (string) $tier['tagline'] );
			$this->assertNotSame( '', (string) $tier['description'] );
			$this->assertNotEmpty( $tier['highlights'] );
			$this->assertStringStartsWith( 'https://pressprimer.com/', $tier['url'] );
		}

		// Stacking statement lives in the copy: the first highlight of
		// each higher tier names the tier below it.
		$this->assertSame( 'Everything in Educator', $tiers['school']['highlights'][0] );
		$this->assertSame( 'Everything in School', $tiers['enterprise']['highlights'][0] );
	}

	/**
	 * A registered addon with a tier flag flips that tier active, and
	 * registrants on the filter can adjust one tier without erasing the
	 * defaults.
	 *
	 * @return void
	 */
	public function test_active_flags_and_filter_overrides() {
		$this->activate_tier( 'educator' );

		$tiers = $this->manager->get_upgrade_tiers();
		$this->assertTrue( $tiers['educator']['active'] );
		$this->assertFalse( $tiers['school']['active'] );

		add_filter(
			'ppcert_upgrade_tiers',
			static function ( $tiers ) {
				$tiers['school']['tagline'] = 'Adjusted tagline';

				return $tiers;
			},
			20
		);

		$tiers = $this->manager->get_upgrade_tiers();
		$this->assertSame( 'Adjusted tagline', $tiers['school']['tagline'] );
		$this->assertSame( 'Educator', $tiers['educator']['name'], 'Defaults survive a partial override' );
	}

	// -------------------------------------------------------------------
	// US-2/US-3: ownership emphasis
	// -------------------------------------------------------------------

	/**
	 * The featured emphasis sits on the lowest unowned paid tier and
	 * vanishes when everything is owned.
	 *
	 * @return void
	 */
	public function test_featured_tier_follows_ownership() {
		$tiers = $this->manager->get_upgrade_tiers();
		$this->assertSame( 'school', PressPrimer_Certificate_Upgrade_Page::featured_tier( $tiers ) );
		$this->assertFalse( PressPrimer_Certificate_Upgrade_Page::all_tiers_active( $tiers ) );

		$this->activate_tier( 'educator' );
		$tiers = $this->manager->get_upgrade_tiers();
		$this->assertSame( 'school', PressPrimer_Certificate_Upgrade_Page::featured_tier( $tiers ), 'Educator owned: emphasis stays on School (US-2)' );

		$this->activate_tier( 'school' );
		$tiers = $this->manager->get_upgrade_tiers();
		$this->assertSame( 'enterprise', PressPrimer_Certificate_Upgrade_Page::featured_tier( $tiers ) );

		$this->activate_tier( 'enterprise' );
		$tiers = $this->manager->get_upgrade_tiers();
		$this->assertSame( '', PressPrimer_Certificate_Upgrade_Page::featured_tier( $tiers ) );
		$this->assertTrue( PressPrimer_Certificate_Upgrade_Page::all_tiers_active( $tiers ) );
		$this->assertTrue( $this->page->enterprise_addon_active() );
	}

	// -------------------------------------------------------------------
	// Link builders and cell rendering
	// -------------------------------------------------------------------

	/**
	 * UTM links carry the ecosystem tag set; the pricing URL keeps its
	 * fragment after the query string.
	 *
	 * @return void
	 */
	public function test_utm_links() {
		$url = PressPrimer_Certificate_Upgrade_Page::utm_url( 'https://pressprimer.com/x/', 'tier-card-school' );

		$this->assertStringContainsString( 'utm_source=pressprimer-certificate', $url );
		$this->assertStringContainsString( 'utm_medium=plugin', $url );
		$this->assertStringContainsString( 'utm_campaign=upgrade-page', $url );
		$this->assertStringContainsString( 'utm_content=tier-card-school', $url );

		$pricing = PressPrimer_Certificate_Upgrade_Page::get_pricing_url( 'hero-cta' );
		$this->assertStringStartsWith( 'https://pressprimer.com/pressprimer-certificate-pricing/', $pricing );
		$this->assertStringEndsWith( '#pricing', $pricing );
	}

	/**
	 * Comparison cells render checkmark / dash / note by value type.
	 *
	 * @return void
	 */
	public function test_render_cell_value() {
		$this->assertStringContainsString( 'ppcert-upgrade-cell-yes', $this->page->render_cell_value( true ) );
		$this->assertStringContainsString( '&mdash;', $this->page->render_cell_value( false ) );
		$this->assertStringContainsString( '1 site', $this->page->render_cell_value( '1 site' ) );
	}

	/**
	 * Comparison rows stay cumulative: a feature available at a tier is
	 * available at every higher tier (stacking, FR-003).
	 *
	 * @return void
	 */
	public function test_comparison_rows_are_cumulative() {
		$order = [ 'free', 'educator', 'school', 'enterprise' ];

		foreach ( PressPrimer_Certificate_Upgrade_Page::get_comparison_features() as $row ) {
			$seen = false;

			foreach ( $order as $tier_key ) {
				$included = false !== $row[ $tier_key ];

				if ( $seen && ! $included ) {
					$this->fail( sprintf( 'Row "%s" grants %s but not a higher tier.', $row['feature'], $tier_key ) );
				}

				$seen = $seen || $included;
			}

			$this->assertTrue( $seen, sprintf( 'Row "%s" is included nowhere.', $row['feature'] ) );
		}
	}

	// -------------------------------------------------------------------
	// Rendered ownership states (manual-test mirror)
	// -------------------------------------------------------------------

	/**
	 * No tiers owned: School is featured with a buy CTA, the sticky bar
	 * and footer CTA render, and no owned badges appear.
	 *
	 * @return void
	 */
	public function test_render_none_owned() {
		$html = $this->render();

		$this->assertStringContainsString( 'Do More with PressPrimer Certificate', $html );
		$this->assertStringContainsString( 'ppcert-upgrade-tier-school is-featured', $html );
		$this->assertStringContainsString( 'Most Popular', $html );
		$this->assertStringContainsString( 'Get School', $html );
		$this->assertStringContainsString( 'ppcert-upgrade-sticky-bar', $html );
		$this->assertStringContainsString( 'utm_content=tier-card-educator', $html );
		$this->assertStringNotContainsString( 'is-owned', $html );
		$this->assertStringNotContainsString( 'Active on This Site', $html );
	}

	/**
	 * Educator owned: its card flips to the owned state with a
	 * documentation link, School keeps the featured emphasis (US-2).
	 *
	 * @return void
	 */
	public function test_render_educator_owned() {
		$this->activate_tier( 'educator' );

		$html = $this->render();

		$this->assertStringContainsString( 'ppcert-upgrade-tier-educator is-owned', $html );
		$this->assertStringContainsString( 'Active on This Site', $html );
		$this->assertStringContainsString( 'View Documentation', $html );
		$this->assertStringNotContainsString( 'Get Educator', $html );
		$this->assertStringContainsString( 'ppcert-upgrade-tier-school is-featured', $html );
		$this->assertStringContainsString( 'Get School', $html );
	}

	/**
	 * Everything owned: the calm state (US-3) - docs links, no buy CTAs,
	 * no sticky bar, no money-back pitch.
	 *
	 * @return void
	 */
	public function test_render_all_owned() {
		$this->activate_tier( 'educator' );
		$this->activate_tier( 'school' );
		$this->activate_tier( 'enterprise' );

		$html = $this->render();

		$this->assertStringContainsString( 'You Have the Complete PressPrimer Certificate Suite', $html );
		$this->assertStringContainsString( 'Browse the Knowledge Base', $html );
		$this->assertStringNotContainsString( 'Get School', $html );
		$this->assertStringNotContainsString( 'Most Popular', $html );
		$this->assertStringNotContainsString( 'ppcert-upgrade-sticky-bar', $html );
		$this->assertStringNotContainsString( 'money-back', $html );
	}
}
