<?php
/**
 * The AI settings screen.
 *
 * Everything here is Pro. The free tier — parsing, product creation, SKU
 * sequencing — needs no key and no settings, and this screen renders an upgrade
 * notice rather than a form when bli_is_pro() is false. Gating from the first
 * commit rather than retrofitting it later is deliberate.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

use BulkListImport\AI\API_Key_Store;
use BulkListImport\AI\Prompt_Builder;
use BulkListImport\AI\Provider_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and saves the AI settings.
 */
class Settings_Page {

	public const SLUG       = 'bli-settings';
	public const GROUP      = 'bli_settings';
	private const KEY_FIELD = 'bli_api_key_input';

	/**
	 * Tones offered. Free text would be more flexible, but these map to words the
	 * model reliably understands; the custom template is the escape hatch.
	 *
	 * @return array<string, string>
	 */
	public static function tones(): array {
		return array(
			'plain'     => __( 'Plain', 'bulk-list-import' ),
			'premium'   => __( 'Premium', 'bulk-list-import' ),
			'technical' => __( 'Technical', 'bulk-list-import' ),
			'playful'   => __( 'Playful', 'bulk-list-import' ),
		);
	}

	/**
	 * Description lengths offered.
	 *
	 * @return array<string, string>
	 */
	public static function lengths(): array {
		return array(
			'short'  => __( 'Short — around 60 words', 'bulk-list-import' ),
			'medium' => __( 'Medium — around 130 words', 'bulk-list-import' ),
			'long'   => __( 'Long — around 220 words', 'bulk-list-import' ),
		);
	}

	/**
	 * Register the submenu entry.
	 */
	public function register_menu(): void {
		add_submenu_page(
			Admin_Page::PARENT,
			__( 'Bulk Import Settings', 'bulk-list-import' ),
			__( 'Bulk Import Settings', 'bulk-list-import' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register settings and their sanitisers.
	 *
	 * Every field gets an explicit sanitize_callback. The Settings API will happily
	 * store whatever it is given otherwise.
	 */
	public function register_settings(): void {
		$defaults = Prompt_Builder::defaults();

		register_setting(
			self::GROUP,
			'bli_ai_provider',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_provider' ),
				'default'           => 'gemini',
			)
		);

		register_setting(
			self::GROUP,
			'bli_ai_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_model' ),
				'default'           => '',
			)
		);

		register_setting(
			self::GROUP,
			'bli_ai_industry',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => (string) $defaults['industry'],
			)
		);

