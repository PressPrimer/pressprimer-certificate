<?php
/**
 * Database migrator
 *
 * Handles database schema creation and version migrations.
 *
 * @package PressPrimer_Certificate
 * @subpackage Database
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migrator class
 *
 * Version-chain migration pattern (008-foundation FR-002): each step pairs a
 * target version with an idempotent callback and the tables/columns it must
 * produce. After running a step, its targets are verified as present BEFORE
 * the stored ppcert_db_version advances. A step that fails verification
 * leaves the version at the last verified step, so the chain retries from
 * there on the next load instead of stranding the site on an advanced
 * version with a half-applied schema (the Quiz 3.0 verify-before-advance
 * lesson, in its simplest form - a presence check per step). Since 2.1.0
 * a target column can also name the type prefix it must have, so a step
 * that changes a column's type (page_size ENUM to VARCHAR) is verified
 * by type, not just presence.
 *
 * Since 2.1.1 one request at a time runs the chain (or the head heal).
 * The first loads after an update arrive together - visitors, WP-Cron,
 * AJAX - and each saw the old version and ran dbDelta; every request
 * but the first logged "Duplicate column name" / "Duplicate key name"
 * for each column and index the step adds. A request that finds the
 * lock taken waits briefly for the holder to finish rather than run
 * against tables the step hasn't finished (an insert naming a new
 * column fails until it exists), then carries on without migrating.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Migrator {

	/**
	 * Option name for storing database version
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const DB_VERSION_OPTION = 'ppcert_db_version';

	/**
	 * Transient recording that the head step's targets were verified
	 * present for the current DB version (heal_head_step()).
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const HEAD_VERIFIED_TRANSIENT = 'ppcert_db_head_verified';

	/**
	 * Option row that holds the migration lock: the Unix time it was taken
	 *
	 * @since 2.1.1
	 * @var string
	 */
	const LOCK_OPTION = 'ppcert_migration_lock';

	/**
	 * Seconds after which a lock counts as abandoned (its request died
	 * mid-migration) and the next load takes it over
	 *
	 * @since 2.1.1
	 * @var int
	 */
	const LOCK_TIMEOUT = 600;

	/**
	 * Seconds a request waits for another request's migration: the most
	 * it waits, and the lock age past which it doesn't wait at all (the
	 * holder is on a very long step or died, and every load stalling
	 * until LOCK_TIMEOUT would be worse than a load that runs early)
	 *
	 * @since 2.1.1
	 * @var int
	 */
	const LOCK_WAIT = 15;

	/**
	 * The value this request wrote into the lock row, or null when it
	 * holds no lock; release only deletes a row that still carries it
	 *
	 * @since 2.1.1
	 * @var string|null
	 */
	private static $lock_value = null;

	/**
	 * Maybe run migrations
	 *
	 * Checks if the database needs to be updated and runs migrations if
	 * necessary. Safe to call multiple times - only runs when the stored
	 * version is behind PPCERT_DB_VERSION. Called on activation and on
	 * every load (the retry path for a previously failed step). While
	 * another request holds the migration lock, this load waits for it
	 * (wait_for_lock()) and skips the chain; a later load picks up
	 * anything left.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_migrate() {
		$current_version = get_option( self::DB_VERSION_OPTION, '0' );

		if ( version_compare( $current_version, PPCERT_DB_VERSION, '>=' ) ) {
			self::heal_head_step();
			return;
		}

		if ( ! self::acquire_lock() ) {
			self::wait_for_lock();
			return;
		}

		try {
			// The options this request loaded can predate a chain another
			// request finished before the lock came free: re-read the
			// stored version from the table.
			$current_version = self::stored_version();

			if ( version_compare( $current_version, PPCERT_DB_VERSION, '<' ) ) {
				self::run_migrations( $current_version );
			}
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Re-run the chain's head step when its targets are missing
	 *
	 * The stored version says the site is current, but a target the
	 * head step produces is absent: a table dropped by hand, a partial
	 * failure that somehow advanced, or - the case that motivated this -
	 * a site that ran an unreleased version of the head step before the
	 * step gained a target (the audit table joined 2.0.0 after dev
	 * sites had already migrated to 2.0.0). Steps are idempotent
	 * (dbDelta), so re-running the head step is always safe; the
	 * version is not touched. Cheap when healthy: one SHOW TABLES per
	 * target table on each load, same as verify_targets() always cost
	 * during a migration.
	 *
	 * @since 2.0.0
	 *
	 * @return bool Whether the head step was re-run.
	 */
	private static function heal_head_step() {
		// One verification per day per site, not per request: the
		// targets are a handful of SHOW TABLES / SHOW COLUMNS queries.
		if ( PPCERT_DB_VERSION === get_transient( self::HEAD_VERIFIED_TRANSIENT ) ) {
			return false;
		}

		$steps = self::get_migration_steps();
		$head  = end( $steps );

		if ( ! is_array( $head ) || version_compare( (string) $head['version'], PPCERT_DB_VERSION, '!=' ) ) {
			return false;
		}

		$healed = false;

		if ( ! empty( self::verify_targets( $head['targets'] ) ) ) {
			// Another request is migrating or healing: leave the daily
			// check unstamped so the next load looks again.
			if ( ! self::acquire_lock() ) {
				return false;
			}

			try {
				// It may have healed the step before the lock came free.
				if ( ! empty( self::verify_targets( $head['targets'] ) ) ) {
					call_user_func( $head['callback'] );
					$healed = true;
				}
			} finally {
				self::release_lock();
			}
		}

		if ( empty( self::verify_targets( $head['targets'] ) ) ) {
			set_transient( self::HEAD_VERIFIED_TRANSIENT, PPCERT_DB_VERSION, DAY_IN_SECONDS );
		}

		return $healed;
	}

	/**
	 * Take the migration lock
	 *
	 * WP_Upgrader::create_lock()'s pattern: INSERT IGNORE into the options
	 * table, whose unique option_name lets exactly one request create the
	 * row (add_option() upserts, so two requests would both "win"). A
	 * lock older than LOCK_TIMEOUT belongs to a request that died, and is
	 * taken over with a compare-and-swap UPDATE so two requests can't
	 * both claim it. The row is written directly and never read through
	 * get_option(), so no options cache sits between requests and the
	 * row; it is not autoloaded.
	 *
	 * @since 2.1.1
	 *
	 * @return bool Whether this request now holds the lock.
	 */
	private static function acquire_lock() {
		global $wpdb;

		$now = (string) time();

		$created = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				$wpdb->options,
				self::LOCK_OPTION,
				$now
			)
		);

		if ( ! $created ) {
			$locked_at = $wpdb->get_var(
				$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK_OPTION )
			);

			// Released in between (the holder just finished), or still
			// fresh: leave the work to the holder or the next load.
			if ( null === $locked_at || (int) $locked_at > (int) $now - self::LOCK_TIMEOUT ) {
				return false;
			}

			$taken = $wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET option_value = %s WHERE option_name = %s AND option_value = %s',
					$wpdb->options,
					$now,
					self::LOCK_OPTION,
					$locked_at
				)
			);

			if ( 1 !== (int) $taken ) {
				return false;
			}
		}

		self::$lock_value = $now;

		return true;
	}

	/**
	 * Release the migration lock
	 *
	 * Deletes the row only while it still carries this request's value,
	 * so a request whose lock was taken over as abandoned can't release
	 * the new holder's lock.
	 *
	 * @since 2.1.1
	 */
	private static function release_lock() {
		global $wpdb;

		if ( null === self::$lock_value ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND option_value = %s',
				$wpdb->options,
				self::LOCK_OPTION,
				self::$lock_value
			)
		);

		self::$lock_value = null;
	}

	/**
	 * Wait for another request's migration to finish
	 *
	 * Polls the lock row until it is gone, for at most LOCK_WAIT seconds,
	 * and not at all once the lock is LOCK_WAIT seconds old. Before
	 * 2.1.1 every request ran the step itself, which held it until the
	 * tables matched; this keeps that without the duplicate ALTERs.
	 *
	 * @since 2.1.1
	 */
	private static function wait_for_lock() {
		global $wpdb;

		$deadline = microtime( true ) + self::LOCK_WAIT;

		do {
			$locked_at = $wpdb->get_var(
				$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::LOCK_OPTION )
			);

			if ( null === $locked_at || time() - (int) $locked_at >= self::LOCK_WAIT ) {
				return;
			}

			usleep( 200000 );
		} while ( microtime( true ) < $deadline );
	}

	/**
	 * Read the stored DB version from the options table
	 *
	 * Bypasses the options cache, which holds the value this request
	 * loaded at startup.
	 *
	 * @since 2.1.1
	 *
	 * @return string Stored version, '0' when none.
	 */
	private static function stored_version() {
		global $wpdb;

		$stored = $wpdb->get_var(
			$wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::DB_VERSION_OPTION )
		);

		return null === $stored ? '0' : (string) $stored;
	}

	/**
	 * Run the migration chain
	 *
	 * Executes pending steps in order, verifying each step's targets before
	 * advancing the stored version. Aborts (without advancing) on the first
	 * step whose targets fail the presence check.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from_version Version migrating from.
	 */
	private static function run_migrations( $from_version ) {
		$current = $from_version;

		foreach ( self::get_migration_steps() as $step ) {
			// Skip steps already applied.
			if ( version_compare( $current, $step['version'], '>=' ) ) {
				continue;
			}

			// Run the (idempotent) step.
			call_user_func( $step['callback'] );

			// Verify the tables/columns this step produces BEFORE advancing
			// the version (008-foundation FR-002).
			$missing = self::verify_targets( $step['targets'] );

			if ( ! empty( $missing ) ) {
				// Do not advance. The chain retries from $current (this same
				// step) on the next load.
				return;
			}

			// Step verified: advance the stored DB version to this step.
			$current = $step['version'];
			update_option( self::DB_VERSION_OPTION, $current );
		}

		// The chain reached the head with its targets just verified:
		// stamp the daily head check so the next load skips it.
		if ( version_compare( $current, PPCERT_DB_VERSION, '>=' ) ) {
			set_transient( self::HEAD_VERIFIED_TRANSIENT, PPCERT_DB_VERSION, DAY_IN_SECONDS );
		}
	}

	/**
	 * Get the ordered migration chain
	 *
	 * Each step: 'version' (target), 'callback' (idempotent), 'targets'
	 * (map of unprefixed table name => required columns; an empty array
	 * means table presence only). A column is listed either by name
	 * (presence) or as name => type prefix (2.1.0: the column must exist
	 * AND its SHOW COLUMNS type must start with that prefix, compared
	 * case-insensitively). A new schema version appends a step here.
	 *
	 * @since 1.0.0
	 *
	 * @return array[] Ordered steps.
	 */
	private static function get_migration_steps() {
		// 1.0.0: initial schema - all eight tables, presence-checked.
		$initial_targets = array_fill_keys( PressPrimer_Certificate_Schema::get_table_names(), [] );

		return [
			[
				'version'  => '1.0.0',
				'callback' => [ __CLASS__, 'migrate_to_1_0_0' ],
				'targets'  => $initial_targets,
			],
			// 1.0.1: template settings (validity periods) - the
			// settings_json column on wp_ppcert_templates.
			[
				'version'  => '1.0.1',
				'callback' => [ __CLASS__, 'migrate_to_1_0_1' ],
				'targets'  => [ 'ppcert_templates' => [ 'settings_json' ] ],
			],
			// 2.0.0: the email templates table (Decision 005, schema only),
			// the audit table (Enterprise contract item 8, schema only),
			// and the search-only title column on certificates (Feature
			// 2.0-002 TR-002), backfilled from each row's snapshot; plus
			// directory_visibility, the School directory's consent flag
			// (contract item 6: NULL = site policy, 1 = listed,
			// 0 = unlisted).
			[
				'version'  => '2.0.0',
				'callback' => [ __CLASS__, 'migrate_to_2_0_0' ],
				'targets'  => [
					'ppcert_email_templates' => [],
					'ppcert_audit'           => [],
					'ppcert_certificates'    => [ 'title', 'directory_visibility' ],
				],
			],
			// 2.1.0 (Feature 2.1-004 FR-001): page_size widens to
			// VARCHAR(32) for the page-size registry; certificates gain
			// credential_display and recipient_email (plus the issued_at,
			// issued_by, and recipient_email indexes); issuers gain
			// settings_json. Type-checked targets: a page_size still on
			// the 2.0 ENUM fails verification.
			[
				'version'  => '2.1.0',
				'callback' => [ __CLASS__, 'migrate_to_2_1_0' ],
				'targets'  => [
					'ppcert_templates'    => [ 'page_size' => 'varchar(32)' ],
					'ppcert_certificates' => [
						'credential_display' => 'varchar(40)',
						'recipient_email'    => 'varchar(190)',
					],
					'ppcert_issuers'      => [ 'settings_json' => 'longtext' ],
				],
			],
		];
	}

	/**
	 * Migration step 1.0.0: create the initial schema
	 *
	 * Runs dbDelta over the full eight-table schema. Idempotent.
	 *
	 * @since 1.0.0
	 */
	public static function migrate_to_1_0_0() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( PressPrimer_Certificate_Schema::get_schema() );
	}

	/**
	 * Migration step 1.0.1: add settings_json to templates
	 *
	 * Runs dbDelta over the full schema, which adds the new column;
	 * idempotent for clean installs that already carry it.
	 *
	 * @since 1.0.0
	 */
	public static function migrate_to_1_0_1() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( PressPrimer_Certificate_Schema::get_schema() );
	}

	/**
	 * Migration step 2.0.0: email templates table + certificate title column
	 *
	 * Runs dbDelta over the full schema (creates wp_ppcert_email_templates
	 * and adds the title column with its prefix index), then backfills
	 * title from each existing row's merge_data_json. Idempotent: dbDelta
	 * is a no-op when the schema matches, and the backfill only touches
	 * rows whose title is still NULL.
	 *
	 * @since 2.0.0
	 */
	public static function migrate_to_2_0_0() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( PressPrimer_Certificate_Schema::get_schema() );

		self::backfill_certificate_titles();
	}

	/**
	 * Migration step 2.1.0: page-size column, certificate and issuer columns
	 *
	 * Runs dbDelta over the full schema: CHANGE COLUMN page_size from
	 * ENUM('a4','letter') to VARCHAR(32) (stored values carry over), ADD
	 * the certificate and issuer columns and indexes. No data changes.
	 * Idempotent.
	 *
	 * @since 2.1.0
	 */
	public static function migrate_to_2_1_0() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( PressPrimer_Certificate_Schema::get_schema() );
	}

	/**
	 * Backfill wp_ppcert_certificates.title from merge_data_json
	 *
	 * The title column is a search-only copy of the snapshot's
	 * certificate.title (Feature 2.0-002 TR-002): rows whose snapshot
	 * carries a non-empty certificate.title get it copied (200-char cap,
	 * matching the pattern sanitizer); rows without one - every pre-1.1
	 * certificate - stay NULL. Display logic is unchanged and keeps
	 * reading the snapshot via Certificate::display_title().
	 *
	 * Keyset-paginated so large sites never load the whole table; safe to
	 * re-run (only NULL-title rows are examined, and unmatched rows are
	 * simply skipped again). Every migrator update sets `updated_at`
	 * explicitly in UTC (2.1); here it keeps the row's own value.
	 *
	 * @since 2.0.0
	 *
	 * @param int $batch_size Rows per batch (tests exercise multi-batch
	 *                        paging with a small size). Default 500.
	 */
	public static function backfill_certificate_titles( $batch_size = 500 ) {
		global $wpdb;

		$table      = $wpdb->prefix . 'ppcert_certificates';
		$batch_size = max( 1, absint( $batch_size ) );
		$last_id    = 0;

		do {
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, merge_data_json, updated_at FROM %i WHERE title IS NULL AND id > %d ORDER BY id ASC LIMIT %d',
					$table,
					$last_id,
					$batch_size
				)
			);

			$batch_count = count( $rows );

			foreach ( $rows as $row ) {
				$last_id = max( $last_id, (int) $row->id );

				$merge = json_decode( (string) $row->merge_data_json, true );

				if ( ! is_array( $merge ) || ! isset( $merge['certificate.title'] ) ) {
					continue;
				}

				$title = trim( (string) $merge['certificate.title'] );

				if ( '' === $title ) {
					continue;
				}

				// updated_at is written explicitly (2.1, Feature 2.1-007
				// FR-008): without it MySQL's ON UPDATE CURRENT_TIMESTAMP
				// rewrote it in server-local time. A backfill is not an
				// edit, so the row keeps its own (UTC) value.
				$wpdb->update(
					$table,
					[
						'title'      => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 200 ) : substr( $title, 0, 200 ),
						'updated_at' => (string) $row->updated_at,
					],
					[ 'id' => (int) $row->id ],
					[ '%s', '%s' ],
					[ '%d' ]
				);
			}
		} while ( $batch_count === $batch_size );
	}

	/**
	 * Verify that a step's target tables (and columns) exist
	 *
	 * Existence of each table, of each named column, and - for columns
	 * listed as name => type prefix - that the column's SHOW COLUMNS type
	 * starts with the prefix (case-insensitive). Not a full definition
	 * comparison.
	 *
	 * @since 1.0.0
	 * @since 2.1.0 Column entries may be name => type prefix.
	 *
	 * @param array $targets Map of unprefixed table name => required columns.
	 * @return string[] Missing items ('table', 'table.column', or
	 *                  'table.column:expected-type'), empty when all present.
	 */
	private static function verify_targets( $targets ) {
		global $wpdb;

		$missing = [];

		foreach ( $targets as $table => $columns ) {
			$full_table = $wpdb->prefix . $table;

			$found = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table )
			);

			if ( $found !== $full_table ) {
				$missing[] = $table;
				continue;
			}

			if ( empty( $columns ) ) {
				continue;
			}

			$types = [];

			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $full_table ) ) as $row ) {
				$row = (array) $row;

				if ( isset( $row['Field'] ) ) {
					$types[ (string) $row['Field'] ] = isset( $row['Type'] ) ? strtolower( (string) $row['Type'] ) : '';
				}
			}

			foreach ( $columns as $key => $value ) {
				$column   = is_int( $key ) ? (string) $value : (string) $key;
				$expected = is_int( $key ) ? '' : strtolower( (string) $value );

				if ( ! isset( $types[ $column ] ) ) {
					$missing[] = $table . '.' . $column;
					continue;
				}

				if ( '' !== $expected && 0 !== strpos( $types[ $column ], $expected ) ) {
					$missing[] = $table . '.' . $column . ':' . $expected;
				}
			}
		}

		return $missing;
	}
}
