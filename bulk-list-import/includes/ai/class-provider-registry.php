<?php
/**
 * Which providers exist, and how to build the configured one.
 *
 * The one place that knows a concrete provider class. Everything else asks for
 * a Description_Provider and gets whichever the user chose — which is what keeps
 * "add OpenAI" a matter of writing a class and adding a line here, and what
 * leaves the door open for a hosted implementation later, for users who cannot
 * easily get an international-billing card for an API key.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport\AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds providers from settings.
 */
final class Provider_Registry {

	/**
	 * How long a fetched model list stays cached. Model catalogues change on a
	 * vendor's release schedule, not on page load.
	 */
	private const MODELS_TTL = DAY_IN_SECONDS;

	/**
	 * Providers available to choose from.
	 *
	 * @return array<string, string> Provider id => human label.
	 */
	public static function available(): array {
		/**
		 * Filters the list of selectable AI providers.
		 *
		 * @param array<string, string> $providers Provider id => label.
		 */
		return (array) apply_filters(
			'bli_ai_providers',
			array( 'gemini' => __( 'Google Gemini', 'bulk-list-import' ) )
		);
	}

	/**
	 * The provider id currently selected.
	 */
	public static function selected_id(): string {
		$id = (string) get_option( 'bli_ai_provider', 'gemini' );

		return isset( self::available()[ $id ] ) ? $id : 'gemini';
	}

	/**
	 * Build the configured provider.
	 *
	 * @param string $provider_id Provider to build; defaults to the selected one.
	 * @return Description_Provider
	 * @throws Provider_Exception If no key is configured, or the provider is unknown.
	 */
	public static function make( string $provider_id = '' ): Description_Provider {
		$provider_id = '' !== $provider_id ? $provider_id : self::selected_id();

		if ( ! isset( self::available()[ $provider_id ] ) ) {
			throw new Provider_Exception(
				'unknown_provider',
				sprintf( 'No implementation registered for provider "%s".', $provider_id )
			);
		}

		$key = ( new API_Key_Store( $provider_id ) )->get();

		$prompts = new Prompt_Builder(
			array(
				'industry' => (string) get_option( 'bli_ai_industry', '' ),
				'tone'     => (string) get_option( 'bli_ai_tone', 'plain' ),
				'length'   => (string) get_option( 'bli_ai_length', 'medium' ),
				'template' => (string) get_option( 'bli_ai_template', '' ),
			)
		);

		/**
		 * Filters the constructed provider, so an add-on can register its own.
		 *
		 * @param Description_Provider|null $provider    Provider, or null to use the built-in.
		 * @param string                    $provider_id Provider id.
		 * @param string                    $key         API key.
		 * @param Prompt_Builder            $prompts     Configured prompt builder.
		 */
		$provider = apply_filters( 'bli_ai_provider_instance', null, $provider_id, $key, $prompts );

		if ( $provider instanceof Description_Provider ) {
			return $provider;
		}

		return new Gemini_Provider( $key, (string) get_option( 'bli_ai_model', '' ), $prompts );
	}

	/**
	 * Models offered for a provider, asked of the provider and cached.
	 *
	 * A hardcoded catalogue is the thing that rots: vendors retire models on their
	 * own schedule, and a plugin nobody has touched starts returning 404. So the
	 * live list is the source of truth, the cache keeps it to one call a day, and
	 * the provider's own short constant is the floor when the call fails.
	 *
	 * Never throws. A settings screen that cannot render because a remote call
	 * failed would be a worse failure than a slightly stale dropdown.
	 *
	 * @param string $provider_id Provider id.
	 * @param bool   $force       Ignore the cache and re-ask.
	 * @return array{models: array<string, string>, stale: bool, error: string}
	 */
	public static function models( string $provider_id = '', bool $force = false ): array {
		$provider_id = '' !== $provider_id ? $provider_id : self::selected_id();
		$cache_key   = 'bli_models_' . md5( $provider_id );

		if ( ! $force ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) && array() !== $cached ) {
				return array(
					'models' => $cached,
					'stale'  => false,
					'error'  => '',
				);
			}
		}

		try {
			$models = self::make( $provider_id )->list_models();

			set_transient( $cache_key, $models, self::MODELS_TTL );

			return array(
				'models' => $models,
				'stale'  => false,
				'error'  => '',
			);
		} catch ( Provider_Exception $e ) {
			return array(
				'models' => self::fallback_models( $provider_id ),
				'stale'  => true,
				'error'  => $e->code_slug(),
			);
		}
	}

	/**
	 * Drop a cached model list, so the next read re-asks.
	 *
	 * @param string $provider_id Provider id.
	 */
	public static function forget_models( string $provider_id ): void {
		delete_transient( 'bli_models_' . md5( $provider_id ) );
	}

	/**
	 * The provider's own short fallback list.
	 *
	 * @param string $provider_id Provider id.
	 * @return array<string, string>
	 */
	private static function fallback_models( string $provider_id ): array {
		if ( 'gemini' === $provider_id ) {
			return Gemini_Provider::FALLBACK_MODELS;
		}

		return array();
	}
}
