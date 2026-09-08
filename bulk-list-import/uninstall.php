<?php
/**
 * Uninstall cleanup.
 *
 * WordPress loads this file when the plugin is deleted — not deactivated. It
 * runs without the plugin bootstrapped, so nothing here may assume the
 * autoloader, the main file's constants, or WooCommerce.
 *
 * Everything the plugin created goes: two tables, and every option it wrote.
 * WordPress.org reviewers check for this, and a plugin that leaves tables behind
 * after deletion is one of the things they look for.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Table names are built here rather than loaded from Schema, because loading
// plugin classes during uninstall is fragile and this is two lines.
$bli_rows    = $wpdb->prefix . 'bli_import_rows';
$bli_imports = $wpdb->prefix . 'bli_imports';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- an uninstaller that did not drop its tables would be the bug.
$wpdb->query( "DROP TABLE IF EXISTS {$bli_rows}" );
$wpdb->query( "DROP TABLE IF EXISTS {$bli_imports}" );
// phpcs:enable

$bli_options = array(
	'bli_db_version',
	'bli_batch_size',
	'bli_sku_prefix',
	'bli_sku_reserved',
	'bli_sku_lock',
	'bli_import_reports',
	'bli_api_keys',
	'bli_ai_provider',
	'bli_ai_model',
	'bli_ai_industry',
	'bli_ai_tone',
	'bli_ai_length',
	'bli_ai_template',
);

foreach ( $bli_options as $bli_option ) {
	delete_option( $bli_option );
}

// Cached recognition verdicts and model lists. Transients are options with a
// prefix, and there is no API for deleting them by pattern.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_bli_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_bli_' ) . '%'
	)
);
// phpcs:enable
