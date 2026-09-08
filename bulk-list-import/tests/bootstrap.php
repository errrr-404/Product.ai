<?php
/**
 * Standalone test bootstrap.
 *
 * The classes loaded here are deliberately free of WordPress functions, so the
 * fixture suites run in milliseconds with no WordPress boot, no database and no
 * network. That property is worth protecting: if a class under test starts
 * needing WordPress, do not add a function_exists() branch to get it back — the
 * branch would mean the tested path is no longer the shipped path. Move the
 * WordPress-dependent step to the caller instead.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

define( 'BLI_STANDALONE', true );

require_once __DIR__ . '/../includes/class-ascii-folder.php';
require_once __DIR__ . '/../includes/class-parser.php';
require_once __DIR__ . '/../includes/ai/class-invalid-response-exception.php';
require_once __DIR__ . '/../includes/ai/class-response-validator.php';
require_once __DIR__ . '/../includes/ai/class-prompt.php';
require_once __DIR__ . '/../includes/ai/class-prompt-builder.php';
require_once __DIR__ . '/../includes/ai/class-provider-exception.php';
require_once __DIR__ . '/../includes/ai/class-retry-policy.php';
require_once __DIR__ . '/../includes/ai/class-secret-box.php';
require_once __DIR__ . '/../includes/ai/interface-description-provider.php';
require_once __DIR__ . '/class-fake-provider.php';
