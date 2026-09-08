<?php
/**
 * Creating the WooCommerce product.
 *
 * Extracted so the synchronous path and the queued path share one implementation.
 * Two creation routines that drifted apart would be a slow, quiet bug: products
 * imported with the queue disabled would gradually stop matching products
 * imported with it on, and nothing would report a difference.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes one draft product.
 */
final class Product_Writer {

	public const REVIEW_TAG = 'needs-review';

	/**
	 * Create a product from a prepared payload.
	 *
	 * @param array<string, mixed> $payload     Prepared row: name, price, variant, line, raw.
	 * @param string               $sku         The SKU reserved for this row.
	 * @param array<string, mixed> $description Validated AI payload, or an empty array.
	 * @return array{outcome: string, reason: string, product_id: int}
	 */
	public function write( array $payload, string $sku, array $description = array() ): array {
		// The reserved block protects against a collision with a concurrent import.
		// It cannot protect against one that predates the batch — a product trashed
		// and restored, or a SKU typed in by hand. Re-check, and fail this row
		// rather than silently shifting the rest of the sequence.
		if ( wc_get_product_id_by_sku( $sku ) > 0 ) {
			return $this->result(
				'failed',
				/* translators: %s: SKU. */
				sprintf( __( 'SKU %s already in use', 'bulk-list-import' ), $sku )
			);
		}

		global $wpdb;

		// Per-row transaction: if the CRUD save fails halfway, this row leaves
		// nothing half-written behind.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, nothing to cache.
		$wpdb->query( 'START TRANSACTION' );

		try {
			$product = new \WC_Product_Simple();
			$product->set_name( (string) $payload['name'] );
			$product->set_sku( $sku );
			$product->set_status( 'draft' );          // Draft is the default, always.
			$product->set_catalog_visibility( 'visible' );

			if ( '' !== (string) $payload['price'] ) {
				$product->set_regular_price( (string) $payload['price'] );
			}

			$variant = trim( (string) ( $payload['variant'] ?? '' ) );

			$this->apply_description( $product, $description );
			$this->apply_attributes( $product, $variant, $description );

			$tag_id = $this->review_tag_id();

			if ( $tag_id > 0 ) {
				$product->set_tag_ids( array( $tag_id ) );
			}

			$product->update_meta_data( '_bli_source_line', (int) ( $payload['line'] ?? 0 ) );
			$product->update_meta_data( '_bli_raw_line', (string) ( $payload['raw'] ?? '' ) );

			if ( '' !== $variant ) {
				$product->update_meta_data( '_bli_variant', $variant );
			}

			// Kept on the product so the uncertainty survives the report being
			// pruned. A spec flagged for verification is still flagged in six weeks.
			$uncertain = (array) ( $description['uncertain_fields'] ?? array() );

			if ( array() !== $uncertain ) {
				$product->update_meta_data( '_bli_uncertain_fields', $uncertain );
			}

			$product_id = (int) $product->save();
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, nothing to cache.
			$wpdb->query( 'ROLLBACK' );

			return $this->result(
				'failed',
				/* translators: %s: error message. */
				sprintf( __( 'Could not save product: %s', 'bulk-list-import' ), $e->getMessage() )
			);
		}

		if ( $product_id <= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, nothing to cache.
			$wpdb->query( 'ROLLBACK' );

			return $this->result( 'failed', __( 'WooCommerce did not return a product ID', 'bulk-list-import' ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, nothing to cache.
		$wpdb->query( 'COMMIT' );

		return $this->result( 'created', '', $product_id );
	}

	/**
	 * Apply generated copy, if there is any.
	 *
	 * This is the write boundary the validator deliberately stops short of.
	 * Response_Validator checks shape and nothing else; wp_kses_post() decides what
	 * HTML is permissible, and it decides it here, once, on the way in.
	 *
	 * @param \WC_Product_Simple   $product     Product being built.
	 * @param array<string, mixed> $description Validated AI payload.
	 */
	private function apply_description( \WC_Product_Simple $product, array $description ): void {
		if ( array() === $description ) {
			return;
		}

		$product->set_description( wp_kses_post( (string) ( $description['long_description'] ?? '' ) ) );
		$product->set_short_description( wp_kses_post( (string) ( $description['short_description'] ?? '' ) ) );

		$slug = sanitize_title( (string) ( $description['slug'] ?? '' ) );

		if ( '' !== $slug ) {
			$product->set_slug( $slug );
		}
	}

	/**
	 * Build the product's attributes.
	 *
	 * The parsed variant always leads, because it came from the user's own list
	 * rather than from a model. Generated attributes follow it.
	 *
	 * @param \WC_Product_Simple   $product     Product being built.
	 * @param string               $variant     Parsed variant/spec.
	 * @param array<string, mixed> $description Validated AI payload.
	 */
	private function apply_attributes( \WC_Product_Simple $product, string $variant, array $description ): void {
		$attributes = array();
		$position   = 0;

		if ( '' !== $variant ) {
			/**
			 * Filters the label shown for the parsed variant/spec attribute.
			 *
			 * @param string $label Attribute label.
			 */
			$label = (string) apply_filters( 'bli_variant_attribute_label', __( 'Variant', 'bulk-list-import' ) );

			$attributes[] = $this->attribute( $label, $variant, $position++ );
		}

		foreach ( (array) ( $description['attributes'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['label'], $row['value'] ) ) {
				continue;
			}

			$attributes[] = $this->attribute(
				sanitize_text_field( (string) $row['label'] ),
				sanitize_text_field( (string) $row['value'] ),
				$position++
			);
		}

		if ( array() !== $attributes ) {
			$product->set_attributes( $attributes );
		}
	}

	/**
	 * One custom product attribute.
	 *
	 * @param string $label    Attribute label.
	 * @param string $value    Attribute value.
	 * @param int    $position Display order.
	 */
	private function attribute( string $label, string $value, int $position ): \WC_Product_Attribute {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( $label );
		$attribute->set_options( array( $value ) );
		$attribute->set_position( $position );
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
	 * Shape a result.
	 *
	 * @param string $outcome    Outcome slug.
	 * @param string $reason     Human reason.
	 * @param int    $product_id Created product id, if any.
	 * @return array{outcome: string, reason: string, product_id: int}
	 */
	private function result( string $outcome, string $reason = '', int $product_id = 0 ): array {
		return array(
			'outcome'    => $outcome,
			'reason'     => $reason,
			'product_id' => $product_id,
		);
	}
}
