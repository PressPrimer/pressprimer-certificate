<?php
/**
 * Layout JSON validator
 *
 * The centralized validation pipeline for layout documents.
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
 * Layout validator class
 *
 * Implements docs/architecture/layout-schema.md exactly. All inbound layout
 * JSON (REST, AJAX, import) passes through this class; no other class
 * sanitizes layout documents (CODE-STRUCTURE.md rule 3).
 *
 * The output is always a REBUILT document constructed key by key - never
 * the input array with fixes - so unknown root keys, unknown element keys,
 * and unknown props are stripped structurally. The client-side mirror in
 * src/designer/schema/ implements the same rules, but this PHP validator
 * is authoritative and always runs on save regardless of client state.
 *
 * Validation philosophy per the schema doc:
 * - Numeric ranges CLAMP (x/y/w/h, font_size, line_height, opacity,
 *   stroke_width, radius)
 * - Documented defaults apply when a prop is absent (line_height 1.2,
 *   qr colors, text content)
 * - Everything else invalid or missing collects into a single WP_Error
 *   with per-element paths (elements[3].props.font_size)
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Layout_Validator {

	/**
	 * The schema version of single-page documents - the free designer's
	 * save path, and the target of the v1 stamp migration
	 *
	 * Version 2 (plugin 1.1.0): text element content may contain merge
	 * tokens, interpolated at render time. Structurally identical to v1.
	 * Since 2.0 this is deliberately NOT the newest version (see
	 * MAX_SCHEMA_VERSION): the validator preserves a v2 document's
	 * version on save so the v3 bump cannot perturb free users.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const SCHEMA_VERSION = 2;

	/**
	 * The newest schema version accepted for validation and rendering
	 *
	 * Version 3 (plugin 2.0.0): the multi-page pages[] document model,
	 * authored by Educator, rendered by free (Feature 2.0-006 FR-002).
	 *
	 * @since 2.0.0
	 * @var int
	 */
	const MAX_SCHEMA_VERSION = 3;

	/**
	 * The oldest schema version accepted (migrated in memory on validate)
	 *
	 * @since 1.1.0
	 * @var int
	 */
	const MIN_SUPPORTED_SCHEMA_VERSION = 1;

	/**
	 * Hard cap on the number of pages in a v3 document
	 *
	 * @since 2.0.0
	 * @var int
	 */
	const MAX_PAGES = 10;

	/**
	 * Hard cap on the number of elements in a document
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_ELEMENTS = 100;

	/**
	 * Maximum text content length in characters
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const MAX_CONTENT_LENGTH = 2000;

	/**
	 * Minimum element dimension in points (the w/h clamp floor; > 0)
	 *
	 * @since 1.0.0
	 * @var float
	 */
	const MIN_DIMENSION = 0.1;

	/**
	 * Element id pattern: el_ + 8 lowercase alphanumerics
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ELEMENT_ID_PATTERN = '/^el_[a-z0-9]{8}$/';

	/**
	 * Merge token grammar: {{group.field}} (schema doc "Merge Token Grammar")
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TOKEN_PATTERN = '/^\{\{[a-z0-9_]+\.[a-z0-9_.]+\}\}$/';

	/**
	 * Merge token meta form: {{group.meta.key}} - keys may contain hyphens
	 * (sanitize_key semantics extended per the schema doc), max 64 chars
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_TOKEN_PATTERN = '/^\{\{[a-z0-9_]+\.meta\.[a-z0-9_\-]{1,64}\}\}$/';

	/**
	 * Default font slug used when an unregistered font_family is supplied
	 *
	 * Provisional until the bundled font manifest lands (Prompt 2.2).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const DEFAULT_FONT = 'playfair-display';

	/**
	 * Page dimensions in points, [width, height] per orientation
	 *
	 * @since 1.0.0
	 * @var array
	 */
	const PAGE_PRESETS = [
		'a4'     => [
			'landscape' => [ 842, 595 ],
			'portrait'  => [ 595, 842 ],
		],
		'letter' => [
			'landscape' => [ 792, 612 ],
			'portrait'  => [ 612, 792 ],
		],
	];

	/**
	 * Collected validation failures for the current run
	 *
	 * Each entry: [ 'path' => string, 'message' => string ].
	 *
	 * @since 1.0.0
	 * @var array
	 */
	private static $errors = [];

	/**
	 * Element ids seen during the current run
	 *
	 * Document-wide (across all pages of a v3 document - ids are unique
	 * within the whole document, not per page). Reset per validate().
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private static $seen_ids = [];

	/**
	 * Validate a decoded layout document
	 *
	 * JSON decoding happens before this method (REST layer); the validator
	 * receives an array and returns the rebuilt clean document, or a
	 * WP_Error carrying every collected failure with its path.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw Decoded layout document.
	 * @return array|WP_Error Rebuilt document, or WP_Error on any failure.
	 */
	public static function validate( array $raw ) {
		self::$errors   = [];
		self::$seen_ids = [];

		// Schema version: required, a supported integer. Documents without
		// it are rejected outright (there are no pre-v1 documents in the
		// wild); supported older versions migrate in memory first.
		$version = isset( $raw['layout_schema_version'] ) ? (int) $raw['layout_schema_version'] : 0;

		if ( $version < self::MIN_SUPPORTED_SCHEMA_VERSION || self::MAX_SCHEMA_VERSION < $version ) {
			return new WP_Error(
				'ppcert_invalid_layout',
				__( 'layout_schema_version: missing or unsupported schema version.', 'pressprimer-certificate' ),
				[ 'path' => 'layout_schema_version' ]
			);
		}

		$raw = self::migrate( $raw );

		// The version the rebuilt document carries: v1 migrated to v2, v2
		// stays v2 (the free save path - never up-converted), v3 stays v3.
		$version = (int) $raw['layout_schema_version'];

		// Page: size and orientation must be valid or the document cannot
		// be rebuilt (dimensions are recomputed from them).
		$page_raw    = isset( $raw['page'] ) && is_array( $raw['page'] ) ? $raw['page'] : [];
		$size        = isset( $page_raw['size'] ) ? (string) $page_raw['size'] : '';
		$orientation = isset( $page_raw['orientation'] ) ? (string) $page_raw['orientation'] : '';

		if ( ! isset( self::PAGE_PRESETS[ $size ] ) ) {
			self::add_error( 'page.size', __( 'must be a4 or letter.', 'pressprimer-certificate' ) );
		}

		if ( ! in_array( $orientation, [ 'landscape', 'portrait' ], true ) ) {
			self::add_error( 'page.orientation', __( 'must be landscape or portrait.', 'pressprimer-certificate' ) );
		}

		if ( ! empty( self::$errors ) ) {
			return self::build_error();
		}

		// Recompute page dimensions from the preset table - the width and
		// height in the input are ignored (defense against tampering).
		$dimensions  = self::PAGE_PRESETS[ $size ][ $orientation ];
		$page_width  = $dimensions[0];
		$page_height = $dimensions[1];

		// Background (root object; the designer's "background" palette type
		// edits this, it never appears in elements).
		$background = self::validate_background( isset( $raw['background'] ) ? $raw['background'] : [] );

		// Rebuilt document root - constructed key by key, never the input.
		$clean = [
			'layout_schema_version' => $version,
			'page'                  => [
				'size'        => $size,
				'orientation' => $orientation,
				'width'       => $page_width,
				'height'      => $page_height,
			],
			'background'            => $background,
		];

		if ( $version >= 3 ) {
			// v3: pages[] wraps the element arrays (multi-page, Educator-
			// authored). A root elements key is stripped structurally by
			// the rebuild. Ids are unique document-wide; z normalizes per
			// page.
			$clean['pages'] = self::validate_pages( isset( $raw['pages'] ) ? $raw['pages'] : null, $page_width, $page_height );
		} else {
			$clean['elements'] = self::validate_elements(
				isset( $raw['elements'] ) ? $raw['elements'] : [],
				$page_width,
				$page_height
			);
		}

		if ( ! empty( self::$errors ) ) {
			return self::build_error();
		}

		return $clean;
	}

	/**
	 * Validate a v3 document's pages array
	 *
	 * @since 2.0.0
	 *
	 * @param mixed $raw         Raw pages value.
	 * @param float $page_width  Page width in points.
	 * @param float $page_height Page height in points.
	 * @return array Clean page objects.
	 */
	private static function validate_pages( $raw, $page_width, $page_height ) {
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			self::add_error( 'pages', __( 'must be an array with at least one page.', 'pressprimer-certificate' ) );
			return [];
		}

		if ( count( $raw ) > self::MAX_PAGES ) {
			self::add_error(
				'pages',
				sprintf(
					/* translators: %d: maximum number of pages */
					__( 'documents are limited to %d pages.', 'pressprimer-certificate' ),
					self::MAX_PAGES
				)
			);
			return [];
		}

		$clean = [];

		foreach ( array_values( $raw ) as $index => $page ) {
			$path = 'pages[' . $index . ']';

			if ( ! is_array( $page ) ) {
				self::add_error( $path, __( 'must be an object.', 'pressprimer-certificate' ) );
				continue;
			}

			$clean[] = [
				'elements' => self::validate_elements(
					isset( $page['elements'] ) ? $page['elements'] : [],
					$page_width,
					$page_height,
					$path . '.'
				),
			];
		}

		return $clean;
	}

	/**
	 * The element arrays of a document, one entry per rendered page
	 *
	 * The single normalization every consumer of "the elements" uses
	 * (renderer, rasterizer, token extraction): v1/v2 documents yield one
	 * page holding the root elements array; v3 documents yield one entry
	 * per pages[] page. Tolerant of raw shapes (missing keys yield empty
	 * arrays) so it is safe on stored snapshots as well as validator
	 * output.
	 *
	 * @since 2.0.0
	 *
	 * @param array $layout Layout document (any supported version).
	 * @return array[] One element array per page, in page order.
	 */
	public static function layout_pages( array $layout ) {
		$version = isset( $layout['layout_schema_version'] ) ? (int) $layout['layout_schema_version'] : 0;

		if ( $version >= 3 ) {
			$pages = isset( $layout['pages'] ) && is_array( $layout['pages'] ) ? $layout['pages'] : [];
			$lists = [];

			foreach ( array_values( $pages ) as $page ) {
				$lists[] = isset( $page['elements'] ) && is_array( $page['elements'] ) ? $page['elements'] : [];
			}

			return empty( $lists ) ? [ [] ] : $lists;
		}

		return [ isset( $layout['elements'] ) && is_array( $layout['elements'] ) ? $layout['elements'] : [] ];
	}

	/**
	 * Migrate a supported older-version document to the current schema
	 *
	 * In-memory only: stored templates and snapshots are never rewritten
	 * (migrate-on-read); the migrated document persists only through the
	 * normal save path.
	 *
	 * v1 -> v2 is a version-stamp-only migration. v2 changes SEMANTICS
	 * (text content may carry merge tokens, interpolated at render time)
	 * but not shape, and stamping is provably safe: the v1 validator
	 * REJECTED token-bearing text content, so no stored v1 document can
	 * contain a token that would begin interpolating after migration.
	 *
	 * @since 1.1.0
	 *
	 * @param array $raw Decoded layout document at a supported version.
	 * @return array The document at SCHEMA_VERSION.
	 */
	public static function migrate( array $raw ) {
		$version = isset( $raw['layout_schema_version'] ) ? (int) $raw['layout_schema_version'] : 0;

		if ( 1 === $version ) {
			$raw['layout_schema_version'] = 2;
		}

		// v2 is deliberately NOT migrated to v3 here: the free designer's
		// save path keeps producing v2 single-page documents, so the 2.0
		// schema bump cannot perturb free users (Feature 2.0-006 FR-002).
		// Educator's designer calls migrate_v2_to_v3() explicitly.
		return $raw;
	}

	/**
	 * Convert a v2 single-page document to the v3 multi-page shape
	 *
	 * Wraps the root elements array as pages[0], removes the root key,
	 * and stamps version 3. Lossless and shape-only: rendering the
	 * wrapped document is pixel-identical to the v2 original. NOT called
	 * by validate() - the free save path preserves v2; this is the
	 * explicit conversion Educator's designer invokes when a user adds a
	 * second page (v1 documents migrate to v2 first via migrate()).
	 *
	 * @since 2.0.0
	 *
	 * @param array $raw Layout document at version 2 (or 1; migrated first).
	 * @return array The document at version 3.
	 */
	public static function migrate_v2_to_v3( array $raw ) {
		$raw = self::migrate( $raw );

		$version = isset( $raw['layout_schema_version'] ) ? (int) $raw['layout_schema_version'] : 0;

		if ( $version >= 3 ) {
			return $raw;
		}

		$elements = isset( $raw['elements'] ) && is_array( $raw['elements'] ) ? $raw['elements'] : [];

		unset( $raw['elements'] );

		$raw['layout_schema_version'] = 3;
		$raw['pages']                 = [ [ 'elements' => $elements ] ];

		return $raw;
	}

	/**
	 * Validate the root background object
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw background value.
	 * @return array Clean background object.
	 */
	private static function validate_background( $raw ) {
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		// Color: absent/empty falls back to the documented default; a
		// present-but-invalid value is a failure.
		$color = '#ffffff';
		if ( isset( $raw['color'] ) && '' !== $raw['color'] ) {
			$sanitized = sanitize_hex_color( (string) $raw['color'] );
			if ( empty( $sanitized ) ) {
				self::add_error( 'background.color', __( 'must be a hex color.', 'pressprimer-certificate' ) );
			} else {
				$color = $sanitized;
			}
		}

		return [
			'color'         => $color,
			'attachment_id' => isset( $raw['attachment_id'] ) ? absint( $raw['attachment_id'] ) : 0,
		];
	}

	/**
	 * Validate an elements array (the root array in v1/v2, one page's
	 * array in v3)
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $raw         Raw elements value.
	 * @param float  $page_width  Page width in points.
	 * @param float  $page_height Page height in points.
	 * @param string $path_prefix Error path prefix ('' for the root array,
	 *                            'pages[n].' for a v3 page).
	 * @return array Clean elements, z-order normalized (per page).
	 */
	private static function validate_elements( $raw, $page_width, $page_height, $path_prefix = '' ) {
		if ( ! is_array( $raw ) ) {
			self::add_error( $path_prefix . 'elements', __( 'must be an array.', 'pressprimer-certificate' ) );
			return [];
		}

		if ( count( $raw ) > self::MAX_ELEMENTS ) {
			self::add_error(
				$path_prefix . 'elements',
				sprintf(
					/* translators: %d: maximum number of elements */
					__( 'documents are limited to %d elements.', 'pressprimer-certificate' ),
					self::MAX_ELEMENTS
				)
			);
			return [];
		}

		$clean = [];

		foreach ( array_values( $raw ) as $index => $element ) {
			$path = $path_prefix . 'elements[' . $index . ']';

			if ( ! is_array( $element ) ) {
				self::add_error( $path, __( 'must be an object.', 'pressprimer-certificate' ) );
				continue;
			}

			$type = isset( $element['type'] ) ? (string) $element['type'] : '';

			// The background palette type never appears in elements - it is
			// stripped silently, not an error (schema doc).
			if ( 'background' === $type ) {
				continue;
			}

			$validated = self::validate_element( $element, $index, $page_width, $page_height, $path_prefix );

			if ( null === $validated ) {
				continue;
			}

			// Ids must be unique within the whole document (across every
			// page of a v3 document).
			if ( isset( self::$seen_ids[ $validated['id'] ] ) ) {
				self::add_error( $path . '.id', __( 'duplicate element id.', 'pressprimer-certificate' ) );
				continue;
			}
			self::$seen_ids[ $validated['id'] ] = true;

			$clean[] = $validated;
		}

		return self::normalize_z_order( $clean );
	}

	/**
	 * Validate a single element's common fields and per-type props
	 *
	 * @since 1.0.0
	 *
	 * @param array  $element     Raw element object.
	 * @param int    $index       Element index (for error paths).
	 * @param float  $page_width  Page width in points.
	 * @param float  $page_height Page height in points.
	 * @param string $path_prefix Error path prefix ('' or 'pages[n].').
	 * @return array|null Clean element, or null when it failed validation.
	 */
	private static function validate_element( $element, $index, $page_width, $page_height, $path_prefix = '' ) {
		$path   = $path_prefix . 'elements[' . $index . ']';
		$before = count( self::$errors );

		// Id.
		$id = isset( $element['id'] ) ? (string) $element['id'] : '';
		if ( ! preg_match( self::ELEMENT_ID_PATTERN, $id ) ) {
			self::add_error( $path . '.id', __( 'must match el_ followed by 8 lowercase alphanumerics.', 'pressprimer-certificate' ) );
		}

		// Type.
		$type  = isset( $element['type'] ) ? (string) $element['type'] : '';
		$types = [ 'text', 'merge_field', 'image', 'signature', 'shape', 'qr' ];
		if ( ! in_array( $type, $types, true ) ) {
			self::add_error( $path . '.type', __( 'unknown element type.', 'pressprimer-certificate' ) );
			return null;
		}

		// Position: clamped to [-page dimension, 2x page dimension]
		// (allows partial bleed, blocks nonsense).
		$x = self::clamp_float( isset( $element['x'] ) ? $element['x'] : 0, -$page_width, 2 * $page_width );
		$y = self::clamp_float( isset( $element['y'] ) ? $element['y'] : 0, -$page_height, 2 * $page_height );

		// Dimensions: clamped to (0, 2x page dimension].
		$w = self::clamp_float( isset( $element['w'] ) ? $element['w'] : self::MIN_DIMENSION, self::MIN_DIMENSION, 2 * $page_width );
		$h = self::clamp_float( isset( $element['h'] ) ? $element['h'] : self::MIN_DIMENSION, self::MIN_DIMENSION, 2 * $page_height );

		// QR elements are square: snap both dimensions to the smaller.
		if ( 'qr' === $type && $w !== $h ) {
			$w = min( $w, $h );
			$h = $w;
		}

		// Props per type.
		$props_raw  = isset( $element['props'] ) && is_array( $element['props'] ) ? $element['props'] : [];
		$props_path = $path . '.props';

		switch ( $type ) {
			case 'text':
				$props = self::validate_text_props( $props_raw, $props_path, true );
				break;
			case 'merge_field':
				$props = self::validate_text_props( $props_raw, $props_path, false );
				break;
			case 'image':
			case 'signature':
				$props = self::validate_image_props( $props_raw, $props_path );
				break;
			case 'shape':
				$props = self::validate_shape_props( $props_raw, $props_path );
				break;
			case 'qr':
			default:
				$props = self::validate_qr_props( $props_raw, $props_path );
				break;
		}

		// Any failure recorded for this element invalidates it as a whole.
		if ( count( self::$errors ) > $before ) {
			return null;
		}

		return [
			'id'    => $id,
			'type'  => $type,
			'x'     => $x,
			'y'     => $y,
			'w'     => $w,
			'h'     => $h,
			'z'     => isset( $element['z'] ) ? (int) $element['z'] : 0,
			'props' => $props,
		];
	}

	/**
	 * Validate text props (shared by text and merge_field)
	 *
	 * @since 1.0.0
	 *
	 * @param array  $raw          Raw props object.
	 * @param string $path         Error path prefix for this props object.
	 * @param bool   $with_content True for text (content, no token); false
	 *                             for merge_field (token, no content).
	 * @return array Clean props.
	 */
	private static function validate_text_props( $raw, $path, $with_content ) {
		$props = [];

		if ( $with_content ) {
			$content = isset( $raw['content'] ) ? sanitize_textarea_field( (string) $raw['content'] ) : '';

			if ( function_exists( 'mb_substr' ) ) {
				$content = mb_substr( $content, 0, self::MAX_CONTENT_LENGTH );
			} else {
				$content = substr( $content, 0, self::MAX_CONTENT_LENGTH );
			}

			// Schema v2: content may contain merge tokens, interpolated at
			// render time. Grammar is NOT validated at save - bad tokens
			// are inert (unknown fields render empty, non-matching braces
			// render literally).
			$props['content'] = $content;
		} else {
			$token = isset( $raw['token'] ) ? (string) $raw['token'] : '';

			// Grammar only here; registry resolution (save warning for
			// unresolvable tokens) is wired in Prompt 1.6.
			if ( ! preg_match( self::TOKEN_PATTERN, $token ) && ! preg_match( self::META_TOKEN_PATTERN, $token ) ) {
				self::add_error( $path . '.token', __( 'must be a {{group.field}} merge token.', 'pressprimer-certificate' ) );
			}

			$props['token'] = $token;
		}

		// Font family: unregistered slugs fall back to the default font
		// (a designer warning affordance, not a validation error).
		$font = isset( $raw['font_family'] ) ? sanitize_key( (string) $raw['font_family'] ) : '';
		if ( '' === $font || ! in_array( $font, self::get_registered_font_slugs(), true ) ) {
			$font = self::DEFAULT_FONT;
		}
		$props['font_family'] = $font;

		if ( ! isset( $raw['font_size'] ) || ! is_numeric( $raw['font_size'] ) ) {
			self::add_error( $path . '.font_size', __( 'must be a number.', 'pressprimer-certificate' ) );
		} else {
			$props['font_size'] = self::clamp_float( $raw['font_size'], 6, 200 );
		}

		$props['color'] = self::require_hex( $raw, 'color', $path . '.color' );

		$align = isset( $raw['align'] ) ? (string) $raw['align'] : '';
		if ( ! in_array( $align, [ 'left', 'center', 'right' ], true ) ) {
			self::add_error( $path . '.align', __( 'must be left, center, or right.', 'pressprimer-certificate' ) );
		}
		$props['align'] = $align;

		$props['line_height'] = isset( $raw['line_height'] ) && is_numeric( $raw['line_height'] )
			? self::clamp_float( $raw['line_height'], 0.8, 3.0 )
			: 1.2;

		$props['bold']   = self::to_bool( isset( $raw['bold'] ) ? $raw['bold'] : false );
		$props['italic'] = self::to_bool( isset( $raw['italic'] ) ? $raw['italic'] : false );

		return $props;
	}

	/**
	 * Validate image/signature props
	 *
	 * @since 1.0.0
	 *
	 * @param array  $raw  Raw props object.
	 * @param string $path Error path prefix.
	 * @return array Clean props.
	 */
	private static function validate_image_props( $raw, $path ) {
		$props = [];

		// 0 = no image chosen yet (a fresh palette element) - valid so
		// drafts save mid-design; the PDF renderer skips it with an
		// attachment_missing warning. A set id must be a real image.
		$attachment_id = isset( $raw['attachment_id'] ) ? absint( $raw['attachment_id'] ) : 0;
		if ( $attachment_id > 0 && ! wp_attachment_is_image( $attachment_id ) ) {
			self::add_error( $path . '.attachment_id', __( 'must be an existing image attachment.', 'pressprimer-certificate' ) );
		}
		$props['attachment_id'] = $attachment_id;

		$fit = isset( $raw['fit'] ) ? (string) $raw['fit'] : '';
		if ( ! in_array( $fit, [ 'contain', 'cover', 'stretch' ], true ) ) {
			self::add_error( $path . '.fit', __( 'must be contain, cover, or stretch.', 'pressprimer-certificate' ) );
		}
		$props['fit'] = $fit;

		$props['opacity'] = isset( $raw['opacity'] ) && is_numeric( $raw['opacity'] )
			? self::clamp_float( $raw['opacity'], 0, 1 )
			: 1.0;

		return $props;
	}

	/**
	 * Validate shape props
	 *
	 * @since 1.0.0
	 *
	 * @param array  $raw  Raw props object.
	 * @param string $path Error path prefix.
	 * @return array Clean props.
	 */
	private static function validate_shape_props( $raw, $path ) {
		$props = [];

		$shape = isset( $raw['shape'] ) ? (string) $raw['shape'] : '';
		if ( ! in_array( $shape, [ 'line', 'rect', 'ellipse' ], true ) ) {
			self::add_error( $path . '.shape', __( 'must be line, rect, or ellipse.', 'pressprimer-certificate' ) );
		}
		$props['shape'] = $shape;

		$props['stroke_color'] = self::require_hex( $raw, 'stroke_color', $path . '.stroke_color' );

		$props['stroke_width'] = isset( $raw['stroke_width'] ) && is_numeric( $raw['stroke_width'] )
			? self::clamp_float( $raw['stroke_width'], 0, 20 )
			: 0.0;

		$props['fill_color'] = self::hex_or_empty( $raw, 'fill_color', $path . '.fill_color' );

		$props['radius'] = isset( $raw['radius'] ) && is_numeric( $raw['radius'] )
			? self::clamp_float( $raw['radius'], 0, 100 )
			: 0.0;

		return $props;
	}

	/**
	 * Validate qr props
	 *
	 * QR content is always the verification URL (not configurable in v1);
	 * error correction M and the quiet zone are fixed, not props.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $raw  Raw props object.
	 * @param string $path Error path prefix.
	 * @return array Clean props.
	 */
	private static function validate_qr_props( $raw, $path ) {
		$props = [];

		// dark_color: documented default #000000 when absent.
		if ( ! isset( $raw['dark_color'] ) || '' === $raw['dark_color'] ) {
			$props['dark_color'] = '#000000';
		} else {
			$sanitized = sanitize_hex_color( (string) $raw['dark_color'] );
			if ( empty( $sanitized ) ) {
				self::add_error( $path . '.dark_color', __( 'must be a hex color.', 'pressprimer-certificate' ) );
				$props['dark_color'] = '#000000';
			} else {
				$props['dark_color'] = $sanitized;
			}
		}

		$props['light_color'] = self::hex_or_empty( $raw, 'light_color', $path . '.light_color' );

		return $props;
	}

	/**
	 * Stable-sort elements by z and reassign 1..n
	 *
	 * Ties (and duplicate z values from tampered documents) keep their
	 * original array order.
	 *
	 * @since 1.0.0
	 *
	 * @param array $elements Clean elements.
	 * @return array Elements sorted by stacking order with z = 1..n.
	 */
	private static function normalize_z_order( $elements ) {
		$decorated = [];
		foreach ( $elements as $index => $element ) {
			$decorated[] = [ $element['z'], $index, $element ];
		}

		usort(
			$decorated,
			static function ( $a, $b ) {
				if ( $a[0] === $b[0] ) {
					return $a[1] <=> $b[1];
				}
				return $a[0] <=> $b[0];
			}
		);

		$normalized = [];
		foreach ( $decorated as $position => $entry ) {
			$element      = $entry[2];
			$element['z'] = $position + 1;
			$normalized[] = $element;
		}

		return $normalized;
	}

	/**
	 * Get the registered designer font definitions
	 *
	 * The bundled font manifest (fonts/manifest.json, generated by
	 * npm run build:fonts) is the canonical default set; the filter
	 * extends it. The admin boot data feeds the designer's font picker
	 * from this same map so the picker and validation cannot drift.
	 *
	 * @since 1.0.0
	 *
	 * @return array Map of font slug => manifest family definition.
	 */
	public static function get_registered_fonts() {
		/**
		 * Filters the registered designer/renderer font set.
		 *
		 * Keys are font slugs usable in layout font_family props. The
		 * bundled font manifest populates the default set; Educator 2.0
		 * adds custom fonts through this same filter.
		 * See docs/architecture/HOOKS.md.
		 *
		 * @since 1.0.0
		 *
		 * @param array $fonts Map of font slug => font definition.
		 */
		return (array) apply_filters( 'ppcert_designer_fonts', self::get_bundled_fonts() );
	}

	/**
	 * Get the registered designer font slugs
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Registered font slugs.
	 */
	public static function get_registered_font_slugs() {
		return array_map( 'strval', array_keys( self::get_registered_fonts() ) );
	}

	/**
	 * Get the bundled font families from the manifest
	 *
	 * Cached for the request; falls back to the default font slug when
	 * the manifest is missing or unreadable so validation never breaks
	 * on a damaged install.
	 *
	 * @since 1.0.0
	 *
	 * @return array Map of font slug => manifest family definition.
	 */
	private static function get_bundled_fonts() {
		static $bundled = null;

		if ( null !== $bundled ) {
			return $bundled;
		}

		$bundled       = [ self::DEFAULT_FONT => [] ];
		$manifest_path = PPCERT_PLUGIN_DIR . 'fonts/manifest.json';

		if ( is_readable( $manifest_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file, not a remote URL.
			$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );

			if ( isset( $manifest['families'] ) && is_array( $manifest['families'] ) && ! empty( $manifest['families'] ) ) {
				$bundled = $manifest['families'];
			}
		}

		return $bundled;
	}

	/**
	 * Clamp a numeric value into a range
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @param float $min   Minimum.
	 * @param float $max   Maximum.
	 * @return float Clamped float.
	 */
	private static function clamp_float( $value, $min, $max ) {
		$value = floatval( $value );

		return max( (float) $min, min( (float) $max, $value ) );
	}

	/**
	 * Coerce a raw value to a boolean
	 *
	 * Accepts real booleans plus the common string/int forms; anything
	 * unrecognized is false.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( $value, [ 1, '1', 'true' ], true );
	}

	/**
	 * Require a valid hex color prop
	 *
	 * @since 1.0.0
	 *
	 * @param array  $raw  Raw props object.
	 * @param string $key  Prop key.
	 * @param string $path Error path for failures.
	 * @return string Sanitized hex color, or '' when invalid (error recorded).
	 */
	private static function require_hex( $raw, $key, $path ) {
		$value = isset( $raw[ $key ] ) ? sanitize_hex_color( (string) $raw[ $key ] ) : null;

		if ( empty( $value ) ) {
			self::add_error( $path, __( 'must be a hex color.', 'pressprimer-certificate' ) );
			return '';
		}

		return $value;
	}

	/**
	 * Accept a valid hex color or the empty string
	 *
	 * @since 1.0.0
	 *
	 * @param array  $raw  Raw props object.
	 * @param string $key  Prop key.
	 * @param string $path Error path for failures.
	 * @return string Sanitized hex color or ''.
	 */
	private static function hex_or_empty( $raw, $key, $path ) {
		if ( ! isset( $raw[ $key ] ) || '' === $raw[ $key ] ) {
			return '';
		}

		$value = sanitize_hex_color( (string) $raw[ $key ] );

		if ( empty( $value ) ) {
			self::add_error( $path, __( 'must be a hex color or empty.', 'pressprimer-certificate' ) );
			return '';
		}

		return $value;
	}

	/**
	 * Record a validation failure
	 *
	 * @since 1.0.0
	 *
	 * @param string $path    Document path (e.g. elements[3].props.font_size).
	 * @param string $message Failure description.
	 */
	private static function add_error( $path, $message ) {
		self::$errors[] = [
			'path'    => $path,
			'message' => $message,
		];
	}

	/**
	 * Build the single WP_Error carrying every collected failure
	 *
	 * @since 1.0.0
	 *
	 * @return WP_Error
	 */
	private static function build_error() {
		$error = new WP_Error();

		foreach ( self::$errors as $failure ) {
			$error->add(
				'ppcert_invalid_layout',
				$failure['path'] . ': ' . $failure['message'],
				[ 'path' => $failure['path'] ]
			);
		}

		return $error;
	}
}
