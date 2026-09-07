<?php
/**
 * Raw-text parser. This is the core value of the plugin; everything else is plumbing.
 *
 * Deliberately free of WordPress function calls so it can be exercised by
 * tests/test-parser-fixtures.php without loading WordPress.
 *
 * The parser has no concept of product category. It matches units, price-like
 * numbers, delimiters and leftover text, so any industry runs through the same
 * code path.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BLI_PARSER_STANDALONE' ) ) {
	exit;
}

/**
 * Turns pasted lines into structured rows.
 */
class Parser {

	/**
	 * Units that mark a number as a specification rather than a price.
	 *
	 * This list is generic on purpose: volume is only one family among many, and
	 * a row with no unit at all is completely normal.
	 *
	 * @var string[]
	 */
	private const UNITS = array(
		// Volume.
		'cl', 'ml', 'l', 'ltr', 'litre', 'litres', 'liter', 'liters', 'gal',
		// Weight.
		'kg', 'g', 'mg', 'lb', 'lbs', 'oz',
		// Digital capacity.
		'gb', 'tb', 'mb', 'kb',
		// Length.
		'mm', 'cm', 'm', 'inch', 'inches', 'ft',
		// Power, electrical, display.
		'mah', 'wh', 'kwh', 'kw', 'w', 'v', 'hz', 'ghz', 'mhz', 'mp', 'ppi', 'rpm',
		// Duration — warranty periods, age statements, course lengths.
		'years', 'year', 'yrs', 'yr', 'months', 'month', 'days', 'day',
		// Count nouns.
		'pack', 'packs', 'pcs', 'pc', 'piece', 'pieces', 'ct', 'count',
		'tablets', 'tablet', 'caps', 'capsule', 'capsules', 'sachets', 'sachet',
		'sheets', 'sheet', 'rolls', 'roll', 'bag', 'bags', 'box', 'boxes', 'tabs',
		'units', 'unit', 'pairs', 'pair', 'set', 'sets', 'bottles', 'bottle',
		'cans', 'can', 'tins', 'tin', 'servings', 'doses', 'dose',
	);

	/**
	 * Column labels. An exact match on every delimited token means the line is a header.
	 *
	 * @var string[]
	 */
	private const COLUMN_LABELS = array(
		'product', 'products', 'name', 'item', 'items', 'title', 'description',
		'desc', 'price', 'cost', 'amount', 'value', 'size', 'sizes', 'cl', 'ml',
		'volume', 'unit', 'units', 'qty', 'quantity', 'sku', 'code', 'category',
		'brand', 'variant', 'spec', 'specs', 'stock', 'weight', 'no', 's/n',
	);

	/**
	 * Currency symbols and ISO codes recognised next to a number.
	 */
	private const CURRENCY = '₦|\$|€|£|¥|₹|NGN|USD|GBP|EUR|ZAR|KES|GHS';

	/**
	 * Column separators. A spaced hyphen counts; an unspaced one does not, so
	 * "Coca-Cola" and "16GB/512GB" survive intact. Commas are never separators,
	 * because "Office Chair, Ergonomic Mesh" is one product name.
	 */
	private const DELIMITERS = '\s*(?:—|–|\||\t|(?<=\s)-(?=\s))\s*';

	/**
	 * Parse pasted text into rows.
	 *
	 * @param string $text Raw pasted text.
	 * @return array<int, array<string, mixed>> One row per non-blank input line.
	 */
	public function parse( string $text ): array {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", $text );
		$lines = explode( "\n", $text );
		$rows  = array();

		foreach ( $lines as $index => $line ) {
			$row = $this->parse_line( $line, $index + 1 );
			if ( 'blank' !== $row['type'] ) {
				$rows[] = $row;
			}
		}

		return $this->flag_duplicates( $rows );
	}

