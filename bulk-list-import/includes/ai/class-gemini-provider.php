<?php
/**
 * Gemini implementation of the provider seam.
 *
 * The default because it is the cheapest at this volume with the most generous
 * free tier and the simplest signup — which matters when a target user may not
 * have an international-billing card. Nothing here is Gemini-specific above the
 * transport: swapping in OpenAI or OpenRouter means another class implementing
 * the same interface, not a change to any caller.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to the Google Generative Language API.
 */
final class Gemini_Provider implements Description_Provider {

	/**
	 * Endpoint template. %s is the model name.
	 */
	private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

	/**
	 * Default model.
	 *
	 * Model names are versioned and retired on Google's schedule, not ours, which
	 * is exactly why this is a user setting rather than a constant. A stale name
	 * surfaces as HTTP 404, which Retry_Policy maps to model_not_found so the
	 * report can point at the setting instead of saying "failed".
	 */
	public const DEFAULT_MODEL = 'gemini-2.0-flash';

	/**
	 * Output token ceiling for a first attempt.
	 */
	private const MAX_OUTPUT_TOKENS = 2048;

	/**
	 * Ceiling after a truncation retry. Doubling is the lever for truncated_json.
	 */
	private const MAX_OUTPUT_TOKENS_RETRY = 4096;

	/**
	 * The API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Model name.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Response validator.
	 *
	 * @var Response_Validator
	 */
	private Response_Validator $validator;

	/**
	 * Retry policy.
	 *
	 * @var Retry_Policy
	 */
	private Retry_Policy $policy;

	/**
	 * Prompt builder.
	 *
	 * @var Prompt_Builder
	 */
	private Prompt_Builder $prompts;

	/**
	 * Build the provider.
	 *
	 * @param string                  $api_key   API key.
	 * @param string                  $model     Model name; falls back to DEFAULT_MODEL.
	 * @param Prompt_Builder|null     $prompts   Prompt builder, for injecting user settings.
	 * @param Retry_Policy|null       $policy    Retry policy.
	 * @param Response_Validator|null $validator Response validator.
	 */
	public function __construct(
		string $api_key,
		string $model = '',
		?Prompt_Builder $prompts = null,
		?Retry_Policy $policy = null,
		?Response_Validator $validator = null
	) {
		$this->api_key   = $api_key;
		$this->model     = '' !== trim( $model ) ? trim( $model ) : self::DEFAULT_MODEL;
		$this->prompts   = $prompts ?? new Prompt_Builder();
		$this->policy    = $policy ?? new Retry_Policy();
		$this->validator = $validator ?? new Response_Validator();
	}

	/**
	 * Stable machine identifier.
	 */
	public function id(): string {
		return 'gemini';
	}

	/**
	 * Pass 1 — the recognition gate.
	 *
	 * @param array<int, string> $product_names Names to check.
	 * @return array<int, array<string, mixed>>
	 * @throws Provider_Exception If the call fails.
	 * @throws Invalid_Response_Exception If the response cannot be validated.
	 */
	public function recognise( array $product_names ): array {
		$names = array_values( $product_names );

		if ( array() === $names ) {
			return array();
		}

		return $this->attempt(
			$this->prompts->recognition_prompt( $names ),
			fn( string $raw ): array => $this->validator->validate_recognition( $raw, $names )
		);
	}

	/**
	 * Pass 2 — write the description.
	 *
	 * @param array<string, mixed> $product Parsed row.
	 * @param array<string, mixed> $options Unused; settings arrive via Prompt_Builder.
	 * @return array<string, mixed>
	 * @throws Provider_Exception If the call fails.
	 * @throws Invalid_Response_Exception If the response cannot be validated.
	 */
	public function describe( array $product, array $options = array() ): array {
		unset( $options );

		return $this->attempt(
			$this->prompts->description_prompt( $product ),
			fn( string $raw ): array => $this->validator->validate_description( $raw )
		);
	}

	/**
	 * Run the call, validate it, and retry once if the policy says the failure is
	 * worth a second attempt and the clock allows it.
	 *
	 * @param string   $prompt   The prompt.
	 * @param callable $validate Receives the raw text, returns the validated payload.
	 * @return array<mixed> The validated payload.
	 * @throws Provider_Exception If the call fails, or there is no time for an attempt.
	 * @throws Invalid_Response_Exception If validation fails and a retry is not worthwhile.
	 */
	private function attempt( string $prompt, callable $validate ): array {
		$started    = microtime( true );
		$budget     = $this->policy->budget();
		$tokens     = self::MAX_OUTPUT_TOKENS;
		$current    = $prompt;
		$last_error = null;

		for ( $attempt = 1; $attempt <= Retry_Policy::MAX_ATTEMPTS; $attempt++ ) {
			$remaining = $budget - (int) ceil( microtime( true ) - $started );

			if ( ! $this->policy->can_attempt_within( $remaining ) ) {
				// Out of clock. Fail as retryable so Action Scheduler runs this row
				// again with a fresh budget, rather than starting a call that PHP
				// will kill before the outcome can be recorded.
				throw new Provider_Exception(
					'out_of_time',
					'Not enough time left in this request to attempt the call.',
					true
				);
			}

			$raw = $this->request( $current, $tokens, $this->policy->timeout( $remaining ) );

			try {
				return (array) $validate( $raw );
			} catch ( Invalid_Response_Exception $e ) {
				$last_error = $e;
				$decision   = $this->policy->for_validation_code( $e->code_slug() );

				if ( ! $decision['retry'] || $attempt >= Retry_Policy::MAX_ATTEMPTS ) {
					throw $e;
				}

				if ( 'output_limit' === $decision['lever'] ) {
					// Same prompt, more room. Re-asking identically with the same
					// ceiling would truncate in the same place.
					$tokens = self::MAX_OUTPUT_TOKENS_RETRY;
				} else {
					$current = $prompt . "\n\n" . $this->nudge( $decision['lever'], $e );
				}
			}
		}

		throw $last_error ?? new Provider_Exception( 'provider_error', 'Call failed for an unknown reason.' );
	}

