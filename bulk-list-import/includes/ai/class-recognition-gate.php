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
 * This class only fetches and translates. Every decision it makes lives in
 * Gate_Policy, which is free of WordPress and therefore actually testable.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks the provider which products it genuinely knows.
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
	 * State constants, re-exported so callers need not reach past this class.
	 */
	public const READY       = Gate_Policy::READY;
	public const BLOCKED     = Gate_Policy::BLOCKED;
	public const UNCHECKED   = Gate_Policy::UNCHECKED;
	public const UNAVAILABLE = Gate_Policy::UNAVAILABLE;

	/**
	 * Provider to ask.
	 *
	 * @var Description_Provider
	 */
	private Description_Provider $provider;

	/**
	 * Decision logic.
	 *
	 * @var Gate_Policy
	 */
	private Gate_Policy $policy;

	/**
	 * Build the gate.
	 *
	 * @param Description_Provider $provider Provider to ask.
	 * @param Gate_Policy|null     $policy   Decision logic.
	 */
	public function __construct( Description_Provider $provider, ?Gate_Policy $policy = null ) {
		$this->provider = $provider;
		$this->policy   = $policy ?? new Gate_Policy();
	}

	/**
	 * Judge a set of parsed rows.
	 *
	 * Never throws. A gate that cannot run must not take the preview down with it:
	 * the rows come back UNAVAILABLE with a reason, and the user can still import
	 * without AI.
	 *
	 * @param array<int, array<string, mixed>> $rows  Parsed rows from the Parser.
	 * @param int                              $limit How many rows to judge; the rest stay UNCHECKED.
	 * @return array<int, array<string, mixed>> Rows with a `gate` key added.
	 */
	public function judge( array $rows, int $limit = self::NAMES_PER_CALL ): array {
		$candidates = $this->policy->candidates( $rows );

		if ( array() === $candidates ) {
			return $rows;
		}

		$cached = array();

		foreach ( $candidates as $index => $name ) {
			$hit = get_transient( $this->cache_key( $name ) );

			if ( is_array( $hit ) ) {
				$cached[ $index ] = $hit;
			}
		}

		$split = $this->policy->partition( $candidates, $cached, $limit );

		// Deferred rows are marked before any call, so a failure part-way through
		// still leaves every candidate carrying an explicit state.
		$verdicts = $split['resolved'];

		foreach ( array_keys( $split['deferred'] ) as $index ) {
			$verdicts[ $index ] = $this->policy->unchecked();
		}

		if ( array() !== $split['batch'] ) {
			$verdicts += $this->ask( $split['batch'] );
		}

		return $this->decorate( $this->policy->apply( $rows, $verdicts ) );
	}

	/**
	 * Ask the provider about one batch of names.
	 *
	 * @param array<int, string> $batch Row index => name.
	 * @return array<int, array<string, mixed>> Row index => verdict.
	 */
	private function ask( array $batch ): array {
		try {
			$records  = $this->provider->recognise( array_values( $batch ) );
			$verdicts = $this->policy->resolve( $batch, $records );
		} catch ( Provider_Exception | Invalid_Response_Exception $e ) {
			$unavailable = $this->policy->unavailable( $e->code_slug() );
			$verdicts    = array();

			foreach ( array_keys( $batch ) as $index ) {
				$verdicts[ $index ] = $unavailable;
			}

			return $verdicts;
		}

		foreach ( $verdicts as $index => $verdict ) {
			if ( $this->policy->is_cacheable( $verdict ) ) {
				set_transient( $this->cache_key( $batch[ $index ] ), $verdict, self::CACHE_TTL );
			}
		}

		return $verdicts;
	}

	/**
	 * Add a translated reason to every verdict.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows carrying verdicts.
	 * @return array<int, array<string, mixed>>
	 */
	private function decorate( array $rows ): array {
		foreach ( $rows as $index => $row ) {
			if ( ! isset( $row['gate']['reason_code'] ) ) {
				continue;
			}

			$rows[ $index ]['gate']['reason'] = $this->reason_for( (string) $row['gate']['reason_code'] );
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
	 * A human reason from a machine code.
	 *
	 * The Import Report's rule applies here too: never a bare "Error". The reason
	 * has to tell the user which lever to reach for.
	 *
	 * @param string $code Machine code.
	 */
	private function reason_for( string $code ): string {
		switch ( $code ) {
			case '':
				return '';
			case 'past_cap':
				return __( 'Not checked — beyond this preview’s limit.', 'bulk-list-import' );
			case 'not_recognised':
				return __( 'Product not recognised — details required.', 'bulk-list-import' );
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
			case 'recognition_missing_name':
				return __( 'The provider’s answer did not cover every product — nothing was assumed.', 'bulk-list-import' );
		}

		return __( 'The recognition check could not be completed.', 'bulk-list-import' );
	}

	/**
	 * Cache key for a product name under the current provider and model.
	 *
	 * @param string $name Product name.
	 */
	private function cache_key( string $name ): string {
		return $this->policy->cache_key(
			$this->provider->id(),
			(string) get_option( 'bli_ai_model', '' ),
			$name
		);
	}
}
