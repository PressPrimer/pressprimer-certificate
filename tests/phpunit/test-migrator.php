<?php
/**
 * Migrator tests
 *
 * Exercises the version-chain migrator against the fake wpdb's schema
 * registry and the bootstrap's dbDelta shim: fresh install, idempotency,
 * the 1.1.0-to-2.0.0 upgrade on a populated fixture (title backfill),
 * the 2.0.0-to-2.1.0 upgrade (page_size widened, new columns and
 * indexes, type-checked targets), fresh-versus-upgraded schema parity,
 * verify-before-advance, backfill batching, and the migration lock
 * (2.1.1: one request at a time). Real dbDelta behavior
 * (SHOW CREATE TABLE parity between fresh and upgraded installs) is
 * additionally verified live on the dev site per the prompt convention.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.0.0
 */

use PHPUnit\Framework\TestCase;

/**
 * Migrator test case
 *
 * @since 2.0.0
 */
class Test_Migrator extends TestCase {

	/**
	 * The fake wpdb for the current test.
	 *
	 * @var PPCert_Fake_WPDB
	 */
	private $wpdb;

	/**
	 * Reset the wpdb fake, options store, and dbDelta skip knob.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->wpdb = ppcert_tests_reset_wpdb();

		$GLOBALS['ppcert_test_options'] = [];
		unset( $GLOBALS['ppcert_test_dbdelta_skip_tables'], $GLOBALS['ppcert_test_dbdelta_keep_types'], $GLOBALS['ppcert_test_before_lock_insert'], $GLOBALS['ppcert_test_on_option_read'] );
	}

	/**
	 * Register the shipped 2.0.0 schema (all ten tables, captured from
	 * free 2.0.2) and stamp the stored version.
	 *
	 * @return void
	 */
	private function install_2_0_schema() {
		dbDelta( (string) file_get_contents( __DIR__ . '/fixtures/schema-2.0.0.sql' ) );
		update_option( 'ppcert_db_version', '2.0.0' );
	}

	/**
	 * Column types and index names of every table, for parity checks.
	 *
	 * @return array
	 */
	private function schema_snapshot() {
		$snapshot = [];

		foreach ( PressPrimer_Certificate_Schema::get_table_names() as $table ) {
			$snapshot[ $table ] = [
				'columns' => $this->wpdb->table_column_types( 'wp_' . $table ),
				'indexes' => $this->wpdb->table_indexes( 'wp_' . $table ),
			];
		}

		return $snapshot;
	}

	/**
	 * Register the eight 1.0-era tables as a 1.1.0 site would have them:
	 * no email templates table, no title column on certificates.
	 *
	 * @return void
	 */
	private function register_1_1_schema() {
		$this->wpdb->register_table(
			'wp_ppcert_certificates',
			[
				'id',
				'uuid',
				'credential_id',
				'template_id',
				'issuer_id',
				'recipient_id',
				'issued_by',
				'source_type',
				'source_ref',
				'status',
				'layout_schema_version',
				'layout_snapshot_json',
				'merge_data_json',
				'issued_at',
				'expires_at',
				'revoked_at',
				'revoke_reason',
				'created_at',
				'updated_at',
			]
		);

		foreach ( [ 'wp_ppcert_templates', 'wp_ppcert_triggers', 'wp_ppcert_issuers', 'wp_ppcert_issuer_members', 'wp_ppcert_credit_types', 'wp_ppcert_credits', 'wp_ppcert_events' ] as $table ) {
			$this->wpdb->register_table( $table, [ 'id' ] );
		}

		$this->wpdb->register_table( 'wp_ppcert_templates', [ 'settings_json' ] );
	}

