<?php
/**
 * The JSON contract, enforced.
 *
 * This class is the only door between model output and the database. Nothing
 * reaches a product without passing through it, and it fails closed: anything it
 * cannot vouch for raises Invalid_Response_Exception with a stable code that the
 * Import Report turns into a specific human reason.
 *
 * Free of WordPress functions, so tests/test-response-validator.php runs
 * standalone. That is a boundary, not an inconvenience: **validation is shape,
 * sanitisation is WordPress's job at the write boundary.** This class will
 * happily return a long_description containing a <script> tag, because deciding
 * what HTML is permissible is wp_kses_post()'s call, made where the value is
 * written. Do not move kses in here; do not skip it out there.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

use BulkListImport\Ascii_Folder;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BLI_STANDALONE' ) ) {
	exit;
}

/**
 * Validates and normalises model responses against the JSON contract.
 */
final class Response_Validator {

	/**
	 * Decode depth cap. A contract-shaped response is three levels deep; anything
	 * far past that is malformed or hostile.
	 */
	private const MAX_DEPTH = 16;

	/**
	 * Keys that must be present, non-empty strings.
	 *
	 * @var string[]
	 */
	private const REQUIRED_STRINGS = array(
		'long_description',
		'short_description',
		'slug',
		'brand',
		'meta_description',
		'focus_keyword',
	);

	/**
	 * Per-field length caps, in bytes. Beyond these the response is rejected
	 * rather than truncated: silently storing half a description is worse than
	 * telling the user the row failed.
	 *
	 * @var array<string, int>
	 */
	private const MAX_LENGTHS = array(
		'long_description'  => 20000,
		'short_description' => 2000,
		'slug'              => 200,
		'brand'             => 200,
		'meta_description'  => 2000,
		'focus_keyword'     => 200,
	);

	/**
	 * Upper bound on attribute rows. Generous — a laptop legitimately has many —
	 * but not unbounded.
	 */
	private const MAX_ATTRIBUTES = 40;

	/**
	 * Validate a description response.
	 *
	 * @param string $raw Raw response body from the provider.
	 * @return array<string, mixed> The validated, normalised payload.
	 * @throws Invalid_Response_Exception If the response does not meet the contract.
	 */
	public function validate_description( string $raw ): array {
		$data = $this->decode( $raw );

		$out = array();

		foreach ( self::REQUIRED_STRINGS as $key ) {
			$out[ $key ] = $this->require_string( $data, $key );
		}

		$out['slug']             = $this->normalise_slug( $out['slug'] );
		$out['attributes']       = $this->require_attributes( $data );
		$out['uncertain_fields'] = $this->require_string_list( $data, 'uncertain_fields' );

		return $out;
	}

	/**
	 * Validate a recognition response.
	 *
	 * @param string             $raw      Raw response body from the provider.
	 * @param array<int, string> $expected Names that were asked about, in order.
	 * @return array<int, array<string, mixed>> One record per expected name, in order.
	 * @throws Invalid_Response_Exception If the response does not meet the contract.
	 */
	public function validate_recognition( string $raw, array $expected ): array {
		$data = $this->decode( $raw, true );

		// Some models wrap the list in an envelope. Accept the common shapes
		// rather than failing a response that is materially correct.
		if ( ! self::is_list( $data ) ) {
			foreach ( array( 'products', 'results', 'items' ) as $envelope ) {
				if ( isset( $data[ $envelope ] ) && is_array( $data[ $envelope ] ) ) {
					$data = $data[ $envelope ];
					break;
				}
			}
		}

		if ( ! is_array( $data ) || ! self::is_list( $data ) ) {
			throw new Invalid_Response_Exception(
				'recognition_not_list',
				'Recognition response is not a list of records.'
			);
		}

		// Index what came back by name so ordering drift cannot silently reassign
		// one product's judgement to another. Getting this wrong would mark a
		// product the model does not know as recognised, which is the exact
		// failure the gate exists to prevent.
		$by_name = array();

		foreach ( $data as $record ) {
			if ( ! is_array( $record ) || ! isset( $record['name'] ) || ! is_string( $record['name'] ) ) {
				continue;
			}

			$by_name[ $this->recognition_key( $record['name'] ) ] = $record;
		}

		$out = array();

		foreach ( $expected as $name ) {
			$record = $by_name[ $this->recognition_key( $name ) ] ?? null;

			if ( null === $record ) {
				throw new Invalid_Response_Exception(
					'recognition_missing_name',
					sprintf( 'No recognition record returned for "%s".', $name ),
					$name
				);
			}

			if ( ! isset( $record['known'] ) || ! is_bool( $record['known'] ) ) {
				throw new Invalid_Response_Exception(
					'recognition_known_not_bool',
					sprintf( 'Recognition record for "%s" has no boolean "known".', $name ),
					$name
				);
			}

			$confidence = $record['confidence'] ?? 0.0;

			if ( ! is_int( $confidence ) && ! is_float( $confidence ) ) {
				$confidence = 0.0;
			}

			$out[] = array(
				'name'             => $name,
				'known'            => $record['known'],
				'confidence'       => min( 1.0, max( 0.0, (float) $confidence ) ),
				'uncertain_fields' => $this->require_string_list( $record, 'uncertain_fields' ),
			);
		}

		return $out;
	}

