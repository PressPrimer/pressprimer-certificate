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
}