	/**
	 * Seed a certificate row with a given merge_data_json payload.
	 *
	 * @param mixed $merge Merge map (array) or a raw string for invalid-JSON cases.
	 * @return int Row id.
	 */
	private function seed_certificate( $merge ) {
		static $n = 0;
		$n++;

		return $this->wpdb->seed_row(
			'wp_ppcert_certificates',
			[
				'uuid'            => 'uuid-' . $n,
				'credential_id'   => 'CRED' . $n,
				'template_id'     => 1,
				'recipient_id'    => 7,
				'status'          => 'issued',
				'merge_data_json' => is_array( $merge ) ? wp_json_encode( $merge ) : (string) $merge,
				'issued_at'       => '2026-08-01 10:00:00',
			]
		);
	}

	/**
	 * A fresh install runs the whole chain: all nine tables present, the
	 * certificates title column included, and the stored version at the
	 * chain head.
	 *
	 * @return void
	 */
	public function test_fresh_install_creates_all_tables_and_advances_version() {
		PressPrimer_Certificate_Migrator::maybe_migrate();

		foreach ( PressPrimer_Certificate_Schema::get_table_names() as $table ) {
			$this->assertNotEmpty(
				$this->wpdb->table_columns( 'wp_' . $table ),
				"Table {$table} was not created."
			);
		}

		$this->assertContains( 'title', $this->wpdb->table_columns( 'wp_ppcert_certificates' ) );
		$this->assertContains( 'context', $this->wpdb->table_columns( 'wp_ppcert_email_templates' ) );
		$this->assertContains( 'event_type', $this->wpdb->table_columns( 'wp_ppcert_audit' ), 'The audit table (Enterprise contract item 8) ships schema-only in 2.0.0' );
		$this->assertContains( 'object_type', $this->wpdb->table_columns( 'wp_ppcert_audit' ) );
		$this->assertSame( 'varchar(32)', $this->wpdb->table_column_types( 'wp_ppcert_templates' )['page_size'] );
		$this->assertContains( 'recipient_email', $this->wpdb->table_columns( 'wp_ppcert_certificates' ) );
		$this->assertContains( 'settings_json', $this->wpdb->table_columns( 'wp_ppcert_issuers' ) );
		$this->assertSame( PPCERT_DB_VERSION, get_option( 'ppcert_db_version' ) );
		$this->assertSame( '2.1.0', PPCERT_DB_VERSION );
	}

	/**
	 * A site already at the head version whose head-step targets fail
	 * verification (here: page_size still on the 2.0 ENUM, a CHANGE
	 * COLUMN that never applied) heals on the next day's load: the step
	 * re-runs, the column is widened, the version is untouched. A
	 * healthy head site runs no migration callback.
	 *
	 * @return void
	 */
	public function test_head_step_heals_failed_targets() {
		PressPrimer_Certificate_Migrator::maybe_migrate();
		$this->assertSame( '2.1.0', get_option( 'ppcert_db_version' ) );

		// Simulate the broken site: at 2.1.0 with page_size un-widened.
		$this->wpdb->register_table( 'wp_ppcert_templates', [ 'page_size' => "enum('a4','letter')" ] );

		// Verified today: no re-check yet (one verification per day).
		PressPrimer_Certificate_Migrator::maybe_migrate();
		$this->assertSame( "enum('a4','letter')", $this->wpdb->table_column_types( 'wp_ppcert_templates' )['page_size'], 'A same-day load trusts the daily verification' );

		// The next day.
		ppcert_tests_reset_transients();
		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( 'varchar(32)', $this->wpdb->table_column_types( 'wp_ppcert_templates' )['page_size'], 'The head step re-ran and widened the column' );
		$this->assertSame( '2.1.0', get_option( 'ppcert_db_version' ), 'Healing never changes the stored version' );
	}

	/**
	 * A second maybe_migrate() at the chain head is a no-op: no queries,
	 * no state changes.
	 *
	 * @return void
	 */
	public function test_migrate_is_idempotent() {
		PressPrimer_Certificate_Migrator::maybe_migrate();

		$schemas_before = [];

		foreach ( PressPrimer_Certificate_Schema::get_table_names() as $table ) {
			$schemas_before[ $table ] = $this->wpdb->table_columns( 'wp_' . $table );
		}

		$queries_before = $this->wpdb->read_queries;

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( $queries_before, $this->wpdb->read_queries, 'An up-to-date site ran queries.' );
		$this->assertSame( '2.1.0', get_option( 'ppcert_db_version' ) );

		foreach ( $schemas_before as $table => $columns ) {
			$this->assertSame( $columns, $this->wpdb->table_columns( 'wp_' . $table ) );
		}
	}

