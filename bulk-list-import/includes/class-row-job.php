<?php
/**
 * What happens to one row.
 *
 * The unit of work for the queue, and also the synchronous path — the same
 * method runs either way, so an import with Action Scheduler unavailable
 * produces the same products as one without.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

use BulkListImport\AI\Gate_Policy;
use BulkListImport\AI\Invalid_Response_Exception;
use BulkListImport\AI\Provider_Exception;
use BulkListImport\AI\Provider_Registry;
use BulkListImport\AI\Recognition_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates copy for one row and writes its product.
 */
final class Row_Job {

	/**
	 * Process one report row.
	 *
	 * Never throws. An uncaught exception here would be marked failed by Action
	 * Scheduler with a stack trace the user cannot act on, and the report row
	 * would sit at "Waiting" for ever. Every exit path writes an outcome.
	 *
	 * @param int|array<string, mixed> $args Row id, or the argument array Action Scheduler passes.
	 */
	public static function run( $args ): void {
		$row_id = is_array( $args ) ? (int) ( $args['row_id'] ?? 0 ) : (int) $args;

		if ( $row_id <= 0 ) {
			return;
		}

		$row = Import_Store::get_row( $row_id );

		if ( null === $row || 'pending' !== (string) $row['outcome'] ) {
			// Already resolved. A duplicate job is not an error — Action Scheduler
			// can run one twice after a timeout — but it must not create a second
			// product against the same reserved SKU.
			return;
		}

		$payload  = json_decode( (string) $row['payload'], true );
		$payload  = is_array( $payload ) ? $payload : array();
		$attempts = (int) $row['attempts'] + 1;

		Import_Store::update_row( $row_id, array( 'attempts' => $attempts ) );

		try {
			self::process( $row_id, $row, $payload, $attempts );
		} catch ( \Throwable $e ) {
			Import_Store::update_row(
				$row_id,
				array(
					'outcome' => 'failed',
					'reason'  => sprintf(
						/* translators: %s: error message. */
						__( 'Unexpected error: %s', 'bulk-list-import' ),
						$e->getMessage()
					),
				)
			);
		}

		self::maybe_complete( (int) $row['import_id'] );
	}

	/**
	 * The work itself.
	 *
	 * @param int                  $row_id   Report row id.
	 * @param array<string, mixed> $row      Row record.
	 * @param array<string, mixed> $payload  Decoded payload.
	 * @param int                  $attempts Attempts including this one.
	 */
	private static function process( int $row_id, array $row, array $payload, int $attempts ): void {
		$sku         = (string) $row['sku'];
		$description = array();
		$notices     = (array) ( $payload['notices'] ?? array() );

		if ( self::wants_generation( $payload ) ) {
			try {
				$description = self::generate( $payload );
			} catch ( Blocked_Row_Exception $e ) {
				// The gate caught it late — this row was never checked at preview
				// time because it fell past the cap. Creating it with no description
				// would be the honest outcome, but it would also mean a product the
				// model does not know slipping in unreviewed, so it stops here.
				Import_Store::update_row(
					$row_id,
					array(
						'outcome' => 'not_generated',
						'reason'  => __( 'Product not recognised — details required.', 'bulk-list-import' ),
					)
				);

				return;
			} catch ( Provider_Exception $e ) {
				if ( $e->is_retryable() ) {
					$result = Queue::retry( $row_id, $attempts, self::reason_for( $e->code_slug() ), $e->retry_after() );

					Import_Store::update_row( $row_id, $result );

					return;
				}

				// Not retryable, but the product itself is still worth creating —
				// without copy. A row that fails outright because the AI was
				// misconfigured would lose the user work they had already reviewed.
				$notices[] = self::reason_for( $e->code_slug() );
			} catch ( Invalid_Response_Exception $e ) {
				$notices[] = sprintf(
					/* translators: %s: validation failure code. */
					__( 'Description not generated (%s)', 'bulk-list-import' ),
					$e->code_slug()
				);
			}
		}

		$written = ( new Product_Writer() )->write( $payload, $sku, $description );

		if ( 'failed' === $written['outcome'] ) {
			Import_Store::update_row( $row_id, $written );

			return;
		}

		$uncertain = (array) ( $description['uncertain_fields'] ?? array() );

		foreach ( $uncertain as $field ) {
			$notices[] = sprintf(
				/* translators: %s: field the model was unsure about. */
				__( '%s uncertain — verify from source', 'bulk-list-import' ),
				(string) $field
			);
		}

		Import_Store::update_row(
			$row_id,
			array(
				'outcome'    => array() === $notices ? 'created' : 'created_verify',
				'reason'     => implode( '; ', $notices ),
				'product_id' => $written['product_id'],
			)
		);
	}

