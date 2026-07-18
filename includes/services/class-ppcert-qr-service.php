<?php
/**
 * QR service
 *
 * Generates QR codes for certificate verification URLs.
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
 * QR service class
 *
 * Wraps the TCPDF bundled QR encoder (ADR 004) - no other class touches
 * the barcode API. One encoder feeds both the PDF renderer (module matrix
 * drawn as vector rectangles) and the canvas preview (PNG rasterized from
 * the same matrix), so canvas-vs-PDF QR parity holds by construction.
 *
 * Error correction level M and the 4-module quiet zone are fixed by the
 * layout schema (layout-schema.md) - constants here, never options. QR
 * content is always the verification URL for the certificate's credential
 * ID; on the canvas and in template previews it encodes a sample URL.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_QR_Service {

	/**
	 * Error correction level (fixed per layout-schema.md)
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ECC_LEVEL = 'M';

	/**
	 * Quiet zone in modules on every side (fixed per layout-schema.md)
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const QUIET_ZONE_MODULES = 4;

	/**
	 * Generate the renderable QR output for a URL
	 *
	 * Returns the module matrix and its metadata - the input the PDF
	 * pipeline consumes (each dark module drawn as a vector rectangle;
	 * the quiet zone is rendering whitespace around the matrix).
	 *
	 * @since 1.0.0
	 *
	 * @param string $url  The URL to encode (a verification URL).
	 * @param array  $opts Reserved for future options; none in 1.0.
	 * @return array|WP_Error {
	 *     @type array  $matrix        Rows of 0/1 module values (no quiet zone).
	 *     @type int    $modules       Modules per side.
	 *     @type int    $total_modules Modules per side including both quiet zones.
	 *     @type string $ecc           Error correction level ('M').
	 *     @type int    $quiet_zone    Quiet zone width in modules (4).
	 * }
	 */
	public static function generate( $url, $opts = [] ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return new WP_Error(
				'ppcert_qr_empty_url',
				__( 'Cannot generate a QR code for an empty URL.', 'pressprimer-certificate' )
			);
		}

		// The bundled encoder's mask selection samples candidate masks via
		// the global RNG (QR_FIND_FROM_RANDOM default; its constants sit
		// behind a single QRCODEDEFS sentinel, so they cannot be cleanly
		// overridden). Identical input MUST produce an identical matrix
		// across requests - the canvas preview and the PDF are encoded
		// separately and compared pixel-for-pixel by the parity suite - so
		// the mask candidates are pinned by seeding the RNG from the URL,
		// then reseeding from entropy afterward. rand() is only called
		// from mask selection in the encoder (verified against 6.11.3;
		// see ADR 004).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Deliberate: pins the third-party encoder's internal rand() calls; wp_rand() is not involved.
		mt_srand( crc32( 'ppcert_qr_' . $url ) );

		try {
			$barcode = new TCPDF2DBarcode( $url, 'QRCODE,' . self::ECC_LEVEL );
			$data    = $barcode->getBarcodeArray();
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- Restores entropy after the pinned encode.
			mt_srand();
		}

		if ( ! is_array( $data ) || empty( $data['bcode'] ) || empty( $data['num_rows'] ) ) {
			return new WP_Error(
				'ppcert_qr_encode_failed',
				__( 'QR code generation failed for the given URL.', 'pressprimer-certificate' )
			);
		}

		$matrix = [];
		foreach ( $data['bcode'] as $row ) {
			$matrix[] = array_map( 'intval', $row );
		}

		return [
			'matrix'        => $matrix,
			'modules'       => (int) $data['num_rows'],
			'total_modules' => (int) $data['num_rows'] + ( 2 * self::QUIET_ZONE_MODULES ),
			'ecc'           => self::ECC_LEVEL,
			'quiet_zone'    => self::QUIET_ZONE_MODULES,
		];
	}

	/**
	 * Generate a PNG rendering of the QR code
	 *
	 * Rasterizes the matrix with GD at an integer module scale (crisp
	 * edges, no resampling), quiet zone included. Used by the canvas
	 * preview and anywhere a raster QR is needed; the PDF path uses
	 * generate() and draws vectors instead.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url  The URL to encode.
	 * @param array  $opts {
	 *     @type int    $size        Minimum output size in pixels (default 300).
	 *                               Actual size rounds up to a whole-module multiple.
	 *     @type string $dark_color  Hex color for dark modules (default #000000).
	 *     @type string $light_color Hex color for light modules; '' = transparent
	 *                               (default '').
	 * }
	 * @return string|WP_Error Binary PNG data.
	 */
	public static function generate_png( $url, $opts = [] ) {
		$qr = self::generate( $url, $opts );

		if ( is_wp_error( $qr ) ) {
			return $qr;
		}

		$size        = isset( $opts['size'] ) ? absint( $opts['size'] ) : 300;
		$size        = max( $qr['total_modules'], $size );
		$dark_color  = isset( $opts['dark_color'] ) ? (string) $opts['dark_color'] : '#000000';
		$light_color = isset( $opts['light_color'] ) ? (string) $opts['light_color'] : '';

		// Integer pixels per module so edges land on pixel boundaries.
		$scale  = (int) ceil( $size / $qr['total_modules'] );
		$pixels = $scale * $qr['total_modules'];

		$image = imagecreatetruecolor( $pixels, $pixels );

		if ( false === $image ) {
			return new WP_Error(
				'ppcert_qr_gd_failed',
				__( 'QR image creation failed (GD).', 'pressprimer-certificate' )
			);
		}

		$dark_rgb = self::hex_to_rgb( $dark_color, [ 0, 0, 0 ] );
		$dark     = imagecolorallocate( $image, $dark_rgb[0], $dark_rgb[1], $dark_rgb[2] );

		if ( '' === $light_color ) {
			// Transparent light modules and quiet zone.
			imagealphablending( $image, false );
			imagesavealpha( $image, true );
			$light = imagecolorallocatealpha( $image, 255, 255, 255, 127 );
		} else {
			$light_rgb = self::hex_to_rgb( $light_color, [ 255, 255, 255 ] );
			$light     = imagecolorallocate( $image, $light_rgb[0], $light_rgb[1], $light_rgb[2] );
		}

		imagefilledrectangle( $image, 0, 0, $pixels - 1, $pixels - 1, $light );

		$offset = self::QUIET_ZONE_MODULES * $scale;

		foreach ( $qr['matrix'] as $row_index => $row ) {
			foreach ( $row as $col_index => $module ) {
				if ( 1 !== $module ) {
					continue;
				}

				$x = $offset + ( $col_index * $scale );
				$y = $offset + ( $row_index * $scale );

				imagefilledrectangle( $image, $x, $y, $x + $scale - 1, $y + $scale - 1, $dark );
			}
		}

		ob_start();
		imagepng( $image );
		$png = ob_get_clean();
		imagedestroy( $image );

		if ( ! is_string( $png ) || '' === $png ) {
			return new WP_Error(
				'ppcert_qr_png_failed',
				__( 'QR PNG encoding failed.', 'pressprimer-certificate' )
			);
		}

		return $png;
	}

	/**
	 * Parse a hex color into an RGB triple
	 *
	 * @since 1.0.0
	 *
	 * @param string $hex      Hex color (#rgb or #rrggbb).
	 * @param int[]  $fallback RGB triple used when the hex is invalid.
	 * @return int[] [ r, g, b ].
	 */
	private static function hex_to_rgb( $hex, $fallback ) {
		$sanitized = sanitize_hex_color( $hex );

		if ( empty( $sanitized ) ) {
			return $fallback;
		}

		$sanitized = ltrim( $sanitized, '#' );

		if ( 3 === strlen( $sanitized ) ) {
			$sanitized = $sanitized[0] . $sanitized[0] . $sanitized[1] . $sanitized[1] . $sanitized[2] . $sanitized[2];
		}

		return [
			(int) hexdec( substr( $sanitized, 0, 2 ) ),
			(int) hexdec( substr( $sanitized, 2, 2 ) ),
			(int) hexdec( substr( $sanitized, 4, 2 ) ),
		];
	}
}
