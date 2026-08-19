<?php
/**
 * Adapter interface and trigger registry unit tests
 *
 * Exercises the locked adapter contract's registration glue and the
 * trigger registry's conditions sanitizer via the test double adapter
 * (Prompt 1.5).
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/doubles/class-ppcert-test-double-adapter.php';
require_once __DIR__ . '/doubles/class-ppcert-test-double-hierarchical-adapter.php';

/**
 * Adapter interface test case
 *
 * @since 1.0.0
 */
class Test_Adapter_Interface extends TestCase {

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
	 * An available adapter registers its trigger type, merge fields, and
	 * listeners through register().
	 *
	 * @return void
	 */
	public function test_available_adapter_registers() {
		$adapter = new PPCert_Test_Double_Adapter();
		$adapter->register();

		$this->assertSame( 1, $adapter->listeners_registered );

		$types = PressPrimer_Certificate_Trigger_Registry::get_types();
		$this->assertArrayHasKey( 'double_lms', $types );

		$entry = $types['double_lms'];
		$this->assertSame( 'double_lms', $entry['id'] );
		$this->assertSame( 'Double LMS', $entry['label'] );
		$this->assertIsCallable( $entry['source_picker'] );
		$this->assertArrayHasKey( 'min_score', $entry['conditions_schema'] );

		// Merge fields flow through the shared filter, group-wise, and
		// are auto-tagged with the contributing trigger type so the
		// designer palette can scope them to the template's trigger.
		$fields = apply_filters( 'ppcert_register_merge_fields', [], 'designer' );
		$this->assertArrayHasKey( 'source', $fields );
		$this->assertArrayHasKey( 'course_title', $fields['source'] );
		$this->assertSame( 'Introduction to Botany', $fields['source']['course_title']['sample'] );
		$this->assertSame( [ 'double_lms' ], $fields['source']['course_title']['trigger_types'] );

		// The registration glue exposes the source noun and post types.
		$this->assertSame( 'Course', $types['double_lms']['source_label'] );
		$this->assertSame( [ 'page' ], $types['double_lms']['source_post_types'] );
	}

	/**
	 * An unavailable adapter registers nothing: no trigger type, no merge
	 * fields, no listeners (Feature 004 FR-002 availability gating).
	 *
	 * @return void
	 */
	public function test_unavailable_adapter_registers_nothing() {
		$adapter            = new PPCert_Test_Double_Adapter();
		$adapter->available = false;
		$adapter->register();

		$this->assertSame( 0, $adapter->listeners_registered );
		$this->assertSame( [], PressPrimer_Certificate_Trigger_Registry::get_types() );
		$this->assertSame( [], apply_filters( 'ppcert_register_merge_fields', [], 'designer' ) );
	}

	/**
	 * The source picker delegates to get_sources() including search.
	 *
	 * @return void
	 */
	public function test_source_picker_delegates_with_search() {
		$adapter = new PPCert_Test_Double_Adapter();
		$adapter->register();

		$entry   = PressPrimer_Certificate_Trigger_Registry::get_type( 'double_lms' );
		$sources = call_user_func( $entry['source_picker'], 'botany' );

		$this->assertCount( 1, $sources );
		$this->assertSame( 'Advanced Botany', $sources[0]['title'] );
	}

	/**
	 * Malformed registry entries are dropped; unregistered types are null.
	 *
	 * @return void
	 */
	public function test_registry_drops_malformed_entries() {
		add_filter(
			'ppcert_register_trigger_types',
			static function ( $types ) {
				$types[] = 'not-an-array';
				$types[] = [ 'label' => 'No id' ];
				$types[] = [
					'id'    => 'valid_type',
					'label' => 'Valid',
				];
				return $types;
			}
		);

		$types = PressPrimer_Certificate_Trigger_Registry::get_types();

		$this->assertSame( [ 'valid_type' ], array_keys( $types ) );
		$this->assertSame( [ 'reissue' ], array_keys( $types['valid_type']['conditions_schema'] ), 'Only the universal reissue toggle' );
		$this->assertNull( $types['valid_type']['source_picker'] );
		$this->assertNull( PressPrimer_Certificate_Trigger_Registry::get_type( 'missing_type' ) );
	}

