<?php
/**
 * Existing defects, part 2 (2.1)
 *
 * Feature 2.1-007, Prompt 2.8: the verification page picker's search
 * (FR-005), placeholder-post dates and the migrator's updated_at rule
 * (FR-008), the deactivation flush and plain-permalink certificate
 * links (FR-009), and duplicates keeping their settings (FR-010). The
 * per-user meta-key cache (FR-006) is in test-merge-fields-rest.php
 * and the deleted-page notice (FR-009) in test-verification-page.php.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Existing defects (screens, dates, duplicates) test case
 *
 * @since 2.1.0
 */
class Test_Existing_Defects_Screens extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Fresh state; an administrator acts.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_transients();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options']      = [];
		$GLOBALS['ppcert_test_current_user'] = 1;
		$GLOBALS['ppcert_test_user_caps']    = true;
		$GLOBALS['ppcert_test_posts']        = [];
		$GLOBALS['ppcert_test_gmt_offset']   = 0;
	}

	/**
	 * Restore the timezone simulation.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['ppcert_test_gmt_offset'] = 0;
		parent::tearDown();
	}

	/**
	 * Seed a page.
	 *
	 * @param int    $id      Post id.
	 * @param string $title   Title.
	 * @param string $status  Status.
	 * @param string $content Content.
	 * @return void
	 */
	private function page( $id, $title, $status = 'publish', $content = '' ) {
		$GLOBALS['ppcert_test_posts'][ $id ] = (object) [
			'ID'           => $id,
			'post_type'    => 'page',
			'post_status'  => $status,
			'post_title'   => $title,
			'post_content' => $content,
		];
	}

	/**
	 * FR-005: the picker searches every published page by title; the
	 * configured page's existence comes from the page, not from a
	 * capped list.
	 *
	 * @return void
	 */
	public function test_verification_page_search() {
		for ( $i = 1; $i <= 60; $i++ ) {
			$this->page( 1000 + $i, sprintf( 'Page %02d', $i ) );
		}

		$this->page( 2001, 'Zebra Verification' );
		$this->page( 2002, 'Draft Verification', 'draft' );
		$this->page( 2003, '' );
		$this->page( 2004, 'About Us', 'publish', 'Our verification process.' );

		$controller = new PressPrimer_Certificate_REST_Settings_Controller();
		$results    = $controller->search_pages( new WP_REST_Request( [ 'search' => 'verification' ] ) )->get_data();

		$this->assertSame(
			[
				[
					'id'    => 2001,
					'title' => 'Zebra Verification',
				],
			],
			$results,
			'Published pages, by title only, found past any first-page cap'
		);

		$this->assertCount( 20, $controller->search_pages( new WP_REST_Request( [ 'search' => 'page' ] ) )->get_data(), 'At most 20 per search' );
		$this->assertContains(
			[
				'id'    => 2003,
				'title' => '(no title) #2003',
			],
			PressPrimer_Certificate_REST_Settings_Controller::page_matches( '', 100 )
		);

		// The configured page: exists whatever its place in the list.
		$this->assertSame(
			[
				'id'     => 2001,
				'title'  => 'Zebra Verification',
				'exists' => true,
			],
			PressPrimer_Certificate_REST_Settings_Controller::verification_page( 2001 )
		);
		$this->assertFalse( PressPrimer_Certificate_REST_Settings_Controller::verification_page( 2002 )['exists'], 'A draft is not a working page' );
		$this->assertFalse( PressPrimer_Certificate_REST_Settings_Controller::verification_page( 9999 )['exists'] );
		$this->assertTrue( PressPrimer_Certificate_REST_Settings_Controller::verification_page( 0 )['exists'], 'None configured is not a warning' );

		$GLOBALS['ppcert_test_rest_routes'] = [];
		$controller->register_routes();
		$this->assertContains( '/settings/pages', array_column( $GLOBALS['ppcert_test_rest_routes'], 'route' ) );
	}

	/**
	 * FR-008: the placeholder post's local dates are site time, its
	 * _gmt dates UTC.
	 *
	 * @return void
	 */
	public function test_placeholder_post_dates() {
		$GLOBALS['ppcert_test_gmt_offset'] = -5 * HOUR_IN_SECONDS;

		$method = new ReflectionMethod( PressPrimer_Certificate_View_Page::class, 'build_stub_post' );
		$method->setAccessible( true );
		$post = $method->invoke( null, 'Certificate', 'certificate', '2026-10-19 15:00:00' );

		$this->assertSame( '2026-10-19 10:00:00', $post->post_date );
		$this->assertSame( '2026-10-19 15:00:00', $post->post_date_gmt );
		$this->assertSame( '2026-10-19 10:00:00', $post->post_modified );
		$this->assertSame( '2026-10-19 15:00:00', $post->post_modified_gmt );
	}

	/**
	 * FR-008: the title backfill writes updated_at explicitly (the row's
	 * own UTC value), so MySQL's ON UPDATE never rewrites it.
	 *
	 * @return void
	 */
	public function test_backfill_sets_updated_at() {
		$spy = new class() extends PPCert_Fake_WPDB {
			/**
			 * Recorded update() calls.
			 *
			 * @var array[]
			 */
			public $updates = [];

			/**
			 * Record, then update.
			 *
			 * @param string $table        Table.
			 * @param array  $data         Data.
			 * @param array  $where        Where.
			 * @param array  $format       Formats.
			 * @param array  $where_format Where formats.
			 * @return int
			 */
			public function update( $table, $data, $where, $format = [], $where_format = [] ) {
				$this->updates[] = $data;
				return parent::update( $table, $data, $where, $format, $where_format );
			}
		};

		$GLOBALS['wpdb'] = $spy;
		$table           = PressPrimer_Certificate_Certificate::table();
		$id              = $spy->seed_row(
			$table,
			[
				'title'           => null,
				'merge_data_json' => wp_json_encode( [ 'certificate.title' => 'Forklift Safety' ] ),
				'updated_at'      => '2026-03-02 08:15:00',
			]
		);

		PressPrimer_Certificate_Migrator::backfill_certificate_titles();

		$this->assertCount( 1, $spy->updates );
		$this->assertSame( '2026-03-02 08:15:00', $spy->updates[0]['updated_at'] ?? null, 'updated_at is written, as the row had it' );
		$this->assertSame( 'Forklift Safety', PressPrimer_Certificate_Certificate::get( $id )->title );
	}

	/**
	 * FR-009: deactivation's flush leaves out the plugin's rules.
	 *
	 * @return void
	 */
	public function test_deactivation_drops_rewrite_rules() {
		$GLOBALS['ppcert_test_registered_rewrite_rules'] = [
			'^certificate/([A-Za-z0-9\-]+)/?$' => 'index.php?ppcert_credential=$matches[1]',
			'(.?.+?)(?:/([0-9]+))?/?$'          => 'index.php?pagename=$matches[1]&page=$matches[2]',
		];

		PressPrimer_Certificate_Deactivator::deactivate();

		$this->assertSame(
			[ '(.?.+?)(?:/([0-9]+))?/?$' => 'index.php?pagename=$matches[1]&page=$matches[2]' ],
			$GLOBALS['ppcert_test_rewrite_rules']
		);
		// The filter is removed after the flush: a later flush (the
		// plugin active again) keeps the rule.
		flush_rewrite_rules();
		$this->assertArrayHasKey( '^certificate/([A-Za-z0-9\-]+)/?$', $GLOBALS['ppcert_test_rewrite_rules'] );
	}

	/**
	 * FR-009: with plain permalinks, certificate links use the query form
	 * the rewrite maps to.
	 *
	 * @return void
	 */
	public function test_plain_permalink_certificate_links() {
		$pretty = PressPrimer_Certificate_View_Page::view_url( '7Q4MK9P2XT3A' );
		$this->assertStringContainsString( '/certificate/7Q4M-K9P2-XT3A/', $pretty );

		$GLOBALS['ppcert_test_options']['permalink_structure'] = '';
		$plain = PressPrimer_Certificate_View_Page::view_url( '7Q4MK9P2XT3A' );

		$this->assertStringNotContainsString( '/certificate/', $plain );
		$this->assertStringContainsString( '?ppcert_credential=7Q4M-K9P2-XT3A', $plain );
	}

	/**
	 * FR-010: a duplicate keeps the settings (an inactive addon's keys
	 * included) except School's program publish switch and slug, and
	 * never the trigger.
	 *
	 * @return void
	 */
	public function test_duplicate_keeps_settings() {
		$source = $this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'                  => 'tmpl-dup-1',
				'title'                 => 'Forklift Safety',
				'status'                => 'published',
				'author_id'             => 1,
				'issuer_id'             => 0,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => ppcert_tests_seams_layout(),
				'settings_json'         => wp_json_encode(
					[
						'certificate_name'      => 'Forklift Safety: {{recipient.full_name}}',
						'validity_mode'         => 'period',
						'validity_amount'       => 2,
						'validity_unit'         => 'years',
						'reminders_enabled'     => true,
						'reminder_offsets'      => [ 30, 7 ],
						'school_program_public' => true,
						'school_program_slug'   => 'forklift-safety',
						'school_program_criteria' => 'Pass the practical test.',
					]
				),
				'deleted_at'            => null,
			]
		);
		$this->wpdb->seed_row(
			PressPrimer_Certificate_Trigger::table(),
			[
				'template_id'  => $source,
				'trigger_type' => 'manual_zz',
				'source_ref'   => '1',
				'is_active'    => 1,
			]
		);

		$copy = PressPrimer_Certificate_Template::duplicate( $source, 1 );
		$row  = PressPrimer_Certificate_Template::get( $copy );

		$this->assertSame( 'Forklift Safety (Copy)', $row->title );
		$this->assertSame( 'draft', $row->status );
		$this->assertSame( 'Forklift Safety: {{recipient.full_name}}', $row->settings['certificate_name'] );
		$this->assertSame( 2, $row->settings['validity_amount'] );
		$this->assertSame( [ 30, 7 ], $row->settings['reminder_offsets'] );
		$this->assertSame( 'Pass the practical test.', $row->settings['school_program_criteria'], "An inactive addon's key carries over" );
		$this->assertArrayNotHasKey( 'school_program_public', $row->settings );
		$this->assertArrayNotHasKey( 'school_program_slug', $row->settings );
		$this->assertSame( [], PressPrimer_Certificate_Trigger::get_for_template( $copy ), 'Triggers never copy' );
	}
}
