<?php
/**
 * Turns reviewed preview rows into draft WooCommerce products.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates products from preview rows and records what happened to every one.
 */
class Importer {

	/**
	 * Import a batch of reviewed rows.
	 *
	 * Runs in four passes:
	 *
	 *   1. Plan every row, deciding which will attempt creation. No writes.
	 *   2. Reserve exactly that many SKUs, under a lock held for milliseconds.
	 *   3. Record every row, so the report exists before any work is attempted.
	 *   4. Dispatch the rows that need creating — to the queue, or inline.
	 *
	 * Pass 3 before pass 4 is the point. The report is written first so that a
	 * process dying between them leaves a complete record of what was meant to
	 * happen, rather than products with no report or a report with no rows.
	 *
	 * Every input row produces exactly one report entry. A row that vanishes
	 * silently is the worst outcome in this plugin — the user believes they
	 * imported 20 products when they imported 16.
	 *
	 * @param array<int, array<string, mixed>> $rows   Reviewed rows from the preview table.
	 * @param string                           $prefix Optional SKU prefix override.
	 * @return int The id of the persisted report.
	 */
	public function import( array $rows, string $prefix = '' ): int {
		// Pass 1 — plan. Rows rejected here never consume a SKU.
		$plans  = array();
		$needed = 0;

		foreach ( $rows as $index => $row ) {
			$plan            = $this->plan_row( $row );
			$plans[ $index ] = $plan;

			if ( 'create' === $plan['action'] ) {
				++$needed;
			}
		}

		// Pass 2 — reserve. Short locked transaction, released immediately. SKUs are
		// claimed once, here, and each queued job carries its own: reserving inside a
		// job would hold the sequence lock across AI generation, for minutes, blocking
		// every other import on the site.
		$reserved = array();

		if ( $needed > 0 ) {
			try {
				$reserved = SKU_Generator::detect( $prefix )->reserve( $needed );
			} catch ( \Throwable $e ) {
				return Import_Report::save( $this->fail_all( $rows, $e ) );
			}
		}

		// Pass 3 — record. Nothing has been created yet.
		$import_id = Import_Store::create_import( count( $rows ) );
		$queued    = array();
		$cursor    = 0;

		foreach ( $rows as $index => $row ) {
			$plan = $plans[ $index ];

			if ( 'create' !== $plan['action'] ) {
				Import_Store::add_row( $import_id, $this->entry( $row, $plan['outcome'], $plan['reason'] ) );
				continue;
			}

			$sku = $reserved[ $cursor ];
			++$cursor;

			$entry            = $this->entry( $row, 'pending', __( 'Queued.', 'bulk-list-import' ) );
			$entry['sku']     = $sku;
			$entry['payload'] = array(
				'name'        => $plan['name'],
				'variant'     => $plan['variant'],
				'price'       => $plan['price'],
				'notices'     => $plan['notices'],
				'line'        => (int) ( $row['line'] ?? 0 ),
				'raw'         => (string) ( $row['raw'] ?? '' ),
				'gate_state'  => (string) ( $row['gate_state'] ?? '' ),
				'gate_action' => (string) ( $row['gate_action'] ?? '' ),
				'details'     => (array) ( $row['details'] ?? array() ),
			);

			$queued[] = Import_Store::add_row( $import_id, $entry );
		}

		Import_Store::prune();

		// Pass 4 — dispatch.
		$this->dispatch( $import_id, $queued );

		return $import_id;
	}

	/**
	 * Hand the queued rows to Action Scheduler, or run them here.
	 *
	 * The queue is skipped when there is no AI to wait for: a free-tier import is a
	 * handful of CRUD writes that finish in well under a second, and sending the
	 * user to a report full of "Waiting" for work already done would be worse than
	 * useless. With generation on, every row goes to the queue — three to eight
	 * seconds each is exactly the arithmetic that kills a single request.
	 *
	 * @param int             $import_id Import id.
	 * @param array<int, int> $row_ids   Rows needing creation.
	 */
	private function dispatch( int $import_id, array $row_ids ): void {
		if ( array() === $row_ids ) {
			Import_Store::set_status( $import_id, 'complete' );

			return;
		}

		if ( Queue::is_available() && \BulkListImport\AI\Recognition_Gate::is_available() ) {
			Import_Store::set_status( $import_id, 'running' );

			foreach ( $row_ids as $row_id ) {
				Queue::enqueue( $row_id );
			}

			return;
		}

		foreach ( $row_ids as $row_id ) {
			Row_Job::run( $row_id );
		}

		Import_Store::set_status( $import_id, 'complete' );
	}

