<?php
/**
 * Layout validator unit tests
 *
 * Exercises the validation pipeline in
 * includes/services/class-ppcert-layout-validator.php against
 * docs/architecture/layout-schema.md.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Layout validator test case
 *
 * @since 1.0.0
 */
class Test_Layout_Validator extends TestCase {

	/**
	 * Reset test globals between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['ppcert_test_image_attachments'] = [];
		$GLOBALS['ppcert_test_filters']           = [];
	}

	/**
	 * Load the schema doc's sample document fixture.
	 *
	 * @return array
	 */
	private function sample() {
		$json = file_get_contents( __DIR__ . '/fixtures/sample-document.json' );
		return json_decode( $json, true );
	}

	/**
	 * Build a minimal valid text element.
	 *
	 * @param array $overrides Element key overrides.
	 * @return array
	 */
	private function text_element( array $overrides = [] ) {
		$element = [
			'id'    => 'el_test0001',
			'type'  => 'text',
			'x'     => 100,
			'y'     => 100,
			'w'     => 200,
			'h'     => 40,
			'z'     => 1,
			'props' => [
				'content'     => 'Hello',
				'font_family' => 'playfair-display',
				'font_size'   => 16,
				'color'       => '#000000',
				'align'       => 'left',
				'line_height' => 1.2,
				'bold'        => false,
				'italic'      => false,
			],
		];

		return array_replace_recursive( $element, $overrides );
	}

	/**
	 * Wrap elements in a valid document root.
	 *
	 * @param array $elements Elements array.
	 * @return array
	 */
	private function document( array $elements ) {
		return [
			'layout_schema_version' => 1,
			'page'                  => [
				'size'        => 'a4',
				'orientation' => 'landscape',
				'width'       => 842,
				'height'      => 595,
			],
			'background'            => [
				'color'         => '#ffffff',
				'attachment_id' => 0,
			],
			'elements'              => $elements,
		];
	}

	/**
	 * Collect the paths recorded on a WP_Error.
	 *
	 * @param WP_Error $error Validation error.
	 * @return string[]
	 */
	private function error_paths( $error ) {
		$paths = [];
		foreach ( $error->get_error_messages( 'ppcert_invalid_layout' ) as $message ) {
			$paths[] = explode( ': ', $message, 2 )[0];
		}
		return $paths;
	}

	// -------------------------------------------------------------------
	// The valid sample document.
	// -------------------------------------------------------------------

	/**
	 * The schema doc's sample passes and survives the rebuild unchanged.
	 *
	 * @return void
	 */
	public function test_valid_sample_passes_unchanged() {
		$sample = $this->sample();
		$result = PressPrimer_Certificate_Layout_Validator::validate( $sample );

		$this->assertIsArray( $result );
		$this->assertEquals( $sample, $result );
	}

	// -------------------------------------------------------------------
	// Documented rejections.
	// -------------------------------------------------------------------

	/**
	 * Documents without a schema version are rejected.
	 *
	 * @return void
	 */
	public function test_missing_schema_version_rejected() {
		$document = $this->document( [] );
		unset( $document['layout_schema_version'] );

		$result = PressPrimer_Certificate_Layout_Validator::validate( $document );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * Unknown element types are a validation error with a path.
	 *
	 * @return void
	 */
	public function test_unknown_element_type_rejected() {
		$element         = $this->text_element();
		$element['type'] = 'iframe';

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $element ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'elements[0].type', $this->error_paths( $result ) );
	}

