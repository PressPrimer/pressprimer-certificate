<?php
/**
 * Templates REST controller tests (save / publish / trash / preview)
 *
 * The save path is the plugin's layout-sanitization chokepoint: whatever
 * the client submits, storage and the adopted response are the
 * validator's REBUILT document (Feature 001 FR-007).
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Templates REST test case
 *
 * @since 1.0.0
 */
class Test_Templates_REST extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Controller under test.
	 *
	 * @var PressPrimer_Certificate_REST_Templates_Controller
	 */
	private $controller;

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_user_caps']    = true;
		$GLOBALS['ppcert_test_current_user'] = 1;

		$this->controller = new PressPrimer_Certificate_REST_Templates_Controller();
	}

	/**
	 * A valid layout document (pre-validation shape).
	 *
	 * @return array Layout.
	 */
	private function valid_layout() {
		return [
			'layout_schema_version' => 1,
			'page'                  => [
				'size'        => 'a4',
				'orientation' => 'landscape',
			],
			'background'            => [ 'color' => '#ffffff' ],
			'elements'              => [
				[
					'id'    => 'el_testtxt1',
					'type'  => 'text',
					'x'     => 100,
					'y'     => 100,
					'w'     => 300,
					'h'     => 40,
					'z'     => 1,
					'props' => [
						'content'     => 'Hello',
						'font_family' => 'source-sans-3',
						'font_size'   => 18,
						'color'       => '#111111',
						'align'       => 'left',
						'line_height' => 1.2,
						'bold'        => false,
						'italic'      => false,
					],
				],
			],
		];
	}

	/**
	 * Create a template through the REST create route.
	 *
	 * @return array Full template payload.
	 */
	private function create_template() {
		$response = $this->controller->create_template(
			new WP_REST_Request( [ 'title' => 'Save Test' ] )
		);

		return $response->get_data();
	}

	/**
	 * Saving adopts the rebuilt document: hostile/unknown props never
	 * reach storage or the response.
	 *
	 * @return void
	 */
	public function test_save_strips_hostile_props_and_persists_rebuilt() {
		$template = $this->create_template();

		$layout = $this->valid_layout();

		// Hostile injections a devtools user could attempt.
		$layout['hostile_root']                      = 'nope';
		$layout['elements'][0]['props']['injected']  = '<script>alert(1)</script>';
		$layout['elements'][0]['onclick']            = 'evil()';
		$layout['elements'][0]['props']['font_size'] = 9999;

		$response = $this->controller->update_template(
			new WP_REST_Request(
				[
					'id'     => $template['id'],
					'layout' => $layout,
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		// Unknown root keys and props are gone; numbers clamped.
		$this->assertArrayNotHasKey( 'hostile_root', $data['layout'] );
		$element = $data['layout']['elements'][0];
		$this->assertArrayNotHasKey( 'onclick', $element );
		$this->assertArrayNotHasKey( 'injected', $element['props'] );
		$this->assertSame( 200.0, (float) $element['props']['font_size'] );

		// Storage matches the adopted response exactly.
		$row = PressPrimer_Certificate_Template::get( $template['id'] );
		$this->assertSame( $data['layout'], $row->layout );
	}

	/**
	 * An invalid layout rejects with a field error and changes nothing.
	 *
	 * @return void
	 */
	public function test_save_rejects_invalid_layout() {
		$template = $this->create_template();

		$layout                        = $this->valid_layout();
		$layout['elements'][0]['id']   = 'bad id!';
		$layout['elements'][0]['type'] = 'text';

		$result = $this->controller->update_template(
			new WP_REST_Request(
				[
					'id'     => $template['id'],
					'layout' => $layout,
				]
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );

		$row = PressPrimer_Certificate_Template::get( $template['id'] );
		$this->assertSame( $template['layout'], $row->layout );
	}

	/**
	 * updated_at mismatch returns 409 unless forced.
	 *
	 * @return void
	 */
	public function test_save_conflict_and_force() {
		$template = $this->create_template();

		$stale = '2020-01-01T00:00:00Z';

		$result = $this->controller->update_template(
			new WP_REST_Request(
				[
					'id'                  => $template['id'],
					'title'               => 'Renamed',
					'expected_updated_at' => $stale,
				]
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ppcert_template_conflict', $result->get_error_code() );

		// Force overrides the gate.
		$response = $this->controller->update_template(
			new WP_REST_Request(
				[
					'id'                  => $template['id'],
					'title'               => 'Renamed',
					'expected_updated_at' => $stale,
					'force'               => true,
				]
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Renamed', $response->get_data()['title'] );
	}

	/**
	 * Status transitions validate against the fixed set.
	 *
	 * @return void
	 */
	public function test_status_transitions() {
		$template = $this->create_template();

		$response = $this->controller->update_template(
			new WP_REST_Request(
				[
					'id'     => $template['id'],
					'status' => 'published',
				]
			)
		);
		$this->assertSame( 'published', $response->get_data()['status'] );

		$result = $this->controller->update_template(
			new WP_REST_Request(
				[
					'id'     => $template['id'],
					'status' => 'live',
				]
			)
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ppcert_invalid_status', $result->get_error_code() );
	}

	/**
	 * DELETE soft-deletes: gone from get/get_all, row retained.
	 *
	 * @return void
	 */
	public function test_trash_soft_deletes() {
		$template = $this->create_template();

		$response = $this->controller->trash_template(
			new WP_REST_Request( [ 'id' => $template['id'] ] )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertNull( PressPrimer_Certificate_Template::get( $template['id'] ) );
		$this->assertCount( 0, PressPrimer_Certificate_Template::get_all() );

		// The row itself survives (issued certificates reference it).
		$rows = $this->wpdb->rows( 'wp_ppcert_templates' );
		$this->assertCount( 1, $rows );
		$this->assertNotEmpty( $rows[0]['deleted_at'] );
	}

	/**
	 * Preview renders a sample-data PDF into the previews directory and
	 * sweeps stale files.
	 *
	 * @return void
	 */
	public function test_preview_renders_and_sweeps() {
		$template = $this->create_template();

		$uploads = wp_upload_dir();
		$dir     = $uploads['basedir'] . '/ppcert-previews';
		wp_mkdir_p( $dir );

		// A stale preview from over an hour ago gets swept.
		$stale = $dir . '/preview-99-stalefile.pdf';
		file_put_contents( $stale, 'old' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		touch( $stale, time() - 2 * HOUR_IN_SECONDS );

		$response = $this->controller->preview_template(
			new WP_REST_Request( [ 'id' => $template['id'] ] )
		);

		$this->assertSame( 200, $response->get_status() );
		$url = $response->get_data()['url'];
		$this->assertStringContainsString( 'ppcert-previews/preview-' . $template['id'] . '-', $url );

		$file = $dir . '/' . basename( $url );
		$this->assertFileExists( $file );

		// It is a real PDF.
		$this->assertStringStartsWith( '%PDF', (string) file_get_contents( $file, false, null, 0, 4 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test assertion on a local file.

		$this->assertFileDoesNotExist( $stale );

		wp_delete_file( $file );
	}

	/**
	 * Creating from a Geometric starter clones its default
	 * certificate_name into the new template's settings (2.0, Feature
	 * 2.0-001 FR-002); 1.0 starters keep storing no settings.
	 *
	 * @return void
	 */
	public function test_create_from_starter_carries_certificate_name() {
		$response = $this->controller->create_template(
			new WP_REST_Request( [ 'starter' => 'starter-geometric-landscape' ] )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );

		$rows = $this->wpdb->rows( 'wp_ppcert_templates' );
		$row  = end( $rows );

		$this->assertSame(
			[ 'certificate_name' => '{{source.course_title}} Certificate' ],
			json_decode( (string) $row['settings_json'], true )
		);

		// A 1.0 starter (no pattern) stores no settings.
		$this->controller->create_template(
			new WP_REST_Request( [ 'starter' => 'starter-modern-landscape' ] )
		);

		$rows = $this->wpdb->rows( 'wp_ppcert_templates' );
		$row  = end( $rows );

		$this->assertArrayNotHasKey( 'settings_json', array_filter( $row, static fn( $v ) => null !== $v ), 'No pattern, no settings row.' );
	}

	/**
	 * POST /templates/{id}/test-email (2.0, Feature 2.0-003 TR-001/TR-003):
	 * unknown templates 404, successes report the sent-to address, and
	 * the per-user throttle turns the sixth call inside the window into
	 * a 429 - even for a fully-authorized user.
	 *
	 * @return void
	 */
	public function test_test_email_route_validates_and_throttles() {
		ppcert_tests_reset_transients();

		$GLOBALS['ppcert_test_mail']         = [];
		$GLOBALS['ppcert_test_current_user'] = 7;
		$GLOBALS['ppcert_test_users']        = [
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];

		$template_id = $this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'        => 'tpl-test-email',
				'title'       => 'Completion Award',
				'status'      => 'published',
				'layout_json' => '{"layout_schema_version":2,"elements":[]}',
				'deleted_at'  => null,
			]
		);

		// Unknown template: 404.
		$missing = $this->controller->send_test_email( new WP_REST_Request( [ 'id' => 999 ] ) );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'ppcert_template_not_found', $missing->get_error_code() );

		// Success: { success, message } with the current user's address.
		$response = $this->controller->send_test_email( new WP_REST_Request( [ 'id' => $template_id ] ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $response->get_data()['success'] );
		$this->assertStringContainsString( 'dana@example.test', $response->get_data()['message'] );
		$this->assertCount( 1, $GLOBALS['ppcert_test_mail'] );

		// The throttle: five per minute, the sixth is a 429.
		for ( $i = 0; $i < 4; $i++ ) {
			$this->controller->send_test_email( new WP_REST_Request( [ 'id' => $template_id ] ) );
		}

		$throttled = $this->controller->send_test_email( new WP_REST_Request( [ 'id' => $template_id ] ) );
		$this->assertInstanceOf( WP_Error::class, $throttled );
		$this->assertSame( 'ppcert_test_email_throttled', $throttled->get_error_code() );
		$this->assertSame( 429, $throttled->get_error_data()['status'] );
		$this->assertCount( 5, $GLOBALS['ppcert_test_mail'], 'The sixth call never reached the mailer.' );
	}
}
