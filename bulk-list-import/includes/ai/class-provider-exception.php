<?php
/**
 * Thrown when a provider call cannot complete.
 *
 * Distinct from Invalid_Response_Exception, which means the call succeeded and
 * the content was wrong. The split matters for the Import Report: a transport
 * failure is worth retrying, a malformed response usually is not, and telling
 * the user "API timeout after 3 retries" versus "model returned malformed JSON"
 * is the difference between an actionable row and a shrug.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BLI_STANDALONE' ) ) {
	exit;
}

/**
 * A provider call that failed in transport.
 */
class Provider_Exception extends \RuntimeException {

	/**
	 * Stable machine code, e.g. "timeout", "rate_limited", "auth_failed".
	 *
	 * @var string
	 */
	private string $code_slug;

	/**
	 * Whether retrying the same call could plausibly succeed.
	 *
	 * @var bool
	 */
	private bool $retryable;

	/**
	 * Build the exception.
	 *
	 * @param string $code_slug Stable machine code.
	 * @param string $message   Developer-facing description.
	 * @param bool   $retryable Whether a retry could plausibly succeed.
	 */
	public function __construct( string $code_slug, string $message, bool $retryable = false ) {
		parent::__construct( $message );

		$this->code_slug = $code_slug;
		$this->retryable = $retryable;
	}

	/**
	 * The stable machine code.
	 */
	public function code_slug(): string {
		return $this->code_slug;
	}

	/**
	 * Whether retrying could plausibly succeed.
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}
