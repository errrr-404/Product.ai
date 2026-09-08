<?php
/**
 * Pass 1 — the recognition gate.
 *
 * A needs-review tag is a disclaimer, and users learn to ignore it by product
 * #40. A blocked import is a gate, and they cannot. This is the gate.
 *
 * It runs before generation, which is what makes it cheap: nothing is spent
 * writing prose for a product the user is about to reject, and — far more
 * importantly — the model is never put in the position of inventing one.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

use BulkListImport\Ascii_Folder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides which rows the AI genuinely knows.
 */
final class Recognition_Gate {

	/**
	 * Names per provider call. The provider is asked once and only once during a
	 * preview, so this doubles as the synchronous cap.
	 */
	public const NAMES_PER_CALL = 25;

	/**
	 * How long a verdict stays cached. Long enough that re-previewing after
	 * editing one row costs nothing; short enough that a model change is picked
	 * up within a working week.
	 */
	private const CACHE_TTL = WEEK_IN_SECONDS;

	/**
	 * Row is recognised and may be generated.
	 */
	public const READY = 'ready';

	/**
	 * Model does not know the product. Not importable until the user supplies facts.
	 */
	public const BLOCKED = 'blocked';

	/**
	 * Past the synchronous cap, so no verdict yet. Not a judgement — an absence
	 * of one, and it must never be presented as either of the other two.
	 */
	public const UNCHECKED = 'unchecked';

	/**
	 * Gate could not run at all: no key, provider down, quota exhausted.
	 */
	public const UNAVAILABLE = 'unavailable';

	/**
	 * Provider to ask.
	 *
	 * @var Description_Provider
	 */
	private Description_Provider $provider;

	/**
	 * Build the gate.
	 *
	 * @param Description_Provider $provider Provider to ask.
	 */
	public function __construct( Description_Provider $provider ) {
		$this->provider = $provider;
	}

	/**
	 * Judge a set of parsed rows.
	 *
	 * Only importable product rows are asked about — headings and duplicates are
	 * not products, and spending tokens on them would be spending them twice over.
	 *
	 * Never throws. A gate that cannot run must not take the preview down with it:
	 * the rows come back UNAVAILABLE with a reason, and the user can still import
	 * without AI.
	 *
	 * @param array<int, array<string, mixed>> $rows  Parsed rows from the Parser.
	 * @param int                              $limit How many rows to judge; the rest are UNCHECKED.
	 * @return array<int, array<string, mixed>> Rows with a `gate` key added.
	 */
	public function judge( array $rows, int $limit = self::NAMES_PER_CALL ): array {
		$candidates = array();

		foreach ( $rows as $index => $row ) {
			if ( 'product' === ( $row['type'] ?? '' ) && ! empty( $row['importable'] ) && '' !== trim( (string) $row['name'] ) ) {
				$candidates[ $index ] = trim( (string) $row['name'] );
			}
		}

		// Every candidate starts UNCHECKED, set before any call so a failure
		// part-way through still leaves each one with an explicit state rather than
		// an absent key that a reader might mistake for a pass.
		//
		// Non-candidates — headings, duplicates, rows with no name — get no gate key
		// at all. They were never asked about, and labelling them "ready" would be
		// recording a verdict nobody reached.
		foreach ( array_keys( $candidates ) as $index ) {
			$rows[ $index ]['gate'] = $this->verdict(
				self::UNCHECKED,
				__( 'Not checked — beyond this preview’s limit.', 'bulk-list-import' )
			);
		}

		if ( array() === $candidates ) {
			return $rows;
		}

		// Cached verdicts first. Re-previewing after fixing one row is the common
		// case, and re-asking about nineteen unchanged names would be pure spend.
		$uncached = array();

		foreach ( $candidates as $index => $name ) {
			$hit = $this->cached( $name );

			if ( null !== $hit ) {
				$rows[ $index ]['gate'] = $hit;
				continue;
			}

			$uncached[ $index ] = $name;
		}

		if ( array() === $uncached ) {
			return $rows;
		}

		// One call. Beyond it, rows stay UNCHECKED rather than blocking the page:
		// a preview that takes a minute reads as a hang, and the user resubmits.
		$batch = array_slice( $uncached, 0, max( 1, $limit ), true );

		try {
			$verdicts = $this->provider->recognise( array_values( $batch ) );
		} catch ( Provider_Exception | Invalid_Response_Exception $e ) {
			$reason = $this->reason_for( $e );

			foreach ( $batch as $index => $name ) {
				$rows[ $index ]['gate'] = $this->verdict( self::UNAVAILABLE, $reason );
			}

			return $rows;
		}

		// Position mapping is safe only because Response_Validator::validate_recognition()
		// guarantees one record per requested name, in the order requested — it
		// indexes the provider's reply by folded name and throws if any name is
		// missing. Without that guarantee this loop would be free to hand one
		// product's judgement to another, which is the worst thing this file could
		// do: it would mark a product the model does not know as recognised.
		$by_index = array_keys( $batch );

		foreach ( $verdicts as $position => $verdict ) {
			if ( ! isset( $by_index[ $position ] ) ) {
				continue;
			}

			$index  = $by_index[ $position ];
			$known  = ! empty( $verdict['known'] );
			$fields = (array) ( $verdict['uncertain_fields'] ?? array() );

			$gate = $this->verdict(
				$known ? self::READY : self::BLOCKED,
				$known ? '' : __( 'Product not recognised — details required.', 'bulk-list-import' ),
				(float) ( $verdict['confidence'] ?? 0.0 ),
				$fields
			);

			$rows[ $index ]['gate'] = $gate;

			$this->remember( $batch[ $index ], $gate );
		}

		return $rows;
	}

