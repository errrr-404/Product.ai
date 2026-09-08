<?php
/**
 * The Import Report — the second of the two required review moments.
 *
 * The pre-import gate stops invention at source. It cannot catch a call that
 * fails halfway through, a malformed response, or a save error. That is what
 * this screen is for.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders import reports from the store.
 */
class Import_Report {

	/**
	 * Persist a finished set of entries and return the new import id.
	 *
	 * @param array<int, array<string, mixed>> $entries One entry per input row.
	 * @return int Import id.
	 */
	public static function save( array $entries ): int {
		$import_id = Import_Store::create_import( count( $entries ) );

		foreach ( $entries as $entry ) {
			Import_Store::add_row( $import_id, $entry );
		}

		Import_Store::set_status( $import_id, 'complete' );
		Import_Store::prune();

		return $import_id;
	}

	/**
	 * Human label and CSS modifier for an outcome.
	 *
	 * @param string $outcome Outcome slug.
	 * @return array{0: string, 1: string}
	 */
	public static function outcome_label( string $outcome ): array {
		switch ( $outcome ) {
			case 'created':
				return array( __( 'Created', 'bulk-list-import' ), 'ok' );
			case 'created_verify':
				return array( __( 'Created, verify', 'bulk-list-import' ), 'warn' );
			case 'skipped':
				return array( __( 'Skipped', 'bulk-list-import' ), 'muted' );
			case 'not_generated':
				return array( __( 'Not generated', 'bulk-list-import' ), 'warn' );
			case 'pending':
				return array( __( 'Waiting', 'bulk-list-import' ), 'muted' );
			case 'failed':
			default:
				return array( __( 'Failed', 'bulk-list-import' ), 'bad' );
		}
	}