	/**
	 * Conditions sanitization: unknown keys stripped, types coerced,
	 * numbers clamped (Feature 004 TR-002).
	 *
	 * @return void
	 */
	public function test_conditions_sanitization() {
		$adapter = new PPCert_Test_Double_Adapter();
		$adapter->register();

		$raw = [
			'min_score'  => '85.5',
			'notify'     => '1',
			'mode'       => 'bogus-mode',
			'note'       => "  Internal <script>alert(1)</script>note  ",
			'evil_key'   => 'payload',
			'__proto__'  => [ 'polluted' => true ],
		];

		$clean = PressPrimer_Certificate_Trigger_Registry::sanitize_conditions( 'double_lms', $raw );

		$this->assertSame(
			[ 'min_score', 'notify', 'mode', 'note', 'reissue' ],
			array_keys( $clean ),
			'Output must contain exactly the schema keys (plus the universal reissue toggle) - unknown keys stripped'
		);
		$this->assertSame( 85.5, $clean['min_score'] );
		$this->assertTrue( $clean['notify'] );
		$this->assertSame( 'full', $clean['mode'], 'Out-of-options select falls back to default' );
		$this->assertSame( 'Internal note', $clean['note'] );
	}

	/**
	 * Every registered type carries the universal reissue toggle - even
	 * types that declare no conditions of their own - defaulting off and
	 * coercing like any toggle.
	 *
	 * @return void
	 */
	public function test_universal_reissue_condition() {
		$adapter = new PPCert_Test_Double_Adapter();
		$adapter->register();

		$type = PressPrimer_Certificate_Trigger_Registry::get_type( 'double_lms' );
		$this->assertArrayHasKey( 'reissue', $type['conditions_schema'], 'The registry appends reissue to every schema' );
		$this->assertSame( 'toggle', $type['conditions_schema']['reissue']['type'] );
		$this->assertFalse( $type['conditions_schema']['reissue']['default'], 'Suppression stays the default' );

		$on  = PressPrimer_Certificate_Trigger_Registry::sanitize_conditions( 'double_lms', [ 'reissue' => '1' ] );
		$off = PressPrimer_Certificate_Trigger_Registry::sanitize_conditions( 'double_lms', [] );

		$this->assertTrue( $on['reissue'] );
		$this->assertFalse( $off['reissue'] );
	}

	/**
	 * Number conditions clamp to min/max; non-numeric input falls back to
	 * the default; absent keys receive defaults.
	 *
	 * @return void
	 */
	public function test_conditions_number_clamping_and_defaults() {
		$adapter = new PPCert_Test_Double_Adapter();
		$adapter->register();

		$clamped_high = PressPrimer_Certificate_Trigger_Registry::sanitize_conditions( 'double_lms', [ 'min_score' => 150 ] );
		$clamped_low  = PressPrimer_Certificate_Trigger_Registry::sanitize_conditions( 'double_lms', [ 'min_score' => -20 ] );
		$non_numeric  = PressPrimer_Certificate_Trigger_Registry::sanitize_conditions( 'double_lms', [ 'min_score' => 'eighty' ] );
		$absent       = PressPrimer_Certificate_Trigger_Registry::sanitize_conditions( 'double_lms', [] );

		$this->assertSame( 100.0, $clamped_high['min_score'] );
		$this->assertSame( 0.0, $clamped_low['min_score'] );
		$this->assertNull( $non_numeric['min_score'], 'Non-numeric falls back to the declared default (null)' );

		$this->assertNull( $absent['min_score'] );
		$this->assertFalse( $absent['notify'] );
		$this->assertSame( 'full', $absent['mode'] );
		$this->assertSame( '', $absent['note'] );
	}