	/**
	 * Whether this row should get generated copy.
	 *
	 * @param array<string, mixed> $payload Decoded payload.
	 */
	private static function wants_generation( array $payload ): bool {
		if ( ! Recognition_Gate::is_available() ) {
			return false;
		}

		// The user chose to write this one themselves.
		return 'own' !== (string) ( $payload['gate_action'] ?? '' );
	}

	/**
	 * Generate copy for one row.
	 *
	 * @param array<string, mixed> $payload Decoded payload.
	 * @return array<string, mixed> Validated description payload.
	 * @throws Blocked_Row_Exception If a late gate check blocks the row.
	 * @throws Provider_Exception If the call fails.
	 * @throws Invalid_Response_Exception If the response cannot be validated.
	 */
	// The sniff counts throw statements in this body and finds one. The other two
	// come out of the provider and the validator, and process() handles all three
	// differently — documenting only the local one would hide that.
	// phpcs:ignore Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
	private static function generate( array $payload ): array {
		$provider = Provider_Registry::make();
		$name     = (string) $payload['name'];

		// A row that fell past the preview's single-call cap has never been judged.
		// It reached here because nobody looked at it, which is not the same as
		// passing, so it is checked now — before anything is written about it.
		if ( Gate_Policy::UNCHECKED === (string) ( $payload['gate_state'] ?? '' ) ) {
			$verdicts = ( new Recognition_Gate( $provider ) )->judge(
				array(
					array(
						'type'       => 'product',
						'name'       => $name,
						'importable' => true,
					),
				),
				1
			);

			if ( Gate_Policy::BLOCKED === ( $verdicts[0]['gate']['state'] ?? '' ) ) {
				throw new Blocked_Row_Exception( 'Row blocked by a late recognition check.' );
			}
		}

		$product = array(
			'name'     => $name,
			'variant'  => (string) ( $payload['variant'] ?? '' ),
			'price'    => (string) ( $payload['price'] ?? '' ),
			'brand'    => (string) ( $payload['details']['brand'] ?? '' ),
			'category' => (string) ( $payload['details']['category'] ?? '' ),
		);

		// Facts the user supplied for a blocked row. They go in the request, never
		// in the rules — the accuracy rule still outranks them, so "generate from my
		// details" cannot become a way to ask for invention.
		$details = array_filter(
			array(
				(string) ( $payload['details']['specs'] ?? '' ),
				(string) ( $payload['details']['notes'] ?? '' ),
			)
		);

		if ( array() !== $details ) {
			$product['variant'] = trim( $product['variant'] . ' ' . implode( '. ', $details ) );
		}

		return $provider->describe( $product, array() );
	}

	/**
	 * Mark the import complete once nothing is left pending.
	 *
	 * @param int $import_id Import id.
	 */
	private static function maybe_complete( int $import_id ): void {
		$totals = Import_Store::totals( $import_id );

		Import_Store::set_status( $import_id, $totals['pending'] > 0 ? 'running' : 'complete' );
	}

	/**
	 * A human reason from a provider failure code.
	 *
	 * @param string $code Machine code.
	 */
	private static function reason_for( string $code ): string {
		switch ( $code ) {
			case 'rate_limited':
				return __( 'Rate limited by the provider', 'bulk-list-import' );
			case 'timeout':
			case 'out_of_time':
				return __( 'The provider did not respond in time', 'bulk-list-import' );
			case 'provider_unavailable':
				return __( 'The provider was unavailable', 'bulk-list-import' );
			case 'auth_failed':
				return __( 'The provider rejected the API key', 'bulk-list-import' );
			case 'model_not_found':
				return __( 'The configured model no longer exists', 'bulk-list-import' );
			case 'key_missing':
				return __( 'No API key configured', 'bulk-list-import' );
		}

		return __( 'The description could not be generated', 'bulk-list-import' );
	}
}
