<?php
/**
 * Admin screens: Print labels, SKUs & barcodes, Settings.
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_Admin {

	const OPT = 'prdrwndn_pos_settings';

	const LABEL_SIZES = array(
		'2.25x1.25' => '2.25 x 1.25 in (recommended)',
		'2x1'       => '2 x 1 in',
		'4x6'       => '4 x 6 in, one big label',
		'4x6-8'     => '4 x 6 in sheet: 8 labels (2 x 1.5 in each, cut apart)',
		'4x6-12'    => '4 x 6 in sheet: 12 labels (2 x 1 in each, cut apart)',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_prdrwndn_pos_save_skus', array( __CLASS__, 'save_skus' ) );
		add_action( 'admin_post_prdrwndn_pos_save_settings', array( __CLASS__, 'save_settings' ) );
		add_filter( 'plugin_action_links_' . PRDRWNDN_POS_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	public static function settings() {
		$s = get_option( self::OPT, array() );
		return wp_parse_args(
			is_array( $s ) ? $s : array(),
			array(
				'prefix'     => 'PRD',
				'label_size' => '2.25x1.25',
				'show_price' => 1,
				'printer'    => '',
			)
		);
	}

	public static function menu() {
		add_menu_page( 'PRDRWNDN POS', 'PRDRWNDN POS', PRDRWNDN_POS_Roles::CAP_LABELS, 'prdrwndn-pos', array( __CLASS__, 'page_labels' ), 'dashicons-tag', 56 );
		add_submenu_page( 'prdrwndn-pos', __( 'Print labels', 'prdrwndn-pos' ), __( 'Print labels', 'prdrwndn-pos' ), PRDRWNDN_POS_Roles::CAP_LABELS, 'prdrwndn-pos', array( __CLASS__, 'page_labels' ) );
		add_submenu_page( 'prdrwndn-pos', __( 'SKUs & barcodes', 'prdrwndn-pos' ), __( 'SKUs & barcodes', 'prdrwndn-pos' ), PRDRWNDN_POS_Roles::CAP_MANAGE, 'prdrwndn-pos-skus', array( __CLASS__, 'page_skus' ) );
		add_submenu_page( 'prdrwndn-pos', __( 'Settings', 'prdrwndn-pos' ), __( 'Settings', 'prdrwndn-pos' ), PRDRWNDN_POS_Roles::CAP_MANAGE, 'prdrwndn-pos-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=prdrwndn-pos' ) ) . '">' . esc_html__( 'Print labels', 'prdrwndn-pos' ) . '</a>' );
		array_unshift( $links, '<a href="' . esc_url( PRDRWNDN_POS_App::url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open POS app', 'prdrwndn-pos' ) . '</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'prdrwndn-pos' ) ) {
			return;
		}
		wp_enqueue_style( 'prdrwndn-pos-admin', PRDRWNDN_POS_URL . 'assets/css/admin.css', array(), PRDRWNDN_POS_VERSION );
		if ( 'toplevel_page_prdrwndn-pos' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'qz-tray', PRDRWNDN_POS_URL . 'assets/vendor/qz-tray.js', array(), '2.3.0', true );
		wp_enqueue_script( 'prdrwndn-pos-labels', PRDRWNDN_POS_URL . 'assets/js/labels.js', array( 'qz-tray' ), PRDRWNDN_POS_VERSION, true );
		$s = self::settings();
		wp_localize_script(
			'prdrwndn-pos-labels',
			'PRDRWNDN_POS',
			array(
				'rest'      => esc_url_raw( rest_url( PRDRWNDN_POS_REST::NS . '/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'items'     => self::label_items(),
				'size'      => $s['label_size'],
				'showPrice' => (bool) $s['show_price'],
				'printer'   => $s['printer'],
				'qzReady'   => PRDRWNDN_POS_QZ::ready(),
				'brand'     => 'PRDRWNDN',
			)
		);
	}

	/**
	 * One entry per sellable item (simple products and variations).
	 *
	 * @return array[]
	 */
	public static function label_items() {
		$items    = array();
		$products = wc_get_products(
			array(
				'limit'   => -1,
				'status'  => array( 'publish', 'private', 'draft', 'pending' ),
				'type'    => array( 'simple', 'variable' ),
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		foreach ( $products as $product ) {
			$list = $product->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $product->get_children() ) ) : array( $product );
			foreach ( $list as $item ) {
				$d       = PRDRWNDN_POS_Lookup::product_data( $item );
				$bars    = PRDRWNDN_POS_UPC::modules( $d['barcode'] );
				$items[] = array(
					'id'      => $d['id'],
					'name'    => $d['name'],
					'variant' => $d['variant'],
					'sku'     => $d['sku'],
					'gtin'    => $d['gtin'],
					'barcode' => $d['barcode'],
					'kind'    => $d['barcode_kind'],
					'price'   => $d['price_text'],
					'stock'   => $d['stock'],
					'modules' => $bars['modules'],
					'long'    => $bars['long'],
				);
			}
		}
		return $items;
	}

	/* ------------------------------------------------------------------ */
	/* Print labels                                                        */
	/* ------------------------------------------------------------------ */

	public static function page_labels() {
		if ( ! current_user_can( PRDRWNDN_POS_Roles::CAP_LABELS ) ) {
			wp_die( esc_html__( 'You do not have access to this page.', 'prdrwndn-pos' ) );
		}
		$s = self::settings();
		?>
		<div class="wrap prdpos">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Print labels', 'prdrwndn-pos' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( PRDRWNDN_POS_App::url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open POS app', 'prdrwndn-pos' ); ?></a>
			<hr class="wp-header-end">
			<p class="prdpos-lead"><?php esc_html_e( 'Pick products, set how many labels you need, then print. Every label has a number barcode (UPC). Scanning it finds the same product on your website.', 'prdrwndn-pos' ); ?></p>

			<div class="prdpos-grid">
				<section class="prdpos-card prdpos-products">
					<div class="prdpos-toolbar">
						<label class="screen-reader-text" for="prdpos-filter"><?php esc_html_e( 'Filter products', 'prdrwndn-pos' ); ?></label>
						<input type="search" id="prdpos-filter" placeholder="<?php esc_attr_e( 'Filter by name or SKU', 'prdrwndn-pos' ); ?>">
						<button type="button" class="button" id="prdpos-qty-stock"><?php esc_html_e( 'Quantity = stock', 'prdrwndn-pos' ); ?></button>
						<button type="button" class="button" id="prdpos-clear"><?php esc_html_e( 'Clear', 'prdrwndn-pos' ); ?></button>
					</div>
					<table class="widefat striped prdpos-table">
						<thead>
							<tr>
								<th class="check-column"><label class="screen-reader-text" for="prdpos-all"><?php esc_html_e( 'Select all', 'prdrwndn-pos' ); ?></label><input type="checkbox" id="prdpos-all"></th>
								<th><?php esc_html_e( 'Product', 'prdrwndn-pos' ); ?></th>
								<th><?php esc_html_e( 'SKU', 'prdrwndn-pos' ); ?></th>
								<th class="num"><?php esc_html_e( 'Price', 'prdrwndn-pos' ); ?></th>
								<th class="num"><?php esc_html_e( 'Stock', 'prdrwndn-pos' ); ?></th>
								<th class="num"><?php esc_html_e( 'Labels', 'prdrwndn-pos' ); ?></th>
							</tr>
						</thead>
						<tbody id="prdpos-rows"></tbody>
					</table>

				</section>

				<aside class="prdpos-side">
					<section class="prdpos-card">
						<h2><?php esc_html_e( 'Label', 'prdrwndn-pos' ); ?></h2>
						<p>
							<label for="prdpos-size"><?php esc_html_e( 'Label size', 'prdrwndn-pos' ); ?></label><br>
							<select id="prdpos-size">
								<?php foreach ( self::LABEL_SIZES as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['label_size'], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p><label><input type="checkbox" id="prdpos-price" <?php checked( $s['show_price'] ); ?>> <?php esc_html_e( 'Show price', 'prdrwndn-pos' ); ?></label></p>
						<div class="prdpos-preview-wrap">
							<canvas id="prdpos-preview" aria-label="<?php esc_attr_e( 'Label preview', 'prdrwndn-pos' ); ?>"></canvas>
						</div>
						<p class="description" id="prdpos-preview-note"><?php esc_html_e( 'Select a product to preview its label.', 'prdrwndn-pos' ); ?></p>
					</section>

					<section class="prdpos-card">
						<h2><?php esc_html_e( 'Printer', 'prdrwndn-pos' ); ?></h2>
						<p class="prdpos-status" id="prdpos-qz-status" role="status"><?php esc_html_e( 'Not connected to QZ Tray.', 'prdrwndn-pos' ); ?></p>
						<p>
							<label for="prdpos-printer"><?php esc_html_e( 'Printer', 'prdrwndn-pos' ); ?></label><br>
							<select id="prdpos-printer" disabled><option value=""><?php esc_html_e( 'Connect first', 'prdrwndn-pos' ); ?></option></select>
						</p>
						<p class="prdpos-actions">
							<button type="button" class="button" id="prdpos-connect"><?php esc_html_e( 'Connect to QZ Tray', 'prdrwndn-pos' ); ?></button>
							<button type="button" class="button button-primary" id="prdpos-print" disabled><?php esc_html_e( 'Print labels', 'prdrwndn-pos' ); ?></button>
						</p>
						<p class="prdpos-total" id="prdpos-total" aria-live="polite"></p>
						<p><button type="button" class="button-link" id="prdpos-browser-print"><?php esc_html_e( 'No QZ Tray? Print with the browser instead', 'prdrwndn-pos' ); ?></button></p>
						<?php if ( ! PRDRWNDN_POS_QZ::ready() && current_user_can( PRDRWNDN_POS_Roles::CAP_MANAGE ) ) : ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: settings link */
									esc_html__( 'QZ Tray will ask permission each time until silent printing is set up in %s.', 'prdrwndn-pos' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=prdrwndn-pos-settings' ) ) . '">' . esc_html__( 'Settings', 'prdrwndn-pos' ) . '</a>'
								);
								?>
							</p>
						<?php endif; ?>
					</section>

					<section class="prdpos-card">
						<h2><?php esc_html_e( 'Scan test', 'prdrwndn-pos' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Scan a label or a UPC here (or type it and press Enter) to check it finds the right product.', 'prdrwndn-pos' ); ?></p>
						<form id="prdpos-scan-form">
							<label class="screen-reader-text" for="prdpos-scan"><?php esc_html_e( 'Code', 'prdrwndn-pos' ); ?></label>
							<input type="text" id="prdpos-scan" autocomplete="off" placeholder="<?php esc_attr_e( 'Scan or type a code', 'prdrwndn-pos' ); ?>">
							<button type="submit" class="button"><?php esc_html_e( 'Find', 'prdrwndn-pos' ); ?></button>
						</form>
						<div id="prdpos-scan-result" aria-live="polite"></div>
					</section>
				</aside>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* SKUs & barcodes                                                     */
	/* ------------------------------------------------------------------ */

	public static function page_skus() {
		if ( ! current_user_can( PRDRWNDN_POS_Roles::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have access to this page.', 'prdrwndn-pos' ) );
		}
		$s        = self::settings();
		$rows     = PRDRWNDN_POS_SKU_Builder::rows( $s['prefix'] );
		$new      = count( array_filter( wp_list_pluck( $rows, 'suggested' ) ) );
		$has_gtin = method_exists( 'WC_Product', 'get_global_unique_id' );
		self::notice();
		?>
		<div class="wrap prdpos">
			<h1><?php esc_html_e( 'SKUs & barcodes', 'prdrwndn-pos' ); ?></h1>
			<p class="prdpos-lead">
				<?php esc_html_e( 'Every product and every option (scent, color, size) gets its own number barcode automatically. Products with a real UPC from GS1 use it; everything else gets an in-store number starting with 4. SKUs are the short codes you read on labels and in orders. Suggested SKUs are filled in where a product has none. Check them, change any you like, then save.', 'prdrwndn-pos' ); ?>
			</p>
			<?php if ( $new ) : ?>
				<div class="notice notice-info inline"><p>
					<?php
					printf(
						/* translators: %d: number of products */
						esc_html( _n( '%d item has no SKU yet. A suggestion is filled in and highlighted.', '%d items have no SKU yet. Suggestions are filled in and highlighted.', $new, 'prdrwndn-pos' ) ),
						(int) $new
					);
					?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'prdrwndn_pos_save_skus' ); ?>
				<input type="hidden" name="action" value="prdrwndn_pos_save_skus">
				<table class="widefat striped prdpos-table prdpos-skus">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'prdrwndn-pos' ); ?></th>
							<th class="num"><?php esc_html_e( 'Stock', 'prdrwndn-pos' ); ?></th>
							<th><?php esc_html_e( 'SKU', 'prdrwndn-pos' ); ?></th>
							<?php if ( $has_gtin ) : ?>
								<th><?php esc_html_e( 'UPC / GTIN (optional)', 'prdrwndn-pos' ); ?></th>
							<?php endif; ?>
							<th><?php esc_html_e( 'Barcode number', 'prdrwndn-pos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $r ) : ?>
							<?php
							$is_parent = 'variable' === $r['type'];
							$value     = '' !== $r['sku'] ? $r['sku'] : $r['suggested'];
							$is_new    = '' === $r['sku'] && '' !== $r['suggested'];
							$real_upc  = PRDRWNDN_POS_UPC::upc_from_gtin( $r['gtin'] );
							$number    = '' !== $real_upc ? $real_upc : PRDRWNDN_POS_UPC::internal_number( $r['id'] );
							$row_class = $is_parent ? 'prdpos-parent' : ( $r['parent_id'] ? 'prdpos-child' : '' );
							?>
							<tr class="<?php echo esc_attr( $row_class ); ?>">
								<td>
									<strong><?php echo esc_html( $r['variant'] ? $r['variant'] : $r['title'] ); ?></strong>
									<?php if ( $is_parent ) : ?>
										<span class="prdpos-muted"><?php esc_html_e( 'Main product (labels use the options below)', 'prdrwndn-pos' ); ?></span>
									<?php elseif ( $r['parent_id'] ) : ?>
										<span class="prdpos-muted"><?php echo esc_html( $r['title'] ); ?></span>
									<?php endif; ?>
								</td>
								<td class="num"><?php echo null === $r['stock'] ? '-' : (int) $r['stock']; ?></td>
								<td>
									<label class="screen-reader-text" for="sku-<?php echo (int) $r['id']; ?>"><?php esc_html_e( 'SKU', 'prdrwndn-pos' ); ?></label>
									<input type="text" class="prdpos-sku<?php echo $is_new ? ' is-new' : ''; ?>" id="sku-<?php echo (int) $r['id']; ?>" name="sku[<?php echo (int) $r['id']; ?>]" value="<?php echo esc_attr( $value ); ?>" data-current="<?php echo esc_attr( $r['sku'] ); ?>" maxlength="40" spellcheck="false">
									<?php if ( $is_new ) : ?>
										<span class="prdpos-badge"><?php esc_html_e( 'Suggested', 'prdrwndn-pos' ); ?></span>
									<?php endif; ?>
								</td>
								<?php if ( $has_gtin ) : ?>
									<td>
										<label class="screen-reader-text" for="gtin-<?php echo (int) $r['id']; ?>"><?php esc_html_e( 'UPC / GTIN', 'prdrwndn-pos' ); ?></label>
										<input type="text" inputmode="numeric" class="prdpos-gtin" id="gtin-<?php echo (int) $r['id']; ?>" name="gtin[<?php echo (int) $r['id']; ?>]" value="<?php echo esc_attr( $r['gtin'] ); ?>" maxlength="14" spellcheck="false">
									</td>
								<?php endif; ?>
								<td class="prdpos-bc">
									<?php if ( ! $is_parent ) : ?>
										<?php echo PRDRWNDN_POS_UPC::svg( $number, 1, 30 ); // phpcs:ignore WordPress.Security.EscapeOutput -- SVG built from escaped parts. ?>
										<span class="prdpos-muted"><?php echo '' !== $real_upc ? esc_html__( 'Real UPC', 'prdrwndn-pos' ) : esc_html__( 'In-store number', 'prdrwndn-pos' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'SKUs use capital letters, numbers and dashes only. Only type in the UPC / GTIN box for a real barcode number from GS1, like your Black Ice freshener. In-store numbers (starting with 4) are made for you and never go in that box, because Google Shopping rejects them.', 'prdrwndn-pos' ); ?>
				</p>
				<?php submit_button( __( 'Save SKUs', 'prdrwndn-pos' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_skus() {
		if ( ! current_user_can( PRDRWNDN_POS_Roles::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have access to this page.', 'prdrwndn-pos' ) );
		}
		check_admin_referer( 'prdrwndn_pos_save_skus' );

		$skus  = isset( $_POST['sku'] ) ? (array) wp_unslash( $_POST['sku'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$gtins = isset( $_POST['gtin'] ) ? (array) wp_unslash( $_POST['gtin'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ids   = array_unique( array_merge( array_keys( $skus ), array_keys( $gtins ) ) );

		$changed = 0;
		$errors  = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( absint( $id ) );
			if ( ! $product ) {
				continue;
			}
			$label = self::item_label( $product );
			$dirty = false;

			if ( isset( $skus[ $id ] ) ) {
				$new = PRDRWNDN_POS_SKU_Builder::sanitize( $skus[ $id ] );
				if ( $new !== $product->get_sku( 'edit' ) ) {
					try {
						$product->set_sku( $new );
						$dirty = true;
					} catch ( Exception $e ) {
						/* translators: 1: product, 2: SKU */
						$errors[] = sprintf( __( '%1$s: the SKU %2$s is already used by another product.', 'prdrwndn-pos' ), $label, $new );
					}
				}
			}

			if ( isset( $gtins[ $id ] ) && method_exists( $product, 'set_global_unique_id' ) ) {
				$gtin = preg_replace( '/\D+/', '', (string) $gtins[ $id ] );
				if ( $gtin !== $product->get_global_unique_id( 'edit' ) ) {
					if ( '' !== $gtin && ! PRDRWNDN_POS_SKU_Builder::valid_gtin( $gtin ) ) {
						/* translators: 1: product, 2: number */
						$errors[] = sprintf( __( '%1$s: %2$s is not a valid UPC / GTIN (check the digits).', 'prdrwndn-pos' ), $label, $gtin );
					} elseif ( '' !== $gtin && PRDRWNDN_POS_UPC::is_restricted( $gtin ) ) {
						/* translators: 1: product, 2: number */
						$errors[] = sprintf( __( '%1$s: %2$s is an in-store number, not a GS1 UPC. Leave the UPC / GTIN box empty; the plugin gives every product its in-store number automatically.', 'prdrwndn-pos' ), $label, $gtin );
					} else {
						try {
							$product->set_global_unique_id( $gtin );
							$dirty = true;
						} catch ( Exception $e ) {
							/* translators: 1: product, 2: number */
							$errors[] = sprintf( __( '%1$s: the UPC / GTIN %2$s is already used by another product.', 'prdrwndn-pos' ), $label, $gtin );
						}
					}
				}
			}

			if ( $dirty ) {
				$product->save();
				$changed++;
			}
		}

		self::set_notice(
			array(
				/* translators: %d: count */
				'ok'     => sprintf( _n( 'Saved %d product.', 'Saved %d products.', $changed, 'prdrwndn-pos' ), $changed ),
				'errors' => $errors,
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=prdrwndn-pos-skus' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function page_settings() {
		if ( ! current_user_can( PRDRWNDN_POS_Roles::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have access to this page.', 'prdrwndn-pos' ) );
		}
		$s = self::settings();
		self::notice();
		?>
		<div class="wrap prdpos">
			<h1><?php esc_html_e( 'PRDRWNDN POS settings', 'prdrwndn-pos' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'prdrwndn_pos_save_settings' ); ?>
				<input type="hidden" name="action" value="prdrwndn_pos_save_settings">

				<h2><?php esc_html_e( 'SKUs and labels', 'prdrwndn-pos' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="prdpos-prefix"><?php esc_html_e( 'SKU prefix', 'prdrwndn-pos' ); ?></label></th>
						<td><input type="text" id="prdpos-prefix" name="prefix" value="<?php echo esc_attr( $s['prefix'] ); ?>" maxlength="6" class="small-text" spellcheck="false">
						<p class="description"><?php esc_html_e( 'Starts every suggested SKU, for example PRD-FR-DR. Keep it short so barcodes stay big.', 'prdrwndn-pos' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="prdpos-size-default"><?php esc_html_e( 'Default label size', 'prdrwndn-pos' ); ?></label></th>
						<td><select id="prdpos-size-default" name="label_size">
							<?php foreach ( self::LABEL_SIZES as $k => $label ) : ?>
								<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['label_size'], $k ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Price on labels', 'prdrwndn-pos' ); ?></th>
						<td><label><input type="checkbox" name="show_price" value="1" <?php checked( $s['show_price'] ); ?>> <?php esc_html_e( 'Show the price by default', 'prdrwndn-pos' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="prdpos-printer-default"><?php esc_html_e( 'Label printer', 'prdrwndn-pos' ); ?></label></th>
						<td><input type="text" id="prdpos-printer-default" name="printer" value="<?php echo esc_attr( $s['printer'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'Optional. Part of the printer name as your Mac shows it, for example CTP800BD. The labels page picks it automatically.', 'prdrwndn-pos' ); ?></p></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Deals in the POS', 'prdrwndn-pos' ); ?></h2>
				<p><?php esc_html_e( 'Bundle prices the POS applies by itself, for example any 2 jerseys for $80. Put the products in a category (like Jerseys), then add the deal here. Leave a row empty to remove it.', 'prdrwndn-pos' ); ?></p>
				<?php
				$cats  = get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'hide_empty' => false,
					)
				);
				$deals = PRDRWNDN_POS_Sales::deals();
				$rows  = array_merge( $deals, array_fill( 0, 2, array( 'label' => '', 'cat' => 0, 'qty' => '', 'price' => '' ) ) );
				?>
				<table class="widefat striped prdpos-deals">
					<thead><tr>
						<th><?php esc_html_e( 'Name on the receipt', 'prdrwndn-pos' ); ?></th>
						<th><?php esc_html_e( 'Category', 'prdrwndn-pos' ); ?></th>
						<th><?php esc_html_e( 'How many', 'prdrwndn-pos' ); ?></th>
						<th><?php esc_html_e( 'Price for the bundle', 'prdrwndn-pos' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rows as $i => $d ) : ?>
						<tr>
							<td><label class="screen-reader-text" for="deal-label-<?php echo (int) $i; ?>"><?php esc_html_e( 'Name', 'prdrwndn-pos' ); ?></label><input type="text" id="deal-label-<?php echo (int) $i; ?>" name="deal_label[]" value="<?php echo esc_attr( $d['label'] ); ?>" placeholder="2 jerseys for $80" class="regular-text"></td>
							<td><label class="screen-reader-text" for="deal-cat-<?php echo (int) $i; ?>"><?php esc_html_e( 'Category', 'prdrwndn-pos' ); ?></label><select id="deal-cat-<?php echo (int) $i; ?>" name="deal_cat[]">
								<option value="0"><?php esc_html_e( 'Choose', 'prdrwndn-pos' ); ?></option>
								<?php foreach ( (array) $cats as $c ) : ?>
									<?php if ( $c instanceof WP_Term ) : ?>
									<option value="<?php echo (int) $c->term_id; ?>" <?php selected( (int) $d['cat'], (int) $c->term_id ); ?>><?php echo esc_html( $c->name ); ?></option>
									<?php endif; ?>
								<?php endforeach; ?>
							</select></td>
							<td><label class="screen-reader-text" for="deal-qty-<?php echo (int) $i; ?>"><?php esc_html_e( 'How many', 'prdrwndn-pos' ); ?></label><input type="number" min="2" step="1" id="deal-qty-<?php echo (int) $i; ?>" name="deal_qty[]" value="<?php echo esc_attr( $d['qty'] ); ?>" class="small-text"></td>
							<td><label class="screen-reader-text" for="deal-price-<?php echo (int) $i; ?>"><?php esc_html_e( 'Bundle price', 'prdrwndn-pos' ); ?></label><input type="number" min="0" step="0.01" id="deal-price-<?php echo (int) $i; ?>" name="deal_price[]" value="<?php echo esc_attr( $d['price'] ); ?>" class="small-text"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h2 id="google"><?php esc_html_e( 'Sign in with Google', 'prdrwndn-pos' ); ?></h2>
				<?php
				$g      = PRDRWNDN_POS_Google::settings();
				$source = PRDRWNDN_POS_Google::source();
				?>
				<p><?php esc_html_e( 'Adds a Sign in with Google button to the POS sign-in screen. Passwords keep working. Google only lets someone in when their Google email matches a store account with POS access. It never creates accounts, so add your helpers in Users first (role: POS Cashier).', 'prdrwndn-pos' ); ?></p>
				<p class="prdpos-status <?php echo $source && $g['enabled'] ? 'is-ok' : ''; ?>">
					<?php
					if ( 'sitekit' === $source ) {
						esc_html_e( 'Using the Google setup from Site Kit.', 'prdrwndn-pos' );
					} elseif ( 'own' === $source ) {
						esc_html_e( 'Using the Client ID below.', 'prdrwndn-pos' );
					} else {
						esc_html_e( 'Not set up yet. In Site Kit, go to Settings, then Connect more services, and set up Sign in with Google. The POS uses the same setup.', 'prdrwndn-pos' );
					}
					?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'POS button', 'prdrwndn-pos' ); ?></th>
						<td><label><input type="checkbox" name="google_enabled" value="1" <?php checked( ! empty( $g['enabled'] ) ); ?>> <?php esc_html_e( 'Show Sign in with Google on the POS', 'prdrwndn-pos' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'One step in Google Cloud', 'prdrwndn-pos' ); ?></th>
						<td>
							<p><?php esc_html_e( 'Google sends people straight back to the POS, so it needs the POS address once. In Google Cloud, open APIs & Services, then Credentials, then your OAuth client (the one Site Kit uses). Under Authorized redirect URIs, add this exact address and save:', 'prdrwndn-pos' ); ?></p>
							<p><input type="text" readonly class="regular-text code" value="<?php echo esc_attr( PRDRWNDN_POS_Google::redirect_uri() ); ?>" onclick="this.select()" aria-label="<?php esc_attr_e( 'POS address for Google', 'prdrwndn-pos' ); ?>"></p>
							<p class="description"><?php esc_html_e( 'If this is missing, Google shows an error called redirect_uri_mismatch when someone taps the button.', 'prdrwndn-pos' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="prdpos-google-client"><?php esc_html_e( 'Client ID (optional)', 'prdrwndn-pos' ); ?></label></th>
						<td><input type="text" id="prdpos-google-client" name="google_client_id" value="<?php echo esc_attr( $g['client_id'] ); ?>" class="large-text code" spellcheck="false" placeholder="<?php echo esc_attr( PRDRWNDN_POS_Google::sitekit_client_id() ? PRDRWNDN_POS_Google::sitekit_client_id() : '1234567890-abc.apps.googleusercontent.com' ); ?>">
						<p class="description"><?php esc_html_e( 'Leave empty to use Site Kit\'s. Only fill this in to use a different Google client for the POS.', 'prdrwndn-pos' ); ?></p></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Silent printing with QZ Tray', 'prdrwndn-pos' ); ?></h2>
				<p><?php esc_html_e( 'Without this, QZ Tray asks for permission every time you print. To turn the prompt off:', 'prdrwndn-pos' ); ?></p>
				<ol class="prdpos-steps">
					<li><?php esc_html_e( 'On your Mac, click the QZ Tray icon in the menu bar, then Advanced, then Site Manager.', 'prdrwndn-pos' ); ?></li>
					<li><?php esc_html_e( 'Click the + button, choose Create New, and answer Yes to each question. QZ Tray now trusts this certificate on that Mac.', 'prdrwndn-pos' ); ?></li>
					<li><?php esc_html_e( 'Open the folder QZ Tray shows. Copy everything in digital-certificate.txt into the first box below, and everything in private-key.pem into the second box.', 'prdrwndn-pos' ); ?></li>
					<li><?php esc_html_e( 'Save, then restart QZ Tray. Any other computer that prints labels needs the same certificate added to its QZ Tray.', 'prdrwndn-pos' ); ?></li>
				</ol>
				<p class="prdpos-status <?php echo PRDRWNDN_POS_QZ::ready() ? 'is-ok' : ''; ?>">
					<?php echo PRDRWNDN_POS_QZ::ready() ? esc_html__( 'Silent printing is set up.', 'prdrwndn-pos' ) : esc_html__( 'Silent printing is not set up.', 'prdrwndn-pos' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="prdpos-cert"><?php esc_html_e( 'Certificate', 'prdrwndn-pos' ); ?></label></th>
						<td><textarea id="prdpos-cert" name="qz_cert" rows="6" class="large-text code" spellcheck="false" placeholder="-----BEGIN CERTIFICATE-----"><?php echo esc_textarea( PRDRWNDN_POS_QZ::certificate() ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="prdpos-key"><?php esc_html_e( 'Private key', 'prdrwndn-pos' ); ?></label></th>
						<td><textarea id="prdpos-key" name="qz_key" rows="6" class="large-text code" spellcheck="false" autocomplete="off" placeholder="<?php echo PRDRWNDN_POS_QZ::has_key() ? esc_attr__( 'Saved. Leave empty to keep it, or paste a new key to replace it.', 'prdrwndn-pos' ) : '-----BEGIN PRIVATE KEY-----'; ?>"></textarea>
						<p class="description"><?php esc_html_e( 'The key is stored encrypted on your server and is never shown again. To turn silent printing off, empty the certificate box and save.', 'prdrwndn-pos' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'prdrwndn-pos' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_settings() {
		if ( ! current_user_can( PRDRWNDN_POS_Roles::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'You do not have access to this page.', 'prdrwndn-pos' ) );
		}
		check_admin_referer( 'prdrwndn_pos_save_settings' );

		$prefix = PRDRWNDN_POS_SKU_Builder::sanitize( wp_unslash( $_POST['prefix'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$size   = sanitize_text_field( wp_unslash( $_POST['label_size'] ?? '' ) );
		update_option(
			self::OPT,
			array(
				'prefix'     => substr( $prefix, 0, 6 ),
				'label_size' => isset( self::LABEL_SIZES[ $size ] ) ? $size : '2.25x1.25',
				'show_price' => empty( $_POST['show_price'] ) ? 0 : 1,
				'printer'    => sanitize_text_field( wp_unslash( $_POST['printer'] ?? '' ) ),
			)
		);

		$deals  = array();
		$labels = (array) wp_unslash( $_POST['deal_label'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$dcats  = (array) wp_unslash( $_POST['deal_cat'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$dqty   = (array) wp_unslash( $_POST['deal_qty'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$dprice = (array) wp_unslash( $_POST['deal_price'] ?? array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( $dcats as $i => $cat ) {
			$cat   = absint( $cat );
			$qty   = absint( $dqty[ $i ] ?? 0 );
			$price = isset( $dprice[ $i ] ) && '' !== $dprice[ $i ] ? (float) $dprice[ $i ] : -1;
			if ( $cat && $qty >= 2 && $price >= 0 ) {
				$deals[] = array(
					'label' => sanitize_text_field( $labels[ $i ] ?? '' ),
					'cat'   => $cat,
					'qty'   => $qty,
					'price' => round( $price, 2 ),
				);
			}
		}
		update_option( PRDRWNDN_POS_Sales::OPT_DEALS, $deals, false );

		PRDRWNDN_POS_Google::save( ! empty( $_POST['google_enabled'] ), sanitize_text_field( wp_unslash( $_POST['google_client_id'] ?? '' ) ) );

		$cert   = isset( $_POST['qz_cert'] ) ? sanitize_textarea_field( wp_unslash( $_POST['qz_cert'] ) ) : '';
		$key    = isset( $_POST['qz_key'] ) ? sanitize_textarea_field( wp_unslash( $_POST['qz_key'] ) ) : '';
		$result = PRDRWNDN_POS_QZ::save( $cert, $key );

		self::set_notice(
			array(
				'ok'     => is_wp_error( $result ) ? __( 'Your other settings were saved. Silent printing was not changed.', 'prdrwndn-pos' ) : __( 'Settings saved.', 'prdrwndn-pos' ),
				'errors' => is_wp_error( $result ) ? array( $result->get_error_message() ) : array(),
			)
		);
		wp_safe_redirect( admin_url( 'admin.php?page=prdrwndn-pos-settings' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private static function item_label( $product ) {
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			return ( $parent ? $parent->get_name() : '' ) . ' (' . wc_get_formatted_variation( $product, true, false, false ) . ')';
		}
		return $product->get_name();
	}

	private static function set_notice( $notice ) {
		set_transient( 'prdrwndn_pos_notice_' . get_current_user_id(), $notice, 60 );
	}

	private static function notice() {
		$key    = 'prdrwndn_pos_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		if ( ! empty( $notice['errors'] ) ) {
			echo '<div class="notice notice-error"><ul>';
			foreach ( $notice['errors'] as $e ) {
				echo '<li>' . esc_html( $e ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( ! empty( $notice['ok'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice['ok'] ) . '</p></div>';
		}
	}
}
