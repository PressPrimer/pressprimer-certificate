<?php
/**
 * Page-size registry tests
 *
 * Exercises includes/utilities/class-ppcert-page-sizes.php (Feature
 * 2.1-003 TR-001, Feature 2.1-004 FR-002): the core sizes and their
 * order, the `ppcert_page_presets` filter's validation, the gated
 * custom size, labels, and the Playwright harness's copy of the
 * presets.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Page sizes test case
 *
 * @since 2.1.0
 */
class Test_Page_Sizes extends TestCase {

	/**
	 * Reset hooks and options between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$GLOBALS['ppcert_test_options'] = [];
	}

	/**
	 * The four core sizes, in menu order, with whole-point dimensions.
	 *
	 * @return void
	 */
	public function test_core_sizes_in_menu_order() {
		$this->assertSame( [ 'letter', 'legal', 'a4', 'a3' ], PressPrimer_Certificate_Page_Sizes::keys() );

		$expected = [
			'letter' => [ [ 792, 612 ], [ 612, 792 ], 'Letter', 'in' ],
			'legal'  => [ [ 1008, 612 ], [ 612, 1008 ], 'Legal', 'in' ],
			'a4'     => [ [ 842, 595 ], [ 595, 842 ], 'A4', 'mm' ],
			'a3'     => [ [ 1191, 842 ], [ 842, 1191 ], 'A3', 'mm' ],
		];

		foreach ( $expected as $key => $values ) {
			$this->assertSame( $values[0], PressPrimer_Certificate_Page_Sizes::dimensions( $key, 'landscape' ), $key );
			$this->assertSame( $values[1], PressPrimer_Certificate_Page_Sizes::dimensions( $key, 'portrait' ), $key );
			$this->assertSame( $values[2], PressPrimer_Certificate_Page_Sizes::get( $key )['label'] );
			$this->assertSame( $values[3], PressPrimer_Certificate_Page_Sizes::get( $key )['unit'] );
		}
	}

	/**
	 * Unknown sizes, `custom`, and bad orientations resolve to nothing.
	 *
	 * @return void
	 */
	public function test_unknown_sizes_and_orientations() {
		$this->assertNull( PressPrimer_Certificate_Page_Sizes::get( 'tabloid' ) );
		$this->assertNull( PressPrimer_Certificate_Page_Sizes::get( 'custom' ) );
		$this->assertFalse( PressPrimer_Certificate_Page_Sizes::is_preset( 'custom' ) );
		$this->assertNull( PressPrimer_Certificate_Page_Sizes::dimensions( 'a4', 'sideways' ) );
		$this->assertNull( PressPrimer_Certificate_Page_Sizes::dimensions( 'tabloid', 'landscape' ) );
	}

	/**
	 * The filter adds a valid preset after the core sizes.
	 *
	 * @return void
	 */
	public function test_filter_adds_preset() {
		add_filter(
			'ppcert_page_presets',
			static function ( $presets ) {
				$presets['half_letter'] = [
					'label'     => 'Half Letter',
					'unit'      => 'in',
					'landscape' => [ 612, 396 ],
					'portrait'  => [ 396, 612 ],
				];
				return $presets;
			}
		);

		$this->assertSame( [ 'letter', 'legal', 'a4', 'a3', 'half_letter' ], PressPrimer_Certificate_Page_Sizes::keys() );
		$this->assertSame( [ 396, 612 ], PressPrimer_Certificate_Page_Sizes::dimensions( 'half_letter', 'portrait' ) );
	}

