<?php
/**
 * Appearance defaults service
 *
 * The Appearance settings surface (Prompt 5.2, Ryan's reshaped scope):
 * default font, default signature/logo attachments, and the primary +
 * accent brand colors. One reader shared by the element-type defaults
 * (new blank certificates), the designer boot data (ColorField
 * presets), and the starter clone path (per-starter color roles).
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
 * Appearance service class
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Appearance_Service {

	/**
	 * Get the appearance settings with empty-safe defaults
	 *
	 * Empty string / zero means "not set" - consumers fall back to
	 * their built-in defaults so a fresh install behaves exactly as
	 * before this surface existed.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     @type string $default_font  Registered font slug, or ''.
	 *     @type int    $signature_id  Attachment id, or 0.
	 *     @type int    $logo_id       Attachment id, or 0.
	 *     @type string $primary_color Hex color for shapes, or ''.
	 *     @type string $accent_color  Hex color for shapes, or ''.
	 *     @type string $text_color    Hex color for text + QR ink, or ''.
	 *     @type string $page_size     'letter' (default) or 'a4'.
	 * }
	 */
	public static function get() {
		$settings = get_option( 'ppcert_settings', [] );
		$settings = is_array( $settings ) ? $settings : [];

		$font = isset( $settings['appearance_default_font'] ) ? sanitize_key( (string) $settings['appearance_default_font'] ) : '';

		// A slug that is no longer registered (e.g. a premium font after
		// the addon deactivates) must not leak into new elements.
		if ( '' !== $font && ! in_array( $font, PressPrimer_Certificate_Layout_Validator::get_registered_font_slugs(), true ) ) {
			$font = '';
		}

		$page_size = isset( $settings['appearance_page_size'] ) ? sanitize_key( (string) $settings['appearance_page_size'] ) : '';

		return [
			'page_size'     => in_array( $page_size, [ 'a4', 'letter' ], true ) ? $page_size : 'letter',
			'default_font'  => $font,
			'signature_id'  => isset( $settings['appearance_signature_id'] ) ? absint( $settings['appearance_signature_id'] ) : 0,
			'logo_id'       => isset( $settings['appearance_logo_id'] ) ? absint( $settings['appearance_logo_id'] ) : 0,
			'primary_color' => isset( $settings['appearance_primary_color'] ) ? (string) $settings['appearance_primary_color'] : '',
			'accent_color'  => isset( $settings['appearance_accent_color'] ) ? (string) $settings['appearance_accent_color'] : '',
			'text_color'    => isset( $settings['appearance_text_color'] ) ? (string) $settings['appearance_text_color'] : '',
		];
	}

	/**
	 * Apply the brand colors to a starter layout via its color roles
	 *
	 * Starters declare which of their hexes play each role
	 * (_meta.color_roles). Primary and accent are SHAPE colors - boxes,
	 * borders, rules, geometric decoration - and the text role covers
	 * text plus QR ink (Ryan, 2026-07-23: brand colors never restyle
	 * text; text has its own optional default). Unmapped colors
	 * (neutral grays, the playful confetti) stay untouched, and layouts
	 * pass through unchanged when no colors are set.
	 *
	 * @since 1.0.0
	 *
	 * @param array $layout Starter layout document.
	 * @param array $roles  Map of role => hex list, e.g. [ 'primary' => [ '#1f2a44' ] ].
	 * @return array Layout with brand colors applied.
	 */
	public static function apply_brand_colors( array $layout, array $roles ) {
		$appearance = self::get();

		$replacements = [];

		foreach ( [
			'primary' => 'primary_color',
			'accent'  => 'accent_color',
			'text'    => 'text_color',
		] as $role => $setting_key ) {
			$brand = strtolower( (string) $appearance[ $setting_key ] );

			if ( '' === $brand || empty( $roles[ $role ] ) || ! is_array( $roles[ $role ] ) ) {
				continue;
			}

			foreach ( $roles[ $role ] as $hex ) {
				$replacements[ strtolower( (string) $hex ) ] = $brand;
			}
		}

		if ( empty( $replacements ) ) {
			return $layout;
		}

		if ( isset( $layout['background']['color'] ) ) {
			$layout['background']['color'] = self::swap_color( $layout['background']['color'], $replacements );
		}

		if ( empty( $layout['elements'] ) || ! is_array( $layout['elements'] ) ) {
			return $layout;
		}

		$color_props = [ 'color', 'stroke_color', 'fill_color', 'dark_color', 'light_color' ];

		foreach ( $layout['elements'] as $index => $element ) {
			if ( empty( $element['props'] ) || ! is_array( $element['props'] ) ) {
				continue;
			}

			foreach ( $color_props as $prop ) {
				if ( isset( $element['props'][ $prop ] ) ) {
					$layout['elements'][ $index ]['props'][ $prop ] = self::swap_color( $element['props'][ $prop ], $replacements );
				}
			}
		}

		return $layout;
	}

	/**
	 * Substitute one color value through the replacement map
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value        Current prop value.
	 * @param array $replacements Map of lowercase hex => brand hex.
	 * @return mixed
	 */
	private static function swap_color( $value, array $replacements ) {
		$key = strtolower( (string) $value );

		return isset( $replacements[ $key ] ) ? $replacements[ $key ] : $value;
	}

	/**
	 * Fill a starter's image slots with the default signature and logo
	 *
	 * Starters declare where a signature image and a logo belong
	 * (_meta.image_slots). A slot only materializes when the matching
	 * Appearance default is set: the signature slot may replace the
	 * starter's typed signature element ('replaces'), and the logo slot
	 * inserts a fresh image element. No default, no change - designs
	 * never grow empty placeholder boxes.
	 *
	 * @since 1.0.0
	 *
	 * @param array $layout Starter layout document.
	 * @param array $slots  Map of slot key => box (+ optional 'replaces').
	 * @return array Layout with the user's artwork in place.
	 */
	public static function apply_image_slots( array $layout, array $slots ) {
		$appearance = self::get();

		if ( empty( $layout['elements'] ) || ! is_array( $layout['elements'] ) ) {
			return $layout;
		}

		$fills = [
			'signature' => [ 'signature', $appearance['signature_id'], 'el_sigslot1' ],
			'logo'      => [ 'image', $appearance['logo_id'], 'el_logoslot' ],
		];

		foreach ( $fills as $slot_key => $fill ) {
			list( $type, $attachment_id, $element_id ) = $fill;

			if ( $attachment_id < 1 || empty( $slots[ $slot_key ] ) || ! is_array( $slots[ $slot_key ] ) ) {
				continue;
			}

			$slot = $slots[ $slot_key ];

			if ( ! isset( $slot['x'], $slot['y'], $slot['w'], $slot['h'] ) ) {
				continue;
			}

			// A signature slot may replace the typed signature element.
			if ( ! empty( $slot['replaces'] ) ) {
				$layout['elements'] = array_values(
					array_filter(
						$layout['elements'],
						static function ( $element ) use ( $slot ) {
							return ! isset( $element['id'] ) || $element['id'] !== $slot['replaces'];
						}
					)
				);
			}

			$layout['elements'][] = [
				'id'    => $element_id,
				'type'  => $type,
				'x'     => (float) $slot['x'],
				'y'     => (float) $slot['y'],
				'w'     => (float) $slot['w'],
				'h'     => (float) $slot['h'],
				'z'     => count( $layout['elements'] ) + 1,
				'props' => [
					'attachment_id' => $attachment_id,
					'fit'           => 'contain',
					'opacity'       => 1,
				],
			];
		}

		return $layout;
	}
}
