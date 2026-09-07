<?php
/**
 * Prompt_Builder regression suite.
 *
 * The settings screen tells the user that the JSON contract and the accuracy
 * rule are appended to whatever they write, so a custom prompt cannot remove
 * them. That is a promise made in the UI, which makes it something to test
 * rather than something to hope for: a custom template that dropped the contract
 * would fail every row at the validator, and a custom template that dropped the
 * accuracy rule would fail nothing at all — it would just quietly start
 * inventing specifications.
 *
 * Run with:  php tests/test-prompt-builder.php
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

require_once __DIR__ . '/bootstrap.php';

use BulkListImport\AI\Prompt_Builder;

$passed = 0;
$failed = 0;

/**
 * Report one case.
 *
 * @param bool   $ok     Whether it passed.
 * @param string $label  What was checked.
 * @param string $detail Printed on failure.
 */
$report = static function ( bool $ok, string $label, string $detail = '' ) use ( &$passed, &$failed ): void {
	if ( $ok ) {
		++$passed;
		printf( "PASS  %s\n", $label );
		return;
	}

	++$failed;
	printf( "FAIL  %s\n", $label );

	if ( '' !== $detail ) {
		printf( "        %s\n", $detail );
	}
};

$product = array(
	'name'    => 'Dell XPS 13 9340',
	'variant' => '16GB/512GB',
	'price'   => '1850000',
	'brand'   => 'Dell',
);

// ------------------------------------------------------------------ defaults --

$default_prompt = ( new Prompt_Builder() )->description_prompt( $product );

$report( str_contains( $default_prompt, 'Dell XPS 13 9340' ), 'default prompt names the product' );
$report( str_contains( $default_prompt, '"long_description"' ), 'default prompt carries the JSON contract' );
$report( str_contains( $default_prompt, 'omit it' ), 'default prompt carries the accuracy rule' );
$report( str_contains( $default_prompt, 'uncertain_fields' ), 'default prompt asks for uncertain_fields' );

// Nothing configured must still produce a usable, category-neutral prompt.
$report(
	! str_contains( $default_prompt, 'The store type is' ),
	'no industry configured means no industry sentence'
);

// --------------------------------------------------------- custom templates --

$builder = new Prompt_Builder(
	array(
		'industry' => 'pharmacy',
		'tone'     => 'technical',
		'template' => 'Write copy for {product_name} ({variant}) at {price}. Industry: {industry}. Tone: {tone}.',
	)
);

$custom = $builder->description_prompt( $product );

$report( str_contains( $custom, 'Write copy for Dell XPS 13 9340 (16GB/512GB) at 1850000.' ), 'placeholders are substituted' );
$report( str_contains( $custom, 'Industry: pharmacy.' ), 'industry placeholder is substituted' );
$report( str_contains( $custom, 'Tone: technical.' ), 'tone placeholder is substituted' );

// The promise made on the settings screen.
$report( str_contains( $custom, '"long_description"' ), 'a custom template still carries the JSON contract' );
$report( str_contains( $custom, '"attributes"' ), 'a custom template still carries the attributes contract' );
$report( str_contains( $custom, 'Respond with JSON only' ), 'a custom template still demands JSON only' );

// A template that never mentions the product at all must not silently produce a
// prompt about nothing.
$empty_template = ( new Prompt_Builder( array( 'template' => 'Say something nice.' ) ) )->description_prompt( $product );
$report( str_contains( $empty_template, '"long_description"' ), 'even a nonsense template gets the contract appended' );

// ------------------------------------------------------------------- lengths --

foreach ( array(
	'short'  => '60',
	'medium' => '130',
	'long'   => '220',
) as $length => $words ) {
	$prompt = ( new Prompt_Builder( array( 'length' => $length ) ) )->description_prompt( $product );

	$report(
		str_contains( $prompt, 'Around ' . $words . ' words' ),
		sprintf( 'length "%s" asks for around %s words', $length, $words )
	);
}

// An unknown length falls back rather than emitting a broken instruction.
$odd = ( new Prompt_Builder( array( 'length' => 'enormous' ) ) )->description_prompt( $product );
$report( str_contains( $odd, 'Around 130 words' ), 'an unknown length falls back to medium' );

// --------------------------------------------------------------- recognition --

$recognition = ( new Prompt_Builder() )->recognition_prompt( array( 'Cape More', 'Don Julio 1942' ) );

$report( str_contains( $recognition, '1. Cape More' ), 'recognition prompt numbers the names' );
$report( str_contains( $recognition, '2. Don Julio 1942' ), 'recognition prompt lists every name' );

// The gate only works if "I do not know" is an easy answer. A prompt that framed
// it as a failure would push the model towards a confident guess, which is the
// one outcome this whole layer exists to prevent.
$report( str_contains( $recognition, 'known:false' ), 'recognition prompt names the not-known answer explicitly' );
$report( str_contains( $recognition, 'Do not guess' ), 'recognition prompt forbids guessing' );
$report(
	str_contains( $recognition, 'not a failure' ),
	'recognition prompt frames not knowing as an acceptable answer'
);
$report( str_contains( $recognition, 'not merely the brand' ), 'recognition prompt distinguishes product from brand' );

printf( "\n%d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
