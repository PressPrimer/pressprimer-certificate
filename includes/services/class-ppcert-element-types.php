<?php
/**
 * Designer element types registry
 *
 * The seven 1.0 element types (Feature 001 FR-003), extensible through
 * the ppcert_designer_element_types filter so Educator 2.0 can add
 * types without core changes.
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
 * Element types registry class
 *
 * Each definition carries the palette entry (label, icon slug), the
 * default box (points), and validator-clean default props used when the
 * palette adds a new element. The designer maps `key` to its canvas
 * component and properties section client-side; unknown keys render as
 * a generic box so third-party types degrade gracefully.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Element_Types {

	/**
	 * Get the registered element type definitions
	 *
	 * @since 1.0.0
	 *
	 * @return array Map of type key => definition.
	 */
	public static function get_types() {
		$default_font = 'source-sans-3';

		$types = [
			'text'        => [
				'key'           => 'text',
				'label'         => __( 'Text', 'pressprimer-certificate' ),
				'icon'          => 'text',
				'default_box'   => [
					'w' => 220,
					'h' => 28,
				],
				'default_props' => [
					'content'     => __( 'Your text', 'pressprimer-certificate' ),
					'font_family' => $default_font,
					'font_size'   => 16,
					'color'       => '#1f2937',
					'align'       => 'left',
					'line_height' => 1.2,
					'bold'        => false,
					'italic'      => false,
				],
			],
			'merge_field' => [
				'key'           => 'merge_field',
				'label'         => __( 'Merge Field', 'pressprimer-certificate' ),
				'icon'          => 'merge_field',
				// Palette activation lands with the merge-field registry
				// routes (Feature 002); the designer keeps this entry
				// inert until then.
				'default_box'   => [
					'w' => 260,
					'h' => 28,
				],
				'default_props' => [
					'token'       => '{{recipient.display_name}}',
					'font_family' => $default_font,
					'font_size'   => 16,
					'color'       => '#1f2937',
					'align'       => 'left',
					'line_height' => 1.2,
					'bold'        => false,
					'italic'      => false,
				],
			],
			'image'       => [
				'key'           => 'image',
				'label'         => __( 'Image / Logo', 'pressprimer-certificate' ),
				'icon'          => 'image',
				'default_box'   => [
					'w' => 140,
					'h' => 140,
				],
				'default_props' => [
					'attachment_id' => 0,
					'fit'           => 'contain',
					'opacity'       => 1,
				],
			],
			'signature'   => [
				'key'           => 'signature',
				'label'         => __( 'Signature', 'pressprimer-certificate' ),
				'icon'          => 'signature',
				'default_box'   => [
					'w' => 180,
					'h' => 60,
				],
				'default_props' => [
					'attachment_id' => 0,
					'fit'           => 'contain',
					'opacity'       => 1,
				],
			],
			'shape'       => [
				'key'           => 'shape',
				'label'         => __( 'Line / Shape', 'pressprimer-certificate' ),
				'icon'          => 'shape',
				'default_box'   => [
					'w' => 200,
					'h' => 100,
				],
				'default_props' => [
					'shape'        => 'rect',
					'stroke_color' => '#1f2937',
					'stroke_width' => 1,
					'fill_color'   => '',
					'radius'       => 0,
				],
			],
			'qr'          => [
				'key'           => 'qr',
				'label'         => __( 'QR Code', 'pressprimer-certificate' ),
				'icon'          => 'qr',
				'default_box'   => [
					'w' => 60,
					'h' => 60,
				],
				'default_props' => [
					'dark_color'  => '#000000',
					'light_color' => '',
				],
			],
			'background'  => [
				'key'           => 'background',
				'label'         => __( 'Background', 'pressprimer-certificate' ),
				'icon'          => 'background',
				// Palette-only: edits the document root background and
				// never appears in elements (the validator strips it).
				'default_box'   => null,
				'default_props' => [],
			],
		];

		/**
		 * Filters the designer element type registry.
		 *
		 * Educator 2.0 adds custom element types here. Added types need a
		 * matching canvas component and properties section registered
		 * client-side, and a PDF render callback (Feature 007).
		 * See docs/architecture/HOOKS.md.
		 *
		 * @since 1.0.0
		 *
		 * @param array $types Map of type key => definition.
		 */
		return apply_filters( 'ppcert_designer_element_types', $types );
	}
}
