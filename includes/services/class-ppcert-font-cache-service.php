<?php
/**
 * Font cache service
 *
 * Resolves source-TTF paths and URLs for the two consumers that need
 * real TTF bytes at runtime: the designer's @font-face CSS (browser
 * rendering) and the GD raster path (view-page previews on hosts
 * without Imagick).
 *
 * @package PressPrimer_Certificate
 * @subpackage Services
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Font cache service class
 *
 * The release ZIP does not ship the source TTF families - each
 * converted TCPDF font's .z file IS the zlib-compressed original TTF,
 * so shipping both would double the payload. Resolution order:
 *
 * 1. Bundled file (`fonts/<family>/<file>.ttf`) when present - git
 *    checkouts, dev environments, and the test/parity harness are
 *    byte-for-byte unchanged by this service.
 * 2. Inflate-on-demand cache: the .z inflates once into
 *    `uploads/ppcert-fonts/` (atomic temp-then-rename write, TTF magic
 *    verified, size-stamped filename so a plugin update with changed
 *    fonts regenerates automatically).
 *
 * Failure never fatals: an unwritable uploads directory returns '',
 * which the GD path already treats as its font_file_missing warning
 * and the @font-face builder treats as "skip this face".
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Font_Cache_Service {

	/**
	 * Uploads subdirectory for inflated fonts.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const SUBDIR = 'ppcert-fonts';

	/**
	 * Absolute TTF path for a manifest variant definition
	 *
	 * @since 1.0.0
	 *
	 * @param array $variant Manifest variant (keys: ttf, tcpdf_font).
	 * @return string Absolute path, or '' when unavailable.
	 */
	public static function ttf_path( array $variant ) {
		$rel = isset( $variant['ttf'] ) ? (string) $variant['ttf'] : '';

		if ( '' === $rel ) {
			return '';
		}

		$bundled = PPCERT_PLUGIN_DIR . 'fonts/' . $rel;

		if ( file_exists( $bundled ) ) {
			return $bundled;
		}

		return self::ensure_cached( $variant );
	}

	/**
	 * Public TTF URL for a manifest variant definition
	 *
	 * @since 1.0.0
	 *
	 * @param array $variant Manifest variant (keys: ttf, tcpdf_font).
	 * @return string URL, or '' when unavailable.
	 */
	public static function ttf_url( array $variant ) {
		$rel = isset( $variant['ttf'] ) ? (string) $variant['ttf'] : '';

		if ( '' === $rel ) {
			return '';
		}

		if ( file_exists( PPCERT_PLUGIN_DIR . 'fonts/' . $rel ) ) {
			return PPCERT_PLUGIN_URL . 'fonts/' . ltrim( $rel, '/' );
		}

		$path = self::ensure_cached( $variant );

		if ( '' === $path ) {
			return '';
		}

		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['baseurl'] ) . self::SUBDIR . '/' . basename( $path );
	}

	/**
	 * Inflate a variant's .z into the uploads cache (idempotent)
	 *
	 * @since 1.0.0
	 *
	 * @param array $variant Manifest variant definition.
	 * @return string Cached TTF path, or '' on any failure.
	 */
	private static function ensure_cached( array $variant ) {
		$key = isset( $variant['tcpdf_font'] ) ? sanitize_key( $variant['tcpdf_font'] ) : '';

		if ( '' === $key ) {
			return '';
		}

		$z_path = PPCERT_PLUGIN_DIR . 'fonts/tcpdf/' . $key . '.z';

		if ( ! file_exists( $z_path ) ) {
			return '';
		}

		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return '';
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::SUBDIR;

		// Size-stamped name: a plugin update that changes the font
		// produces a different .z size, so the cache self-invalidates.
		$target = $dir . '/' . $key . '-' . (int) filesize( $z_path ) . '.ttf';

		if ( file_exists( $target ) && filesize( $target ) > 0 ) {
			return $target;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		$guard = $dir . '/index.php';

		if ( ! file_exists( $guard ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a static guard file inside uploads.
			file_put_contents( $guard, "<?php\n// Silence is golden.\n" );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file, never remote.
		$compressed = file_get_contents( $z_path );

		if ( false === $compressed ) {
			return '';
		}

		// TCPDF's converter stores the zlib-compressed original font
		// program; @ because a corrupt file returns false (handled).
		$ttf = @gzuncompress( $compressed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- False return is the handled failure signal.

		if ( false === $ttf || ! self::looks_like_font( $ttf ) ) {
			return '';
		}

		// Sweep stale size-stamps for this font before the atomic write.
		foreach ( (array) glob( $dir . '/' . $key . '-*.ttf' ) as $stale ) {
			wp_delete_file( $stale );
		}

		$temp = $target . '.' . wp_generate_password( 8, false, false ) . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic cache write inside uploads; temp-then-rename.
		if ( false === file_put_contents( $temp, $ttf ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-directory promotion of the temp write.
		if ( ! rename( $temp, $target ) ) {
			wp_delete_file( $temp );
			return '';
		}

		return $target;
	}

	/**
	 * Whether inflated bytes carry a TrueType/OpenType signature
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes Inflated font program.
	 * @return bool
	 */
	private static function looks_like_font( $bytes ) {
		$magic = substr( (string) $bytes, 0, 4 );

		return "\x00\x01\x00\x00" === $magic || 'OTTO' === $magic || 'true' === $magic;
	}

	/**
	 * Remove the cache directory (uninstall data removal)
	 *
	 * @since 1.0.0
	 */
	public static function delete_cache() {
		$uploads = wp_upload_dir();

		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::SUBDIR;

		if ( ! is_dir( $dir ) ) {
			return;
		}

		foreach ( (array) glob( $dir . '/*' ) as $file ) {
			wp_delete_file( $file );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removing the emptied plugin cache directory.
		rmdir( $dir );
	}
}
