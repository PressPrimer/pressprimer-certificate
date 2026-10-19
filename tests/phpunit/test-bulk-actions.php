<?php
/**
 * Bulk action tests (2.1)
 *
 * Exercises Feature 2.1-002: Certificate::query_ids()
 * against the list, the job lifecycle (create, run, cancel, resume),
 * per-row skips with reasons, the site lock and the one-run-per-job
 * guard, the explicit actor, the time budget, the stored bulk scope
 * and the per-certificate check (a School-style test consumer), the
 * bulk job actions, retention, the REST routes (selected rows, and
 * filters with the typed DELETE and owner narrowing), and the list
 * table's checkbox column, filters, and criteria.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.1.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Bulk actions test case
 *
 * @since 2.1.0
 */
class Test_Bulk_Actions extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Seeded template id (issuer 4).
	 *
	 * @var int
	 */
	private $template_id;

	/**
	 * Seed a template, two recipients, and an acting administrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		ppcert_tests_reset_transients();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options']      = [ 'ppcert_settings' => [ 'email_issued_enabled' => 1 ] ];
		$GLOBALS['ppcert_test_mail']         = [];
		$GLOBALS['ppcert_test_mail_fail']    = '';
		$GLOBALS['ppcert_test_current_user'] = 1;
		$GLOBALS['ppcert_test_user_caps']    = true;
		$GLOBALS['ppcert_test_bloginfo']     = [
			'name'        => 'Sunrise Training Academy',
			'admin_email' => 'admin@sunrise.example',
		];
		$GLOBALS['ppcert_test_users']        = [
			1 => (object) [
				'ID'           => 1,
				'display_name' => 'Avery Admin',
				'user_email'   => 'avery@sunrise.example',
			],
			2 => (object) [
				'ID'           => 2,
				'display_name' => 'Owen Owner',
				'user_email'   => 'owen@sunrise.example',
			],
			7 => (object) [
				'ID'           => 7,
				'display_name' => 'Dana Whitfield',
				'first_name'   => 'Dana',
				'last_name'    => 'Whitfield',
				'user_email'   => 'dana@example.test',
			],
		];

		$this->template_id = $this->seed_template( 4 );
	}

	/**
	 * Clear the mail failure knob.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		$GLOBALS['ppcert_test_mail_fail'] = '';
		$_GET                             = [];
		parent::tearDown();
	}

	/**
	 * Seed a published template.
	 *
	 * @param int $issuer_id Issuer id.
	 * @return int
	 */
	private function seed_template( $issuer_id ) {
		return $this->wpdb->seed_row(
			PressPrimer_Certificate_Template::table(),
			[
				'uuid'                  => 'tmpl-bulk-' . $issuer_id,
				'title'                 => 'Completion Certificate',
				'status'                => 'published',
				'author_id'             => 1,
				'issuer_id'             => $issuer_id,
				'page_size'             => 'a4',
				'orientation'           => 'landscape',
				'layout_schema_version' => 1,
				'layout_json'           => ppcert_tests_seams_layout(),
				'deleted_at'            => null,
			]
		);
	}

	/**
	 * Issue certificates to user 7.
	 *
	 * @param int $count       How many.
	 * @param int $template_id Template (default: issuer 4's).
	 * @return int[]
	 */
	private function issue( $count, $template_id = 0 ) {
		static $ref = 0;
		$ids        = [];

		for ( $i = 0; $i < $count; $i++ ) {
			++$ref;

			$ids[] = (int) PressPrimer_Certificate_Issuance_Service::issue(
				[
					'template_id'  => $template_id ? $template_id : $this->template_id,
					'recipient_id' => 7,
					'source_type'  => 'manual',
					'source_ref'   => 'bulk-' . $ref,
					'issued_by'    => 1,
				]
			);
		}

		return $ids;
	}

	/**
	 * Create and run a job to the end as user 1.
	 *
	 * @param string $action Bulk action.
	 * @param int[]  $ids    Certificate ids.
	 * @param array  $args   create() args.
	 * @return array Final job.
	 */
	private function run_job( $action, array $ids, array $args = [] ) {
		$job = PressPrimer_Certificate_Bulk_Action_Service::create( 1, $action, $ids, $args );
		$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );

		for ( $guard = 0; $guard < 50 && 'done' !== $job['status']; $guard++ ) {
			$job = PressPrimer_Certificate_Bulk_Action_Service::run( $job['id'], 1 );
			$this->assertIsArray( $job, is_wp_error( $job ) ? $job->get_error_message() : '' );
		}

		return $job;
	}

	/**
	 * Ids of hydrated rows.
	 *
	 * @param array $rows Rows.
	 * @return int[]
	 */
	private function row_ids( array $rows ) {
		return array_map(
			static function ( $row ) {
				return (int) $row->id;
			},
			$rows
		);
	}

	/**
	 * Event rows of one type.
	 *
	 * @param string $type Event type.
	 * @return array[]
	 */
	private function events( $type ) {
		return array_values(
			array_filter(
				$this->wpdb->rows( PressPrimer_Certificate_Certificate::events_table() ),
				static function ( $row ) use ( $type ) {
					return $type === $row['event_type'];
				}
			)
		);
	}

	/**
	 * Grant bulk actions to a scoped "owner" the School way: the list
	 * scope includes issuers 4 and 6 plus their own issues, the bulk
	 * scope only issuer 4.
	 *
	 * @return void
	 */
	private function school_style_owner() {
		add_filter(
			'ppcert_certificate_scope',
			static function () {
				return [
					'issuer_ids' => [ 4, 6 ],
					'issued_by'  => 2,
				];
			}
		);
		add_filter( 'ppcert_user_can_bulk_manage_certificates', static fn() => true );
		add_filter(
			'ppcert_bulk_certificate_scope',
			static function () {
				return [
					'issuer_ids' => [ 4 ],
					'issued_by'  => 0,
				];
			}
		);
	}

	/**
	 * query_ids() returns exactly the list's rows, for every filter and
	 * an explicit scope, in id order.
	 *
	 * @return void
	 */
	public function test_query_ids_equals_the_list() {
		$issuer_six = $this->seed_template( 6 );
		$ids        = array_merge( $this->issue( 5 ), $this->issue( 3, $issuer_six ) );

		PressPrimer_Certificate_Certificate::revoke( $ids[1] );
		$this->wpdb->mutate_row( PressPrimer_Certificate_Certificate::table(), $ids[2], [ 'expires_at' => '2020-01-01 00:00:00' ] );
		$this->wpdb->mutate_row( PressPrimer_Certificate_Certificate::table(), $ids[6], [ 'issued_at' => '2025-03-05 10:00:00' ] );

		$cases = [
			[],
			[ 'status' => 'revoked' ],
			[ 'status' => 'expired' ],
			[ 'status' => 'issued' ],
			[ 'template_id' => $issuer_six ],
			[ 'source_type' => 'manual' ],
			[ 'search' => 'Dana' ],
			[
				'issued_after'  => '2025-03-01',
				'issued_before' => '2025-03-31',
			],
		];
		$scopes = [
			null,
			[
				'issuer_ids' => [ 6 ],
				'issued_by'  => 0,
			],
		];

		foreach ( $scopes as $scope ) {
			foreach ( $cases as $filters ) {
				$listed = [];

				for ( $page = 1; $page <= 5; $page++ ) {
					$result = PressPrimer_Certificate_Certificate::query(
						array_merge(
							$filters,
							[
								'scope'    => $scope,
								'page'     => $page,
								'per_page' => 2,
							]
						)
					);
					$listed = array_merge( $listed, $this->row_ids( $result['items'] ) );
				}

				sort( $listed );

				$this->assertSame( $listed, PressPrimer_Certificate_Certificate::query_ids( $filters, $scope, 100 ), wp_json_encode( [ $filters, $scope ] ) );
			}
		}

		$this->assertCount( 2, PressPrimer_Certificate_Certificate::query_ids( [], null, 2 ), 'The limit holds' );
		$this->assertSame( array_slice( $ids, 0, 2 ), PressPrimer_Certificate_Certificate::query_ids( [], null, 2 ), 'Lowest ids first' );
	}

	/**
	 * The three list queries share one WHERE clause, character for
	 * character (TR-001).
	 *
	 * @return void
	 */
	public function test_list_queries_share_one_where_clause() {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/models/class-ppcert-certificate.php' );

		preg_match_all( '/"SELECT (?:COUNT\(\*\)|\*|id) FROM %i WHERE (\( %d = 0 OR template_id = %d \) AND .+?)(?: ORDER BY [^"]+)?",/', $source, $matches );

		$this->assertCount( 3, $matches[1] );
		$this->assertCount( 1, array_unique( $matches[1] ), 'COUNT, page, and query_ids() WHERE clauses are identical' );
	}

	/**
	 * Revoke on selected rows: one reason for all, an already-revoked
	 * row skipped with its reason, the creator on every event, and the
	 * started/completed actions.
	 *
	 * @return void
	 */
	public function test_revoke_selected_rows() {
		$ids = $this->issue( 4 );
		PressPrimer_Certificate_Certificate::revoke( $ids[3], 'earlier' );

		$seen = [];
		add_action(
			'ppcert_certificates_bulk_started',
			static function ( $job ) use ( &$seen ) {
				$seen[] = [ 'started', $job['total'], isset( $job['ids'] ) ];
			}
		);
		add_action(
			'ppcert_certificates_bulk_completed',
			static function ( $job ) use ( &$seen ) {
				$seen[] = [ 'completed', $job['counts'] ];
			}
		);

		$job = $this->run_job( 'revoke', $ids, [ 'reason' => 'Course retired' ] );

		$this->assertSame( 'done', $job['status'] );
		$this->assertSame(
			[
				'done'    => 3,
				'skipped' => 1,
				'failed'  => 0,
			],
			$job['counts']
		);
		$this->assertSame( 'It is already revoked.', $job['problems'][0]['reason'] );
		$this->assertSame( $ids[3], $job['problems'][0]['id'] );
		$this->assertNotSame( '', $job['problems'][0]['credential_id'] );

		foreach ( array_slice( $ids, 0, 3 ) as $id ) {
			$row = PressPrimer_Certificate_Certificate::get( $id );
			$this->assertSame( 'revoked', $row->status );
			$this->assertSame( 'Course retired', $row->revoke_reason );
		}

		$this->assertSame( 'earlier', PressPrimer_Certificate_Certificate::get( $ids[3] )->revoke_reason, 'Skipped rows are untouched' );
		$this->assertSame(
			[
				[ 'started', 4, false ],
				[
					'completed',
					[
						'done'    => 3,
						'skipped' => 1,
						'failed'  => 0,
					],
				],
			],
			$seen
		);
		$this->assertNull( PressPrimer_Certificate_Bulk_Action_Service::lock_holder(), 'Finished jobs release the lock' );
		$this->assertNull( PressPrimer_Certificate_Bulk_Action_Service::current_job() );
	}

	/**
	 * Every lifecycle event records the job's creator, even when the
	 * request runs as someone else.
	 *
	 * @return void
	 */
	public function test_events_record_the_creator() {
		$ids = $this->issue( 2 );
		$job = PressPrimer_Certificate_Bulk_Action_Service::create( 1, 'revoke', $ids );

		// A different current user (cron-like) cannot run it...
		$this->assertSame( 'ppcert_bulk_not_found', PressPrimer_Certificate_Bulk_Action_Service::run( $job['id'], 9 )->get_error_code() );

		// ...and the creator's run records the creator.
		$GLOBALS['ppcert_test_current_user'] = 0;
		PressPrimer_Certificate_Bulk_Action_Service::run( $job['id'], 1 );

		$actors = array_map( 'intval', array_column( $this->events( 'revoked' ), 'actor_id' ) );
		$this->assertSame( [ 1, 1 ], $actors );
	}

	/**
	 * Reinstate skips rows that are not revoked.
	 *
	 * @return void
	 */
	public function test_reinstate_selected_rows() {
		$ids = $this->issue( 3 );
		PressPrimer_Certificate_Certificate::revoke( $ids[0] );
		PressPrimer_Certificate_Certificate::revoke( $ids[1] );

		$job = $this->run_job( 'reinstate', $ids );

		$this->assertSame( 2, $job['counts']['done'] );
		$this->assertSame( 1, $job['counts']['skipped'] );
		$this->assertSame( 'It is not revoked.', $job['problems'][0]['reason'] );
		$this->assertSame( 'issued', PressPrimer_Certificate_Certificate::get( $ids[0] )->status );
	}

	/**
	 * Delete: the pre-delete action fires with the bulk context while
	 * current_job() names the job; rows and their events are gone; a
	 * row deleted elsewhere is skipped.
	 *
	 * @return void
	 */
	public function test_delete_selected_rows() {
		$ids = $this->issue( 3 );
		PressPrimer_Certificate_Certificate::delete( $ids[2] );

		$seen = [];
		add_action(
			'ppcert_before_certificate_delete',
			static function ( $id, $certificate, $context ) use ( &$seen ) {
				$job    = PressPrimer_Certificate_Bulk_Action_Service::current_job();
				$seen[] = [ $id, $context, $job ? $job['action'] : null, PressPrimer_Certificate_Certificate::current_actor() ];
			},
			10,
			3
		);

		$job = $this->run_job( 'delete', $ids );

		$this->assertSame( 2, $job['counts']['done'] );
		$this->assertSame( 'It no longer exists.', $job['problems'][0]['reason'] );
		$this->assertSame(
			[
				[ $ids[0], 'bulk', 'delete', 1 ],
				[ $ids[1], 'bulk', 'delete', 1 ],
			],
			$seen
		);
		$this->assertNull( PressPrimer_Certificate_Certificate::get( $ids[0] ) );
		$this->assertSame( [], $this->events( 'issued' ), 'Deleted certificates take their events with them' );
	}

	/**
	 * Resend: sent, revoked skipped, vetoed skipped, mailer error failed
	 * with the mailer's message.
	 *
	 * @return void
	 */
	public function test_resend_selected_rows() {
		$ids = $this->issue( 3 );
		PressPrimer_Certificate_Certificate::revoke( $ids[1] );
		$GLOBALS['ppcert_test_mail'] = [];

		$job = $this->run_job( 'resend', $ids );

		$this->assertSame(
			[
				'done'    => 2,
				'skipped' => 1,
				'failed'  => 0,
			],
			$job['counts']
		);
		$this->assertCount( 2, $GLOBALS['ppcert_test_mail'] );
		$this->assertSame( "This certificate is revoked, so it can't be resent.", $job['problems'][0]['reason'] );
		$this->assertCount( 2, $this->events( 'reissued' ) );

		// Mailer failure.
		$GLOBALS['ppcert_test_mail_fail'] = 'SMTP connect() failed.';
		$job                              = $this->run_job( 'resend', [ $ids[0] ] );
		$this->assertSame( 1, $job['counts']['failed'] );
		$this->assertSame( 'SMTP connect() failed.', $job['problems'][0]['reason'] );
		$GLOBALS['ppcert_test_mail_fail'] = '';

		// A veto through ppcert_email_enabled is a skip.
		add_filter( 'ppcert_email_enabled', static fn() => false );
		$job = $this->run_job( 'resend', [ $ids[0] ] );
		$this->assertSame( 1, $job['counts']['skipped'] );
		$this->assertStringStartsWith( 'No email was sent', $job['problems'][0]['reason'] );
	}

	/**
	 * A School-style owner: bulk scope fixed at confirmation (a later
	 * widening does not reach the job), rows outside it skipped, and
	 * the per-certificate check still applies to each row.
	 *
	 * @return void
	 */
	public function test_owner_scope_is_fixed_and_rows_rechecked() {
		$issuer_six = $this->seed_template( 6 );
		$mine       = $this->issue( 2 );
		$other      = $this->issue( 1, $issuer_six );

		$this->school_style_owner();
		$GLOBALS['ppcert_test_current_user'] = 2;

		$job = PressPrimer_Certificate_Bulk_Action_Service::create( 2, 'delete', array_merge( $mine, $other ) );
		$this->assertSame(
			[
				'issuer_ids' => [ 4 ],
				'issued_by'  => 0,
			],
			$job['scope']
		);

		// Widening the bulk scope after confirmation changes nothing.
		add_filter(
			'ppcert_bulk_certificate_scope',
			static function () {
				return null;
			},
			20
		);

		// The per-certificate check narrows one row (School: delete is
		// owner-only).
		add_filter(
			'ppcert_user_can_manage_certificate',
			static function ( $can, $user_id, $action, $certificate ) use ( $mine ) {
				return (int) $certificate->id === $mine[1] ? false : $can;
			},
			10,
			4
		);

		$job = PressPrimer_Certificate_Bulk_Action_Service::run( $job['id'], 2 );

		$this->assertSame( 1, $job['counts']['done'] );
		$this->assertSame( 2, $job['counts']['skipped'] );
		$this->assertNull( PressPrimer_Certificate_Certificate::get( $mine[0] ) );
		$this->assertNotNull( PressPrimer_Certificate_Certificate::get( $mine[1] ) );
		$this->assertNotNull( PressPrimer_Certificate_Certificate::get( $other[0] ), 'Outside the stored bulk scope' );
		$this->assertSame( 'You are not allowed to change this certificate.', $job['problems'][1]['reason'] );
	}

	/**
	 * Losing the bulk permission stops the job at the next run; the
	 * job stays listed for its creator (Resume / Discard).
	 *
	 * @return void
	 */
	public function test_permission_loss_stops_the_job() {
		$ids = $this->issue( 3 );
		add_filter( 'ppcert_bulk_chunk_size', static fn() => 1 );
		add_filter( 'ppcert_bulk_time_budget', static fn() => 1 );

		$job = PressPrimer_Certificate_Bulk_Action_Service::create( 1, 'revoke', $ids );

		add_filter( 'ppcert_user_can_bulk_manage_certificates', static fn() => false );

		$error = PressPrimer_Certificate_Bulk_Action_Service::run( $job['id'], 1 );
		$this->assertSame( 403, $error->get_error_data()['status'] );
		$this->assertNull( PressPrimer_Certificate_Bulk_Action_Service::lock_holder() );

		$listed = PressPrimer_Certificate_Bulk_Action_Service::unfinished_for( 1 );
		$this->assertCount( 1, $listed );
		$this->assertSame( 'stopped', $listed[0]['status'] );
		$this->assertStringContainsString( 'no longer allowed', $listed[0]['message'] );
		$this->assertSame( [], PressPrimer_Certificate_Bulk_Action_Service::unfinished_for( 2 ), 'Only the creator sees it' );
	}

	/**
	 * A run stops when the time budget is spent and the next run picks
	 * up at the cursor (resume).
	 *
	 * @return void
	 */
	public function test_time_budget_and_resume() {
		$ids = $this->issue( 4 );
		add_filter( 'ppcert_bulk_chunk_size', static fn() => 1 );
		add_filter( 'ppcert_bulk_time_budget', static fn() => 1 );
		add_action(
			'ppcert_certificate_revoked',
			static function () {
				usleep( 550000 );
			}
		);

		$job   = PressPrimer_Certificate_Bulk_Action_Service::create( 1, 'revoke', $ids );
		$first = PressPrimer_Certificate_Bulk_Action_Service::run( $job['id'], 1 );

		$this->assertSame( 'running', $first['status'] );
		$this->assertSame( 2, $first['processed'], 'Stopped after the chunk that spent the budget' );
		$this->assertSame( 'running', PressPrimer_Certificate_Bulk_Action_Service::unfinished_for( 1 )[0]['status'] );

		// The page closed; the creator resumes later.
		$second = PressPrimer_Certificate_Bulk_Action_Service::run( $job['id'], 1 );
		$this->assertSame( 'done', $second['status'] );
		$this->assertSame( 4, $second['counts']['done'] );
	}

	/**
	 * One job per site; one run per job; cancel releases the lock.
	 *
	 * @return void
	 */
	public function test_lock_run_guard_and_cancel() {
		$ids = $this->issue( 2 );
		$job = PressPrimer_Certificate_Bulk_Action_Service::create( 1, 'revoke', $ids );

		$second = PressPrimer_Certificate_Bulk_Action_Service::create( 1, 'delete', $ids );
		$this->assertSame( 409, $second->get_error_data()['status'] );
		$this->assertStringContainsString( 'started by Avery Admin', $second->get_error_message(), 'Administrators see who started it' );

		set_transient( PressPrimer_Certificate_Bulk_Action_Service::RUN_PREFIX . $job['id'], 1, 60 );
		$this->assertSame( 'ppcert_bulk_busy', PressPrimer_Certificate_Bulk_Action_Service::run( $job['id'], 1 )->get_error_code() );
		delete_transient( PressPrimer_Certificate_Bulk_Action_Service::RUN_PREFIX . $job['id'] );

		$cancelled = [];
		add_action(
			'ppcert_certificates_bulk_cancelled',
			static function ( $ended ) use ( &$cancelled ) {
				$cancelled[] = $ended['id'];
			}
		);

		$this->assertSame( 'ppcert_bulk_not_found', PressPrimer_Certificate_Bulk_Action_Service::cancel( $job['id'], 2 )->get_error_code(), "Another user's job is not found" );

		$ended = PressPrimer_Certificate_Bulk_Action_Service::cancel( $job['id'], 1 );
		$this->assertSame( 'discarded', $ended['status'] );
		$this->assertSame( [ $job['id'] ], $cancelled );
		$this->assertNull( PressPrimer_Certificate_Bulk_Action_Service::lock_holder() );
		$this->assertSame( 'issued', PressPrimer_Certificate_Certificate::get( $ids[0] )->status, 'Nothing processed after cancel' );
		$this->assertSame( 'discarded', PressPrimer_Certificate_Bulk_Action_Service::run( $job['id'], 1 )['status'], 'A discarded job does not run' );

		// The lock is free for the next job.
		$this->assertIsArray( PressPrimer_Certificate_Bulk_Action_Service::create( 1, 'delete', $ids ) );
	}

	/**
	 * Creation refusals: unknown action, no rows, over the cap, and a
	 * user without bulk actions.
	 *
	 * @return void
	 */
	public function test_create_refusals() {
		$ids = $this->issue( 3 );

		$this->assertSame( 'ppcert_bulk_invalid_action', PressPrimer_Certificate_Bulk_Action_Service::create( 1, 'archive', $ids )->get_error_code() );
		$this->assertSame( 'ppcert_bulk_empty', PressPrimer_Certificate_Bulk_Action_Service::create( 1, 'revoke', [ 0, -4 ] )->get_error_code() );

		add_filter( 'ppcert_bulk_max_items', static fn() => 2 );
		$too_many = PressPrimer_Certificate_Bulk_Action_Service::create( 1, 'revoke', $ids );
		$this->assertSame( 'Narrow your filters to 2 certificates or fewer.', $too_many->get_error_message() );

		// A scoped user without the School grant has no bulk actions.
		add_filter(
			'ppcert_certificate_scope',
			static function () {
				return [
					'issuer_ids' => [ 4 ],
					'issued_by'  => 2,
				];
			}
		);
		$this->assertSame( 'ppcert_bulk_forbidden', PressPrimer_Certificate_Bulk_Action_Service::create( 2, 'revoke', [ $ids[0] ] )->get_error_code() );
		$this->assertNull( PressPrimer_Certificate_Bulk_Action_Service::lock_holder(), 'Refusals take no lock' );
	}

	/**
	 * Retention: finished jobs after 7 days, untouched unfinished ones
	 * after 30 (announced as cancelled); recent jobs stay.
	 *
	 * @return void
	 */
	public function test_prune_old_jobs() {
		$ids  = $this->issue( 1 );
		$done = $this->run_job( 'revoke', $ids );
		$open = PressPrimer_Certificate_Bulk_Action_Service::create( 1, 'reinstate', $ids );

		$this->assertSame( 0, PressPrimer_Certificate_Bulk_Action_Service::prune() );

		$age = static function ( $job_id, $days ) {
			$job                              = get_option( PressPrimer_Certificate_Bulk_Action_Service::JOB_PREFIX . $job_id );
			$index                            = get_option( PressPrimer_Certificate_Bulk_Action_Service::INDEX_OPTION );
			$stamp                            = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
			$job['updated_at']                = $stamp;
			$index[ $job_id ]['updated_at']   = $stamp;
			$GLOBALS['ppcert_test_options'][ PressPrimer_Certificate_Bulk_Action_Service::JOB_PREFIX . $job_id ] = $job;
			$GLOBALS['ppcert_test_options'][ PressPrimer_Certificate_Bulk_Action_Service::INDEX_OPTION ]       = $index;
		};

		$age( $done['id'], 8 );
		$age( $open['id'], 8 );

		$this->assertSame( 1, PressPrimer_Certificate_Bulk_Action_Service::prune(), 'The finished job only' );
		$this->assertNull( PressPrimer_Certificate_Bulk_Action_Service::get( $done['id'] ) );

		$cancelled = [];
		add_action(
			'ppcert_certificates_bulk_cancelled',
			static function ( $job ) use ( &$cancelled ) {
				$cancelled[] = $job['id'];
			}
		);

		$age( $open['id'], 31 );
		$this->assertSame( 1, PressPrimer_Certificate_Bulk_Action_Service::prune() );
		$this->assertSame( [ $open['id'] ], $cancelled );
		$this->assertSame( [], get_option( PressPrimer_Certificate_Bulk_Action_Service::INDEX_OPTION ) );
	}

	/**
	 * The REST routes: registration, the create permission, validation,
	 * and a full run through the controller.
	 *
	 * @return void
	 */
	public function test_rest_routes() {
		$GLOBALS['ppcert_test_rest_routes'] = [];

		$controller = new PressPrimer_Certificate_REST_Bulk_Controller();
		$controller->register_routes();

		$routes = array_map(
			static function ( $entry ) {
				return $entry['namespace'] . $entry['route'];
			},
			$GLOBALS['ppcert_test_rest_routes']
		);
		$this->assertContains( 'ppcert/v1/certificates/bulk', $routes );
		$this->assertContains( 'ppcert/v1/certificates/bulk/' . PressPrimer_Certificate_REST_Bulk_Controller::JOB_PATTERN . '/run', $routes );
		$this->assertContains( 'ppcert/v1/certificates/bulk/' . PressPrimer_Certificate_REST_Bulk_Controller::JOB_PATTERN, $routes );

		$this->assertTrue( $controller->validate_ids( [ 3, '4' ] ) );
		$this->assertFalse( $controller->validate_ids( [] ) );
		$this->assertFalse( $controller->validate_ids( [ 3, 0 ] ) );
		$this->assertFalse( $controller->validate_ids( [ 3, -4 ] ), 'Negative ids are not made positive' );
		$this->assertFalse( $controller->validate_ids( '3' ) );
		$this->assertFalse( $controller->validate_action( 'archive' ) );

		$ids     = $this->issue( 2 );
		$request = new WP_REST_Request(
			[
				'action' => 'revoke',
				'ids'    => array_map( 'strval', $ids ),
				'reason' => 'Duplicate award',
			]
		);

		$this->assertTrue( $controller->can_create( $request ) );

		$created = $controller->create_job( $request );
		$this->assertSame( 201, $created->get_status() );
		$this->assertArrayNotHasKey( 'ids', $created->get_data(), 'Responses never carry the id list' );

		$job_id = $created->get_data()['id'];
		$ran    = $controller->run_job( new WP_REST_Request( [ 'job' => $job_id ] ) );
		$this->assertSame( 'done', $ran->get_data()['status'] );
		$this->assertSame( 'done', $controller->get_job( new WP_REST_Request( [ 'job' => $job_id ] ) )->get_data()['status'] );
		$this->assertSame( 'Duplicate award', PressPrimer_Certificate_Certificate::get( $ids[0] )->revoke_reason );

		// No bulk permission: the create route refuses.
		add_filter( 'ppcert_user_can_bulk_manage_certificates', static fn() => false );
		$this->assertFalse( $controller->can_create( $request ) );
	}

	/**
	 * POST with filters: the set is the list's matching ids in the
	 * user's bulk scope, fixed at confirmation, with the filters as the
	 * criteria.
	 *
	 * @return void
	 */
	public function test_filter_jobs() {
		$issuer_six = $this->seed_template( 6 );
		$four       = $this->issue( 3 );
		$six        = $this->issue( 2, $issuer_six );
		PressPrimer_Certificate_Certificate::revoke( $four[0] );
		PressPrimer_Certificate_Certificate::revoke( $six[0] );

		$controller = new PressPrimer_Certificate_REST_Bulk_Controller();
		$create     = static function ( array $params ) use ( $controller ) {
			return $controller->create_job( new WP_REST_Request( $params ) );
		};

		$response = $create(
			[
				'action'  => 'reinstate',
				'filters' => [ 'status' => 'revoked' ],
			]
		);
		$this->assertSame( 201, $response->get_status() );
		$job = $response->get_data();
		$this->assertSame( 2, $job['total'] );
		$this->assertSame(
			[
				'type'    => 'filters',
				'filters' => [
					'template_id'   => 0,
					'status'        => 'revoked',
					'source_type'   => '',
					'search'        => '',
					'issued_after'  => '',
					'issued_before' => '',
				],
			],
			$job['criteria']
		);
		PressPrimer_Certificate_Bulk_Action_Service::cancel( $job['id'], 1 );

		// GET /certificates' validators and the reversed range.
		$this->assertFalse( $controller->validate_filters( [ 'status' => 'pending' ] ) );
		$this->assertFalse( $controller->validate_filters( [ 'issued_after' => '10/01/2026' ] ) );
		$this->assertFalse( $controller->validate_filters( [ 'author' => 3 ] ), 'Unknown filters are refused' );
		$this->assertFalse( $controller->validate_filters( 'status=revoked' ) );
		$this->assertTrue( $controller->validate_filters( [ 'template_id' => '7', 'search' => 'Dana', 'issued_before' => '2026-10-01' ] ) );

		$reversed = $create(
			[
				'action'  => 'revoke',
				'filters' => [
					'issued_after'  => '2026-10-02',
					'issued_before' => '2026-10-01',
				],
			]
		);
		$this->assertSame( 'ppcert_invalid_date_range', $reversed->get_error_code() );

		// Typed confirmation for delete-all-matching.
		foreach ( [ null, '', 'delete', 'DELETE ', 'DELET' ] as $typed ) {
			$params = [
				'action'  => 'delete',
				'filters' => [ 'template_id' => $issuer_six ],
			];

			if ( null !== $typed ) {
				$params['confirm'] = $typed;
			}

			$this->assertSame( 'ppcert_bulk_confirm_required', $create( $params )->get_error_code(), wp_json_encode( $typed ) );
		}

		$this->assertNull( PressPrimer_Certificate_Bulk_Action_Service::lock_holder(), 'Refused deletes take no lock' );

		$deleted = $create(
			[
				'action'  => 'delete',
				'filters' => [ 'template_id' => $issuer_six ],
				'confirm' => 'DELETE',
			]
		);
		$this->assertSame( 2, $deleted->get_data()['total'] );
		PressPrimer_Certificate_Bulk_Action_Service::run( $deleted->get_data()['id'], 1 );
		$this->assertNull( PressPrimer_Certificate_Certificate::get( $six[1] ) );
		$this->assertNotNull( PressPrimer_Certificate_Certificate::get( $four[1] ) );

		// Nothing matching; ids and filters together; the cap.
		$this->assertSame( 'ppcert_bulk_empty', $create( [ 'action' => 'revoke', 'filters' => [ 'search' => 'nobody-matches-this' ] ] )->get_error_code() );
		$this->assertSame( 'ppcert_bulk_ambiguous', $create( [ 'action' => 'revoke', 'ids' => [ $four[1] ], 'filters' => [] ] )->get_error_code() );

		add_filter( 'ppcert_bulk_max_items', static fn() => 2 );
		$this->assertSame( 'Narrow your filters to 2 certificates or fewer.', $create( [ 'action' => 'revoke', 'filters' => [] ] )->get_error_message() );
	}

	/**
	 * A School-style owner's filter job holds only their own issuer's
	 * certificates, though the filters match more on the site and their
	 * list also shows their own awards elsewhere.
	 *
	 * @return void
	 */
	public function test_filter_job_owner_narrowing() {
		$issuer_six = $this->seed_template( 6 );
		$four       = $this->issue( 2 );
		$six        = $this->issue( 2, $issuer_six );

		// Issued by the owner (user 2) from issuer 6: in their list scope
		// (issued_by), not in their bulk scope.
		$this->wpdb->mutate_row( PressPrimer_Certificate_Certificate::table(), $six[0], [ 'issued_by' => 2 ] );

		$this->school_style_owner();
		$GLOBALS['ppcert_test_current_user'] = 2;

		$response = ( new PressPrimer_Certificate_REST_Bulk_Controller() )->create_job(
			new WP_REST_Request(
				[
					'action'  => 'delete',
					'filters' => [],
					'confirm' => 'DELETE',
				]
			)
		);

		$job = $response->get_data();
		$this->assertSame( 2, $job['total'] );
		$this->assertSame( $four, PressPrimer_Certificate_Bulk_Action_Service::get( $job['id'] )['ids'] );
		$this->assertSame(
			[
				'issuer_ids' => [ 4 ],
				'issued_by'  => 0,
			],
			$job['scope']
		);
	}

	/**
	 * The list table's filters, bulk context, and criteria in words.
	 *
	 * @return void
	 */
	public function test_list_table_bulk_context() {
		$ids = $this->issue( 3 );
		PressPrimer_Certificate_Certificate::revoke( $ids[0] );

		$_GET = [
			'template_id'   => (string) $this->template_id,
			'status'        => 'revoked',
			'source_type'   => 'manual',
			's'             => 'Dana',
			'issued_after'  => '2026-10-01',
			'issued_before' => 'not-a-date',
		];

		$table = new PressPrimer_Certificate_Certificates_List_Table();
		$table->prepare_items();
		$context = $table->bulk_context();

		$this->assertSame(
			[
				'template_id'   => $this->template_id,
				'status'        => 'revoked',
				'source_type'   => 'manual',
				'search'        => 'Dana',
				'issued_after'  => '2026-10-01',
				'issued_before' => '',
			],
			$context['filters']
		);
		$this->assertSame(
			[
				'Template: Completion Certificate',
				'Status: Revoked',
				'Source: Manual',
				'Search: "Dana"',
				'Issued on or after October 1, 2026',
			],
			$context['criteria']
		);
		$this->assertStringContainsString( 'first 100 people', $context['note'] );
		$this->assertSame( $context['total'], $context['bulkTotal'], 'Administrators: the bulk scope is the list scope' );

		$_GET = [];
		$this->assertSame( [ 'All certificates' ], ( new PressPrimer_Certificate_Certificates_List_Table() )->criteria_summary( ( new PressPrimer_Certificate_Certificates_List_Table() )->current_filters() ) );

		// A School-style owner: their list also shows an award they made
		// for issuer 6; the bulk total does not count it.
		$issuer_six = $this->seed_template( 6 );
		$elsewhere  = $this->issue( 1, $issuer_six );
		$this->wpdb->mutate_row( PressPrimer_Certificate_Certificate::table(), $elsewhere[0], [ 'issued_by' => 2 ] );
		add_filter(
			'ppcert_certificate_scope',
			static function () {
				return [
					'issuer_ids' => [ 4 ],
					'issued_by'  => 2,
				];
			}
		);
		add_filter( 'ppcert_user_can_bulk_manage_certificates', static fn() => true );
		add_filter(
			'ppcert_bulk_certificate_scope',
			static function () {
				return [
					'issuer_ids' => [ 4 ],
					'issued_by'  => 0,
				];
			}
		);

		$owner_table = new PressPrimer_Certificate_Certificates_List_Table();
		$owner_table->prepare_items();
		$owner = $owner_table->bulk_context();

		$this->assertSame( 4, $owner['total'] );
		$this->assertSame( 3, $owner['bulkTotal'] );
	}

	/**
	 * The list table shows the checkbox column and bulk actions only to
	 * users with bulk actions.
	 *
	 * @return void
	 */
	public function test_list_table_bulk_controls() {
		$table   = new PressPrimer_Certificate_Certificates_List_Table();
		$columns = $table->get_columns();

		$this->assertSame( 'cb', array_key_first( $columns ) );

		$item = (object) [
			'id'            => 41,
			'credential_id' => '7Q4MK9P2XT3A',
		];
		$this->assertStringContainsString( 'name="certificate[]" value="41"', $table->column_cb( $item ) );

		add_filter(
			'ppcert_certificate_scope',
			static function () {
				return [
					'issuer_ids' => [ 4 ],
					'issued_by'  => 1,
				];
			}
		);

		$this->assertArrayNotHasKey( 'cb', ( new PressPrimer_Certificate_Certificates_List_Table() )->get_columns(), 'Scoped users without the grant get no checkboxes' );
	}
}
