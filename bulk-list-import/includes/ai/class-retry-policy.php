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
	private const RETRYABLE_STATUSES = array( 408, 425, 429, 500, 502, 503, 504 );

	/**
	 * Decide what to do about a validation failure.
	 *
	 * @param string $code Stable code from Invalid_Response_Exception.
	 * @return array{retry: bool, lever: string, known: bool} lever is one of: none,
	 *         format, contract, output_limit. known is false when the validator has
	 *         raised a code this table has never heard of.
	 */
	public function for_validation_code( string $code ): array {
		if ( in_array( $code, self::CAPACITY_FAILURES, true ) ) {
			return array(
				'retry' => true,
				'lever' => 'output_limit',
				'known' => true,
			);
		}

		if ( in_array( $code, self::FORMAT_FAILURES, true ) ) {
			return array(
				'retry' => true,
				'lever' => 'format',
				'known' => true,
			);
		}

		if ( in_array( $code, self::CONTRACT_FAILURES, true ) ) {
			return array(
				'retry' => true,
				'lever' => 'contract',
				'known' => true,
			);
		}

		// Failing closed is the safe default for both cases below, but they are
		// worth telling apart. A known terminal code is a considered decision; an
		// unknown one means the validator has grown a code this table has never
		// been taught, and the caller can log that rather than let the omission
		// sit unnoticed behind identical behaviour.
		return array(
			'retry' => false,
			'lever' => 'none',
			'known' => in_array( $code, self::TERMINAL_FAILURES, true ),
		);
	}

	/**
	 * Decide what to do about an HTTP status.
	 *
	 * @param int $status HTTP status code.
	 * @return array{retry: bool, code: string} code is a stable slug for the report.
	 */
	public function for_http_status( int $status ): array {
		if ( in_array( $status, self::RETRYABLE_STATUSES, true ) ) {
			return array(
				'retry' => true,
				'code'  => 429 === $status ? 'rate_limited' : 'provider_unavailable',
			);
		}

		switch ( $status ) {
			case 401:
			case 403:
				// Never retried: a rejected key is rejected identically next time,
				// and the fix is in settings, not in this loop.
				return array(
					'retry' => false,
					'code'  => 'auth_failed',
				);

			case 404:
				// Overwhelmingly a wrong or retired model name. Worth its own code
				// so the report can point at the setting rather than say "failed".
				return array(
					'retry' => false,
					'code'  => 'model_not_found',
				);

			case 400:
			case 422:
				return array(
					'retry' => false,
					'code'  => 'request_rejected',
				);
		}

		return array(
			'retry' => false,
			'code'  => 'provider_error',
		);
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
	 * start a call it cannot finish: Action Scheduler will run it again with a
	 * fresh budget, which is the whole point of having an outer loop.
	 *
	 * @param int $remaining Seconds left in the budget.
	 */
	public function can_attempt_within( int $remaining ): bool {
		return $remaining >= 1 + self::RESERVE;
	}
}
