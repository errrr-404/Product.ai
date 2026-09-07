<?php
/**
 * Response_Validator regression suite.
 *
 * The validator is the only door between model output and the database, so this
 * runs on every change, standalone: no WordPress, no database, no network, no
 * API key. Milliseconds.
 *
 * Run with:  php tests/test-response-validator.php
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

require_once __DIR__ . '/bootstrap.php';

use BulkListImport\AI\Invalid_Response_Exception;
use BulkListImport\AI\Response_Validator;

$validator = new Response_Validator();
$passed    = 0;
$failed    = 0;

/**
 * Report one case.
 *
 * @param bool   $ok      Whether the case passed.
 * @param string $label   Case label.
 * @param string $detail  Extra detail printed on failure.
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

// ---------------------------------------------------------------- description --

foreach ( require __DIR__ . '/fixtures/description-responses.php' as $case ) {
	$label  = 'description: ' . $case['label'];
	$expect = $case['expect'];

	try {
		$out = $validator->validate_description( $case['raw'] );

		if ( 'ok' !== $expect ) {
			$report( false, $label, sprintf( 'expected %s, but the response validated', $expect ) );
			continue;
		}

		// A validated payload must carry every contract key, always — callers
		// index it directly.
		$missing = array_diff(
			array(
				'long_description',
				'short_description',
				'slug',
				'brand',
				'meta_description',
				'focus_keyword',
				'attributes',
				'uncertain_fields',
			),
			array_keys( $out )
		);

		$report( array() === $missing, $label, 'missing keys: ' . implode( ', ', $missing ) );
	} catch ( Invalid_Response_Exception $e ) {
		if ( 'ok' === $expect ) {
			$report( false, $label, sprintf( 'expected ok, got %s: %s', $e->code_slug(), $e->getMessage() ) );
			continue;
		}

		$report(
			$e->code_slug() === $expect,
			$label,
			sprintf( 'expected %s, got %s: %s', $expect, $e->code_slug(), $e->getMessage() )
		);
	}
}

// ---------------------------------------------------------------- recognition --

foreach ( require __DIR__ . '/fixtures/recognition-responses.php' as $case ) {
	$label  = 'recognition: ' . $case['label'];
	$expect = $case['expect'];

	try {
		$out = $validator->validate_recognition( $case['raw'], $case['asked'] );

		if ( 'ok' !== $expect ) {
			$report( false, $label, sprintf( 'expected %s, but the response validated', $expect ) );
			continue;
		}

		if ( count( $out ) !== count( $case['asked'] ) ) {
			$report( false, $label, sprintf( 'expected %d records, got %d', count( $case['asked'] ), count( $out ) ) );
			continue;
		}

		$problem = '';

		foreach ( $case['assert'] ?? array() as $index => $want ) {
			foreach ( $want as $key => $value ) {
				if ( ( $out[ $index ][ $key ] ?? null ) !== $value ) {
					$problem = sprintf(
						'record %d, %s: expected %s, got %s',
						$index,
						$key,
						var_export( $value, true ),
						var_export( $out[ $index ][ $key ] ?? null, true )
					);
					break 2;
				}
			}
		}

		$report( '' === $problem, $label, $problem );
	} catch ( Invalid_Response_Exception $e ) {
		if ( 'ok' === $expect ) {
			$report( false, $label, sprintf( 'expected ok, got %s: %s', $e->code_slug(), $e->getMessage() ) );
			continue;
		}

		$report(
			$e->code_slug() === $expect,
			$label,
			sprintf( 'expected %s, got %s: %s', $expect, $e->code_slug(), $e->getMessage() )
		);
	}
}

// ------------------------------------------------------------ targeted checks --

// Slug folding, not stripping. The fixture asserts it validates; this asserts
// the value, because "rmy-martin-1738" would also validate.
$slug = $validator->validate_description(
	'{"long_description":"x","short_description":"x","slug":"rémy-martin-1738","brand":"x","meta_description":"x","focus_keyword":"x","attributes":[],"uncertain_fields":[]}'
)['slug'];

$report( 'remy-martin-1738' === $slug, 'slug folds accents rather than stripping them', 'got ' . var_export( $slug, true ) );

// The sanitisation boundary. If this ever fails, kses has been moved into the
// validator and the standalone suite has stopped covering the shipped path.
$html = $validator->validate_description(
	'{"long_description":"a<script>alert(1)</script>b","short_description":"x","slug":"x","brand":"x","meta_description":"x","focus_keyword":"x","attributes":[],"uncertain_fields":[]}'
)['long_description'];

$report(
	str_contains( $html, '<script>' ),
	'validator does not sanitise HTML — that is wp_kses_post() at the write boundary',
	'got ' . var_export( $html, true )
);

// Over-length rejection, built here rather than embedded as a 20KB fixture.
try {
	$validator->validate_description(
		sprintf(
			'{"long_description":"%s","short_description":"x","slug":"x","brand":"x","meta_description":"x","focus_keyword":"x","attributes":[],"uncertain_fields":[]}',
			str_repeat( 'a', 20001 )
		)
	);

	$report( false, 'over-length long_description is rejected', 'it validated' );
} catch ( Invalid_Response_Exception $e ) {
	$report( 'field_too_long' === $e->code_slug(), 'over-length long_description is rejected', 'got ' . $e->code_slug() );
}

printf( "\n%d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
