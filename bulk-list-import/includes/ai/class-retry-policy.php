<?php
/**
 * When to retry a provider call, and with which lever.
 *
 * Pure decision logic, free of WordPress, so the table below is covered by
 * fixtures rather than discovered in production.
 *
 * There are two retry loops in this plugin and they do different jobs. This one
 * is the *inner* loop: one extra attempt inside a single request, for failures a
 * second try can plausibly fix. Action Scheduler is the *outer* loop, and it is
 * what handles real outages — it survives the PHP time limit, which an inner
 * loop cannot. Anything that would need a third attempt belongs to the outer
 * loop, not here.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BLI_STANDALONE' ) ) {
	exit;
}

/**
 * Decides whether a failed call is worth another attempt, and what to change.
 */
final class Retry_Policy {

	/**
	 * Attempts inside a single request, including the first. Two, because a third
	 * cannot fit inside a default 30s max_execution_time alongside the work of
	 * recording the outcome — and a job that dies mid-write is the failure the
	 * queue exists to prevent.
	 */
	public const MAX_ATTEMPTS = 2;

	/**
	 * Seconds allowed per HTTP attempt.
	 */
	public const ATTEMPT_TIMEOUT = 20;

	/**
	 * Seconds held back from the budget so the outcome can always be recorded.
	 * Without this the call consumes the whole limit and PHP is killed before the
	 * report row is written — leaving a product half-created and no record of it.
	 */
	public const RESERVE = 5;

	/**
	 * Assumed budget when max_execution_time is 0 (unlimited, typically CLI).
	 * Unlimited PHP does not mean unlimited request: an nginx or proxy timeout
	 * around 60s usually applies regardless.
	 */
	public const ASSUMED_BUDGET = 60;

	/**
	 * Attempts in the outer loop, across separate Action Scheduler runs.
	 *
	 * Action Scheduler does **not** reschedule a failed action by itself — when a
	 * job throws, it is marked failed and that is the end of it. The outer loop is
	 * something this plugin has to build with as_schedule_single_action(), not
	 * something the queue provides. Until it exists, every "retryable" verdict
	 * below terminates the row permanently.
	 */
	public const MAX_OUTER_ATTEMPTS = 3;

	/**
	 * Default outer-loop backoff, in seconds, by attempt number. Used only when
	 * the provider gives no Retry-After of its own.
	 *
	 * Overridable per instance rather than through a filter read in here: this
	 * class is deliberately free of WordPress, so apply_filters() would break the
	 * standalone suite and a function_exists() guard around it would mean the
	 * tested path is not the shipped path. Queue applies the bli_outer_backoff
	 * filter and passes the result in, which is the same boundary drawn between
	 * Gate_Policy and Recognition_Gate.
	 *
	 * @var int[]
	 */
	public const DEFAULT_BACKOFF = array( 60, 300, 900 );

	/**
	 * Backoff schedule in use.
	 *
	 * @var int[]
	 */
	private array $backoff;

	/**
	 * Build the policy.
	 *
	 * @param array<int, mixed>|null $backoff Seconds by attempt; null for the default.
	 */
	public function __construct( ?array $backoff = null ) {
		$this->backoff = self::sanitise_backoff( $backoff );
	}

	/**
	 * Keep a supplied schedule to positive whole seconds, falling back whole
	 * rather than partially: half a filtered schedule would be worse than none.
	 *
	 * @param array<int, mixed>|null $backoff Candidate schedule.
	 * @return int[]
	 */
	private static function sanitise_backoff( ?array $backoff ): array {
		if ( null === $backoff ) {
			return self::DEFAULT_BACKOFF;
		}

		$clean = array();

		foreach ( $backoff as $seconds ) {
			if ( ( is_int( $seconds ) || is_float( $seconds ) ) && $seconds > 0 ) {
				$clean[] = (int) $seconds;
			}
		}

		return array() === $clean ? self::DEFAULT_BACKOFF : $clean;
	}

	/**
	 * Floor on any delay, so a provider answering "retry after 0" cannot turn the
	 * outer loop into a spin.
	 */
	private const MIN_DELAY = 5;

