<?php
/**
 * Continuous SKU sequencing.
 *
 * The rule that matters: the next SKU comes from MAX(existing sku), never from
 * COUNT(products). A store with 57 products whose highest SKU is GE-0058 must
 * get GE-0059 — counting would hand out GE-0058 again and collide.
 *
 * MAX is computed in PHP after a REGEXP narrowing query, never in SQL: sorted as
 * text, "GE-9" beats "GE-0058".
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects the existing SKU sequence and reserves blocks of numbers from it.
 */
class SKU_Generator {

	private const LOCK_NAME       = 'bli_sku_sequence';
	private const LOCK_TIMEOUT    = 10;
	private const OPTION_LOCK     = 'bli_sku_lock';
	private const OPTION_RESERVED = 'bli_sku_reserved';

	/**
	 * Upper bound on numbers skipped while hunting for free SKUs, so a catalogue
	 * densely packed with taken numbers cannot spin forever.
	 */
	private const MAX_PROBES = 10000;

	/**
	 * Alphabetic/symbol prefix, e.g. "GE-".
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Zero-padding width of the numeric part, e.g. 4 for "0059".
	 *
	 * @var int
	 */
	private int $pad;

	/**
	 * Highest number known to be spoken for — in the catalogue or reserved.
	 *
	 * @var int
	 */
	private int $highest;

	/**
	 * Build a generator positioned at a known point in the sequence.
	 *
	 * @param string $prefix  Prefix, e.g. "GE-".
	 * @param int    $pad     Zero-padding width.
	 * @param int    $highest Highest number already spoken for.
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
		$default_prefix = '' !== $forced_prefix
			? $forced_prefix
			: (string) get_option( 'bli_sku_prefix', '' );

		$groups = self::scan_catalogue();

		// An explicit prefix wins even if the store has never used it.
		if ( '' !== $default_prefix ) {
			$group = $groups[ $default_prefix ] ?? array(
				'highest' => 0,
				'pad'     => 4,
			);

			return new self(
				$default_prefix,
				(int) $group['pad'],
				max( (int) $group['highest'], self::reserved_high_water( $default_prefix ) )
			);
		}

		if ( array() === $groups ) {
			return new self( '', 4, self::reserved_high_water( '' ) );
		}

		// Otherwise follow the sequence the store actually uses most.
		uasort(
			$groups,
			static fn( array $a, array $b ) => array( $b['count'], $b['highest'] ) <=> array( $a['count'], $a['highest'] )
		);

		$prefix = (string) array_key_first( $groups );
		$group  = $groups[ $prefix ];

		return new self(
			$prefix,
			(int) $group['pad'],
			max( (int) $group['highest'], self::reserved_high_water( $prefix ) )
		);
	}

	/**
	 * Group every sequence-shaped SKU in the catalogue by its prefix.
	 *
	 * @return array<string, array{count: int, highest: int, pad: int}>
	 */
	private static function scan_catalogue(): array {
		global $wpdb;

		// Narrow to SKUs shaped like "<letters><separator><digits>" or plain digits.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

		return $groups;
	}

	/**
	 * The SKU that would be issued $offset places from now, without consuming it.
	 *
	 * Used by the preview table, which must show the sequence before anything is
	 * written. It is a forecast, not a promise: the numbers actually written are
	 * the ones returned by reserve().
	 *
	 * @param int $offset 0 for the next SKU, 1 for the one after, and so on.
	 */
	public function peek( int $offset = 0 ): string {
		return $this->format( $this->highest + $offset + 1 );
	}

