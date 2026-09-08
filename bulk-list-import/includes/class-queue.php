<?php
/**
 * Action Scheduler, and the retry loop it does not provide.
 *
 * Generation is not slow — three to eight seconds a description — but PHP
 * requests are short. Twenty products at five seconds is a hundred, against a
 * default max_execution_time of thirty and a proxy timeout around sixty. A
 * single-request import dies partway through, and it dies mid-write: some
 * products created, no record of where it stopped, no way to resume. The user
 * re-runs it and gets duplicates.
 *
 * One row per job keeps every run well inside the limit, survives the tab
 * closing, and makes a failure at product #14 a single retryable job rather than
 * wreckage.
 *
 * **Action Scheduler does not retry failed actions.** A throwing action is marked
 * failed and that is the end of it — there is no built-in reschedule. The outer
 * loop below is therefore something this plugin builds, not something the queue
 * hands over.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

use BulkListImport\AI\Retry_Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and reschedules per-row jobs.
 */
final class Queue {

	/**
	 * Action hook one row is processed under.
	 */
	public const HOOK = 'bli_process_row';

	/**
	 * Action Scheduler group, so the plugin's jobs are identifiable in the admin
	 * and cancellable together.
	 */
	public const GROUP = 'bulk-list-import';

	/**
	 * Whether Action Scheduler is present.
	 *
	 * It ships with WooCommerce, which is a hard dependency, so this should always
	 * be true. It is checked anyway: a site with a broken or very old WooCommerce
	 * should fall back to a synchronous import rather than silently queue work
	 * nothing will ever run.
	 */
	public static function is_available(): bool {
		return function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Register the job handler.
	 */
	public static function register(): void {
		add_action( self::HOOK, array( Row_Job::class, 'run' ), 10, 1 );
	}

	/**
	 * Schedule one row.
	 *
	 * @param int $row_id Report row id.
	 * @param int $delay  Seconds to wait.
	 */
	public static function enqueue( int $row_id, int $delay = 0 ): void {
		if ( ! self::is_available() ) {
			return;
		}

		as_schedule_single_action(
			time() + max( 0, $delay ),
			self::HOOK,
			array( 'row_id' => $row_id ),
			self::GROUP
		);
	}

	/**
	 * Reschedule a row that failed for a reason worth another attempt.
	 *
	 * Returns the reason to record. A row waiting on a retry stays "pending" and
	 * says when it will run again, because "failed once, will retry" and "gave up
	 * after three" are different states and the report must not blur them.
	 *
	 * @param int    $row_id      Report row id.
	 * @param int    $attempts    Attempts already made, including the one that just failed.
	 * @param string $reason      Human reason for the failure.
	 * @param int    $retry_after Seconds the provider asked for, or 0.
	 * @return array{outcome: string, reason: string}
	 */
	public static function retry( int $row_id, int $attempts, string $reason, int $retry_after = 0 ): array {
		$policy = new Retry_Policy();

		if ( ! self::is_available() || ! $policy->has_outer_attempts_left( $attempts ) ) {
			return array(
				'outcome' => 'failed',
				'reason'  => sprintf(
					/* translators: 1: failure reason, 2: number of attempts. */
					__( '%1$s — gave up after %2$d attempts.', 'bulk-list-import' ),
					$reason,
					$attempts
				),
			);
		}

		$delay = $policy->outer_delay( $attempts, $retry_after );

		self::enqueue( $row_id, $delay );

		return array(
			'outcome' => 'pending',
			'reason'  => sprintf(
				/* translators: 1: failure reason, 2: human time difference, 3: attempt number, 4: maximum attempts. */
				__( '%1$s — retrying in %2$s (attempt %3$d of %4$d).', 'bulk-list-import' ),
				$reason,
				human_time_diff( time(), time() + $delay ),
				$attempts + 1,
				Retry_Policy::MAX_OUTER_ATTEMPTS
			),
		);
	}
}
