<?php
/**
 * The Import Report — the second of the two required review moments.
 *
 * The pre-import gate (Phase 4) stops invention at source. It cannot catch a
 * call that fails halfway through, a malformed response, or a save error. That
 * is what this screen is for, and it must exist before the AI layer so Phase 4
 * only adds new failure *reasons*, not a new screen.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores and renders import reports.
 */
class Import_Report {

	private const OPTION = 'bli_import_reports';

	/**
	 * How many reports to keep. The user must be able to close the tab, come
	 * back, and still see what happened.
	 */
	private const KEEP = 10;

	/**
	 * Persist a report and return it.
	 *
	 * @param array<int, array<string, mixed>> $entries One entry per input row.
	 * @return array<string, mixed>
	 */
	public static function save( array $entries ): array {
		$report = array(
			'id'      => uniqid( 'imp_', false ),
			'time'    => time(),
			'user'    => get_current_user_id(),
			'entries' => array_values( $entries ),
			'totals'  => self::totals( $entries ),
		);

		$reports = self::all();
		array_unshift( $reports, $report );
		$reports = array_slice( $reports, 0, self::KEEP );

		update_option( self::OPTION, $reports, false );

		return $report;
	}

	/**
	 * All stored reports, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$reports = get_option( self::OPTION, array() );

		return is_array( $reports ) ? $reports : array();
	}

	/**
	 * Fetch one report by ID.
	 *
	 * @param string $id Report ID.
	 * @return array<string, mixed>|null
	 */
	public static function get( string $id ): ?array {
		foreach ( self::all() as $report ) {
			if ( (string) ( $report['id'] ?? '' ) === $id ) {
				return $report;
			}
		}

		return null;
	}

	/**
	 * Count outcomes.
	 *
	 * @param array<int, array<string, mixed>> $entries Report entries.
	 * @return array<string, int>
	 */
	public static function totals( array $entries ): array {
		$totals = array(
			'created'        => 0,
			'created_verify' => 0,
			'skipped'        => 0,
			'failed'         => 0,
			'not_generated'  => 0,
		);

		foreach ( $entries as $entry ) {
			$outcome = (string) ( $entry['outcome'] ?? '' );
			if ( isset( $totals[ $outcome ] ) ) {
				++$totals[ $outcome ];
			}
		}

		return $totals;
	}

