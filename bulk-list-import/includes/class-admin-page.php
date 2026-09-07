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
				'batchWarning' => __( 'Large imports are harder to review carefully.', 'bulk-list-import' ),
				/* translators: %d: number of selected rows. */
				'selected'     => __( 'Import %d products', 'bulk-list-import' ),
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
		if ( isset( $_GET['retry'], $_GET['line'] ) && is_string( $_GET['retry'] ) && check_admin_referer( 'bli_retry' ) ) {
			$report = Import_Report::get( sanitize_text_field( wp_unslash( $_GET['retry'] ) ) );
			$line   = absint( $_GET['line'] );

			if ( null !== $report ) {
				foreach ( (array) $report['entries'] as $entry ) {
					if ( (int) ( $entry['line'] ?? 0 ) === $line ) {
						$text = (string) ( $entry['raw'] ?? '' );
						break;
					}
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
	 * Step 2 — the preview table.
	 */
	private function render_preview(): void {
		// The nonce is verified by render(), which is the only caller and checks
		// check_admin_referer( 'bli_parse' ) before dispatching here.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in render().
		$source = isset( $_POST['bli_source'] ) && is_string( $_POST['bli_source'] ) ? sanitize_textarea_field( wp_unslash( $_POST['bli_source'] ) ) : '';
		$batch  = isset( $_POST['bli_batch_size'] ) && is_scalar( $_POST['bli_batch_size'] ) ? absint( wp_unslash( $_POST['bli_batch_size'] ) ) : self::DEFAULT_BATCH_SIZE;
		$prefix = isset( $_POST['bli_sku_prefix'] ) && is_string( $_POST['bli_sku_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['bli_sku_prefix'] ) ) : '';

		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$batch = max( 1, min( 500, $batch ) );

		update_option( 'bli_batch_size', $batch, false );
		update_option( 'bli_sku_prefix', $prefix, false );

		if ( '' === trim( $source ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Nothing to parse — the paste box was empty.', 'bulk-list-import' ) . '</p></div>';
			$this->render_paste_form();
			return;
		}

		$rows = ( new Parser() )->parse( $source );

		if ( array() === $rows ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No usable lines found.', 'bulk-list-import' ) . '</p></div>';
			$this->render_paste_form();
			return;
		}

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
			$selectable = (bool) $row['importable'];
			$selected   = $selectable && $importable < $batch;

			if ( $selectable ) {
				++$importable;
			}

			$next_sku = $selectable ? $sku->peek( $sku_offset ) : '';
			if ( $selectable ) {
				++$sku_offset;
			}

			$classes = array( 'bli-row', 'bli-row--' . $row['type'] );
			if ( ! $selectable ) {
				$classes[] = 'bli-row--locked';
			}

			$field = 'bli_rows[' . $index . ']';

			echo '<tr class="' . esc_attr( implode( ' ', $classes ) ) . '">';

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
		}

		echo '</tbody></table>';

		echo '<div class="bli-actions">';
		printf(
			'<p id="bli-batch-warning" class="bli-warning" data-batch="%d" hidden>%s</p>',
			(int) $batch,
			esc_html__( 'Large imports are harder to review carefully.', 'bulk-list-import' )
		);
		echo '<p class="description">'
			. esc_html__( 'Products are created as drafts and tagged needs-review. Nothing is published.', 'bulk-list-import' )
			. '</p>';
		submit_button( __( 'Import selected products', 'bulk-list-import' ), 'primary', 'submit', false, array( 'id' => 'bli-import-button' ) );
		echo ' <a class="button" href="' . esc_url( self::url() ) . '">'
			. esc_html__( 'Start over', 'bulk-list-import' ) . '</a>';
		echo '</div>';

		echo '</form>';
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

			$rows[] = array(
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

		$report = ( new Importer() )->import( $rows, $prefix );

		wp_safe_redirect(
			self::url( self::REPORT_SLUG, 'report=' . rawurlencode( (string) $report['id'] ) )
		);
		exit;
	}
}
