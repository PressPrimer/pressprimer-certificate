<?php
/**
 * Font cache service tests
 *
 * The trimmed-ZIP font path: bundled TTFs win when present; otherwise
 * the converted .z inflates once into the uploads cache, byte-equal to
 * the original TTF.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Font cache test case
 *
 * @since 1.0.0
 */
class Test_Font_Cache extends TestCase {

	/**
	 * Clean the stubbed uploads cache dir between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . PressPrimer_Certificate_Font_Cache_Service::SUBDIR;

		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				unlink( $file );
			}
		}
	}

	/**
	 * Bundled TTFs resolve directly - dev checkouts and the parity
	 * harness never touch the cache.
	 *
	 * @return void
	 */
	public function test_bundled_ttf_wins() {
		$variant = [
			'tcpdf_font' => 'playfairdisplay',
			'ttf'        => 'playfair-display/PlayfairDisplay-Regular.ttf',
		];

		$path = PressPrimer_Certificate_Font_Cache_Service::ttf_path( $variant );
		$this->assertSame( PPCERT_PLUGIN_DIR . 'fonts/playfair-display/PlayfairDisplay-Regular.ttf', $path );
		$this->assertFileExists( $path );

		$url = PressPrimer_Certificate_Font_Cache_Service::ttf_url( $variant );
		$this->assertSame( PPCERT_PLUGIN_URL . 'fonts/playfair-display/PlayfairDisplay-Regular.ttf', $url );

		// Nothing was written to the cache.
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . PressPrimer_Certificate_Font_Cache_Service::SUBDIR;
		$this->assertSame( [], array_values( (array) glob( $dir . '/*.ttf' ) ) );
	}

	/**
	 * With no bundled TTF (the trimmed release ZIP), the .z inflates
	 * into the uploads cache byte-equal to the original, idempotently,
	 * and the URL points into uploads.
	 *
	 * @return void
	 */
	public function test_inflates_from_z_when_bundled_missing() {
		$variant = [
			'tcpdf_font' => 'playfairdisplay',
			'ttf'        => 'playfair-display/DOES-NOT-EXIST.ttf',
		];

		$path = PressPrimer_Certificate_Font_Cache_Service::ttf_path( $variant );

		$this->assertNotSame( '', $path, 'Cache path resolves' );
		$this->assertFileExists( $path );
		$this->assertStringContainsString( 'ppcert-fonts', $path );

		// Byte-equal to the real bundled TTF (the .z is the compressed original).
		$this->assertSame(
			md5_file( PPCERT_PLUGIN_DIR . 'fonts/playfair-display/PlayfairDisplay-Regular.ttf' ),
			md5_file( $path ),
			'Inflated TTF is byte-identical to the source'
		);

		// Idempotent: second call returns the same file, no rewrite.
		$mtime = filemtime( $path );
		$again = PressPrimer_Certificate_Font_Cache_Service::ttf_path( $variant );
		$this->assertSame( $path, $again );
		clearstatcache();
		$this->assertSame( $mtime, filemtime( $path ) );

		// The URL points into the uploads cache with the same basename.
		$url = PressPrimer_Certificate_Font_Cache_Service::ttf_url( $variant );
		$this->assertStringContainsString( 'ppcert-fonts/' . basename( $path ), $url );

		// The directory carries an index guard.
		$this->assertFileExists( dirname( $path ) . '/index.php' );
	}

	/**
	 * Unknown fonts and empty variants fail soft with '' - the GD
	 * warning path and the @font-face skip handle the rest.
	 *
	 * @return void
	 */
	public function test_failure_returns_empty_string() {
		$this->assertSame( '', PressPrimer_Certificate_Font_Cache_Service::ttf_path( [] ) );
		$this->assertSame(
			'',
			PressPrimer_Certificate_Font_Cache_Service::ttf_path(
				[
					'tcpdf_font' => 'nonexistentfont',
					'ttf'        => 'nope/Nope.ttf',
				]
			)
		);
		$this->assertSame(
			'',
			PressPrimer_Certificate_Font_Cache_Service::ttf_url(
				[
					'tcpdf_font' => 'nonexistentfont',
					'ttf'        => 'nope/Nope.ttf',
				]
			)
		);
	}

	/**
	 * delete_cache() removes every cached file and the directory.
	 *
	 * @return void
	 */
	public function test_delete_cache() {
		PressPrimer_Certificate_Font_Cache_Service::ttf_path(
			[
				'tcpdf_font' => 'playfairdisplay',
				'ttf'        => 'playfair-display/DOES-NOT-EXIST.ttf',
			]
		);

		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . PressPrimer_Certificate_Font_Cache_Service::SUBDIR;
		$this->assertNotSame( [], (array) glob( $dir . '/*.ttf' ) );

		PressPrimer_Certificate_Font_Cache_Service::delete_cache();
		$this->assertDirectoryDoesNotExist( $dir );
	}
}
