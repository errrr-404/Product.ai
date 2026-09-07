<?php
/**
 * Parser regression fixtures.
 *
 * These are test cases, not a whitelist. The parser has no concept of product
 * category — it matches units, price-like numbers, delimiters and leftover text.
 * Every industry runs through the same code path; the categories below are
 * simply where verified coverage currently sits.
 *
 * When you meet a product shape that parses badly, add a fixture line for it
 * rather than special-casing the parser. Growing this list is how coverage
 * expands.
 *
 * Run with:  php tests/test-parser-fixtures.php
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

define( 'BLI_PARSER_STANDALONE', true );

require_once __DIR__ . '/../includes/class-parser.php';

/**
 * Fixture set: raw line => expected [ name, variant, price ].
 *
 * Use null for price to assert that no price was found, and the literal
 * '#heading' as the name to assert the line was detected as a heading.
 */
$fixtures = array(
	// ELECTRONICS.
	'ELECTRONICS'                                     => array( '#heading', '', null ),
	'Samsung 55" QLED TV — ₦450,000'                  => array( 'Samsung 55" QLED TV', '', 450000.0 ),
	'iPhone 15 Pro 256GB — $999'                      => array( 'iPhone 15 Pro', '256GB', 999.0 ),
	'Dell XPS 13 9340 — 16GB/512GB — ₦1,850,000'      => array( 'Dell XPS 13 9340', '16GB/512GB', 1850000.0 ),
	'Anker PowerCore 20000mAh — ₦28,500'              => array( 'Anker PowerCore', '20000mAh', 28500.0 ),

	// APPAREL.
	'Nike Air Max 270 — UK 9 — ₦85,000'               => array( 'Nike Air Max 270', 'UK 9', 85000.0 ),
	"Levi's 501 Straight Jeans — W32 L34 — ₦45,000"   => array( "Levi's 501 Straight Jeans", 'W32 L34', 45000.0 ),

	// PHARMACY.
	'Paracetamol 500mg x 100 tablets — ₦2,500'        => array( 'Paracetamol', '500mg x 100 tablets', 2500.0 ),
	'Vitamin C 1000mg (60 caps) — ₦8,000'             => array( 'Vitamin C', '1000mg 60 caps', 8000.0 ),

	// BUILDING MATERIALS / GROCERY / FURNITURE.
	'Cement (50kg bag) — ₦9,500'                      => array( 'Cement', '50kg bag', 9500.0 ),
	'Organic Basmati Rice — 5kg — ₦12,000'            => array( 'Organic Basmati Rice', '5kg', 12000.0 ),
	'Office Chair, Ergonomic Mesh — ₦75,000'          => array( 'Office Chair, Ergonomic Mesh', '', 75000.0 ),

	// DRINKS — the original data set, kept as one category among many.
	'1. Glenfiddich 30 Years Old — 70cl — ₦2,260,000' => array( 'Glenfiddich 30 Years Old', '70cl', 2260000.0 ),
	'Rémy Martin 1738 — 70cl — ₦95,000'               => array( 'Rémy Martin 1738', '70cl', 95000.0 ),
	'818 Tequila Blanco (75cl) 80,000'                => array( '818 Tequila Blanco', '75cl', 80000.0 ),
	'4th Street Sweet Red Wine — 75cl — ₦5,000'       => array( '4th Street Sweet Red Wine', '75cl', 5000.0 ),
	'Declan White Wine — 75cl 8000'                   => array( 'Declan White Wine', '75cl', 8000.0 ),
	'Aznauri Saperavi — 75cl'                         => array( 'Aznauri Saperavi', '75cl', null ),
	"Grey Goose\t70cl\t₦49,000"                       => array( 'Grey Goose', '70cl', 49000.0 ),
	'Element — 70cl — ₦43,000'                        => array( 'Element', '70cl', 43000.0 ),
	'Product / CL / Price'                            => array( '#heading', '', null ),
);

$parser = new BulkListImport\Parser();
$passed = 0;
$failed = 0;

foreach ( $fixtures as $line => $expected ) {
	list( $want_name, $want_variant, $want_price ) = $expected;

	$row = $parser->parse_line( (string) $line, 1 );
	$got = 'heading' === $row['type'] ? '#heading' : $row['name'];

	$ok = $got === $want_name
		&& $row['variant'] === $want_variant
		&& ( null === $want_price ? null === $row['price'] : abs( (float) $row['price'] - $want_price ) < 0.001 );

	if ( $ok ) {
		++$passed;
		printf( "PASS  %s\n", $line );
		continue;
	}

	++$failed;
	printf( "FAIL  %s\n", $line );
	printf( "        expected  name=%-30s variant=%-20s price=%s\n", var_export( $want_name, true ), var_export( $want_variant, true ), var_export( $want_price, true ) );
	printf( "        got       name=%-30s variant=%-20s price=%s\n", var_export( $got, true ), var_export( $row['variant'], true ), var_export( $row['price'], true ) );
}

// Duplicate detection runs across the whole paste, not per line.
$dupes = $parser->parse( "Grey Goose — 70cl — ₦49,000\nGrey Goose — 70cl — ₦49,000" );

if ( 2 === count( $dupes ) && in_array( 'duplicate', $dupes[1]['flags'], true ) && 1 === $dupes[1]['duplicate_of'] ) {
	++$passed;
	echo "PASS  duplicate row flagged against the first occurrence\n";
} else {
	++$failed;
	echo "FAIL  duplicate row flagged against the first occurrence\n";
}

// A row with no unit must score full confidence when name and price are present.
$no_unit = $parser->parse_line( 'Office Chair, Ergonomic Mesh — ₦75,000', 1 );

if ( abs( $no_unit['confidence'] - 1.0 ) < 0.001 ) {
	++$passed;
	echo "PASS  row with no unit keeps full confidence\n";
} else {
	++$failed;
	printf( "FAIL  row with no unit keeps full confidence (got %s)\n", var_export( $no_unit['confidence'], true ) );
}

printf( "\n%d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
