<?php
/**
 * Page-size registry
 *
 * The one list of certificate page sizes (Feature 2.1-003 TR-001). The
 * layout validator, the designer boot data, the blank layout, the
 * Appearance default, and the Templates list all read it; before 2.1
 * each kept its own copy of the A4/Letter table.
 *
 * @package PressPrimer_Certificate
 * @subpackage Utilities
 * @since 2.1.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page sizes class
 *
 * Presets are keyed by size slug and carry a label, a measurement unit
 * for display ('in' or 'mm'), and [width, height] in points for each
 * orientation. Addons extend the list through `ppcert_page_presets`
 * (Feature 2.1-004 FR-002); a `custom` size with its own dimensions is
 * accepted only while `ppcert_allow_custom_page_size` is on (Educator).
 * Adding a size is a value-set change, never a layout schema change
 * (Feature 2.1-003 TR-003).
 *
 * @since 2.1.0
 */
class PressPrimer_Certificate_Page_Sizes {

	/**
	 * The custom size key (dimensions travel with the document)
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const CUSTOM = 'custom';

	/**
	 * Smallest page side in points (1 inch)
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const MIN_POINTS = 72;

	/**
	 * Largest page side in points (24 inches)
	 *
	 * @since 2.1.0
	 * @var int
	 */
	const MAX_POINTS = 1728;

	/**
	 * All presets, filtered and validated, in menu order
	 *
	 * Each entry: `label` (string), `unit` ('in' or 'mm'), `landscape`
	 * and `portrait` ([width, height] in points). Filter entries that
	 * are malformed, out of bounds, or use the reserved `custom` key are
	 * dropped; the four core sizes are restored if a filter removes them,
	 * so stored templates always validate.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,array> Presets keyed by size slug.
	 */
	public static function all() {
		$core = self::core_presets();

		/**
		 * Filters the certificate page-size presets.
		 *
		 * Each entry is keyed by a size slug (`sanitize_key()` form) and
		 * needs a `label`, plus `landscape` and `portrait` as [width,
		 * height] in points between 72 and 1728 per side; `unit`
		 * ('in' or 'mm') is optional and only affects display. The
		 * `custom` key is reserved and ignored here. Core sizes cannot
		 * be removed.
		 *
		 * @since 2.1.0
		 *
		 * @param array $presets Presets keyed by size slug, in menu order.
		 */
		$filtered = apply_filters( 'ppcert_page_presets', $core );
		$presets  = [];

		foreach ( is_array( $filtered ) ? $filtered : [] as $key => $preset ) {
			$clean = self::clean_preset( $key, $preset );

			if ( null !== $clean ) {
				$presets[ $key ] = $clean;
			}
		}

		// Core sizes always exist: stored templates use them.
		foreach ( $core as $key => $preset ) {
			if ( ! isset( $presets[ $key ] ) ) {
				$presets[ $key ] = $preset;
			}
		}

		return $presets;
	}

	/**
	 * Size slugs, in menu order (never includes `custom`)
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_keys( self::all() );
	}

	/**
	 * One preset, or null when the slug is unknown
	 *
	 * @since 2.1.0
	 *
	 * @param string $key Size slug.
	 * @return array|null
	 */
	public static function get( $key ) {
		$presets = self::all();
		$key     = (string) $key;

		return isset( $presets[ $key ] ) ? $presets[ $key ] : null;
	}

	/**
	 * Whether a slug names a preset (not `custom`)
	 *
	 * @since 2.1.0
	 *
	 * @param string $key Size slug.
	 * @return bool
	 */
	public static function is_preset( $key ) {
		return null !== self::get( $key );
	}

	/**
	 * Preset dimensions for an orientation
	 *
	 * @since 2.1.0
	 *
	 * @param string $key         Size slug.
	 * @param string $orientation 'landscape' or 'portrait'.
	 * @return int[]|null [width, height] in points, or null.
	 */
	public static function dimensions( $key, $orientation ) {
		$preset = self::get( $key );

		if ( null === $preset || ! in_array( $orientation, [ 'landscape', 'portrait' ], true ) ) {
			return null;
		}

		return $preset[ $orientation ];
	}

