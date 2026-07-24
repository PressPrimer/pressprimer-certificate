<?php
/**
 * Template model tests
 *
 * Listing (soft-delete aware, newest-updated first), creation from a
 * validator-clean layout, and the bundled starter definitions that feed
 * the template gallery (Feature 001 FR-001).
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Template model test case
 *
 * @since 1.0.0
 */
class Test_Template_Model extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();
	}

	/**
	 * A minimal validator-clean layout document.
	 *
	 * @param array $overrides Top-level overrides.
	 * @return array Layout document.
	 */
	private function minimal_layout( array $overrides = [] ) {
		return array_merge(
			[
				'layout_schema_version' => 1,
				'page'                  => [
					'size'        => 'a4',
					'orientation' => 'landscape',
					'background'  => [ 'color' => '#ffffff' ],
				],
				'elements'              => [],
			],
			$overrides
		);
	}

	/**
	 * Seed a template row.
	 *
	 * @param array $overrides Column overrides.
	 * @return int Row id.
	 */
	private function seed_template( array $overrides = [] ) {
		return $this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			array_merge(
				[
					'uuid'                  => 'tpl-0000-0000-0000',
					'title'                 => 'Course Completion',
					'status'                => 'published',
					'author_id'             => 1,
					'page_size'             => 'a4',
					'orientation'           => 'landscape',
					'layout_schema_version' => 1,
					'layout_json'           => '{"layout_schema_version":1,"elements":[]}',
					'is_starter'            => 0,
					'created_at'            => '2026-07-01 10:00:00',
					'updated_at'            => '2026-07-01 10:00:00',
					'deleted_at'            => null,
				],
				$overrides
			)
		);
	}

	/**
	 * get_all() skips soft-deleted rows and orders newest-updated first.
	 *
	 * @return void
	 */
	public function test_get_all_excludes_deleted_and_orders_by_updated_desc() {
		$older = $this->seed_template(
			[
				'uuid'       => 'tpl-older',
				'title'      => 'Older',
				'updated_at' => '2026-07-01 10:00:00',
			]
		);
		$newer = $this->seed_template(
			[
				'uuid'       => 'tpl-newer',
				'title'      => 'Newer',
				'updated_at' => '2026-07-10 10:00:00',
			]
		);
		$this->seed_template(
			[
				'uuid'       => 'tpl-deleted',
				'title'      => 'Deleted',
				'updated_at' => '2026-07-15 10:00:00',
				'deleted_at' => '2026-07-16 10:00:00',
			]
		);

		$rows = PressPrimer_Certificate_Template::get_all();

		$this->assertCount( 2, $rows );
		$this->assertSame( $newer, (int) $rows[0]->id );
		$this->assertSame( $older, (int) $rows[1]->id );
	}

	/**
	 * get_all() hydrates layout_json onto a layout array property.
	 *
	 * @return void
	 */
	public function test_get_all_hydrates_layout() {
		$this->seed_template();

		$rows = PressPrimer_Certificate_Template::get_all();

		$this->assertIsArray( $rows[0]->layout );
		$this->assertSame( 1, $rows[0]->layout['layout_schema_version'] );
	}

	/**
	 * create() rejects a missing layout.
	 *
	 * @return void
	 */
	public function test_create_requires_layout() {
		$result = PressPrimer_Certificate_Template::create( [ 'title' => 'No Layout' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ppcert_invalid_layout', $result->get_error_code() );
	}

	/**
	 * create() persists the row and mirrors page metadata from the layout.
	 *
	 * @return void
	 */
	public function test_create_persists_row_from_layout() {
		$layout = $this->minimal_layout(
			[
				'page' => [
					'size'        => 'letter',
					'orientation' => 'portrait',
					'background'  => [ 'color' => '#ffffff' ],
				],
			]
		);

		$id = PressPrimer_Certificate_Template::create(
			[
				'title'     => 'My New Template',
				'layout'    => $layout,
				'author_id' => 5,
			]
		);

		$this->assertIsInt( $id );

		$row = PressPrimer_Certificate_Template::get( $id );

		$this->assertSame( 'My New Template', $row->title );
		$this->assertSame( 'draft', $row->status );
		$this->assertSame( 5, (int) $row->author_id );
		$this->assertSame( 'letter', $row->page_size );
		$this->assertSame( 'portrait', $row->orientation );
		$this->assertSame( 0, (int) $row->is_starter );
		$this->assertSame( $layout, $row->layout );
	}

	/**
	 * create() rejects unknown statuses back to draft and truncates titles.
	 *
	 * @return void
	 */
	public function test_create_defaults_status_and_truncates_title() {
		$id = PressPrimer_Certificate_Template::create(
			[
				'title'  => str_repeat( 'T', 250 ),
				'status' => 'sneaky',
				'layout' => $this->minimal_layout(),
			]
		);

		$row = PressPrimer_Certificate_Template::get( $id );

		$this->assertSame( 'draft', $row->status );
		$this->assertSame( 200, strlen( $row->title ) );
	}

	/**
	 * get_starters() exposes the bundled definitions with _meta stripped.
	 *
	 * @return void
	 */
	public function test_get_starters_reads_bundled_definitions() {
		$starters = PressPrimer_Certificate_Template::get_starters();

		$this->assertNotEmpty( $starters );

		foreach ( $starters as $slug => $starter ) {
			$this->assertSame( $slug, $starter['slug'] );
			$this->assertNotSame( '', $starter['label'] );
			$this->assertIsArray( $starter['layout'] );
			$this->assertArrayNotHasKey( '_meta', $starter['layout'] );
		}
	}

	/**
	 * Every bundled starter layout must be validator-clean.
	 *
	 * The gallery clones these documents straight into new templates, so a
	 * starter that fails validation would break create-from-starter.
	 *
	 * @return void
	 */
	public function test_bundled_starters_pass_the_layout_validator() {
		$starters = PressPrimer_Certificate_Template::get_starters();

		foreach ( $starters as $slug => $starter ) {
			$result = PressPrimer_Certificate_Layout_Validator::validate( $starter['layout'] );

			$this->assertNotInstanceOf(
				WP_Error::class,
				$result,
				sprintf(
					'Starter "%s" failed validation: %s',
					$slug,
					$result instanceof WP_Error ? $result->get_error_message() : ''
				)
			);
		}
	}
}

/**
 * Templates admin list query + duplication (Phase 5B items 3-4)
 *
 * @since 1.0.0
 */
class Test_Template_List_And_Duplicate extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Reset state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();
	}

	/**
	 * Seed a template row.
	 *
	 * @param array $overrides Column overrides.
	 * @return int Row id.
	 */
	private function seed_template( array $overrides = [] ) {
		static $seq = 0;
		$seq++;

		return $this->wpdb->seed_row(
			'wp_ppcert_templates',
			array_merge(
				[
					'uuid'                  => 'tpl-list-' . $seq,
					'title'                 => 'Template ' . $seq,
					'status'                => 'draft',
					'author_id'             => 1,
					'page_size'             => 'a4',
					'orientation'           => 'landscape',
					'layout_schema_version' => 1,
					'layout_json'           => '{"layout_schema_version":1,"page":{"size":"a4","orientation":"landscape","width":842,"height":595},"background":{"color":"#ffffff"},"elements":[]}',
					'updated_at'            => '2026-07-0' . min( 9, $seq ) . ' 00:00:00',
					'deleted_at'            => null,
				],
				$overrides
			)
		);
	}

	/**
	 * Filters combine: status + integration trigger types + search.
	 *
	 * @return void
	 */
	public function test_query_filters_and_search() {
		$published_ld = $this->seed_template(
			[
				'title'  => 'Sales Onboarding',
				'status' => 'published',
			]
		);
		$published_ppq = $this->seed_template( [ 'status' => 'published' ] );
		$this->seed_template( [ 'title' => 'Draft LearnDash' ] );

		$this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'template_id'  => $published_ld,
				'trigger_type' => 'learndash_course',
				'source_ref'   => '11',
				'is_active'    => 1,
			]
		);
		$this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'template_id'  => $published_ppq,
				'trigger_type' => 'ppq_quiz',
				'source_ref'   => '12',
				'is_active'    => 1,
			]
		);

		// Unfiltered: all three.
		$result = PressPrimer_Certificate_Template::query();
		$this->assertSame( 3, $result['total'] );

		// Published only.
		$result = PressPrimer_Certificate_Template::query( [ 'status' => 'published' ] );
		$this->assertSame( 2, $result['total'] );

		// Published with a LearnDash trigger - Ryan's example filter.
		$result = PressPrimer_Certificate_Template::query(
			[
				'status'        => 'published',
				'trigger_types' => [ 'learndash_course', 'learndash_lesson' ],
			]
		);
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'Sales Onboarding', $result['items'][0]->title );

		// Title search.
		$result = PressPrimer_Certificate_Template::query( [ 'search' => 'onboard' ] );
		$this->assertSame( 1, $result['total'] );

		// Pagination: page 2 of size 2 carries one row.
		$result = PressPrimer_Certificate_Template::query(
			[
				'per_page' => 2,
				'page'     => 2,
			]
		);
		$this->assertSame( 3, $result['total'] );
		$this->assertCount( 1, $result['items'] );
	}

	/**
	 * Batch trigger fetch maps rows per template.
	 *
	 * @return void
	 */
	public function test_get_for_templates_batches() {
		$a = $this->seed_template();
		$b = $this->seed_template();
		$this->seed_template();

		$this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'template_id'  => $a,
				'trigger_type' => 'ppq_quiz',
				'source_ref'   => '5',
				'is_active'    => 1,
			]
		);

		$map = PressPrimer_Certificate_Trigger::get_for_templates( [ $a, $b ] );

		$this->assertArrayHasKey( $a, $map );
		$this->assertArrayNotHasKey( $b, $map );
		$this->assertSame( 'ppq_quiz', $map[ $a ][0]->trigger_type );

		$this->assertSame( [], PressPrimer_Certificate_Trigger::get_for_templates( [] ) );
	}

	/**
	 * Template settings: field-by-field sanitization (validity_months
	 * bounds, unknown keys stripped) and the update/hydrate round trip.
	 *
	 * @return void
	 */
	public function test_settings_sanitize_and_round_trip() {
		// Period mode: coerced amount, whitelisted unit, unknown keys
		// stripped.
		$this->assertSame(
			[
				'validity_mode'   => 'period',
				'validity_amount' => 12,
				'validity_unit'   => 'months',
			],
			PressPrimer_Certificate_Template::sanitize_settings(
				[
					'validity_mode'   => 'period',
					'validity_amount' => '12',
					'validity_unit'   => 'months',
					'evil'            => 'payload',
				]
			)
		);

		// Per-unit bounds: 3650 days ok, 121 months rejected, 51 years
		// rejected, zero rejected, unknown unit rejected.
		$period = static function ( $amount, $unit ) {
			return PressPrimer_Certificate_Template::sanitize_settings(
				[
					'validity_mode'   => 'period',
					'validity_amount' => $amount,
					'validity_unit'   => $unit,
				]
			);
		};

		$this->assertSame( 3650, $period( 3650, 'days' )['validity_amount'] );
		$this->assertSame( [], $period( 121, 'months' ) );
		$this->assertSame( [], $period( 51, 'years' ) );
		$this->assertSame( [], $period( 0, 'months' ) );
		$this->assertSame( [], $period( 12, 'fortnights' ) );

		// Date mode: Y-m-d only.
		$this->assertSame(
			[
				'validity_mode' => 'date',
				'validity_date' => '2027-12-31',
			],
			PressPrimer_Certificate_Template::sanitize_settings(
				[
					'validity_mode' => 'date',
					'validity_date' => '2027-12-31',
				]
			)
		);
		$this->assertSame(
			[],
			PressPrimer_Certificate_Template::sanitize_settings(
				[
					'validity_mode' => 'date',
					'validity_date' => 'next tuesday',
				]
			)
		);

		// Unknown modes and non-arrays clear to never-expires.
		$this->assertSame( [], PressPrimer_Certificate_Template::sanitize_settings( [ 'validity_mode' => 'sometimes' ] ) );
		$this->assertSame( [], PressPrimer_Certificate_Template::sanitize_settings( 'not-an-array' ) );

		// Round trip through update() and hydration.
		$id = $this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'        => 'tpl-settings-0001',
				'title'       => 'Validity Test',
				'status'      => 'draft',
				'layout_json' => '{"layout_schema_version":1}',
				'deleted_at'  => null,
			]
		);

		PressPrimer_Certificate_Template::update(
			$id,
			[
				'settings' => [
					'validity_mode'   => 'period',
					'validity_amount' => 2,
					'validity_unit'   => 'years',
				],
			]
		);
		$this->assertSame( 'period', PressPrimer_Certificate_Template::get( $id )->settings['validity_mode'] );

		// Clearing writes NULL, hydrating back to an empty array.
		PressPrimer_Certificate_Template::update( $id, [ 'settings' => [] ] );
		$this->assertSame( [], PressPrimer_Certificate_Template::get( $id )->settings );
	}

	/**
	 * Duplication copies the design as a fresh draft and never copies
	 * triggers (the reuse path is attaching a DIFFERENT trigger).
	 *
	 * @return void
	 */
	public function test_duplicate_copies_design_not_triggers() {
		$source = $this->seed_template(
			[
				'title'  => 'Original',
				'status' => 'published',
			]
		);

		$this->wpdb->seed_row(
			'wp_ppcert_triggers',
			[
				'template_id'  => $source,
				'trigger_type' => 'learndash_course',
				'source_ref'   => '11',
				'is_active'    => 1,
			]
		);

		$GLOBALS['ppcert_test_current_user'] = 4;

		$copy_id = PressPrimer_Certificate_Template::duplicate( $source, 4 );

		$this->assertIsInt( $copy_id );
		$this->assertNotSame( $source, $copy_id );

		$copy     = PressPrimer_Certificate_Template::get( $copy_id );
		$original = PressPrimer_Certificate_Template::get( $source );

		$this->assertSame( 'Original (Copy)', $copy->title );
		$this->assertSame( 'draft', $copy->status );
		$this->assertSame( 4, (int) $copy->author_id );
		$this->assertNotSame( $original->uuid, $copy->uuid );
		$this->assertSame( $original->layout, $copy->layout );

		$this->assertSame( [], PressPrimer_Certificate_Trigger::get_for_template( $copy_id ) );
		$this->assertCount( 1, PressPrimer_Certificate_Trigger::get_for_template( $source ) );

		// A missing source errors cleanly.
		$missing = PressPrimer_Certificate_Template::duplicate( 99999, 4 );
		$this->assertInstanceOf( WP_Error::class, $missing );
	}
}
