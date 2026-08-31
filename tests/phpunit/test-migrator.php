<?php
/**
 * Migrator tests
 *
 * Exercises the version-chain migrator against the fake wpdb's schema
 * registry and the bootstrap's dbDelta shim: fresh install, idempotency,
 * the 1.1.0-to-2.0.0 upgrade on a populated fixture (title backfill),
 * verify-before-advance, and backfill batching. Real dbDelta behavior
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
		unset( $GLOBALS['ppcert_test_dbdelta_skip_tables'] );
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
		$this->assertSame( '2.0.0', get_option( 'ppcert_db_version' ) );
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
		$this->assertSame( '2.0.0', get_option( 'ppcert_db_version' ) );

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

		$this->assertSame( '2.0.0', get_option( 'ppcert_db_version' ) );
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

		$this->assertSame( '2.0.0', get_option( 'ppcert_db_version' ), 'The retry must complete the chain.' );
		$this->assertNotEmpty( $this->wpdb->table_columns( 'wp_ppcert_email_templates' ) );
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