	/**
	 * Decide what will happen to a row, without writing anything.
	 *
	 * @param array<string, mixed> $row Reviewed row.
	 * @return array<string, mixed> Either action=create with a prepared payload,
	 *                              or action=reject with an outcome and reason.
	 */
	private function plan_row( array $row ): array {
		$flags = (array) ( $row['flags'] ?? array() );

		if ( 'heading' === ( $row['type'] ?? 'product' ) ) {
			return $this->reject( 'skipped', __( 'Heading or column labels — not a product', 'bulk-list-import' ) );
		}

		if ( in_array( 'duplicate', $flags, true ) ) {
			$of = (int) ( $row['duplicate_of'] ?? 0 );

			return $this->reject(
				'skipped',
				$of > 0
					/* translators: %d: line number of the earlier identical row. */
					? sprintf( __( 'Duplicate of row %d', 'bulk-list-import' ), $of )
					: __( 'Duplicate of an earlier row', 'bulk-list-import' )
			);
		}

		if ( empty( $row['selected'] ) ) {
			return $this->reject( 'skipped', __( 'Not selected for import', 'bulk-list-import' ) );
		}

		$name = trim( (string) ( $row['name'] ?? '' ) );

		if ( '' === $name ) {
			return $this->reject( 'failed', __( 'No product name could be read from this line', 'bulk-list-import' ) );
		}

		$price   = $row['price'];
		$notices = array();

		if ( null === $price || '' === $price ) {
			$price     = '';
			$notices[] = __( 'No price found — set it before publishing', 'bulk-list-import' );
		} elseif ( ! is_numeric( $price ) || (float) $price < 0 ) {
			return $this->reject( 'failed', __( 'Price could not be parsed', 'bulk-list-import' ) );
		} else {
			$price = wc_format_decimal( (string) $price );
		}

		if ( in_array( 'price_uncertain', $flags, true ) ) {
			$notices[] = __( 'Price was guessed from a bare number — verify from source', 'bulk-list-import' );
		}

		return array(
			'action'  => 'create',
			'name'    => $name,
			'price'   => $price,
			'variant' => trim( (string) ( $row['variant'] ?? '' ) ),
			'notices' => $notices,
		);
	}

	/**
	 * Report every row as failed because the SKU block could not be reserved.
	 *
	 * @param array<int, array<string, mixed>> $rows Input rows.
	 * @param \Throwable                       $e    What went wrong.
	 * @return array<int, array<string, mixed>>
	 */
	private function fail_all( array $rows, \Throwable $e ): array {
		if ( $e instanceof \OverflowException ) {
			$reason = __( 'No free block of SKUs is available — check the sequence for a gap or a very high existing number.', 'bulk-list-import' );
		} elseif ( $e instanceof \RuntimeException ) {
			$reason = __( 'Another import is running — SKU sequence is locked. Try again in a moment.', 'bulk-list-import' );
		} else {
			/* translators: %s: error message. */
			$reason = sprintf( __( 'Could not reserve SKUs: %s', 'bulk-list-import' ), $e->getMessage() );
		}

		$entries = array();

		foreach ( $rows as $row ) {
			$entries[] = $this->entry( $row, 'failed', $reason );
		}

		return $entries;
	}

	/**
	 * Build a rejection plan.
	 *
	 * @param string $outcome Report outcome.
	 * @param string $reason  Specific, human reason.
	 * @return array<string, mixed>
	 */
	private function reject( string $outcome, string $reason ): array {
		return array(
			'action'  => 'reject',
			'outcome' => $outcome,
			'reason'  => $reason,
		);
	}

	/**
	 * Build a report entry.
	 *
	 * @param array<string, mixed> $row     Source row.
	 * @param string               $outcome created|created_verify|skipped|failed|not_generated.
	 * @param string               $reason  Specific, human reason. Never just "Error".
	 * @return array<string, mixed>
	 */
	private function entry( array $row, string $outcome, string $reason = '' ): array {
		return array(
			'line'       => (int) ( $row['line'] ?? 0 ),
			'name'       => (string) ( $row['name'] ?? '' ),
			'variant'    => (string) ( $row['variant'] ?? '' ),
			'raw'        => (string) ( $row['raw'] ?? '' ),
			'sku'        => '',
			'product_id' => 0,
			'outcome'    => $outcome,
			'reason'     => $reason,
			'attempts'   => 0,
			'payload'    => array(),
		);
	}
}
