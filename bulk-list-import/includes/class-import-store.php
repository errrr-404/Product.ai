<?php
/**
 * Reading and writing import reports.
 *
 * Every write here touches one row. That is the whole point: once generation is
 * queued, separate jobs update separate rows concurrently, and anything that
 * rewrote a whole record would lose the updates it did not know about.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistence for imports and their rows.
 */
final class Import_Store {

	/**
	 * How many imports to keep.
	 */
	public const KEEP = 10;

	/**
	 * Start an import and return its id.
	 *
	 * @param int $total Number of pasted rows this import covers.
	 */
	public static function create_import( int $total ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Schema::imports_table(),
			array(
				'created_at' => $now,
				'updated_at' => $now,
				'user_id'    => get_current_user_id(),
				'status'     => 'queued',
				'total'      => $total,
			),
			array( '%s', '%s', '%d', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Add one row to an import.
	 *
	 * @param int                  $import_id Import id.
	 * @param array<string, mixed> $row       Row fields.
	 * @return int The new row id.
	 */
	public static function add_row( int $import_id, array $row ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			Schema::rows_table(),
			array(
				'import_id'  => $import_id,
				'line'       => (int) ( $row['line'] ?? 0 ),
				'name'       => (string) ( $row['name'] ?? '' ),
				'variant'    => (string) ( $row['variant'] ?? '' ),
				'raw'        => (string) ( $row['raw'] ?? '' ),
				'sku'        => (string) ( $row['sku'] ?? '' ),
				'product_id' => (int) ( $row['product_id'] ?? 0 ),
				'outcome'    => (string) ( $row['outcome'] ?? 'pending' ),
				'reason'     => (string) ( $row['reason'] ?? '' ),
				'attempts'   => (int) ( $row['attempts'] ?? 0 ),
				'payload'    => (string) wp_json_encode( $row['payload'] ?? array() ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update one row.
	 *
	 * Only the named columns are written, so two jobs touching different rows —
	 * or the same row at different stages — cannot clobber each other's work.
	 *
	 * @param int                  $row_id Row id.
	 * @param array<string, mixed> $fields Columns to set.
	 */
	public static function update_row( int $row_id, array $fields ): void {
		global $wpdb;

		$allowed = array(
			'sku'        => '%s',
			'product_id' => '%d',
			'outcome'    => '%s',
			'reason'     => '%s',
			'attempts'   => '%d',
			'payload'    => '%s',
			'name'       => '%s',
			'variant'    => '%s',
		);

		$data   = array();
		$format = array();

		foreach ( $fields as $column => $value ) {
			if ( ! isset( $allowed[ $column ] ) ) {
				continue;
			}

			$data[ $column ] = 'payload' === $column && ! is_string( $value ) ? (string) wp_json_encode( $value ) : $value;
			$format[]        = $allowed[ $column ];
		}

		if ( array() === $data ) {
			return;
		}

		$data['updated_at'] = current_time( 'mysql', true );
		$format[]           = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( Schema::rows_table(), $data, array( 'id' => $row_id ), $format, array( '%d' ) );
	}

	/**
	 * Mark an import finished.
	 *
	 * @param int    $import_id Import id.
	 * @param string $status    queued|running|complete.
	 */
	public static function set_status( int $import_id, string $status ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Schema::imports_table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $import_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * One import record, or null.
	 *
	 * @param int $import_id Import id.
	 * @return array<string, mixed>|null
	 */
	public static function get_import( int $import_id ): ?array {
		global $wpdb;

		$table = Schema::imports_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $import_id ),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Every row of an import, in pasted order.
	 *
	 * Ordered by line, then id, because "every input row appears" is only useful
	 * if they appear in the order the user pasted them.
	 *
	 * @param int $import_id Import id.
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_rows( int $import_id ): array {
		global $wpdb;

		$table = Schema::rows_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE import_id = %d ORDER BY line ASC, id ASC", $import_id ),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The most recent imports, newest first.
	 *
	 * @param int $limit How many.
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = self::KEEP ): array {
		global $wpdb;

		$table = Schema::imports_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", max( 1, $limit ) ),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count outcomes for one import.
	 *
	 * @param int $import_id Import id.
	 * @return array<string, int>
	 */
	public static function totals( int $import_id ): array {
		global $wpdb;

		$table = Schema::rows_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = $wpdb->get_results(
			$wpdb->prepare( "SELECT outcome, COUNT(*) AS n FROM {$table} WHERE import_id = %d GROUP BY outcome", $import_id ),
			ARRAY_A
		);
		// phpcs:enable

		$totals = array(
			'created'        => 0,
			'created_verify' => 0,
			'skipped'        => 0,
			'failed'         => 0,
			'not_generated'  => 0,
			'pending'        => 0,
		);

		foreach ( (array) $counts as $count ) {
			$outcome = (string) ( $count['outcome'] ?? '' );

			if ( isset( $totals[ $outcome ] ) ) {
				$totals[ $outcome ] = (int) $count['n'];
			}
		}

		return $totals;
	}

	/**
	 * Delete all but the newest KEEP imports.
	 */
	public static function prune(): void {
		global $wpdb;

		$imports = Schema::imports_table();
		$rows    = Schema::rows_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stale = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$imports} ORDER BY created_at DESC, id DESC LIMIT 18446744073709551615 OFFSET %d",
				self::KEEP
			)
		);

		foreach ( (array) $stale as $import_id ) {
			$wpdb->delete( $rows, array( 'import_id' => (int) $import_id ), array( '%d' ) );
			$wpdb->delete( $imports, array( 'id' => (int) $import_id ), array( '%d' ) );
		}
		// phpcs:enable
	}
}
