<?php
/**
 * Gate_Policy regression suite.
 *
 * The gate's safety properties are the kind a refactor breaks without anything
 * failing: an unavailable check quietly reading as a pass, a verdict landing on
 * the wrong product. Neither shows up as an error — both show up as a shop full
 * of confident, invented product copy — so they are pinned here.
 *
 * Run with:  php tests/test-gate-policy.php
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

require_once __DIR__ . '/bootstrap.php';

use BulkListImport\AI\Gate_Policy;
use BulkListImport\AI\Invalid_Response_Exception;

$policy = new Gate_Policy();
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

/**
 * Build a parsed row.
 *
 * @param string $type       Row type.
 * @param string $name       Product name.
 * @param bool   $importable Whether the parser considers it importable.
 * @return array<string, mixed>
 */
function bli_row( string $type, string $name, bool $importable = true ): array {
	return array(
		'type'       => $type,
		'name'       => $name,
		'importable' => $importable,
	);
}

// ---------------------------------------------------------------- candidates --

$rows = array(
	0 => bli_row( 'heading', 'ELECTRONICS', false ),
	1 => bli_row( 'product', 'Dell XPS 13 9340' ),
	2 => bli_row( 'product', 'Cape More' ),
	3 => bli_row( 'product', 'Grey Goose', false ),
	4 => bli_row( 'product', '' ),
);

$candidates = $policy->candidates( $rows );

$report( array( 1, 2 ) === array_keys( $candidates ), 'only importable named products are candidates', 'got ' . implode( ',', array_keys( $candidates ) ) );
$report( ! isset( $candidates[0] ), 'a heading is never asked about' );
$report( ! isset( $candidates[3] ), 'a duplicate is never asked about' );
$report( ! isset( $candidates[4] ), 'a row with no name is never asked about' );

// ----------------------------------------------------------------- the cap --

$many = array();

for ( $i = 0; $i < 40; $i++ ) {
	$many[ $i ] = 'Product ' . $i;
}

$split = $policy->partition( $many, array(), 25 );

$report( 25 === count( $split['batch'] ), 'the batch is capped', 'got ' . count( $split['batch'] ) );
$report( 15 === count( $split['deferred'] ), 'the remainder is deferred, not dropped', 'got ' . count( $split['deferred'] ) );
$report( array() === array_intersect_key( $split['batch'], $split['deferred'] ), 'no row is both asked and deferred' );
$report(
	count( $split['batch'] ) + count( $split['deferred'] ) === count( $many ),
	'every candidate lands in exactly one bucket'
);

// A cached verdict spends nothing. Re-previewing after fixing one row is the
// common case, and re-asking about unchanged names would be pure spend.
$cached = array( 0 => $policy->verdict( Gate_Policy::READY ) );
$split  = $policy->partition( $many, $cached, 25 );

$report( ! isset( $split['batch'][0] ), 'a cached row is not asked about again' );
$report( isset( $split['resolved'][0] ), 'a cached row is resolved from cache' );

// Junk in the cache must not be trusted as a verdict.
$split = $policy->partition( array( 0 => 'X' ), array( 0 => array( 'state' => 'nonsense' ) ), 25 );
$report( isset( $split['batch'][0] ), 'an unrecognised cached state is re-asked, not honoured' );

// ------------------------------------------------------- records to verdicts --

$report( Gate_Policy::READY === $policy->from_record( array( 'known' => true ) )['state'], 'known:true is READY' );
$report( Gate_Policy::BLOCKED === $policy->from_record( array( 'known' => false ) )['state'], 'known:false is BLOCKED' );

// Fails closed. The validator already rejects these shapes, so this is a second
// lock on the same door — justified by the asymmetry: a wrongly blocked row costs
// a panel to fill in, a wrongly recognised row costs a fabricated description.
foreach ( array(
	'missing'      => array(),
	'string true'  => array( 'known' => 'true' ),
	'string false' => array( 'known' => 'false' ),
	'integer one'  => array( 'known' => 1 ),
	'null'         => array( 'known' => null ),
) as $label => $record ) {
	$report(
		Gate_Policy::BLOCKED === $policy->from_record( $record )['state'],
		sprintf( 'known as %s fails closed to BLOCKED', $label )
	);
}

$report(
	1.0 === $policy->from_record(
		array(
			'known'      => true,
			'confidence' => 95,
		)
	)['confidence'],
	'confidence is clamped'
);

// ------------------------------------------------- matching by name, not slot --

$batch = array(
	7 => 'Dell XPS 13 9340',
	9 => 'Cape More',
);

// The case that matters. Matching by position would hand Cape More the Dell's
// verdict — a recognised judgement on a product the model has never heard of.
$scrambled = array(
	array(
		'name'  => 'Cape More',
		'known' => false,
	),
	array(
		'name'  => 'Dell XPS 13 9340',
		'known' => true,
	),
);

$resolved = $policy->resolve( $batch, $scrambled );

