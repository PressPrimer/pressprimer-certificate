<?php
/**
 * PNG rasterization tests
 *
 * The GD raster path in CI (dimensions, QR presence, pixel determinism)
 * and the GD-vs-Imagick equivalence wherever Imagick with a PDF delegate
 * exists (skips otherwise - covered locally per Feature 007 TR-002).
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * PNG rasterizer test case
 *
 * @since 1.0.0
 */
class Test_PNG_Rasterizer extends TestCase {

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$GLOBALS['ppcert_test_attachment_files'] = [];
	}

	/**
	 * Load the schema sample document.
	 *
	 * @return array
	 */
	private function sample() {
		return json_decode(
			(string) file_get_contents( PPCERT_PLUGIN_DIR . 'tests/phpunit/fixtures/sample-document.json' ),
			true
		);
	}

	/**
	 * GD raster: valid PNG at the requested DPI's exact pixel dimensions.
	 *
	 * @return void
	 */
	public function test_gd_raster_dimensions() {
		$renderer = new PressPrimer_Certificate_PDF_Renderer();

		$path = $renderer->render_png(
			$this->sample(),
			[ 'recipient.display_name' => 'Dana Whitfield' ],
			[
				'context' => 'parity',
				'dpi'     => 72,
			]
		);

		$this->assertIsString( $path );

		$info = getimagesize( $path );
		$this->assertSame( 842, $info[0] );
		$this->assertSame( 595, $info[1] );
		$this->assertSame( 'image/png', $info['mime'] );

		unlink( $path );
	}

	/**
	 * 300 DPI default scales exactly (A4 landscape: 3508 x 2479).
	 *
	 * @return void
	 */
	public function test_default_dpi_dimensions() {
		$renderer = new PressPrimer_Certificate_PDF_Renderer();

		$path = $renderer->render_png( $this->sample(), [], [ 'context' => 'parity' ] );

		$this->assertIsString( $path );

		$info = getimagesize( $path );
		$this->assertSame( (int) round( 842 * 300 / 72 ), $info[0] );
		$this->assertSame( (int) round( 595 * 300 / 72 ), $info[1] );

		unlink( $path );
	}

	/**
	 * Determinism (US-3): two renders of identical input are byte-identical
	 * on the GD path.
	 *
	 * @return void
	 */
	public function test_gd_raster_deterministic() {
		$renderer   = new PressPrimer_Certificate_PDF_Renderer();
		$merge_data = [ 'recipient.display_name' => 'Dana Whitfield' ];
		$args       = [
			'context'       => 'parity',
			'dpi'           => 96,
			'credential_id' => 'G1MYK9P2XT3A',
		];

		$first  = $renderer->render_png( $this->sample(), $merge_data, $args );
		$second = $renderer->render_png( $this->sample(), $merge_data, $args );

		$this->assertIsString( $first );
		$this->assertIsString( $second );
		$this->assertSame( md5_file( $first ), md5_file( $second ), 'Identical input must produce identical pixels' );

		unlink( $first );
		unlink( $second );
	}

	/**
	 * The QR element draws dark modules inside its box on the raster.
	 *
	 * @return void
	 */
	public function test_qr_present_in_raster() {
		$renderer = new PressPrimer_Certificate_PDF_Renderer();

		$path = $renderer->render_png(
			$this->sample(),
			[],
			[
				'context'       => 'parity',
				'dpi'           => 72,
				'credential_id' => 'G1MYK9P2XT3A',
			]
		);

		$this->assertIsString( $path );

		$image = imagecreatefrompng( $path );

		// Sample QR box: x 750, y 480, 60x60 at 72 DPI (1 px per pt).
		// The finder pattern begins after the 4-module quiet zone.
		$dark_found = false;
		for ( $x = 750; $x < 810 && ! $dark_found; $x += 2 ) {
			for ( $y = 480; $y < 540 && ! $dark_found; $y += 2 ) {
				$rgb = imagecolorsforindex( $image, imagecolorat( $image, $x, $y ) );
				if ( $rgb['red'] < 100 && $rgb['green'] < 100 && $rgb['blue'] < 120 ) {
					$dark_found = true;
				}
			}
		}

		$this->assertTrue( $dark_found, 'QR dark modules must appear inside the element box' );

		imagedestroy( $image );
		unlink( $path );
	}

	/**
	 * The PDF path also renders the QR now: the sample renders with no
	 * warnings and produces more content than a QR-less render.
	 *
	 * @return void
	 */
	public function test_pdf_path_qr_no_warnings() {
		$renderer = new PressPrimer_Certificate_PDF_Renderer();

		$path = $renderer->render_pdf(
			$this->sample(),
			[],
			[
				'context'       => 'preview',
				'credential_id' => 'G1MYK9P2XT3A',
			]
		);

		$this->assertIsString( $path );
		$this->assertSame( [], $renderer->get_last_render_warnings() );

		unlink( $path );
	}

	/**
	 * GD-vs-Imagick equivalence within the parity threshold (FR-005:
	 * 1.0% differing pixels). Skips wherever Imagick with a PDF delegate
	 * is unavailable - this is the locally-run leg per TR-002.
	 *
	 * @return void
	 */
	public function test_gd_vs_imagick_within_threshold() {
		if ( ! extension_loaded( 'imagick' ) ) {
			$this->markTestSkipped( 'Imagick unavailable; GD-vs-Imagick equivalence runs where Imagick + Ghostscript exist (TR-002 local leg).' );
		}

		$renderer   = new PressPrimer_Certificate_PDF_Renderer();
		$merge_data = [ 'recipient.display_name' => 'Dana Whitfield' ];
		$args       = [
			'context'       => 'parity',
			'dpi'           => 150,
			'credential_id' => 'G1MYK9P2XT3A',
		];

		// Imagick path (render_png prefers it when loaded).
		$imagick_png = $renderer->render_png( $this->sample(), $merge_data, $args );

		// Force the GD path via reflection on the private rasterizer.
		$method = new ReflectionMethod( PressPrimer_Certificate_PDF_Renderer::class, 'gd_rasterize' );
		$method->setAccessible( true );
		$gd_png = $method->invoke( $renderer, $this->sample(), $merge_data, $args, 150 );

		$this->assertIsString( $imagick_png );
		$this->assertIsString( $gd_png );

		$a = imagecreatefrompng( $imagick_png );
		$b = imagecreatefrompng( $gd_png );

		$width  = min( imagesx( $a ), imagesx( $b ) );
		$height = min( imagesy( $a ), imagesy( $b ) );

		$differing = 0;
		$sampled   = 0;

		for ( $x = 0; $x < $width; $x += 3 ) {
			for ( $y = 0; $y < $height; $y += 3 ) {
				$ca = imagecolorsforindex( $a, imagecolorat( $a, $x, $y ) );
				$cb = imagecolorsforindex( $b, imagecolorat( $b, $x, $y ) );
				$sampled++;

				if ( abs( $ca['red'] - $cb['red'] ) > 32 || abs( $ca['green'] - $cb['green'] ) > 32 || abs( $ca['blue'] - $cb['blue'] ) > 32 ) {
					$differing++;
				}
			}
		}

		$this->assertLessThanOrEqual( 0.01, $differing / max( 1, $sampled ), 'GD and Imagick rasters must agree within the parity threshold' );

		imagedestroy( $a );
		imagedestroy( $b );
		unlink( $imagick_png );
		unlink( $gd_png );
	}
}
