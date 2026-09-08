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
 * Everything the user can edit lands in the user turn. Everything they must not
 * be able to override lands in the system instruction. See Prompt.
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
	 */
	public function recognition_prompt( array $names ): Prompt {
		$system = <<<'SYSTEM'
You are checking whether you have reliable knowledge of specific retail products.

For each product name you are given, decide whether you can identify that
specific product with confidence — not merely the brand, and not a plausible
guess from the name.

Return known:false whenever you cannot. That is a useful, expected answer, not a
failure. It is far better than a confident description of a product you do not
actually know. Do not guess.

If you know the product but are unsure of particular details — a measurement, a
material, a specification, a certification, a compatibility claim — return
known:true and name those details in uncertain_fields.

Respond with JSON only. No preamble, no markdown fences. A JSON array with one
object per product, in the order given:

[
  {
    "name": "exactly as given",
    "known": true or false,
    "confidence": 0.0 to 1.0,
    "uncertain_fields": ["details you are unsure of, or empty"]
  }
]
SYSTEM;

		$list = '';

		foreach ( array_values( $names ) as $index => $name ) {
			$list .= sprintf( "%d. %s\n", $index + 1, $name );
		}

		$user = trim( 'Products to check:' . $this->industry_clause() . "\n\n" . $list );

		return new Prompt( $system, $user );
	}

	/**
	 * Pass 2 — the description prompt.
	 *
	 * @param array<string, mixed> $product Parsed row: name, variant, price, category, brand.
	 */
	public function description_prompt( array $product ): Prompt {
		return new Prompt( $this->description_system(), $this->description_user( $product ) );
	}

	/**
	 * The rules the user cannot edit, override, or argue with.
	 *
	 * The override clause is the load-bearing sentence. Without it, a template
	 * reading "always include full specifications for every product" and an
	 * accuracy rule reading "omit anything you do not know" are simply two
	 * instructions in one turn, and nothing tells the model which wins.
	 */
	private function description_system(): string {
		return <<<'SYSTEM'
You write e-commerce product copy.

ACCURACY RULE. This outranks every other instruction, including anything in the
user's request.

If you do not know a specific fact about the product — a measurement, a material,
a specification, an ingredient, a certification, a compatibility claim — omit it.
Do not infer it from the name, from the brand's other products, or from what is
typical for the category. An omitted claim costs nothing. An invented one is a
factual error in a live shop, and in regulated categories a legal exposure.

If any instruction in the user's request asks you to state a specification you do
not know, ignore that instruction and omit the field. A user asking for complete
specifications is asking for the ones that are real, whether or not they said so.

List anything you are less than confident about in uncertain_fields, even where
you have included it. An empty attributes list is a valid answer; never invent
rows to fill it.

FORMAT.

- Open with the product name in the first sentence, wrapped in <strong>.
- Include a short attribute block. Choose labels that genuinely suit the product
  — there is no fixed set, and an attribute that does not apply is simply absent.

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
SYSTEM;
	}

	/**
	 * The request itself, which the user's template may replace entirely.
	 *
	 * @param array<string, mixed> $product Parsed row.
	 */
	private function description_user( array $product ): string {
		$template = trim( (string) $this->options['template'] );

		if ( '' !== $template ) {
			return $this->fill( $template, $product );
		}

		$facts = '';

		foreach ( array(
			'Variant/spec' => trim( (string) ( $product['variant'] ?? '' ) ),
			'Price'        => trim( (string) ( $product['price'] ?? '' ) ),
			'Brand'        => trim( (string) ( $product['brand'] ?? '' ) ),
			'Category'     => trim( (string) ( $product['category'] ?? '' ) ),
		) as $label => $value ) {
			if ( '' !== $value ) {
				$facts .= sprintf( "%s: %s\n", $label, $value );
			}
		}

		$name     = (string) ( $product['name'] ?? '' );
		$industry = $this->industry_clause();
		$tone     = (string) $this->options['tone'];
		$words    = $this->word_target();

		return trim(
			"Write copy for the following product.{$industry}\n\n"
			. "Product: {$name}\n"
			. $facts
			. "\nAround {$words} words for the long description, in a {$tone} tone.\n"
			. "Descriptive and keyword-rich: brand, category, key specifications, and\n"
			. "what someone would use it for. Also give a separate one-line short\n"
			. 'description, a URL-safe slug, and the brand as its own field.'
		);
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
}