	/**
	 * Format failures: the JSON contract was never reached because the model did
	 * not follow the output instruction. A second ask with a blunter instruction
	 * usually lands, and costs the same as the first.
	 *
	 * @var string[]
	 */
	private const FORMAT_FAILURES = array(
		'no_json_found',
		'ambiguous_json',
		'invalid_json',
		'not_an_object',
		'recognition_not_list',
		'recognition_missing_name',
		'recognition_known_not_bool',
	);

	/**
	 * Contract failures: the JSON parsed, the shape was wrong. A generic "return
	 * JSON" nudge is useless here — the model already returned JSON. The retry
	 * has to name the specific field it got wrong.
	 *
	 * @var string[]
	 */
	private const CONTRACT_FAILURES = array(
		'missing_field',
		'wrong_type',
		'attributes_not_list',
		'attribute_item_shape',
	);

	/**
	 * Capacity failures: the response was cut off by the output token limit.
	 *
	 * Re-asking identically truncates at the same place, so the only lever that
	 * helps is changing the *request*. This is why truncated_json and
	 * ambiguous_json cannot share a strategy despite both being "bad JSON": one
	 * is a capacity problem, the other an instruction-following problem, and
	 * applying either lever to the other failure is spend with no chance of
	 * success.
	 *
	 * @var string[]
	 */
	private const CAPACITY_FAILURES = array(
		'truncated_json',
	);

	/**
	 * Terminal validator codes. Retrying cannot help, or should not be attempted.
	 *
	 * The one worth explaining is empty_field. A model that returns an empty
	 * long_description is signalling it has nothing to say about this product.
	 * Asking again is asking it to fill a field it just declined to fill, which
	 * is precisely the invention pressure this whole layer exists to remove. The
	 * row belongs in the details panel where the user supplies the facts, not in
	 * a retry loop.
	 *
	 * @var string[]
	 */
	private const TERMINAL_FAILURES = array(
		'empty_field',
		'field_too_long',
		'too_many_attributes',
		'json_too_deep',
		'slug_not_transliterable',
	);

	/**
	 * HTTP statuses worth another attempt.
	 *
	 * @var int[]
	 */
	private const RETRYABLE_STATUSES = array( 408, 425, 500, 502, 503, 504 );

	/**
	 * Decide what to do about a validation failure.
	 *
	 * Applies to both passes. `classified` is deliberately not called `known`:
	 * that word already means "the model recognises this product" in the
	 * recognition pass, and an unclassified code surfacing during description
	 * generation should read as "the policy has no entry for this", not as a
	 * recognition verdict. A caller seeing classified:false should report the row
	 * failed with an unrecognised-validator-code reason and log it.
	 *
	 * @param string $code Stable code from Invalid_Response_Exception.
	 * @return array{retry: bool, lever: string, lane: string, classified: bool}
	 *         lever is one of: none, format, contract, output_limit.
	 */
	public function for_validation_code( string $code ): array {
		if ( in_array( $code, self::CAPACITY_FAILURES, true ) ) {
			return array(
				'retry'      => true,
				'lever'      => 'output_limit',
				'lane'       => 'inner',
				'classified' => true,
			);
		}

		if ( in_array( $code, self::FORMAT_FAILURES, true ) ) {
			return array(
				'retry'      => true,
				'lever'      => 'format',
				'lane'       => 'inner',
				'classified' => true,
			);
		}

		if ( in_array( $code, self::CONTRACT_FAILURES, true ) ) {
			return array(
				'retry'      => true,
				'lever'      => 'contract',
				'lane'       => 'inner',
				'classified' => true,
			);
		}

		// Failing closed is the safe default for both cases below, but they are
		// worth telling apart. A known terminal code is a considered decision; an
		// unknown one means the validator has grown a code this table has never
		// been taught, and the caller can log that rather than let the omission
		// sit unnoticed behind identical behaviour.
		return array(
			'retry'      => false,
			'lever'      => 'none',
			'lane'       => 'none',
			'classified' => in_array( $code, self::TERMINAL_FAILURES, true ),
		);
	}