	/**
	 * Whether custom page sizes are accepted (Educator turns this on)
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public static function custom_allowed() {
		/**
		 * Filters whether the layout validator accepts a `custom` page
		 * size with its own width and height (72-1728 pt per side).
		 *
		 * @since 2.1.0
		 *
		 * @param bool $allowed Default false.
		 */
		return (bool) apply_filters( 'ppcert_allow_custom_page_size', false );
	}

	/**
	 * Clamp one custom page side to the allowed range
	 *
	 * Rounded to hundredths of a point, which is far below any print
	 * tolerance and keeps stored documents stable across saves.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $value Raw side length in points.
	 * @return float
	 */
	public static function clamp_side( $value ) {
		$value = is_numeric( $value ) ? (float) $value : 0.0;

		return round( max( self::MIN_POINTS, min( self::MAX_POINTS, $value ) ), 2 );
	}

	/**
	 * Display label for a template's page ("A3 · Landscape",
	 * "Custom · 8 × 10 in")
	 *
	 * Custom sizes show their dimensions in inches when the site's
	 * default page size is measured in inches (Letter, Legal), else in
	 * millimeters (Feature 2.1-004 FR-002).
	 *
	 * @since 2.1.0
	 *
	 * @param string $size        Size slug.
	 * @param string $orientation 'landscape' or 'portrait'.
	 * @param array  $page        The layout's page object (custom sizes read width/height).
	 * @return string Plain text (escape on output).
	 */
	public static function label( $size, $orientation, array $page = [] ) {
		if ( self::CUSTOM === $size ) {
			$width  = isset( $page['width'] ) ? (float) $page['width'] : 0.0;
			$height = isset( $page['height'] ) ? (float) $page['height'] : 0.0;

			if ( $width <= 0 || $height <= 0 ) {
				return __( 'Custom', 'pressprimer-certificate' );
			}

			$default = self::get( PressPrimer_Certificate_Appearance_Service::get()['page_size'] );
			$inches  = null !== $default && 'in' === $default['unit'];

			return sprintf(
				/* translators: 1: page width, 2: page height, 3: unit abbreviation ("in" or "mm") */
				__( 'Custom · %1$s × %2$s %3$s', 'pressprimer-certificate' ),
				self::format_length( $width, $inches ),
				self::format_length( $height, $inches ),
				$inches ? __( 'in', 'pressprimer-certificate' ) : __( 'mm', 'pressprimer-certificate' )
			);
		}

		$preset = self::get( $size );
		$name   = null !== $preset ? $preset['label'] : strtoupper( (string) $size );
		$labels = [
			'landscape' => __( 'Landscape', 'pressprimer-certificate' ),
			'portrait'  => __( 'Portrait', 'pressprimer-certificate' ),
		];

		return $name . ' · ' . ( isset( $labels[ $orientation ] ) ? $labels[ $orientation ] : ucfirst( (string) $orientation ) );
	}

	/**
	 * The four core sizes, in menu order
	 *
	 * Dimensions are the standard sizes rounded to whole points (A4 is
	 * 595.28 × 841.89 pt; the 1.0 renderer and every stored template use
	 * the whole-point values).
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,array>
	 */
	private static function core_presets() {
		return [
			'letter' => [
				'label'     => __( 'Letter', 'pressprimer-certificate' ),
				'unit'      => 'in',
				'landscape' => [ 792, 612 ],
				'portrait'  => [ 612, 792 ],
			],
			'legal'  => [
				'label'     => __( 'Legal', 'pressprimer-certificate' ),
				'unit'      => 'in',
				'landscape' => [ 1008, 612 ],
				'portrait'  => [ 612, 1008 ],
			],
			'a4'     => [
				'label'     => 'A4',
				'unit'      => 'mm',
				'landscape' => [ 842, 595 ],
				'portrait'  => [ 595, 842 ],
			],
			'a3'     => [
				'label'     => 'A3',
				'unit'      => 'mm',
				'landscape' => [ 1191, 842 ],
				'portrait'  => [ 842, 1191 ],
			],
		];
	}

	/**
	 * Validate one filtered preset
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $key    Preset key.
	 * @param mixed $preset Preset entry.
	 * @return array|null Clean preset, or null to drop it.
	 */
	private static function clean_preset( $key, $preset ) {
		if ( ! is_string( $key ) || sanitize_key( $key ) !== $key || self::CUSTOM === $key || '' === $key ) {
			return null;
		}

		if ( ! is_array( $preset ) || ! isset( $preset['label'], $preset['landscape'], $preset['portrait'] ) ) {
			return null;
		}

		$clean = [
			'label' => sanitize_text_field( (string) $preset['label'] ),
			'unit'  => isset( $preset['unit'] ) && 'in' === $preset['unit'] ? 'in' : 'mm',
		];

		foreach ( [ 'landscape', 'portrait' ] as $orientation ) {
			$dims = $preset[ $orientation ];

			if ( ! is_array( $dims ) || 2 !== count( $dims ) ) {
				return null;
			}

			$dims = array_values( $dims );

			foreach ( $dims as $side ) {
				if ( ! is_numeric( $side ) || (float) $side < self::MIN_POINTS || (float) $side > self::MAX_POINTS ) {
					return null;
				}
			}

			$clean[ $orientation ] = [ 0 + $dims[0], 0 + $dims[1] ];
		}

		return '' === $clean['label'] ? null : $clean;
	}

	/**
	 * Format a length in points as inches or millimeters
	 *
	 * Up to two decimals, trailing zeros dropped (8, 8.5, 203.2).
	 *
	 * @since 2.1.0
	 *
	 * @param float $points Length in points.
	 * @param bool  $inches True for inches, false for millimeters.
	 * @return string
	 */
	private static function format_length( $points, $inches ) {
		$value    = round( $inches ? $points / 72 : $points * 25.4 / 72, 2 );
		$decimals = 2;

		if ( round( $value, 0 ) === $value ) {
			$decimals = 0;
		} elseif ( round( $value, 1 ) === $value ) {
			$decimals = 1;
		}

		return number_format_i18n( $value, $decimals );
	}
}
