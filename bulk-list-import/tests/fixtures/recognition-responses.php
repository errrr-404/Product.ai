<?php
/**
 * Golden fixtures for Response_Validator::validate_recognition().
 *
 * Separate from the adversarial set in recognition-adversarial.json, which tests
 * the *model's* judgement against a live API. These test our handling of what
 * comes back, and run offline.
 *
 * `asked` is the list of names sent, in order. `expect` is 'ok' or the stable
 * code the validator must raise. `assert` optionally checks the normalised
 * output.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

return array(

	array(
		'label'  => 'straightforward list',
		'why'    => 'The baseline shape.',
		'asked'  => array( 'Dell XPS 13 9340', 'Cape More' ),
		'expect' => 'ok',
		'assert' => array(
			array(
				'name'             => 'Dell XPS 13 9340',
				'known'            => true,
				'uncertain_fields' => array( 'exact processor variant' ),
			),
			array(
				'name'             => 'Cape More',
				'known'            => false,
				'uncertain_fields' => array(),
			),
		),
		'raw'    => <<<'RAW'
[
  { "name": "Dell XPS 13 9340", "known": true, "confidence": 0.9, "uncertain_fields": ["exact processor variant"] },
  { "name": "Cape More", "known": false, "confidence": 0.1, "uncertain_fields": [] }
]
RAW
		,
	),

	array(
		'label'  => 'records returned out of order',
		'why'    => 'The case that matters most. Matching by position instead of by name would hand Cape More the judgement meant for the Dell — marking a product the model does not know as recognised, which is precisely the failure the gate exists to prevent.',
		'asked'  => array( 'Dell XPS 13 9340', 'Cape More' ),
		'expect' => 'ok',
		'assert' => array(
			array(
				'name'  => 'Dell XPS 13 9340',
				'known' => true,
			),
			array(
				'name'  => 'Cape More',
				'known' => false,
			),
		),
		'raw'    => <<<'RAW'
[
  { "name": "Cape More", "known": false, "confidence": 0.1, "uncertain_fields": [] },
  { "name": "Dell XPS 13 9340", "known": true, "confidence": 0.9, "uncertain_fields": [] }
]
RAW
		,
	),

	array(
		'label'  => 'wrapped in an envelope object',
		'why'    => 'Common when a model is told to return JSON and reaches for an object at the top level. Materially correct, so accept it.',
		'asked'  => array( 'Cape More' ),
		'expect' => 'ok',
		'assert' => array(
			array(
				'name'  => 'Cape More',
				'known' => false,
			),
		),
		'raw'    => <<<'RAW'
{
  "products": [
    { "name": "Cape More", "known": false, "confidence": 0.05, "uncertain_fields": [] }
  ]
}
RAW
		,
	),

	array(
		'label'  => 'accent drift between request and response',
		'why'    => 'The model echoes the name with the accent stripped. Matching on the raw string would orphan the record and fail the row for no real reason.',
		'asked'  => array( 'Volcán Añejo D.M.' ),
		'expect' => 'ok',
		'assert' => array(
			array(
				'name'  => 'Volcán Añejo D.M.',
				'known' => true,
			),
		),
		'raw'    => <<<'RAW'
[
  { "name": "Volcan Anejo D.M.", "known": true, "confidence": 0.7, "uncertain_fields": ["finish", "maturation period"] }
]
RAW
		,
	),

	array(
		'label'  => 'a requested name is missing from the response',
		'why'    => 'A dropped name is a row with no judgement. Silently defaulting it either way is wrong: default to known and you generate copy for an unknown product; default to blocked and you block a product the model knew. Fail the batch and say so.',
		'asked'  => array( 'Dell XPS 13 9340', 'Cape More' ),
		'expect' => 'recognition_missing_name',
		'raw'    => <<<'RAW'
[
  { "name": "Dell XPS 13 9340", "known": true, "confidence": 0.9, "uncertain_fields": [] }
]
RAW
		,
	),

	array(
		'label'  => 'known returned as the string "true"',
		'why'    => 'PHP treats the non-empty string "true" as truthy — and would treat "false" as truthy too. A loose check here turns every blocked product into a recognised one.',
		'asked'  => array( 'Cape More' ),
		'expect' => 'recognition_known_not_bool',
		'raw'    => <<<'RAW'
[
  { "name": "Cape More", "known": "false", "confidence": 0.1, "uncertain_fields": [] }
]
RAW
		,
	),

	array(
		'label'  => 'confidence out of range',
		'why'    => 'Clamped rather than rejected. A confidence of 95 instead of 0.95 is a scale mistake, not a judgement we should throw away — and known is what actually gates the row.',
		'asked'  => array( 'Cape More' ),
		'expect' => 'ok',
		'assert' => array(
			array(
				'name'       => 'Cape More',
				'known'      => false,
				'confidence' => 1.0,
			),
		),
		'raw'    => <<<'RAW'
[
  { "name": "Cape More", "known": false, "confidence": 95, "uncertain_fields": [] }
]
RAW
		,
	),

	array(
		'label'  => 'not a list at all',
		'why'    => 'A single object where a list was asked for, with no recognisable envelope.',
		'asked'  => array( 'Cape More' ),
		'expect' => 'recognition_not_list',
		'raw'    => <<<'RAW'
{ "name": "Cape More", "known": false, "confidence": 0.1, "uncertain_fields": [] }
RAW
		,
	),
);
