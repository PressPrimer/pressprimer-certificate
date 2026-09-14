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

	// -------------------------------------------------------------------
	// Schema v3: multi-page rendering (2.0, Feature 2.0-006 FR-002).
	// -------------------------------------------------------------------

	/**
	 * Load the two-page v3 parity fixture (shared with the Playwright
	 * suite so both suites exercise the identical document).
	 *
	 * @return array
	 */
	private function multipage_fixture() {
		return json_decode(
			(string) file_get_contents( PPCERT_PLUGIN_DIR . 'tests/playwright/fixtures/parity-multipage.json' ),
			true
		);
	}

	/**
	 * Count the PDF's rendered pages ("/Type /Page" objects, excluding
	 * the "/Type /Pages" tree node).
	 *
	 * @param string $bytes PDF bytes.
	 * @return int
	 */
	private function pdf_page_count( $bytes ) {
		return (int) preg_match_all( '#/Type /Page(?!s)#', $bytes );
	}

	/**
	 * The hand-authored v3 fixture (three pages since Educator Prompt
	 * 2.4 - QR on page 3) renders one PDF page per pages[] entry; the
	 * single-page sample keeps rendering one (guards the counter too).
	 *
	 * @return void
	 */
	public function test_v3_multipage_fixture_renders_every_page() {
		$renderer = new PressPrimer_Certificate_PDF_Renderer();

		$two_page = $renderer->render_pdf(
			$this->multipage_fixture(),
			[ 'recipient.display_name' => 'Dana Whitfield' ],
			[
				'context'       => 'preview',
				'credential_id' => '7Q4MK9P2XT3A',
			]
		);

		$this->assertIsString( $two_page );

		$bytes = (string) file_get_contents( $two_page );
		$this->assertStringStartsWith( '%PDF-', $bytes );
		$this->assertSame( 3, $this->pdf_page_count( $bytes ) );

		unlink( $two_page );

		$sample = json_decode(
			(string) file_get_contents( PPCERT_PLUGIN_DIR . 'tests/phpunit/fixtures/sample-document.json' ),
			true
		);

		$one_page = $renderer->render_pdf( $sample, [], [ 'context' => 'preview' ] );

		$this->assertIsString( $one_page );
		$this->assertSame( 1, $this->pdf_page_count( (string) file_get_contents( $one_page ) ) );

		unlink( $one_page );
	}

	/**
	 * migrate_v2_to_v3 is render-lossless: the wrapped document's PNG is
	 * byte-identical to the v2 original's (GD raster is deterministic -
	 * this is the PHPUnit half of "v2 documents render identically
	 * pre/post the v3 bump"; the parity suite's unchanged goldens are
	 * the other half).
	 *
	 * @return void
	 */
	public function test_v3_wrap_renders_pixel_identical_to_v2() {
		$sample = json_decode(
			(string) file_get_contents( PPCERT_PLUGIN_DIR . 'tests/phpunit/fixtures/sample-document.json' ),
			true
		);

		$merge_data = [ 'recipient.display_name' => 'Dana Whitfield' ];
		$args       = [
			'context'       => 'preview',
			'dpi'           => 72,
			'credential_id' => '7Q4MK9P2XT3A',
		];

		$renderer = new PressPrimer_Certificate_PDF_Renderer();

		$v2_png = $renderer->render_png( $sample, $merge_data, $args );
		$this->assertIsString( $v2_png );

		$wrapped_png = $renderer->render_png(
			PressPrimer_Certificate_Layout_Validator::migrate_v2_to_v3( $sample ),
			$merge_data,
			$args
		);
		$this->assertIsString( $wrapped_png );

		$this->assertSame(
			md5_file( $v2_png ),
			md5_file( $wrapped_png ),
			'Wrapping a v2 document as v3 pages[0] must not change a single pixel.'
		);

		unlink( $v2_png );
		unlink( $wrapped_png );
	}

	/**
	 * render_png's page argument selects the page: the fixture's two
	 * pages raster differently, and out-of-range requests clamp to the
	 * last page.
	 *
	 * @return void
	 */
	public function test_render_png_page_selection() {
		$fixture    = $this->multipage_fixture();
		$merge_data = [ 'recipient.display_name' => 'Dana Whitfield' ];
		$renderer   = new PressPrimer_Certificate_PDF_Renderer();

		$args = [
			'context'       => 'preview',
			'dpi'           => 72,
			'credential_id' => '7Q4MK9P2XT3A',
		];

		$page_one  = $renderer->render_png( $fixture, $merge_data, array_merge( $args, [ 'page' => 1 ] ) );
		$page_two  = $renderer->render_png( $fixture, $merge_data, array_merge( $args, [ 'page' => 2 ] ) );
		$page_last = $renderer->render_png( $fixture, $merge_data, array_merge( $args, [ 'page' => 3 ] ) );
		$clamped   = $renderer->render_png( $fixture, $merge_data, array_merge( $args, [ 'page' => 99 ] ) );

		$this->assertIsString( $page_one );
		$this->assertIsString( $page_two );
		$this->assertIsString( $page_last );
		$this->assertIsString( $clamped );

		$this->assertNotSame( md5_file( $page_one ), md5_file( $page_two ), 'Pages render distinct content.' );
		$this->assertSame( md5_file( $page_last ), md5_file( $clamped ), 'An out-of-range page clamps to the last page.' );

		unlink( $page_one );
		unlink( $page_two );
		unlink( $page_last );
		unlink( $clamped );
	}

	/**
	 * Filter-registered font families resolve in the PDF (2.0, Feature
	 * 2.0-006 addon contract): an addon family with absolute tcpdf_file
	 * and ttf_file paths renders with no font fallback warning, while an
	 * unregistered family still substitutes the default with a warning.
	 *
	 * @return void
	 */
	public function test_filter_registered_font_resolves_in_pdf() {
		add_filter(
			'ppcert_designer_fonts',
			static function ( $fonts ) {
				$fonts['acme-serif'] = [
					'label'    => 'Acme Serif',
					'variants' => [
						'regular' => [
							'tcpdf_font' => 'playfairdisplay',
							'tcpdf_file' => PPCERT_PLUGIN_DIR . 'fonts/tcpdf/playfairdisplay.php',
							'ttf_file'   => PPCERT_PLUGIN_DIR . 'fonts/playfair-display/PlayfairDisplay-Regular.ttf',
							'metrics'    => [ 'ascent' => 885 ],
						],
					],
				];

				return $fonts;
			}
		);

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
					'id'    => 'el_addonfont',
					'type'  => 'text',
					'x'     => 100,
					'y'     => 200,
					'w'     => 600,
					'h'     => 60,
					'z'     => 1,
					'props' => [
						'text'        => 'Addon font body',
						'font_family' => 'acme-serif',
						'font_size'   => 24,
						'color'       => '#1f2a44',
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
		$this->assertFileExists( $path );

		$warnings = array_column( $renderer->get_last_render_warnings(), 'warning' );
		$this->assertNotContains( 'font_family_fallback', $warnings, 'The filtered family resolves without fallback' );
		unlink( $path );

		// The resolution itself prefers the addon's absolute paths (not
		// the bundled fonts/tcpdf/ construction).
		$method = new ReflectionMethod( PressPrimer_Certificate_PDF_Renderer::class, 'resolve_font' );
		$method->setAccessible( true );
		$resolved = $method->invoke( new PressPrimer_Certificate_PDF_Renderer(), 'acme-serif', false, false, 'el_x' );

		$this->assertSame( PPCERT_PLUGIN_DIR . 'fonts/tcpdf/playfairdisplay.php', $resolved['file'] );
		$this->assertSame( PPCERT_PLUGIN_DIR . 'fonts/playfair-display/PlayfairDisplay-Regular.ttf', $resolved['ttf'] );

		// A family absent from the registry still substitutes the
		// default with the fallback warning (deleted-font semantics).
		$renderer2 = new PressPrimer_Certificate_PDF_Renderer();
		$method->invoke( $renderer2, 'never-registered', false, false, 'el_y' );

		$warnings2 = array_column( $renderer2->get_last_render_warnings(), 'warning' );
		$this->assertContains( 'font_family_fallback', $warnings2 );
	}

	/**
	 * Build a 40x40 test image: left half fully transparent, right half
	 * opaque red. Saved in the requested format next to the PNG source of
	 * truth.
	 *
	 * @param string $format png | webp | avif.
	 * @return string File path.
	 */
	private function make_half_transparent_image( $format ) {
		$image = imagecreatetruecolor( 40, 40 );
		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		$transparent = imagecolorallocatealpha( $image, 0, 0, 0, 127 );
		$red         = imagecolorallocate( $image, 255, 0, 0 );

		imagefilledrectangle( $image, 0, 0, 19, 39, $transparent );
		imagefilledrectangle( $image, 20, 0, 39, 39, $red );

		$path = tempnam( sys_get_temp_dir(), 'ppcert-fixture' ) . '.' . $format;

		if ( 'webp' === $format ) {
			// Lossless where GD offers it (PHP 8.1+); otherwise quality 100,
			// which still converts through YUV - the assertions tolerate a
			// few units per channel for that reason.
			imagewebp( $image, $path, defined( 'IMG_WEBP_LOSSLESS' ) ? IMG_WEBP_LOSSLESS : 100 );
		} elseif ( 'avif' === $format ) {
			imageavif( $image, $path, 100 );
		} else {
			imagepng( $image, $path );
		}

		return $path;
	}

	/**
	 * A layout placing attachment $attachment_id contain-fitted into a
	 * 200x200 box at (100,100) over a blue page.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array
	 */
	private function image_over_blue_layout( $attachment_id ) {
		return [
			'layout_schema_version' => 1,
			'page'                  => [
				'size'        => 'a4',
				'orientation' => 'landscape',
				'width'       => 842,
				'height'      => 595,
			],
			'background'            => [
				'color'         => '#0000ff',
				'attachment_id' => 0,
			],
			'elements'              => [
				[
					'id'    => 'el_modernimg',
					'type'  => 'image',
					'x'     => 100,
					'y'     => 100,
					'w'     => 200,
					'h'     => 200,
					'z'     => 1,
					'props' => [
						'attachment_id' => $attachment_id,
						'fit'           => 'contain',
						'opacity'       => 1.0,
					],
				],
			],
		];
	}

	/**
	 * The renderable allowlist reflects this server's GD decoders (2.0,
	 * Feature 2.0-010): the three classic types always, WebP and AVIF
	 * only when the decoder function exists.
	 *
	 * @return void
	 */
	public function test_renderable_image_mimes_reflect_gd() {
		$mimes = PressPrimer_Certificate_PDF_Renderer::renderable_image_mimes();

		$this->assertSame( [ 'image/jpeg', 'image/png', 'image/gif' ], array_slice( $mimes, 0, 3 ) );
		$this->assertSame( function_exists( 'imagecreatefromwebp' ), in_array( 'image/webp', $mimes, true ) );
		$this->assertSame( function_exists( 'imagecreatefromavif' ), in_array( 'image/avif', $mimes, true ) );
	}

	/**
	 * WebP (and AVIF where GD can write it) render into the PDF without
	 * warnings, the transcode temp file is removed after the render, and
	 * the preview raster is pixel-identical to the PNG twin - transparency
	 * intact over the page color (2.0, Feature 2.0-010).
	 *
	 * @return void
	 */
	public function test_webp_and_avif_render_like_their_png_twin() {
		if ( ! function_exists( 'imagewebp' ) || ! function_exists( 'imagecreatefromwebp' ) ) {
			$this->markTestSkipped( 'GD without WebP support.' );
		}

		$formats = [ 'png', 'webp' ];

		if ( function_exists( 'imageavif' ) && function_exists( 'imagecreatefromavif' ) ) {
			$formats[] = 'avif';
		}

		$files   = [];
		$rasters = [];
		$id      = 700;

		foreach ( $formats as $format ) {
			$id++;
			$files[ $format ] = $this->make_half_transparent_image( $format );

			$GLOBALS['ppcert_test_attachment_files'][ $id ] = $files[ $format ];

			$layout = $this->image_over_blue_layout( $id );

			// PDF path: renders, no image warnings, and the only new temp
			// file left behind is the PDF itself (the transcoded PNG is
			// cleaned up).
			$before   = glob( sys_get_temp_dir() . '/ppcert*' );
			$renderer = new PressPrimer_Certificate_PDF_Renderer();
			$pdf      = $renderer->render_pdf( $layout, [], [ 'context' => 'preview' ] );

			$this->assertIsString( $pdf, $format . ': PDF renders' );
			$this->assertFileExists( $pdf );

			$warnings = array_column( $renderer->get_last_render_warnings(), 'warning' );
			$this->assertNotContains( 'attachment_not_image', $warnings, $format . ' is renderable' );
			$this->assertNotContains( 'attachment_unreadable', $warnings, $format . ' decodes' );
			$this->assertNotContains( 'attachment_missing', $warnings );

			// Compare basenames: macOS reports the temp dir as /var/... and
			// tempnam() as /private/var/..., the same directory.
			$after = array_diff( array_map( 'basename', glob( sys_get_temp_dir() . '/ppcert*' ) ), array_map( 'basename', $before ) );
			$this->assertSame( [ basename( $pdf ) ], array_values( $after ), $format . ': no transcode temp file survives the render' );
			unlink( $pdf );

			// Preview path at 72 dpi (1 px per pt): the same box math.
			$png = $renderer->render_png( $layout, [], [ 'dpi' => 72 ] );
			$this->assertIsString( $png, $format . ': preview renders' );

			$rasters[ $format ] = $png;
		}

		// Transparency: the image's left half shows the blue page exactly
		// (the page color is painted by the canvas, never decoded), the
		// right half is red within codec tolerance - in every format.
		foreach ( $rasters as $format => $png ) {
			$canvas = imagecreatefrompng( $png );
			$left   = imagecolorsforindex( $canvas, imagecolorat( $canvas, 150, 150 ) );
			$right  = imagecolorsforindex( $canvas, imagecolorat( $canvas, 250, 150 ) );

			$this->assertSame( [ 0, 0, 255 ], [ $left['red'], $left['green'], $left['blue'] ], $format . ': transparent half shows the page color' );
			$this->assertGreaterThanOrEqual( 250, $right['red'], $format . ': opaque half is red' );
			$this->assertLessThanOrEqual( 5, $right['green'] + $right['blue'], $format . ': opaque half is red' );
		}

		// Placement parity: every pixel of the WebP (and AVIF) raster is
		// within a few units of the PNG twin across the whole page - the
		// same box math placed the same pixels; only codec noise differs.
		$reference = imagecreatefrompng( $rasters['png'] );

		foreach ( $rasters as $format => $png ) {
			if ( 'png' === $format ) {
				continue;
			}

			$candidate = imagecreatefrompng( $png );
			$max_delta = 0;

			for ( $y = 90; $y < 310; $y += 2 ) {
				for ( $x = 90; $x < 310; $x += 2 ) {
					$a = imagecolorsforindex( $reference, imagecolorat( $reference, $x, $y ) );
					$b = imagecolorsforindex( $candidate, imagecolorat( $candidate, $x, $y ) );

					$max_delta = max( $max_delta, abs( $a['red'] - $b['red'] ), abs( $a['green'] - $b['green'] ), abs( $a['blue'] - $b['blue'] ) );
				}
			}

			$this->assertLessThanOrEqual( 8, $max_delta, $format . ': raster matches the PNG twin within codec tolerance across the image box and its surroundings' );
		}

		foreach ( $rasters as $png ) {
			unlink( $png );
		}

		foreach ( $files as $file ) {
			unlink( $file );
		}
	}

	/**
	 * A WebP attachment on a server without the decoder is skipped with
	 * the same warning as any non-image (the 1.1 behavior) - proven by
	 * the allowlist gate rather than by disabling GD: a file whose
	 * extension says WebP but whose bytes are not an image fails the
	 * getimagesize re-check.
	 *
	 * @return void
	 */
	public function test_undecodable_modern_image_warns_never_fatal() {
		$path = tempnam( sys_get_temp_dir(), 'ppcert-fixture' ) . '.webp';
		file_put_contents( $path, 'not an image' );
		$GLOBALS['ppcert_test_attachment_files'][777] = $path;

		$renderer = new PressPrimer_Certificate_PDF_Renderer();
		$pdf      = $renderer->render_pdf( $this->image_over_blue_layout( 777 ), [], [ 'context' => 'preview' ] );

		$this->assertIsString( $pdf );
		$this->assertContains( 'attachment_not_image', array_column( $renderer->get_last_render_warnings(), 'warning' ) );

		unlink( $pdf );
		unlink( $path );
	}

	/**
	 * ppcert_pdf_metadata (2.0, Enterprise contract item 10): the
	 * document's title, author, subject, keywords, and creator are
	 * filterable; values are sanitized; the defaults name PressPrimer
	 * as creator and compose the title from the render args. The XMP
	 * packet is cleartext even in the protected file, so it proves what
	 * the filter set.
	 *
	 * @return void
	 */
	public function test_pdf_metadata_filter() {
		$layout = $this->image_over_blue_layout( 0 );
		$layout['elements'] = [];

		$renderer = new PressPrimer_Certificate_PDF_Renderer();
		$default  = $renderer->render_pdf( $layout, [], [ 'context' => 'preview', 'title' => 'Botany 101', 'recipient_name' => 'Dana Whitfield' ] );
		$bytes    = (string) file_get_contents( $default );
		unlink( $default );

		$this->assertStringContainsString( 'Botany 101 - Dana Whitfield', $bytes, 'Default title composes title and recipient' );
		$this->assertStringContainsString( '<xmp:CreatorTool>PressPrimer Certificate</xmp:CreatorTool>', $bytes );

		$received = null;
		add_filter(
			'ppcert_pdf_metadata',
			static function ( $metadata, $args, $layout ) use ( &$received ) {
				$received = [ $metadata, $args, $layout ];

				return [
					'title'    => 'Acme <Academy> Certificate',
					'author'   => 'Acme Academy',
					'subject'  => 'Completion',
					'keywords' => 'acme, botany',
					'creator'  => 'Acme Credentials',
				];
			},
			10,
			3
		);

		$renderer = new PressPrimer_Certificate_PDF_Renderer();
		$path     = $renderer->render_pdf( $layout, [], [ 'context' => 'preview', 'title' => 'Botany 101', 'recipient_name' => 'Dana Whitfield' ] );
		$bytes    = (string) file_get_contents( $path );
		unlink( $path );

		$this->assertSame( 'Botany 101 - Dana Whitfield', $received[0]['title'], 'The filter receives the composed default' );
		$this->assertSame( 'PressPrimer Certificate', $received[0]['creator'] );
		$this->assertSame( 'preview', $received[1]['context'] );
		$this->assertArrayHasKey( 'page', $received[2] );

		$this->assertStringContainsString( 'Acme Certificate', $bytes, 'Title applied; the tag was stripped by sanitization' );
		$this->assertStringContainsString( '<xmp:CreatorTool>Acme Credentials</xmp:CreatorTool>', $bytes );
		$this->assertStringContainsString( 'Acme Academy', $bytes, 'Author in dc:creator' );
		$this->assertStringContainsString( 'Completion', $bytes, 'Subject in dc:description' );
		$this->assertStringContainsString( '<pdf:Keywords>acme, botany</pdf:Keywords>', $bytes );
	}
}
