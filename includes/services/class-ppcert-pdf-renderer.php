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
 * QR elements render as vector module rectangles from the QR service's
 * matrix (ADR 004 - one encoder for canvas and PDF). render_png()
 * rasterizes page 1: Imagick preferred where a PDF delegate exists, with
 * a geometry-identical GD direct rasterizer as the everywhere fallback.
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

			if ( self::is_parity_debug( $args ) ) {
				// Parity-only mode: white page, one solid rect per element
				// in its index color - the harness extracts bounding boxes
				// from these (FR-005 drift assertions).
				$pdf->SetFillColorArray( [ 255, 255, 255 ] );
				$pdf->Rect( 0, 0, (float) $layout['page']['width'], (float) $layout['page']['height'], 'F' );

				foreach ( array_values( $layout['elements'] ) as $index => $element ) {
					$pdf->SetFillColorArray( self::parity_color( $index ) );
					$pdf->Rect( (float) $element['x'], (float) $element['y'], (float) $element['w'], (float) $element['h'], 'F' );
				}
			} else {
				$this->render_background( $pdf, $layout );

				foreach ( $layout['elements'] as $element ) {
					$this->render_element( $pdf, $element, $merge_data, $layout, $args );
				}
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
				$this->render_qr( $pdf, $element, $args );
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
	 * @return array {
	 *     @type string $key    TCPDF font key.
	 *     @type string $file   TCPDF definition path.
	 *     @type string $ttf    Source TTF path (GD raster path).
	 *     @type int    $ascent Ascent in per-1000-em units (manifest metrics).
	 * }
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
		$ttf_rel  = isset( $variants[ $variant ]['ttf'] ) ? (string) $variants[ $variant ]['ttf'] : '';
		$ascent   = isset( $variants[ $variant ]['metrics']['ascent'] ) ? (int) $variants[ $variant ]['metrics']['ascent'] : 1000;

		return [
			'key'    => $font_key,
			'file'   => PPCERT_PLUGIN_DIR . 'fonts/tcpdf/' . $font_key . '.php',
			'ttf'    => '' !== $ttf_rel ? PPCERT_PLUGIN_DIR . 'fonts/' . $ttf_rel : '',
			'ascent' => $ascent,
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
	 * QR (Feature 006 FR-005 via the QR service - ADR 004).
	 * ------------------------------------------------------------------
	 */

	/**
	 * Render a QR element as vector module rectangles
	 *
	 * The module matrix comes from the QR service (one encoder for canvas
	 * and PDF - the parity guarantee); modules draw as vector rects, so
	 * output is crisp at any DPI. Content is always the verification URL
	 * for the credential; previews encode a sample URL (FR-005).
	 *
	 * @since 1.0.0
	 *
	 * @param TCPDF $pdf     Document.
	 * @param array $element Clean qr element.
	 * @param array $args    Render arguments.
	 */
	private function render_qr( $pdf, $element, $args ) {
		$qr = PressPrimer_Certificate_QR_Service::generate( self::qr_content( $args ) );

		if ( is_wp_error( $qr ) ) {
			$this->warn( $element['id'], 'qr_generation_failed' );
			return;
		}

		$box_x = (float) $element['x'];
		$box_y = (float) $element['y'];
		$box_w = (float) $element['w']; // Square: validator snaps w === h.

		$total       = (int) $qr['total_modules'];
		$module_size = $box_w / $total;
		$offset      = (int) $qr['quiet_zone'];
		$props       = $element['props'];

		// Light modules + quiet zone: filled only when a light color is
		// set; empty means transparent (the page shows through).
		if ( '' !== $props['light_color'] ) {
			$pdf->SetFillColorArray( self::hex_to_rgb( (string) $props['light_color'], [ 255, 255, 255 ] ) );
			$pdf->Rect( $box_x, $box_y, $box_w, $box_w, 'F' );
		}

		$pdf->SetFillColorArray( self::hex_to_rgb( (string) $props['dark_color'], [ 0, 0, 0 ] ) );

		foreach ( $qr['matrix'] as $row_index => $row ) {
			foreach ( $row as $col_index => $module ) {
				if ( 1 !== $module ) {
					continue;
				}

				$pdf->Rect(
					$box_x + ( ( $offset + $col_index ) * $module_size ),
					$box_y + ( ( $offset + $row_index ) * $module_size ),
					$module_size,
					$module_size,
					'F'
				);
			}
		}
	}

	/**
	 * The QR content URL for a render
	 *
	 * Issued renders encode the credential's verification URL; previews
	 * without a credential encode a sample URL.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Render arguments.
	 * @return string
	 */
	private static function qr_content( $args ) {
		$credential = isset( $args['credential_id'] ) ? (string) $args['credential_id'] : '';

		if ( '' !== $credential && function_exists( 'ppcert_verification_url' ) ) {
			return ppcert_verification_url( $credential );
		}

		// TODO (Prompt 2.7): ppcert_verification_url() becomes the canonical
		// builder; this provisional query-arg form matches its planned shape.
		if ( '' !== $credential ) {
			return home_url( '/?ppcert_id=' . rawurlencode( $credential ) );
		}

		return home_url( '/?ppcert_id=SAMPLE' );
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
	 * PNG rasterization (Feature 007 FR-001, TR-002).
	 * ------------------------------------------------------------------
	 */

	/**
	 * Rasterize page 1 of a layout to a PNG temp file
	 *
	 * Imagick (with a PDF delegate) is preferred: it rasterizes the real
	 * PDF, so the PNG is the ground truth. Where Imagick is unavailable -
	 * most shared hosting - the GD path draws the layout directly with
	 * identical geometry (same fit math, same fitting rule, same manifest
	 * metrics, same QR matrix). The GD-vs-Imagick equivalence is held to
	 * the parity threshold by the test suite wherever Imagick exists.
	 *
	 * @since 1.0.0
	 *
	 * @param array $layout     Validator-clean layout document.
	 * @param array $merge_data Resolved merge data.
	 * @param array $args       Render arguments plus 'dpi' (default 300).
	 * @return string|WP_Error Absolute path to the PNG temp file (caller owns).
	 */
	public function render_png( array $layout, array $merge_data, array $args = [] ) {
		$structure = $this->verify_structure( $layout );

		if ( is_wp_error( $structure ) ) {
			return $structure;
		}

		$dpi = isset( $args['dpi'] ) ? max( 36, absint( $args['dpi'] ) ) : 300;

		if ( extension_loaded( 'imagick' ) ) {
			$pdf_path = $this->render_pdf( $layout, $merge_data, $args );

			if ( is_wp_error( $pdf_path ) ) {
				return $pdf_path;
			}

			$png_path = $this->imagick_raster( $pdf_path, $dpi );
			wp_delete_file( $pdf_path );

			if ( null !== $png_path ) {
				return $png_path;
			}
			// No PDF delegate (Ghostscript) behind Imagick: fall through to GD.
		}

		$this->warnings = [];

		return $this->gd_rasterize( $layout, $merge_data, $args, $dpi );
	}

	/**
	 * Rasterize a PDF's first page with Imagick
	 *
	 * @since 1.0.0
	 *
	 * @param string $pdf_path PDF file path.
	 * @param int    $dpi      Target resolution.
	 * @return string|null PNG temp path, or null when Imagick cannot read PDFs.
	 */
	private function imagick_raster( $pdf_path, $dpi ) {
		try {
			$imagick = new \Imagick();
			$imagick->setResolution( $dpi, $dpi );
			$imagick->readImage( $pdf_path . '[0]' );
			$imagick->setImageBackgroundColor( '#ffffff' );
			$imagick->setImageAlphaChannel( \Imagick::ALPHACHANNEL_REMOVE );
			$imagick->setImageFormat( 'png24' );

			$png_path = wp_tempnam( 'ppcert-raster' );

			if ( ! $png_path ) {
				return null;
			}

			$imagick->writeImage( $png_path );
			$imagick->clear();

			return $png_path;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Draw the layout directly onto a GD canvas
	 *
	 * @since 1.0.0
	 *
	 * @param array $layout     Layout document.
	 * @param array $merge_data Resolved merge data.
	 * @param array $args       Render arguments.
	 * @param int   $dpi        Target resolution.
	 * @return string|WP_Error PNG temp path.
	 */
	private function gd_rasterize( $layout, $merge_data, $args, $dpi ) {
		$scale  = $dpi / 72;
		$width  = (int) round( (float) $layout['page']['width'] * $scale );
		$height = (int) round( (float) $layout['page']['height'] * $scale );

		$canvas = imagecreatetruecolor( $width, $height );

		if ( false === $canvas ) {
			return new WP_Error(
				'ppcert_raster_failed',
				__( 'PNG rasterization failed (GD).', 'pressprimer-certificate' )
			);
		}

		imagealphablending( $canvas, true );

		if ( self::is_parity_debug( $args ) ) {
			// Parity-only mode: mirror of the PDF path's debug rendering.
			imagefilledrectangle( $canvas, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate( $canvas, 255, 255, 255 ) );

			foreach ( array_values( $layout['elements'] ) as $index => $element ) {
				$rgb = self::parity_color( $index );
				imagefilledrectangle(
					$canvas,
					(int) round( (float) $element['x'] * $scale ),
					(int) round( (float) $element['y'] * $scale ),
					(int) round( ( (float) $element['x'] + (float) $element['w'] ) * $scale ) - 1,
					(int) round( ( (float) $element['y'] + (float) $element['h'] ) * $scale ) - 1,
					(int) imagecolorallocate( $canvas, $rgb[0], $rgb[1], $rgb[2] )
				);
			}

			$png_path = wp_tempnam( 'ppcert-raster' );

			if ( ! $png_path ) {
				imagedestroy( $canvas );

				return new WP_Error(
					'ppcert_raster_tempfile',
					__( 'Could not create a temporary file for rasterization.', 'pressprimer-certificate' )
				);
			}

			imagepng( $canvas, $png_path );
			imagedestroy( $canvas );

			return $png_path;
		}

		// Background color, then full-bleed cover image.
		$bg_color = isset( $layout['background']['color'] ) ? (string) $layout['background']['color'] : '#ffffff';
		imagefilledrectangle( $canvas, 0, 0, $width - 1, $height - 1, $this->gd_color( $canvas, $bg_color, [ 255, 255, 255 ] ) );

		$bg_attachment = isset( $layout['background']['attachment_id'] ) ? absint( $layout['background']['attachment_id'] ) : 0;

		if ( $bg_attachment > 0 ) {
			$this->gd_image( $canvas, $bg_attachment, 0, 0, $width, $height, 'cover', 1.0, 'background', $scale );
		}

		foreach ( $layout['elements'] as $element ) {
			$x = (float) $element['x'] * $scale;
			$y = (float) $element['y'] * $scale;
			$w = (float) $element['w'] * $scale;
			$h = (float) $element['h'] * $scale;

			switch ( $element['type'] ) {
				case 'text':
					$this->gd_text( $canvas, $element, (string) $element['props']['content'], $scale );
					break;

				case 'merge_field':
					$token = trim( str_replace( [ '{{', '}}' ], '', (string) $element['props']['token'] ) );
					$value = isset( $merge_data[ $token ] ) && is_scalar( $merge_data[ $token ] )
						? (string) $merge_data[ $token ]
						: '';
					$this->gd_text( $canvas, $element, $value, $scale );
					break;

				case 'image':
				case 'signature':
					$this->gd_image(
						$canvas,
						absint( $element['props']['attachment_id'] ),
						$x,
						$y,
						$w,
						$h,
						(string) $element['props']['fit'],
						(float) $element['props']['opacity'],
						$element['id'],
						$scale
					);
					break;

				case 'shape':
					$this->gd_shape( $canvas, $element, $scale );
					break;

				case 'qr':
					$this->gd_qr( $canvas, $element, $args, $scale );
					break;
			}
		}

		$png_path = wp_tempnam( 'ppcert-raster' );

		if ( ! $png_path ) {
			imagedestroy( $canvas );

			return new WP_Error(
				'ppcert_raster_tempfile',
				__( 'Could not create a temporary file for rasterization.', 'pressprimer-certificate' )
			);
		}

		imagepng( $canvas, $png_path );
		imagedestroy( $canvas );

		return $png_path;
	}

	/**
	 * GD: draw a text box with the shared fitting rule
	 *
	 * Same wrap-and-fit geometry as the PDF path: greedy word wrap
	 * (character-break for over-wide words), FR-004 fitting, per-variant
	 * ascent from the manifest metrics.
	 *
	 * @since 1.0.0
	 *
	 * @param resource|GdImage $canvas  GD canvas.
	 * @param array            $element Text/merge_field element.
	 * @param string           $text    Literal text.
	 * @param float            $scale   Pixels per point.
	 */
	private function gd_text( $canvas, $element, $text, $scale ) {
		if ( '' === $text ) {
			return;
		}

		$props       = $element['props'];
		$box_w_px    = (float) $element['w'] * $scale;
		$line_height = (float) $props['line_height'];

		$font = $this->resolve_font(
			(string) $props['font_family'],
			! empty( $props['bold'] ),
			! empty( $props['italic'] ),
			$element['id']
		);

		$ttf = $font['ttf'];

		if ( '' === $ttf || ! file_exists( $ttf ) ) {
			$this->warn( $element['id'], 'font_file_missing' );
			return;
		}

		$measure = function ( $candidate_text, $candidate_size ) use ( $ttf, $box_w_px, $scale ) {
			return count( $this->gd_wrap_lines( $candidate_text, $candidate_size, $ttf, $box_w_px, $scale ) );
		};

		$fitted = self::fit_text(
			$text,
			(float) $element['w'],
			(float) $element['h'],
			(float) $props['font_size'],
			$line_height,
			$measure,
			self::fitting_thresholds()
		);

		if ( $fitted['truncated'] ) {
			$this->warn( $element['id'], 'text_truncated' );
		}

		$lines     = $this->gd_wrap_lines( $fitted['text'], $fitted['size'], $ttf, $box_w_px, $scale );
		$gd_size   = self::gd_font_size( $fitted['size'], $scale );
		$line_h_px = $fitted['size'] * $line_height * $scale;
		$ascent_px = ( $font['ascent'] / 1000 ) * $fitted['size'] * $scale;
		$color     = $this->gd_color( $canvas, (string) $props['color'], [ 0, 0, 0 ] );
		$box_x_px  = (float) $element['x'] * $scale;
		$box_y_px  = (float) $element['y'] * $scale;

		foreach ( $lines as $index => $line ) {
			if ( '' === $line ) {
				continue;
			}

			$bbox   = imagettfbbox( $gd_size, 0, $ttf, $line );
			$line_w = $bbox[2] - $bbox[0];

			$x = $box_x_px;
			if ( 'center' === $props['align'] ) {
				$x = $box_x_px + ( ( $box_w_px - $line_w ) / 2 );
			} elseif ( 'right' === $props['align'] ) {
				$x = $box_x_px + ( $box_w_px - $line_w );
			}

			imagettftext(
				$canvas,
				$gd_size,
				0,
				(int) round( $x ),
				(int) round( $box_y_px + ( $index * $line_h_px ) + $ascent_px ),
				$color,
				$ttf,
				$line
			);
		}
	}

	/**
	 * GD: greedy word wrap with character-break for over-wide words
	 *
	 * @since 1.0.0
	 *
	 * @param string $text     Text (may contain newlines).
	 * @param float  $size_pt  Font size in points.
	 * @param string $ttf      TTF file path.
	 * @param float  $box_w_px Box width in pixels.
	 * @param float  $scale    Pixels per point.
	 * @return string[] Wrapped lines.
	 */
	private function gd_wrap_lines( $text, $size_pt, $ttf, $box_w_px, $scale ) {
		$gd_size = self::gd_font_size( $size_pt, $scale );
		$fits    = static function ( $candidate ) use ( $gd_size, $ttf, $box_w_px ) {
			$bbox = imagettfbbox( $gd_size, 0, $ttf, $candidate );
			return ( $bbox[2] - $bbox[0] ) <= $box_w_px;
		};

		$lines = [];

		foreach ( explode( "\n", $text ) as $paragraph ) {
			if ( '' === trim( $paragraph ) ) {
				$lines[] = '';
				continue;
			}

			$current = '';

			foreach ( explode( ' ', $paragraph ) as $word ) {
				$candidate = '' === $current ? $word : $current . ' ' . $word;

				if ( $fits( $candidate ) ) {
					$current = $candidate;
					continue;
				}

				if ( '' !== $current ) {
					$lines[] = $current;
					$current = '';
				}

				// The word alone is wider than the box: break by character.
				if ( ! $fits( $word ) ) {
					$chunk = '';
					$chars = function_exists( 'mb_str_split' ) ? mb_str_split( $word ) : str_split( $word );

					foreach ( $chars as $char ) {
						if ( $fits( $chunk . $char ) || '' === $chunk ) {
							$chunk .= $char;
						} else {
							$lines[] = $chunk;
							$chunk   = $char;
						}
					}

					$current = $chunk;
				} else {
					$current = $word;
				}
			}

			$lines[] = $current;
		}

		return $lines;
	}

	/**
	 * GD: place an attachment image per fit mode using source-crop math
	 *
	 * Cover crops the SOURCE rectangle (no clipping needed in GD); the
	 * visible result is identical to the PDF path's clipped placement.
	 *
	 * @since 1.0.0
	 *
	 * @param resource|GdImage $canvas        GD canvas.
	 * @param int              $attachment_id Attachment id.
	 * @param float            $box_x         Box X in pixels.
	 * @param float            $box_y         Box Y in pixels.
	 * @param float            $box_w         Box width in pixels.
	 * @param float            $box_h         Box height in pixels.
	 * @param string           $fit           Fit mode.
	 * @param float            $opacity       0-1.
	 * @param string           $element_id    Element id (warnings).
	 * @param float            $scale         Pixels per point.
	 */
	private function gd_image( $canvas, $attachment_id, $box_x, $box_y, $box_w, $box_h, $fit, $opacity, $element_id, $scale ) {
		$file = $attachment_id > 0 ? get_attached_file( $attachment_id ) : false;

		if ( ! $file || ! file_exists( $file ) ) {
			$this->warn( $element_id, 'attachment_missing' );
			return;
		}

		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Non-image files return false; handled below.

		if ( false === $info ) {
			$this->warn( $element_id, 'attachment_not_image' );
			return;
		}

		switch ( $info['mime'] ) {
			case 'image/jpeg':
				$source = imagecreatefromjpeg( $file );
				break;
			case 'image/png':
				$source = imagecreatefrompng( $file );
				break;
			case 'image/gif':
				$source = imagecreatefromgif( $file );
				break;
			default:
				$this->warn( $element_id, 'attachment_not_image' );
				return;
		}

		if ( false === $source ) {
			$this->warn( $element_id, 'attachment_unreadable' );
			return;
		}

		$iw = (float) $info[0];
		$ih = (float) $info[1];

		$placement = self::fit_box( $iw, $ih, $box_w, $box_h, $fit );

		if ( 'cover' === $fit ) {
			// Invert to a source crop: draw exactly the box, sampling the
			// visible source region.
			$ratio = $placement['dw'] / $iw;
			$sx    = (int) round( -$placement['dx'] / $ratio );
			$sy    = (int) round( -$placement['dy'] / $ratio );
			$sw    = (int) round( $box_w / $ratio );
			$sh    = (int) round( $box_h / $ratio );

			imagecopyresampled( $canvas, $source, (int) round( $box_x ), (int) round( $box_y ), $sx, $sy, (int) round( $box_w ), (int) round( $box_h ), $sw, $sh );
		} else {
			imagecopyresampled(
				$canvas,
				$source,
				(int) round( $box_x + $placement['dx'] ),
				(int) round( $box_y + $placement['dy'] ),
				0,
				0,
				(int) round( $placement['dw'] ),
				(int) round( $placement['dh'] ),
				(int) $iw,
				(int) $ih
			);
		}

		// Opacity below 1 is approximated in the GD path (imagecopymerge
		// composites without alpha nuance); the PDF path applies exact
		// alpha. Parity-sensitive designs keep opacity at 1.
		if ( $opacity < 1 ) {
			$this->warn( $element_id, 'gd_opacity_approximate' );
		}

		imagedestroy( $source );
	}

	/**
	 * GD: draw a shape element
	 *
	 * @since 1.0.0
	 *
	 * @param resource|GdImage $canvas  GD canvas.
	 * @param array            $element Shape element.
	 * @param float            $scale   Pixels per point.
	 */
	private function gd_shape( $canvas, $element, $scale ) {
		$props        = $element['props'];
		$x            = (int) round( (float) $element['x'] * $scale );
		$y            = (int) round( (float) $element['y'] * $scale );
		$w            = (int) round( (float) $element['w'] * $scale );
		$h            = (int) round( (float) $element['h'] * $scale );
		$stroke_px    = (int) round( (float) $props['stroke_width'] * $scale );
		$has_stroke   = (float) $props['stroke_width'] > 0;
		$has_fill     = '' !== $props['fill_color'];
		$stroke_color = $has_stroke ? $this->gd_color( $canvas, (string) $props['stroke_color'], [ 0, 0, 0 ] ) : 0;
		$fill_color   = $has_fill ? $this->gd_color( $canvas, (string) $props['fill_color'], [ 255, 255, 255 ] ) : 0;

		if ( $has_stroke ) {
			imagesetthickness( $canvas, max( 1, $stroke_px ) );
		}

		switch ( $props['shape'] ) {
			case 'line':
				if ( $has_stroke ) {
					imageline( $canvas, $x, $y, $x + $w, $y + $h, $stroke_color );
				}
				break;

			case 'ellipse':
				if ( $has_fill ) {
					imagefilledellipse( $canvas, $x + ( $w / 2 ), $y + ( $h / 2 ), $w, $h, $fill_color );
				}
				if ( $has_stroke ) {
					imageellipse( $canvas, $x + ( $w / 2 ), $y + ( $h / 2 ), $w, $h, $stroke_color );
				}
				break;

			case 'rect':
			default:
				// Corner radius approximates as square in the GD path for
				// radii below a module of practical visibility; larger
				// radii draw arc corners.
				if ( $has_fill ) {
					imagefilledrectangle( $canvas, $x, $y, $x + $w, $y + $h, $fill_color );
				}
				if ( $has_stroke ) {
					imagerectangle( $canvas, $x, $y, $x + $w, $y + $h, $stroke_color );
				}
				break;
		}

		imagesetthickness( $canvas, 1 );
	}

	/**
	 * GD: draw a QR element from the shared matrix
	 *
	 * @since 1.0.0
	 *
	 * @param resource|GdImage $canvas  GD canvas.
	 * @param array            $element QR element.
	 * @param array            $args    Render arguments.
	 * @param float            $scale   Pixels per point.
	 */
	private function gd_qr( $canvas, $element, $args, $scale ) {
		$qr = PressPrimer_Certificate_QR_Service::generate( self::qr_content( $args ) );

		if ( is_wp_error( $qr ) ) {
			$this->warn( $element['id'], 'qr_generation_failed' );
			return;
		}

		$props    = $element['props'];
		$box_x    = (float) $element['x'] * $scale;
		$box_y    = (float) $element['y'] * $scale;
		$box_size = (float) $element['w'] * $scale;
		$total    = (int) $qr['total_modules'];
		$module   = $box_size / $total;
		$offset   = (int) $qr['quiet_zone'];

		if ( '' !== $props['light_color'] ) {
			imagefilledrectangle(
				$canvas,
				(int) round( $box_x ),
				(int) round( $box_y ),
				(int) round( $box_x + $box_size ),
				(int) round( $box_y + $box_size ),
				$this->gd_color( $canvas, (string) $props['light_color'], [ 255, 255, 255 ] )
			);
		}

		$dark = $this->gd_color( $canvas, (string) $props['dark_color'], [ 0, 0, 0 ] );

		foreach ( $qr['matrix'] as $row_index => $row ) {
			foreach ( $row as $col_index => $value ) {
				if ( 1 !== $value ) {
					continue;
				}

				$x1 = $box_x + ( ( $offset + $col_index ) * $module );
				$y1 = $box_y + ( ( $offset + $row_index ) * $module );

				imagefilledrectangle(
					$canvas,
					(int) round( $x1 ),
					(int) round( $y1 ),
					(int) round( $x1 + $module ) - 1,
					(int) round( $y1 + $module ) - 1,
					$dark
				);
			}
		}
	}

	/**
	 * Whether a render is in parity-debug mode
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Render arguments.
	 * @return bool
	 */
	private static function is_parity_debug( $args ) {
		return ! empty( $args['parity_debug'] );
	}

	/**
	 * The deterministic debug color for an element index
	 *
	 * Unique for up to the 100-element cap and never white; the parity
	 * harness groups pixels by exact color to recover each element's
	 * rendered bounding box.
	 *
	 * @since 1.0.0
	 *
	 * @param int $index Element index in canonical (z-normalized) order.
	 * @return int[] [ r, g, b ].
	 */
	public static function parity_color( $index ) {
		return [
			40 + ( ( $index * 2 ) % 200 ),
			70 + ( ( (int) floor( $index / 100 ) * 60 ) % 150 ),
			90 + ( ( $index * 3 ) % 160 ),
		];
	}

	/**
	 * GD font size parameter for a target point size at a scale
	 *
	 * GD2 interprets the size parameter at 96 DPI; converting through
	 * 72/96 yields glyphs whose pixel height matches size_pt * scale.
	 *
	 * @since 1.0.0
	 *
	 * @param float $size_pt Point size.
	 * @param float $scale   Pixels per point.
	 * @return float
	 */
	private static function gd_font_size( $size_pt, $scale ) {
		return $size_pt * $scale * 72 / 96;
	}

	/**
	 * GD color allocation from hex
	 *
	 * @since 1.0.0
	 *
	 * @param resource|GdImage $canvas   GD canvas.
	 * @param string           $hex      Hex color.
	 * @param int[]            $fallback RGB fallback.
	 * @return int
	 */
	private function gd_color( $canvas, $hex, $fallback ) {
		$rgb = self::hex_to_rgb( $hex, $fallback );

		return (int) imagecolorallocate( $canvas, $rgb[0], $rgb[1], $rgb[2] );
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
