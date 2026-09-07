<?php
/**
 * Retry_Policy and Secret_Box regression suite.
 *
 * The retry table decides what gets re-asked and what gets reported, so it is
 * worth pinning: a code that quietly moves from terminal to retryable is spend
 * with no chance of success, and one that moves the other way is a row failed
 * that a second attempt would have saved.
 *
 * Run with:  php tests/test-retry-policy.php
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

require_once __DIR__ . '/bootstrap.php';

use BulkListImport\AI\Provider_Exception;
use BulkListImport\AI\Retry_Policy;
use BulkListImport\AI\Secret_Box;

$policy = new Retry_Policy();
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

// ------------------------------------------------------- validation failures --

$expected = array(
	// Capacity: re-asking identically truncates in the same place, so the lever
	// is the request, not the instruction.
	'truncated_json'             => array( true, 'output_limit' ),

	// Format: the contract was never reached. A blunter instruction usually lands.
	'no_json_found'              => array( true, 'format' ),
	'ambiguous_json'             => array( true, 'format' ),
	'invalid_json'               => array( true, 'format' ),
	'not_an_object'              => array( true, 'format' ),
	'recognition_not_list'       => array( true, 'format' ),
	'recognition_missing_name'   => array( true, 'format' ),
	'recognition_known_not_bool' => array( true, 'format' ),

	// Contract: JSON parsed, shape wrong. "Return JSON" would be noise, so the
	// retry names the offending field instead.
	'missing_field'              => array( true, 'contract' ),
	'wrong_type'                 => array( true, 'contract' ),
	'attributes_not_list'        => array( true, 'contract' ),
	'attribute_item_shape'       => array( true, 'contract' ),

	// Terminal.
	'empty_field'                => array( false, 'none' ),
	'field_too_long'             => array( false, 'none' ),
	'too_many_attributes'        => array( false, 'none' ),
	'json_too_deep'              => array( false, 'none' ),
	'slug_not_transliterable'    => array( false, 'none' ),
);

foreach ( $expected as $code => $want ) {
	$got = $policy->for_validation_code( $code );

	$report(
		$got['retry'] === $want[0] && $got['lever'] === $want[1],
		sprintf( 'validation %s -> retry=%s lever=%s', $code, $want[0] ? 'true' : 'false', $want[1] ),
		sprintf( 'got retry=%s lever=%s', $got['retry'] ? 'true' : 'false', $got['lever'] )
	);
}

// truncated_json and ambiguous_json are both "bad JSON" and must NOT share a
// lever. Applying either lever to the other failure is spend with no chance of
// success, so this is asserted directly rather than left implicit in the table.
$report(
	$policy->for_validation_code( 'truncated_json' )['lever']
		!== $policy->for_validation_code( 'ambiguous_json' )['lever'],
	'truncated_json and ambiguous_json use different levers'
);

// An unrecognised code must fail closed. A new validator code should surface as
// a reported failure, not as silent extra spend.
$unknown = $policy->for_validation_code( 'some_code_added_next_year' );
$report( false === $unknown['retry'], 'unknown validation code fails closed', 'got retry=true' );
$report( false === $unknown['classified'], 'unknown validation code is flagged unclassified' );
$report( true === $policy->for_validation_code( 'empty_field' )['classified'], 'a known terminal code is flagged classified' );

// ------------------------------------------------------------- HTTP statuses --

$statuses = array(
	429 => array( true, 'rate_limited', 'outer' ),
	503 => array( true, 'provider_unavailable', 'outer' ),
	500 => array( true, 'provider_unavailable', 'outer' ),
	504 => array( true, 'provider_unavailable', 'outer' ),
	408 => array( true, 'provider_unavailable', 'outer' ),
	401 => array( false, 'auth_failed', 'none' ),
	403 => array( false, 'auth_failed', 'none' ),
	404 => array( false, 'model_not_found', 'none' ),
	400 => array( false, 'request_rejected', 'none' ),
	418 => array( false, 'provider_error', 'none' ),
);

foreach ( $statuses as $http_status => $want ) {
	$got = $policy->for_http_status( $http_status );

	$report(
		$got['retry'] === $want[0] && $got['code'] === $want[1] && $got['lane'] === $want[2],
		sprintf( 'HTTP %d -> retry=%s code=%s lane=%s', $http_status, $want[0] ? 'true' : 'false', $want[1], $want[2] ),
		sprintf( 'got retry=%s code=%s lane=%s', $got['retry'] ? 'true' : 'false', $got['code'], $got['lane'] )
	);
}

// 429 must not share a code with the 5xx bucket. A 503 clears in seconds; a rate
// limit is a quota window, and re-asking inside a 20s attempt fails identically
// while burning the one inner attempt.
$report(
	$policy->for_http_status( 429 )['code'] !== $policy->for_http_status( 503 )['code'],
	'429 does not share a code with 503'
);

// Every HTTP failure worth retrying belongs to the outer lane. The inner loop is
// for prompt-level failures, where a reworded ask fixes things instantly;
// infrastructure failures need time, which one PHP request cannot spend.
foreach ( array( 429, 500, 503, 504 ) as $retryable_status ) {
	$report(
		'outer' === $policy->for_http_status( $retryable_status )['lane'],
		sprintf( 'HTTP %d is handled by the outer loop, not an inner retry', $retryable_status )
	);
}

// Validation failures go the other way.
$report( 'inner' === $policy->for_validation_code( 'ambiguous_json' )['lane'], 'validation failures use the inner lane' );

// ------------------------------------------------------------- outer backoff --

// A provider-supplied Retry-After wins: it is the only party that knows when the
// quota window rolls over, and guessing shorter spends an attempt to be told the
// same thing again.
$report( 90 === $policy->outer_delay( 1, 90 ), 'Retry-After overrides the backoff schedule', 'got ' . $policy->outer_delay( 1, 90 ) );
$report( 60 === $policy->outer_delay( 1 ), 'first outer attempt without Retry-After waits 60s', 'got ' . $policy->outer_delay( 1 ) );
$report( 900 === $policy->outer_delay( 3 ), 'third outer attempt backs off to 900s', 'got ' . $policy->outer_delay( 3 ) );
$report( 900 === $policy->outer_delay( 99 ), 'backoff is clamped, not indexed past the end', 'got ' . $policy->outer_delay( 99 ) );
$report( 5 === $policy->outer_delay( 1, 1 ), 'a tiny Retry-After is floored so the loop cannot spin', 'got ' . $policy->outer_delay( 1, 1 ) );

$report( true === $policy->has_outer_attempts_left( 2 ), 'a third outer attempt is allowed' );
$report( false === $policy->has_outer_attempts_left( 3 ), 'a fourth outer attempt is not' );

// ---------------------------------------------------------------- time budget --

$report( 60 === $policy->budget( 0 ), 'unlimited max_execution_time assumes a proxy timeout', 'got ' . $policy->budget( 0 ) );
$report( 30 === $policy->budget( 30 ), 'a real limit is used as-is', 'got ' . $policy->budget( 30 ) );

// The reserve is the point: a call must never be allowed to consume the whole
// budget, or PHP is killed before the outcome can be recorded — and a job that
// dies mid-write is exactly what the queue exists to prevent.
$report( 20 === $policy->timeout( 30 ), 'a 30s budget gives a 20s attempt', 'got ' . $policy->timeout( 30 ) );
$report( 5 === $policy->timeout( 10 ), 'a 10s budget holds 5s back to record the outcome', 'got ' . $policy->timeout( 10 ) );
$report( $policy->timeout( 30 ) <= 30 - Retry_Policy::RESERVE, 'timeout always leaves the reserve' );

$report( false === $policy->can_attempt_within( 3 ), 'no attempt is started with 3s left' );
$report( true === $policy->can_attempt_within( 30 ), 'an attempt is started with 30s left' );

// ------------------------------------------------------------------ Secret_Box --

if ( ! extension_loaded( 'sodium' ) && ! function_exists( 'sodium_crypto_secretbox' ) ) {
	echo "\nSKIP  Secret_Box — no sodium. Run with: php -d extension=sodium tests/test-retry-policy.php\n";
	echo "      WordPress bundles sodium_compat, so this is a local toolchain gap, not a runtime one.\n";
	printf( "\n%d passed, %d failed\n", $passed, $failed );
	exit( $failed > 0 ? 1 : 0 );
}

$key    = Secret_Box::derive_key( str_repeat( 'salt-material-', 8 ) );
$secret = 'AIzaSy-not-a-real-key-0123456789';

$report( 32 === strlen( $key ), 'derived key is 32 bytes', 'got ' . strlen( $key ) );
$report( Secret_Box::decrypt( Secret_Box::encrypt( $secret, $key ), $key ) === $secret, 'round trip returns the original' );

// A fresh nonce per call, so the same secret never produces the same ciphertext.
// Identical output would leak that two sites share a key, and is the classic
// way this gets built wrong.
$report(
	Secret_Box::encrypt( $secret, $key ) !== Secret_Box::encrypt( $secret, $key ),
	'encrypting twice gives different ciphertext'
);

// Authenticated: a tampered payload must fail loudly, not decrypt to rubbish.
// This is the property openssl_encrypt would not have given us for free.
$tampered = Secret_Box::encrypt( $secret, $key );
// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- flipping a byte to prove the payload is authenticated.
$bytes                         = base64_decode( $tampered, true );
$bytes[ strlen( $bytes ) - 1 ] = chr( ord( $bytes[ strlen( $bytes ) - 1 ] ) ^ 0x01 );

try {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- re-encoding the tampered payload for the round trip.
	Secret_Box::decrypt( base64_encode( $bytes ), $key );
	$report( false, 'tampered ciphertext is rejected', 'it decrypted' );
} catch ( Provider_Exception $e ) {
	$report( 'secret_undecryptable' === $e->code_slug(), 'tampered ciphertext is rejected', 'got ' . $e->code_slug() );
}

// Rotating the WordPress salts changes the derived key. That must report as its
// own failure so the user is told to re-enter, not left with a provider that
// silently stopped working.
try {
	Secret_Box::decrypt( Secret_Box::encrypt( $secret, $key ), Secret_Box::derive_key( str_repeat( 'different-salts-', 4 ) ) );
	$report( false, 'a rotated salt reports as undecryptable', 'it decrypted' );
} catch ( Provider_Exception $e ) {
	$report( 'secret_undecryptable' === $e->code_slug(), 'a rotated salt reports as undecryptable', 'got ' . $e->code_slug() );
}

try {
	Secret_Box::decrypt( 'not base64 at all !!', $key );
	$report( false, 'corrupt payload is rejected', 'it decrypted' );
} catch ( Provider_Exception $e ) {
	$report( 'secret_corrupt' === $e->code_slug(), 'corrupt payload is rejected', 'got ' . $e->code_slug() );
}

try {
	Secret_Box::encrypt( $secret, 'too short' );
	$report( false, 'a wrong-length key is rejected', 'it encrypted' );
} catch ( Provider_Exception $e ) {
	$report( 'key_wrong_length' === $e->code_slug(), 'a wrong-length key is rejected', 'got ' . $e->code_slug() );
}

printf( "\n%d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
