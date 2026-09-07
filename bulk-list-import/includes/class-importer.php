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

	public const REVIEW_TAG = 'needs-review';

	/**
	 * Import a batch of reviewed rows.
	 *
	 * Runs in three passes:
	 *
	 *   1. Plan every row, deciding which will attempt creation. No writes.
	 *   2. Reserve exactly that many SKUs, under a lock held for milliseconds.
	 *   3. Create products, unlocked, against the pre-assigned numbers.
	 *
	 * Every input row produces exactly one report entry. A row that vanishes
	 * silently is the worst outcome in this plugin — the user believes they
	 * imported 20 products when they imported 16.
	 *
	 * @param array<int, array<string, mixed>> $rows   Reviewed rows from the preview table.
	 * @param string                           $prefix Optional SKU prefix override.
	 * @return array<string, mixed> The persisted report.
	 */
	public function import( array $rows, string $prefix = '' ): array {
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

		// Pass 2 — reserve. Short locked transaction, released immediately.
		$reserved = array();

		if ( $needed > 0 ) {
			try {
				$reserved = SKU_Generator::detect( $prefix )->reserve( $needed );
			} catch ( \Throwable $e ) {
				return Import_Report::save( $this->fail_all( $rows, $e ) );
			}
		}

		// Pass 3 — create, unlocked.
		$entries = array();
		$cursor  = 0;

		foreach ( $rows as $index => $row ) {
			$plan = $plans[ $index ];

			if ( 'create' !== $plan['action'] ) {
				$entries[] = $this->entry( $row, $plan['outcome'], $plan['reason'] );
				continue;
			}

			$entries[] = $this->create_row( $row, $plan, $reserved[ $cursor ] );
			++$cursor;
		}

		return Import_Report::save( $entries );
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
	 * Create one product against its pre-assigned SKU.
	 *
	 * @param array<string, mixed> $row  Source row, for the report entry.
	 * @param array<string, mixed> $plan Prepared payload from plan_row().
	 * @param string               $sku  The SKU reserved for this row.
	 * @return array<string, mixed> Report entry.
	 */
	private function create_row( array $row, array $plan, string $sku ): array {
		// The reserved block protects against a collision with a concurrent
		// import. It cannot protect against one that predates the batch — a
		// product trashed and restored, or a SKU typed in by hand. Re-check, and
		// fail this row rather than silently shifting the rest of the sequence.
		if ( wc_get_product_id_by_sku( $sku ) > 0 ) {
			/* translators: %s: SKU. */
			return $this->entry( $row, 'failed', sprintf( __( 'SKU %s already in use', 'bulk-list-import' ), $sku ) );
		}

		global $wpdb;

		// Per-row transaction: if the CRUD save fails halfway, this row leaves
		// nothing half-written behind.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, nothing to cache.
		$wpdb->query( 'START TRANSACTION' );

		try {
			$product = new \WC_Product_Simple();
			$product->set_name( $plan['name'] );
			$product->set_sku( $sku );
			$product->set_status( 'draft' );          // Draft is the default, always.
			$product->set_catalog_visibility( 'visible' );

			if ( '' !== $plan['price'] ) {
				$product->set_regular_price( (string) $plan['price'] );
			}

			if ( '' !== $plan['variant'] ) {
				$product->set_attributes( array( $this->variant_attribute( $plan['variant'] ) ) );
			}

			$tag_id = $this->review_tag_id();

			if ( $tag_id > 0 ) {
				$product->set_tag_ids( array( $tag_id ) );
			}

			$product->update_meta_data( '_bli_source_line', (int) ( $row['line'] ?? 0 ) );
			$product->update_meta_data( '_bli_raw_line', (string) ( $row['raw'] ?? '' ) );

			if ( '' !== $plan['variant'] ) {
				$product->update_meta_data( '_bli_variant', $plan['variant'] );
			}

			$product_id = (int) $product->save();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, nothing to cache.
			$wpdb->query( 'ROLLBACK' );

			return $this->entry(
				$row,
				'failed',
				/* translators: %s: error message. */
				sprintf( __( 'Could not save product: %s', 'bulk-list-import' ), $e->getMessage() )
			);
		}

		if ( $product_id <= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, nothing to cache.
			$wpdb->query( 'ROLLBACK' );

			return $this->entry( $row, 'failed', __( 'WooCommerce did not return a product ID', 'bulk-list-import' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, nothing to cache.
		$wpdb->query( 'COMMIT' );

		$notices = (array) $plan['notices'];

		$entry               = $this->entry( $row, array() === $notices ? 'created' : 'created_verify', implode( '; ', $notices ) );
		$entry['sku']        = $sku;
		$entry['product_id'] = (int) $product_id;
		$entry['edit_url']   = (string) get_edit_post_link( (int) $product_id, 'raw' );

		return $entry;
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
	 * Build the generic variant/spec attribute.
	 *
	 * Called "variant", never "size" — the same field holds 70cl, 256GB, UK 9
	 * and "50kg bag".
	 *
	 * @param string $value Variant text.
	 */
	private function variant_attribute( string $value ): \WC_Product_Attribute {
		/**
		 * Filters the label shown for the parsed variant/spec attribute.
		 *
		 * @param string $label Attribute label.
		 */
		$label = (string) apply_filters( 'bli_variant_attribute_label', __( 'Variant', 'bulk-list-import' ) );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( $label );
		$attribute->set_options( array( $value ) );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( false );

		return $attribute;
	}

	/**
	 * Term ID of the needs-review product tag, creating it on first use.
	 */
	private function review_tag_id(): int {
		$term = term_exists( self::REVIEW_TAG, 'product_tag' );

		if ( ! $term ) {
			$term = wp_insert_term( self::REVIEW_TAG, 'product_tag' );
		}

		if ( is_wp_error( $term ) || ! isset( $term['term_id'] ) ) {
			return 0;
		}

		return (int) $term['term_id'];
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
			'edit_url'   => '',
			'outcome'    => $outcome,
			'reason'     => $reason,
		);
	}
}
