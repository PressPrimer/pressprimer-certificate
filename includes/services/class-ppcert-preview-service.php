<?php
/**
 * Certificate preview service
 *
 * Cached view-page preview PNGs (Feature 005 FR-003): page 1 of the
 * certificate rasterized server-side and stored in uploads at
 * ppcert/previews/{credential_id}.png, regenerated on demand if
 * missing. The eraser deletes them (Feature 008 FR-005).
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
 * Preview service class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Preview_Service {

	/**
	 * Uploads subdirectory for cached previews
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const SUBDIR = 'ppcert/previews';

	/**
	 * Preview raster resolution
	 *
	 * Screen preview, not print: 150 DPI keeps files small while staying
	 * crisp at typical view-page widths.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const DPI = 150;

	/**
	 * Absolute filesystem path for a credential's preview PNG
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential_id Credential ID (any accepted form).
	 * @return string Path, or '' for an invalid credential.
	 */
	public static function preview_path( $credential_id ) {
		$normalized = PressPrimer_Certificate_Credential_ID_Service::normalize( $credential_id );

		if ( '' === $normalized ) {
			return '';
		}

		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::SUBDIR . '/' . $normalized . '.png';
	}

	/**
	 * Public URL for a credential's preview PNG
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential_id Credential ID (any accepted form).
	 * @return string URL, or '' for an invalid credential.
	 */
	public static function preview_url( $credential_id ) {
		$normalized = PressPrimer_Certificate_Credential_ID_Service::normalize( $credential_id );

		if ( '' === $normalized ) {
			return '';
		}

		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['baseurl'] ) . self::SUBDIR . '/' . $normalized . '.png';
	}

	/**
	 * Get the cached preview URL, generating the PNG if missing
	 *
	 * Renders from the immutable layout snapshot (never the live
	 * template), so a cached preview always matches the issued artifact.
	 *
	 * @since 1.0.0
	 *
	 * @param object $certificate Hydrated certificate row.
	 * @return string|WP_Error Preview URL, or the render error.
	 */
	public static function get_or_create( $certificate ) {
		$path = self::preview_path( (string) $certificate->credential_id );

		if ( '' === $path ) {
			return new WP_Error(
				'ppcert_invalid_credential',
				__( 'Invalid credential ID.', 'pressprimer-certificate' )
			);
		}

		if ( file_exists( $path ) ) {
			return self::preview_url( (string) $certificate->credential_id );
		}

		if ( ! is_array( $certificate->layout_snapshot ) ) {
			return new WP_Error(
				'ppcert_missing_snapshot',
				__( 'The certificate has no layout snapshot.', 'pressprimer-certificate' )
			);
		}

		$renderer = new PressPrimer_Certificate_PDF_Renderer();
		$png_path = $renderer->render_png(
			$certificate->layout_snapshot,
			is_array( $certificate->merge_data ) ? $certificate->merge_data : [],
			[
				'context'       => 'preview',
				'credential_id' => (string) $certificate->credential_id,
				'dpi'           => self::DPI,
			]
		);

		if ( is_wp_error( $png_path ) ) {
			/**
			 * Fires when a view-page preview render fails.
			 *
			 * The view page falls back to a text card; this action is the
			 * logging hook (Feature 005 Edge Cases).
			 *
			 * @since 1.0.0
			 *
			 * @param WP_Error $error       The render error.
			 * @param int      $certificate_id Certificate row id.
			 */
			do_action( 'ppcert_preview_render_failed', $png_path, (int) $certificate->id );

			return $png_path;
		}

		$dir = dirname( $path );

		if ( ! wp_mkdir_p( $dir ) ) {
			wp_delete_file( $png_path );

			return new WP_Error(
				'ppcert_preview_dir_failed',
				__( 'The preview directory could not be created.', 'pressprimer-certificate' )
			);
		}

		self::ensure_index_guard( $dir );

		// Move the temp render into the cache location.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Moving a locally rendered temp file into the uploads cache.
		if ( ! rename( $png_path, $path ) ) {
			// Cross-device fallback: copy then remove the temp file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Same local move, second attempt.
			if ( ! copy( $png_path, $path ) ) {
				wp_delete_file( $png_path );

				return new WP_Error(
					'ppcert_preview_write_failed',
					__( 'The preview image could not be written.', 'pressprimer-certificate' )
				);
			}

			wp_delete_file( $png_path );
		}

		return self::preview_url( (string) $certificate->credential_id );
	}

	/**
	 * Delete a credential's cached preview PNG
	 *
	 * Used by the privacy eraser and available to future revocation flows.
	 *
	 * @since 1.0.0
	 *
	 * @param string $credential_id Credential ID (any accepted form).
	 * @return bool Whether a file was deleted.
	 */
	public static function delete( $credential_id ) {
		$path = self::preview_path( $credential_id );

		if ( '' === $path || ! file_exists( $path ) ) {
			return false;
		}

		wp_delete_file( $path );

		return ! file_exists( $path );
	}

	/**
	 * Drop an empty index.php into the previews directory
	 *
	 * Prevents directory listing on hosts that allow it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir Directory path.
	 */
	private static function ensure_index_guard( $dir ) {
		$guard = trailingslashit( $dir ) . 'index.php';

		if ( file_exists( $guard ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing a static guard file inside uploads.
		file_put_contents( $guard, "<?php\n// Silence is golden.\n" );
	}
}
