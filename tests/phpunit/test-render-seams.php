<?php
/**
 * Render and layout-extension seam tests (2.1)
 *
 * Exercises Feature 2.1-004 FR-005: render variants on
 * render_certificate() and the public PDF route, `ppcert_render_layout`,
 * the layout `ext` key with `ppcert_layout_sanitize_extension` and its
 * stored-value carry-over (validator and the REST save),
 * `ppcert_layout_token_pages`; and FR-016's Preview_Service::flush_all().
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Render seams test case
 *
 * @since 2.1.0
 */
class Test_Render_Seams extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Seeded template id.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Seed a template; an administrator acts.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_transients();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options']      = [];
		$GLOBALS['ppcert_test_mail']         = [];
		$GLOBALS['ppcert_test_current_user'] = 1;
		$GLOBALS['ppcert_test_user_caps']    = true;
		$GLOBALS['ppcert_test_bloginfo']     = [ 'name' => 'Sunrise Training Academy' ];
		$GLOBALS['ppcert_test_users']        = [
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana Whitfield',
				'first_name'   => 'Dana',
				'last_name'    => 'Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';

		$this->template_id = $this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'                  => 'tmpl-render-1',
				'title'                 => 'Completion Certificate',
				'status'                => 'published',
				'author_id'             => 1,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => ppcert_tests_seams_layout(),
				'updated_at'            => '2026-10-01 10:00:00',
				'deleted_at'            => null,
			]
		);
	}

	/**
	 * Clear the request address.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/**
	 * A card layout an addon would supply for the 'card' variant.
	 *
	 * @return array
	 */
	private function card_layout() {
		$layout = json_decode( ppcert_tests_seams_layout(), true );

		$layout['page'] = [
			'size'        => 'custom',
			'orientation' => 'landscape',
			'width'       => 243,
			'height'      => 153,
		];

		foreach ( $layout['elements'] as $index => $element ) {
			$layout['elements'][ $index ]['x'] = 10;
			$layout['elements'][ $index ]['w'] = 200;
		}

		return $layout;
	}

	/**
	 * Unknown variants render the certificate under its own name; an
	 * addon-supplied variant renders its layout with -{variant}.
	 *
	 * @return void
	 */
	public function test_render_variants() {
		$id          = PressPrimer_Certificate_Issuance_Service::issue(
			[
				'template_id'  => $this->template_id,
				'recipient_id' => 7,
				'source_type'  => 'manual',
				'issued_by'    => 1,
			]
		);
		$certificate = PressPrimer_Certificate_Certificate::get( $id );
		$base        = PressPrimer_Certificate_Certificate::pdf_filename( $certificate );

		$plain = PressPrimer_Certificate_PDF_Renderer::render_certificate( $id, 'download', [ 'variant' => 'card' ] );
		$this->assertStringEndsWith( '/' . $base, $plain, 'No addon: the certificate itself, unsuffixed' );
		wp_delete_file( $plain );

		$seen = [];
		$card = $this->card_layout();

		add_filter(
			'ppcert_render_layout',
			static function ( $layout, $merge, $args ) use ( $card, &$seen ) {
				$seen[] = [ $args['variant'], isset( $args['certificate_id'] ) ? (int) $args['certificate_id'] : 0 ];

				return 'card' === $args['variant'] ? $card : $layout;
			},
			10,
			3
		);

		$path = PressPrimer_Certificate_PDF_Renderer::render_certificate( $id, 'download', [ 'variant' => 'Card!' ] );
		$this->assertStringEndsWith( '/' . str_replace( '.pdf', '-card.pdf', $base ), $path );
		$this->assertSame( '%PDF-', (string) file_get_contents( $path, false, null, 0, 5 ) );
		wp_delete_file( $path );
		$this->assertSame( [ 'card', $id ], end( $seen ), 'Sanitized variant and the certificate id reach the filter' );

		// The public route: ?variant=card.
		$controller = new PPCert_Test_Streaming_Certificates_Controller();
		$controller->download_pdf(
			new WP_REST_Request(
				[
					'credential_id' => (string) $certificate->credential_id,
					'variant'       => 'card',
				]
			)
		);
		$this->assertSame( str_replace( '.pdf', '-card.pdf', $base ), $controller->streamed['filename'] );
		$this->assertSame( [ 'card', $id ], end( $seen ), 'The route passes the certificate id too (Feature 2.1-007 FR-003)' );
		wp_delete_file( $controller->streamed['path'] );

		// A filter that answers with something unusable is ignored.
		ppcert_tests_reset_hooks();
		add_filter(
			'ppcert_render_layout',
			static function () {
				return false;
			}
		);
		$fallback = PressPrimer_Certificate_PDF_Renderer::render_certificate( $id, 'download', [ 'variant' => 'card' ] );
		$this->assertStringEndsWith( '/' . $base, $fallback );
		wp_delete_file( $fallback );
	}

	/**
	 * The `ext` key: dropped without an owner; kept as the owner cleans
	 * it; dropped when the owner returns null; stored values carry over
	 * while unclaimed; an active owner is authoritative.
	 *
	 * @return void
	 */
	public function test_layout_extensions() {
		$raw        = json_decode( ppcert_tests_seams_layout(), true );
		$raw['ext'] = [
			'educator'  => [
				'v'    => 1,
				'card' => [ 'size' => 'cr80' ],
			],
			'Bad Slug!' => [ 'x' => 1 ],
		];

		$clean = PressPrimer_Certificate_Layout_Validator::validate( $raw );
		$this->assertArrayNotHasKey( 'ext', $clean, 'No sanitizer: unknown data dropped, as in 2.0' );

		// Carry-over: unclaimed slugs keep the STORED value only.
		$stored = [ 'educator' => [ 'stored' => true ] ];
		$clean  = PressPrimer_Certificate_Layout_Validator::validate( $raw, [ 'stored_ext' => $stored ] );
		$this->assertSame( $stored, $clean['ext'] );

		$without = $raw;
		unset( $without['ext'] );
		$this->assertSame( $stored, PressPrimer_Certificate_Layout_Validator::validate( $without, [ 'stored_ext' => $stored ] )['ext'], 'The free designer omits ext; it survives' );

		// An owner answers for its slug only.
		$calls = [];

		add_filter(
			'ppcert_layout_sanitize_extension',
			static function ( $value, $slug, $input, $layout ) use ( &$calls ) {
				$calls[] = $slug;

				if ( 'educator' !== $slug ) {
					return $value;
				}

				if ( ! is_array( $input ) || 1 !== ( $input['v'] ?? 0 ) ) {
					return null;
				}

				return [
					'v'     => 1,
					'size'  => sanitize_key( (string) $input['card']['size'] ),
					'pages' => count( $layout['elements'] ),
				];
			},
			10,
			4
		);

		$clean = PressPrimer_Certificate_Layout_Validator::validate( $raw, [ 'stored_ext' => $stored ] );
		$this->assertSame(
			[
				'educator' => [
					'v'     => 1,
					'size'  => 'cr80',
					'pages' => 2,
				],
			],
			$clean['ext']
		);
		$this->assertSame( [ 'educator' ], $calls, 'Invalid slugs never reach the filter' );

		$this->assertArrayNotHasKey(
			'ext',
			PressPrimer_Certificate_Layout_Validator::validate( $without, [ 'stored_ext' => $stored ] ),
			'An active owner decides: no card submitted, none kept'
		);
	}

	/**
	 * The REST save carries stored ext data over while its owner is
	 * inactive.
	 *
	 * @return void
	 */
	public function test_rest_save_carries_ext_over() {
		$stored        = json_decode( ppcert_tests_seams_layout(), true );
		$stored['ext'] = [ 'educator' => [ 'card' => 'kept' ] ];

		$this->wpdb->mutate_row( PressPrimer_Certificate_Template::table(), $this->template_id, [ 'layout_json' => wp_json_encode( $stored ) ] );

		$controller = new PressPrimer_Certificate_REST_Templates_Controller();
		$response   = $controller->update_template(
			new WP_REST_Request(
				[
					'id'     => $this->template_id,
					'layout' => json_decode( ppcert_tests_seams_layout(), true ),
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'educator' => [ 'card' => 'kept' ] ], PressPrimer_Certificate_Template::get( $this->template_id )->layout['ext'] );
	}

	/**
	 * ppcert_layout_token_pages: tokens on addon pages are snapshotted.
	 *
	 * @return void
	 */
	public function test_token_pages_filter() {
		$layout = json_decode( ppcert_tests_seams_layout(), true );

		$this->assertNotContains( 'recipient.first_name', PressPrimer_Certificate_Merge_Field_Registry::extract_tokens( $layout ) );

		add_filter(
			'ppcert_layout_token_pages',
			static function ( $pages ) {
				$pages[] = [
					[
						'type'  => 'merge_field',
						'props' => [ 'token' => '{{recipient.first_name}}' ],
					],
				];

				return $pages;
			}
		);

		$tokens = PressPrimer_Certificate_Merge_Field_Registry::extract_tokens( $layout );
		$this->assertContains( 'recipient.first_name', $tokens );
		$this->assertContains( 'recipient.display_name', $tokens, 'The document pages still count' );

		$id = PressPrimer_Certificate_Issuance_Service::issue(
			[
				'template_id'  => $this->template_id,
				'recipient_id' => 7,
				'source_type'  => 'manual',
				'issued_by'    => 1,
			]
		);

		$this->assertSame( 'Dana', PressPrimer_Certificate_Certificate::get( $id )->merge_data['recipient.first_name'] );
	}

	/**
	 * flush_all() deletes every cached preview (the index guard stays).
	 *
	 * @return void
	 */
	public function test_flush_all_previews() {
		$dir = dirname( PressPrimer_Certificate_Preview_Service::preview_path( 'AAAA00000001' ) );
		wp_mkdir_p( $dir );

		// Other tests share the uploads folder.
		$before = count( (array) glob( $dir . '/*.png' ) );

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixtures.
		file_put_contents( $dir . '/AAAA00000001.png', 'x' );
		file_put_contents( $dir . '/BBBB00000002-abcdef012345.png', 'x' );
		file_put_contents( $dir . '/index.php', '<?php' );
		// phpcs:enable

		$this->assertSame( $before + 2, PressPrimer_Certificate_Preview_Service::flush_all() );
		$this->assertSame( [], glob( $dir . '/*.png' ) );
		$this->assertFileExists( $dir . '/index.php' );
	}
}
