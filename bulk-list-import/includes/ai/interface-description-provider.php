<?php
/**
 * The provider seam.
 *
 * We integrate an existing AI API. We do not build a model: no training, no ML
 * pipeline, no local inference. An implementation makes HTTP requests to a
 * third-party API and parses the JSON that comes back. That is the whole AI
 * component, and it is a commodity — the value this plugin adds is everything
 * around it: parsing, gating, SKU logic, review workflow.
 *
 * Which is exactly why this seam exists from the first commit. Provider and
 * model are user settings, not constants, and a hosted-proxy implementation may
 * replace bring-your-own-key later without calling code changing.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BLI_STANDALONE' ) ) {
	exit;
}

/**
 * A source of recognition judgements and product descriptions.
 *
 * Named Description_Provider rather than the spec's DescriptionProvider because
 * WordPress class names are Capitalized_Words_With_Underscores, and the file
 * naming convention derives from it.
 */
interface Description_Provider {

	/**
	 * Pass 1 — the recognition gate. Cheap, batched, runs before generation.
	 *
	 * Asks only "do you actually know this product?" so that nothing is spent
	 * writing prose for a product the user is about to reject, and — far more
	 * importantly — so the model is never put in the position of inventing one.
	 *
	 * Implementations MUST return one record per input name, in the same order.
	 * A name the provider silently drops is a row that vanishes from the report.
	 *
	 * @param array<int, string> $product_names Product names to check, deduplicated by the caller.
	 * @return array<int, array{name: string, known: bool, confidence: float, uncertain_fields: array<int, string>}>
	 * @throws Provider_Exception If the call fails, or the response cannot be validated.
	 */
	public function recognise( array $product_names ): array;

	/**
	 * Pass 2 — write the description. Runs at import time, one product per call.
	 *
	 * Only ever called for rows that cleared the gate.
	 *
	 * @param array<string, mixed> $product Parsed row: name, variant, price, category, brand.
	 * @param array<string, mixed> $options Prompt settings: industry, tone, length, template.
	 * @return array{
	 *     long_description: string,
	 *     short_description: string,
	 *     slug: string,
	 *     brand: string,
	 *     meta_description: string,
	 *     focus_keyword: string,
	 *     attributes: array<int, array{label: string, value: string}>,
	 *     uncertain_fields: array<int, string>
	 * }
	 * @throws Provider_Exception If the call fails, or the response cannot be validated.
	 */
	public function describe( array $product, array $options ): array;

	/**
	 * Stable machine identifier, e.g. "gemini". Used as a settings value and as
	 * part of the recognition cache key.
	 */
	public function id(): string;

	/**
	 * Models this key may use, for the settings dropdown.
	 *
	 * On the seam rather than inside one provider because the problem is not
	 * Gemini's: model names are versioned and retired on every vendor's own
	 * schedule, so any hardcoded list rots and users hit 404s on a plugin they
	 * never touched. Asking the provider is the only version that stays true.
	 *
	 * Callers are expected to cache the result.
	 *
	 * @return array<string, string> Model id => human label.
	 * @throws Provider_Exception If the list cannot be fetched.
	 */
	public function list_models(): array;
}
