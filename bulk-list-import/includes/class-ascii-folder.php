<?php
/**
 * ASCII folding for Latin-script text.
 *
 * One deterministic implementation, shared by everything that needs it: the
 * parser's duplicate key and the AI layer's slug validation. Deliberately *not*
 * WordPress's remove_accents(), and never behind a function_exists() branch — a
 * branch would mean the tested path is not the shipped path, and drift between
 * them would surface only as a duplicate slipping through in a real store.
 *
 * Free of WordPress functions so the fixture suites can run standalone.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BLI_STANDALONE' ) ) {
	exit;
}

/**
 * Folds accented Latin characters to their ASCII equivalents.
 */
final class Ascii_Folder {

	/**
	 * Latin-1 Supplement (U+00C0–U+00FF) and Latin Extended-A (U+0100–U+017F).
	 *
	 * INVARIANT: every key here is a two-byte UTF-8 sequence (U+0080–U+07FF), so
	 * no key can be a byte-prefix of another and strtr() matching is unambiguous.
	 * Latin Extended-B (U+0180–U+024F) stays two-byte and is safe to add. Anything
	 * from U+0800 up — Vietnamese precomposed forms in Latin Extended Additional
	 * (U+1E00–U+1EFF), for instance — is three bytes and breaks the invariant.
	 * That is survivable, because strtr() tries the longest keys first, but the
	 * uniform width is what makes the table verifiable by inspection. If you add
	 * wider sequences, say so here.
	 *
	 * If a gap appears, add a row here and a fixture line — same workflow as any
	 * other parser fix.
	 *
	 * @var array<string, string>
	 */
	// The table is laid out as a readable grid, six mappings per line. One item per
	// line would make it ~190 lines and destroy the property that makes it
	// verifiable: you can see at a glance that every key is two bytes and every
	// value is plain ASCII.
	// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine
	// phpcs:disable Universal.WhiteSpace.CommaSpacing.TooMuchSpaceAfter
	// phpcs:disable WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned
	private const TABLE = array(
		// Latin-1 Supplement.
		'À' => 'A',  'Á' => 'A',  'Â' => 'A',  'Ã' => 'A',  'Ä' => 'A',  'Å' => 'A',
		'Æ' => 'AE', 'Ç' => 'C',  'È' => 'E',  'É' => 'E',  'Ê' => 'E',  'Ë' => 'E',
		'Ì' => 'I',  'Í' => 'I',  'Î' => 'I',  'Ï' => 'I',  'Ð' => 'D',  'Ñ' => 'N',
		'Ò' => 'O',  'Ó' => 'O',  'Ô' => 'O',  'Õ' => 'O',  'Ö' => 'O',  'Ø' => 'O',
		'Ù' => 'U',  'Ú' => 'U',  'Û' => 'U',  'Ü' => 'U',  'Ý' => 'Y',  'Þ' => 'TH',
		'ß' => 'ss',
		'à' => 'a',  'á' => 'a',  'â' => 'a',  'ã' => 'a',  'ä' => 'a',  'å' => 'a',
		'æ' => 'ae', 'ç' => 'c',  'è' => 'e',  'é' => 'e',  'ê' => 'e',  'ë' => 'e',
		'ì' => 'i',  'í' => 'i',  'î' => 'i',  'ï' => 'i',  'ð' => 'd',  'ñ' => 'n',
		'ò' => 'o',  'ó' => 'o',  'ô' => 'o',  'õ' => 'o',  'ö' => 'o',  'ø' => 'o',
		'ù' => 'u',  'ú' => 'u',  'û' => 'u',  'ü' => 'u',  'ý' => 'y',  'þ' => 'th',
		'ÿ' => 'y',

		// Latin Extended-A.
		'Ā' => 'A',  'ā' => 'a',  'Ă' => 'A',  'ă' => 'a',  'Ą' => 'A',  'ą' => 'a',
		'Ć' => 'C',  'ć' => 'c',  'Ĉ' => 'C',  'ĉ' => 'c',  'Ċ' => 'C',  'ċ' => 'c',
		'Č' => 'C',  'č' => 'c',  'Ď' => 'D',  'ď' => 'd',  'Đ' => 'D',  'đ' => 'd',
		'Ē' => 'E',  'ē' => 'e',  'Ĕ' => 'E',  'ĕ' => 'e',  'Ė' => 'E',  'ė' => 'e',
		'Ę' => 'E',  'ę' => 'e',  'Ě' => 'E',  'ě' => 'e',  'Ĝ' => 'G',  'ĝ' => 'g',
		'Ğ' => 'G',  'ğ' => 'g',  'Ġ' => 'G',  'ġ' => 'g',  'Ģ' => 'G',  'ģ' => 'g',
		'Ĥ' => 'H',  'ĥ' => 'h',  'Ħ' => 'H',  'ħ' => 'h',  'Ĩ' => 'I',  'ĩ' => 'i',
		'Ī' => 'I',  'ī' => 'i',  'Ĭ' => 'I',  'ĭ' => 'i',  'Į' => 'I',  'į' => 'i',
		'İ' => 'I',  'ı' => 'i',  'Ĳ' => 'IJ', 'ĳ' => 'ij', 'Ĵ' => 'J',  'ĵ' => 'j',
		'Ķ' => 'K',  'ķ' => 'k',  'ĸ' => 'k',  'Ĺ' => 'L',  'ĺ' => 'l',  'Ļ' => 'L',
		'ļ' => 'l',  'Ľ' => 'L',  'ľ' => 'l',  'Ŀ' => 'L',  'ŀ' => 'l',  'Ł' => 'L',
		'ł' => 'l',  'Ń' => 'N',  'ń' => 'n',  'Ņ' => 'N',  'ņ' => 'n',  'Ň' => 'N',
		'ň' => 'n',  'ŉ' => 'n',  'Ŋ' => 'N',  'ŋ' => 'n',  'Ō' => 'O',  'ō' => 'o',
		'Ŏ' => 'O',  'ŏ' => 'o',  'Ő' => 'O',  'ő' => 'o',  'Œ' => 'OE', 'œ' => 'oe',
		'Ŕ' => 'R',  'ŕ' => 'r',  'Ŗ' => 'R',  'ŗ' => 'r',  'Ř' => 'R',  'ř' => 'r',
		'Ś' => 'S',  'ś' => 's',  'Ŝ' => 'S',  'ŝ' => 's',  'Ş' => 'S',  'ş' => 's',
		'Š' => 'S',  'š' => 's',  'Ţ' => 'T',  'ţ' => 't',  'Ť' => 'T',  'ť' => 't',
		'Ŧ' => 'T',  'ŧ' => 't',  'Ũ' => 'U',  'ũ' => 'u',  'Ū' => 'U',  'ū' => 'u',
		'Ŭ' => 'U',  'ŭ' => 'u',  'Ů' => 'U',  'ů' => 'u',  'Ű' => 'U',  'ű' => 'u',
		'Ų' => 'U',  'ų' => 'u',  'Ŵ' => 'W',  'ŵ' => 'w',  'Ŷ' => 'Y',  'ŷ' => 'y',
		'Ÿ' => 'Y',  'Ź' => 'Z',  'ź' => 'z',  'Ż' => 'Z',  'ż' => 'z',  'Ž' => 'Z',
		'ž' => 'z',  'ſ' => 's',
	);
	// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine, Universal.WhiteSpace.CommaSpacing.TooMuchSpaceAfter, WordPress.Arrays.MultipleStatementAlignment.DoubleArrowNotAligned

	/**
	 * Fold accented Latin characters to their ASCII equivalents.
	 *
	 * PHP note: strtr() with an array replaces the longest matching key first and
	 * never re-scans its own output, so it is safe for multi-byte keys and needs
	 * no mbstring extension.
	 *
	 * @param string $text Text to fold.
	 * @return string The text with accented Latin characters folded to ASCII.
	 */
	public static function fold( string $text ): string {
		return strtr( $text, self::TABLE );
	}
}