	/**
	 * Sanitizing against an unregistered trigger type yields an empty set.
	 *
	 * @return void
	 */
	public function test_conditions_for_unregistered_type_empty() {
		$this->assertSame(
			[],
			PressPrimer_Certificate_Trigger_Registry::sanitize_conditions( 'ghost_type', [ 'anything' => 1 ] )
		);
	}

	/**
	 * The locked contract: the abstract declares exactly the seven
	 * documented abstract methods with the documented signatures. This
	 * test is the tripwire - if it fails, the interface changed and that
	 * requires explicit approval (Feature 004 FR-001).
	 *
	 * @return void
	 */
	public function test_locked_contract_signatures() {
		$reflection = new ReflectionClass( PressPrimer_Certificate_LMS_Adapter::class );

		$abstract_methods = array_map(
			static function ( $method ) {
				return $method->getName();
			},
			$reflection->getMethods( ReflectionMethod::IS_ABSTRACT )
		);
		sort( $abstract_methods );

		$this->assertSame(
			[
				'get_conditions_schema',
				'get_id',
				'get_merge_fields',
				'get_sources',
				'is_available',
				'register_listeners',
				'resolve_merge_data',
			],
			$abstract_methods
		);

		// Spot-check the documented signatures.
		$get_sources = $reflection->getMethod( 'get_sources' );
		$this->assertSame( 1, $get_sources->getNumberOfParameters() );
		$this->assertSame( 'string', (string) $get_sources->getParameters()[0]->getType() );
		$this->assertSame( 'array', (string) $get_sources->getReturnType() );

		$resolve = $reflection->getMethod( 'resolve_merge_data' );
		$this->assertSame( 'array', (string) $resolve->getParameters()[0]->getType() );
		$this->assertSame( 'array', (string) $resolve->getReturnType() );
	}

	// -------------------------------------------------------------------
	// Any-source triggers (Feature 1.1-002 contract).
	// -------------------------------------------------------------------

	/**
	 * The registry captures scope keys; flat types declare none.
	 *
	 * @return void
	 */
	public function test_registry_captures_scope_condition_keys() {
		( new PPCert_Test_Double_Adapter() )->register();
		( new PPCert_Test_Double_Hierarchical_Adapter() )->register();

		$types = PressPrimer_Certificate_Trigger_Registry::get_types();

		$this->assertSame( [], $types['double_lms']['scope_condition_keys'] );
		$this->assertSame( [ 'course_id' ], $types['double_lms_lesson']['scope_condition_keys'] );

		// Any labels flow through; an entry without one gets ''.
		$this->assertSame( 'Any course', $types['double_lms']['any_label'] );
		$this->assertSame( 'Any lesson in this course', $types['double_lms_lesson']['any_label'] );
	}

	/**
	 * Scope keys pass through conditions sanitization as id strings,
	 * outside the conditions_schema walk; junk stores null.
	 *
	 * @return void
	 */
	public function test_sanitize_conditions_passes_scope_keys() {
		( new PPCert_Test_Double_Hierarchical_Adapter() )->register();

		$clean = PressPrimer_Certificate_Trigger_Registry::sanitize_conditions(
			'double_lms_lesson',
			[
				'course_id' => '301',
				'min_score' => 80,
			]
		);

		$this->assertSame( '301', $clean['course_id'] );
		$this->assertSame( 80.0, $clean['min_score'] );

		$junk = PressPrimer_Certificate_Trigger_Registry::sanitize_conditions(
			'double_lms_lesson',
			[ 'course_id' => 'not-a-post-id' ]
		);

		$this->assertNull( $junk['course_id'] );

		$missing = PressPrimer_Certificate_Trigger_Registry::sanitize_conditions( 'double_lms_lesson', [] );

		$this->assertNull( $missing['course_id'] );
	}

