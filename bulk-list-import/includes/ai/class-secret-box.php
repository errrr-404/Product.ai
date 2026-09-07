<?php
/**
 * Authenticated symmetric encryption for stored secrets.
 *
 * Built on sodium_crypto_secretbox rather than openssl_encrypt: it is
 * authenticated, so a tampered ciphertext fails loudly instead of decrypting to
 * rubbish, and it has no IV-reuse footgun to get wrong. WordPress bundles
 * sodium_compat, so these functions exist on every install — there is no
 * function_exists() branch here and there must not be one, because a branch
 * would mean the tested path is not the shipped path.
 *
 * Free of WordPress functions, so the round trip is covered by a real test
 * rather than asserted in a comment.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

if ( ! defined( 'ABSPATH' ) && ! defined( 'BLI_STANDALONE' ) ) {
	exit;
}

/**
 * Encrypts and decrypts short secrets with a caller-supplied key.
 */
final class Secret_Box {

	/**
	 * Derive a 32-byte key from arbitrary material.
	 *
	 * @param string $material Key material — for this plugin, the WordPress salts.
	 * @return string A 32-byte key.
	 * @throws Provider_Exception If the material is too weak to derive from.
	 */
	public static function derive_key( string $material ): string {
		if ( strlen( $material ) < 32 ) {
			throw new Provider_Exception(
				'key_material_weak',
				'Key material is too short to derive an encryption key from.'
			);
		}

		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt a secret.
	 *
	 * A fresh random nonce per call, stored alongside the ciphertext. Nonces are
	 * not secret; reusing one would be the failure, and generating one per call
	 * makes that impossible.
	 *
	 * @param string $plaintext Secret to encrypt.
	 * @param string $key       32-byte key from derive_key().
	 * @return string Base64 of nonce + ciphertext.
	 * @throws Provider_Exception If the key is the wrong length.
	 */
	public static function encrypt( string $plaintext, string $key ): string {
		self::assert_key( $key );

		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- not obfuscation: secretbox output is binary and wp_options is a text column.
		return base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a secret.
	 *
	 * @param string $encoded Base64 of nonce + ciphertext, as returned by encrypt().
	 * @param string $key     32-byte key from derive_key().
	 * @return string The plaintext.
	 * @throws Provider_Exception If the key is wrong, or the payload is corrupt or tampered with.
	 */
	public static function decrypt( string $encoded, string $key ): string {
		self::assert_key( $key );

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- not obfuscation: secretbox output is binary and wp_options is a text column.
		$raw = base64_decode( $encoded, true );

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new Provider_Exception( 'secret_corrupt', 'Stored secret is not a valid encrypted payload.' );
		}

		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );

		if ( false === $plaintext ) {
			// Authentication failed. Either the salts changed — which is a real
			// scenario, people rotate them — or the row was tampered with. Both
			// mean the same thing to the user: the stored key is unusable and has
			// to be entered again.
			throw new Provider_Exception(
				'secret_undecryptable',
				'Stored secret could not be decrypted. The WordPress salts may have changed.'
			);
		}

		return $plaintext;
	}

	/**
	 * Reject a key of the wrong length before sodium does, with a clearer message.
	 *
	 * @param string $key Key to check.
	 * @throws Provider_Exception If the key is not exactly SODIUM_CRYPTO_SECRETBOX_KEYBYTES long.
	 */
	private static function assert_key( string $key ): void {
		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			throw new Provider_Exception(
				'key_wrong_length',
				sprintf(
					'Encryption key must be %d bytes, got %d.',
					SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
					strlen( $key )
				)
			);
		}
	}
}
