<?php
/**
 * PDF renderer
 *
 * The server-side render path: layout JSON in, print-quality PDF out.
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
 * PDF renderer class
 *
 * The ONLY class that instantiates TCPDF (Feature 007 TR-001 /
 * CODE-STRUCTURE rule 4). Walks a validator-clean layout document
 * coordinate by coordinate in z order - no HTML-to-PDF path exists and
 * writeHTML is prohibited in this codebase (text mode only, Security
 * Requirements).
 *
 * The renderer trusts the validator: documents are assumed
 * validator-clean and structural violations hard-fail rather than guess
 * (FR-001). Per-element failures that are content problems - a missing
 * attachment, an unbundled font variant - degrade gracefully with a
 * render warning instead (Edge Cases).
 *
 * QR elements render via the QR service in Prompt 2.4; until then they
 * are skipped with a warning. render_png() also arrives in 2.4.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_PDF_Renderer {

	/**
	 * Render warnings collected during the last render
	 *
	 * Callers note these in issuance/preview event meta.
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private $warnings = [];

	/**
	 * Cached font manifest
	 *
	 * @since 1.0.0
	 * @var array|null
	 */
	private static $manifest = null;

	/**
	 * Render a layout document to a PDF temp file
	 *
	 * @since 1.0.0
	 *
	 * @param array $layout     Validator-clean layout document.
	 * @param array $merge_data Resolved merge data (token => value).
	 * @param array $args {
	 *     Render arguments.
	 *
	 *     @type string $context        'download' | 'email' | 'preview' | 'parity'.
	 *     @type string $credential_id  Credential ID (QR content, Prompt 2.4).
	 *     @type int    $certificate_id Certificate row id when rendering an
	 *                                  issued certificate; 0 otherwise.
	 *     @type string $title          Document title (template title).
	 *     @type string $recipient_name Recipient display name for metadata.
	 * }
	 * @return string|WP_Error Absolute path to the PDF temp file. The caller
	 *                         owns the file (stream/attach, then delete).
	 */
	public function render_pdf( array $layout, array $merge_data, array $args = [] ) {
		$this->warnings = [];

		$structure = $this->verify_structure( $layout );

		if ( is_wp_error( $structure ) ) {
			return $structure;
		}

		$context   = isset( $args['context'] ) ? (string) $args['context'] : 'download';
		$temp_path = wp_tempnam( 'ppcert-render' );

		if ( ! $temp_path ) {
			return new WP_Error(
				'ppcert_render_tempfile',
				__( 'Could not create a temporary file for rendering.', 'pressprimer-certificate' )
			);
		}

		try {
			$pdf = $this->create_document( $layout, $args );

			$this->render_background( $pdf, $layout );

			foreach ( $layout['elements'] as $element ) {
				$this->render_element( $pdf, $element, $merge_data, $layout, $args );
			}

			$pdf->Output( $temp_path, 'F' );
		} catch ( \Throwable $e ) {
			if ( file_exists( $temp_path ) ) {
				wp_delete_file( $temp_path );
			}

			return new WP_Error(
				'ppcert_render_failed',
				__( 'PDF rendering failed.', 'pressprimer-certificate' )
			);
		}

		$certificate_id = isset( $args['certificate_id'] ) ? absint( $args['certificate_id'] ) : 0;

		/** This action is documented in docs/architecture/HOOKS.md */
		do_action( 'ppcert_pdf_generated', $temp_path, $certificate_id, $context );

		return $temp_path;
	}

	/**
	 * Render warnings from the most recent render
	 *
	 * @since 1.0.0
	 *
	 * @return array Entries of [ 'element' => id, 'warning' => slug ].
	 */
	public function get_last_render_warnings() {
		return $this->warnings;
	}

	/*
	 * ------------------------------------------------------------------
	 * Document setup.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Hard structural verification (the renderer never guesses)
	 *
	 * @since 1.0.0
	 *
	 * @param array $layout Layout document.
	 * @return true|WP_Error
	 */
	private function verify_structure( $layout ) {
		$valid = isset( $layout['layout_schema_version'] )
			&& 1 === (int) $layout['layout_schema_version']
			&& isset( $layout['page']['width'], $layout['page']['height'] )
			&& is_numeric( $layout['page']['width'] )
			&& is_numeric( $layout['page']['height'] )
			&& isset( $layout['elements'] )
			&& is_array( $layout['elements'] );

		if ( ! $valid ) {
			return new WP_Error(
				'ppcert_render_invalid_layout',
				__( 'The layout document is not validator-clean; refusing to render.', 'pressprimer-certificate' )
			);
		}

		return true;
	}

	/**
	 * Create the TCPDF document for a layout page
	 *
	 * @since 1.0.0
	 *
	 * @param array $layout Layout document.
	 * @param array $args   Render arguments.
	 * @return TCPDF
	 */
	private function create_document( $layout, $args ) {
		$width       = (float) $layout['page']['width'];
		$height      = (float) $layout['page']['height'];
		$orientation = $width >= $height ? 'L' : 'P';

		$pdf = new TCPDF( $orientation, 'pt', [ $width, $height ], true, 'UTF-8', false );

		// Document metadata (FR-006).
		$title     = isset( $args['title'] ) ? (string) $args['title'] : __( 'Certificate', 'pressprimer-certificate' );
		$recipient = isset( $args['recipient_name'] ) ? (string) $args['recipient_name'] : '';

		$pdf->SetCreator( 'PressPrimer Certificate' );
		$pdf->SetTitle( '' !== $recipient ? $title . ' - ' . $recipient : $title );

		// Exact-size page: no margins, headers, footers, or auto page breaks.
		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );
		$pdf->SetMargins( 0, 0, 0 );
		$pdf->SetAutoPageBreak( false );
		$pdf->AddPage( $orientation, [ $width, $height ] );

		return $pdf;
	}

	/**
	 * Render the root background: fill color, then full-bleed cover image
	 *
	 * @since 1.0.0
	 *
	 * @param TCPDF $pdf    Document.
	 * @param array $layout Layout document.
	 */
	private function render_background( $pdf, $layout ) {
		$width  = (float) $layout['page']['width'];
		$height = (float) $layout['page']['height'];
		$color  = isset( $layout['background']['color'] ) ? (string) $layout['background']['color'] : '#ffffff';

		$pdf->SetFillColorArray( self::hex_to_rgb( $color, [ 255, 255, 255 ] ) );
		$pdf->Rect( 0, 0, $width, $height, 'F' );

		$attachment_id = isset( $layout['background']['attachment_id'] ) ? absint( $layout['background']['attachment_id'] ) : 0;

		if ( $attachment_id > 0 ) {
			$this->place_attachment_image(
				$pdf,
				$attachment_id,
				0,
				0,
				$width,
				$height,
				'cover',
				1.0,
				'background'
			);
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Element dispatch.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Render one element
	 *
	 * @since 1.0.0
	 *
	 * @param TCPDF $pdf        Document.
	 * @param array $element    Clean element.
	 * @param array $merge_data Resolved merge data.
	 * @param array $layout     Layout document (for page context).
	 * @param array $args       Render arguments.
	 */
	private function render_element( $pdf, $element, $merge_data, $layout, $args ) {
		switch ( $element['type'] ) {
			case 'text':
				$this->render_text( $pdf, $element, (string) $element['props']['content'] );
				break;

			case 'merge_field':
				$token = trim( str_replace( [ '{{', '}}' ], '', (string) $element['props']['token'] ) );
				$value = isset( $merge_data[ $token ] ) && is_scalar( $merge_data[ $token ] )
					? (string) $merge_data[ $token ]
					: '';
				$this->render_text( $pdf, $element, $value );
				break;

			case 'image':
			case 'signature':
				$this->place_attachment_image(
					$pdf,
					absint( $element['props']['attachment_id'] ),
					(float) $element['x'],
					(float) $element['y'],
					(float) $element['w'],
					(float) $element['h'],
					(string) $element['props']['fit'],
					(float) $element['props']['opacity'],
					$element['id']
				);
				break;

			case 'shape':
				$this->render_shape( $pdf, $element );
				break;

			case 'qr':
				// Prompt 2.4 wires the QR service into the renderer.
				$this->warn( $element['id'], 'qr_pending_2_4' );
				break;
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Text (shared by text and merge_field).
	 * ------------------------------------------------------------------
	 */

	/**
	 * Render a text box with the FR-004 fitting rule
	 *
	 * Text mode only - merge values are data, never markup.
	 *
	 * @since 1.0.0
	 *
	 * @param TCPDF  $pdf     Document.
	 * @param array  $element Clean text/merge_field element.
	 * @param string $text    The literal text to render.
	 */
	private function render_text( $pdf, $element, $text ) {
		if ( '' === $text ) {
			return;
		}

		$props       = $element['props'];
		$box_w       = (float) $element['w'];
		$box_h       = (float) $element['h'];
		$font_size   = (float) $props['font_size'];
		$line_height = (float) $props['line_height'];

		$font = $this->resolve_font(
			(string) $props['font_family'],
			! empty( $props['bold'] ),
			! empty( $props['italic'] ),
			$element['id']
		);

		$pdf->setCellHeightRatio( $line_height );
		$pdf->SetTextColorArray( self::hex_to_rgb( (string) $props['color'], [ 0, 0, 0 ] ) );

		// FR-004 fitting with real TCPDF line measurement.
		$measure = function ( $candidate_text, $candidate_size ) use ( $pdf, $font, $box_w ) {
			$pdf->SetFont( $font['key'], '', $candidate_size, $font['file'] );
			return (int) $pdf->getNumLines( $candidate_text, $box_w );
		};

		$thresholds = self::fitting_thresholds();
		$fitted     = self::fit_text( $text, $box_w, $box_h, $font_size, $line_height, $measure, $thresholds );

		if ( $fitted['truncated'] ) {
			$this->warn( $element['id'], 'text_truncated' );
		}

		$pdf->SetFont( $font['key'], '', $fitted['size'], $font['file'] );

		$align_map = [
			'left'   => 'L',
			'center' => 'C',
			'right'  => 'R',
		];
		$align     = isset( $align_map[ $props['align'] ] ) ? $align_map[ $props['align'] ] : 'L';

		$pdf->MultiCell(
			$box_w,
			$box_h,
			$fitted['text'],
			0,          // No border.
			$align,
			false,      // No fill.
			1,          // Move below.
			(float) $element['x'],
			(float) $element['y'],
			true,       // Reset height.
			0,          // No stretch.
			false,      // Not HTML - text mode only, always.
			false,      // No autopadding: exact box positioning.
			$box_h,     // Max height.
			'T',        // Top vertical alignment.
			false       // No fitcell (fitting is ours, FR-004).
		);
	}

	/**
	 * The FR-004 fitting rule (pure; unit-tested against shared fixtures)
	 *
	 * Shrink in shrink_step_pt steps down to min_scale of the specified
	 * size; if still overflowing, truncate with an ellipsis. The canvas
	 * implements this identical rule from the same manifest thresholds.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $text        Text to fit.
	 * @param float    $box_w       Box width (pt).
	 * @param float    $box_h       Box height (pt).
	 * @param float    $font_size   Specified font size (pt).
	 * @param float    $line_height Line height multiplier.
	 * @param callable $measure     fn( string $text, float $size ): int line count.
	 * @param array    $thresholds  [ 'shrink_step_pt' => float, 'min_scale' => float ].
	 * @return array [ 'size' => float, 'text' => string, 'truncated' => bool ].
	 */
	public static function fit_text( $text, $box_w, $box_h, $font_size, $line_height, $measure, $thresholds ) {
		$step  = (float) $thresholds['shrink_step_pt'];
		$floor = $font_size * (float) $thresholds['min_scale'];
		$size  = $font_size;

		while ( true ) {
			$lines  = max( 1, (int) call_user_func( $measure, $text, $size ) );
			$height = $lines * $size * $line_height;

			if ( $height <= $box_h ) {
				return [
					'size'      => $size,
					'text'      => $text,
					'truncated' => false,
				];
			}

			if ( $size - $step < $floor ) {
				break;
			}

			$size -= $step;
		}

		// At the floor: truncate with ellipsis until it fits.
		$max_lines = max( 1, (int) floor( $box_h / ( $size * $line_height ) ) );
		$kept      = $text;

		while ( '' !== $kept ) {
			$lines = max( 1, (int) call_user_func( $measure, $kept . "\u{2026}", $size ) );

			if ( $lines <= $max_lines ) {
				break;
			}

			$kept = function_exists( 'mb_substr' )
				? mb_substr( $kept, 0, max( 0, mb_strlen( $kept ) - 1 ) )
				: substr( $kept, 0, max( 0, strlen( $kept ) - 1 ) );
		}

		return [
			'size'      => $size,
			'text'      => $kept . "\u{2026}",
			'truncated' => true,
		];
	}

	/**
	 * Resolve a font family + style to a bundled TCPDF font
	 *
	 * Real variants only (parity requirement - no synthetic styling). A
	 * requested variant missing from the family falls back to regular
	 * with a warning, matching validator behavior.
	 *
	 * @since 1.0.0
	 *
	 * @param string $family_slug Font family slug.
	 * @param bool   $bold        Bold requested.
	 * @param bool   $italic      Italic requested.
	 * @param string $element_id  Element id (for warnings).
	 * @return array [ 'key' => TCPDF font key, 'file' => definition path ].
	 */
	private function resolve_font( $family_slug, $bold, $italic, $element_id ) {
		$manifest = self::manifest();
		$families = isset( $manifest['families'] ) ? $manifest['families'] : [];

		// Unknown family: use the default family (validator already
		// prevents this for stored documents).
		if ( ! isset( $families[ $family_slug ] ) ) {
			$this->warn( $element_id, 'font_family_fallback' );
			$family_slug = PressPrimer_Certificate_Layout_Validator::DEFAULT_FONT;
		}

		$variant = 'regular';
		if ( $bold && $italic ) {
			$variant = 'bold_italic';
		} elseif ( $bold ) {
			$variant = 'bold';
		} elseif ( $italic ) {
			$variant = 'italic';
		}

		$variants = isset( $families[ $family_slug ]['variants'] ) ? $families[ $family_slug ]['variants'] : [];

		if ( ! isset( $variants[ $variant ] ) ) {
			if ( 'regular' !== $variant ) {
				$this->warn( $element_id, 'font_variant_fallback' );
			}
			$variant = 'regular';
		}

		$font_key = isset( $variants[ $variant ]['tcpdf_font'] ) ? (string) $variants[ $variant ]['tcpdf_font'] : '';

		return [
			'key'  => $font_key,
			'file' => PPCERT_PLUGIN_DIR . 'fonts/tcpdf/' . $font_key . '.php',
		];
	}

	/*
	 * ------------------------------------------------------------------
	 * Images.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Place an attachment image into a box per fit mode
	 *
	 * Local files only: attachment IDs resolve via get_attached_file()
	 * with the MIME re-checked (Security Requirements). A missing or
	 * non-image attachment skips the element with a warning - never a
	 * fatal (Edge Cases).
	 *
	 * @since 1.0.0
	 *
	 * @param TCPDF  $pdf           Document.
	 * @param int    $attachment_id Attachment id.
	 * @param float  $box_x         Box X (pt).
	 * @param float  $box_y         Box Y (pt).
	 * @param float  $box_w         Box width (pt).
	 * @param float  $box_h         Box height (pt).
	 * @param string $fit           'contain' | 'cover' | 'stretch'.
	 * @param float  $opacity       0-1.
	 * @param string $element_id    Element id (for warnings).
	 */
	private function place_attachment_image( $pdf, $attachment_id, $box_x, $box_y, $box_w, $box_h, $fit, $opacity, $element_id ) {
		$file = $attachment_id > 0 ? get_attached_file( $attachment_id ) : false;

		if ( ! $file || ! file_exists( $file ) ) {
			$this->warn( $element_id, 'attachment_missing' );
			return;
		}

		$filetype = wp_check_filetype( $file );
		$size     = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Non-image files return false; handled below.

		$allowed = [ 'image/jpeg', 'image/png', 'image/gif' ];

		if ( false === $size || empty( $filetype['type'] ) || ! in_array( $filetype['type'], $allowed, true ) ) {
			$this->warn( $element_id, 'attachment_not_image' );
			return;
		}

		$placement = self::fit_box( (float) $size[0], (float) $size[1], $box_w, $box_h, $fit );

		if ( $opacity < 1 ) {
			$pdf->SetAlpha( $opacity );
		}

		$crops = 'cover' === $fit && ( $placement['dx'] < 0 || $placement['dy'] < 0 );

		if ( $crops ) {
			$pdf->StartTransform();
			$pdf->Rect( $box_x, $box_y, $box_w, $box_h, 'CNZ' );
		}

		$pdf->Image(
			$file,
			$box_x + $placement['dx'],
			$box_y + $placement['dy'],
			$placement['dw'],
			$placement['dh'],
			'',
			'',
			'',
			false,
			300
		);

		if ( $crops ) {
			$pdf->StopTransform();
		}

		if ( $opacity < 1 ) {
			$pdf->SetAlpha( 1 );
		}
	}

	/**
	 * The fit-mode aspect math (pure; shared fixtures with the canvas)
	 *
	 * Returns the draw rectangle relative to the element box. The JS
	 * canvas implements identical math against the same fixture file
	 * (tests/fixtures/fit-modes.json).
	 *
	 * @since 1.0.0
	 *
	 * @param float  $image_w Image intrinsic width.
	 * @param float  $image_h Image intrinsic height.
	 * @param float  $box_w   Box width.
	 * @param float  $box_h   Box height.
	 * @param string $fit     'contain' | 'cover' | 'stretch'.
	 * @return array [ 'dx' => float, 'dy' => float, 'dw' => float, 'dh' => float ].
	 */
	public static function fit_box( $image_w, $image_h, $box_w, $box_h, $fit ) {
		if ( 'stretch' === $fit || $image_w <= 0 || $image_h <= 0 ) {
			return [
				'dx' => 0.0,
				'dy' => 0.0,
				'dw' => $box_w,
				'dh' => $box_h,
			];
		}

		$scale_contain = min( $box_w / $image_w, $box_h / $image_h );
		$scale_cover   = max( $box_w / $image_w, $box_h / $image_h );
		$scale         = 'cover' === $fit ? $scale_cover : $scale_contain;

		$dw = $image_w * $scale;
		$dh = $image_h * $scale;

		return [
			'dx' => ( $box_w - $dw ) / 2,
			'dy' => ( $box_h - $dh ) / 2,
			'dw' => $dw,
			'dh' => $dh,
		];
	}

	/*
	 * ------------------------------------------------------------------
	 * Shapes.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Render a shape element (line / rect / ellipse)
	 *
	 * @since 1.0.0
	 *
	 * @param TCPDF $pdf     Document.
	 * @param array $element Clean shape element.
	 */
	private function render_shape( $pdf, $element ) {
		$props        = $element['props'];
		$x            = (float) $element['x'];
		$y            = (float) $element['y'];
		$w            = (float) $element['w'];
		$h            = (float) $element['h'];
		$stroke_width = (float) $props['stroke_width'];
		$has_stroke   = $stroke_width > 0;
		$has_fill     = '' !== $props['fill_color'];

		if ( $has_stroke ) {
			$pdf->SetLineWidth( $stroke_width );
			$pdf->SetDrawColorArray( self::hex_to_rgb( (string) $props['stroke_color'], [ 0, 0, 0 ] ) );
		}

		if ( $has_fill ) {
			$pdf->SetFillColorArray( self::hex_to_rgb( (string) $props['fill_color'], [ 255, 255, 255 ] ) );
		}

		$style = $has_stroke && $has_fill ? 'DF' : ( $has_fill ? 'F' : 'D' );

		switch ( $props['shape'] ) {
			case 'line':
				if ( $has_stroke ) {
					// The bounding box defines the endpoints: top-left to
					// bottom-right (layout-schema.md).
					$pdf->Line( $x, $y, $x + $w, $y + $h );
				}
				break;

			case 'ellipse':
				if ( $has_stroke || $has_fill ) {
					$pdf->Ellipse( $x + ( $w / 2 ), $y + ( $h / 2 ), $w / 2, $h / 2, 0, 0, 360, $style );
				}
				break;

			case 'rect':
			default:
				if ( ! $has_stroke && ! $has_fill ) {
					break;
				}

				$radius = (float) $props['radius'];

				if ( $radius > 0 ) {
					$pdf->RoundedRect( $x, $y, $w, $h, $radius, '1111', $style );
				} else {
					$pdf->Rect( $x, $y, $w, $h, $style );
				}
				break;
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Shared internals.
	 * ------------------------------------------------------------------
	 */

	/**
	 * Read the font manifest (cached)
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function manifest() {
		if ( null !== self::$manifest ) {
			return self::$manifest;
		}

		self::$manifest = [];
		$path           = PPCERT_PLUGIN_DIR . 'fonts/manifest.json';

		if ( is_readable( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file, not a remote URL.
			$decoded = json_decode( (string) file_get_contents( $path ), true );

			if ( is_array( $decoded ) ) {
				self::$manifest = $decoded;
			}
		}

		return self::$manifest;
	}

	/**
	 * The FR-004 fitting thresholds from the manifest
	 *
	 * @since 1.0.0
	 *
	 * @return array [ 'shrink_step_pt' => float, 'min_scale' => float ].
	 */
	public static function fitting_thresholds() {
		$manifest = self::manifest();

		return [
			'shrink_step_pt' => isset( $manifest['fitting']['shrink_step_pt'] ) ? (float) $manifest['fitting']['shrink_step_pt'] : 0.5,
			'min_scale'      => isset( $manifest['fitting']['min_scale'] ) ? (float) $manifest['fitting']['min_scale'] : 0.6,
		];
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

	/**
	 * Record a render warning
	 *
	 * @since 1.0.0
	 *
	 * @param string $element_id Element id ('background' for the root).
	 * @param string $warning    Warning slug.
	 */
	private function warn( $element_id, $warning ) {
		$this->warnings[] = [
			'element' => $element_id,
			'warning' => $warning,
		];
	}
}
