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
	 * Every input row produces exactly one report entry. A row that vanishes
	 * silently is the worst outcome in this plugin — the user believes they
	 * imported 20 products when they imported 16.
	 *
	 * @param array<int, array<string, mixed>> $rows     Reviewed rows from the preview table.
	 * @param string                           $prefix   Optional SKU prefix override.
	 * @return array<string, mixed> The persisted report.
	 */
	public function import( array $rows, string $prefix = '' ): array {
		$entries = array();

		$sku = SKU_Generator::detect( $prefix );

		// One named lock for the whole batch. This is what actually stops two
		// concurrent imports claiming the same number; the per-row transaction
		// below only guards a single product's own writes.
		if ( ! $sku->lock() ) {
			foreach ( $rows as $row ) {
				$entries[] = $this->entry(
					$row,
					'failed',
					__( 'Another import is running — SKU sequence is locked. Try again in a moment.', 'bulk-list-import' )
				);
			}

			return Import_Report::save( $entries );
		}

		try {
			foreach ( $rows as $row ) {
				$entries[] = $this->import_row( $row, $sku );
			}
		} finally {
			$sku->unlock();
		}

		return Import_Report::save( $entries );
	}

	/**
	 * Import a single row.
	 *
	 * @param array<string, mixed> $row Reviewed row.
	 * @param SKU_Generator        $sku Shared generator, held under lock by the caller.
	 * @return array<string, mixed> Report entry.
	 */
	private function import_row( array $row, SKU_Generator $sku ): array {
		$flags = (array) ( $row['flags'] ?? array() );

		if ( 'heading' === ( $row['type'] ?? 'product' ) ) {
			return $this->entry( $row, 'skipped', __( 'Heading or column labels — not a product', 'bulk-list-import' ) );
		}

		if ( in_array( 'duplicate', $flags, true ) ) {
			$of = (int) ( $row['duplicate_of'] ?? 0 );
			return $this->entry(
				$row,
				'skipped',
				$of > 0
					/* translators: %d: line number of the earlier identical row. */
					? sprintf( __( 'Duplicate of row %d', 'bulk-list-import' ), $of )
					: __( 'Duplicate of an earlier row', 'bulk-list-import' )
			);
		}

		if ( empty( $row['selected'] ) ) {
			return $this->entry( $row, 'skipped', __( 'Not selected for import', 'bulk-list-import' ) );
		}

		$name = trim( (string) ( $row['name'] ?? '' ) );

		if ( '' === $name ) {
			return $this->entry( $row, 'failed', __( 'No product name could be read from this line', 'bulk-list-import' ) );
		}

		$price   = $row['price'];
		$notices = array();

		if ( null === $price || '' === $price ) {
			$price     = '';
			$notices[] = __( 'No price found — set it before publishing', 'bulk-list-import' );
		} elseif ( ! is_numeric( $price ) || (float) $price < 0 ) {
			return $this->entry( $row, 'failed', __( 'Price could not be parsed', 'bulk-list-import' ) );
		} else {
			$price = wc_format_decimal( (string) $price );
		}

		if ( in_array( 'price_uncertain', $flags, true ) ) {
			$notices[] = __( 'Price was guessed from a bare number — verify from source', 'bulk-list-import' );
		}

		$assigned = $sku->next();

		if ( wc_get_product_id_by_sku( $assigned ) > 0 ) {
			/* translators: %s: SKU. */
			return $this->entry( $row, 'failed', sprintf( __( 'SKU %s already in use', 'bulk-list-import' ), $assigned ) );
		}

		global $wpdb;

		// Per-row transaction: if the CRUD save fails halfway, this row leaves
		// nothing half-written behind. Deliberately scoped to one product so a
		// long batch never holds a long transaction open.
		$wpdb->query( 'START TRANSACTION' );

		try {
			$product = new \WC_Product_Simple();
			$product->set_name( $name );
			$product->set_sku( $assigned );
			$product->set_status( 'draft' );          // Draft is the default, always.
			$product->set_catalog_visibility( 'visible' );

			if ( '' !== $price ) {
				$product->set_regular_price( (string) $price );
			}

			$variant = trim( (string) ( $row['variant'] ?? '' ) );
			if ( '' !== $variant ) {
				$product->set_attributes( array( $this->variant_attribute( $variant ) ) );
			}

			$tag_id = $this->review_tag_id();
			if ( $tag_id > 0 ) {
				$product->set_tag_ids( array( $tag_id ) );
			}

			$product->update_meta_data( '_bli_source_line', (int) ( $row['line'] ?? 0 ) );
			$product->update_meta_data( '_bli_raw_line', (string) ( $row['raw'] ?? '' ) );
			if ( '' !== $variant ) {
				$product->update_meta_data( '_bli_variant', $variant );
			}

			$product_id = $product->save();

			if ( ! $product_id ) {
				throw new \RuntimeException( 'WC_Product_Simple::save() returned no ID' );
			}

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );

			return $this->entry(
				$row,
				'failed',
				/* translators: %s: error message. */
				sprintf( __( 'Could not save product: %s', 'bulk-list-import' ), $e->getMessage() )
			);
		}

		$entry               = $this->entry( $row, array() === $notices ? 'created' : 'created_verify', implode( '; ', $notices ) );
		$entry['sku']        = $assigned;
		$entry['product_id'] = (int) $product_id;
		$entry['edit_url']   = get_edit_post_link( (int) $product_id, 'raw' );

		return $entry;
	}

	/**
	 * Build the generic variant/spec attribute.
	 *
	 * Called "variant", never "size" — the same field holds 70cl, 256GB, UK 9
	 * and "50kg bag".
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
