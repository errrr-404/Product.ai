<?php
/**
 * The recognition gate's decisions, with nothing fetched.
 *
 * Separated from Recognition_Gate along the same line already drawn between
 * Response_Validator and wp_kses_post(): this decides *what verdict a state
 * produces*, and the caller decides *where the state comes from*. Transients,
 * translation and bli_is_pro() stay on the other side of that line.
 *
 * The point is not tidiness. The gate's safety properties — an unavailable check
 * never reading as a pass, a verdict never landing on the wrong product — are
 * exactly the kind that a later refactor breaks without anything failing, and
 * they are only testable if the logic can run without WordPress.
 *
 * Reasons are returned as machine codes, never translated strings, because this
 * class cannot call __(). Recognition_Gate maps them for display, the same way
 * the Import Report maps validator codes.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

use BulkListImport\Ascii_Folder;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BLI_STANDALONE' ) ) {
	exit;
}

/**
 * Pure decision logic for the recognition gate.
 */
final class Gate_Policy {

	/**
	 * Recognised. May be generated.
	 */
	public const READY = 'ready';

	/**
	 * Not recognised. Not importable until the user supplies the facts.
	 */
	public const BLOCKED = 'blocked';

	/**
	 * No verdict was sought — the row fell past the single-call cap.
	 *
	 * This is an absence of judgement, not a judgement. It must never be rendered
	 * as a pass, and 4e re-checks these rows before generating anything for them.
	 */
	public const UNCHECKED = 'unchecked';

