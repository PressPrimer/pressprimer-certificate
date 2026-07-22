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