		register_setting(
			self::GROUP,
			'bli_ai_tone',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_tone' ),
				'default'           => (string) $defaults['tone'],
			)
		);

		register_setting(
			self::GROUP,
			'bli_ai_length',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_length' ),
				'default'           => (string) $defaults['length'],
			)
		);

		register_setting(
			self::GROUP,
			'bli_ai_template',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => (string) $defaults['template'],
			)
		);

		// The key is deliberately not a registered setting. It never round-trips
		// through a form value, so it cannot be autosaved, exported, or echoed back
		// by anything that walks the option list.
		register_setting(
			self::GROUP,
			self::KEY_FIELD,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'capture_api_key' ),
				'default'           => '',
			)
		);
	}

	/**
	 * Keep the provider to one we actually implement.
	 *
	 * @param mixed $value Submitted value.
	 */
	public function sanitize_provider( $value ): string {
		$value = sanitize_key( (string) $value );

		return isset( Provider_Registry::available()[ $value ] ) ? $value : 'gemini';
	}

	/**
	 * Accept any model-shaped string, but confirm the shape.
	 *
	 * Deliberately not validated against the fetched list. That list is a cache of
	 * a remote call, and rejecting a model because our copy is a day old would make
	 * the plugin hardest to fix at exactly the moment a vendor retires something. A
	 * wrong name surfaces as HTTP 404 with a reason pointing back here, which is a
	 * better failure than a silently ignored setting.
	 *
	 * The shape still matters, because the value is interpolated into the request
	 * path: /v1beta/models/{model}:generateContent. A "/" or a ".." would rewrite
	 * that path. rawurlencode() at the call site already escapes the separator, so
	 * this is defence in depth rather than a live hole — but a constrained shape is
	 * cheaper to reason about than an escaping guarantee two files away, and
	 * rejecting outright beats silently stripping a name into something the user
	 * never typed.
	 *
	 * @param mixed $value Submitted value.
	 */
	public function sanitize_model( $value ): string {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $value ) || str_contains( $value, '..' ) ) {
			add_settings_error(
				self::GROUP,
				'bli_bad_model',
				sprintf(
					/* translators: %s: the rejected model name. */
					__( '"%s" is not a valid model name, so the model setting was left unchanged.', 'bulk-list-import' ),
					$value
				)
			);

			return (string) get_option( 'bli_ai_model', '' );
		}

		return $value;
	}

	/**
	 * Keep the tone to one of the offered values.
	 *
	 * @param mixed $value Submitted value.
	 */
	public function sanitize_tone( $value ): string {
		$value = sanitize_key( (string) $value );

		return isset( self::tones()[ $value ] ) ? $value : 'plain';
	}

	/**
	 * Keep the length to one of the offered values.
	 *
	 * @param mixed $value Submitted value.
	 */
	public function sanitize_length( $value ): string {
		$value = sanitize_key( (string) $value );

		return isset( self::lengths()[ $value ] ) ? $value : 'medium';
	}

	/**
	 * Divert a submitted key into the encrypted store, and save nothing here.
	 *
	 * An empty submission means "leave it alone", not "delete it" — the field
	 * renders empty on every load because the key is never sent to the browser, so
	 * treating empty as a delete would wipe the key every time someone saved an
	 * unrelated setting. Clearing is an explicit checkbox.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Always empty: nothing about the key is stored in this option.
	 */
	public function capture_api_key( $value ): string {
		$provider = Provider_Registry::selected_id();
		$store    = new API_Key_Store( $provider );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verified the nonce before calling this sanitiser.
		$clear = isset( $_POST['bli_clear_api_key'] );

		if ( $clear ) {
			$store->set( '' );
			Provider_Registry::forget_models( $provider );

			return '';
		}

		$submitted = trim( sanitize_text_field( (string) $value ) );

		if ( '' !== $submitted ) {
			try {
				$store->set( $submitted );

				// A new key may see a different catalogue, and the old cache would
				// otherwise hide that for a day.
				Provider_Registry::forget_models( $provider );
			} catch ( \BulkListImport\AI\Provider_Exception $e ) {
				add_settings_error(
					self::GROUP,
					'bli_key_not_stored',
					sprintf(
						/* translators: %s: error detail. */
						__( 'The API key could not be stored: %s', 'bulk-list-import' ),
						$e->getMessage()
					)
				);
			}
		}

		return '';
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'bulk-list-import' ) );
		}

		echo '<div class="wrap bli-wrap">';
		echo '<h1>' . esc_html__( 'Bulk Import Settings', 'bulk-list-import' ) . '</h1>';

		if ( ! bli_is_pro() ) {
			echo '<div class="notice notice-info inline"><p>';
			echo esc_html__(
				'AI descriptions and the recognition gate are Pro features. Parsing, product creation and SKU sequencing work without any of these settings.',
				'bulk-list-import'
			);
			echo '</p></div>';
			echo '</div>';
			return;
		}

		$provider = Provider_Registry::selected_id();
		$store    = new API_Key_Store( $provider );

		settings_errors( self::GROUP );

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::GROUP );

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->render_provider_row( $provider );
		$this->render_key_row( $store );
		$this->render_model_row( $provider, $store );
		$this->render_industry_row();
		$this->render_choice_row( 'bli_ai_tone', __( 'Tone', 'bulk-list-import' ), self::tones(), 'plain' );
		$this->render_choice_row( 'bli_ai_length', __( 'Description length', 'bulk-list-import' ), self::lengths(), 'medium' );
		$this->render_template_row();

		echo '</tbody></table>';

		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Provider select.
	 *
	 * @param string $provider Selected provider id.
	 */
	private function render_provider_row( string $provider ): void {
		echo '<tr><th scope="row"><label for="bli-provider">' . esc_html__( 'Provider', 'bulk-list-import' ) . '</label></th><td>';
		echo '<select id="bli-provider" name="bli_ai_provider">';

		foreach ( Provider_Registry::available() as $id => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $id ),
				selected( $id, $provider, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
		echo '</td></tr>';
	}

	/**
	 * API key field. Write-only.
	 *
	 * @param API_Key_Store $store Key store for the selected provider.
	 */
	private function render_key_row( API_Key_Store $store ): void {
		echo '<tr><th scope="row"><label for="bli-api-key">' . esc_html__( 'API key', 'bulk-list-import' ) . '</label></th><td>';

		if ( $store->is_from_constant() ) {
			echo '<p><code>' . esc_html( $store->masked() ) . '</code></p>';
			echo '<p class="description">'
				. esc_html__( 'Defined in wp-config.php, which takes precedence. Remove the constant to manage the key here.', 'bulk-list-import' )
				. '</p>';
			echo '</td></tr>';
			return;
		}

		printf(
			'<p><strong>%s</strong> %s</p>',
			esc_html__( 'Current:', 'bulk-list-import' ),
			esc_html( $store->masked() )
		);

		// autocomplete="new-password" stops browsers offering to fill or remember
		// this the way they would a login field.
		printf(
			'<input type="password" id="bli-api-key" name="%s" value="" class="regular-text" autocomplete="new-password" spellcheck="false" />',
			esc_attr( self::KEY_FIELD )
		);

		echo '<p class="description">'
			. esc_html__( 'Leave blank to keep the key you already have. The key is never displayed again after saving.', 'bulk-list-import' )
			. '</p>';

		if ( $store->has_key() ) {
			echo '<p><label><input type="checkbox" name="bli_clear_api_key" value="1" /> '
				. esc_html__( 'Delete the stored key', 'bulk-list-import' ) . '</label></p>';
		}

		echo '<p class="description">'
			. esc_html__(
				'Stored encrypted in your database. This protects against database leaks and backup exposure, but not against filesystem access. For strongest protection, define the key in wp-config.php:',
				'bulk-list-import'
			)
			. '</p>';

		printf(
			'<p><code>define( \'%s\', \'your-key-here\' );</code></p>',
			esc_html( $store->constant_name() )
		);

		echo '</td></tr>';
	}

	/**
	 * Model select, populated from the provider.
	 *
	 * @param string        $provider Provider id.
	 * @param API_Key_Store $store    Key store, to explain an empty list.
	 */
	private function render_model_row( string $provider, API_Key_Store $store ): void {
		$current = (string) get_option( 'bli_ai_model', '' );

		echo '<tr><th scope="row"><label for="bli-model">' . esc_html__( 'Model', 'bulk-list-import' ) . '</label></th><td>';

		if ( ! $store->has_key() ) {
			echo '<p class="description">'
				. esc_html__( 'Add an API key and save, then the model list will load from the provider.', 'bulk-list-import' )
				. '</p></td></tr>';
			return;
		}

		$result = Provider_Registry::models( $provider );
		$models = $result['models'];

		// A model that is set but no longer listed still has to be selectable, or
		// saving an unrelated setting would silently switch the model.
		if ( '' !== $current && ! isset( $models[ $current ] ) ) {
			$models = array( $current => $current . ' ' . __( '(not in the provider list)', 'bulk-list-import' ) ) + $models;
		}

		echo '<select id="bli-model" name="bli_ai_model">';
		printf(
			'<option value="" %s>%s</option>',
			selected( '', $current, false ),
			esc_html__( 'Use the plugin default', 'bulk-list-import' )
		);

		foreach ( $models as $id => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $id ),
				selected( (string) $id, $current, false ),
				esc_html( (string) $label )
			);
		}

		echo '</select>';

		if ( $result['stale'] ) {
			echo '<p class="description bli-warning-inline">'
				. esc_html__(
					'Could not reach the provider for the current model list, so a short built-in list is shown. It may be out of date — vendors retire models regularly.',
					'bulk-list-import'
				)
				. '</p>';
		} else {
			echo '<p class="description">'
				. esc_html__( 'Loaded from the provider and cached for a day.', 'bulk-list-import' )
				. '</p>';
		}

		echo '</td></tr>';
	}

	/**
	 * Industry free-text field.
	 */
	private function render_industry_row(): void {
		echo '<tr><th scope="row"><label for="bli-industry">' . esc_html__( 'Industry or store type', 'bulk-list-import' ) . '</label></th><td>';
		printf(
			'<input type="text" id="bli-industry" name="bli_ai_industry" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr( (string) get_option( 'bli_ai_industry', '' ) ),
			esc_attr__( 'electronics retailer', 'bulk-list-import' )
		);
		echo '<p class="description">'
			. esc_html__( 'Optional. Leave blank and the wording stays category-neutral, which works for any catalogue.', 'bulk-list-import' )
			. '</p>';
		echo '</td></tr>';
	}

	/**
	 * A select backed by a fixed list of choices.
	 *
	 * @param string                $option  Option name.
	 * @param string                $label   Field label.
	 * @param array<string, string> $choices Value => label.
	 * @param string                $default_value Fallback value.
	 */
	private function render_choice_row( string $option, string $label, array $choices, string $default_value ): void {
		$current = (string) get_option( $option, $default_value );
		$id      = str_replace( '_', '-', $option );

		printf(
			'<tr><th scope="row"><label for="%s">%s</label></th><td><select id="%s" name="%s">',
			esc_attr( $id ),
			esc_html( $label ),
			esc_attr( $id ),
			esc_attr( $option )
		);

		foreach ( $choices as $value => $text ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $value, $current, false ),
				esc_html( $text )
			);
		}

		echo '</select></td></tr>';
	}

	/**
	 * Custom prompt template.
	 */
	private function render_template_row(): void {
		echo '<tr><th scope="row"><label for="bli-template">' . esc_html__( 'Custom prompt', 'bulk-list-import' ) . '</label></th><td>';
		printf(
			'<textarea id="bli-template" name="bli_ai_template" rows="6" class="large-text code">%s</textarea>',
			esc_textarea( (string) get_option( 'bli_ai_template', '' ) )
		);

		// Escape each placeholder, then join with the markup. Escaping the joined
		// string would turn the <code> tags into visible entities.
		$codes = array();

		foreach ( Prompt_Builder::PLACEHOLDERS as $placeholder ) {
			$codes[] = '<code>' . esc_html( $placeholder ) . '</code>';
		}

		echo '<p class="description">'
			. esc_html__( 'Optional. Leave blank to use the built-in prompt. Available placeholders:', 'bulk-list-import' )
			. ' ' . wp_kses_post( implode( ' ', $codes ) ) . '</p>';

		echo '<p class="description">'
			. esc_html__(
				'The JSON contract and the accuracy rule are appended to whatever you write, so a custom prompt cannot accidentally remove them.',
				'bulk-list-import'
			)
			. '</p>';

		echo '</td></tr>';
	}
}
