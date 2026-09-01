<?php
/**
 * Third-party integration surface tests (2.0, Feature 2.0-007)
 *
 * Exercises the public API functions (FR-001), the value-only trigger
 * type flow end to end (FR-002 / TR-002), the positive merge-group
 * detection regression (FR-003), adapter discovery through the
 * ppcert_adapter_classes filter (FR-004), and duplicate-window scoping
 * through ppcert_issue_duplicate_ref (FR-005).
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.0.0
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/doubles/class-ppcert-test-value-only-adapter.php';

/**
 * Integration surface test case
 *
 * @since 2.0.0
 */
class Test_Integration_Surface extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Seeded published template id.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Reset state, register the value-only adapter, seed a template
	 * and a recipient.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_user_caps']    = true;
		$GLOBALS['ppcert_test_current_user'] = 1;
		$GLOBALS['ppcert_test_mail']         = [];
		$GLOBALS['ppcert_test_options']      = [];
		$GLOBALS['ppcert_test_posts']        = [];
		$GLOBALS['ppcert_test_user_meta']    = [];
		$GLOBALS['ppcert_test_bloginfo']     = [ 'name' => 'Sunrise Training Academy' ];
		$GLOBALS['ppcert_test_users']        = [
			7 => (object) [
				'display_name' => 'Dana Whitfield',
				'first_name'   => 'Dana',
				'last_name'    => 'Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];

		$adapter = new PPCert_Test_Value_Only_Adapter();
		$adapter->register();

		$layout = wp_json_encode(
			[
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
				'elements'              => [],
			]
		);

		$this->template_id = $this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'                  => 'tpl-surface-test',
				'title'                 => 'Credit Milestone Certificate',
				'status'                => 'published',
				'author_id'             => 1,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => $layout,
				'deleted_at'            => null,
			]
		);
	}

	/**
	 * PUT helper against the triggers controller.
	 *
	 * @param array $triggers Trigger payloads.
	 * @return WP_REST_Response|WP_Error
	 */
	private function put_triggers( array $triggers ) {
		$controller = new PressPrimer_Certificate_REST_Triggers_Controller();

		return $controller->replace_triggers(
			new WP_REST_Request(
				[
					'id'       => $this->template_id,
					'triggers' => $triggers,
				]
			)
		);
	}

	// -------------------------------------------------------------------
	// FR-002 / TR-002: value-only trigger types
	// -------------------------------------------------------------------

	/**
	 * The registry entry and the REST types listing both declare the
	 * value-only type sourceless.
	 *
	 * @return void
	 */
	public function test_value_only_type_declares_has_sources_false() {
		$type = PressPrimer_Certificate_Trigger_Registry::get_type( 'test_credits' );

		$this->assertNotNull( $type );
		$this->assertFalse( $type['has_sources'] );

		$controller = new PressPrimer_Certificate_REST_Triggers_Controller();
		$response   = $controller->get_types( new WP_REST_Request( [] ) );
		$types      = $response->get_data();

		$this->assertCount( 1, $types );
		$this->assertSame( 'test_credits', $types[0]['id'] );
		$this->assertFalse( $types[0]['has_sources'] );
	}

	/**
	 * Saving a value-only trigger stores a NULL source_ref no matter what
	 * ref the client sends.
	 *
	 * @return void
	 */
	public function test_rest_save_forces_null_ref_for_value_only_type() {
		$response = $this->put_triggers(
			[
				[
					'trigger_type' => 'test_credits',
					'source_ref'   => '55',
					'conditions'   => [ 'min_credits' => 20 ],
				],
			]
		);

		$this->assertSame( 200, $response->get_status() );

		$rows = $this->wpdb->rows( 'wp_ppcert_triggers' );
		$this->assertCount( 1, $rows );
		$this->assertNull( $rows[0]['source_ref'] );

		$conditions = json_decode( $rows[0]['conditions_json'], true );
		$this->assertSame( 20.0, (float) $conditions['min_credits'] );
	}

	/**
	 * find_active(null) matches NULL-ref rows plus the 'any' sentinel;
	 * inactive rows and refs of other rows stay excluded, and the exact-
	 * ref form never returns NULL rows.
	 *
	 * @return void
	 */
	public function test_find_active_null_ref_matrix() {
		$rows = [
			[ 'ref' => null, 'active' => 1 ],
			[ 'ref' => 'any', 'active' => 1 ],
			[ 'ref' => '305', 'active' => 1 ],
			[ 'ref' => null, 'active' => 0 ],
		];

		foreach ( $rows as $index => $row ) {
			$this->wpdb->seed_row(
				'wp_ppcert_triggers',
				[
					'uuid'            => 'trg-null-' . $index,
					'template_id'     => 20 + $index,
					'trigger_type'    => 'test_credits',
					'source_ref'      => $row['ref'],
					'conditions_json' => null,
					'is_active'       => $row['active'],
				]
			);
		}

		$null_matches = PressPrimer_Certificate_Trigger::find_active( 'test_credits', null );
		$refs         = array_map(
			static function ( $trigger ) {
				return $trigger->source_ref;
			},
			$null_matches
		);

		$this->assertCount( 2, $null_matches, 'NULL row and the any row match a null fired ref' );
		$this->assertContains( null, $refs );
		$this->assertContains( 'any', $refs );

		// The empty-string form behaves identically to null.
		$this->assertCount( 2, PressPrimer_Certificate_Trigger::find_active( 'test_credits', '' ) );

		// The exact-ref form is unchanged: no NULL rows in its results.
		$exact = PressPrimer_Certificate_Trigger::find_active( 'test_credits', '305' );
		$this->assertCount( 2, $exact );

		foreach ( $exact as $trigger ) {
			$this->assertNotNull( $trigger->source_ref );
		}
	}

	/**
	 * The FR-002 acceptance flow end to end: save -> NULL ref ->
	 * find_active(null) -> issue -> suppress -> reissue toggle.
	 *
	 * @return void
	 */
	public function test_value_only_flow_end_to_end() {
		$this->put_triggers(
			[
				[
					'trigger_type' => 'test_credits',
					'source_ref'   => '',
					'conditions'   => [ 'min_credits' => 10 ],
				],
			]
		);

		$matched = PressPrimer_Certificate_Trigger::find_active( 'test_credits', null );
		$this->assertCount( 1, $matched );
		$this->assertSame( $this->template_id, (int) $matched[0]->template_id );

		$args = [
			'template_id'  => $this->template_id,
			'recipient_id' => 7,
			'source_type'  => 'test_credits',
			'source_ref'   => null,
			'issued_by'    => 0,
			'context'      => [ 'credit_total' => 12 ],
		];

		$first = PressPrimer_Certificate_Issuance_Service::issue( $args );
		$this->assertIsInt( $first );

		// Duplicate suppression keys on the NULL ref.
		$second = PressPrimer_Certificate_Issuance_Service::issue( $args );
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) );

		// Reissue toggle on the value-only trigger issues a fresh row.
		$this->put_triggers(
			[
				[
					'trigger_type' => 'test_credits',
					'source_ref'   => '',
					'conditions'   => [
						'min_credits' => 10,
						'reissue'     => true,
					],
				],
			]
		);

		$third = PressPrimer_Certificate_Issuance_Service::issue( $args );
		$this->assertIsInt( $third );
		$this->assertNotSame( $first, $third );
		$this->assertCount( 2, $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) );
	}

	// -------------------------------------------------------------------
	// FR-003: positive merge-group detection
	// -------------------------------------------------------------------

	/**
	 * A group whose sub-keys are named like reserved field keys (all
	 * three: 'key', 'label', 'resolver' - US-3 acceptance) survives
	 * registration; the 1.x heuristic dropped the whole group. A stray
	 * scalar in a group drops per-field, never the whole group.
	 *
	 * @return void
	 */
	public function test_merge_group_with_reserved_sub_keys_survives() {
		add_filter(
			'ppcert_register_merge_fields',
			static function ( $fields ) {
				$fields['credits'] = [
					'key'      => [
						'key'    => 'credits.key',
						'label'  => 'Credit Key',
						'sample' => 'ce-2026',
					],
					'label'    => [
						'key'    => 'credits.label',
						'label'  => 'Credit Label',
						'sample' => 'CE',
					],
					'resolver' => [
						'key'    => 'credits.resolver',
						'label'  => 'Credit Resolver',
						'sample' => 'board',
					],
					'total'    => [
						'key'    => 'credits.total',
						'label'  => 'Credit Total',
						'sample' => '12',
					],
					'stray'    => 'scalar-junk',
				];

				return $fields;
			}
		);

		$fields = PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );

		$this->assertArrayHasKey( 'credits.key', $fields );
		$this->assertArrayHasKey( 'credits.label', $fields );
		$this->assertArrayHasKey( 'credits.resolver', $fields );
		$this->assertArrayHasKey( 'credits.total', $fields );
		$this->assertSame( 'Credit Label', $fields['credits.label']['label'] );
		$this->assertArrayNotHasKey( 'stray', $fields, 'The scalar drops per-field, not the group' );
	}

	/**
	 * Flat token-keyed entries (core shape) still register, and scalar
	 * junk is still dropped.
	 *
	 * @return void
	 */
	public function test_flat_fields_and_junk_unchanged() {
		add_filter(
			'ppcert_register_merge_fields',
			static function ( $fields ) {
				$fields['acme.flat'] = [
					'key'    => 'acme.flat',
					'label'  => 'Flat Field',
					'sample' => 'flat',
				];
				$fields['junk']      = 'not-a-field';

				return $fields;
			}
		);

		$fields = PressPrimer_Certificate_Merge_Field_Registry::get_fields( 'designer' );

		$this->assertArrayHasKey( 'acme.flat', $fields );
		$this->assertArrayNotHasKey( 'junk', $fields );
		$this->assertArrayHasKey( 'recipient.display_name', $fields, 'Core fields unaffected' );
	}

	// -------------------------------------------------------------------
	// FR-001: public API functions
	// -------------------------------------------------------------------

	/**
	 * ppcert_issue_certificate() and ppcert_find_certificate() round-trip
	 * through the real pipeline.
	 *
	 * @return void
	 */
	public function test_public_issue_and_find() {
		$id = ppcert_issue_certificate(
			[
				'template_id'  => $this->template_id,
				'recipient_id' => 7,
				'source_type'  => 'test_credits',
				'source_ref'   => null,
			]
		);

		$this->assertIsInt( $id );

		$found = ppcert_find_certificate( 7, $this->template_id, 'test_credits' );
		$this->assertNotNull( $found );
		$this->assertSame( $id, (int) $found->id );

		$this->assertNull( ppcert_find_certificate( 7, $this->template_id, 'other_type' ) );

		// Invalid template aborts with a WP_Error, not an exception.
		$error = ppcert_issue_certificate(
			[
				'template_id'  => 999999,
				'recipient_id' => 7,
			]
		);
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'ppcert_invalid_template', $error->get_error_code() );
	}

	/**
	 * ppcert_get_templates() lists summaries and honors the status filter.
	 *
	 * @return void
	 */
	public function test_public_get_templates() {
		$this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'        => 'tpl-surface-draft',
				'title'       => 'Draft Design',
				'status'      => 'draft',
				'layout_json' => '{"layout_schema_version":1,"elements":[]}',
				'deleted_at'  => null,
			]
		);

		$all = ppcert_get_templates();
		$this->assertCount( 2, $all );
		$this->assertSame(
			[ 'id', 'title', 'status' ],
			array_keys( $all[0] ),
			'Summaries expose exactly id/title/status'
		);

		$published = ppcert_get_templates( [ 'status' => 'published' ] );
		$this->assertCount( 1, $published );
		$this->assertSame( 'Credit Milestone Certificate', $published[0]['title'] );
	}

	/**
	 * The URL helpers build from a credential in any accepted input form
	 * and return '' for garbage.
	 *
	 * @return void
	 */
	public function test_public_url_helpers() {
		$id  = ppcert_issue_certificate(
			[
				'template_id'  => $this->template_id,
				'recipient_id' => 7,
			]
		);
		$row = PressPrimer_Certificate_Certificate::get( $id );

		$view = ppcert_certificate_view_url( $row->credential_id );
		$pdf  = ppcert_certificate_pdf_url( $row->credential_id );

		$this->assertNotSame( '', $view );
		$this->assertNotSame( '', $pdf );
		$this->assertStringContainsString( (string) $row->credential_id, str_replace( '-', '', $view . $pdf ) );

		$this->assertSame( '', ppcert_certificate_view_url( 'not a credential!' ) );
		$this->assertSame( '', ppcert_certificate_pdf_url( 'not a credential!' ) );
	}

	/**
	 * ppcert_render_certificate_pdf() renders an issued row to a real PDF
	 * temp file, and the email attachment path produces the same document
	 * (byte-identical after normalizing volatile PDF metadata).
	 *
	 * @return void
	 */
	public function test_public_render_and_email_attachment_parity() {
		$id = ppcert_issue_certificate(
			[
				'template_id'  => $this->template_id,
				'recipient_id' => 7,
			]
		);

		$path = ppcert_render_certificate_pdf( $id, 'download' );
		$this->assertIsString( $path );
		$this->assertFileExists( $path );
		$this->assertStringStartsWith( '%PDF', (string) file_get_contents( $path, false, null, 0, 4 ) );

		// The email path delegates to the same renderer (FR-001): the
		// attachment is the same document.
		$certificate = PressPrimer_Certificate_Certificate::get( $id );
		$method      = new ReflectionMethod( PressPrimer_Certificate_Email_Service::class, 'render_attachment' );
		$method->setAccessible( true );
		$attachment = $method->invoke( null, $certificate, null );

		$this->assertNotSame( '', $attachment );
		$this->assertSame( basename( $path ), basename( $attachment ), 'Same certificate-{DISPLAY-ID}.pdf filename' );
		$this->assertSame(
			$this->normalize_pdf( (string) file_get_contents( $path ) ),
			$this->normalize_pdf( (string) file_get_contents( $attachment ) ),
			'Attachment bytes match the direct render after stripping volatile metadata'
		);

		wp_delete_file( $path );
		wp_delete_file( $attachment );

		// Unknown id: a WP_Error, never a broken file.
		$error = ppcert_render_certificate_pdf( 999999 );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'ppcert_invalid_certificate', $error->get_error_code() );
	}

	/**
	 * Strip volatile PDF metadata (timestamps, document ids) so two
	 * renders of the same certificate compare equal.
	 *
	 * @param string $bytes Raw PDF bytes.
	 * @return string
	 */
	private function normalize_pdf( $bytes ) {
		$bytes = (string) preg_replace( '/\/(CreationDate|ModDate)\s*\([^)]*\)/', '', $bytes );

		return (string) preg_replace( '/\/ID\s*\[[^\]]*\]/', '', $bytes );
	}

	// -------------------------------------------------------------------
	// FR-004: adapter discovery
	// -------------------------------------------------------------------

	/**
	 * ppcert_adapter_classes admits real adapter subclasses and drops
	 * everything else; discovered adapters label their trigger types.
	 *
	 * @return void
	 */
	public function test_adapter_classes_filter() {
		add_filter(
			'ppcert_adapter_classes',
			static function ( $classes ) {
				$classes[] = 'PPCert_Test_Value_Only_Adapter';
				$classes[] = 'PPCert_Test_Value_Only_Adapter'; // Duplicate.
				$classes[] = 'stdClass';                        // Not an adapter.
				$classes[] = 'PPCert_No_Such_Class';            // Missing.
				$classes[] = 42;                                // Not a string.

				return $classes;
			}
		);

		$classes = PressPrimer_Certificate_Plugin::get_adapter_classes();

		$this->assertContains( 'PPCert_Test_Value_Only_Adapter', $classes );
		$this->assertNotContains( 'stdClass', $classes );
		$this->assertNotContains( 'PPCert_No_Such_Class', $classes );
		$this->assertNotContains( 42, $classes );
		$this->assertSame( $classes, array_unique( $classes ), 'No duplicate entries' );

		// Discovery feeds the label maps consumed by the admin lists.
		$details = PressPrimer_Certificate_Plugin::get_trigger_type_details();
		$this->assertSame( 'Test Credits', $details['test_credits']['integration'] );

		$map = PressPrimer_Certificate_Plugin::get_integration_map();
		$this->assertContains( 'test_credits', $map['Test Credits'] );
	}

	// -------------------------------------------------------------------
	// FR-005: duplicate-window scoping
	// -------------------------------------------------------------------

	/**
	 * ppcert_issue_duplicate_ref rewrites the ref used for the duplicate
	 * window only: a per-occurrence fired ref (automation run id) mapped
	 * to a stable key suppresses against the certificate stored under
	 * that key, while an inserted row always records the REAL fired ref.
	 *
	 * @return void
	 */
	public function test_duplicate_ref_filter_scopes_suppression() {
		// The stable-key certificate exists first (an adapter-style fire
		// whose ref IS the stable key - the filter passes it through).
		$first = PressPrimer_Certificate_Issuance_Service::issue(
			[
				'template_id'  => $this->template_id,
				'recipient_id' => 7,
				'source_type'  => 'test_credits',
				'source_ref'   => 'course-12',
			]
		);
		$this->assertIsInt( $first );

		add_filter(
			'ppcert_issue_duplicate_ref',
			static function ( $ref, $context ) {
				// Third-party fires carry per-run refs; dedup them
				// against the stable course key.
				if ( 'test_credits' === $context['source_type'] && 0 === strpos( (string) $ref, 'run-' ) ) {
					return 'course-12';
				}

				return $ref;
			},
			10,
			2
		);

		// A per-run fired ref suppresses against the stable-key row.
		$second = PressPrimer_Certificate_Issuance_Service::issue(
			[
				'template_id'  => $this->template_id,
				'recipient_id' => 7,
				'source_type'  => 'test_credits',
				'source_ref'   => 'run-2',
			]
		);
		$this->assertSame( $first, $second );
		$this->assertCount( 1, $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) );

		// When a row IS inserted (force), it records the real fired ref,
		// never the filtered key.
		$third = PressPrimer_Certificate_Issuance_Service::issue(
			[
				'template_id'  => $this->template_id,
				'recipient_id' => 7,
				'source_type'  => 'test_credits',
				'source_ref'   => 'run-3',
				'force'        => true,
			]
		);
		$this->assertIsInt( $third );

		$rows = $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() );
		$refs = array_column( $rows, 'source_ref' );

		sort( $refs );
		$this->assertSame( [ 'course-12', 'run-3' ], $refs, 'Stored refs stay the real fired refs' );
	}
}