	/**
	 * Extract and decode the JSON object from a raw response.
	 *
	 * @param string $raw       Raw response body.
	 * @param bool   $allow_list Whether a top-level array is acceptable.
	 * @return array<mixed> Decoded data.
	 * @throws Invalid_Response_Exception If no single well-formed JSON value is present.
	 */
	private function decode( string $raw, bool $allow_list = false ): array {
		$json = $this->extract_json( $raw, $allow_list );

		$data = json_decode( $json, true, self::MAX_DEPTH );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Invalid_Response_Exception(
				JSON_ERROR_DEPTH === json_last_error() ? 'json_too_deep' : 'invalid_json',
				'Could not decode response JSON: ' . json_last_error_msg()
			);
		}

		if ( ! is_array( $data ) ) {
			throw new Invalid_Response_Exception( 'not_an_object', 'Response JSON is not an object.' );
		}

		return $data;
	}

	/**
	 * Find exactly one top-level JSON value in a raw response.
	 *
	 * Models routinely wrap the payload in markdown fences or a conversational
	 * preamble, and that is not worth failing a row over. Two payloads is
	 * different: the model answered twice, and picking one would be a guess about
	 * which answer it meant.
	 *
	 * @param string $raw        Raw response body.
	 * @param bool   $allow_list Whether a top-level array is acceptable.
	 * @return string The JSON substring.
	 * @throws Invalid_Response_Exception If there is no payload, or more than one.
	 */
	private function extract_json( string $raw, bool $allow_list = false ): string {
		$text  = trim( $raw );
		$opens = $allow_list ? array( '{', '[' ) : array( '{' );

		$first = null;

		foreach ( $opens as $open ) {
			$at = strpos( $text, $open );

			if ( false !== $at && ( null === $first || $at < $first ) ) {
				$first = $at;
			}
		}

		if ( null === $first ) {
			throw new Invalid_Response_Exception(
				'no_json_found',
				'Response contains no JSON payload. The model answered in prose.'
			);
		}

		$end = $this->match_bracket( $text, $first );

		if ( null === $end ) {
			throw new Invalid_Response_Exception(
				'truncated_json',
				'JSON payload is unterminated. The response was probably cut off by a token limit.'
			);
		}

		// Anything that looks like a second payload after the first is ambiguous.
		$tail = trim( substr( $text, $end + 1 ) );
		$tail = trim( str_replace( array( '```', '`' ), '', $tail ) );

		if ( '' !== $tail && ( str_contains( $tail, '{' ) || str_contains( $tail, '[' ) ) ) {
			throw new Invalid_Response_Exception(
				'ambiguous_json',
				'Response contains more than one JSON payload; refusing to guess which one was meant.'
			);
		}

		return substr( $text, $first, $end - $first + 1 );
	}

	/**
	 * Find the offset of the bracket closing the one at $start, respecting
	 * strings and escapes.
	 *
	 * @param string $text  Text to scan.
	 * @param int    $start Offset of the opening bracket.
	 * @return int|null Offset of the matching close, or null if unterminated.
	 */
	private function match_bracket( string $text, int $start ): ?int {
		$pairs     = array(
			'{' => '}',
			'[' => ']',
		);
		$open      = $text[ $start ];
		$close     = $pairs[ $open ];
		$depth     = 0;
		$in_string = false;
		$escaped   = false;
		$length    = strlen( $text );

		for ( $i = $start; $i < $length; $i++ ) {
			$char = $text[ $i ];

			if ( $in_string ) {
				if ( $escaped ) {
					$escaped = false;
				} elseif ( '\\' === $char ) {
					$escaped = true;
				} elseif ( '"' === $char ) {
					$in_string = false;
				}

				continue;
			}

			if ( '"' === $char ) {
				$in_string = true;
				continue;
			}

			if ( $char === $open ) {
				++$depth;
			} elseif ( $char === $close ) {
				--$depth;

				if ( 0 === $depth ) {
					return $i;
				}
			}
		}

		return null;
	}

	/**
	 * Require a present, non-empty string of acceptable length.
	 *
	 * Strict about type on purpose. PHP would happily coerce 12345 to "12345",
	 * and a model that returned a number where the contract says string has
	 * misunderstood the contract — that is worth surfacing, not papering over.
	 *
	 * @param array<mixed> $data Decoded payload.
	 * @param string       $key  Field name.
	 * @return string The trimmed value.
	 * @throws Invalid_Response_Exception If missing, wrong type, empty or over-long.
	 */
	private function require_string( array $data, string $key ): string {
		if ( ! array_key_exists( $key, $data ) ) {
			throw new Invalid_Response_Exception( 'missing_field', sprintf( 'Required field "%s" is missing.', $key ), $key );
		}

		if ( ! is_string( $data[ $key ] ) ) {
			throw new Invalid_Response_Exception(
				'wrong_type',
				sprintf( 'Field "%s" is %s, expected string.', $key, gettype( $data[ $key ] ) ),
				$key
			);
		}

		$value = trim( $data[ $key ] );

		if ( '' === $value ) {
			throw new Invalid_Response_Exception( 'empty_field', sprintf( 'Field "%s" is empty.', $key ), $key );
		}

		$max = self::MAX_LENGTHS[ $key ] ?? 20000;

		if ( strlen( $value ) > $max ) {
			throw new Invalid_Response_Exception(
				'field_too_long',
				sprintf( 'Field "%s" is %d bytes, over the %d byte cap.', $key, strlen( $value ), $max ),
				$key
			);
		}

		return $value;
	}

	/**
	 * Validate the attributes block.
	 *
	 * The contract is a *list* of {label, value} pairs, and it is deliberately
	 * generic — tasting notes for a drink, specs for a laptop, dosage for a
	 * pharmacy item. The model decides what suits the category, which is exactly
	 * why the common drift is returning a map keyed by label instead. Reject it:
	 * accepting both shapes would mean two code paths downstream, and hardcoding
	 * expected labels to disambiguate is the one thing this field must never do.
	 *
	 * @param array<mixed> $data Decoded payload.
	 * @return array<int, array{label: string, value: string}>
	 * @throws Invalid_Response_Exception If the block is not a well-formed list.
	 */
	private function require_attributes( array $data ): array {
		if ( ! array_key_exists( 'attributes', $data ) ) {
			throw new Invalid_Response_Exception( 'missing_field', 'Required field "attributes" is missing.', 'attributes' );
		}

		$raw = $data['attributes'];

		if ( ! is_array( $raw ) ) {
			throw new Invalid_Response_Exception(
				'wrong_type',
				sprintf( 'Field "attributes" is %s, expected a list.', gettype( $raw ) ),
				'attributes'
			);
		}

		// An empty attributes block is legitimate: a plain product may have
		// nothing worth tabulating, and inventing rows to fill it is the failure
		// mode this whole layer exists to prevent.
		if ( array() === $raw ) {
			return array();
		}

		if ( ! self::is_list( $raw ) ) {
			throw new Invalid_Response_Exception(
				'attributes_not_list',
				'Field "attributes" is a keyed map, expected a list of {label, value} pairs.',
				'attributes'
			);
		}

		if ( count( $raw ) > self::MAX_ATTRIBUTES ) {
			throw new Invalid_Response_Exception(
				'too_many_attributes',
				sprintf( 'Field "attributes" has %d rows, over the %d cap.', count( $raw ), self::MAX_ATTRIBUTES ),
				'attributes'
			);
		}

		$out = array();

		foreach ( $raw as $index => $item ) {
			if ( ! is_array( $item ) ) {
				throw new Invalid_Response_Exception(
					'attribute_item_shape',
					sprintf( 'Attribute %d is %s, expected an object.', $index, gettype( $item ) ),
					'attributes'
				);
			}

			foreach ( array( 'label', 'value' ) as $part ) {
				if ( ! isset( $item[ $part ] ) || ! is_string( $item[ $part ] ) || '' === trim( $item[ $part ] ) ) {
					throw new Invalid_Response_Exception(
						'attribute_item_shape',
						sprintf( 'Attribute %d has no non-empty string "%s".', $index, $part ),
						'attributes'
					);
				}
			}

			$out[] = array(
				'label' => trim( $item['label'] ),
				'value' => trim( $item['value'] ),
			);
		}

		return $out;
	}

	/**
	 * Validate a list-of-strings field, treating absence as empty.
	 *
	 * An absent uncertain_fields is not an error — a model with nothing to hedge
	 * should say nothing. A model returning it as a bare string is an error,
	 * because "abv" and ["abv"] mean different things to the caller, and guessing
	 * which was meant is how one hedge silently becomes three.
	 *
	 * @param array<mixed> $data Decoded payload.
	 * @param string       $key  Field name.
	 * @return array<int, string>
	 * @throws Invalid_Response_Exception If present but not a list of strings.
	 */
	private function require_string_list( array $data, string $key ): array {
		if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
			return array();
		}

		$raw = $data[ $key ];

		if ( ! is_array( $raw ) || ( array() !== $raw && ! self::is_list( $raw ) ) ) {
			throw new Invalid_Response_Exception(
				'wrong_type',
				sprintf( 'Field "%s" is %s, expected a list of strings.', $key, gettype( $raw ) ),
				$key
			);
		}

		$out = array();

		foreach ( $raw as $item ) {
			if ( ! is_string( $item ) ) {
				throw new Invalid_Response_Exception(
					'wrong_type',
					sprintf( 'Field "%s" contains a %s, expected strings.', $key, gettype( $item ) ),
					$key
				);
			}

			$item = trim( $item );

			if ( '' !== $item ) {
				$out[] = $item;
			}
		}

		return $out;
	}

	/**
	 * Whether an array is a list: sequential integer keys from zero.
	 *
	 * Not array_is_list(), which is PHP 8.1+ and — unlike str_contains() — is not
	 * polyfilled by WordPress, so it is unavailable on the floor anywhere in this
	 * plugin, not merely in the WordPress-free classes.
	 *
	 * @param array<mixed> $value Array to test.
	 */
	private static function is_list( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Normalise a slug to something URL-safe.
	 *
	 * Folds accents first, using the same table the parser's duplicate key uses.
	 * Stripping non-ASCII without folding would turn "rémy-martin-1738" into
	 * "rmy-martin-1738" — a silently wrong URL rather than an obviously wrong one.
	 *
	 * Quotes and apostrophes are deleted rather than turned into separators, which
	 * is what WordPress's own sanitize_title() does: "Levi's" should slug as
	 * "levis", not "levi-s". Everything else non-alphanumeric collapses to a
	 * single hyphen, so `&`, `/`, `.` and `"` all behave.
	 *
	 * @param string $slug Slug as returned by the model.
	 * @return string A URL-safe slug.
	 * @throws Invalid_Response_Exception If nothing usable survives normalisation.
	 */
	private function normalise_slug( string $slug ): string {
		$folded = Ascii_Folder::fold( $slug );

		$folded = str_replace( array( "'", '"', '`', '’', '‘', '“', '”' ), '', $folded );

		$out = strtolower( $folded );
		$out = (string) preg_replace( '/[^a-z0-9]+/', '-', $out );
		$out = trim( $out, '-' );

		if ( '' === $out ) {
			// Two different failures land here and they need different reasons,
			// because they need different fixes. Punctuation-only means the model
			// sent nothing usable and the row is worth re-running. A name written
			// wholly in a script the fold table does not cover — Cyrillic, Arabic,
			// CJK — is nobody's mistake and re-running cannot help; that user needs
			// to set the slug by hand.
			if ( preg_match( '/[\p{L}\p{N}]/u', $slug ) ) {
				throw new Invalid_Response_Exception(
					'slug_not_transliterable',
					'Field "slug" has no Latin-script characters to build a URL from.',
					'slug'
				);
			}

			throw new Invalid_Response_Exception(
				'empty_field',
				'Field "slug" contains nothing URL-safe.',
				'slug'
			);
		}

		return $out;
	}

	/**
	 * Key used to match a returned recognition record to a requested name.
	 *
	 * Folded and stripped, so trivial echo differences — a smart quote, a changed
	 * accent, different spacing — do not orphan a record.
	 *
	 * @param string $name Product name.
	 */
	private function recognition_key( string $name ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9]/i', '', Ascii_Folder::fold( $name ) ) );
	}
}
