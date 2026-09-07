<?php
/**
 * Continuous SKU sequencing.
 *
 * The rule that matters: the next SKU comes from MAX(existing sku), never from
 * COUNT(products). A store with 57 products whose highest SKU is GE-0058 must
 * get GE-0059 — counting would hand out GE-0058 again and collide.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects the existing SKU sequence and issues the next numbers in it.
 */
class SKU_Generator {

	private const LOCK_NAME    = 'bli_sku_sequence';
	private const LOCK_TIMEOUT = 10;
	private const OPTION_LOCK  = 'bli_sku_lock';

	/**
	 * Alphabetic/symbol prefix, e.g. "GE-".
	 */
	private string $prefix;

	/**
	 * Zero-padding width of the numeric part, e.g. 4 for "0059".
	 */
	private int $pad;

	/**
	 * Highest number currently in use.
	 */
	private int $highest;

	/**
	 * How many numbers this instance has issued.
	 */
	private int $issued = 0;

	/**
	 * Whether a database-level lock is currently held.
	 */
	private bool $locked = false;

	/**
	 * @param string $prefix  Prefix, e.g. "GE-".
	 * @param int    $pad     Zero-padding width.
	 * @param int    $highest Highest number already in use.
	 */
	public function __construct( string $prefix, int $pad, int $highest ) {
		$this->prefix  = $prefix;
		$this->pad     = max( 1, $pad );
		$this->highest = max( 0, $highest );
	}

	/**
	 * Inspect the catalogue and build a generator that continues its sequence.
	 *
	 * @param string $forced_prefix Optional prefix override from the import form.
	 */
	public static function detect( string $forced_prefix = '' ): self {
		global $wpdb;

		$default_prefix = '' !== $forced_prefix
			? $forced_prefix
			: (string) get_option( 'bli_sku_prefix', '' );

		// Narrow to SKUs shaped like "<letters><separator><digits>" or plain digits.
		// Doing MAX() in SQL would be wrong: sorted as text, "GE-9" beats "GE-0058".
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$skus = $wpdb->get_col(
			"SELECT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_sku'
			   AND pm.meta_value <> ''
			   AND p.post_type IN ('product', 'product_variation')
			   AND p.post_status NOT IN ('trash', 'auto-draft')
			   AND pm.meta_value REGEXP '^[A-Za-z]{0,12}[-_ ]?[0-9]{1,12}$'"
		);
		// phpcs:enable

		$groups = array();

		foreach ( (array) $skus as $sku ) {
			if ( ! preg_match( '/^([A-Za-z]{0,12}[-_ ]?)(\d{1,12})$/', (string) $sku, $parts ) ) {
				continue;
			}

			$prefix = $parts[1];
			$number = (int) $parts[2];

			if ( ! isset( $groups[ $prefix ] ) ) {
				$groups[ $prefix ] = array(
					'count'   => 0,
					'highest' => 0,
					'pad'     => strlen( $parts[2] ),
				);
			}

			++$groups[ $prefix ]['count'];
			if ( $number >= $groups[ $prefix ]['highest'] ) {
				$groups[ $prefix ]['highest'] = $number;
				$groups[ $prefix ]['pad']     = strlen( $parts[2] );
			}
		}

		// An explicit prefix wins even if the store has never used it.
		if ( '' !== $default_prefix ) {
			$group = $groups[ $default_prefix ] ?? array( 'highest' => 0, 'pad' => 4 );
			return new self( $default_prefix, (int) $group['pad'], (int) $group['highest'] );
		}

		if ( array() === $groups ) {
			return new self( '', 4, 0 );
		}

		// Otherwise follow the sequence the store actually uses most.
		uasort(
			$groups,
			static fn( array $a, array $b ) => array( $b['count'], $b['highest'] ) <=> array( $a['count'], $a['highest'] )
		);

		$prefix = (string) array_key_first( $groups );
		$group  = $groups[ $prefix ];

		return new self( $prefix, (int) $group['pad'], (int) $group['highest'] );
	}

	/**
	 * The SKU that would be issued $offset places from now, without consuming it.
	 *
	 * Used by the preview table, which must show the sequence before anything is written.
	 *
	 * @param int $offset 0 for the next SKU, 1 for the one after, and so on.
	 */
	public function peek( int $offset = 0 ): string {
		return $this->format( $this->highest + $this->issued + $offset + 1 );
	}

	/**
	 * Issue the next free SKU and consume it.
	 *
	 * Skips any number already taken in the database — a store can have gaps and
	 * still have GE-0100 sitting in the trash.
	 */
	public function next(): string {
		do {
			++$this->issued;
			$sku = $this->format( $this->highest + $this->issued );
		} while ( $this->is_taken( $sku ) );

		return $sku;
	}

	/**
	 * The prefix in use, for display.
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Acquire a cross-request lock so two concurrent imports cannot claim the
	 * same number.
	 *
	 * Uses the database's own named lock where available (MySQL/MariaDB
	 * GET_LOCK), which releases automatically if the PHP process dies mid-import.
	 * Falls back to an option-based lock with a short expiry.
	 */
	public function lock(): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, self::LOCK_TIMEOUT )
		);
		// phpcs:enable

		if ( '1' === (string) $acquired ) {
			$this->locked = true;
			return true;
		}

		if ( null === $acquired ) {
			// GET_LOCK unsupported (or errored). Fall back to an option-based lock.
			$held = (int) get_option( self::OPTION_LOCK, 0 );

			if ( $held > 0 && ( time() - $held ) < 300 ) {
				return false;
			}

			update_option( self::OPTION_LOCK, time(), false );
			$this->locked = true;
			return true;
		}

		return false;
	}

	/**
	 * Release the lock. Safe to call when no lock is held.
	 */
	public function unlock(): void {
		global $wpdb;

		if ( ! $this->locked ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
		// phpcs:enable

		delete_option( self::OPTION_LOCK );
		$this->locked = false;
	}

	/**
	 * Format a number as a SKU.
	 */
	private function format( int $number ): string {
		return $this->prefix . str_pad( (string) $number, $this->pad, '0', STR_PAD_LEFT );
	}

	/**
	 * Whether a SKU already exists in the catalogue.
	 */
	private function is_taken( string $sku ): bool {
		return function_exists( 'wc_get_product_id_by_sku' ) && wc_get_product_id_by_sku( $sku ) > 0;
	}
}