	/**
	 * Parse a single line.
	 *
	 * @param string $raw     The raw line.
	 * @param int    $lineno  1-based line number in the pasted text.
	 * @return array<string, mixed>
	 */
	public function parse_line( string $raw, int $lineno ): array {
		// PHP note: an array does double duty as List and Map. This is the Map form —
		// a record, closer to a Java DTO than to a java.util.List.
		$row = array(
			'line'         => $lineno,
			'raw'          => $raw,
			'type'         => 'product',
			'name'         => '',
			'variant'      => '',
			'price'        => null,
			'currency'     => '',
			'confidence'   => 1.0,
			'flags'        => array(),
			'duplicate_of' => null,
			'importable'   => true,
		);

		$text = trim( $raw );

		if ( '' === $text ) {
			$row['type']       = 'blank';
			$row['importable'] = false;
			return $row;
		}

		if ( $this->is_heading( $text ) ) {
			$row['type']       = 'heading';
			$row['name']       = $text;
			$row['confidence'] = 0.0;
			$row['flags'][]    = 'heading';
			$row['importable'] = false;
			return $row;
		}

		// Strip list numbering. Requires digit + "." or ")" + space, which is what
		// protects names that legitimately begin with digits: 818, 4th, 501.
		$text = (string) preg_replace( '/^\d+[.)]\s+/u', '', $text );

		$specs  = array();
		$masked = $this->mask_specs( $text, $specs );

		$segments = preg_split( '/' . self::DELIMITERS . '/u', $masked, -1, PREG_SPLIT_NO_EMPTY );
		$segments = array_values( array_filter( (array) $segments, static fn( $s ) => '' !== trim( (string) $s ) ) );

		if ( array() === $segments ) {
			$row['type']       = 'blank';
			$row['importable'] = false;
			return $row;
		}

		$price = $this->find_price( $segments );

		if ( null !== $price ) {
			$row['price']    = $price['value'];
			$row['currency'] = $price['symbol'];
		}

		$name_segment  = $segments[0];
		$variant_parts = array();

		foreach ( $segments as $index => $segment ) {
			if ( 0 === $index ) {
				continue;
			}
			if ( null !== $price && $price['segment'] === $index ) {
				$segment = substr( $segment, 0, $price['offset'] ) . ' ' . substr( $segment, $price['offset'] + $price['length'] );
			}
			$segment = $this->trim_edges( $segment );
			if ( '' !== $segment ) {
				$variant_parts[] = $segment;
			}
		}

		if ( null !== $price && 0 === $price['segment'] ) {
			$name_segment = substr( $name_segment, 0, $price['offset'] ) . ' ' . substr( $name_segment, $price['offset'] + $price['length'] );
		}
		$name_segment = $this->trim_edges( (string) preg_replace( '/\s{2,}/u', ' ', $name_segment ) );

		// No separate variant column? Peel a trailing run of specs off the name,
		// so "Paracetamol 500mg x 100 tablets" splits but "Samsung 55\" QLED TV"
		// — which ends in a word, not a spec — does not.
		if ( array() === $variant_parts ) {
			$run = '';
			$this->split_trailing_specs( $name_segment, $run );
			if ( '' !== $run ) {
				$variant_parts[] = $run;
			}
		}

		$row['name']    = trim( $this->unmask( $name_segment, $specs ) );
		$row['variant'] = trim( $this->unmask( implode( ' ', $variant_parts ), $specs ) );

		if ( '' === $row['name'] ) {
			$row['flags'][]     = 'no_name';
			$row['confidence'] -= 0.6;
			$row['importable']  = false;
		}

		if ( null === $row['price'] ) {
			$row['flags'][]     = 'no_price';
			$row['confidence'] -= 0.4;
		} elseif ( 0 === $price['segment'] && count( $segments ) > 1 && '' === $price['symbol'] && ! $price['grouped'] ) {
			// A bare number sitting inside the name while other columns exist is the
			// shakiest kind of price match. Import it, but say so.
			$row['flags'][]     = 'price_uncertain';
			$row['confidence'] -= 0.3;
		}

		$row['confidence'] = round( max( 0.0, $row['confidence'] ), 2 );

		return $row;
	}

	/**
	 * Strip whitespace, dashes and colons from both ends.
	 *
	 * PHP note: trim() with a character list works on *bytes*, so passing an em
	 * dash there would shred any adjacent multi-byte character — "₦" and "—"
	 * share a leading byte. A UTF-8-aware regex is the only safe way to do this.
	 */
	private function trim_edges( string $text ): string {
		return (string) preg_replace( '/(?:^[\s\-–—:]+)|(?:[\s\-–—:]+$)/u', '', $text );
	}

