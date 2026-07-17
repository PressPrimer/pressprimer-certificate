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
 * lesson, in its simplest form - a presence check per step).
 *
 * 1.0 ships the initial chain entry only.
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
	 * Maybe run migrations
	 *
	 * Checks if the database needs to be updated and runs migrations if
	 * necessary. Safe to call multiple times - only runs when the stored
	 * version is behind PPCERT_DB_VERSION. Called on activation and on
	 * every load (the retry path for a previously failed step).
	 *
	 * @since 1.0.0
	 */
	public static function maybe_migrate() {
		$current_version = get_option( self::DB_VERSION_OPTION, '0' );

		if ( version_compare( $current_version, PPCERT_DB_VERSION, '>=' ) ) {
			return;
		}

		self::run_migrations( $current_version );
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
	}

	/**
	 * Get the ordered migration chain
	 *
	 * Each step: 'version' (target), 'callback' (idempotent), 'targets'
	 * (map of unprefixed table name => array of required column names; an
	 * empty array means table presence only). A new schema version appends
	 * a step here.
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
	 * Verify that a step's target tables (and columns) exist
	 *
	 * Presence check only - existence of each table, and of each named
	 * column within it. Not a full definition comparison.
	 *
	 * @since 1.0.0
	 *
	 * @param array $targets Map of unprefixed table name => required column names.
	 * @return string[] Missing items ('table' or 'table.column'), empty when all present.
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

			$existing_columns = $wpdb->get_col(
				$wpdb->prepare( 'SHOW COLUMNS FROM %i', $full_table )
			);

			foreach ( $columns as $column ) {
				if ( ! in_array( $column, $existing_columns, true ) ) {
					$missing[] = $table . '.' . $column;
				}
			}
		}

		return $missing;
	}
}
