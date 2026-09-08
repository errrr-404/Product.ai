<?php
/**
 * Paste form and preview table.
 *
 * Plain PHP forms on purpose. A build pipeline now would slow down the part
 * that actually matters.
 *
 * @package BulkListImport
 */

declare( strict_types = 1 );

namespace BulkListImport;

use BulkListImport\AI\Provider_Registry;
use BulkListImport\AI\Recognition_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The importer screen.
 */
class Admin_Page {

	public const SLUG               = 'bulk-list-import';
	public const REPORT_SLUG        = 'bli-import-report';
	public const DEFAULT_BATCH_SIZE = 20;

	/**
	 * Rough tokens per product, for the estimate shown before committing. A round
	 * number on purpose: it is an order-of-magnitude warning, not a quote.
	 */
	public const TOKENS_PER_PRODUCT = 650;

	/**
	 * Admin menu parent.
	 *
	 * Products, not WooCommerce: WooCommerce's own CSV product importer lives
	 * here, and the WooCommerce menu is for orders, settings and reports. This
	 * also decides the page URL — a submenu registered under a parent other than
	 * admin.php is served by that parent file, so `admin.php?page=…` would 404
	 * with a permissions error. Always build links with url() below.
	 */
	public const PARENT = 'edit.php?post_type=product';

	/**
	 * Build an admin URL for one of this plugin's screens.
	 *
	 * @param string $page  Page slug.
	 * @param string $query Extra query string, already encoded, without a leading "&".
	 */
	public static function url( string $page = self::SLUG, string $query = '' ): string {
		$url = self::PARENT . '&page=' . $page;

		if ( '' !== $query ) {
			$url .= '&' . $query;
		}

		return admin_url( $url );
	}

	/**
	 * Flags the preview form is allowed to round-trip back to us.
	 *
	 * @var string[]
	 */
	private const ALLOWED_FLAGS = array( 'heading', 'duplicate', 'no_price', 'no_name', 'price_uncertain' );

	/**
	 * What a user may do with a blocked row.
	 *
	 * @var string[]
	 */
	private const GATE_ACTIONS = array( 'own', 'details', 'skip' );