	/**
	 * Malformed, out-of-bounds, and reserved filter entries are dropped;
	 * core sizes survive a filter that removes them.
	 *
	 * @return void
	 */
	public function test_filter_entries_validated_and_core_kept() {
		add_filter(
			'ppcert_page_presets',
			static function () {
				return [
					'custom'   => [
						'label'     => 'Reserved',
						'landscape' => [ 300, 200 ],
						'portrait'  => [ 200, 300 ],
					],
					'tiny'     => [
						'label'     => 'Tiny',
						'landscape' => [ 50, 40 ],
						'portrait'  => [ 40, 50 ],
					],
					'huge'     => [
						'label'     => 'Huge',
						'landscape' => [ 2000, 1000 ],
						'portrait'  => [ 1000, 2000 ],
					],
					'nolabel'  => [
						'landscape' => [ 300, 200 ],
						'portrait'  => [ 200, 300 ],
					],
					'Bad Key'  => [
						'label'     => 'Bad',
						'landscape' => [ 300, 200 ],
						'portrait'  => [ 200, 300 ],
					],
					'one_side' => [
						'label'     => 'One side',
						'landscape' => [ 300 ],
						'portrait'  => [ 200, 300 ],
					],
				];
			}
		);

		$this->assertSame( [ 'letter', 'legal', 'a4', 'a3' ], PressPrimer_Certificate_Page_Sizes::keys() );
	}

	/**
	 * Custom sizes are off by default and follow the filter.
	 *
	 * @return void
	 */
	public function test_custom_allowed_follows_filter() {
		$this->assertFalse( PressPrimer_Certificate_Page_Sizes::custom_allowed() );

		add_filter( 'ppcert_allow_custom_page_size', '__return_true' );

		$this->assertTrue( PressPrimer_Certificate_Page_Sizes::custom_allowed() );
	}

	/**
	 * Custom sides clamp to 72-1728 pt and round to hundredths.
	 *
	 * @return void
	 */
	public function test_clamp_side() {
		$this->assertSame( 72.0, PressPrimer_Certificate_Page_Sizes::clamp_side( 10 ) );
		$this->assertSame( 1728.0, PressPrimer_Certificate_Page_Sizes::clamp_side( 5000 ) );
		$this->assertSame( 243.12, PressPrimer_Certificate_Page_Sizes::clamp_side( '243.1234' ) );
		$this->assertSame( 72.0, PressPrimer_Certificate_Page_Sizes::clamp_side( 'wide' ) );
	}

	/**
	 * Preset labels read "A3 · Landscape".
	 *
	 * @return void
	 */
	public function test_preset_labels() {
		$this->assertSame( 'A3 · Landscape', PressPrimer_Certificate_Page_Sizes::label( 'a3', 'landscape' ) );
		$this->assertSame( 'Legal · Portrait', PressPrimer_Certificate_Page_Sizes::label( 'legal', 'portrait' ) );
		$this->assertSame( 'TABLOID · Portrait', PressPrimer_Certificate_Page_Sizes::label( 'tabloid', 'portrait' ), 'An unregistered stored size still labels' );
	}

	/**
	 * Custom labels use inches on Letter/Legal-default sites and
	 * millimeters otherwise, with trailing zeros dropped.
	 *
	 * @return void
	 */
	public function test_custom_labels_follow_default_unit() {
		$page = [
			'width'  => 576,
			'height' => 720,
		];

		// Default page size is Letter (inches).
		$this->assertSame( 'Custom · 8 × 10 in', PressPrimer_Certificate_Page_Sizes::label( 'custom', 'portrait', $page ) );
		$this->assertSame( 'Custom · 3.38 × 2.13 in', PressPrimer_Certificate_Page_Sizes::label( 'custom', 'landscape', [ 'width' => 243, 'height' => 153 ] ) );

		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [ 'appearance_page_size' => 'a4' ];

		$this->assertSame( 'Custom · 203.2 × 254 mm', PressPrimer_Certificate_Page_Sizes::label( 'custom', 'portrait', $page ) );
		$this->assertSame( 'Custom', PressPrimer_Certificate_Page_Sizes::label( 'custom', 'portrait', [] ), 'No dimensions: just the name' );
	}

	/**
	 * The Playwright harness boots from a fixture copy of the presets;
	 * it must match the registry exactly (Feature 2.1-003 TR-001).
	 *
	 * @return void
	 */
	public function test_harness_fixture_matches_registry() {
		$fixture = json_decode(
			(string) file_get_contents( dirname( __DIR__ ) . '/playwright/fixtures/page-presets.json' ),
			true
		);

		$this->assertSame( PressPrimer_Certificate_Page_Sizes::all(), $fixture );
	}
}
