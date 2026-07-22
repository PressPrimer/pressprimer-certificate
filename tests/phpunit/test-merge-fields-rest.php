<?php
/**
 * Merge fields REST controller tests
 *
 * Registry feed and the meta-key pickers (Feature 002 TR-002): denylist
 * enforcement, LIMIT 50, sample truncation, transient caching, and the
 * source-post-type gate on the post-meta route.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Merge fields REST test case
 *
 * @since 1.0.0
 */
class Test_Merge_Fields_REST extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Controller under test.
	 *
	 * @var PressPrimer_Certificate_REST_Merge_Fields_Controller
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
		ppcert_tests_reset_transients();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_user_caps']    = true;
		$GLOBALS['ppcert_test_current_user'] = 0;
		$GLOBALS['ppcert_test_posts']        = [];

		$this->controller = new PressPrimer_Certificate_REST_Merge_Fields_Controller();
	}

	/**
	 * Seed a usermeta row.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Meta key.
	 * @param string $value   Meta value.
	 * @return void
	 */
	private function seed_user_meta( $user_id, $key, $value ) {
		$this->wpdb->seed_row(
			'wp_usermeta',
			[
				'user_id'    => $user_id,
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test fixture array, not a query.
				'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test fixture array, not a query.
			]
		);
	}

	/**
	 * The registry route exposes labels/samples/groups, never resolvers.
	 *
	 * @return void
	 */
	public function test_registry_route_exposes_fields_without_resolvers() {
		$response = $this->controller->get_registry( new WP_REST_Request( [] ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'recipient', $data['groups'] );
		$this->assertArrayHasKey( 'certificate', $data['groups'] );
		$this->assertArrayHasKey( 'site', $data['groups'] );
		$this->assertNotEmpty( $data['fields'] );

		foreach ( $data['fields'] as $field ) {
			$this->assertSame(
				[ 'key', 'group', 'label', 'sample' ],
				array_keys( $field )
			);
		}

		$keys = array_column( $data['fields'], 'key' );
		$this->assertContains( 'recipient.display_name', $keys );
		$this->assertContains( 'certificate.credential_id', $keys );
	}

	/**
	 * ?trigger_types scopes adapter fields and relabels the source group
	 * with the trigger type's noun (Award tab review pass, 2026-07-22).
	 *
	 * @return void
	 */
	public function test_registry_scoping_by_trigger_types() {
		$adapter = new PPCert_Test_Double_Adapter();
		$adapter->register();

		// Absent parameter: unscoped - the adapter field is offered.
		$data = $this->controller->get_registry( new WP_REST_Request( [] ) )->get_data();
		$this->assertContains( 'source.course_title', array_column( $data['fields'], 'key' ) );

		// Scoped to no triggers (empty string): core fields only, and
		// the source group keeps its generic label.
		$data = $this->controller->get_registry(
			new WP_REST_Request( [ 'trigger_types' => '' ] )
		)->get_data();
		$keys = array_column( $data['fields'], 'key' );
		$this->assertNotContains( 'source.course_title', $keys );
		$this->assertContains( 'recipient.display_name', $keys );
		$this->assertSame( 'Source', $data['groups']['source'] );

		// Scoped to the adapter's type: its fields return and the source
		// group takes the type's noun.
		$data = $this->controller->get_registry(
			new WP_REST_Request( [ 'trigger_types' => 'double_lms' ] )
		)->get_data();
		$this->assertContains( 'source.course_title', array_column( $data['fields'], 'key' ) );
		$this->assertSame( 'Course', $data['groups']['source'] );

		// Scoped to some other type: the adapter's fields stay out.
		$data = $this->controller->get_registry(
			new WP_REST_Request( [ 'trigger_types' => 'unrelated_lms' ] )
		)->get_data();
		$this->assertNotContains( 'source.course_title', array_column( $data['fields'], 'key' ) );
	}

	/**
	 * Every route requires ppcert_manage_templates.
	 *
	 * @return void
	 */
	public function test_permission_callback_requires_capability() {
		$GLOBALS['ppcert_test_user_caps'] = [];
		$this->assertFalse( $this->controller->can_manage() );

		$GLOBALS['ppcert_test_user_caps'] = [ 'ppcert_manage_templates' ];
		$this->assertTrue( $this->controller->can_manage() );
	}

	/**
	 * User meta picker: denylisted and underscore keys never appear.
	 *
	 * @return void
	 */
	public function test_user_meta_keys_excludes_denylist() {
		$this->seed_user_meta( 1, 'license_no', 'LIC-100' );
		$this->seed_user_meta( 1, 'session_tokens', 'secret' );
		$this->seed_user_meta( 1, 'wp_capabilities', 'a:1:{s:13:"administrator";b:1;}' );
		$this->seed_user_meta( 1, 'wp_user_level', '10' );
		$this->seed_user_meta( 1, '_hidden_key', 'internal' );

		$response = $this->controller->get_user_meta_keys( new WP_REST_Request( [] ) );
		$data     = $response->get_data();

		$this->assertSame( [ 'license_no' ], array_column( $data, 'key' ) );
	}

	/**
	 * User meta picker: search narrows, LIMIT 50 caps.
	 *
	 * @return void
	 */
	public function test_user_meta_keys_search_and_limit() {
		for ( $i = 0; $i < 60; $i++ ) {
			$this->seed_user_meta( 1, sprintf( 'key_%02d', $i ), 'v' . $i );
		}

		$all = $this->controller->get_user_meta_keys( new WP_REST_Request( [] ) )->get_data();
		$this->assertCount( 50, $all );

		$narrowed = $this->controller->get_user_meta_keys(
			new WP_REST_Request( [ 'search' => 'key_1' ] )
		)->get_data();

		$this->assertCount( 10, $narrowed );
		$this->assertSame( 'key_10', $narrowed[0]['key'] );
	}

	/**
	 * Samples prefer the current user, falling back to the most recent
	 * user holding the key.
	 *
	 * @return void
	 */
	public function test_user_meta_sample_prefers_current_user() {
		$this->seed_user_meta( 5, 'license_no', 'MINE' );
		$this->seed_user_meta( 9, 'license_no', 'NEWEST' );
		$this->seed_user_meta( 2, 'license_no', 'OLDER' );

		$GLOBALS['ppcert_test_current_user'] = 5;
		$data = $this->controller->get_user_meta_keys( new WP_REST_Request( [] ) )->get_data();
		$this->assertSame( 'MINE', $data[0]['sample'] );

		ppcert_tests_reset_transients();
		$GLOBALS['ppcert_test_current_user'] = 0;
		$data = $this->controller->get_user_meta_keys( new WP_REST_Request( [] ) )->get_data();
		$this->assertSame( 'NEWEST', $data[0]['sample'] );
	}

	/**
	 * Samples truncate to 80 chars; serialized values sample empty.
	 *
	 * @return void
	 */
	public function test_user_meta_sample_truncates_and_skips_serialized() {
		$this->seed_user_meta( 1, 'long_bio', str_repeat( 'x', 200 ) );
		$this->seed_user_meta( 1, 'prefs', 'a:1:{s:3:"foo";s:3:"bar";}' );

		$data = $this->controller->get_user_meta_keys( new WP_REST_Request( [] ) )->get_data();
		$by_key = array_column( $data, 'sample', 'key' );

		$this->assertSame( 80, strlen( $by_key['long_bio'] ) );
		$this->assertSame( '', $by_key['prefs'] );
	}

	/**
	 * The picker caches for 5 minutes: the second call issues no queries.
	 *
	 * @return void
	 */
	public function test_user_meta_keys_cached() {
		$this->seed_user_meta( 1, 'license_no', 'LIC-100' );

		$first = $this->controller->get_user_meta_keys( new WP_REST_Request( [] ) )->get_data();

		$queries_after_first = $this->wpdb->read_queries;

		// New data appears only after the cache expires.
		$this->seed_user_meta( 1, 'brand_new_key', 'val' );

		$second = $this->controller->get_user_meta_keys( new WP_REST_Request( [] ) )->get_data();

		$this->assertSame( $queries_after_first, $this->wpdb->read_queries );
		$this->assertSame( $first, $second );

		// A different search term is its own cache entry.
		$other = $this->controller->get_user_meta_keys(
			new WP_REST_Request( [ 'search' => 'brand' ] )
		)->get_data();
		$this->assertSame( [ 'brand_new_key' ], array_column( $other, 'key' ) );
	}

	/**
	 * Post meta picker: rejected unless the post's type is a registered
	 * trigger source post type.
	 *
	 * @return void
	 */
	public function test_post_meta_keys_requires_registered_source_type() {
		$GLOBALS['ppcert_test_posts'][42] = (object) [
			'ID'        => 42,
			'post_type' => 'sfwd-courses',
		];

		// No trigger types registered: every post rejects.
		$result = $this->controller->get_post_meta_keys(
			new WP_REST_Request( [ 'post_id' => 42 ] )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ppcert_invalid_source_post', $result->get_error_code() );

		// Register a type claiming that post type; the post now passes.
		add_filter(
			'ppcert_register_trigger_types',
			static function ( $types ) {
				$types[] = [
					'id'                => 'learndash_course_completed',
					'label'             => 'Course completed',
					'source_post_types' => [ 'sfwd-courses' ],
				];

				return $types;
			}
		);

		$this->wpdb->seed_row(
			'wp_postmeta',
			[
				'post_id'    => 42,
				'meta_key'   => 'ce_hours', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test fixture array, not a query.
				'meta_value' => '12', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test fixture array, not a query.
			]
		);
		$this->wpdb->seed_row(
			'wp_postmeta',
			[
				'post_id'    => 42,
				'meta_key'   => '_private', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test fixture array, not a query.
				'meta_value' => 'x', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test fixture array, not a query.
			]
		);
		$this->wpdb->seed_row(
			'wp_postmeta',
			[
				'post_id'    => 99,
				'meta_key'   => 'other_post_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Test fixture array, not a query.
				'meta_value' => 'y', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Test fixture array, not a query.
			]
		);

		$response = $this->controller->get_post_meta_keys(
			new WP_REST_Request( [ 'post_id' => 42 ] )
		);
		$data     = $response->get_data();

		$this->assertSame( [ 'ce_hours' ], array_column( $data, 'key' ) );
		$this->assertSame( '12', $data[0]['sample'] );

		// Cached: reads stop after the first hit.
		$queries = $this->wpdb->read_queries;
		$this->controller->get_post_meta_keys( new WP_REST_Request( [ 'post_id' => 42 ] ) );
		$this->assertSame( $queries, $this->wpdb->read_queries );
	}
}
