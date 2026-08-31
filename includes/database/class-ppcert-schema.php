<?php
/**
 * Database schema
 *
 * Defines all database table structures for PressPrimer Certificate.
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
 * Schema class
 *
 * Provides SQL definitions for all plugin database tables, exactly matching
 * docs/architecture/DATABASE.md (any deviation updates that document in the
 * same commit - 008-foundation TR-002). Uses dbDelta-compatible syntax.
 *
 * All DATETIME columns store UTC. Code writes them with
 * current_time( 'mysql', true ) - never local time (CLAUDE.md Datetime
 * Standard). The CURRENT_TIMESTAMP defaults are a safety net only; every
 * insert/update in plugin code supplies explicit UTC values.
 *
 * The issuer, credit, and event foundation tables ship in 1.0 with no UI so
 * that 2.0 (issuers) and 3.0 (credits) land without migrations; the email
 * templates table ships schema-only in 2.0 for Educator 2.0/2.1 (Decision
 * 005). Do not remove or "clean up" dormant tables.
 *
 * @since 1.0.0
 */
class PressPrimer_Certificate_Schema {

	/**
	 * Get the complete schema SQL
	 *
	 * Returns SQL for creating all plugin tables.
	 * Uses dbDelta-compatible syntax.
	 *
	 * @since 1.0.0
	 *
	 * @return string SQL for all table creation.
	 */
	public static function get_schema() {
		return implode( '', self::get_core_table_sql() );
	}

	/**
	 * Get the unprefixed names of all plugin tables
	 *
	 * Single source of truth for the table list. Consumed by the migrator's
	 * presence verification; keep uninstall.php's drop list in sync.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Unprefixed table names.
	 */
	public static function get_table_names() {
		return [
			'ppcert_templates',
			'ppcert_certificates',
			'ppcert_triggers',
			'ppcert_issuers',
			'ppcert_issuer_members',
			'ppcert_credit_types',
			'ppcert_credits',
			'ppcert_events',
			'ppcert_email_templates',
		];
	}