	/**
	 * The correction appended to a retry prompt.
	 *
	 * @param string                     $lever format or contract.
	 * @param Invalid_Response_Exception $error What went wrong the first time.
	 */
	private function nudge( string $lever, Invalid_Response_Exception $error ): string {
		if ( 'contract' === $lever ) {
			// The model already returned JSON, so "return JSON" would be noise.
			// Name the field it got wrong instead.
			return sprintf(
				'Your previous reply was valid JSON but did not match the required shape: %s. '
					. 'Return exactly one JSON object matching the contract above, with every field present '
					. 'and of the stated type. Do not add fields.',
				$error->getMessage()
			);
		}

		return 'Your previous reply could not be parsed as a single JSON object. '
			. 'Return exactly one JSON object and nothing else: no explanation, no markdown fences, '
			. 'no second version.';
	}

	/**
	 * Make one HTTP call and return the model's text.
	 *
	 * @param string $prompt   Prompt to send.
	 * @param int    $tokens   Output token ceiling.
	 * @param int    $timeout  Seconds.
	 * @return string The raw text of the reply.
	 * @throws Provider_Exception If the transport or the provider fails.
	 */
	private function request( string $prompt, int $tokens, int $timeout ): string {
		$body = array(
			'contents'         => array(
				array(
					'parts' => array( array( 'text' => $prompt ) ),
				),
			),
			'generationConfig' => array(
				'temperature'      => 0.4,
				'maxOutputTokens'  => $tokens,
				// Ask for JSON at the transport level as well as in the prompt. It
				// removes most fence-and-preamble failures before the validator ever
				// sees them — but it does not make the validator optional, because a
				// provider honouring the MIME type says nothing about the shape.
				'responseMimeType' => 'application/json',
			),
		);

		$response = wp_remote_post(
			sprintf( self::ENDPOINT, rawurlencode( $this->model ) ),
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Content-Type'   => 'application/json',
					// Header rather than ?key=, so the secret does not land in access
					// logs, proxy logs, or an error report that quotes the URL.
					'x-goog-api-key' => $this->api_key,
				),
				'body'    => (string) wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();

			throw new Provider_Exception(
				str_contains( strtolower( $message ), 'timed out' ) ? 'timeout' : 'transport_error',
				$message,
				true
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			$decision = $this->policy->for_http_status( $status );

			throw new Provider_Exception(
				$decision['code'],
				sprintf( 'Provider returned HTTP %d.', $status ),
				$decision['retry']
			);
		}

		return $this->extract_text( $raw );
	}

	/**
	 * Pull the reply text out of a Gemini response envelope.
	 *
	 * @param string $raw Raw response body.
	 * @return string The model's text.
	 * @throws Provider_Exception If the envelope is unusable or the request was blocked.
	 * @throws Invalid_Response_Exception If the reply was cut short by the token limit.
	 */
	private function extract_text( string $raw ): string {
		$decoded = json_decode( $raw, true );

		if ( ! is_array( $decoded ) ) {
			throw new Provider_Exception( 'provider_error', 'Provider response was not JSON.' );
		}

		if ( isset( $decoded['promptFeedback']['blockReason'] ) ) {
			throw new Provider_Exception(
				'blocked_by_provider',
				sprintf( 'Provider blocked the request: %s.', (string) $decoded['promptFeedback']['blockReason'] )
			);
		}

		$candidate = $decoded['candidates'][0] ?? null;

		if ( ! is_array( $candidate ) ) {
			throw new Provider_Exception( 'provider_error', 'Provider returned no candidates.' );
		}

		// The provider tells us it ran out of room, so there is no need to wait for
		// the validator to infer it from a dangling brace. Raised as the validator's
		// own truncation code so one policy entry covers both routes to the same
		// problem — and so the retry uses the output-limit lever, not a nudge.
		if ( 'MAX_TOKENS' === ( $candidate['finishReason'] ?? '' ) ) {
			throw new Invalid_Response_Exception(
				'truncated_json',
				'Provider stopped at the output token limit.'
			);
		}

		$text = $candidate['content']['parts'][0]['text'] ?? null;

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			throw new Provider_Exception( 'provider_error', 'Provider returned an empty reply.' );
		}

		return $text;
	}
}