	/**
	 * Render the reports screen.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view import reports.', 'bulk-list-import' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$requested = isset( $_GET['report'] ) ? absint( $_GET['report'] ) : 0;
		$recent    = Import_Store::recent();
		$import    = $requested > 0 ? Import_Store::get_import( $requested ) : ( $recent[0] ?? null );

		echo '<div class="wrap bli-wrap">';
		echo '<h1>' . esc_html__( 'Import Report', 'bulk-list-import' ) . '</h1>';

		if ( null === $import ) {
			echo '<p>' . esc_html__( 'No imports yet.', 'bulk-list-import' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( Admin_Page::url() ) . '">'
				. esc_html__( 'Start an import', 'bulk-list-import' ) . '</a></p>';
			echo '</div>';
			return;
		}

		self::render_report( $import );
		self::render_history( $recent, (int) $import['id'] );

		echo '</div>';
	}

	/**
	 * Render one report.
	 *
	 * @param array<string, mixed> $import Import record.
	 */
	private static function render_report( array $import ): void {
		$import_id = (int) $import['id'];
		$totals    = Import_Store::totals( $import_id );
		$rows      = Import_Store::get_rows( $import_id );

		$attention = $totals['created_verify'] + $totals['not_generated'];

		printf(
			'<p class="bli-report-summary">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: created count, 2: needs-attention count, 3: failed count, 4: skipped count. */
					__( 'Import complete — %1$d created, %2$d need attention, %3$d failed, %4$d skipped', 'bulk-list-import' ),
					$totals['created'],
					$attention,
					$totals['failed'],
					$totals['skipped']
				)
			)
		);

		if ( $totals['pending'] > 0 ) {
			printf(
				'<p class="bli-warning">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of rows still queued. */
						__( '%d rows are still queued. This page updates as they finish — you can close it and come back.', 'bulk-list-import' ),
						$totals['pending']
					)
				)
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'Run %s. Every row you pasted appears below.', 'bulk-list-import' ),
					wp_date( 'j M Y, H:i', (int) strtotime( (string) $import['created_at'] . ' UTC' ) )
				)
			)
		);

		if ( bli_is_pro() ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=bli_export_report&report=' . $import_id ),
				'bli_export_report'
			);
			echo '<p><a class="button" href="' . esc_url( $url ) . '">'
				. esc_html__( 'Export CSV', 'bulk-list-import' ) . '</a></p>';
		} else {
			echo '<p class="description bli-pro-hint">'
				. esc_html__( 'CSV export of the needs-attention list is a Pro feature.', 'bulk-list-import' )
				. '</p>';
		}

		echo '<table class="widefat striped bli-report">';
		echo '<thead><tr>';
		echo '<th class="bli-col-row">' . esc_html__( 'Row', 'bulk-list-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Product', 'bulk-list-import' ) . '</th>';
		echo '<th>' . esc_html__( 'SKU', 'bulk-list-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Outcome', 'bulk-list-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Tries', 'bulk-list-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Reason', 'bulk-list-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'bulk-list-import' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			self::render_row( $import_id, $row );
		}

		echo '</tbody></table>';
	}

	/**
	 * One report row.
	 *
	 * @param int                  $import_id Import id.
	 * @param array<string, mixed> $row       Row record.
	 */
	private static function render_row( int $import_id, array $row ): void {
		list( $label, $modifier ) = self::outcome_label( (string) ( $row['outcome'] ?? '' ) );

		$name = (string) ( $row['name'] ?? '' );

		if ( '' === $name ) {
			$name = (string) ( $row['raw'] ?? '' );
		}

		$variant  = (string) ( $row['variant'] ?? '' );
		$sku      = (string) ( $row['sku'] ?? '' );
		$reason   = (string) ( $row['reason'] ?? '' );
		$attempts = (int) ( $row['attempts'] ?? 0 );

		echo '<tr>';
		echo '<td>' . esc_html( (string) ( $row['line'] ?? '' ) ) . '</td>';
		echo '<td>' . esc_html( $name );

		if ( '' !== $variant ) {
			echo ' <span class="bli-variant">' . esc_html( $variant ) . '</span>';
		}

		echo '</td>';
		echo '<td>' . esc_html( '' !== $sku ? $sku : '—' ) . '</td>';
		echo '<td><span class="bli-badge bli-badge--' . esc_attr( $modifier ) . '">' . esc_html( $label ) . '</span></td>';

		// "Failed once, will retry" and "gave up after three" are different states,
		// and a report that shows both as "Failed" is asking the user to guess.
		echo '<td>' . esc_html( $attempts > 0 ? (string) $attempts : '—' ) . '</td>';

		echo '<td>' . esc_html( '' !== $reason ? $reason : '—' ) . '</td>';
		echo '<td>';

		$product_id = (int) ( $row['product_id'] ?? 0 );

		if ( $product_id > 0 ) {
			$edit = get_edit_post_link( $product_id, 'raw' );

			if ( $edit ) {
				echo '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit product', 'bulk-list-import' ) . '</a>';
			} else {
				echo '—';
			}
		} elseif ( 'failed' === ( $row['outcome'] ?? '' ) ) {
			$retry = wp_nonce_url(
				Admin_Page::url( Admin_Page::SLUG, 'retry=' . $import_id . '&line=' . (int) ( $row['line'] ?? 0 ) ),
				'bli_retry'
			);
			echo '<a href="' . esc_url( $retry ) . '">' . esc_html__( 'Retry', 'bulk-list-import' ) . '</a>';
		} else {
			echo '—';
		}

		echo '</td></tr>';
	}

	/**
	 * Links to earlier reports.
	 *
	 * @param array<int, array<string, mixed>> $recent  Recent imports.
	 * @param int                              $current Currently shown import id.
	 */
	private static function render_history( array $recent, int $current ): void {
		if ( count( $recent ) < 2 ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Earlier imports', 'bulk-list-import' ) . '</h2><ul class="bli-history">';

		foreach ( $recent as $import ) {
			$import_id = (int) $import['id'];
			$totals    = Import_Store::totals( $import_id );

			$label = sprintf(
				/* translators: 1: date, 2: created count, 3: failed count. */
				__( '%1$s — %2$d created, %3$d failed', 'bulk-list-import' ),
				wp_date( 'j M Y, H:i', (int) strtotime( (string) $import['created_at'] . ' UTC' ) ),
				$totals['created'],
				$totals['failed']
			);

			echo '<li>';

			if ( $import_id === $current ) {
				echo '<strong>' . esc_html( $label ) . '</strong>';
			} else {
				echo '<a href="' . esc_url( Admin_Page::url( Admin_Page::REPORT_SLUG, 'report=' . $import_id ) ) . '">'
					. esc_html( $label ) . '</a>';
			}

			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Stream a report as CSV. Pro only.
	 */
	public static function export_csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export import reports.', 'bulk-list-import' ) );
		}

		check_admin_referer( 'bli_export_report' );

		if ( ! bli_is_pro() ) {
			wp_die( esc_html__( 'CSV export is a Pro feature.', 'bulk-list-import' ) );
		}

		$import_id = isset( $_GET['report'] ) ? absint( $_GET['report'] ) : 0;
		$import    = $import_id > 0 ? Import_Store::get_import( $import_id ) : null;

		if ( null === $import ) {
			wp_die( esc_html__( 'That import report no longer exists.', 'bulk-list-import' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( 'bulk-list-import-' . $import_id . '.csv' ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem cannot stream to php://output, which is the whole mechanism of a download.
		$out = fopen( 'php://output', 'w' );

		fputcsv( $out, array( 'Row', 'Product', 'Variant', 'SKU', 'Outcome', 'Tries', 'Reason', 'Raw line' ) );

		foreach ( Import_Store::get_rows( $import_id ) as $row ) {
			list( $label ) = self::outcome_label( (string) ( $row['outcome'] ?? '' ) );

			fputcsv(
				$out,
				array(
					self::csv_cell( (string) ( $row['line'] ?? '' ) ),
					self::csv_cell( (string) ( $row['name'] ?? '' ) ),
					self::csv_cell( (string) ( $row['variant'] ?? '' ) ),
					self::csv_cell( (string) ( $row['sku'] ?? '' ) ),
					self::csv_cell( $label ),
					self::csv_cell( (string) ( $row['attempts'] ?? 0 ) ),
					self::csv_cell( (string) ( $row['reason'] ?? '' ) ),
					self::csv_cell( (string) ( $row['raw'] ?? '' ) ),
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with the fopen above; WP_Filesystem has no streaming equivalent.
		fclose( $out );
		exit;
	}

	/**
	 * Neutralise a CSV cell that a spreadsheet would treat as a formula.
	 *
	 * This export exists so a shop owner can hand the needs-attention list to
	 * someone else, so the payload travels to a second person who has no reason to
	 * distrust the file. A leading =, +, - or @ makes Excel and Sheets evaluate the
	 * cell, and the content here came from a pasted product list.
	 *
	 * @param string $value Cell value.
	 */
	private static function csv_cell( string $value ): string {
		if ( '' !== $value && str_contains( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}

		return $value;
	}
}