	/**
	 * Human label and CSS modifier for an outcome.
	 *
	 * @param string $outcome Stored outcome key.
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
			case 'failed':
			default:
				return array( __( 'Failed', 'bulk-list-import' ), 'bad' );
		}
	}

	/**
	 * Render the reports screen: a single report if one is requested, otherwise a list.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view import reports.', 'bulk-list-import' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$id     = isset( $_GET['report'] ) && is_string( $_GET['report'] ) ? sanitize_text_field( wp_unslash( $_GET['report'] ) ) : '';
		$report = '' !== $id ? self::get( $id ) : ( self::all()[0] ?? null );

		echo '<div class="wrap bli-wrap">';
		echo '<h1>' . esc_html__( 'Import Report', 'bulk-list-import' ) . '</h1>';

		if ( null === $report ) {
			echo '<p>' . esc_html__( 'No imports yet.', 'bulk-list-import' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( Admin_Page::url() ) . '">'
				. esc_html__( 'Start an import', 'bulk-list-import' ) . '</a></p>';
			echo '</div>';
			return;
		}

		self::render_report( $report );
		self::render_history( (string) $report['id'] );

		echo '</div>';
	}

	/**
	 * Render one report.
	 *
	 * @param array<string, mixed> $report Report record.
	 */
	private static function render_report( array $report ): void {
		$totals  = (array) ( $report['totals'] ?? array() );
		$entries = (array) ( $report['entries'] ?? array() );

		$attention = (int) ( $totals['created_verify'] ?? 0 ) + (int) ( $totals['not_generated'] ?? 0 );

		printf(
			'<p class="bli-report-summary">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: created count, 2: needs-attention count, 3: failed count, 4: skipped count. */
					__( 'Import complete — %1$d created, %2$d need attention, %3$d failed, %4$d skipped', 'bulk-list-import' ),
					(int) ( $totals['created'] ?? 0 ),
					$attention,
					(int) ( $totals['failed'] ?? 0 ),
					(int) ( $totals['skipped'] ?? 0 )
				)
			)
		);

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: date and time. */
					__( 'Run %s. Every row you pasted appears below.', 'bulk-list-import' ),
					wp_date( 'j M Y, H:i', (int) ( $report['time'] ?? time() ) )
				)
			)
		);

		if ( bli_is_pro() ) {
			$url = wp_nonce_url(
				admin_url( 'admin-post.php?action=bli_export_report&report=' . rawurlencode( (string) $report['id'] ) ),
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
		echo '<th>' . esc_html__( 'Reason', 'bulk-list-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'bulk-list-import' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			list( $label, $modifier ) = self::outcome_label( (string) ( $entry['outcome'] ?? '' ) );

			$name = (string) ( $entry['name'] ?? '' );
			if ( '' === $name ) {
				$name = (string) ( $entry['raw'] ?? '' );
			}
			$variant = (string) ( $entry['variant'] ?? '' );

			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $entry['line'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $name );
			if ( '' !== $variant ) {
				echo ' <span class="bli-variant">' . esc_html( $variant ) . '</span>';
			}
			echo '</td>';
			$sku = (string) ( $entry['sku'] ?? '' );
			echo '<td>' . esc_html( '' !== $sku ? $sku : '—' ) . '</td>';
			echo '<td><span class="bli-badge bli-badge--' . esc_attr( $modifier ) . '">' . esc_html( $label ) . '</span></td>';
			$reason = (string) ( $entry['reason'] ?? '' );
			echo '<td>' . esc_html( '' !== $reason ? $reason : '—' ) . '</td>';
			echo '<td>';

			if ( ! empty( $entry['edit_url'] ) ) {
				echo '<a href="' . esc_url( (string) $entry['edit_url'] ) . '">' . esc_html__( 'Edit product', 'bulk-list-import' ) . '</a>';
			} elseif ( 'failed' === ( $entry['outcome'] ?? '' ) ) {
				$retry = wp_nonce_url(
					Admin_Page::url(
						Admin_Page::SLUG,
						'retry=' . rawurlencode( (string) ( $report['id'] ?? '' ) )
						. '&line=' . (int) ( $entry['line'] ?? 0 )
					),
					'bli_retry'
				);
				echo '<a href="' . esc_url( $retry ) . '">' . esc_html__( 'Retry', 'bulk-list-import' ) . '</a>';
			} else {
				echo '—';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Links to earlier reports.
	 *
	 * @param string $current ID of the report on screen.
	 */
	private static function render_history( string $current ): void {
		$reports = self::all();

		if ( count( $reports ) < 2 ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Earlier imports', 'bulk-list-import' ) . '</h2><ul class="bli-history">';

		foreach ( $reports as $report ) {
			$id     = (string) ( $report['id'] ?? '' );
			$totals = (array) ( $report['totals'] ?? array() );
			$label  = sprintf(
				/* translators: 1: date, 2: created count, 3: failed count. */
				__( '%1$s — %2$d created, %3$d failed', 'bulk-list-import' ),
				wp_date( 'j M Y, H:i', (int) ( $report['time'] ?? 0 ) ),
				(int) ( $totals['created'] ?? 0 ),
				(int) ( $totals['failed'] ?? 0 )
			);

			echo '<li>';
			if ( $id === $current ) {
				echo '<strong>' . esc_html( $label ) . '</strong>';
			} else {
				$url = Admin_Page::url( Admin_Page::REPORT_SLUG, 'report=' . rawurlencode( $id ) );
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}
			echo '</li>';
		}

		echo '</ul>';
	}

	/**
	 * Neutralise a CSV cell that a spreadsheet would execute as a formula.
	 *
	 * Excel, LibreOffice and Google Sheets treat a cell beginning with =, +, -
	 * or @ as a formula, and strip a leading tab or carriage return before
	 * deciding. Every text column in this report carries whatever the user
	 * pasted, so it can begin with any of them. A leading apostrophe tells all
	 * three "the rest is literal text" and is not shown to the reader.
	 *
	 * @param string $value Raw cell value.
	 */
	private static function csv_cell( string $value ): string {
		if ( '' !== $value && 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}

		return $value;
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

		$id     = isset( $_GET['report'] ) && is_string( $_GET['report'] ) ? sanitize_text_field( wp_unslash( $_GET['report'] ) ) : '';
		$report = self::get( $id );

		if ( null === $report ) {
			wp_die( esc_html__( 'That import report no longer exists.', 'bulk-list-import' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		// Filename comes from the stored report, never straight from the request.
		$filename = sanitize_file_name( 'bulk-list-import-' . (string) $report['id'] . '.csv' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Row', 'Product', 'Variant', 'SKU', 'Outcome', 'Reason', 'Raw line' ) );

		foreach ( (array) $report['entries'] as $entry ) {
			list( $label ) = self::outcome_label( (string) ( $entry['outcome'] ?? '' ) );
			fputcsv(
				$out,
				array(
					self::csv_cell( (string) ( $entry['line'] ?? '' ) ),
					self::csv_cell( (string) ( $entry['name'] ?? '' ) ),
					self::csv_cell( (string) ( $entry['variant'] ?? '' ) ),
					self::csv_cell( (string) ( $entry['sku'] ?? '' ) ),
					self::csv_cell( $label ),
					self::csv_cell( (string) ( $entry['reason'] ?? '' ) ),
					self::csv_cell( (string) ( $entry['raw'] ?? '' ) ),
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a download to php://output; WP_Filesystem cannot stream.
		fclose( $out );
		exit;
	}
}
