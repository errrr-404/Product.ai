<?php
/**
 * Thrown when model output fails the JSON contract.
 *
 * Carries a stable machine code rather than a translated string, because the
 * validator is deliberately free of WordPress functions and cannot call __().
 * The WordPress-side caller maps the code to a human reason for the Import
 * Report — where "every non-success states a specific, human reason" is a rule,
 * not a nicety.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BLI_STANDALONE' ) ) {
	exit;
}

/**
 * A response that did not survive validation.
 */
class Invalid_Response_Exception extends \RuntimeException {

	/**
	 * Stable machine code, e.g. "attributes_not_list".
	 *
	 * @var string
	 */
	private string $code_slug;

	/**
	 * The field the failure concerns, where one applies.
	 *
	 * @var string
	 */
	private string $field;

	/**
	 * Build the exception.
	 *
	 * @param string $code_slug Stable machine code.
	 * @param string $message   Developer-facing description. Not shown to users.
	 * @param string $field     Field the failure concerns, if any.
	 */
	public function __construct( string $code_slug, string $message, string $field = '' ) {
		parent::__construct( $message );

		$this->code_slug = $code_slug;
		$this->field     = $field;
	}

	/**
	 * The stable machine code.
	 */
	public function code_slug(): string {
		return $this->code_slug;
	}

	/**
	 * The field the failure concerns, or an empty string.
	 */
	public function field(): string {
		return $this->field;
	}
}
