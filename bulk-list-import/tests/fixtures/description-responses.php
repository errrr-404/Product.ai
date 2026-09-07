<?php
/**
 * Golden fixtures for Response_Validator.
 *
 * The validator is the only door between model output and the database, so this
 * is its regression net — the same role tests/test-parser-fixtures.php plays for
 * the parser, and the same growth rule: when a real response breaks something,
 * add a case here rather than loosening the validator.
 *
 * Raw payloads are nowdoc so the bytes are exact. Several are deliberately ugly,
 * because real responses are.
 *
 * `expect` is 'ok' for a response that must validate, otherwise the stable code
 * the validator must raise. Asserting the *code* rather than just "it threw"
 * matters: the Import Report turns these into the specific human reasons the
 * user reads, and "malformed JSON" versus "attributes came back as a map" is
 * the difference between an actionable row and a shrug.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

return array(

	// ---------------------------------------------------------------- valid --

	array(
		'label'  => 'complete payload',
		'why'    => 'The shape everything else is measured against.',
		'expect' => 'ok',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Dell XPS 13 9340</strong> is a 13-inch ultraportable laptop built around Intel's Core Ultra platform. It pairs 16GB of memory with a 512GB solid-state drive, making it suited to office work, development and travel where weight matters more than raw graphics performance.",
  "short_description": "13-inch ultraportable laptop with 16GB RAM and a 512GB SSD.",
  "slug": "dell-xps-13-9340-16gb-512gb",
  "brand": "Dell",
  "meta_description": "Dell XPS 13 9340 ultraportable laptop with 16GB RAM and 512GB SSD. Lightweight build for work and travel.",
  "focus_keyword": "dell xps 13 9340",
  "attributes": [
    { "label": "Memory", "value": "16GB" },
    { "label": "Storage", "value": "512GB SSD" },
    { "label": "Screen size", "value": "13 inch" }
  ],
  "uncertain_fields": ["exact processor variant"]
}
RAW
		,
	),

	array(
		'label'  => 'empty attributes and no hedges',
		'why'    => 'A plain product may have nothing worth tabulating and nothing to hedge. Inventing rows to fill the block is the exact failure this layer exists to prevent, so empty must be legal.',
		'expect' => 'ok',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Office Chair, Ergonomic Mesh</strong> is a task chair with a breathable mesh back and adjustable seat height, intended for extended desk work.",
  "short_description": "Ergonomic mesh task chair with adjustable height.",
  "slug": "office-chair-ergonomic-mesh",
  "brand": "Unbranded",
  "meta_description": "Ergonomic mesh office chair with adjustable seat height for extended desk work.",
  "focus_keyword": "ergonomic mesh office chair",
  "attributes": [],
  "uncertain_fields": []
}
RAW
		,
	),

	array(
		'label'  => 'wrapped in markdown fences',
		'why'    => 'Extremely common. Not worth failing a row over.',
		'expect' => 'ok',
		'raw'    => <<<'RAW'
```json
{
  "long_description": "The <strong>Anker PowerCore 20000mAh</strong> is a high-capacity portable battery for charging phones and tablets away from mains power.",
  "short_description": "20000mAh portable power bank.",
  "slug": "anker-powercore-20000mah",
  "brand": "Anker",
  "meta_description": "Anker PowerCore 20000mAh portable power bank for phones and tablets.",
  "focus_keyword": "anker powercore 20000mah",
  "attributes": [ { "label": "Capacity", "value": "20000mAh" } ],
  "uncertain_fields": ["output wattage"]
}
```
RAW
		,
	),

	array(
		'label'  => 'conversational preamble and sign-off',
		'why'    => 'Also common. The trailing prose has no braces, so the ambiguity check must not fire on it.',
		'expect' => 'ok',
		'raw'    => <<<'RAW'
Sure! Here is the product copy you asked for:

{
  "long_description": "The <strong>Organic Basmati Rice 5kg</strong> is a long-grain aromatic rice sold in a five kilogram sack.",
  "short_description": "Long-grain aromatic basmati rice, 5kg.",
  "slug": "organic-basmati-rice-5kg",
  "brand": "Unbranded",
  "meta_description": "Organic long-grain basmati rice in a 5kg sack.",
  "focus_keyword": "organic basmati rice 5kg",
  "attributes": [ { "label": "Weight", "value": "5kg" } ],
  "uncertain_fields": []
}

Let me know if you would like a different tone.
RAW
		,
	),

	array(
		'label'  => 'uncertain_fields key absent entirely',
		'why'    => 'Absence means nothing to hedge, which is not an error. Must normalise to an empty list rather than throw.',
		'expect' => 'ok',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Cement 50kg bag</strong> is general-purpose construction cement supplied in a fifty kilogram bag.",
  "short_description": "General-purpose cement, 50kg bag.",
  "slug": "cement-50kg-bag",
  "brand": "Unbranded",
  "meta_description": "General-purpose construction cement in a 50kg bag.",
  "focus_keyword": "cement 50kg bag",
  "attributes": [ { "label": "Weight", "value": "50kg" } ]
}
RAW
		,
	),

	array(
		'label'  => 'script tag in long_description passes through unchanged',
		'why'    => 'Documents the boundary. Validation is shape; sanitisation is wp_kses_post() at the write boundary. If this case ever starts failing, someone has moved kses in here — and the standalone suite will have stopped covering the shipped path.',
		'expect' => 'ok',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Test Product</strong> is a widget.<script>alert(1)</script><img src=x onerror=alert(2)>",
  "short_description": "A widget.",
  "slug": "test-product",
  "brand": "Test",
  "meta_description": "A widget for testing.",
  "focus_keyword": "test product",
  "attributes": [],
  "uncertain_fields": []
}
RAW
		,
	),

	array(
		'label'  => 'accented slug folds rather than strips',
		'why'    => 'Proves the shared Ascii_Folder table is in the slug path. Stripping without folding yields "rmy-martin-1738" — a silently wrong URL rather than an obviously wrong one.',
		'expect' => 'ok',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Rémy Martin 1738</strong> is a cognac from the Rémy Martin house.",
  "short_description": "Cognac from Rémy Martin.",
  "slug": "rémy-martin-1738",
  "brand": "Rémy Martin",
  "meta_description": "Rémy Martin 1738 cognac, 70cl.",
  "focus_keyword": "remy martin 1738",
  "attributes": [ { "label": "Volume", "value": "70cl" } ],
  "uncertain_fields": ["cask type", "abv"]
}
RAW
		,
	),

	array(
		'label'  => 'slug full of punctuation that is not an accent',
		'why'    => "Ascii_Folder covers accents; slugs meet more than that. Apostrophes must vanish rather than split a word — WordPress's own sanitize_title() gives \"levis\", and \"levi-s\" would be a subtly wrong URL. Ampersand, slash, full stop and inch mark all collapse to a single separator.",
		'expect' => 'ok',
		'assert' => array( 'slug' => 'levis-501-w32-l34-55-more' ),
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Levi's 501 Straight Jeans</strong> are a straight-leg denim jean.",
  "short_description": "Straight-leg denim jeans.",
  "slug": "Levi's 501 — W32/L34 — 55\" & More",
  "brand": "Levi's",
  "meta_description": "Levi's 501 straight jeans in W32 L34.",
  "focus_keyword": "levis 501 straight jeans",
  "attributes": [ { "label": "Waist", "value": "W32" } ],
  "uncertain_fields": []
}
RAW
		,
	),

	// -------------------------------------------------------------- invalid --

	array(
		'label'  => 'name written entirely in a non-Latin script',
		'why'    => 'Folds to nothing through no fault of the model, so it must not share the empty_field reason a lazy or punctuation-only response gets. Different cause, different fix: this row is unfixable by re-running and the user has to set the slug by hand, which is only sayable in the report if the code says it here.',
		'expect' => 'slug_not_transliterable',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Кока-Кола</strong> is a soft drink.",
  "short_description": "Soft drink.",
  "slug": "Кока-Кола",
  "brand": "Кока-Кола",
  "meta_description": "Soft drink.",
  "focus_keyword": "кока-кола",
  "attributes": [],
  "uncertain_fields": []
}
RAW
		,
	),


	array(
		'label'  => 'truncated mid-string',
		'why'    => 'What a token limit actually looks like. Must be distinguishable from ordinary bad JSON, because the fix differs: raise max output tokens, not re-prompt.',
		'expect' => 'truncated_json',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Samsung 55\" QLED TV</strong> is a 55-inch quantum-dot television offering high peak brightness and wide colour cover
RAW
		,
	),

	array(
		'label'  => 'attributes returned as a keyed map',
		'why'    => 'The single most likely shape drift, and invited by the contract itself: attributes is deliberately generic, so a model naturally reaches for label-as-key. Accepting both shapes would mean two code paths downstream, and disambiguating by expected label names is the one thing this field must never do.',
		'expect' => 'attributes_not_list',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Dell XPS 13 9340</strong> is a 13-inch ultraportable laptop.",
  "short_description": "13-inch ultraportable laptop.",
  "slug": "dell-xps-13-9340",
  "brand": "Dell",
  "meta_description": "Dell XPS 13 9340 ultraportable laptop.",
  "focus_keyword": "dell xps 13 9340",
  "attributes": { "Memory": "16GB", "Storage": "512GB SSD" },
  "uncertain_fields": []
}
RAW
		,
	),

	array(
		'label'  => 'attribute items use the wrong key names',
		'why'    => 'A list, but of {name, val} instead of {label, value}. Close enough to slip through a loose check and write empty attributes onto every product.',
		'expect' => 'attribute_item_shape',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Nike Air Max 270</strong> is a lifestyle trainer.",
  "short_description": "Lifestyle trainer.",
  "slug": "nike-air-max-270",
  "brand": "Nike",
  "meta_description": "Nike Air Max 270 lifestyle trainer.",
  "focus_keyword": "nike air max 270",
  "attributes": [ { "name": "Size", "val": "UK 9" } ],
  "uncertain_fields": []
}
RAW
		,
	),

	array(
		'label'  => 'attribute value is a number, not a string',
		'why'    => 'PHP would coerce 16 to "16" without complaint. A model that returns a bare number has misunderstood the contract, and the next response may be an object.',
		'expect' => 'attribute_item_shape',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Test Laptop</strong> is a laptop.",
  "short_description": "A laptop.",
  "slug": "test-laptop",
  "brand": "Test",
  "meta_description": "A test laptop.",
  "focus_keyword": "test laptop",
  "attributes": [ { "label": "Memory", "value": 16 } ],
  "uncertain_fields": []
}
RAW
		,
	),

	array(
		'label'  => 'uncertain_fields as a bare string',
		'why'    => 'The most consequential loose-typing case in the contract. "abv" and ["abv"] mean different things, and guessing is how one hedge silently becomes three characters iterated as a list.',
		'expect' => 'wrong_type',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Don Julio 1942</strong> is an añejo tequila.",
  "short_description": "Añejo tequila.",
  "slug": "don-julio-1942",
  "brand": "Don Julio",
  "meta_description": "Don Julio 1942 anejo tequila.",
  "focus_keyword": "don julio 1942",
  "attributes": [],
  "uncertain_fields": "abv"
}
RAW
		,
	),

	array(
		'label'  => 'required key missing',
		'why'    => 'No short_description. Would otherwise write a product with a blank excerpt.',
		'expect' => 'missing_field',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Test Product</strong> is a widget.",
  "slug": "test-product",
  "brand": "Test",
  "meta_description": "A widget.",
  "focus_keyword": "test product",
  "attributes": [],
  "uncertain_fields": []
}
RAW
		,
	),

	array(
		'label'  => 'required key is null',
		'why'    => 'Present but null. isset() would reject it, array_key_exists() would not — this pins which one the validator uses.',
		'expect' => 'wrong_type',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Test Product</strong> is a widget.",
  "short_description": "A widget.",
  "slug": "test-product",
  "brand": null,
  "meta_description": "A widget.",
  "focus_keyword": "test product",
  "attributes": [],
  "uncertain_fields": []
}
RAW
		,
	),

	array(
		'label'  => 'slug returned as a number',
		'why'    => 'Strict typing over PHP coercion. 12345 would silently become the string "12345" and produce a numeric URL nobody intended.',
		'expect' => 'wrong_type',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Test Product</strong> is a widget.",
  "short_description": "A widget.",
  "slug": 12345,
  "brand": "Test",
  "meta_description": "A widget.",
  "focus_keyword": "test product",
  "attributes": [],
  "uncertain_fields": []
}
RAW
		,
	),

	array(
		'label'  => 'long_description present but empty',
		'why'    => 'Shape-valid, semantically useless. Without this check the import reports success and the shop gets a product with no description.',
		'expect' => 'empty_field',
		'raw'    => <<<'RAW'
{
  "long_description": "",
  "short_description": "A widget.",
  "slug": "test-product",
  "brand": "Test",
  "meta_description": "A widget.",
  "focus_keyword": "test product",
  "attributes": [],
  "uncertain_fields": []
}
RAW
		,
	),

	array(
		'label'  => 'slug contains nothing URL-safe',
		'why'    => 'Non-empty before normalisation, empty after. The check has to run on the normalised value, not the raw one.',
		'expect' => 'empty_field',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Test Product</strong> is a widget.",
  "short_description": "A widget.",
  "slug": "!!! ???",
  "brand": "Test",
  "meta_description": "A widget.",
  "focus_keyword": "test product",
  "attributes": [],
  "uncertain_fields": []
}
RAW
		,
	),

	array(
		'label'  => 'prose only, no JSON at all',
		'why'    => 'The model ignored the format instruction. Needs its own reason: re-prompting may help, whereas a truncation needs a token-limit change.',
		'expect' => 'no_json_found',
		'raw'    => <<<'RAW'
I would be happy to help you write product copy! However, I do not have
reliable information about this specific product, so I cannot describe its
features accurately. Could you tell me more about it?
RAW
		,
	),

	array(
		'label'  => 'two JSON objects in one response',
		'why'    => 'The model answered twice — often a corrected second attempt. Picking either one is a guess about which it meant, and guessing wrong writes copy the model itself retracted.',
		'expect' => 'ambiguous_json',
		'raw'    => <<<'RAW'
{
  "long_description": "First attempt.",
  "short_description": "First.",
  "slug": "first",
  "brand": "Test",
  "meta_description": "First.",
  "focus_keyword": "first",
  "attributes": [],
  "uncertain_fields": []
}

Actually, here is a better version:

{
  "long_description": "Second attempt.",
  "short_description": "Second.",
  "slug": "second",
  "brand": "Test",
  "meta_description": "Second.",
  "focus_keyword": "second",
  "attributes": [],
  "uncertain_fields": []
}
RAW
		,
	),

	array(
		'label'  => 'trailing comma',
		'why'    => 'Syntactically invalid JSON that looks fine to a human reader.',
		'expect' => 'invalid_json',
		'raw'    => <<<'RAW'
{
  "long_description": "The <strong>Test Product</strong> is a widget.",
  "short_description": "A widget.",
  "slug": "test-product",
  "brand": "Test",
  "meta_description": "A widget.",
  "focus_keyword": "test product",
  "attributes": [],
  "uncertain_fields": [],
}
RAW
		,
	),
);