	/**
	 * Bad merge token grammar is rejected.
	 *
	 * @return void
	 */
	public function test_bad_token_grammar_rejected() {
		$bad_tokens = [ '{{Recipient.name}}', 'recipient.name', '{{recipient}}', '{{recipient.name}} extra' ];

		foreach ( $bad_tokens as $token ) {
			$element = $this->text_element(
				[
					'type'  => 'merge_field',
					'props' => [ 'token' => $token ],
				]
			);
			unset( $element['props']['content'] );

			$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $element ] ) );

			$this->assertInstanceOf( WP_Error::class, $result, "Token should be rejected: {$token}" );
			$this->assertContains( 'elements[0].props.token', $this->error_paths( $result ) );
		}
	}

	/**
	 * The meta token form allows hyphens in meta keys.
	 *
	 * @return void
	 */
	public function test_meta_token_with_hyphen_passes() {
		$element = $this->text_element(
			[
				'type'  => 'merge_field',
				'props' => [ 'token' => '{{recipient.meta.linkedin-url}}' ],
			]
		);
		unset( $element['props']['content'] );

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $element ] ) );

		$this->assertIsArray( $result );
		$this->assertSame( '{{recipient.meta.linkedin-url}}', $result['elements'][0]['props']['token'] );
	}

	/**
	 * More than 100 elements is a hard-cap error.
	 *
	 * @return void
	 */
	public function test_element_cap_rejected() {
		$elements = array_fill( 0, 101, $this->text_element() );

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( $elements ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'elements', $this->error_paths( $result ) );
	}

	/**
	 * Duplicate and malformed element ids are rejected.
	 *
	 * @return void
	 */
	public function test_element_id_rules() {
		// Duplicate ids.
		$a = $this->text_element();
		$b = $this->text_element( [ 'z' => 2 ] );

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $a, $b ] ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'elements[1].id', $this->error_paths( $result ) );

		// Malformed id.
		$bad = $this->text_element( [ 'id' => 'element-1; DROP TABLE' ] );

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $bad ] ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'elements[0].id', $this->error_paths( $result ) );
	}

	/**
	 * Text content may not contain merge tokens.
	 *
	 * @return void
	 */
	public function test_content_with_merge_token_rejected() {
		$element = $this->text_element( [ 'props' => [ 'content' => 'Awarded to {{recipient.display_name}}' ] ] );

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $element ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'elements[0].props.content', $this->error_paths( $result ) );
	}

	/**
	 * Image elements require a known image attachment.
	 *
	 * @return void
	 */
	public function test_image_requires_existing_attachment() {
		$element = [
			'id'    => 'el_imgtest1',
			'type'  => 'image',
			'x'     => 10,
			'y'     => 10,
			'w'     => 100,
			'h'     => 100,
			'z'     => 1,
			'props' => [
				'attachment_id' => 123,
				'fit'           => 'contain',
				'opacity'       => 1,
			],
		];

		// Unknown attachment: rejected.
		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $element ] ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'elements[0].props.attachment_id', $this->error_paths( $result ) );

		// Known attachment: accepted.
		$GLOBALS['ppcert_test_image_attachments'] = [ 123 ];

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $element ] ) );
		$this->assertIsArray( $result );
		$this->assertSame( 123, $result['elements'][0]['props']['attachment_id'] );
	}

	/**
	 * Multiple failures collect into one WP_Error with per-element paths.
	 *
	 * @return void
	 */
	public function test_failures_collect_with_paths() {
		$bad_color = $this->text_element( [ 'props' => [ 'color' => 'not-a-color' ] ] );
		$bad_align = $this->text_element( [ 'id' => 'el_test0002', 'z' => 2, 'props' => [ 'align' => 'justify' ] ] );

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $bad_color, $bad_align ] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$paths = $this->error_paths( $result );
		$this->assertContains( 'elements[0].props.color', $paths );
		$this->assertContains( 'elements[1].props.align', $paths );
	}

	// -------------------------------------------------------------------
	// Recompute, clamp, snap, and strip behavior.
	// -------------------------------------------------------------------

	/**
	 * Tampered page dimensions are recomputed from the preset table.
	 *
	 * @return void
	 */
	public function test_tampered_page_dimensions_recomputed() {
		$document                   = $this->document( [] );
		$document['page']['width']  = 99999;
		$document['page']['height'] = 1;

		$result = PressPrimer_Certificate_Layout_Validator::validate( $document );

		$this->assertIsArray( $result );
		$this->assertEquals( 842, $result['page']['width'] );
		$this->assertEquals( 595, $result['page']['height'] );
	}

	/**
	 * Letter portrait recomputes to 612 x 792.
	 *
	 * @return void
	 */
	public function test_letter_portrait_dimensions() {
		$document         = $this->document( [] );
		$document['page'] = [
			'size'        => 'letter',
			'orientation' => 'portrait',
			'width'       => 0,
			'height'      => 0,
		];

		$result = PressPrimer_Certificate_Layout_Validator::validate( $document );

		$this->assertIsArray( $result );
		$this->assertEquals( 612, $result['page']['width'] );
		$this->assertEquals( 792, $result['page']['height'] );
	}

	/**
	 * Coordinates and numeric props clamp to their documented ranges.
	 *
	 * @return void
	 */
	public function test_clamping_behavior() {
		$element = $this->text_element(
			[
				'x'     => -5000,
				'y'     => 5000,
				'w'     => 9000,
				'h'     => -10,
				'props' => [
					'font_size'   => 500,
					'line_height' => 0.1,
				],
			]
		);

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $element ] ) );

		$this->assertIsArray( $result );
		$clean = $result['elements'][0];

		$this->assertEquals( -842, $clean['x'] );                 // floor: -page width
		$this->assertEquals( 1190, $clean['y'] );                 // ceiling: 2x page height
		$this->assertEquals( 1684, $clean['w'] );                 // ceiling: 2x page width
		$this->assertEquals( 0.1, $clean['h'] );                  // floor: > 0
		$this->assertEquals( 200, $clean['props']['font_size'] ); // ceiling
		$this->assertEquals( 0.8, $clean['props']['line_height'] ); // floor
	}

	/**
	 * QR elements snap to square using the smaller dimension.
	 *
	 * @return void
	 */
	public function test_qr_snaps_to_square() {
		$element = [
			'id'    => 'el_qrtest01',
			'type'  => 'qr',
			'x'     => 700,
			'y'     => 450,
			'w'     => 80,
			'h'     => 60,
			'z'     => 1,
			'props' => [
				'dark_color'  => '#000000',
				'light_color' => '',
			],
		];

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $element ] ) );

		$this->assertIsArray( $result );
		$this->assertEquals( 60, $result['elements'][0]['w'] );
		$this->assertEquals( 60, $result['elements'][0]['h'] );
	}

	/**
	 * Z order is stable-sorted and reassigned 1..n.
	 *
	 * @return void
	 */
	public function test_z_reassignment() {
		$first  = $this->text_element( [ 'id' => 'el_ztest001', 'z' => 10 ] );
		$second = $this->text_element( [ 'id' => 'el_ztest002', 'z' => 10 ] );
		$third  = $this->text_element( [ 'id' => 'el_ztest003', 'z' => 5 ] );

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $first, $second, $third ] ) );

		$this->assertIsArray( $result );

		$ids = array_column( $result['elements'], 'id' );
		$zs  = array_column( $result['elements'], 'z' );

		// z=5 sorts first; the duplicate z=10 pair keeps original order.
		$this->assertSame( [ 'el_ztest003', 'el_ztest001', 'el_ztest002' ], $ids );
		$this->assertSame( [ 1, 2, 3 ], $zs );
	}

	/**
	 * Unknown props, unknown element keys, and unknown root keys are
	 * stripped by the rebuild.
	 *
	 * @return void
	 */
	public function test_unknown_keys_stripped() {
		$element                      = $this->text_element();
		$element['rotation']          = 45;
		$element['onclick']           = 'alert(1)';
		$element['props']['onload']   = 'alert(1)';
		$element['props']['__proto__'] = [ 'polluted' => true ];

		$document           = $this->document( [ $element ] );
		$document['evil']   = 'payload';
		$document['assets'] = [ 'http://evil.example/x.js' ];

		$result = PressPrimer_Certificate_Layout_Validator::validate( $document );

		$this->assertIsArray( $result );
		$this->assertSame(
			[ 'layout_schema_version', 'page', 'background', 'elements' ],
			array_keys( $result )
		);
		$this->assertSame(
			[ 'id', 'type', 'x', 'y', 'w', 'h', 'z', 'props' ],
			array_keys( $result['elements'][0] )
		);
		$this->assertArrayNotHasKey( 'onload', $result['elements'][0]['props'] );
		$this->assertArrayNotHasKey( '__proto__', $result['elements'][0]['props'] );
	}

	/**
	 * The background palette type never appears in elements - stripped
	 * silently, not an error.
	 *
	 * @return void
	 */
	public function test_background_type_stripped_from_elements() {
		$background_element = [
			'id'    => 'el_bgtest01',
			'type'  => 'background',
			'x'     => 0,
			'y'     => 0,
			'w'     => 842,
			'h'     => 595,
			'z'     => 1,
			'props' => [ 'color' => '#ff0000' ],
		];

		$result = PressPrimer_Certificate_Layout_Validator::validate(
			$this->document( [ $background_element, $this->text_element( [ 'z' => 2 ] ) ] )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['elements'] );
		$this->assertSame( 'text', $result['elements'][0]['type'] );
	}

	/**
	 * Script tags in text content are sanitized away, content preserved.
	 *
	 * @return void
	 */
	public function test_script_tags_sanitized_in_content() {
		$element = $this->text_element(
			[ 'props' => [ 'content' => "Awarded with honors<script>alert('xss')</script>" ] ]
		);

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $element ] ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'Awarded with honors', $result['elements'][0]['props']['content'] );
	}

	/**
	 * Unregistered font slugs fall back to the default font (not an error).
	 *
	 * @return void
	 */
	public function test_unregistered_font_falls_back() {
		$element = $this->text_element( [ 'props' => [ 'font_family' => 'comic-sans-forever' ] ] );

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $element ] ) );

		$this->assertIsArray( $result );
		$this->assertSame(
			PressPrimer_Certificate_Layout_Validator::DEFAULT_FONT,
			$result['elements'][0]['props']['font_family']
		);
	}

	/**
	 * Fonts registered through the ppcert_designer_fonts filter validate.
	 *
	 * @return void
	 */
	public function test_filter_registered_font_accepted() {
		$GLOBALS['ppcert_test_filters']['ppcert_designer_fonts'] = static function ( $fonts ) {
			$fonts['custom-font'] = [];
			return $fonts;
		};

		$element = $this->text_element( [ 'props' => [ 'font_family' => 'custom-font' ] ] );

		$result = PressPrimer_Certificate_Layout_Validator::validate( $this->document( [ $element ] ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'custom-font', $result['elements'][0]['props']['font_family'] );
	}
}