	/**
	 * Header detection, deliberately conservative: real products *are* single
	 * words with no digits ("Element"), so headers are flagged, never silently
	 * deleted.
	 *
	 * @param string $text Trimmed line.
	 */
	private function is_heading( string $text ): bool {
		$parts = preg_split( '/[\/|,\t]|\s+-\s+|—|–/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$parts = array_filter( array_map( 'trim', (array) $parts ), static fn( $p ) => '' !== $p );

		if ( array() !== $parts ) {
			$all_labels = true;
			foreach ( $parts as $part ) {
				if ( ! in_array( strtolower( (string) $part ), self::COLUMN_LABELS, true ) ) {
					$all_labels = false;
					break;
				}
			}
			if ( $all_labels ) {
				return true;
			}
		}

		if ( preg_match( '/\d/u', $text ) ) {
			return false;
		}

		$letters = (string) preg_replace( '/[^A-Za-z]/u', '', $text );

		// preg_match_all( '/./u' ) counts characters, not bytes, without needing
		// the mbstring extension to be present.
		$length = (int) preg_match_all( '/./u', $text );

		return '' !== $letters
			&& $letters === strtoupper( $letters )
			&& $length <= 40
			&& count( preg_split( '/\s+/u', $text ) ?: array() ) <= 4;
	}

	/**
	 * Replace every specification with a letters-only placeholder.
	 *
	 * Numbers attached to a recognised unit are specs, and are masked *before*
	 * price detection so they can never be read as a price. Placeholders contain
	 * no digits, so the price scan cannot see through them.
	 *
	 * @param string               $text  Line to mask.
	 * @param array<int, string> $specs Filled with the masked spec texts, by reference.
	 * @return string Masked line.
	 */
	private function mask_specs( string $text, array &$specs ): string {
		$specs = array();

		$add = static function ( string $value ) use ( &$specs ): string {
			$specs[] = trim( $value );
			return self::placeholder( count( $specs ) - 1 );
		};

		$units = self::UNITS;
		usort( $units, static fn( $a, $b ) => strlen( $b ) <=> strlen( $a ) );
		$unit_pattern = implode( '|', array_map( 'preg_quote', $units ) );

		// 1. Number + unit: 70cl, 500mg, 256GB, 20000mAh, 30 Years, 100 tablets, 5kg.
		$text = (string) preg_replace_callback(
			'/(?<![A-Za-z0-9])\d+(?:[.,]\d+)?\s*(?:' . $unit_pattern . ')(?![A-Za-z0-9])/iu',
			static fn( array $m ) => $add( $m[0] ),
			$text
		);

		// 2. Inch symbol: 55".
		$text = (string) preg_replace_callback(
			'/(?<![A-Za-z0-9])\d+(?:\.\d+)?\s*"/u',
			static fn( array $m ) => $add( $m[0] ),
			$text
		);

		// 3. Regional apparel sizing: UK 9, EU 42.
		$text = (string) preg_replace_callback(
			'/\b(?:UK|EU|US|IT|FR|AU)\s?\d{1,2}(?:\.5)?\b/u',
			static fn( array $m ) => $add( $m[0] ),
			$text
		);

		// 4. Waist / leg sizing: W32 L34.
		$text = (string) preg_replace_callback(
			'/\b[WL]\d{2}\b/u',
			static fn( array $m ) => $add( $m[0] ),
			$text
		);

		// 5. A parenthetical that already contains a spec becomes one group, so
		//    "(50kg bag)" and "(60 caps)" stay together as a single variant.
		$text = (string) preg_replace_callback(
			'/\(([^()]*§[A-Z]+§[^()]*)\)/u',
			static fn( array $m ) => $add( $m[1] ),
			$text
		);

		return $text;
	}

	/**
	 * Build a letters-only placeholder for spec index $index (0 -> §A§, 26 -> §BA§).
	 */
	private static function placeholder( int $index ): string {
		$out = '';
		$n   = $index;
		do {
			$out = chr( 65 + ( $n % 26 ) ) . $out;
			$n   = intdiv( $n, 26 );
		} while ( $n > 0 );

		return '§' . $out . '§';
	}

	/**
	 * Restore masked specs. Recursive, because a parenthetical spec group holds
	 * placeholders of its own.
	 *
	 * @param string             $text  Masked text.
	 * @param array<int, string> $specs Spec texts by index.
	 */
	private function unmask( string $text, array $specs ): string {
		$replace = static function ( array $m ) use ( $specs ): string {
			$index = 0;
			foreach ( str_split( $m[1] ) as $char ) {
				$index = $index * 26 + ( ord( $char ) - 65 );
			}
			return $specs[ $index ] ?? $m[0];
		};

		for ( $pass = 0; $pass < 8; $pass++ ) {
			$next = (string) preg_replace_callback( '/§([A-Z]+)§/u', $replace, $text );
			if ( $next === $text ) {
				break;
			}
			$text = $next;
		}

		return $text;
	}

	/**
	 * Score every number-like candidate and return the best price, or null.
	 *
	 * Scoring rather than "take the last number" is what protects 1738, 270, 501
	 * and 9340 from being read as prices.
	 *
	 * @param array<int, string> $segments Masked, delimiter-split segments.
	 * @return array<string, mixed>|null
	 */
	private function find_price( array $segments ): ?array {
		$pattern = '/(?P<pre>' . self::CURRENCY . ')?\s*'
			. '(?<![A-Za-z0-9.,])(?P<num>\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)(?![A-Za-z])'
			. '\s*(?P<post>' . self::CURRENCY . ')?/iu';

		$best = null;

		foreach ( $segments as $index => $segment ) {
			if ( ! preg_match_all( $pattern, (string) $segment, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$number = $match['num'][0];
				$value  = $this->to_number( $number );

				if ( null === $value || $value <= 0.0 ) {
					continue;
				}

				$symbol  = '';
				if ( isset( $match['pre'] ) && '' !== $match['pre'][0] ) {
					$symbol = $match['pre'][0];
				} elseif ( isset( $match['post'] ) && '' !== $match['post'][0] ) {
					$symbol = $match['post'][0];
				}

				$grouped = 1 === preg_match( '/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/', $number );

				$score = 0.0;
				if ( '' !== $symbol ) {
					$score += 100.0;          // Currency symbol is the strongest signal.
				}
				if ( $grouped ) {
					$score += 50.0;           // Thousands separator is the next strongest.
				}
				$score += $index * 10.0;                        // Later column wins.
				$score += $match[0][1] / 1000.0;                // Later within the column.
				$score += min( log10( $value ), 8.0 ) / 10.0;    // Magnitude, tiebreak only.

				if ( null === $best || $score > $best['score'] ) {
					$best = array(
						'score'   => $score,
						'value'   => $value,
						'segment' => $index,
						'offset'  => $match[0][1],
						'length'  => strlen( $match[0][0] ),
						'symbol'  => $symbol,
						'grouped' => $grouped,
					);
				}
			}
		}

		return $best;
	}

	/**
	 * Convert a matched number string to a float.
	 *
	 * Handles "2,260,000", "8000", "999", "1,250.50" and the European "1.250,50"
	 * by treating whichever of "," or "." appears last as the decimal separator.
	 */
	private function to_number( string $raw ): ?float {
		$text = str_replace( ' ', '', $raw );

		$has_comma = str_contains( $text, ',' );
		$has_dot   = str_contains( $text, '.' );

		if ( $has_comma && $has_dot ) {
			$text = strrpos( $text, ',' ) > strrpos( $text, '.' )
				? str_replace( ',', '.', str_replace( '.', '', $text ) )
				: str_replace( ',', '', $text );
		} elseif ( $has_comma ) {
			$text = preg_match( '/^\d{1,3}(?:,\d{3})+$/', $text )
				? str_replace( ',', '', $text )
				: str_replace( ',', '.', $text );
		}

		return is_numeric( $text ) ? (float) $text : null;
	}

	/**
	 * Split a trailing run of spec placeholders off the end of the name segment.
	 *
	 * A bare "x" between two specs is absorbed, so "500mg x 100 tablets" comes
	 * off whole.
	 *
	 * @param string $segment Name segment; trimmed in place.
	 * @param string $run     Receives the trailing spec run, by reference.
	 */
	private function split_trailing_specs( string &$segment, string &$run ): void {
		$tokens = preg_split( '/\s+/u', $segment, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		$cursor = count( $tokens );

		while ( $cursor > 0 ) {
			$token = $tokens[ $cursor - 1 ];

			if ( preg_match( '/^§[A-Z]+§$/u', $token ) ) {
				--$cursor;
				continue;
			}

			if ( 'x' === strtolower( $token )
				&& $cursor - 1 > 0
				&& preg_match( '/^§[A-Z]+§$/u', $tokens[ $cursor - 2 ] ) ) {
				--$cursor;
				continue;
			}

			break;
		}

		// Nothing peeled, or the whole segment is specs (which would leave no name).
		if ( count( $tokens ) === $cursor || 0 === $cursor ) {
			$run = '';
			return;
		}

		$run     = implode( ' ', array_slice( $tokens, $cursor ) );
		$segment = implode( ' ', array_slice( $tokens, 0, $cursor ) );
	}

	/**
	 * Flag repeated name + variant pairs. The first occurrence wins; later ones
	 * point back at it and are not importable.
	 *
	 * @param array<int, array<string, mixed>> $rows Parsed rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function flag_duplicates( array $rows ): array {
		$seen = array();

		foreach ( $rows as $index => $row ) {
			if ( 'product' !== $row['type'] ) {
				continue;
			}

			$key = strtolower( (string) preg_replace( '/[^a-z0-9]/iu', '', $row['name'] . '|' . $row['variant'] ) );

			if ( '' === $key ) {
				continue;
			}

			if ( isset( $seen[ $key ] ) ) {
				$rows[ $index ]['flags'][]     = 'duplicate';
				$rows[ $index ]['duplicate_of'] = $seen[ $key ];
				$rows[ $index ]['importable']   = false;
			} else {
				$seen[ $key ] = $row['line'];
			}
		}

		return $rows;
	}
}
