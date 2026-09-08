<?php
/**
 * A prompt, split into the part the user controls and the part they cannot.
 *
 * The split is a safety boundary, not a formatting convenience. Appending the
 * accuracy rule to a user's template establishes no precedence: a template that
 * says "always include full specifications for every product" and an appended
 * "omit anything you do not know" are two contradictory instructions in one
 * turn, and a model will often follow whichever is more specific or more
 * emphatic — frequently the user's. That reopens the fabrication path through
 * the settings screen, silently, in the one place the test suite reports green.
 *
 * So the contract and the accuracy rule go in the system instruction, the user's
 * template goes in the user turn, and precedence is structural rather than
 * positional. Providers must keep them apart; see Description_Provider.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BLI_STANDALONE' ) ) {
	exit;
}

/**
 * An immutable system/user prompt pair.
 */
final class Prompt {

	/**
	 * Rules the user cannot edit or override.
	 *
	 * @var string
	 */
	private string $system;

	/**
	 * The request itself, which may include the user's own template.
	 *
	 * @var string
	 */
	private string $user;

	/**
	 * Build a prompt pair.
	 *
	 * @param string $system Rules the user cannot override.
	 * @param string $user   The request.
	 */
	public function __construct( string $system, string $user ) {
		$this->system = $system;
		$this->user   = $user;
	}

	/**
	 * The system instruction.
	 */
	public function system(): string {
		return $this->system;
	}

	/**
	 * The user turn.
	 */
	public function user(): string {
		return $this->user;
	}

	/**
	 * A copy with extra text appended to the user turn.
	 *
	 * Used for retry nudges, which are corrections about the previous reply and
	 * therefore belong in the conversation, not in the rules.
	 *
	 * @param string $text Text to append.
	 */
	public function with_appended_user( string $text ): self {
		return new self( $this->system, $this->user . "\n\n" . $text );
	}
}