	/**
	 * Register menu entries.
	 */
	public function register_menu(): void {
		add_submenu_page(
			self::PARENT,
			__( 'Bulk List Import', 'bulk-list-import' ),
			__( 'Bulk List Import', 'bulk-list-import' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);

		add_submenu_page(
			self::PARENT,
			__( 'Import Report', 'bulk-list-import' ),
			__( 'Import Report', 'bulk-list-import' ),
			'manage_woocommerce',
			self::REPORT_SLUG,
			array( Import_Report::class, 'render_page' )
		);
	}

	/**
	 * Load assets only on our own screens.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) && ! str_contains( $hook, self::REPORT_SLUG ) && ! str_contains( $hook, Settings_Page::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'bli-admin', BLI_URL . 'assets/admin.css', array(), BLI_VERSION );
		wp_enqueue_script( 'bli-admin', BLI_URL . 'assets/admin.js', array(), BLI_VERSION, true );
		wp_localize_script(
			'bli-admin',
			'bliStrings',
			array(
				'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
				'previewNonce'        => wp_create_nonce( 'bli_parse' ),
				'batchWarning'        => __( 'Large imports are harder to review carefully.', 'bulk-list-import' ),
				/* translators: %d: number of selected rows. */
				'selected'            => __( 'Import %d products', 'bulk-list-import' ),
				/* translators: 1: number of rows to import, 2: number blocked. */
				'selectedWithBlocked' => __( 'Import %1$d products (%2$d blocked)', 'bulk-list-import' ),
				'checking'            => __( 'Checking products…', 'bulk-list-import' ),
				'parsing'             => __( 'Parsing…', 'bulk-list-import' ),
				'previewError'        => __( 'The preview could not be built. Try again.', 'bulk-list-import' ),
				/* translators: %s: approximate token count. */
				'tokens'              => __( 'Estimated %s tokens for this import.', 'bulk-list-import' ),
				'skipAll'             => __( 'Skip all blocked', 'bulk-list-import' ),
				'bulkFill'            => __( 'Fill all blocked from the first', 'bulk-list-import' ),
				'gateOn'              => Recognition_Gate::is_available(),
			)
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to import products.', 'bulk-list-import' ) );
		}

		echo '<div class="wrap bli-wrap">';
		echo '<h1>' . esc_html__( 'Bulk List Import', 'bulk-list-import' ) . '</h1>';
		echo '<p class="bli-tagline">' . esc_html__( 'Stop typing. Start reviewing.', 'bulk-list-import' ) . '</p>';

		$action = isset( $_POST['bli_action'] ) && is_string( $_POST['bli_action'] ) ? sanitize_key( wp_unslash( $_POST['bli_action'] ) ) : '';

		if ( 'parse' === $action ) {
			check_admin_referer( 'bli_parse' );
			$this->render_preview();
		} else {
			$this->render_paste_form();
		}

		echo '</div>';
	}

	/**
	 * Step 1 — the paste box.
	 */
	private function render_paste_form(): void {
		$text = '';

		// A Retry link from the Import Report drops the failed line straight back
		// into the box, so the user can correct it and re-run just that row.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below when present.
		if ( isset( $_GET['retry'], $_GET['line'] ) && check_admin_referer( 'bli_retry' ) ) {
			$import_id = absint( $_GET['retry'] );
			$line      = absint( $_GET['line'] );

			foreach ( Import_Store::get_rows( $import_id ) as $entry ) {
				if ( (int) ( $entry['line'] ?? 0 ) === $line ) {
					$text = (string) ( $entry['raw'] ?? '' );
					break;
				}
			}
		}

		$batch  = (int) get_option( 'bli_batch_size', self::DEFAULT_BATCH_SIZE );
		$prefix = (string) get_option( 'bli_sku_prefix', '' );
		$sku    = SKU_Generator::detect();

		echo '<form method="post" class="bli-paste-form">';
		wp_nonce_field( 'bli_parse' );
		echo '<input type="hidden" name="bli_action" value="parse" />';

		echo '<p><label for="bli-source"><strong>' . esc_html__( 'Paste your product list', 'bulk-list-import' ) . '</strong></label></p>';
		echo '<p class="description">'
			. esc_html__( 'One product per line. Headings, numbering, tabs, dashes and missing prices are all fine — nothing is thrown away silently.', 'bulk-list-import' )
			. '</p>';

		echo '<textarea id="bli-source" name="bli_source" rows="14" class="large-text code" spellcheck="false">'
			. esc_textarea( $text ) . '</textarea>';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th scope="row"><label for="bli-batch">' . esc_html__( 'Batch size', 'bulk-list-import' ) . '</label></th><td>';
		echo '<input type="number" id="bli-batch" name="bli_batch_size" min="1" max="500" value="' . esc_attr( (string) $batch ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'How many rows to select by default. Larger imports are allowed — just harder to review carefully.', 'bulk-list-import' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="bli-prefix">' . esc_html__( 'SKU prefix', 'bulk-list-import' ) . '</label></th><td>';
		echo '<input type="text" id="bli-prefix" name="bli_sku_prefix" value="' . esc_attr( $prefix ) . '" class="regular-text" placeholder="' . esc_attr( $sku->prefix() ) . '" />';
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: next SKU in sequence. */
					__( 'Leave blank to continue the sequence already in use. Next SKU would be %s.', 'bulk-list-import' ),
					$sku->peek()
				)
			)
		);
		echo '</td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Preview', 'bulk-list-import' ) );
		echo '</form>';
	}

	/**
	 * Step 2 — the preview table, from a plain form POST.
	 *
	 * This is the no-JavaScript path. It still works, and still gates.
	 */
	private function render_preview(): void {
		// The nonce is verified by render(), which is the only caller and checks
		// check_admin_referer( 'bli_parse' ) before dispatching here.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in render().
		$source = isset( $_POST['bli_source'] ) && is_string( $_POST['bli_source'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bli_source'] ) ) : '';
		$batch  = isset( $_POST['bli_batch_size'] ) && is_scalar( $_POST['bli_batch_size'] ) ? absint( wp_unslash( $_POST['bli_batch_size'] ) ) : self::DEFAULT_BATCH_SIZE;
		$prefix = isset( $_POST['bli_sku_prefix'] ) && is_string( $_POST['bli_sku_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['bli_sku_prefix'] ) ) : '';

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $this->render_preview_for( $source, $batch, $prefix ) ) {
			$this->render_paste_form();
		}
	}

	/**
	 * Answer the asynchronous preview request.
	 *
	 * The preview is fetched rather than posted because the recognition gate makes
	 * it slow enough to matter: a synchronous POST leaves the browser blank for
	 * several seconds, which reads as a hang, and people resubmit. Same request
	 * shape, same rendering code, same nonce — only the delivery differs.
	 */
	public function handle_preview(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import products.', 'bulk-list-import' ) ), 403 );
		}

		check_ajax_referer( 'bli_parse' );

		$source = isset( $_POST['bli_source'] ) && is_string( $_POST['bli_source'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bli_source'] ) ) : '';
		$batch  = isset( $_POST['bli_batch_size'] ) && is_scalar( $_POST['bli_batch_size'] ) ? absint( wp_unslash( $_POST['bli_batch_size'] ) ) : self::DEFAULT_BATCH_SIZE;
		$prefix = isset( $_POST['bli_sku_prefix'] ) && is_string( $_POST['bli_sku_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['bli_sku_prefix'] ) ) : '';

		ob_start();
		$rendered = $this->render_preview_for( $source, $batch, $prefix );
		$html     = (string) ob_get_clean();

		wp_send_json_success(
			array(
				'html'     => $html,
				'rendered' => $rendered,
			)
		);
	}

	/**
	 * Parse, gate and render. Returns false when there was nothing to show.
	 *
	 * @param string $source Pasted text.
	 * @param int    $batch  Requested batch size.
	 * @param string $prefix SKU prefix override.
	 */
	private function render_preview_for( string $source, int $batch, string $prefix ): bool {
		$batch = max( 1, min( 500, $batch ) );

		update_option( 'bli_batch_size', $batch, false );
		update_option( 'bli_sku_prefix', $prefix, false );

		if ( '' === trim( $source ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Nothing to parse — the paste box was empty.', 'bulk-list-import' ) . '</p></div>';
			return false;
		}

		$rows = ( new Parser() )->parse( $source );

		if ( array() === $rows ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No usable lines found.', 'bulk-list-import' ) . '</p></div>';
			return false;
		}

		$rows = $this->apply_gate( $rows, $batch );

		$sku        = SKU_Generator::detect( $prefix );
		$importable = 0;
		$sku_offset = 0;

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bli-preview-form">';
		wp_nonce_field( 'bli_import' );
		echo '<input type="hidden" name="action" value="bli_import" />';
		echo '<input type="hidden" name="bli_sku_prefix" value="' . esc_attr( $prefix ) . '" />';
		echo '<input type="hidden" name="bli_batch_size" value="' . esc_attr( (string) $batch ) . '" />';

		echo '<table class="widefat striped bli-preview">';
		echo '<thead><tr>';
		echo '<td class="check-column"><input type="checkbox" id="bli-select-all" checked="checked" /></td>';
		echo '<th class="bli-col-row">' . esc_html__( 'Row', 'bulk-list-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Product name', 'bulk-list-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Variant / spec', 'bulk-list-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Price', 'bulk-list-import' ) . '</th>';
		echo '<th>' . esc_html__( 'SKU', 'bulk-list-import' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'bulk-list-import' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $index => $row ) {
			$gate  = (array) ( $row['gate'] ?? array() );
			$state = (string) ( $gate['state'] ?? Recognition_Gate::READY );

			// A blocked row is not selectable until the user does something about it.
			// That is the difference between a gate and a disclaimer.
			$blocked    = Recognition_Gate::BLOCKED === $state;
			$selectable = (bool) $row['importable'] && ! $blocked;
			$selected   = $selectable && $importable < $batch;

			if ( $selectable ) {
				++$importable;
			}

			$next_sku = $selectable ? $sku->peek( $sku_offset ) : '';
			if ( $selectable ) {
				++$sku_offset;
			}

			$classes = array( 'bli-row', 'bli-row--' . $row['type'], 'bli-row--gate-' . $state );
			if ( ! $selectable ) {
				$classes[] = 'bli-row--locked';
			}

			$field = 'bli_rows[' . $index . ']';

			printf(
				'<tr class="%s" data-index="%d" data-gate="%s">',
				esc_attr( implode( ' ', $classes ) ),
				(int) $index,
				esc_attr( $state )
			);

			echo '<th scope="row" class="check-column">';
			if ( $selectable ) {
				echo '<input type="checkbox" class="bli-row-check" name="' . esc_attr( $field ) . '[selected]" value="1" '
					. checked( $selected, true, false ) . ' />';
			} else {
				echo '<input type="checkbox" disabled="disabled" />';
			}

			// Everything the importer needs that the user cannot edit. Kept inside
			// an existing cell so the row keeps the same column count as the header.
			echo '<input type="hidden" name="' . esc_attr( $field ) . '[line]" value="' . esc_attr( (string) $row['line'] ) . '" />';
			echo '<input type="hidden" name="' . esc_attr( $field ) . '[type]" value="' . esc_attr( $row['type'] ) . '" />';
			echo '<input type="hidden" name="' . esc_attr( $field ) . '[raw]" value="' . esc_attr( $row['raw'] ) . '" />';
			echo '<input type="hidden" name="' . esc_attr( $field ) . '[flags]" value="' . esc_attr( implode( ',', $row['flags'] ) ) . '" />';
			echo '<input type="hidden" name="' . esc_attr( $field ) . '[duplicate_of]" value="' . esc_attr( (string) ( $row['duplicate_of'] ?? 0 ) ) . '" />';
			if ( 'heading' === $row['type'] ) {
				echo '<input type="hidden" name="' . esc_attr( $field ) . '[name]" value="' . esc_attr( $row['name'] ) . '" />';
			}
			echo '</th>';

			echo '<td>' . esc_html( (string) $row['line'] ) . '</td>';

			// Editable name / variant / price: a parse error must be fixable here,
			// without going back and re-pasting the whole list.
			echo '<td>';
			if ( 'heading' === $row['type'] ) {
				echo '<span class="bli-heading-text">' . esc_html( $row['name'] ) . '</span>';
			} else {
				echo '<input type="text" class="regular-text" name="' . esc_attr( $field ) . '[name]" value="' . esc_attr( $row['name'] ) . '" />';
			}
			echo '</td>';

			echo '<td>';
			if ( 'heading' !== $row['type'] ) {
				echo '<input type="text" class="bli-input-variant" name="' . esc_attr( $field ) . '[variant]" value="' . esc_attr( $row['variant'] ) . '" />';
			}
			echo '</td>';

			echo '<td>';
			if ( 'heading' !== $row['type'] ) {
				echo '<input type="text" inputmode="decimal" class="bli-input-price" name="' . esc_attr( $field ) . '[price]" value="'
					. esc_attr( null === $row['price'] ? '' : (string) $row['price'] ) . '" />';
			}
			echo '</td>';

			echo '<td class="bli-sku">' . esc_html( '' !== $next_sku ? $next_sku : '—' ) . '</td>';

			echo '<td>' . wp_kses_post( $this->status_cell( $row ) ) . '</td>';

			echo '</tr>';

			if ( $blocked ) {
				$this->render_blocked_panel( $index, $row, $field );
			}
		}

		echo '</tbody></table>';

		echo '<div class="bli-actions">';
		printf(
			'<p id="bli-batch-warning" class="bli-warning" data-batch="%d" hidden>%s</p>',
			(int) $batch,
			esc_html__( 'Large imports are harder to review carefully.', 'bulk-list-import' )
		);

		if ( Recognition_Gate::is_available() ) {
			printf(
				'<p id="bli-token-estimate" class="description" data-per-product="%s"></p>',
				esc_attr( (string) self::TOKENS_PER_PRODUCT )
			);
		}

		echo '<p class="description">'
			. esc_html__( 'Products are created as drafts and tagged needs-review. Nothing is published.', 'bulk-list-import' )
			. '</p>';
		submit_button( __( 'Import selected products', 'bulk-list-import' ), 'primary', 'submit', false, array( 'id' => 'bli-import-button' ) );
		echo ' <a class="button" href="' . esc_url( self::url() ) . '">'
			. esc_html__( 'Start over', 'bulk-list-import' ) . '</a>';
		echo '</div>';

		echo '</form>';

		return true;
	}

	/**
	 * Run the recognition gate over the parsed rows.
	 *
	 * Free tier, no key, or a provider that cannot be reached: the rows come back
	 * ungated and the import still works. The gate is a Pro feature layered on top
	 * of a plugin that is useful without it, not a dependency of it.
	 *
	 * @param array<int, array<string, mixed>> $rows  Parsed rows.
	 * @param int                              $batch Batch size, which caps how many are worth checking.
	 * @return array<int, array<string, mixed>>
	 */
	private function apply_gate( array $rows, int $batch ): array {
		if ( ! Recognition_Gate::is_available() ) {
			return $rows;
		}

		try {
			$gate = new Recognition_Gate( Provider_Registry::make() );
		} catch ( \Throwable $e ) {
			return $rows;
		}

		// Never more than one provider call for a page load. Checking rows the user
		// is not about to import would spend tokens to answer a question nobody asked.
		$limit = min( Recognition_Gate::NAMES_PER_CALL, max( 1, $batch ) );

		return $gate->judge( $rows, $limit );
	}

	/**
	 * The panel under a blocked row.
	 *
	 * The gate should feel like a seatbelt, not a locked door — so a blocked row
	 * offers three ways forward rather than a dead end. "Generate from my details"
	 * is the one that matters: the user supplies the facts, the model supplies the
	 * prose, and the time saving survives without anything being invented.
	 *
	 * @param int                  $index Row index.
	 * @param array<string, mixed> $row   Parsed row.
	 * @param string               $field Field name prefix for this row.
	 */
	private function render_blocked_panel( int $index, array $row, string $field ): void {
		$gate   = (array) ( $row['gate'] ?? array() );
		$reason = (string) ( $gate['reason'] ?? '' );

		printf(
			'<tr class="bli-panel-row" data-panel-for="%d"><td colspan="7" class="bli-panel">',
			(int) $index
		);

		echo '<p class="bli-panel-head"><strong>' . esc_html( (string) $row['name'] ) . '</strong> — '
			. esc_html( '' !== $reason ? $reason : __( 'No reliable information about this product.', 'bulk-list-import' ) )
			. '</p>';

		echo '<div class="bli-panel-fields">';

		foreach ( array(
			'category' => __( 'Category', 'bulk-list-import' ),
			'brand'    => __( 'Brand', 'bulk-list-import' ),
			'specs'    => __( 'Key specs', 'bulk-list-import' ),
		) as $key => $label ) {
			printf(
				'<label><span>%s</span><input type="text" class="bli-detail bli-detail--%s" name="%s[details][%s]" value="" /></label>',
				esc_html( $label ),
				esc_attr( $key ),
				esc_attr( $field ),
				esc_attr( $key )
			);
		}

		printf(
			'<label class="bli-detail-notes"><span>%s</span><textarea rows="2" class="bli-detail" name="%s[details][notes]"></textarea></label>',
			esc_html__( 'Notes from the packaging', 'bulk-list-import' ),
			esc_attr( $field )
		);

		echo '</div>';

		printf( '<input type="hidden" class="bli-gate-action" name="%s[gate_action]" value="skip" />', esc_attr( $field ) );

		echo '<p class="bli-panel-actions">';
		printf(
			'<button type="button" class="button bli-gate-choice" data-choice="details">%s</button> ',
			esc_html__( 'Generate from my details', 'bulk-list-import' )
		);
		printf(
			'<button type="button" class="button bli-gate-choice" data-choice="own">%s</button> ',
			esc_html__( 'Write description myself', 'bulk-list-import' )
		);
		printf(
			'<button type="button" class="button-link bli-gate-choice" data-choice="skip">%s</button>',
			esc_html__( 'Skip', 'bulk-list-import' )
		);
		echo '</p>';

		echo '</td></tr>';
	}

	/**
	 * The status cell: what the parser thinks of this row, in words.
	 *
	 * @param array<string, mixed> $row Parsed row.
	 */
	private function status_cell( array $row ): string {
		$flags = (array) $row['flags'];

		if ( 'heading' === $row['type'] ) {
			return '<span class="bli-badge bli-badge--muted">' . esc_html__( 'Heading — skipped', 'bulk-list-import' ) . '</span>';
		}

		$parts = array();
		$gate  = (array) ( $row['gate'] ?? array() );
		$state = (string) ( $gate['state'] ?? Recognition_Gate::READY );

		switch ( $state ) {
			case Recognition_Gate::BLOCKED:
				$parts[] = '<span class="bli-badge bli-badge--bad">'
					. esc_html__( 'Not recognised', 'bulk-list-import' ) . '</span>';
				break;

			case Recognition_Gate::UNCHECKED:
				// Deliberately not phrased as a verdict. Nobody has looked at this row.
				$parts[] = '<span class="bli-badge bli-badge--muted">'
					. esc_html__( 'Not yet checked', 'bulk-list-import' ) . '</span>';
				break;

			case Recognition_Gate::UNAVAILABLE:
				$parts[] = '<span class="bli-badge bli-badge--warn" title="'
					. esc_attr( (string) ( $gate['reason'] ?? '' ) ) . '">'
					. esc_html__( 'Check unavailable', 'bulk-list-import' ) . '</span>';
				break;
		}

		foreach ( (array) ( $gate['uncertain_fields'] ?? array() ) as $uncertain ) {
			$parts[] = '<span class="bli-badge bli-badge--warn">'
				. esc_html(
					sprintf(
						/* translators: %s: field the model was unsure about. */
						__( 'Verify %s', 'bulk-list-import' ),
						(string) $uncertain
					)
				) . '</span>';
		}

		if ( in_array( 'duplicate', $flags, true ) ) {
			$parts[] = '<span class="bli-badge bli-badge--muted">' . esc_html(
				sprintf(
					/* translators: %d: earlier row number. */
					__( 'Duplicate of row %d', 'bulk-list-import' ),
					(int) $row['duplicate_of']
				)
			) . '</span>';
		}

		if ( in_array( 'no_price', $flags, true ) ) {
			$parts[] = '<span class="bli-badge bli-badge--warn">' . esc_html__( 'No price found', 'bulk-list-import' ) . '</span>';
		}

		if ( in_array( 'price_uncertain', $flags, true ) ) {
			$parts[] = '<span class="bli-badge bli-badge--warn">' . esc_html__( 'Verify price', 'bulk-list-import' ) . '</span>';
		}

		if ( in_array( 'no_name', $flags, true ) ) {
			$parts[] = '<span class="bli-badge bli-badge--bad">' . esc_html__( 'No name', 'bulk-list-import' ) . '</span>';
		}

		if ( array() === $parts ) {
			$parts[] = '<span class="bli-badge bli-badge--ok">' . esc_html__( 'Ready', 'bulk-list-import' ) . '</span>';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Handle the import POST, then redirect to the report.
	 *
	 * Runs on admin-post.php rather than inside the page render so the redirect
	 * happens before any output, and a browser refresh cannot re-run the import.
	 */
	public function handle_import(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to import products.', 'bulk-list-import' ) );
		}

		check_admin_referer( 'bli_import' );

		$prefix = isset( $_POST['bli_sku_prefix'] ) && is_string( $_POST['bli_sku_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['bli_sku_prefix'] ) ) : '';
		// Sanitised field by field in the loop below; there is no whole-array
		// sanitiser for a nested structure like this one.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitised individually below.
		$raw  = isset( $_POST['bli_rows'] ) && is_array( $_POST['bli_rows'] ) ? wp_unslash( $_POST['bli_rows'] ) : array();
		$rows = array();

		foreach ( (array) $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$flags = array_values(
				array_intersect(
					array_map( 'sanitize_key', explode( ',', (string) ( $item['flags'] ?? '' ) ) ),
					self::ALLOWED_FLAGS
				)
			);

			$price = isset( $item['price'] ) ? trim( sanitize_text_field( (string) $item['price'] ) ) : '';

			$action = isset( $item['gate_action'] ) ? sanitize_key( (string) $item['gate_action'] ) : '';
			$action = in_array( $action, self::GATE_ACTIONS, true ) ? $action : '';

			$details = array();

			if ( isset( $item['details'] ) && is_array( $item['details'] ) ) {
				foreach ( array( 'category', 'brand', 'specs', 'notes' ) as $detail ) {
					$details[ $detail ] = sanitize_textarea_field( (string) ( $item['details'][ $detail ] ?? '' ) );
				}
			}

			$rows[] = array(
				'gate_action'  => $action,
				'details'      => $details,
				'line'         => absint( $item['line'] ?? 0 ),
				'raw'          => sanitize_textarea_field( (string) ( $item['raw'] ?? '' ) ),
				'type'         => 'heading' === ( $item['type'] ?? '' ) ? 'heading' : 'product',
				'name'         => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
				'variant'      => sanitize_text_field( (string) ( $item['variant'] ?? '' ) ),
				'price'        => '' === $price ? null : $price,
				'flags'        => $flags,
				'duplicate_of' => absint( $item['duplicate_of'] ?? 0 ),
				'selected'     => ! empty( $item['selected'] ),
			);
		}

		if ( array() === $rows ) {
			wp_safe_redirect( self::url() );
			exit;
		}

		$import_id = ( new Importer() )->import( $rows, $prefix );

		wp_safe_redirect( self::url( self::REPORT_SLUG, 'report=' . $import_id ) );
		exit;
	}
}
