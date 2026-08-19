<?php
/**
 * PDF renderer tests
 *
 * Aspect math and fitting math against the shared fixtures, plus a real
 * TCPDF render of the schema doc's sample document in CI.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * PDF renderer test case
 *
 * @since 1.0.0
 */
class Test_PDF_Renderer extends TestCase {

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$GLOBALS['ppcert_test_attachment_files'] = [];
	}

	/**
	 * The deterministic measurement model documented in the fitting
	 * fixture file: cpl = max(1, floor(box_w / (size * 0.5))).
	 *
	 * @param float $box_w Box width.
	 * @return callable fn( string $text, float $size ): int lines.
	 */
	private function fixture_measure( $box_w ) {
		return static function ( $text, $size ) use ( $box_w ) {
			$chars_per_line = max( 1, (int) floor( $box_w / ( $size * 0.5 ) ) );
			return max( 1, (int) ceil( strlen( $text ) / $chars_per_line ) );
		};
	}

	/**
	 * Aspect math matches every shared fixture case exactly - the same
	 * file the JS canvas tests consume (parity contract).
	 *
	 * @return void
	 */
	public function test_fit_box_matches_shared_fixtures() {
		$fixtures = json_decode(
			(string) file_get_contents( PPCERT_PLUGIN_DIR . 'tests/fixtures/fit-modes.json' ),
			true
		);

		$this->assertNotEmpty( $fixtures['cases'] );

		foreach ( $fixtures['cases'] as $case ) {
			$result = PressPrimer_Certificate_PDF_Renderer::fit_box(
				$case['iw'],
				$case['ih'],
				$case['bw'],
				$case['bh'],
				$case['mode']
			);

			foreach ( [ 'dx', 'dy', 'dw', 'dh' ] as $key ) {
				$this->assertEqualsWithDelta(
					$case[ $key ],
					$result[ $key ],
					0.0001,
					"{$case['name']}: {$key}"
				);
			}
		}
	}

	/**
	 * Fitting math matches every shared fixture case under the documented
	 * measurement model.
	 *
	 * @return void
	 */
	public function test_fit_text_matches_shared_fixtures() {
		$fixtures = json_decode(
			(string) file_get_contents( PPCERT_PLUGIN_DIR . 'tests/fixtures/text-fitting.json' ),
			true
		);

		$this->assertNotEmpty( $fixtures['cases'] );

		foreach ( $fixtures['cases'] as $case ) {
			$text   = str_repeat( 'a', $case['text_length'] );
			$result = PressPrimer_Certificate_PDF_Renderer::fit_text(
				$text,
				$case['box_w'],
				$case['box_h'],
				$case['font_size'],
				$case['line_height'],
				$this->fixture_measure( $case['box_w'] ),
				$fixtures['thresholds']
			);

			$this->assertEqualsWithDelta( $case['expected_size'], $result['size'], 0.0001, "{$case['name']}: size" );
			$this->assertSame( $case['expected_truncated'], $result['truncated'], "{$case['name']}: truncated" );

			if ( $case['expected_truncated'] ) {
				$this->assertStringEndsWith( "\u{2026}", $result['text'] );
			} else {
				$this->assertSame( $text, $result['text'] );
			}
		}
	}

	/**
	 * The manifest thresholds feed the renderer's fitting rule.
	 *
	 * @return void
	 */
	public function test_fitting_thresholds_from_manifest() {
		$thresholds = PressPrimer_Certificate_PDF_Renderer::fitting_thresholds();

		$this->assertSame( 0.5, $thresholds['shrink_step_pt'] );
		$this->assertSame( 0.6, $thresholds['min_scale'] );
	}

	/**
	 * A real TCPDF render of the schema doc's sample document in CI:
	 * valid PDF bytes, the generated hook with documented args, and the
	 * 2.4-pending QR warning.
	 *
	 * @return void
	 */
	public function test_renders_sample_document() {
		$sample = json_decode(
			(string) file_get_contents( PPCERT_PLUGIN_DIR . 'tests/phpunit/fixtures/sample-document.json' ),
			true
		);

		$merge_data = [
			'recipient.display_name' => 'Dana Whitfield',
		];

		$fired = [];
		add_action(
			'ppcert_pdf_generated',
			static function ( ...$args ) use ( &$fired ) {
				$fired[] = $args;
			},
			10,
			5
		);

		$renderer = new PressPrimer_Certificate_PDF_Renderer();
		$path     = $renderer->render_pdf(
			$sample,
			$merge_data,
			[
				'context'        => 'preview',
				'title'          => 'Sample Document',
				'recipient_name' => 'Dana Whitfield',
			]
		);

		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		$bytes = (string) file_get_contents( $path );
		$this->assertStringStartsWith( '%PDF-', $bytes );
		$this->assertGreaterThan( 1000, strlen( $bytes ) );

		// Edit protection (2026-07-24): the encryption dictionary must
		// be present - an empty user password keeps the file readable
		// everywhere while the discarded owner password locks viewer
		// edit tools. (The cleartext XMP metadata title legitimately
		// still names the recipient; content streams are encrypted.)
		$this->assertStringContainsString( '/Encrypt', $bytes );

		// ppcert_pdf_generated: ( string $file_path, int $certificate_id, string $context ).
		$this->assertCount( 1, $fired );
		$this->assertCount( 3, $fired[0] );
		$this->assertSame( $path, $fired[0][0] );
		$this->assertSame( 0, $fired[0][1] );
		$this->assertSame( 'preview', $fired[0][2] );

		// With QR live (Prompt 2.4) the sample renders warning-free.
		$this->assertSame( [], $renderer->get_last_render_warnings() );

		unlink( $path );
	}

	/**
	 * Structural violations hard-fail - the renderer never guesses.
	 *
	 * @return void
	 */
	public function test_structural_violations_hard_fail() {
		$renderer = new PressPrimer_Certificate_PDF_Renderer();

		$no_version = $renderer->render_pdf( [ 'page' => [ 'width' => 842, 'height' => 595 ], 'elements' => [] ], [] );
		$this->assertInstanceOf( WP_Error::class, $no_version );

		$no_page = $renderer->render_pdf( [ 'layout_schema_version' => 1, 'elements' => [] ], [] );
		$this->assertInstanceOf( WP_Error::class, $no_page );
	}

	/**
	 * A missing attachment skips the element with a warning, never a
	 * fatal - the PDF still renders.
	 *
	 * @return void
	 */
	public function test_missing_attachment_warns_never_fatal() {
		$layout = [
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
			'elements'              => [
				[
					'id'    => 'el_missing1',
					'type'  => 'image',
					'x'     => 100,
					'y'     => 100,
					'w'     => 200,
					'h'     => 100,
					'z'     => 1,
					'props' => [
						'attachment_id' => 12345,
						'fit'           => 'contain',
						'opacity'       => 1.0,
					],
				],
			],
		];

		$renderer = new PressPrimer_Certificate_PDF_Renderer();
		$path     = $renderer->render_pdf( $layout, [], [ 'context' => 'preview' ] );

		$this->assertIsString( $path );
		$this->assertFileExists( $path );

		$warnings = array_column( $renderer->get_last_render_warnings(), 'warning' );
		$this->assertContains( 'attachment_missing', $warnings );

		unlink( $path );
	}

	/**
	 * Long text in a small box records the truncation warning.
	 *
	 * @return void
	 */
	public function test_truncation_warning_on_overflow() {
		$layout = [
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
			'elements'              => [
				[
					'id'    => 'el_longname',
					'type'  => 'text',
					'x'     => 100,
					'y'     => 100,
					'w'     => 120,
					'h'     => 18,
					'z'     => 1,
					'props' => [
						'content'     => 'Wolfeschlegelsteinhausenbergerdorff Wolfeschlegelsteinhausenbergerdorff Wolfeschlegelsteinhausenbergerdorff',
						'font_family' => 'playfair-display',
						'font_size'   => 16,
						'color'       => '#000000',
						'align'       => 'left',
						'line_height' => 1.2,
						'bold'        => false,
						'italic'      => false,
					],
				],
			],
		];

		$renderer = new PressPrimer_Certificate_PDF_Renderer();
		$path     = $renderer->render_pdf( $layout, [], [ 'context' => 'preview' ] );

		$this->assertIsString( $path );

		$warnings = array_column( $renderer->get_last_render_warnings(), 'warning' );
		$this->assertContains( 'text_truncated', $warnings );

		unlink( $path );
	}

	// -------------------------------------------------------------------
	// Clickable credentials (Feature 1.1-003).
	// -------------------------------------------------------------------

	/**
	 * A layout with one QR and one credential-ID field for link tests.
	 *
	 * @return array
	 */
	private function linkable_layout() {
		$text_props = [
			'font_family' => 'playfair-display',
			'font_size'   => 14,
			'color'       => '#000000',
			'align'       => 'left',
			'line_height' => 1.2,
			'bold'        => false,
			'italic'      => false,
		];

		return [
			'layout_schema_version' => 2,
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
			'elements'              => [
				[
					'id'    => 'el_linkqr01',
					'type'  => 'qr',
					'x'     => 750,
					'y'     => 480,
					'w'     => 60,
					'h'     => 60,
					'z'     => 1,
					'props' => [
						'dark_color'  => '#000000',
						'light_color' => '',
					],
				],
				[
					'id'    => 'el_linkcred',
					'type'  => 'merge_field',
					'x'     => 100,
					'y'     => 480,
					'w'     => 300,
					'h'     => 24,
					'z'     => 2,
					'props' => array_merge( $text_props, [ 'token' => '{{certificate.credential_id}}' ] ),
				],
				[
					'id'    => 'el_linktext',
					'type'  => 'text',
					'x'     => 100,
					'y'     => 100,
					'w'     => 400,
					'h'     => 30,
					'z'     => 3,
					'props' => array_merge( $text_props, [ 'content' => 'Certificate of Completion' ] ),
				],
			],
		];
	}

	/**
	 * Issued renders carry link annotations over the QR box and the
	 * credential field even under the always-on AES-256 protection;
	 * previews without a credential carry neither.
	 *
	 * Annotation Rect values stay plaintext under encryption (only
	 * string values encrypt), so the two element boxes are asserted
	 * exactly - in PDF coordinates, y measures from the BOTTOM: the QR
	 * at y=480 h=60 on a 595pt page becomes Rect y 55..115. A baseline
	 * link exists in every render (TCPDF's own hidden tcpdf.org credit
	 * link, present since 1.0), so counts are asserted relative to the
	 * preview, never absolute.
	 *
	 * @return void
	 */
	public function test_issued_render_links_qr_and_credential_field() {
		$merge_data = [ 'certificate.credential_id' => '7Q4M-K9P2-XT3A' ];
		$renderer   = new PressPrimer_Certificate_PDF_Renderer();
		$qr_rect    = '/Rect [750.000000 55.000000 810.000000 115.000000]';
		$cred_rect  = '/Rect [100.000000 91.000000 400.000000 115.000000]';

		$issued = $renderer->render_pdf(
			$this->linkable_layout(),
			$merge_data,
			[
				'context'       => 'download',
				'credential_id' => '7Q4MK9P2XT3A',
			]
		);

		$this->assertIsString( $issued );
		$bytes = (string) file_get_contents( $issued );
		unlink( $issued );

		$preview = $renderer->render_pdf(
			$this->linkable_layout(),
			$merge_data,
			[ 'context' => 'preview' ]
		);

		$this->assertIsString( $preview );
		$preview_bytes = (string) file_get_contents( $preview );
		unlink( $preview );

		// Exactly our two element boxes gained annotations.
		$this->assertStringContainsString( $qr_rect, $bytes );
		$this->assertStringContainsString( $cred_rect, $bytes );
		$this->assertSame(
			substr_count( $preview_bytes, '/Subtype /Link' ) + 2,
			substr_count( $bytes, '/Subtype /Link' )
		);

		// No credential, no links - previews never carry a dead URL.
		$this->assertStringNotContainsString( $qr_rect, $preview_bytes );
		$this->assertStringNotContainsString( $cred_rect, $preview_bytes );
	}

	// -------------------------------------------------------------------
	// Inline merge tokens (schema v2, Feature 1.1-001).
	// -------------------------------------------------------------------

	/**
	 * Known tokens substitute; unknown grammar-matching tokens render
	 * empty; non-scalar values render empty.
	 *
	 * @return void
	 */
	public function test_interpolate_tokens_resolution() {
		$merge_data = [
			'recipient.display_name' => 'Dana Whitfield',
			'source.course_title'    => 'Botany 101',
			'source.bad_value'       => [ 'not', 'scalar' ],
		];

		$this->assertSame(
			'Awarded to Dana Whitfield for Botany 101',
			PressPrimer_Certificate_PDF_Renderer::interpolate_tokens(
				'Awarded to {{recipient.display_name}} for {{source.course_title}}',
				$merge_data
			)
		);

		$this->assertSame(
			'Score: ',
			PressPrimer_Certificate_PDF_Renderer::interpolate_tokens( 'Score: {{source.unknown_field}}', $merge_data )
		);

		$this->assertSame(
			'Value: ',
			PressPrimer_Certificate_PDF_Renderer::interpolate_tokens( 'Value: {{source.bad_value}}', $merge_data )
		);
	}

	/**
	 * Meta tokens resolve; brace runs outside the grammar stay literal.
	 *
	 * @return void
	 */
	public function test_interpolate_tokens_meta_and_literals() {
		$merge_data = [ 'recipient.meta.license-no' => 'LN-4821' ];

		$this->assertSame(
			'License LN-4821',
			PressPrimer_Certificate_PDF_Renderer::interpolate_tokens( 'License {{recipient.meta.license-no}}', $merge_data )
		);

		// No dot, uppercase, spaces, single braces: not tokens - literal.
		$this->assertSame(
			'{{hello}} {{Group.Field}} {{a b.c}} {single.brace}',
			PressPrimer_Certificate_PDF_Renderer::interpolate_tokens( '{{hello}} {{Group.Field}} {{a b.c}} {single.brace}', $merge_data )
		);
	}

	/**
	 * The stored-version gate: identical content renders literally in a
	 * v1 document and interpolated in a v2 document.
	 *
	 * @return void
	 */
	public function test_text_content_gate_by_stored_version() {
		$element = [
			'props' => [ 'content' => 'Awarded to {{recipient.display_name}}' ],
		];

		$merge_data = [ 'recipient.display_name' => 'Dana Whitfield' ];

		$this->assertSame(
			'Awarded to {{recipient.display_name}}',
			PressPrimer_Certificate_PDF_Renderer::text_content_for_render(
				[ 'layout_schema_version' => 1 ],
				$element,
				$merge_data
			)
		);

		$this->assertSame(
			'Awarded to Dana Whitfield',
			PressPrimer_Certificate_PDF_Renderer::text_content_for_render(
				[ 'layout_schema_version' => 2 ],
				$element,
				$merge_data
			)
		);

		// Missing version: treated as pre-v2, literal (defense in depth).
		$this->assertSame(
			'Awarded to {{recipient.display_name}}',
			PressPrimer_Certificate_PDF_Renderer::text_content_for_render( [], $element, $merge_data )
		);
	}
}
