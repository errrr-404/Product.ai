<?php
/**
 * Custom tables for import reports.
 *
 * Reports moved off a serialised option because Phase 4 writes them one row at a
 * time from separate Action Scheduler jobs, and concurrent read-modify-write on a
 * single option silently loses updates. That would have reintroduced "a row
 * vanished from the report" through the storage of the very screen built to
 * prevent it. Size settles it too: batch size is user-editable and the last ten
 * imports are kept, so that is thousands of rows in one blob.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, upgrades and drops the plugin's tables.
 */
final class Schema {

	/**
	 * Bump when a table definition changes. dbDelta is gated on this rather than
	 * run on every load: it inspects the live schema, which is not free, and doing
	 * that on every request to save one option read is a bad trade.
	 */
	public const VERSION = 1;

	/**
	 * Option holding the installed schema version.
	 */
	public const OPTION = 'bli_db_version';

	/**
	 * Table holding one row per import run.
	 */
	public static function imports_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bli_imports';
	}

	/**
	 * Table holding one row per pasted line.
	 */
	public static function rows_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'bli_import_rows';
	}

	/**
	 * Install or upgrade the tables if the stored version is behind.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::OPTION, 0 ) === self::VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Create or alter the tables to match the current definition.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$imports = self::imports_table();
		$rows    = self::rows_table();

		// dbDelta is particular: two spaces after PRIMARY KEY, one field per line,
		// KEY rather than INDEX, and types in lower case. It compares this text
		// against the live schema, so formatting is not cosmetic here.
		$imports_sql = "CREATE TABLE {$imports} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'queued',
			total smallint(5) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset};";

		$rows_sql = "CREATE TABLE {$rows} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			import_id bigint(20) unsigned NOT NULL,
			line smallint(5) unsigned NOT NULL DEFAULT 0,
			name varchar(255) NOT NULL DEFAULT '',
			variant varchar(255) NOT NULL DEFAULT '',
			raw text NOT NULL,
			sku varchar(100) NOT NULL DEFAULT '',
			product_id bigint(20) unsigned NOT NULL DEFAULT 0,
			outcome varchar(32) NOT NULL DEFAULT 'pending',
			reason text NOT NULL,
			attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
			payload longtext NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY import_id (import_id),
			KEY outcome (outcome)
		) {$charset};";

		dbDelta( $imports_sql );
		dbDelta( $rows_sql );

		update_option( self::OPTION, self::VERSION, false );
	}

	/**
	 * Drop both tables and forget the version.
	 *
	 * Called from uninstall.php only. WordPress.org reviewers check that a plugin
	 * cleans up after itself, and leaving tables behind on uninstall is one of the
	 * things they look for.
	 */
	public static function drop(): void {
		global $wpdb;

		$imports = self::imports_table();
		$rows    = self::rows_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping our own tables is the entire purpose of this method.
		$wpdb->query( "DROP TABLE IF EXISTS {$rows}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$imports}" );
		// phpcs:enable

		delete_option( self::OPTION );
	}
}
