<?php
/**
 * Thrown when a late recognition check blocks a row.
 *
 * Distinct from a provider failure: the call succeeded, and the answer was that
 * the model does not know this product. Retrying would ask the same question and
 * get the same answer, so the row goes to the report as not_generated with the
 * same reason a blocked preview row gets.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A row the gate blocked after the preview.
 */
class Blocked_Row_Exception extends \RuntimeException {
}