	/**
	 * The check could not run: no key, provider down, quota exhausted.
	 *
	 * Distinct from BLOCKED, which is the model saying it does not know the
	 * product, and distinct from READY in every circumstance.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Which rows are worth asking about.
	 *
	 * Headings are not products. Duplicates are already going to be skipped, and
	 * asking about one would be spending the same token twice. A row with no name
	 * has nothing to ask about.
	 *
	 * @param array<int, array<string, mixed>> $rows Parsed rows.
	 * @return array<int, string> Row index => product name, order preserved.
	 */
	public function candidates( array $rows ): array {
		$candidates = array();

		foreach ( $rows as $index => $row ) {
			if ( 'product' !== ( $row['type'] ?? '' ) ) {
				continue;
			}

			if ( empty( $row['importable'] ) ) {
				continue;
			}

			$name = trim( (string) ( $row['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$candidates[ $index ] = $name;
		}

		return $candidates;
	}

	/**
	 * Split candidates into those already answered, those to ask about now, and
	 * those that fall past the cap.
	 *
	 * @param array<int, string>               $candidates Row index => name.
	 * @param array<int, array<string, mixed>> $cached     Row index => cached verdict.
	 * @param int                              $limit      Maximum names in one call.
	 * @return array{resolved: array<int, array<string, mixed>>, batch: array<int, string>, deferred: array<int, string>}
	 */
	public function partition( array $candidates, array $cached, int $limit ): array {
		$limit    = max( 1, $limit );
		$resolved = array();
		$pending  = array();

		foreach ( $candidates as $index => $name ) {
			if ( isset( $cached[ $index ] ) && $this->is_verdict( $cached[ $index ] ) ) {
				$resolved[ $index ] = $cached[ $index ];
				continue;
			}

			$pending[ $index ] = $name;
		}

		return array(
			'resolved' => $resolved,
			'batch'    => array_slice( $pending, 0, $limit, true ),
			'deferred' => array_slice( $pending, $limit, null, true ),
		);
	}

	/**
	 * Match a provider's records to the names we asked about, by name.
	 *
	 * Matching by position would be shorter, and would work, right up until
	 * something upstream returns records in a different order or drops one. The
	 * failure mode there is that product A is told it is known because product B
	 * was — a recognised verdict on a product the model has never heard of, which
	 * is the precise outcome this whole layer exists to prevent. So the invariant
	 * is enforced here, where it is consumed, rather than trusted from the file
	 * that happens to guarantee it today.
	 *
	 * Extra records nobody asked about are ignored: they cannot cause a
	 * misassignment. A missing record can, so it is fatal.
	 *
	 * @param array<int, string>               $batch    Row index => name, as sent.
	 * @param array<int, array<string, mixed>> $verdicts Provider records.
	 * @return array<int, array<string, mixed>> Row index => verdict.
	 * @throws Invalid_Response_Exception If any requested name has no record.
	 */
	public function resolve( array $batch, array $verdicts ): array {
		$by_name = array();

		foreach ( $verdicts as $record ) {
			if ( ! is_array( $record ) || ! isset( $record['name'] ) || ! is_string( $record['name'] ) ) {
				continue;
			}

			$by_name[ $this->name_key( $record['name'] ) ] = $record;
		}

		$out = array();

		foreach ( $batch as $index => $name ) {
			$key = $this->name_key( $name );

			if ( ! isset( $by_name[ $key ] ) ) {
				throw new Invalid_Response_Exception(
					'recognition_missing_name',
					sprintf( 'No recognition record returned for "%s".', $name ),
					$name
				);
			}

			$out[ $index ] = $this->from_record( $by_name[ $key ] );
		}

		return $out;
	}

	/**
	 * Turn one provider record into a verdict.
	 *
	 * Fails closed. Anything that is not literally `known === true` is BLOCKED —
	 * not a missing key, not the string "true", not 1. The validator already
	 * rejects those shapes, so this is a second lock on the same door, and the
	 * asymmetry justifies it: a wrongly blocked row costs the user a panel to fill
	 * in, a wrongly recognised row costs them a fabricated product description.
	 *
	 * @param array<string, mixed> $record Provider record.
	 * @return array<string, mixed>
	 */
	public function from_record( array $record ): array {
		$known = ( $record['known'] ?? null ) === true;

		$confidence = $record['confidence'] ?? 0.0;
		$confidence = is_int( $confidence ) || is_float( $confidence ) ? (float) $confidence : 0.0;

		return $this->verdict(
			$known ? self::READY : self::BLOCKED,
			$known ? '' : 'not_recognised',
			min( 1.0, max( 0.0, $confidence ) ),
			(array) ( $record['uncertain_fields'] ?? array() )
		);
	}

	/**
	 * Build a verdict record.
	 *
	 * @param string            $state       One of the state constants.
	 * @param string            $reason_code Machine code; the caller translates.
	 * @param float             $confidence  Model confidence, 0..1.
	 * @param array<int, mixed> $uncertain   Fields the model flagged.
	 * @return array<string, mixed>
	 */
	public function verdict( string $state, string $reason_code = '', float $confidence = 1.0, array $uncertain = array() ): array {
		return array(
			'state'            => $state,
			'reason_code'      => $reason_code,
			'confidence'       => $confidence,
			'uncertain_fields' => array_values(
				array_filter(
					array_map( 'strval', $uncertain ),
					static fn( string $field ): bool => '' !== trim( $field )
				)
			),
		);
	}

	/**
	 * The verdict for a row nobody got to.
	 */
	public function unchecked(): array {
		return $this->verdict( self::UNCHECKED, 'past_cap', 0.0 );
	}

	/**
	 * The verdict for a check that could not run.
	 *
	 * @param string $code Machine code from the provider or validator failure.
	 */
	public function unavailable( string $code ): array {
		return $this->verdict( self::UNAVAILABLE, '' !== $code ? $code : 'gate_failed', 0.0 );
	}

	/**
	 * Whether a verdict is worth caching.
	 *
	 * Only real judgements are. Caching an UNAVAILABLE would turn a five-minute
	 * outage into a week of rows the gate refuses to look at, and caching an
	 * UNCHECKED would record "nobody asked" as though it were an answer.
	 *
	 * @param array<string, mixed> $verdict Verdict record.
	 */
	public function is_cacheable( array $verdict ): bool {
		return in_array( $verdict['state'] ?? '', array( self::READY, self::BLOCKED ), true );
	}

	/**
	 * Whether a row in this state may be selected for import.
	 *
	 * BLOCKED is the only state that withholds the checkbox, because it is the
	 * only one where the model has actually said it does not know the product.
	 * UNCHECKED and UNAVAILABLE stay selectable — the gate not having run is not
	 * grounds to stop someone importing, and the free tier has no gate at all —
	 * but neither is ever recorded as READY, and both stay visible as themselves.
	 *
	 * @param string $state Verdict state.
	 */
	public function permits_import( string $state ): bool {
		return self::BLOCKED !== $state;
	}

	/**
	 * Merge verdicts onto rows without ever upgrading one.
	 *
	 * A row keeps the weakest verdict it has been given. Nothing here can turn an
	 * UNAVAILABLE or a BLOCKED into a READY: only from_record() mints a READY, and
	 * only from a record that explicitly said so.
	 *
	 * @param array<int, array<string, mixed>> $rows     Parsed rows.
	 * @param array<int, array<string, mixed>> $verdicts Row index => verdict.
	 * @return array<int, array<string, mixed>>
	 */
	public function apply( array $rows, array $verdicts ): array {
		foreach ( $verdicts as $index => $verdict ) {
			if ( ! array_key_exists( $index, $rows ) || ! $this->is_verdict( $verdict ) ) {
				continue;
			}

			$existing = $rows[ $index ]['gate'] ?? null;

			if ( is_array( $existing ) && $this->rank( $existing['state'] ?? '' ) < $this->rank( $verdict['state'] ) ) {
				continue;
			}

			$rows[ $index ]['gate'] = $verdict;
		}

		return $rows;
	}

	/**
	 * Cache key for one product name under one provider and model.
	 *
	 * Model is part of the key so switching model re-asks rather than serving a
	 * judgement a different model never made. The name is folded and stripped so
	 * "Rémy Martin" and "Remy Martin" share one answer.
	 *
	 * @param string $provider Provider id.
	 * @param string $model    Model name.
	 * @param string $name     Product name.
	 */
	public function cache_key( string $provider, string $model, string $name ): string {
		return 'bli_gate_' . md5( $provider . '|' . $model . '|' . $this->name_key( $name ) );
	}

	/**
	 * How trusted a state is. Lower is weaker, and weaker always wins a merge.
	 *
	 * @param string $state Verdict state.
	 */
	private function rank( string $state ): int {
		switch ( $state ) {
			case self::BLOCKED:
				return 0;
			case self::UNAVAILABLE:
				return 1;
			case self::UNCHECKED:
				return 2;
			case self::READY:
				return 3;
		}

		return 0;
	}

	/**
	 * Whether a value looks like a verdict record.
	 *
	 * @param mixed $value Candidate.
	 */
	private function is_verdict( $value ): bool {
		return is_array( $value )
			&& isset( $value['state'] )
			&& in_array( $value['state'], array( self::READY, self::BLOCKED, self::UNCHECKED, self::UNAVAILABLE ), true );
	}

	/**
	 * Normalised form of a product name, for matching and caching.
	 *
	 * @param string $name Product name.
	 */
	private function name_key( string $name ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', Ascii_Folder::fold( $name ) ) );
	}
}