	/**
	 * Get the CREATE TABLE statement for every core table, keyed by table name.
	 *
	 * Single source of truth for the plugin's schema: get_schema() concatenates
	 * these statements for dbDelta().
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Map of full table name => CREATE TABLE SQL.
	 */
	private static function get_core_table_sql() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		return [
			$wpdb->prefix . 'ppcert_templates'       => self::get_templates_table( $charset_collate ),
			$wpdb->prefix . 'ppcert_certificates'    => self::get_certificates_table( $charset_collate ),
			$wpdb->prefix . 'ppcert_triggers'        => self::get_triggers_table( $charset_collate ),
			$wpdb->prefix . 'ppcert_issuers'         => self::get_issuers_table( $charset_collate ),
			$wpdb->prefix . 'ppcert_issuer_members'  => self::get_issuer_members_table( $charset_collate ),
			$wpdb->prefix . 'ppcert_credit_types'    => self::get_credit_types_table( $charset_collate ),
			$wpdb->prefix . 'ppcert_credits'         => self::get_credits_table( $charset_collate ),
			$wpdb->prefix . 'ppcert_events'          => self::get_events_table( $charset_collate ),
			$wpdb->prefix . 'ppcert_email_templates' => self::get_email_templates_table( $charset_collate ),
		];
	}

	/**
	 * Get templates table schema
	 *
	 * Certificate designs. The layout JSON is the single source of truth
	 * consumed by the designer canvas and the PDF renderer.
	 *
	 * @since 1.0.0
	 *
	 * @param string $charset_collate Character set and collation.
	 * @return string SQL for templates table.
	 */
	private static function get_templates_table( $charset_collate ) {
		global $wpdb;

		return "CREATE TABLE {$wpdb->prefix}ppcert_templates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			title VARCHAR(200) NOT NULL,
			status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
			author_id BIGINT UNSIGNED NOT NULL,
			issuer_id BIGINT UNSIGNED DEFAULT NULL,
			page_size ENUM('a4','letter') NOT NULL DEFAULT 'a4',
			orientation ENUM('landscape','portrait') NOT NULL DEFAULT 'landscape',
			layout_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			layout_json LONGTEXT NOT NULL,
			settings_json LONGTEXT DEFAULT NULL,
			is_starter TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			deleted_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY status (status),
			KEY author_id (author_id),
			KEY issuer_id (issuer_id)
		) $charset_collate;\n";
	}

	/**
	 * Get certificates table schema
	 *
	 * Issued certificates. Immutable after issuance except for status
	 * transitions; layout_snapshot_json + merge_data_json freeze the
	 * certificate exactly as issued.
	 *
	 * @since 1.0.0
	 *
	 * @param string $charset_collate Character set and collation.
	 * @return string SQL for certificates table.
	 */
	private static function get_certificates_table( $charset_collate ) {
		global $wpdb;

		return "CREATE TABLE {$wpdb->prefix}ppcert_certificates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			credential_id VARCHAR(32) NOT NULL,
			template_id BIGINT UNSIGNED NOT NULL,
			issuer_id BIGINT UNSIGNED DEFAULT NULL,
			recipient_id BIGINT UNSIGNED NOT NULL,
			issued_by BIGINT UNSIGNED NOT NULL,
			source_type VARCHAR(32) NOT NULL DEFAULT 'manual',
			source_ref VARCHAR(191) DEFAULT NULL,
			status ENUM('issued','revoked','expired') NOT NULL DEFAULT 'issued',
			title VARCHAR(200) DEFAULT NULL,
			layout_schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			layout_snapshot_json LONGTEXT NOT NULL,
			merge_data_json LONGTEXT NOT NULL,
			issued_at DATETIME NOT NULL,
			expires_at DATETIME DEFAULT NULL,
			revoked_at DATETIME DEFAULT NULL,
			revoke_reason VARCHAR(191) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY credential_id (credential_id),
			KEY recipient_id (recipient_id),
			KEY template_id (template_id),
			KEY issuer_id (issuer_id),
			KEY status (status),
			KEY source (source_type,source_ref),
			KEY title (title(60))
		) $charset_collate;\n";
	}

	/**
	 * Get triggers table schema
	 *
	 * Maps templates to issuance events. trigger_lookup is the hot index:
	 * issuance listeners query by (trigger_type, source_ref, is_active) on
	 * every candidate event.
	 *
	 * @since 1.0.0
	 *
	 * @param string $charset_collate Character set and collation.
	 * @return string SQL for triggers table.
	 */
	private static function get_triggers_table( $charset_collate ) {
		global $wpdb;

		return "CREATE TABLE {$wpdb->prefix}ppcert_triggers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			template_id BIGINT UNSIGNED NOT NULL,
			trigger_type VARCHAR(32) NOT NULL,
			source_ref VARCHAR(191) DEFAULT NULL,
			conditions_json LONGTEXT DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY template_id (template_id),
			KEY trigger_lookup (trigger_type,source_ref,is_active)
		) $charset_collate;\n";
	}

	/**
	 * Get issuers table schema (dormant foundation - UI in School 2.0)
	 *
	 * @since 1.0.0
	 *
	 * @param string $charset_collate Character set and collation.
	 * @return string SQL for issuers table.
	 */
	private static function get_issuers_table( $charset_collate ) {
		global $wpdb;

		return "CREATE TABLE {$wpdb->prefix}ppcert_issuers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			name VARCHAR(200) NOT NULL,
			slug VARCHAR(200) NOT NULL,
			description TEXT DEFAULT NULL,
			logo_id BIGINT UNSIGNED DEFAULT NULL,
			website VARCHAR(191) DEFAULT NULL,
			brand_json LONGTEXT DEFAULT NULL,
			status ENUM('active','archived') NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY slug (slug),
			KEY status (status)
		) $charset_collate;\n";
	}

	/**
	 * Get issuer members table schema (dormant foundation - UI in School 2.0)
	 *
	 * Issuer membership model (public identity + delegated issuance rights),
	 * not a classroom Groups model - see docs/decisions/001.
	 *
	 * @since 1.0.0
	 *
	 * @param string $charset_collate Character set and collation.
	 * @return string SQL for issuer members table.
	 */
	private static function get_issuer_members_table( $charset_collate ) {
		global $wpdb;

		return "CREATE TABLE {$wpdb->prefix}ppcert_issuer_members (
			issuer_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role ENUM('owner','issuer','signatory') NOT NULL DEFAULT 'issuer',
			added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			added_by BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (issuer_id,user_id),
			KEY user_id (user_id),
			KEY role (role)
		) $charset_collate;\n";
	}

	/**
	 * Get credit types table schema (dormant foundation - UI in 3.0)
	 *
	 * @since 1.0.0
	 *
	 * @param string $charset_collate Character set and collation.
	 * @return string SQL for credit types table.
	 */
	private static function get_credit_types_table( $charset_collate ) {
		global $wpdb;

		return "CREATE TABLE {$wpdb->prefix}ppcert_credit_types (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			name VARCHAR(200) NOT NULL,
			slug VARCHAR(200) NOT NULL,
			unit_label VARCHAR(50) DEFAULT NULL,
			description TEXT DEFAULT NULL,
			status ENUM('active','archived') NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			UNIQUE KEY slug (slug)
		) $charset_collate;\n";
	}

	/**
	 * Get credits table schema (dormant foundation - UI in 3.0)
	 *
	 * The credit ledger. certificate_id is nullable so 3.0 can record credits
	 * from sources other than a certificate without a schema change.
	 *
	 * @since 1.0.0
	 *
	 * @param string $charset_collate Character set and collation.
	 * @return string SQL for credits table.
	 */
	private static function get_credits_table( $charset_collate ) {
		global $wpdb;

		return "CREATE TABLE {$wpdb->prefix}ppcert_credits (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			certificate_id BIGINT UNSIGNED DEFAULT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			credit_type_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(8,2) NOT NULL DEFAULT 0.00,
			awarded_at DATETIME NOT NULL,
			expires_at DATETIME DEFAULT NULL,
			source_json LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY user_credit (user_id,credit_type_id),
			KEY certificate_id (certificate_id),
			KEY expires_at (expires_at)
		) $charset_collate;\n";
	}

	/**
	 * Get email templates table schema (dormant foundation - Decision 005)
	 *
	 * Ships schema-only in free 2.0. Educator 2.0 writes the expiry-reminder
	 * subject/body as a reminder-context row; Educator 2.1 ships the manager
	 * UI for issuance-context rows mapped from certificate templates via
	 * settings_json.email_template_id. The free plugin owns the table and
	 * the resolution chain, so deactivating Educator degrades cleanly.
	 *
	 * context is VARCHAR, not ENUM, so future email kinds need no migration
	 * (recognized 2.x values: 'issuance', 'reminder').
	 *
	 * @since 2.0.0
	 *
	 * @param string $charset_collate Character set and collation.
	 * @return string SQL for email templates table.
	 */
	private static function get_email_templates_table( $charset_collate ) {
		global $wpdb;

		return "CREATE TABLE {$wpdb->prefix}ppcert_email_templates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid CHAR(36) NOT NULL,
			title VARCHAR(200) NOT NULL,
			context VARCHAR(32) NOT NULL DEFAULT 'issuance',
			subject VARCHAR(255) NOT NULL,
			body LONGTEXT NOT NULL,
			status ENUM('active','archived') NOT NULL DEFAULT 'active',
			author_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			deleted_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY context (context),
			KEY status (status),
			KEY author_id (author_id)
		) $charset_collate;\n";
	}

	/**
	 * Get events table schema
	 *
	 * Certificate lifecycle event log. Privacy rule: no raw IP addresses or
	 * user agents in meta_json; anonymous events store actor_id NULL and
	 * nothing identifying (DATABASE.md).
	 *
	 * @since 1.0.0
	 *
	 * @param string $charset_collate Character set and collation.
	 * @return string SQL for events table.
	 */
	private static function get_events_table( $charset_collate ) {
		global $wpdb;

		return "CREATE TABLE {$wpdb->prefix}ppcert_events (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			certificate_id BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(32) NOT NULL,
			actor_id BIGINT UNSIGNED DEFAULT NULL,
			meta_json LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY certificate_id (certificate_id),
			KEY event_type (event_type),
			KEY created_at (created_at)
		) $charset_collate;\n";
	}
}