	/**
	 * The prefix in use, for display.
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Claim a block of SKUs for one import, and release the lock immediately.
	 *
	 * Reservation is deliberately decoupled from product creation. Holding the
	 * lock across creation was fine while imports were synchronous and finished
	 * in well under a second, but Phase 4 puts AI generation behind Action
	 * Scheduler and a batch will take minutes. A named lock held that long blocks
	 * every other import on the site, and GET_LOCK behaves badly under connection
	 * pooling and on some managed MySQL hosts. Here the lock is held for the
	 * duration of one arithmetic loop — milliseconds — and creation runs unlocked
	 * against numbers already spoken for.
	 *
	 * The high-water mark is persisted, because that is what makes the
	 * reservation real: without it a second import starting a millisecond later
	 * would read the same MAX and hand out the same block.
	 *
	 * Numbers are burned if a row later fails to save. That is the trade-off a
	 * reservation model makes — an occasional gap in the sequence in exchange for
	 * never issuing a number twice. Gaps are already normal (trashed products
	 * hold their SKUs); collisions are not.
	 *
	 * @param int $count How many SKUs the caller needs.
	 * @return array<int, string> Exactly $count SKUs, in order.
	 * @throws \RuntimeException If the sequence lock cannot be acquired — another
	 *                           import is mid-reservation, so retrying works.
	 * @throws \OverflowException If no free block exists below the probe cap — the
	 *                            sequence needs attention, so retrying will not help.
	 * @throws \Throwable If the reservation transaction fails; rolled back first.
	 */
	public function reserve( int $count ): array {
		if ( $count < 1 ) {
			return array();
		}

		if ( ! self::acquire_lock() ) {
			throw new \RuntimeException( 'Could not acquire the SKU sequence lock.' );
		}

		global $wpdb;

		$skus = array();

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, nothing to cache.
			$wpdb->query( 'START TRANSACTION' );

			// Re-read under the lock. Another import may have reserved a block
			// between detect() and here.
			$groups = self::scan_catalogue();
			$in_db  = (int) ( $groups[ $this->prefix ]['highest'] ?? 0 );
			$number = max( $this->highest, $in_db, self::reserved_high_water( $this->prefix ) );
			$probes = 0;
			$issued = 0;

			while ( $issued < $count ) {
				++$number;
				++$probes;

				if ( $probes > self::MAX_PROBES ) {
					throw new \OverflowException( 'Could not find a free block of SKUs.' );
				}

				$candidate = $this->format( $number );

				// Skip numbers already in use — a trashed product still holds its SKU.
				if ( $this->is_taken( $candidate ) ) {
					continue;
				}

				$skus[] = $candidate;
				++$issued;
			}

			self::store_high_water( $this->prefix, $number );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, nothing to cache.
			$wpdb->query( 'COMMIT' );

			$this->highest = $number;
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control, nothing to cache.
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		} finally {
			self::release_lock();
		}

		return $skus;
	}

	/**
	 * Highest number reserved so far for a prefix.
	 *
	 * Read past any persistent object cache: a stale value here would hand the
	 * same block to two imports.
	 *
	 * @param string $prefix SKU prefix.
	 */
	private static function reserved_high_water( string $prefix ): int {
		wp_cache_delete( self::OPTION_RESERVED, 'options' );

		$reserved = get_option( self::OPTION_RESERVED, array() );

		if ( ! is_array( $reserved ) ) {
			return 0;
		}

		return (int) ( $reserved[ $prefix ] ?? 0 );
	}

	/**
	 * Record the highest number reserved for a prefix.
	 *
	 * @param string $prefix SKU prefix.
	 * @param int    $number Highest number reserved.
	 */
	private static function store_high_water( string $prefix, int $number ): void {
		$reserved = get_option( self::OPTION_RESERVED, array() );

		if ( ! is_array( $reserved ) ) {
			$reserved = array();
		}

		$reserved[ $prefix ] = max( $number, (int) ( $reserved[ $prefix ] ?? 0 ) );

		update_option( self::OPTION_RESERVED, $reserved, false );
	}

	/**
	 * Acquire the cross-request sequence lock.
	 *
	 * Uses the database's own named lock where available (MySQL/MariaDB
	 * GET_LOCK), which releases automatically if the PHP process dies. Falls back
	 * to an option-based lock with a short expiry where GET_LOCK is unavailable.
	 */
	private static function acquire_lock(): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, self::LOCK_TIMEOUT )
		);
		// phpcs:enable

		if ( '1' === (string) $acquired ) {
			return true;
		}

		if ( null === $acquired ) {
			// GET_LOCK unsupported or errored. Fall back to an option-based lock.
			// The window is short now that the lock only spans reservation.
			$held = (int) get_option( self::OPTION_LOCK, 0 );

			if ( $held > 0 && ( time() - $held ) < 60 ) {
				return false;
			}

			update_option( self::OPTION_LOCK, time(), false );

			return true;
		}

		return false;
	}

	/**
	 * Release the sequence lock.
	 */
	private static function release_lock(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
		// phpcs:enable

		delete_option( self::OPTION_LOCK );
	}

	/**
	 * Format a number as a SKU.
	 *
	 * @param int $number Sequence number.
	 */
	private function format( int $number ): string {
		return $this->prefix . str_pad( (string) $number, $this->pad, '0', STR_PAD_LEFT );
	}

	/**
	 * Whether a SKU already exists in the catalogue.
	 *
	 * @param string $sku Candidate SKU.
	 */
	private function is_taken( string $sku ): bool {
		return function_exists( 'wc_get_product_id_by_sku' ) && wc_get_product_id_by_sku( $sku ) > 0;
	}
}