	/**
	 * Whether the gate can run at all.
	 */
	public static function is_available(): bool {
		if ( ! bli_is_pro() ) {
			return false;
		}

		try {
			return ( new API_Key_Store( Provider_Registry::selected_id() ) )->has_key();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Build a verdict record.
	 *
	 * @param string             $state      One of the class constants.
	 * @param string             $reason     Human reason, for anything but READY.
	 * @param float              $confidence Model confidence, 0..1.
	 * @param array<int, string> $uncertain  Fields the model flagged.
	 * @return array<string, mixed>
	 */
	private function verdict( string $state, string $reason = '', float $confidence = 1.0, array $uncertain = array() ): array {
		return array(
			'state'            => $state,
			'reason'           => $reason,
			'confidence'       => $confidence,
			'uncertain_fields' => array_values( array_filter( array_map( 'strval', $uncertain ) ) ),
		);
	}

	/**
	 * A human reason for a gate failure, from a machine code.
	 *
	 * The Import Report's rule applies here too: never a bare "Error". The reason
	 * has to tell the user which lever to reach for.
	 *
	 * @param \Throwable $e What went wrong.
	 */
	private function reason_for( \Throwable $e ): string {
		$code = $e instanceof Provider_Exception || $e instanceof Invalid_Response_Exception
			? $e->code_slug()
			: '';

		switch ( $code ) {
			case 'key_missing':
				return __( 'No API key configured — add one in settings.', 'bulk-list-import' );
			case 'auth_failed':
				return __( 'The provider rejected the API key.', 'bulk-list-import' );
			case 'model_not_found':
				return __( 'The configured model no longer exists — pick another in settings.', 'bulk-list-import' );
			case 'rate_limited':
				return __( 'Rate limited by the provider — try again shortly.', 'bulk-list-import' );
			case 'timeout':
			case 'out_of_time':
			case 'provider_unavailable':
				return __( 'The provider did not respond in time.', 'bulk-list-import' );
			case 'secret_undecryptable':
				return __( 'The stored API key could not be read — enter it again in settings.', 'bulk-list-import' );
		}

		return __( 'The recognition check could not be completed.', 'bulk-list-import' );
	}

	/**
	 * Cache key for a product name.
	 *
	 * Keyed on provider, model and folded name, so switching model re-asks rather
	 * than serving a judgement a different model never made.
	 *
	 * @param string $name Product name.
	 */
	private function cache_key( string $name ): string {
		$normalised = strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', Ascii_Folder::fold( $name ) ) );

		return 'bli_gate_' . md5(
			$this->provider->id() . '|' . (string) get_option( 'bli_ai_model', '' ) . '|' . $normalised
		);
	}

	/**
	 * A cached verdict, or null.
	 *
	 * @param string $name Product name.
	 * @return array<string, mixed>|null
	 */
	private function cached( string $name ): ?array {
		$hit = get_transient( $this->cache_key( $name ) );

		return is_array( $hit ) && isset( $hit['state'] ) ? $hit : null;
	}

	/**
	 * Cache a verdict.
	 *
	 * Only real judgements are cached. Caching an UNAVAILABLE would turn a
	 * five-minute outage into a week of rows the gate refuses to look at.
	 *
	 * @param string               $name    Product name.
	 * @param array<string, mixed> $verdict Verdict record.
	 */
	private function remember( string $name, array $verdict ): void {
		if ( ! in_array( $verdict['state'], array( self::READY, self::BLOCKED ), true ) ) {
			return;
		}

		set_transient( $this->cache_key( $name ), $verdict, self::CACHE_TTL );
	}
}