	/**
	 * The 1.1.0-to-2.0.0 upgrade on a populated fixture: the email
	 * templates table appears, the title column lands, and the backfill
	 * copies exactly the snapshots' non-empty certificate.title values.
	 *
	 * @return void
	 */
	public function test_upgrade_from_1_1_backfills_titles() {
		$this->register_1_1_schema();
		update_option( 'ppcert_db_version', '1.0.1' );

		$with_title  = $this->seed_certificate(
			[
				'recipient.full_name' => 'Dana Whitfield',
				'certificate.title'   => 'Botany Certificate',
			]
		);
		$pre_1_1     = $this->seed_certificate( [ 'recipient.full_name' => 'Dana Whitfield' ] );
		$empty_title = $this->seed_certificate( [ 'certificate.title' => '   ' ] );
		$long_title  = $this->seed_certificate( [ 'certificate.title' => str_repeat( 'x', 250 ) ] );
		$bad_json    = $this->seed_certificate( 'not-json{' );

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( '2.1.0', get_option( 'ppcert_db_version' ), 'A 1.1 site runs the whole chain to the head' );
		$this->assertNotEmpty( $this->wpdb->table_columns( 'wp_ppcert_email_templates' ) );
		$this->assertContains( 'title', $this->wpdb->table_columns( 'wp_ppcert_certificates' ) );

		$titles = [];

		foreach ( $this->wpdb->rows( 'wp_ppcert_certificates' ) as $row ) {
			$titles[ (int) $row['id'] ] = isset( $row['title'] ) ? $row['title'] : null;
		}

		$this->assertSame( 'Botany Certificate', $titles[ $with_title ] );
		$this->assertNull( $titles[ $pre_1_1 ], 'A pre-1.1 row (no certificate.title) must stay NULL.' );
		$this->assertNull( $titles[ $empty_title ], 'A whitespace-only title must stay NULL.' );
		$this->assertSame( str_repeat( 'x', 200 ), $titles[ $long_title ], 'Backfilled titles cap at 200 characters.' );
		$this->assertNull( $titles[ $bad_json ], 'Unparseable merge JSON must be skipped, not fatal.' );
	}

	/**
	 * Verify-before-advance: when the 2.0.0 step fails to produce its
	 * targets, the stored version stays put and the next load retries the
	 * same step successfully.
	 *
	 * @return void
	 */
	public function test_failed_step_does_not_advance_and_retries() {
		$this->register_1_1_schema();
		update_option( 'ppcert_db_version', '1.0.1' );

		$GLOBALS['ppcert_test_dbdelta_skip_tables'] = [ 'wp_ppcert_email_templates' ];

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( '1.0.1', get_option( 'ppcert_db_version' ), 'A failed step must not advance the version.' );

		unset( $GLOBALS['ppcert_test_dbdelta_skip_tables'] );

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( '2.1.0', get_option( 'ppcert_db_version' ), 'The retry must complete the chain.' );
		$this->assertNotEmpty( $this->wpdb->table_columns( 'wp_ppcert_email_templates' ) );
	}

