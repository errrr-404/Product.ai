<?php
/**
 * A provider that replays canned responses. Test seam only — never shipped.
 *
 * It exists to prove the interface is implementable without a network, and to
 * let the orchestration around a provider (the gate, the queue, the report) be
 * tested without spending tokens.
 *
 * Note what it does *not* do: it returns raw response strings and runs them
 * through the real Response_Validator, exactly as a live provider does. A fake
 * that returned pre-validated arrays would skip the one component that stands
 * between model output and the database, and the tested path would stop being
 * the shipped path.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BLI_STANDALONE' ) ) {
	exit;
}

/**
 * Replays canned provider responses.
 */
final class Fake_Provider implements Description_Provider {

	/**
	 * Raw response to return from recognise().
	 *
	 * @var string
	 */
	private string $recognition_response;

	/**
	 * Raw response to return from describe().
	 *
	 * @var string
	 */
	private string $description_response;

	/**
	 * Validator, deliberately the real one.
	 *
	 * @var Response_Validator
	 */
	private Response_Validator $validator;

	/**
	 * Build with the raw responses to replay.
	 *
	 * @param string $recognition_response Raw recognition response.
	 * @param string $description_response Raw description response.
	 */
	public function __construct( string $recognition_response = '[]', string $description_response = '{}' ) {
		$this->recognition_response = $recognition_response;
		$this->description_response = $description_response;
		$this->validator            = new Response_Validator();
	}

	/**
	 * Replay the canned recognition response.
	 *
	 * @param array<int, string> $product_names Names to check.
	 * @return array<int, array<string, mixed>>
	 * @throws Invalid_Response_Exception If the canned response fails the contract.
	 */
	public function recognise( array $product_names ): array {
		return $this->validator->validate_recognition( $this->recognition_response, $product_names );
	}

	/**
	 * Replay the canned description response.
	 *
	 * @param array<string, mixed> $product Parsed row.
	 * @param array<string, mixed> $options Prompt settings.
	 * @return array<string, mixed>
	 * @throws Invalid_Response_Exception If the canned response fails the contract.
	 */
	public function describe( array $product, array $options ): array {
		unset( $product, $options );

		return $this->validator->validate_description( $this->description_response );
	}

	/**
	 * Stable machine identifier.
	 */
	public function id(): string {
		return 'fake';
	}
}
