<?php
/**
 * Appearance defaults tests (Prompt 5.2)
 *
 * The Appearance settings driving new-element defaults, the starter
 * color-role substitution on clone, and the safety fallbacks.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Appearance test case
 *
 * @since 1.0.0
 */
class Test_Appearance extends TestCase {

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options']     = [];
		$GLOBALS['ppcert_test_user_caps']   = true;
		$GLOBALS['ppcert_test_attachments'] = [];
	}

	/**
	 * Unset appearance leaves the built-in element defaults untouched.
	 *
	 * @return void
	 */
	public function test_unset_appearance_keeps_builtin_defaults() {
		$types = PressPrimer_Certificate_Element_Types::get_types();

		$this->assertSame( 'source-sans-3', $types['text']['default_props']['font_family'] );
		$this->assertSame( '#1f2937', $types['text']['default_props']['color'] );
		$this->assertSame( '#1f2937', $types['shape']['default_props']['stroke_color'] );
		$this->assertSame( '#000000', $types['qr']['default_props']['dark_color'] );
		$this->assertSame( 0, $types['signature']['default_props']['attachment_id'] );
		$this->assertSame( 0, $types['image']['default_props']['attachment_id'] );
	}

	/**
	 * Appearance settings drive every new-element default.
	 *
	 * @return void
	 */
	public function test_appearance_drives_element_defaults() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [
			'appearance_default_font'  => 'quicksand',
			'appearance_primary_color' => '#123456',
			'appearance_accent_color'  => '#654321',
			'appearance_signature_id'  => 41,
			'appearance_logo_id'       => 42,
		];

		$types = PressPrimer_Certificate_Element_Types::get_types();

		$this->assertSame( 'quicksand', $types['text']['default_props']['font_family'] );
		$this->assertSame( 'quicksand', $types['merge_field']['default_props']['font_family'] );
		$this->assertSame( '#123456', $types['text']['default_props']['color'] );
		$this->assertSame( '#123456', $types['qr']['default_props']['dark_color'] );
		$this->assertSame( '#654321', $types['shape']['default_props']['stroke_color'] );
		$this->assertSame( 41, $types['signature']['default_props']['attachment_id'] );
		$this->assertSame( 42, $types['image']['default_props']['attachment_id'] );
	}

	/**
	 * An unregistered stored font never reaches element defaults.
	 *
	 * @return void
	 */
	public function test_unregistered_font_falls_back() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [
			'appearance_default_font' => 'premium-font-gone',
		];

		$types = PressPrimer_Certificate_Element_Types::get_types();

		$this->assertSame( 'source-sans-3', $types['text']['default_props']['font_family'] );
	}

	/**
	 * Brand colors substitute only role-mapped hexes.
	 *
	 * @return void
	 */
	public function test_apply_brand_colors_substitutes_roles() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [
			'appearance_primary_color' => '#ff0000',
			'appearance_accent_color'  => '#00ff00',
		];

		$layout = [
			'layout_schema_version' => 1,
			'background'            => [ 'color' => '#fdfcf8' ],
			'elements'              => [
				[
					'id'    => 'el_title001',
					'type'  => 'text',
					'props' => [ 'color' => '#1F2A44' ],
				],
				[
					'id'    => 'el_border01',
					'type'  => 'shape',
					'props' => [
						'stroke_color' => '#b8860b',
						'fill_color'   => '',
					],
				],
				[
					'id'    => 'el_muted001',
					'type'  => 'text',
					'props' => [ 'color' => '#6b7280' ],
				],
			],
		];

		$roles = [
			'primary' => [ '#1f2a44' ],
			'accent'  => [ '#b8860b' ],
		];

		$branded = PressPrimer_Certificate_Appearance_Service::apply_brand_colors( $layout, $roles );

		// Case-insensitive substitution on mapped colors.
		$this->assertSame( '#ff0000', $branded['elements'][0]['props']['color'] );
		$this->assertSame( '#00ff00', $branded['elements'][1]['props']['stroke_color'] );

		// Unmapped colors and empty values stay untouched.
		$this->assertSame( '#6b7280', $branded['elements'][2]['props']['color'] );
		$this->assertSame( '', $branded['elements'][1]['props']['fill_color'] );
		$this->assertSame( '#fdfcf8', $branded['background']['color'] );
	}

	/**
	 * No brand colors set: the layout passes through byte-identical.
	 *
	 * @return void
	 */
	public function test_apply_brand_colors_noop_when_unset() {
		$layout = [
			'elements' => [
				[
					'id'    => 'el_title001',
					'type'  => 'text',
					'props' => [ 'color' => '#1f2a44' ],
				],
			],
		];

		$branded = PressPrimer_Certificate_Appearance_Service::apply_brand_colors(
			$layout,
			[ 'primary' => [ '#1f2a44' ] ]
		);

		$this->assertSame( $layout, $branded );
	}

	/**
	 * Creating from a starter applies the brand colors to the stored
	 * layout; the formal starter's navy and gold become the site's
	 * primary and accent.
	 *
	 * @return void
	 */
	public function test_starter_clone_is_branded() {
		$GLOBALS['ppcert_test_options']['ppcert_settings'] = [
			'appearance_primary_color' => '#222222',
			'appearance_accent_color'  => '#c0ffee',
		];

		$controller = new PressPrimer_Certificate_REST_Templates_Controller();
		$response   = $controller->create_template(
			new WP_REST_Request( [ 'starter' => 'starter-formal-landscape' ] )
		);

		$this->assertNotInstanceOf( WP_Error::class, $response );

		$layout = $response->get_data()['layout'];
		$colors = [];

		foreach ( $layout['elements'] as $element ) {
			foreach ( [ 'color', 'stroke_color', 'dark_color' ] as $prop ) {
				if ( isset( $element['props'][ $prop ] ) && '' !== $element['props'][ $prop ] ) {
					$colors[] = strtolower( $element['props'][ $prop ] );
				}
			}
		}

		$this->assertContains( '#222222', $colors );
		$this->assertContains( '#c0ffee', $colors );
		$this->assertNotContains( '#1f2a44', $colors );
		$this->assertNotContains( '#b8860b', $colors );
	}
}