	/**
	 * The 2.0.0-to-2.1.0 upgrade on a populated fixture: page_size widens
	 * to VARCHAR(32) with its values intact, certificates gain the two
	 * columns and three indexes, issuers gain settings_json, no row data
	 * changes, and the version advances.
	 *
	 * @return void
	 */
	public function test_upgrade_from_2_0_widens_page_size_and_adds_columns() {
		$this->install_2_0_schema();

		$this->assertSame( "enum('a4','letter')", $this->wpdb->table_column_types( 'wp_ppcert_templates' )['page_size'], 'The fixture is the shipped 2.0 schema' );

		$letter = $this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'        => 'tpl-1',
				'title'       => 'Letter template',
				'page_size'   => 'letter',
				'orientation' => 'landscape',
				'layout_json' => '{}',
			]
		);
		$a4     = $this->wpdb->seed_row(
			'wp_ppcert_templates',
			[
				'uuid'        => 'tpl-2',
				'title'       => 'A4 template',
				'page_size'   => 'a4',
				'orientation' => 'portrait',
				'layout_json' => '{}',
			]
		);
		$cert   = $this->seed_certificate( [ 'certificate.title' => 'Kept' ] );

		$templates_before    = $this->wpdb->rows( 'wp_ppcert_templates' );
		$certificates_before = $this->wpdb->rows( 'wp_ppcert_certificates' );

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( '2.1.0', get_option( 'ppcert_db_version' ) );

		$template_types    = $this->wpdb->table_column_types( 'wp_ppcert_templates' );
		$certificate_types = $this->wpdb->table_column_types( 'wp_ppcert_certificates' );

		$this->assertSame( 'varchar(32)', $template_types['page_size'] );
		$this->assertSame( 'varchar(40)', $certificate_types['credential_display'] );
		$this->assertSame( 'varchar(190)', $certificate_types['recipient_email'] );
		$this->assertSame( 'longtext', $this->wpdb->table_column_types( 'wp_ppcert_issuers' )['settings_json'] );

		foreach ( [ 'issued_at', 'issued_by', 'recipient_email' ] as $index ) {
			$this->assertContains( $index, $this->wpdb->table_indexes( 'wp_ppcert_certificates' ), "Index {$index} added" );
		}

		$this->assertSame( $templates_before, $this->wpdb->rows( 'wp_ppcert_templates' ), 'Template rows (and their page_size values) are untouched' );
		$this->assertSame( $certificates_before, $this->wpdb->rows( 'wp_ppcert_certificates' ), 'Certificate rows are untouched' );
		$this->assertGreaterThan( 0, $letter + $a4 + $cert );
	}

	/**
	 * A fresh 2.1.0 install and a 2.0.0 site upgraded to 2.1.0 end with
	 * identical tables: same columns, types, column order, and indexes
	 * (the fake's stand-in for comparing SHOW CREATE TABLE).
	 *
	 * @return void
	 */
	public function test_fresh_install_and_upgrade_schemas_match() {
		PressPrimer_Certificate_Migrator::maybe_migrate();
		$fresh = $this->schema_snapshot();

		$this->wpdb = ppcert_tests_reset_wpdb();
		$GLOBALS['ppcert_test_options'] = [];
		ppcert_tests_reset_transients();

		$this->install_2_0_schema();
		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( '2.1.0', get_option( 'ppcert_db_version' ) );
		$this->assertSame( $fresh, $this->schema_snapshot() );
	}

	/**
	 * Type-checked targets: when dbDelta leaves page_size on the ENUM (a
	 * CHANGE COLUMN that silently failed), the 2.1.0 step fails
	 * verification, the version stays at 2.0.0, and the next load
	 * retries and completes.
	 *
	 * @return void
	 */
	public function test_type_check_catches_unwidened_column() {
		$this->install_2_0_schema();

		$GLOBALS['ppcert_test_dbdelta_keep_types'] = [ 'wp_ppcert_templates' ];

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( '2.0.0', get_option( 'ppcert_db_version' ), 'An un-widened page_size must not advance the version' );
		$this->assertSame( "enum('a4','letter')", $this->wpdb->table_column_types( 'wp_ppcert_templates' )['page_size'] );
		$this->assertContains( 'recipient_email', $this->wpdb->table_columns( 'wp_ppcert_certificates' ), 'Presence alone would have passed: the new columns exist' );
		$this->assertArrayNotHasKey( 'ppcert_migration_lock', $GLOBALS['ppcert_test_options'], 'A failed step still releases the lock' );

		unset( $GLOBALS['ppcert_test_dbdelta_keep_types'] );

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( '2.1.0', get_option( 'ppcert_db_version' ), 'The retry completes the step' );
		$this->assertSame( 'varchar(32)', $this->wpdb->table_column_types( 'wp_ppcert_templates' )['page_size'] );
	}

	/**
	 * The 2.1.0 upgrade race (2.1.1): while another request holds the
	 * migration lock, a load that sees the old version runs nothing (no
	 * dbDelta, so no duplicate-column errors) and leaves the lock alone.
	 * A lock older than the wait (a long step, or a holder that died) is
	 * not waited on. Once the lock is gone, the next load migrates and
	 * releases it.
	 *
	 * @return void
	 */
	public function test_held_lock_skips_the_chain_until_released() {
		$this->install_2_0_schema();

		$held = (string) ( time() - 60 );

		$GLOBALS['ppcert_test_options']['ppcert_migration_lock'] = $held;

		$started = microtime( true );
		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertLessThan( 1, microtime( true ) - $started, 'A minute-old lock is not waited on' );
		$this->assertSame( '2.0.0', get_option( 'ppcert_db_version' ) );
		$this->assertSame( "enum('a4','letter')", $this->wpdb->table_column_types( 'wp_ppcert_templates' )['page_size'], 'dbDelta never ran' );
		$this->assertNotContains( 'credential_display', $this->wpdb->table_columns( 'wp_ppcert_certificates' ) );
		$this->assertSame( $held, $GLOBALS['ppcert_test_options']['ppcert_migration_lock'], "The holder's lock is untouched" );

		unset( $GLOBALS['ppcert_test_options']['ppcert_migration_lock'] );

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( '2.1.0', get_option( 'ppcert_db_version' ) );
		$this->assertContains( 'credential_display', $this->wpdb->table_columns( 'wp_ppcert_certificates' ) );
		$this->assertArrayNotHasKey( 'ppcert_migration_lock', $GLOBALS['ppcert_test_options'], 'The lock is released after the chain' );
	}

	/**
	 * A load that collides with a migration just begun waits for it, so
	 * it doesn't run against tables the step hasn't finished (an
	 * issuance insert naming credential_display would fail), and returns
	 * as soon as the holder releases the lock, without migrating itself.
	 *
	 * @return void
	 */
	public function test_waits_for_a_fresh_lock_to_be_released() {
		$this->install_2_0_schema();

		$GLOBALS['ppcert_test_options']['ppcert_migration_lock'] = (string) time();

		// Reads of the lock row: the failed take, two polls while held,
		// then the holder finishes before the third poll.
		$reads                                  = 0;
		$GLOBALS['ppcert_test_on_option_read'] = static function ( $name ) use ( &$reads ) {
			if ( 'ppcert_migration_lock' !== $name ) {
				return;
			}

			++$reads;

			if ( 4 === $reads ) {
				unset( $GLOBALS['ppcert_test_options']['ppcert_migration_lock'] );
			}
		};

		$started = microtime( true );
		PressPrimer_Certificate_Migrator::maybe_migrate();
		$elapsed = microtime( true ) - $started;

		$this->assertSame( 4, $reads, 'It polled until the lock was gone, then stopped' );
		$this->assertGreaterThanOrEqual( 0.35, $elapsed, 'It waited between polls' );
		$this->assertLessThan( 2, $elapsed, 'It returned as soon as the lock was released' );
		$this->assertSame( '2.0.0', get_option( 'ppcert_db_version' ), 'The waiting load leaves migrating to the holder' );
		$this->assertSame( "enum('a4','letter')", $this->wpdb->table_column_types( 'wp_ppcert_templates' )['page_size'] );
	}

	/**
	 * A lock older than ten minutes belongs to a request that died
	 * mid-migration: the next load takes it over and migrates. A younger
	 * one is still respected.
	 *
	 * @return void
	 */
	public function test_abandoned_lock_is_taken_over() {
		$this->install_2_0_schema();

		$GLOBALS['ppcert_test_options']['ppcert_migration_lock'] = (string) ( time() - 300 );

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( '2.0.0', get_option( 'ppcert_db_version' ), 'A five-minute-old lock is still held' );

		$GLOBALS['ppcert_test_options']['ppcert_migration_lock'] = (string) ( time() - 601 );

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( '2.1.0', get_option( 'ppcert_db_version' ), 'An abandoned lock is taken over' );
		$this->assertArrayNotHasKey( 'ppcert_migration_lock', $GLOBALS['ppcert_test_options'] );
	}

	/**
	 * The options a request loads at startup can be stale by the time it
	 * gets the lock: another request may have finished the chain in
	 * between. With the lock taken, the stored version is re-read from
	 * the table, and a finished chain is not run again.
	 *
	 * @return void
	 */
	public function test_lock_rechecks_the_stored_version() {
		$this->install_2_0_schema();

		// Another request finishes the chain after this one read 2.0.0.
		$GLOBALS['ppcert_test_before_lock_insert'] = static function () {
			$GLOBALS['ppcert_test_options']['ppcert_db_version'] = '2.1.0';
		};

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( "enum('a4','letter')", $this->wpdb->table_column_types( 'wp_ppcert_templates' )['page_size'], 'dbDelta did not run a second time' );
		$this->assertArrayNotHasKey( 'ppcert_migration_lock', $GLOBALS['ppcert_test_options'] );
	}

	/**
	 * The daily head heal takes the same lock: while it is held, a
	 * broken head step is left alone (without waiting: the site's
	 * version is current) and the daily check stays unstamped, so the
	 * next load heals it.
	 *
	 * @return void
	 */
	public function test_head_heal_respects_the_lock() {
		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->wpdb->register_table( 'wp_ppcert_templates', [ 'page_size' => "enum('a4','letter')" ] );
		ppcert_tests_reset_transients();

		$GLOBALS['ppcert_test_options']['ppcert_migration_lock'] = (string) time();

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( "enum('a4','letter')", $this->wpdb->table_column_types( 'wp_ppcert_templates' )['page_size'] );
		$this->assertFalse( get_transient( 'ppcert_db_head_verified' ), 'The daily check is not stamped while the step is unhealed' );

		unset( $GLOBALS['ppcert_test_options']['ppcert_migration_lock'] );

		PressPrimer_Certificate_Migrator::maybe_migrate();

		$this->assertSame( 'varchar(32)', $this->wpdb->table_column_types( 'wp_ppcert_templates' )['page_size'] );
		$this->assertSame( '2.1.0', get_option( 'ppcert_db_version' ) );
		$this->assertArrayNotHasKey( 'ppcert_migration_lock', $GLOBALS['ppcert_test_options'] );
	}

	/**
	 * The backfill pages through batches with keyset pagination: every
	 * eligible row across multiple batches is filled, rows that resolve
	 * to NULL are passed over, and the loop terminates.
	 *
	 * @return void
	 */
	public function test_backfill_pages_through_batches() {
		$this->register_1_1_schema();

		$expected = [];

		for ( $i = 1; $i <= 7; $i++ ) {
			$id              = $this->seed_certificate( [ 'certificate.title' => 'Award ' . $i ] );
			$expected[ $id ] = 'Award ' . $i;

			// Interleave rows that stay NULL so a batch is never all-fillable.
			$this->seed_certificate( [ 'recipient.full_name' => 'Skip Me' ] );
		}

		PressPrimer_Certificate_Migrator::backfill_certificate_titles( 3 );

		foreach ( $this->wpdb->rows( 'wp_ppcert_certificates' ) as $row ) {
			$id = (int) $row['id'];

			if ( isset( $expected[ $id ] ) ) {
				$this->assertSame( $expected[ $id ], $row['title'] );
			} else {
				$this->assertArrayNotHasKey( 'title', $row, 'A NULL-title row must not be written.' );
			}
		}
	}
}
