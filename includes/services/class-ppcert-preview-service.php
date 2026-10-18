<?php
/**
 * Certificate preview service
 *
 * Cached view-page preview PNGs (Feature 005 FR-003): page 1 of the
 * certificate rasterized server-side and stored in uploads at
 * ppcert/previews/{credential_id}-{secret}.png, regenerated on demand if
 * missing. The eraser deletes them (Feature 008 FR-005).
 *
 * Since 2.1.0 the file name carries a secret derived from the
 * certificate's UUID (Feature 2.1-004 FR-009, Decision 006): a pattern
 * credential ID can be guessable, and the static image URL must not be.
 * 2.0 files ({credential_id}.png) are replaced on the next view.
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
	 * Subscribe preview cleanup to the certificate lifecycle
	 *
	 * The cached PNG lives at a static uploads URL. Revocation must take it offline with the PDF (the
	 * download route 410s, and the preview may not outlive it), and
	 * permanent deletion must leave nothing behind. Reinstatement needs
	 * no handler - the next view-page load regenerates via
	 * get_or_create().
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'ppcert_certificate_revoked', [ __CLASS__, 'delete_for_certificate' ] );
		add_action( 'ppcert_certificate_deleted', [ __CLASS__, 'delete_for_credential' ], 10, 2 );
	}

	/**
	 * Delete the cached preview for a certificate row id (revocation)
	 *
	 * @since 1.0.0
	 *
	 * @param int $certificate_id Certificate row id.
	 */
	public static function delete_for_certificate( $certificate_id ) {
		$certificate = PressPrimer_Certificate_Certificate::get( absint( $certificate_id ) );

		if ( $certificate ) {
			self::delete( (string) $certificate->credential_id );
		}
	}

	/**
	 * Delete the cached preview for a credential ID (permanent deletion)
	 *
	 * The row is already gone when ppcert_certificate_deleted fires;
	 * the hook passes the credential ID for exactly this reason.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $certificate_id Deleted row id (unused).
	 * @param string $credential_id  Credential ID in stored form.
	 */
	public static function delete_for_credential( $certificate_id, $credential_id = '' ) {
		if ( '' !== (string) $credential_id ) {
			self::delete( (string) $credential_id );
		}
	}

	/**
	 * Absolute filesystem path for a credential's preview PNG
	 *
	 * @since 1.0.0
	 * @since 2.1.0 The $uuid argument; without it, the 2.0 file name.
	 *
	 * @param string $credential_id Credential ID (any accepted form).
	 * @param string $uuid          The certificate's UUID.
	 * @return string Path, or '' for an invalid credential.
	 */
	public static function preview_path( $credential_id, $uuid = '' ) {
		$file = self::file_name( $credential_id, $uuid );

		if ( '' === $file ) {
			return '';
		}

		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::SUBDIR . '/' . $file;
	}

	/**
	 * Public URL for a credential's preview PNG
	 *
	 * @since 1.0.0
	 * @since 2.1.0 The $uuid argument; without it, the 2.0 file name.
	 *
	 * @param string $credential_id Credential ID (any accepted form).
	 * @param string $uuid          The certificate's UUID.
	 * @return string URL, or '' for an invalid credential.
	 */
	public static function preview_url( $credential_id, $uuid = '' ) {
		$file = self::file_name( $credential_id, $uuid );

		if ( '' === $file ) {
			return '';
		}

		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['baseurl'] ) . self::SUBDIR . '/' . $file;
	}

	/**
	 * The preview file name: `{normalized}-{first 12 of wp_hash( uuid )}.png`
	 *
	 * The hash is salted, so the name cannot be derived from the
	 * credential ID. Without a UUID, the 2.0 name `{normalized}.png`.
	 *
	 * @since 2.1.0
	 *
	 * @param string $credential_id Credential ID (any accepted form).
	 * @param string $uuid          The certificate's UUID.
	 * @return string File name, or '' for an invalid credential.
	 */
	private static function file_name( $credential_id, $uuid ) {
		$normalized = self::safe_credential( $credential_id );

		if ( '' === $normalized ) {
			return '';
		}

		if ( '' === (string) $uuid ) {
			return $normalized . '.png';
		}

		return $normalized . '-' . substr( wp_hash( (string) $uuid ), 0, 12 ) . '.png';
	}

	/**
	 * A normalized credential safe to use in a file name and a glob
	 *
	 * @since 2.1.0
	 *
	 * @param string $credential_id Credential ID (any accepted form).
	 * @return string Normalized ID, or '' when it holds anything but [0-9A-Z].
	 */
	private static function safe_credential( $credential_id ) {
		$normalized = PressPrimer_Certificate_Credential_ID_Service::normalize( (string) $credential_id );

		return preg_match( '/^[0-9A-Z]{1,32}$/', $normalized ) ? $normalized : '';
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
		$uuid = isset( $certificate->uuid ) ? (string) $certificate->uuid : '';
		$path = self::preview_path( (string) $certificate->credential_id, $uuid );

		if ( '' === $path ) {
			return new WP_Error(
				'ppcert_invalid_credential',
				__( 'Invalid credential ID.', 'pressprimer-certificate' )
			);
		}

		if ( file_exists( $path ) ) {
			return self::preview_url( (string) $certificate->credential_id, $uuid );
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

		// A 2.0 name (or one hashed under an older salt) goes before the
		// new file lands.
		self::delete( (string) $certificate->credential_id );

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

		return self::preview_url( (string) $certificate->credential_id, $uuid );
	}

	/**
	 * Delete a credential's cached preview PNGs
	 *
	 * Every name the credential's preview may have: the 2.0 name and any
	 * hashed name (the UUID is not needed, so the deleted-row hook can
	 * call this). Used by revocation, deletion, and the privacy eraser.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Deletes hashed names too.
	 *
	 * @param string $credential_id Credential ID (any accepted form).
	 * @return bool Whether a file was deleted.
	 */
	public static function delete( $credential_id ) {
		$normalized = self::safe_credential( $credential_id );

		if ( '' === $normalized ) {
			return false;
		}

		$legacy = self::preview_path( $normalized );
		$paths  = glob( dirname( $legacy ) . '/' . $normalized . '-*.png' );
		$paths  = is_array( $paths ) ? $paths : [];

		$paths[] = $legacy;
		$deleted = false;

		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
				$deleted = $deleted || ! file_exists( $path );
			}
		}

		return $deleted;
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
