<?php
/**
 * Triggers REST controller tests (Feature 001 FR-006, Feature 004 TR-002)
 *
 * Driven by the Phase 1 test-double adapter: type listing, source
 * search, the schema-walking conditions sanitization on save, replace-
 * set semantics, and orphaned-source/inert-type enrichment.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/doubles/class-ppcert-test-double-hierarchical-adapter.php';

/**
 * Triggers REST test case
 *
 * @since 1.0.0
 */
class Test_Triggers_REST extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Controller under test.
	 *
	 * @var PressPrimer_Certificate_REST_Triggers_Controller
	 */
	private $controller;

	/**
	 * Template row id for trigger attachment.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Reset state, register the double adapter, seed a template.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_user_caps']    = true;
		$GLOBALS['ppcert_test_current_user'] = 1;
		$GLOBALS['ppcert_test_posts']        = [];

		$adapter = new PPCert_Test_Double_Adapter();
		$adapter->register();

		$this->template_id = $this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'        => 'tpl-trigger-test',
				'title'       => 'Trigger Test',
				'status'      => 'draft',
				'page_size'   => 'a4',
				'orientation' => 'landscape',
				'layout_json' => '{"layout_schema_version":1,"elements":[]}',
				'updated_at'  => '2026-07-01 00:00:00',
				'deleted_at'  => null,
			]
		);

		$this->controller = new PressPrimer_Certificate_REST_Triggers_Controller();
	}

	/**
	 * PUT helper.
	 *
	 * @param array $triggers Trigger payloads.
	 * @return WP_REST_Response|WP_Error
	 */
	private function put_triggers( array $triggers ) {
		return $this->controller->replace_triggers(
			new WP_REST_Request(
				[
					'id'       => $this->template_id,
					'triggers' => $triggers,
				]
			)
		);
	}

	/**
	 * Types listing exposes the registered adapter without callables.
	 *
	 * @return void
	 */
	public function test_types_listing() {
		$response = $this->controller->get_types( new WP_REST_Request( [] ) );
		$types    = $response->get_data();

		$this->assertCount( 1, $types );
		$this->assertSame( 'double_lms', $types[0]['id'] );
		$this->assertSame( 'Double LMS', $types[0]['label'] );
		$this->assertSame( 'Course', $types[0]['source_label'] );
		$this->assertTrue( $types[0]['has_sources'] );
		$this->assertSame( [ 'page' ], $types[0]['source_post_types'] );

		$schema = $types[0]['conditions_schema'];
		$this->assertSame(
			[ 'min_score', 'notify', 'mode', 'note', 'reissue' ],
			array_keys( $schema ),
			'Own schema keys plus the universal reissue toggle'
		);
		$this->assertSame( 0.0, $schema['min_score']['min'] );
		$this->assertSame( 100.0, $schema['min_score']['max'] );
		$this->assertSame( [ 'full', 'lessons_only' ], $schema['mode']['options'] );

		// The schema's help string reaches the client (condition
		// tooltips); fields without help omit the key.
		$this->assertSame(
			'Leave blank to award on any passing score.',
			$schema['min_score']['help']
		);
		$this->assertArrayNotHasKey( 'help', $schema['notify'] );

		// Callables never serialize into the response.
		$this->assertArrayNotHasKey( 'source_picker', $types[0] );
	}

	/**
	 * ?type=&search= runs the type's source picker.
	 *
	 * @return void
	 */
	public function test_sources_search() {
		$response = $this->controller->get_types(
			new WP_REST_Request(
				[
					'type'   => 'double_lms',
					'search' => 'botany',
				]
			)
		);

		$this->assertSame(
			[
				[
					'id'    => '102',
					'title' => 'Advanced Botany',
				],
			],
			$response->get_data()
		);
	}

	/**
	 * The schema walk owns conditions_json: junk in, exact schema out.
	 *
	 * @return void
	 */
	public function test_put_sanitizes_conditions_against_the_schema() {
		$response = $this->put_triggers(
			[
				[
					'trigger_type' => 'double_lms',
					'source_ref'   => '101',
					'conditions'   => [
						'min_score'   => '150',
						'notify'      => 'true',
						'mode'        => 'hacked_mode',
						'note'        => "  <b>keep</b> me\n",
						'unknown_key' => 'strip me',
					],
					'is_active'    => true,
				],
			]
		);

		$this->assertSame( 200, $response->get_status() );

		$rows       = $this->wpdb->rows( 'wp_ppcert_triggers' );
		$conditions = json_decode( $rows[0]['conditions_json'], true );

		// Exactly the schema's keys (plus the universal reissue
		// toggle), coerced and clamped.
		$this->assertSame(
			[ 'min_score', 'notify', 'mode', 'note', 'reissue' ],
			array_keys( $conditions )
		);
		$this->assertSame( 100.0, (float) $conditions['min_score'] );
		$this->assertTrue( $conditions['notify'] );
		$this->assertSame( 'full', $conditions['mode'] );
		$this->assertSame( 'keep me', $conditions['note'] );
		$this->assertFalse( $conditions['reissue'], 'Reissue defaults off when not sent' );
	}

	/**
	 * PUT is a replace-set: the new payload fully defines the row.
	 *
	 * @return void
	 */
	public function test_put_replaces_the_set() {
		$this->put_triggers(
			[
				[
					'trigger_type' => 'double_lms',
					'source_ref'   => '101',
				],
			]
		);

		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_triggers' ) );

		$response = $this->put_triggers(
			[
				[
					'trigger_type' => 'double_lms',
					'source_ref'   => '102',
					'is_active'    => false,
				],
			]
		);

		$data = $response->get_data();
		$this->assertCount( 1, $data );
		$this->assertSame( '102', $data[0]['source_ref'] );
		$this->assertFalse( $data[0]['is_active'] );
		$this->assertCount( 1, $this->wpdb->rows( 'wp_ppcert_triggers' ) );
	}

	/**
	 * 1.0 scope decision (2026-07-22): one trigger per template. The PUT
	 * rejects a larger set outright - nothing is written - while the
	 * model and issuance engine stay multi-trigger capable underneath.
	 *
	 * @return void
	 */
	public function test_put_caps_at_one_trigger() {
		$response = $this->put_triggers(
			[
				[
					'trigger_type' => 'double_lms',
					'source_ref'   => '101',
				],
				[
					'trigger_type' => 'double_lms',
					'source_ref'   => '102',
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'ppcert_too_many_triggers', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_triggers' ) );
	}

	/**
	 * Enrichment resolves sources and flags orphans and inert types.
	 *
	 * @return void
	 */
	public function test_enrichment_flags_orphans_and_inert_types() {
		// Seeded at the model layer: the REST cap allows one trigger,
		// but stored multi-trigger data (a future release, or rows
		// written before the cap) must still enrich correctly on GET.
		PressPrimer_Certificate_Trigger::replace_for_template(
			$this->template_id,
			[
				[
					'trigger_type' => 'double_lms',
					'source_ref'   => '101',
					'conditions'   => [],
					'is_active'    => true,
				],
				[
					'trigger_type' => 'double_lms',
					'source_ref'   => '999',
					'conditions'   => [],
					'is_active'    => true,
				],
				[
					'trigger_type' => 'vanished_lms',
					'source_ref'   => '55',
					'conditions'   => [ 'legacy_setting' => 'keep' ],
					'is_active'    => true,
				],
				[
					'trigger_type' => 'lms_learndash',
					'source_ref'   => '77',
					'conditions'   => [],
					'is_active'    => true,
				],
			]
		);

		$response = $this->controller->get_triggers(
			new WP_REST_Request( [ 'id' => $this->template_id ] )
		);

		$data = $response->get_data();

		$this->assertSame( 'Sample Course', $data[0]['source_label'] );
		$this->assertTrue( $data[0]['source_found'] );

		// Unknown source id: the orphan badge case.
		$this->assertFalse( $data[1]['source_found'] );

		// Inert type: unavailable, conditions preserved; a third-party
		// type has no known integration name.
		$this->assertFalse( $data[2]['type_available'] );
		$this->assertSame( [ 'legacy_setting' => 'keep' ], $data[2]['conditions'] );
		$this->assertSame( '', $data[2]['integration'] );

		// A BUNDLED type with its plugin deactivated still names its
		// integration - the Award card says which plugin to reactivate.
		$this->assertFalse( $data[3]['type_available'] );
		$this->assertSame( 'LearnDash', $data[3]['integration'] );
	}

	/**
	 * Routes require ppcert_manage_templates.
	 *
	 * @return void
	 */
	public function test_permission_callback_requires_capability() {
		$GLOBALS['ppcert_test_user_caps'] = [];
		$this->assertFalse( $this->controller->can_manage() );

		$GLOBALS['ppcert_test_user_caps'] = [ 'ppcert_manage_templates' ];
		$this->assertTrue( $this->controller->can_manage() );
	}

	// -------------------------------------------------------------------
	// Any-source triggers (Feature 1.1-002).
	// -------------------------------------------------------------------

	/**
	 * The types listing exposes each type's Any label.
	 *
	 * @return void
	 */
	public function test_types_listing_exposes_any_label() {
		$response = $this->controller->get_types( new WP_REST_Request( [] ) );
		$types    = $response->get_data();

		$this->assertSame( 'Any course', $types[0]['any_label'] );
	}

	/**
	 * Enrichment labels 'any' triggers with the type's Any wording and
	 * never flags them orphaned.
	 *
	 * @return void
	 */
	public function test_enrich_labels_any_triggers() {
		$this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'uuid'            => 'trg-any-enrich',
				'template_id'     => $this->template_id,
				'trigger_type'    => 'double_lms',
				'source_ref'      => 'any',
				'conditions_json' => null,
				'is_active'       => 1,
			]
		);

		$response = $this->controller->get_triggers(
			new WP_REST_Request( [ 'id' => $this->template_id ] )
		);
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( 'any', $data[0]['source_ref'] );
		$this->assertSame( 'Any course', $data[0]['source_label'] );
		$this->assertTrue( $data[0]['source_found'] );
	}

	/**
	 * A flat type saves an 'any' trigger with no scope requirement.
	 *
	 * @return void
	 */
	public function test_put_flat_any_trigger_saves() {
		$response = $this->put_triggers(
			[
				[
					'trigger_type' => 'double_lms',
					'source_ref'   => 'any',
					'conditions'   => [],
				],
			]
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$rows = $this->wpdb->rows( 'wp_ppcert_triggers' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'any', $rows[0]['source_ref'] );
	}

	/**
	 * A hierarchical 'any' trigger without its parent scope is a 400.
	 *
	 * @return void
	 */
	public function test_put_hierarchical_any_without_scope_rejected() {
		( new PPCert_Test_Double_Hierarchical_Adapter() )->register();

		$response = $this->put_triggers(
			[
				[
					'trigger_type' => 'double_lms_lesson',
					'source_ref'   => 'any',
					'conditions'   => [],
				],
			]
		);

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'ppcert_trigger_scope_required', $response->get_error_code() );
		$this->assertCount( 0, $this->wpdb->rows( 'wp_ppcert_triggers' ) );
	}

	/**
	 * A scoped hierarchical 'any' trigger saves with the parent id
	 * carried in conditions as a sanitized id string.
	 *
	 * @return void
	 */
	public function test_put_hierarchical_any_with_scope_saves() {
		( new PPCert_Test_Double_Hierarchical_Adapter() )->register();

		$response = $this->put_triggers(
			[
				[
					'trigger_type' => 'double_lms_lesson',
					'source_ref'   => 'any',
					'conditions'   => [ 'course_id' => 301 ],
				],
			]
		);

		$this->assertInstanceOf( WP_REST_Response::class, $response );

		$rows = $this->wpdb->rows( 'wp_ppcert_triggers' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'any', $rows[0]['source_ref'] );

		$conditions = json_decode( (string) $rows[0]['conditions_json'], true );
		$this->assertSame( '301', $conditions['course_id'] );
	}
}