$report( Gate_Policy::READY === $resolved[7]['state'], 'out-of-order records still land on the right row' );
$report( Gate_Policy::BLOCKED === $resolved[9]['state'], 'the unknown product stays blocked when order differs' );

// Accent drift between request and reply must not orphan a record.
$resolved = $policy->resolve(
	array( 3 => 'Volcán Añejo D.M.' ),
	array(
		array(
			'name'  => 'Volcan Anejo D.M.',
			'known' => true,
		),
	)
);
$report( Gate_Policy::READY === $resolved[3]['state'], 'accent drift still matches' );

// A missing record is fatal rather than skipped. If this ever starts returning
// short, judge() would misassign every verdict after the gap.
try {
	$policy->resolve(
		$batch,
		array(
			array(
				'name'  => 'Dell XPS 13 9340',
				'known' => true,
			),
		)
	);
	$report( false, 'a missing record throws', 'it returned' );
} catch ( Invalid_Response_Exception $e ) {
	$report( 'recognition_missing_name' === $e->code_slug(), 'a missing record throws', 'got ' . $e->code_slug() );
}

// An extra record nobody asked about cannot cause a misassignment, so it is
// ignored rather than treated as an error.
$resolved = $policy->resolve(
	array( 1 => 'Cape More' ),
	array(
		array(
			'name'  => 'Cape More',
			'known' => false,
		),
		array(
			'name'  => 'Something Nobody Asked About',
			'known' => true,
		),
	)
);
$report( 1 === count( $resolved ) && Gate_Policy::BLOCKED === $resolved[1]['state'], 'an unrequested extra record is ignored' );

// -------------------------------------------- UNAVAILABLE never becomes a pass --

// The safety property. An unavailable check is not a judgement, and no path may
// turn it into one.
$unavailable = $policy->unavailable( 'rate_limited' );

$report( Gate_Policy::UNAVAILABLE === $unavailable['state'], 'unavailable is its own state' );
$report( Gate_Policy::READY !== $unavailable['state'], 'unavailable is never READY' );
$report( ! $policy->is_cacheable( $unavailable ), 'unavailable is never cached' );
$report( ! $policy->is_cacheable( $policy->unchecked() ), 'unchecked is never cached' );
$report( $policy->is_cacheable( $policy->from_record( array( 'known' => true ) ) ), 'a real verdict is cached' );

// Merging must not upgrade. A row already marked unavailable or blocked cannot be
// quietly promoted by a later pass.
$base = array( 0 => bli_row( 'product', 'Cape More' ) );

$merged = $policy->apply( $policy->apply( $base, array( 0 => $unavailable ) ), array( 0 => $policy->verdict( Gate_Policy::READY ) ) );
$report( Gate_Policy::UNAVAILABLE === $merged[0]['gate']['state'], 'READY cannot overwrite UNAVAILABLE' );

$blocked = $policy->from_record( array( 'known' => false ) );
$merged  = $policy->apply( $policy->apply( $base, array( 0 => $blocked ) ), array( 0 => $policy->verdict( Gate_Policy::READY ) ) );
$report( Gate_Policy::BLOCKED === $merged[0]['gate']['state'], 'READY cannot overwrite BLOCKED' );

$merged = $policy->apply( $policy->apply( $base, array( 0 => $policy->unchecked() ) ), array( 0 => $unavailable ) );
$report( Gate_Policy::UNAVAILABLE === $merged[0]['gate']['state'], 'UNAVAILABLE overwrites UNCHECKED' );

// Junk cannot be applied at all.
$merged = $policy->apply( $base, array( 0 => array( 'state' => 'made-up' ) ) );
$report( ! isset( $merged[0]['gate'] ), 'an unrecognised state is not applied' );

// ------------------------------------------------------------- importability --

$report( ! $policy->permits_import( Gate_Policy::BLOCKED ), 'blocked rows withhold the checkbox' );
$report( $policy->permits_import( Gate_Policy::READY ), 'ready rows are selectable' );

// The gate not having run is not grounds to stop someone importing — the free
// tier has no gate at all — but neither state is ever recorded as READY.
$report( $policy->permits_import( Gate_Policy::UNCHECKED ), 'unchecked rows stay selectable' );
$report( $policy->permits_import( Gate_Policy::UNAVAILABLE ), 'unavailable rows stay selectable' );

// --------------------------------------------------------------- cache keys --

$a = $policy->cache_key( 'gemini', 'gemini-3.5-flash', 'Rémy Martin 1738' );

$report( $a === $policy->cache_key( 'gemini', 'gemini-3.5-flash', 'Remy Martin 1738' ), 'accent variants share a cache key' );
$report( $a !== $policy->cache_key( 'gemini', 'gemini-3.1-flash-lite', 'Rémy Martin 1738' ), 'a different model gets a different key' );
$report( $a !== $policy->cache_key( 'openai', 'gemini-3.5-flash', 'Rémy Martin 1738' ), 'a different provider gets a different key' );

printf( "\n%d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
