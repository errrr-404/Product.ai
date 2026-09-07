<?php
/**
 * Where the API key lives, and what that actually protects.
 *
 * WordPress has no key management. A secret encrypted with a key derived from
 * the salts is defeated by the same filesystem access that reveals
 * wp-config.php, so this is not a substitute for a constant. What it does stop
 * is a database-only compromise — SQL injection, a leaked backup, a staging dump
 * handed to a contractor — which is the common case.
 *
 * Say that plainly wherever the UI mentions it. Never the word "encrypted" on
 * its own.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the provider API key.
 */
final class API_Key_Store {

	/**
	 * Option holding encrypted keys, indexed by provider id.
	 */
	private const OPTION = 'bli_api_keys';

	/**
	 * Provider id, e.g. "gemini".
	 *
	 * @var string
	 */
	private string $provider;

	/**
	 * Build a store for one provider.
	 *
	 * @param string $provider Provider id.
	 */
	public function __construct( string $provider ) {
		$this->provider = $provider;
	}

	/**
	 * The constant a site owner can define in wp-config.php, e.g. BLI_GEMINI_API_KEY.
	 */
	public function constant_name(): string {
		return 'BLI_' . strtoupper( preg_replace( '/[^a-z0-9]/i', '_', $this->provider ) ?? '' ) . '_API_KEY';
	}

	/**
	 * Whether the key comes from wp-config.php rather than the database.
	 *
	 * The settings screen uses this to lock the field: offering an input that
	 * silently loses to a constant would be a small cruelty.
	 */
	public function is_from_constant(): bool {
		$name = $this->constant_name();

		return defined( $name ) && '' !== (string) constant( $name );
	}

	/**
	 * Whether a key is available at all.
	 */
	public function has_key(): bool {
		if ( $this->is_from_constant() ) {
			return true;
		}

		$stored = $this->stored();

		return '' !== $stored;
	}

	/**
	 * The API key.
	 *
	 * @return string The key.
	 * @throws Provider_Exception If no key is configured, or the stored one cannot be decrypted.
	 */
	public function get(): string {
		if ( $this->is_from_constant() ) {
			return (string) constant( $this->constant_name() );
		}

		$stored = $this->stored();

		if ( '' === $stored ) {
			throw new Provider_Exception(
				'key_missing',
				sprintf( 'No API key configured for provider "%s".', $this->provider )
			);
		}

		return Secret_Box::decrypt( $stored, $this->key() );
	}

	/**
	 * Store a key, encrypted. An empty string deletes it.
	 *
	 * @param string $api_key The key to store.
	 * @throws Provider_Exception If the key cannot be encrypted.
	 */
	public function set( string $api_key ): void {
		$keys = get_option( self::OPTION, array() );

		if ( ! is_array( $keys ) ) {
			$keys = array();
		}

		$api_key = trim( $api_key );

		if ( '' === $api_key ) {
			unset( $keys[ $this->provider ] );
		} else {
			$keys[ $this->provider ] = Secret_Box::encrypt( $api_key, $this->key() );
		}

		// Not autoloaded: this is read on the few requests that call the provider,
		// not on every page of the site.
		update_option( self::OPTION, $keys, false );
	}

	/**
	 * A masked form safe to render.
	 *
	 * The key itself is never sent back to the browser — not in a value
	 * attribute, not in a data attribute, not in an AJAX payload. A field the
	 * user can overwrite but not read is the whole design.
	 */
	public function masked(): string {
		if ( $this->is_from_constant() ) {
			return sprintf(
				/* translators: %s: PHP constant name. */
				__( 'Set in wp-config.php as %s', 'bulk-list-import' ),
				$this->constant_name()
			);
		}

		try {
			$key = $this->get();
		} catch ( Provider_Exception $e ) {
			return 'key_missing' === $e->code_slug()
				? __( 'Not set', 'bulk-list-import' )
				: __( 'Stored key is unreadable — enter it again', 'bulk-list-import' );
		}

		$tail = strlen( $key ) > 4 ? substr( $key, -4 ) : '';

		return str_repeat( '•', 8 ) . $tail;
	}

	/**
	 * The raw stored ciphertext for this provider, or an empty string.
	 */
	private function stored(): string {
		$keys = get_option( self::OPTION, array() );

		if ( ! is_array( $keys ) ) {
			return '';
		}

		return (string) ( $keys[ $this->provider ] ?? '' );
	}

	/**
	 * The encryption key, derived from the WordPress salts.
	 *
	 * Binding to the salts means a database lifted on its own is useless without
	 * wp-config.php. It also means rotating the salts invalidates stored keys —
	 * deliberately, and Secret_Box reports that as a distinct failure so the user
	 * is told to re-enter rather than left with a provider that silently stopped
	 * working.
	 *
	 * @throws Provider_Exception If the salts are missing or left at their defaults.
	 */
	private function key(): string {
		$material = '';

		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT' ) as $constant ) {
			if ( defined( $constant ) ) {
				$material .= (string) constant( $constant );
			}
		}

		// A fresh wp-config.php ships these as "put your unique phrase here". If
		// the site never replaced them, every install shares the same key and the
		// encryption is theatre. Refuse rather than pretend.
		if ( str_contains( $material, 'put your unique phrase here' ) ) {
			throw new Provider_Exception(
				'salts_not_set',
				'WordPress salts are still at their placeholder values; refusing to derive an encryption key from them.'
			);
		}

		return Secret_Box::derive_key( $material );
	}
}
