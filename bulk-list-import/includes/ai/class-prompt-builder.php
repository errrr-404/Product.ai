<?php
/**
 * Builds the prompts. Category-neutral by default; the user supplies the industry.
 *
 * Free of WordPress functions so it can be exercised standalone — settings are
 * passed in as an array, read from wp_options by the caller.
 *
 * Nothing here names a product category. The plugin has no taxonomy: the same
 * prompt has to work for a phone shop, a pharmacy, a boutique and a builders'
 * merchant, and the model decides what attributes suit what it is looking at.
 * If a change to this file would only make sense for one industry, it is wrong.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BLI_STANDALONE' ) ) {
	exit;
}

/**
 * Assembles recognition and description prompts from user settings.
 */
final class Prompt_Builder {

	/**
	 * Placeholders a custom template may use.
	 *
	 * @var string[]
	 */
	public const PLACEHOLDERS = array(
		'{product_name}',
		'{variant}',
		'{price}',
		'{category}',
		'{brand}',
		'{industry}',
		'{tone}',
	);

	/**
	 * Settings, merged over the defaults.
	 *
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * Build with user settings. Anything absent falls back to a default that
	 * works with nothing configured.
	 *
	 * @param array<string, mixed> $options Industry, tone, length, template.
	 */
	public function __construct( array $options = array() ) {
		$this->options = array_merge( self::defaults(), $options );
	}

	/**
	 * Settings that make the plugin work out of the box.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'industry' => '',
			'tone'     => 'plain',
			'length'   => 'medium',
			'template' => '',
		);
	}

	/**
	 * Pass 1 — the recognition prompt.
	 *
	 * Deliberately gives the model an easy, dignified way to say no. The whole
	 * gate rests on "I don't know" being a cheap answer rather than a failure,
	 * because a model that feels obliged to produce something will.
	 *
	 * @param array<int, string> $names Product names to check.
	 * @return string The prompt.
	 */
	public function recognition_prompt( array $names ): string {
		$list = '';

		foreach ( array_values( $names ) as $index => $name ) {
			$list .= sprintf( "%d. %s\n", $index + 1, $name );
		}

		$industry = $this->industry_clause();

		return <<<PROMPT
			You are checking whether you have reliable knowledge of specific retail products.{$industry}

			For each product name below, decide whether you can identify that specific
			product with confidence — not merely the brand, and not a plausible guess
			from the name.

			Return known:false whenever you cannot. That is a useful, expected answer,
			not a failure. It is far better than a confident description of a product
			you do not actually know. Do not guess.

			If you know the product but are unsure of particular details — a
			measurement, a material, a specification, a certification, a compatibility
			claim — return known:true and name those details in uncertain_fields.

			Products:
			{$list}
			Respond with JSON only. No preamble, no markdown fences. A JSON array with
			one object per product, in the order given:

			[
			  {
			    "name": "exactly as given above",
			    "known": true or false,
			    "confidence": 0.0 to 1.0,
			    "uncertain_fields": ["details you are unsure of, or empty"]
			  }
			]
			PROMPT;
	}

	/**
	 * Pass 2 — the description prompt.
	 *
	 * @param array<string, mixed> $product Parsed row: name, variant, price, category, brand.
	 * @return string The prompt.
	 */
	public function description_prompt( array $product ): string {
		$template = trim( (string) $this->options['template'] );

		if ( '' !== $template ) {
			return $this->fill( $template, $product ) . "\n\n" . $this->contract_clause();
		}

		$name     = (string) ( $product['name'] ?? '' );
		$variant  = trim( (string) ( $product['variant'] ?? '' ) );
		$price    = trim( (string) ( $product['price'] ?? '' ) );
		$brand    = trim( (string) ( $product['brand'] ?? '' ) );
		$category = trim( (string) ( $product['category'] ?? '' ) );

		$facts = '';

		foreach ( array(
			'Variant/spec' => $variant,
			'Price'        => $price,
			'Brand'        => $brand,
			'Category'     => $category,
		) as $label => $value ) {
			if ( '' !== $value ) {
				$facts .= sprintf( "%s: %s\n", $label, $value );
			}
		}

		$industry = $this->industry_clause();
		$tone     = (string) $this->options['tone'];
		$words    = $this->word_target();

		return <<<PROMPT
			Write e-commerce product copy for the following product.{$industry}

			Product: {$name}
			{$facts}
			Requirements:
			- Open with the product name in the first sentence, wrapped in <strong>.
			- Around {$words} words for the long description, in a {$tone} tone.
			- Descriptive and keyword-rich: brand, category, key specifications, and
			  what someone would use it for.
			- Then a short attribute block. Choose labels that genuinely suit this
			  product — there is no fixed set, and an attribute that does not apply
			  should simply be absent.
			- A separate one-line short description.
			- A URL-safe slug, and the brand as its own field.

			Accuracy rule, and it outranks every requirement above:

			If you do not know a specific fact about this product — a measurement, a
			material, a specification, an ingredient, a certification, a compatibility
			claim — omit it. Do not infer it from the name, from the brand's other
			products, or from what is typical for the category. An omitted claim costs
			nothing. An invented one is a factual error in a live shop, and in
			regulated categories a legal exposure.

			List anything you are less than confident about in uncertain_fields, even
			where you have included it.

			{$this->contract_clause()}
			PROMPT;
	}

	/**
	 * Substitute template placeholders.
	 *
	 * @param string               $template Custom template.
	 * @param array<string, mixed> $product  Parsed row.
	 * @return string The filled template.
	 */
	private function fill( string $template, array $product ): string {
		return str_replace(
			self::PLACEHOLDERS,
			array(
				(string) ( $product['name'] ?? '' ),
				(string) ( $product['variant'] ?? '' ),
				(string) ( $product['price'] ?? '' ),
				(string) ( $product['category'] ?? '' ),
				(string) ( $product['brand'] ?? '' ),
				(string) $this->options['industry'],
				(string) $this->options['tone'],
			),
			$template
		);
	}

	/**
	 * The industry sentence, or nothing when the user has not set one.
	 */
	private function industry_clause(): string {
		$industry = trim( (string) $this->options['industry'] );

		return '' === $industry ? '' : sprintf( ' The store type is: %s.', $industry );
	}

	/**
	 * Rough word target for the configured length.
	 */
	private function word_target(): int {
		switch ( (string) $this->options['length'] ) {
			case 'short':
				return 60;
			case 'long':
				return 220;
			case 'medium':
			default:
				return 130;
		}
	}

	/**
	 * The JSON contract, appended to every description prompt including custom
	 * templates — the validator rejects anything that does not match it, so a
	 * user-written template must not be able to omit it.
	 */
	private function contract_clause(): string {
		return <<<'CONTRACT'
			Respond with JSON only. No preamble, no markdown fences:

			{
			  "long_description": "...",
			  "short_description": "...",
			  "slug": "...",
			  "brand": "...",
			  "meta_description": "...",
			  "focus_keyword": "...",
			  "attributes": [ { "label": "...", "value": "..." } ],
			  "uncertain_fields": ["..."]
			}
			CONTRACT;
	}
}