	/**
	 * Decide what to do about an HTTP status.
	 *
	 * Every HTTP failure that is worth another go belongs to the *outer* lane. The
	 * inner loop exists for prompt-level failures, where a differently worded ask
	 * fixes things instantly; infrastructure failures need time to pass, and time
	 * is the one thing a single PHP request cannot spend.
	 *
	 * @param int $status HTTP status code.
	 * @return array{retry: bool, code: string, lane: string} code is a stable slug
	 *         for the report; lane is outer or none.
	 */
	public function for_http_status( int $status ): array {
		// 429 is not a transient blip and does not belong with the 5xx bucket. A
		// 503 clears in seconds; a rate limit on a free tier is a per-minute or
		// per-day quota, and re-asking inside a 20s window fails identically while
		// burning the one inner attempt. It gets its own code and its own delay,
		// taken from the provider rather than guessed.
		if ( 429 === $status ) {
			return array(
				'retry' => true,
				'code'  => 'rate_limited',
				'lane'  => 'outer',
			);
		}

		if ( in_array( $status, self::RETRYABLE_STATUSES, true ) ) {
			return array(
				'retry' => true,
				'code'  => 'provider_unavailable',
				'lane'  => 'outer',
			);
		}

		switch ( $status ) {
			case 401:
			case 403:
				// Never retried: a rejected key is rejected identically next time,
				// and the fix is in settings, not in any loop.
				return array(
					'retry' => false,
					'code'  => 'auth_failed',
					'lane'  => 'none',
				);

			case 404:
				// Overwhelmingly a wrong or retired model name — and model names are
				// retired often enough that this is a routine outcome, not an edge
				// case. Its own code, so the report points at the setting.
				return array(
					'retry' => false,
					'code'  => 'model_not_found',
					'lane'  => 'none',
				);

			case 400:
			case 422:
				return array(
					'retry' => false,
					'code'  => 'request_rejected',
					'lane'  => 'none',
				);
		}

		return array(
			'retry' => false,
			'code'  => 'provider_error',
			'lane'  => 'none',
		);
	}

	/**
	 * How long to wait before an outer-loop attempt.
	 *
	 * A provider-supplied Retry-After wins over our own schedule: it is the only
	 * party that knows when the quota window rolls over, and guessing shorter just
	 * spends an attempt to be told the same thing again.
	 *
	 * @param int $attempt     1-based outer attempt about to be scheduled.
	 * @param int $retry_after Seconds from a Retry-After header, or 0.
	 * @return int Seconds to wait.
	 */
	public function outer_delay( int $attempt, int $retry_after = 0 ): int {
		if ( $retry_after > 0 ) {
			return max( self::MIN_DELAY, $retry_after );
		}

		// Clamped rather than indexed past the end, so a filtered schedule shorter
		// than MAX_OUTER_ATTEMPTS simply repeats its last delay.
		$index = max( 0, min( $attempt - 1, count( $this->backoff ) - 1 ) );

		return $this->backoff[ $index ];
	}

	/**
	 * Whether the outer loop has attempts left.
	 *
	 * @param int $attempt Attempts already made.
	 */
	public function has_outer_attempts_left( int $attempt ): bool {
		return $attempt < self::MAX_OUTER_ATTEMPTS;
	}

	/**
	 * The time budget for this request, in seconds.
	 *
	 * @param int|null $max_execution_time Override for testing; ini value otherwise.
	 */
	public function budget( ?int $max_execution_time = null ): int {
		$limit = null === $max_execution_time ? (int) ini_get( 'max_execution_time' ) : $max_execution_time;

		if ( $limit <= 0 ) {
			return self::ASSUMED_BUDGET;
		}

		return $limit;
	}

	/**
	 * Timeout to give a single attempt, given the seconds still available.
	 *
	 * @param int $remaining Seconds left in the budget.
	 */
	public function timeout( int $remaining ): int {
		return max( 1, min( self::ATTEMPT_TIMEOUT, $remaining - self::RESERVE ) );
	}

	/**
	 * Whether another attempt fits in the time left.
	 *
	 * When it does not, the caller must fail the row as retryable rather than
	 * start a call it cannot finish. The outer loop then runs it again with a fresh
	 * budget — once the outer loop exists to do so.
	 *
	 * @param int $remaining Seconds left in the budget.
	 */
	public function can_attempt_within( int $remaining ): bool {
		return $remaining >= 1 + self::RESERVE;
	}
}
