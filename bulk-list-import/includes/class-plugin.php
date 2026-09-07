<?php
/**
 * Hook wiring — the closest thing this plugin has to main().
 *
 * WordPress note: nothing in a plugin runs on its own. Every entry point below
 * is a callback registered against a named event; WordPress calls them, not us.
 * add_action() is "run this when X happens"; add_filter() is "let me rewrite
 * this value on its way past".
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private Admin_Page $admin_page;

	private bool $booted = false;

	/**
	 * Singleton accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {
		$this->admin_page = new Admin_Page();
	}

	/**
	 * Register every hook the plugin needs.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this->admin_page, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this->admin_page, 'enqueue' ) );

		// admin-post.php endpoints. Both are capability- and nonce-checked in the handler.
		add_action( 'admin_post_bli_import', array( $this->admin_page, 'handle_import' ) );
		add_action( 'admin_post_bli_export_report', array( Import_Report::class, 'export_csv' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( BLI_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'bulk-list-import', false, dirname( plugin_basename( BLI_FILE ) ) . '/languages' );
	}

	/**
	 * Add an "Import" link on the Plugins screen.
	 *
	 * @param array<int, string> $links Existing links.
	 * @return array<int, string>
	 */
	public function action_links( array $links ): array {
		$url = Admin_Page::url();

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Import', 'bulk-list-import' ) . '</a>'
		);

		return $links;
	}
}