	/**
	 * validate_trigger_source: specific refs and flat 'any' pass; a
	 * hierarchical 'any' requires its parent scope.
	 *
	 * @return void
	 */
	public function test_validate_trigger_source_scope_contract() {
		( new PPCert_Test_Double_Adapter() )->register();
		( new PPCert_Test_Double_Hierarchical_Adapter() )->register();

		$registry = 'PressPrimer_Certificate_Trigger_Registry';

		$this->assertTrue( $registry::validate_trigger_source( 'double_lms_lesson', '305', [] ) );
		$this->assertTrue( $registry::validate_trigger_source( 'double_lms', 'any', [] ) );
		$this->assertTrue( $registry::validate_trigger_source( 'double_lms_lesson', 'any', [ 'course_id' => '301' ] ) );
		$this->assertTrue( $registry::validate_trigger_source( 'unregistered_type', 'any', [] ) );

		$error = $registry::validate_trigger_source( 'double_lms_lesson', 'any', [] );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'ppcert_trigger_scope_required', $error->get_error_code() );

		$null_scope = $registry::validate_trigger_source( 'double_lms_lesson', 'any', [ 'course_id' => null ] );
		$this->assertInstanceOf( WP_Error::class, $null_scope );
	}

	/**
	 * The fire-time scope check: exact refs always pass; 'any' passes
	 * only when every declared key matches, failing closed on missing
	 * values from either side.
	 *
	 * @return void
	 */
	public function test_trigger_scope_matches_matrix() {
		$flat         = new PPCert_Test_Double_Adapter();
		$hierarchical = new PPCert_Test_Double_Hierarchical_Adapter();

		$specific = (object) [
			'source_ref' => '305',
			'conditions' => null,
		];
		$any      = (object) [
			'source_ref' => 'any',
			'conditions' => [ 'course_id' => '301' ],
		];
		$unscoped = (object) [
			'source_ref' => 'any',
			'conditions' => null,
		];

		// Exact refs never consult scope.
		$this->assertTrue( $hierarchical->scope_matches( $specific, [] ) );

		// Flat 'any' matches unconditionally (no declared keys).
		$this->assertTrue( $flat->scope_matches( $unscoped, [] ) );

		// Hierarchical 'any': in-course matches, everything else fails.
		$this->assertTrue( $hierarchical->scope_matches( $any, [ 'course_id' => '301' ] ) );
		$this->assertFalse( $hierarchical->scope_matches( $any, [ 'course_id' => '999' ] ) );
		$this->assertFalse( $hierarchical->scope_matches( $any, [ 'course_id' => '' ] ) );
		$this->assertFalse( $hierarchical->scope_matches( $any, [] ) );

		// Unscoped hierarchical 'any' (unreachable via save) never fires.
		$this->assertFalse( $hierarchical->scope_matches( $unscoped, [ 'course_id' => '301' ] ) );
	}

	/**
	 * find_active matches the exact ref plus the 'any' sentinel, and
	 * inactive rows stay excluded.
	 *
	 * @return void
	 */
	public function test_find_active_includes_any_triggers() {
		$wpdb = ppcert_tests_reset_wpdb();

		$rows = [
			[ 'ref' => '305', 'active' => 1 ],
			[ 'ref' => 'any', 'active' => 1 ],
			[ 'ref' => '999', 'active' => 1 ],
			[ 'ref' => 'any', 'active' => 0 ],
		];

		foreach ( $rows as $index => $row ) {
			$wpdb->seed_row(
				'wp_ppcert_triggers',
				[
					'uuid'            => 'trg-any-' . $index,
					'template_id'     => 10 + $index,
					'trigger_type'    => 'double_lms_lesson',
					'source_ref'      => $row['ref'],
					'conditions_json' => null,
					'is_active'       => $row['active'],
				]
			);
		}

		$matched = PressPrimer_Certificate_Trigger::find_active( 'double_lms_lesson', '305' );
		$refs    = array_map(
			static function ( $trigger ) {
				return (string) $trigger->source_ref;
			},
			$matched
		);

		sort( $refs );
		$this->assertSame( [ '305', 'any' ], $refs );

		// A ref with no specific row still matches the active 'any'.
		$only_any = PressPrimer_Certificate_Trigger::find_active( 'double_lms_lesson', '777' );
		$this->assertCount( 1, $only_any );
		$this->assertSame( 'any', $only_any[0]->source_ref );
	}
}
