<?php
/**
 * Issuance service tests
 *
 * Exercises the Feature 003 FR-001 pipeline against the in-memory wpdb
 * fake: happy path, pipeline order, duplicate suppression, abort paths,
 * snapshot immutability, and collision retry.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 1.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Issuance service test case
 *
 * @since 1.0.0
 */
class Test_Issuance_Service extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Seeded template row id.
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Template layout JSON used for seeding (snapshot comparisons).
	 *
	 * @var string
	 */
	private $layout_json;

	/**
	 * Seed a published template and a recipient before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_users'] = [
			7 => (object) [
				'display_name' => 'Dana Whitfield',
				'first_name'   => 'Dana',
				'last_name'    => 'Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];
		$GLOBALS['ppcert_test_user_meta'] = [];
		$GLOBALS['ppcert_test_bloginfo']  = [ 'name' => 'Sunrise Training Academy' ];

		$this->layout_json = wp_json_encode(
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
				'elements'              => [
					[
						'id'    => 'el_nametest',
						'type'  => 'merge_field',
						'x'     => 100,
						'y'     => 200,
						'w'     => 600,
						'h'     => 48,
						'z'     => 1,
						'props' => [
							'token'       => '{{recipient.display_name}}',
							'font_family' => 'playfair-display',
							'font_size'   => 40,
							'color'       => '#1f2a44',
							'align'       => 'center',
							'line_height' => 1.2,
							'bold'        => false,
							'italic'      => false,
						],
					],
					[
						'id'    => 'el_credtest',
						'type'  => 'merge_field',
						'x'     => 100,
						'y'     => 500,
						'w'     => 300,
						'h'     => 20,
						'z'     => 2,
						'props' => [
							'token'       => '{{certificate.credential_id}}',
							'font_family' => 'playfair-display',
							'font_size'   => 10,
							'color'       => '#1f2a44',
							'align'       => 'left',
							'line_height' => 1.2,
							'bold'        => false,
							'italic'      => false,
						],
					],
				],
			]
		);

		$this->template_id = $this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'                  => 'tmpl-0000-0000-0000',
				'title'                 => 'Completion Certificate',
				'status'                => 'published',
				'author_id'             => 1,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => $this->layout_json,
				'deleted_at'            => null,
			]
		);
	}

	/**
	 * Baseline issue args.
	 *
	 * @param array $overrides Overrides.
	 * @return array
	 */
	private function args( array $overrides = [] ) {
		return array_merge(
			[
				'template_id'  => $this->template_id,
				'recipient_id' => 7,
				'source_type'  => 'ppq_quiz',
				'source_ref'   => '89',
				'issued_by'    => 1,
				'context'      => [],
			],
			$overrides
		);
	}

	/**
	 * Happy path: row written with snapshots, credential, UTC datetimes,
	 * and the issued event.
	 *
	 * @return void
	 */
	public function test_issue_happy_path() {
		$id = PressPrimer_Certificate_Issuance_Service::issue( $this->args() );

		$this->assertIsInt( $id );

		$rows = $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() );
		$this->assertCount( 1, $rows );

		$row = $rows[0];
		$this->assertSame( 'issued', $row['status'] );
		$this->assertSame( $this->layout_json, $row['layout_snapshot_json'], 'Layout snapshot is byte-for-byte the template JSON' );
		$this->assertTrue( PressPrimer_Certificate_Credential_ID_Service::is_well_formed( $row['credential_id'] ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $row['issued_at'] );

		$merge_data = json_decode( $row['merge_data_json'], true );
		$this->assertSame( 'Dana Whitfield', $merge_data['recipient.display_name'] );
		$this->assertSame(
			PressPrimer_Certificate_Credential_ID_Service::format_display( $row['credential_id'] ),
			$merge_data['certificate.credential_id'],
			'The snapshotted credential merge value matches the stored credential ID'
		);

		$events = $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() );
		$this->assertCount( 1, $events );
		$this->assertSame( 'issued', $events[0]['event_type'] );
		$this->assertSame( $id, (int) $events[0]['certificate_id'] );
	}

	/**
	 * Pipeline order: validation -> before_issue -> resolution -> email
	 * hooks -> issued action (hook spy).
	 *
	 * @return void
	 */
	public function test_pipeline_order() {
		$order = [];

		add_filter(
			'ppcert_issue_validation',
			static function ( $result ) use ( &$order ) {
				$order[] = 'issue_validation';
				return $result;
			}
		);
		add_action(
			'ppcert_before_issue',
			static function () use ( &$order ) {
				$order[] = 'before_issue';
			}
		);
		add_filter(
			'ppcert_merge_data',
			static function ( $merge_data ) use ( &$order ) {
				$order[] = 'merge_data';
				return $merge_data;
			}
		);
		add_filter(
			'ppcert_email_enabled',
			static function ( $enabled ) use ( &$order ) {
				$order[] = 'email_enabled';
				return $enabled;
			}
		);
		add_filter(
			'ppcert_email_content',
			static function ( $content ) use ( &$order ) {
				$order[] = 'email_content';
				return $content;
			}
		);
		add_action(
			'ppcert_certificate_issued',
			static function () use ( &$order ) {
				$order[] = 'certificate_issued';
			}
		);

		PressPrimer_Certificate_Issuance_Service::issue( $this->args() );

		$this->assertSame(
			[ 'issue_validation', 'before_issue', 'merge_data', 'email_enabled', 'email_content', 'certificate_issued' ],
			$order
		);
	}

	/**
	 * Duplicate suppression: same key returns the existing id with a
	 * duplicate_suppressed event; force bypasses (manual "Issue anyway").
	 *
	 * @return void
	 */
	public function test_duplicate_suppression_and_force() {
		$first  = PressPrimer_Certificate_Issuance_Service::issue( $this->args() );
		$second = PressPrimer_Certificate_Issuance_Service::issue( $this->args() );

		$this->assertSame( $first, $second, 'Suppression returns the existing certificate id' );
		$this->assertCount( 1, $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) );

		$events = $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() );
		$types  = array_column( $events, 'event_type' );
		$this->assertSame( [ 'issued', 'duplicate_suppressed' ], $types );

		// Different source_ref is a different suppression key.
		$other = PressPrimer_Certificate_Issuance_Service::issue( $this->args( [ 'source_ref' => '90' ] ) );
		$this->assertNotSame( $first, $other );

		// Force bypasses suppression entirely.
		$forced = PressPrimer_Certificate_Issuance_Service::issue( $this->args( [ 'force' => true ] ) );
		$this->assertNotSame( $first, $forced );
		$this->assertCount( 3, $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) );
	}

	/**
	 * The trigger's reissue condition opts out of duplicate suppression:
	 * every qualifying completion issues a fresh certificate (compliance
	 * and recertification sites), while triggers without it keep the
	 * suppression default.
	 *
	 * @return void
	 */
	public function test_trigger_reissue_condition_bypasses_suppression() {
		// The firing trigger: reissue on.
		$this->wpdb->seed_row(
			PressPrimer_Certificate_Trigger::table(),
			[
				'uuid'            => 'trg-reissue-0001',
				'template_id'     => $this->template_id,
				'trigger_type'    => 'ppq_quiz',
				'source_ref'      => '89',
				'conditions_json' => '{"reissue":true}',
				'is_active'       => 1,
			]
		);

		$first  = PressPrimer_Certificate_Issuance_Service::issue( $this->args() );
		$second = PressPrimer_Certificate_Issuance_Service::issue( $this->args() );

		$this->assertNotSame( $first, $second, 'Reissue triggers create a fresh certificate per completion' );
		$this->assertCount( 2, $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) );

		$credentials = array_column( $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ), 'credential_id' );
		$this->assertNotSame( $credentials[0], $credentials[1], 'Each reissue carries its own credential ID' );

		// No suppression event was recorded - both completions issued.
		$types = array_column( $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() ), 'event_type' );
		$this->assertSame( [ 'issued', 'issued' ], $types );

		// A different source_ref has no matching trigger: default
		// suppression still applies to it.
		$other      = PressPrimer_Certificate_Issuance_Service::issue( $this->args( [ 'source_ref' => '90' ] ) );
		$suppressed = PressPrimer_Certificate_Issuance_Service::issue( $this->args( [ 'source_ref' => '90' ] ) );
		$this->assertSame( $other, $suppressed );

		// An inactive reissue trigger no longer bypasses.
		$this->wpdb->seed_row(
			PressPrimer_Certificate_Trigger::table(),
			[
				'uuid'            => 'trg-reissue-0002',
				'template_id'     => $this->template_id,
				'trigger_type'    => 'ppq_quiz',
				'source_ref'      => '91',
				'conditions_json' => '{"reissue":true}',
				'is_active'       => 0,
			]
		);

		$inactive_first  = PressPrimer_Certificate_Issuance_Service::issue( $this->args( [ 'source_ref' => '91' ] ) );
		$inactive_second = PressPrimer_Certificate_Issuance_Service::issue( $this->args( [ 'source_ref' => '91' ] ) );
		$this->assertSame( $inactive_first, $inactive_second, 'Inactive triggers fall back to suppression' );
	}

	/**
	 * A WP_Error from ppcert_issue_validation aborts with no rows and no
	 * downstream hooks.
	 *
	 * @return void
	 */
	public function test_validation_abort_leaves_no_rows() {
		$downstream = 0;

		add_filter(
			'ppcert_issue_validation',
			static function () {
				return new WP_Error( 'ppcert_test_hold', 'Held for approval.' );
			}
		);
		add_action(
			'ppcert_before_issue',
			static function () use ( &$downstream ) {
				$downstream++;
			}
		);
		add_action(
			'ppcert_certificate_issued',
			static function () use ( &$downstream ) {
				$downstream++;
			}
		);

		$result = PressPrimer_Certificate_Issuance_Service::issue( $this->args() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ppcert_test_hold', $result->get_error_codes()[0] );
		$this->assertCount( 0, $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) );
		$this->assertCount( 0, $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() ) );
		$this->assertSame( 0, $downstream, 'No downstream hooks after a validation abort' );
	}

	/**
	 * Bad inputs are rejected before any side effect.
	 *
	 * @return void
	 */
	public function test_input_rejections() {
		$missing_template = PressPrimer_Certificate_Issuance_Service::issue( $this->args( [ 'template_id' => 999 ] ) );
		$this->assertInstanceOf( WP_Error::class, $missing_template );

		$this->wpdb->mutate_row( PressPrimer_Certificate_Template::table(), $this->template_id, [ 'status' => 'draft' ] );
		$unpublished = PressPrimer_Certificate_Issuance_Service::issue( $this->args() );
		$this->assertInstanceOf( WP_Error::class, $unpublished );
		$this->assertSame( 'ppcert_template_not_published', $unpublished->get_error_codes()[0] );

		$this->wpdb->mutate_row( PressPrimer_Certificate_Template::table(), $this->template_id, [ 'status' => 'published' ] );
		$missing_recipient = PressPrimer_Certificate_Issuance_Service::issue( $this->args( [ 'recipient_id' => 404 ] ) );
		$this->assertInstanceOf( WP_Error::class, $missing_recipient );

		$this->assertCount( 0, $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) );
		$this->assertCount( 0, $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() ) );
	}

	/**
	 * Snapshot immutability: editing the template after issuance never
	 * changes the issued certificate's snapshot (US-3).
	 *
	 * @return void
	 */
	public function test_snapshot_immutability() {
		$id = PressPrimer_Certificate_Issuance_Service::issue( $this->args() );

		// Simulate editing the template afterward.
		$this->wpdb->mutate_row(
			PressPrimer_Certificate_Template::table(),
			$this->template_id,
			[ 'layout_json' => wp_json_encode( [ 'layout_schema_version' => 1, 'elements' => [] ] ) ]
		);

		$certificate = PressPrimer_Certificate_Certificate::get( $id );

		$this->assertSame( $this->layout_json, $certificate->layout_snapshot_json );
		$this->assertSame( 'Dana Whitfield', $certificate->merge_data['recipient.display_name'] );
	}

	/**
	 * Insert retry: transient insert failures are retried (the collision
	 * path); persistent failure returns WP_Error with no issued event.
	 *
	 * @return void
	 */
	public function test_insert_retry_and_persistent_failure() {
		$this->wpdb->force_insert_failures = 2;

		$id = PressPrimer_Certificate_Issuance_Service::issue( $this->args() );
		$this->assertIsInt( $id, 'Two transient failures are absorbed by the retry loop' );
		$this->assertCount( 1, $this->wpdb->rows( PressPrimer_Certificate_Certificate::table() ) );

		$fresh              = ppcert_tests_reset_wpdb();
		$this->wpdb         = $fresh;
		$this->template_id  = $fresh->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'                  => 'tmpl-0000-0000-0001',
				'title'                 => 'Completion Certificate',
				'status'                => 'published',
				'author_id'             => 1,
				'layout_schema_version' => 1,
				'layout_json'           => $this->layout_json,
				'deleted_at'            => null,
			]
		);
		$fresh->force_insert_failures = 10;

		$result = PressPrimer_Certificate_Issuance_Service::issue( $this->args() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ppcert_insert_failed', $result->get_error_codes()[0] );
		$this->assertCount( 0, $fresh->rows( PressPrimer_Certificate_Certificate::table() ) );
		$this->assertCount( 0, $fresh->rows( PressPrimer_Certificate_Certificate::events_table() ) );
	}

	/**
	 * Resolver failures are noted in the issued event meta (Feature 002
	 * FR-005), never breaking issuance.
	 *
	 * @return void
	 */
	public function test_resolver_failures_noted_in_event_meta() {
		$layout                          = json_decode( $this->layout_json, true );
		$layout['elements'][0]['props']['token'] = '{{ghost.field}}';
		$this->wpdb->mutate_row(
			PressPrimer_Certificate_Template::table(),
			$this->template_id,
			[ 'layout_json' => wp_json_encode( $layout ) ]
		);

		$id = PressPrimer_Certificate_Issuance_Service::issue( $this->args() );
		$this->assertIsInt( $id );

		$events = $this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() );
		$meta   = json_decode( $events[0]['meta_json'], true );

		$this->assertSame( 'ghost.field', $meta['resolver_failed'][0]['token'] );
		$this->assertSame( 'unregistered', $meta['resolver_failed'][0]['reason'] );

		// The certificate itself resolves the token to "" (no syntax leak).
		$certificate = PressPrimer_Certificate_Certificate::get( $id );
		$this->assertSame( '', $certificate->merge_data['ghost.field'] );
	}
}
