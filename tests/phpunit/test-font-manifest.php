<?php
/**
 * Font manifest tests
 *
 * The committed fonts/manifest.json is the single font map consumed by
 * the PDF renderer and the designer (Feature 007 FR-003); these tests
 * pin its shape, the FR-004 fitting thresholds, and the validator wiring.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Font manifest test case
 *
 * @since 1.0.0
 */
class Test_Font_Manifest extends TestCase {

	/**
	 * Decoded manifest.
	 *
	 * @var array
	 */
	private $manifest;

	/**
	 * Load the committed manifest.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();

		$path = PPCERT_PLUGIN_DIR . 'fonts/manifest.json';
		$this->assertFileExists( $path );
		$this->manifest = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $this->manifest );
	}

	/**
	 * Both working families are present with four variants each, every
	 * variant pointing at existing TTF and converted TCPDF files.
	 *
	 * @return void
	 */
	public function test_families_and_variants_complete() {
		// The final 5.1 roster: variant coverage differs per family -
		// Quicksand has no upstream italics and the two scripts are
		// single-weight by design (Ryan, 2026-07-23).
		$expected_variants = [
			'playfair-display' => [ 'regular', 'bold', 'italic', 'bold_italic' ],
			'source-sans-3'    => [ 'regular', 'bold', 'italic', 'bold_italic' ],
			'eb-garamond'      => [ 'regular', 'bold', 'italic', 'bold_italic' ],
			'quicksand'        => [ 'regular', 'bold' ],
			'great-vibes'      => [ 'regular' ],
			'alex-brush'       => [ 'regular' ],
		];

		$this->assertSame( array_keys( $expected_variants ), array_keys( $this->manifest['families'] ) );

		foreach ( $this->manifest['families'] as $slug => $family ) {
			$this->assertSame(
				$expected_variants[ $slug ],
				array_keys( $family['variants'] ),
				"{$slug} must ship its bundled variant set"
			);

			$this->assertSame( 'SIL OFL 1.1', $family['license'] );
			$this->assertFileExists( PPCERT_PLUGIN_DIR . 'fonts/' . $family['license_file'], "{$slug} license text must ship" );

			foreach ( $family['variants'] as $variant => $info ) {
				$this->assertFileExists( PPCERT_PLUGIN_DIR . 'fonts/' . $info['ttf'], "{$slug}/{$variant} TTF" );

				foreach ( [ '.php', '.z', '.ctg.z' ] as $ext ) {
					$this->assertFileExists(
						PPCERT_PLUGIN_DIR . 'fonts/tcpdf/' . $info['tcpdf_font'] . $ext,
						"{$slug}/{$variant} converted output ({$ext})"
					);
				}

				$this->assertGreaterThan( 0, $info['metrics']['ascent'], "{$slug}/{$variant} metrics present" );
				$this->assertLessThan( 0, $info['metrics']['descent'] );
			}
		}
	}

	/**
	 * The FR-004 fitting thresholds are pinned - the canvas reads these
	 * same values, so changing them is a parity-contract change.
	 *
	 * @return void
	 */
	public function test_fitting_thresholds() {
		$this->assertSame( 0.5, $this->manifest['fitting']['shrink_step_pt'] );
		$this->assertSame( 0.6, $this->manifest['fitting']['min_scale'] );
		$this->assertSame( 'ellipsis', $this->manifest['fitting']['overflow'] );
	}

	/**
	 * The layout validator sources its font set from the manifest, still
	 * extensible through the filter, with the default font present.
	 *
	 * @return void
	 */
	public function test_validator_reads_manifest() {
		$slugs = PressPrimer_Certificate_Layout_Validator::get_registered_font_slugs();

		$this->assertContains( 'playfair-display', $slugs );
		$this->assertContains( 'source-sans-3', $slugs );
		$this->assertContains( PressPrimer_Certificate_Layout_Validator::DEFAULT_FONT, $slugs );

		add_filter(
			'ppcert_designer_fonts',
			static function ( $fonts ) {
				$fonts['premium-font'] = [];
				return $fonts;
			}
		);

		$this->assertContains( 'premium-font', PressPrimer_Certificate_Layout_Validator::get_registered_font_slugs() );
	}

	/**
	 * A source-sans-3 font_family now validates without fallback.
	 *
	 * @return void
	 */
	public function test_source_sans_validates_in_layouts() {
		$document = [
			'layout_schema_version' => 1,
			'page'                  => [
				'size'        => 'a4',
				'orientation' => 'landscape',
				'width'       => 842,
				'height'      => 595,
			],
			'background'            => [
				'color'         => '#ffffff',
				'attachment_id' => 0,
			],
			'elements'              => [
				[
					'id'    => 'el_sanstest',
					'type'  => 'text',
					'x'     => 10,
					'y'     => 10,
					'w'     => 200,
					'h'     => 30,
					'z'     => 1,
					'props' => [
						'content'     => 'Sans text',
						'font_family' => 'source-sans-3',
						'font_size'   => 14,
						'color'       => '#000000',
						'align'       => 'left',
						'line_height' => 1.2,
						'bold'        => false,
						'italic'      => false,
					],
				],
			],
		];

		$result = PressPrimer_Certificate_Layout_Validator::validate( $document );

		$this->assertIsArray( $result );
		$this->assertSame( 'source-sans-3', $result['elements'][0]['props']['font_family'] );
	}
}
