<?php
/**
 * Element types registry tests
 *
 * The palette adds elements straight from these defaults (Feature 001
 * FR-003), so every default must be validator-clean - a drifting
 * default would break "add element" the moment the validator runs.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Element types test case
 *
 * @since 1.0.0
 */
class Test_Element_Types extends TestCase {

	/**
	 * Reset hooks between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
	}

	/**
	 * The registry ships exactly the seven schema types.
	 *
	 * @return void
	 */
	public function test_registry_ships_the_seven_types() {
		$types = PressPrimer_Certificate_Element_Types::get_types();

		$this->assertSame(
			[ 'text', 'merge_field', 'image', 'signature', 'shape', 'qr', 'background' ],
			array_keys( $types )
		);

		foreach ( $types as $key => $type ) {
			$this->assertSame( $key, $type['key'] );
			$this->assertNotSame( '', $type['label'] );
		}
	}

	/**
	 * Every canvas type's defaults produce a validator-clean element.
	 *
	 * @return void
	 */
	public function test_defaults_are_validator_clean() {
		foreach ( PressPrimer_Certificate_Element_Types::get_types() as $key => $type ) {
			if ( null === $type['default_box'] ) {
				continue; // background: palette-only, never an element.
			}

			$layout = [
				'layout_schema_version' => 1,
				'page'                  => [
					'size'        => 'a4',
					'orientation' => 'landscape',
				],
				'background'            => [ 'color' => '#ffffff' ],
				'elements'              => [
					[
						'id'    => 'el_test0001',
						'type'  => $key,
						'x'     => 100,
						'y'     => 100,
						'w'     => $type['default_box']['w'],
						'h'     => $type['default_box']['h'],
						'z'     => 1,
						'props' => $type['default_props'],
					],
				],
			];

			$result = PressPrimer_Certificate_Layout_Validator::validate( $layout );

			$this->assertNotInstanceOf(
				WP_Error::class,
				$result,
				sprintf(
					'Type "%s" defaults failed validation: %s',
					$key,
					$result instanceof WP_Error ? $result->get_error_message() : ''
				)
			);

			// The rebuilt element keeps the default props (nothing was
			// stripped or coerced away).
			$rebuilt = $result['elements'][0];

			foreach ( $type['default_props'] as $prop => $value ) {
				$this->assertArrayHasKey(
					$prop,
					$rebuilt['props'],
					sprintf( 'Type "%s" lost default prop "%s".', $key, $prop )
				);
			}
		}
	}

	/**
	 * Background is palette-only: no box, no props.
	 *
	 * @return void
	 */
	public function test_background_is_palette_only() {
		$types = PressPrimer_Certificate_Element_Types::get_types();

		$this->assertNull( $types['background']['default_box'] );
		$this->assertSame( [], $types['background']['default_props'] );
	}

	/**
	 * The filter extends the registry (Educator 2.0 hook point).
	 *
	 * @return void
	 */
	public function test_filter_extends_the_registry() {
		add_filter(
			'ppcert_designer_element_types',
			static function ( $types ) {
				$types['ppcert_educator_badge'] = [
					'key'           => 'ppcert_educator_badge',
					'label'         => 'Badge',
					'icon'          => 'badge',
					'default_box'   => [
						'w' => 80,
						'h' => 80,
					],
					'default_props' => [],
				];

				return $types;
			}
		);

		$types = PressPrimer_Certificate_Element_Types::get_types();

		$this->assertArrayHasKey( 'ppcert_educator_badge', $types );
	}

	/**
	 * Line endpoints (2.1): a box 1 pt or less across is a straight rule
	 * through its center; any other box runs corner to corner.
	 *
	 * @return void
	 */
	public function test_line_endpoints() {
		// The starter rules: 1 pt tall, horizontal through the middle.
		$this->assertSame( [ 271.0, 282.5, 571.0, 282.5 ], PressPrimer_Certificate_PDF_Renderer::line_endpoints( 271.0, 282.0, 300.0, 1.0 ) );

		// A thin vertical box: vertical through the middle.
		$this->assertSame( [ 50.25, 100.0, 50.25, 400.0 ], PressPrimer_Certificate_PDF_Renderer::line_endpoints( 50.0, 100.0, 0.5, 300.0 ) );

		// A tiny box no taller than wide stays horizontal.
		$this->assertSame( [ 10.0, 10.5, 11.0, 10.5 ], PressPrimer_Certificate_PDF_Renderer::line_endpoints( 10.0, 10.0, 1.0, 1.0 ) );

		// Anything larger is a diagonal, as in 2.0.
		$this->assertSame( [ 10.0, 20.0, 110.0, 70.0 ], PressPrimer_Certificate_PDF_Renderer::line_endpoints( 10.0, 20.0, 100.0, 50.0 ) );
		$this->assertSame( [ 10.0, 20.0, 110.0, 21.5 ], PressPrimer_Certificate_PDF_Renderer::line_endpoints( 10.0, 20.0, 100.0, 1.5 ), 'Taller than 1 pt: a deliberate slope' );
	}
}
