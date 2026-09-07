<?php
/**
 * Plugin Name:       Bulk List Import for WooCommerce
 * Plugin URI:        https://example.com/bulk-list-import
 * Description:       Turn a raw pasted product list into reviewable draft products with continuous SKUs. Stop typing. Start reviewing.
 * Version:           0.3.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Bulk List Import
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bulk-list-import
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.4
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLI_VERSION', '0.3.0' );
define( 'BLI_FILE', __FILE__ );
define( 'BLI_PATH', plugin_dir_path( __FILE__ ) );
define( 'BLI_URL', plugin_dir_url( __FILE__ ) );

/**
 * Class autoloader.
 *
 * PHP note: spl_autoload_register hands PHP a fallback to run the first time a
 * class name is used that isn't loaded yet. There is no classpath like in Java —
 * this callback maps the fully-qualified name to a file by convention.
 *
 * Sub-namespace segments become directories, and the leaf follows the WPCS file
 * naming rules, so a new directory needs no registration:
 *
 *   BulkListImport\SKU_Generator        -> includes/class-sku-generator.php
 *   BulkListImport\AI\Gemini_Provider   -> includes/ai/class-gemini-provider.php
 *   BulkListImport\AI\Description_Provider
 *                                       -> includes/ai/interface-description-provider.php
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'BulkListImport\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( 'BulkListImport\\' ) );
		$segments = explode( '\\', $relative );
		$leaf     = array_pop( $segments );

		// PHP class names cannot contain a separator or a dot, so traversal is not
		// reachable here. Rejecting anything unexpected is still cheaper than
		// reasoning about it again later.
		foreach ( array_merge( $segments, array( $leaf ) ) as $segment ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $segment ) ) {
				return;
			}
		}

		$directory = BLI_PATH . 'includes/';

		foreach ( $segments as $segment ) {
			$directory .= strtolower( $segment ) . '/';
		}

		$slug = strtolower( str_replace( '_', '-', $leaf ) );

		foreach ( array( 'class-', 'interface-', 'trait-' ) as $prefix ) {
			$path = $directory . $prefix . $slug . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

/**
 * Whether the Pro tier is active.
 *
 * Every premium feature is gated on this from day one — retrofitting a freemium
 * boundary later is painful. Phase 3 ships free-tier only, so this returns false;
 * the Freemius bootstrap will replace the body, not the signature.
 */
function bli_is_pro(): bool {
	/**
	 * Filters whether Pro features are unlocked.
	 *
	 * @param bool $is_pro Pro status.
	 */
	return (bool) apply_filters( 'bli_is_pro', false );
}

/**
 * Declare HPOS (High-Performance Order Storage) compatibility.
 *
 * WordPress note: nothing runs unless it is hooked. add_action() registers a
 * callback against a named event that WooCommerce fires later — this one has to
 * run before WooCommerce boots its feature flags, hence 'before_woocommerce_init'.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				BLI_FILE,
				true
			);
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded, so the WooCommerce check is reliable.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p>';
					echo esc_html__(
						'Bulk List Import for WooCommerce requires WooCommerce to be installed and active.',
						'bulk-list-import'
					);
					echo '</p></div>';
				}
			);
			return;
		}

		\BulkListImport\Plugin::instance()->boot();
	}
);
