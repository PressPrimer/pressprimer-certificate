<?php
/**
 * QR service unit tests
 *
 * Exercises the ADR-004 wrapper: matrix output, fixed ECC/quiet zone,
 * determinism (the parity guarantee), and PNG rasterization.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * QR service test case
 *
 * @since 1.0.0
 */
class Test_QR_Service extends TestCase {

	/**
	 * Sample verification URL used across tests.
	 *
	 * @var string
	 */
	const SAMPLE_URL = 'https://example.com/verify/?ppcert_id=7Q4MK9P2XT3A';

	/**
	 * generate() returns a well-formed module matrix with the fixed ECC
	 * and quiet zone metadata.
	 *
	 * @return void
	 */
	public function test_generate_matrix_shape() {
		$qr = PressPrimer_Certificate_QR_Service::generate( self::SAMPLE_URL );

		$this->assertIsArray( $qr );
		$this->assertSame( 'M', $qr['ecc'] );
		$this->assertSame( 4, $qr['quiet_zone'] );

		// QR symbol sizes are 21 + 4n modules per side.
		$this->assertGreaterThanOrEqual( 21, $qr['modules'] );
		$this->assertSame( 1, $qr['modules'] % 4 );
		$this->assertSame( $qr['modules'] + 8, $qr['total_modules'] );

		$this->assertCount( $qr['modules'], $qr['matrix'] );
		foreach ( $qr['matrix'] as $row ) {
			$this->assertCount( $qr['modules'], $row );
			foreach ( $row as $module ) {
				$this->assertContains( $module, [ 0, 1 ] );
			}
		}

		// Finder pattern: top-left corner module is always dark.
		$this->assertSame( 1, $qr['matrix'][0][0] );
	}

	/**
	 * Identical input yields an identical matrix - the single-encoder
	 * parity guarantee (ADR 004).
	 *
	 * @return void
	 */
	public function test_generate_deterministic() {
		$first  = PressPrimer_Certificate_QR_Service::generate( self::SAMPLE_URL );
		$second = PressPrimer_Certificate_QR_Service::generate( self::SAMPLE_URL );

		$this->assertSame( $first['matrix'], $second['matrix'] );
	}

	/**
	 * Different URLs yield different matrices.
	 *
	 * @return void
	 */
	public function test_generate_differs_by_url() {
		$first  = PressPrimer_Certificate_QR_Service::generate( self::SAMPLE_URL );
		$second = PressPrimer_Certificate_QR_Service::generate( 'https://example.com/verify/?ppcert_id=451BNTRP0C2G' );

		$this->assertNotSame( $first['matrix'], $second['matrix'] );
	}

	/**
	 * Empty URLs are rejected.
	 *
	 * @return void
	 */
	public function test_generate_rejects_empty_url() {
		$this->assertInstanceOf( WP_Error::class, PressPrimer_Certificate_QR_Service::generate( '' ) );
		$this->assertInstanceOf( WP_Error::class, PressPrimer_Certificate_QR_Service::generate( '   ' ) );
	}

	/**
	 * PNG output: valid signature, square, whole-module scaling, quiet
	 * zone light, finder pattern dark, colors honored.
	 *
	 * @return void
	 */
	public function test_generate_png() {
		$qr = PressPrimer_Certificate_QR_Service::generate( self::SAMPLE_URL );

		$png = PressPrimer_Certificate_QR_Service::generate_png(
			self::SAMPLE_URL,
			[
				'size'        => 300,
				'dark_color'  => '#1f2a44',
				'light_color' => '#ffffff',
			]
		);

		$this->assertIsString( $png );
		$this->assertSame( "\x89PNG", substr( $png, 0, 4 ) );

		$image = imagecreatefromstring( $png );
		$this->assertNotFalse( $image );

		$width  = imagesx( $image );
		$height = imagesy( $image );
		$this->assertSame( $width, $height, 'QR PNG must be square' );
		$this->assertGreaterThanOrEqual( 300, $width, 'Requested minimum size honored' );
		$this->assertSame( 0, $width % $qr['total_modules'], 'Whole-module pixel scaling' );

		$scale = $width / $qr['total_modules'];

		// Quiet zone corner is the light color.
		$corner = imagecolorsforindex( $image, imagecolorat( $image, 1, 1 ) );
		$this->assertSame( [ 255, 255, 255 ], [ $corner['red'], $corner['green'], $corner['blue'] ] );

		// First finder-pattern module (just past the quiet zone) is dark.
		$offset = (int) ( 4 * $scale + 1 );
		$finder = imagecolorsforindex( $image, imagecolorat( $image, $offset, $offset ) );
		$this->assertSame( [ 31, 42, 68 ], [ $finder['red'], $finder['green'], $finder['blue'] ] );

	}

	/**
	 * An empty light_color produces a transparent background.
	 *
	 * @return void
	 */
	public function test_generate_png_transparent_light() {
		$png = PressPrimer_Certificate_QR_Service::generate_png(
			self::SAMPLE_URL,
			[
				'size'        => 120,
				'light_color' => '',
			]
		);

		$this->assertIsString( $png );

		$image = imagecreatefromstring( $png );
		$this->assertNotFalse( $image );

		$corner = imagecolorsforindex( $image, imagecolorat( $image, 1, 1 ) );
		$this->assertSame( 127, $corner['alpha'], 'Quiet zone must be fully transparent' );

	}
}
