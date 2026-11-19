<?php
/**
 * Product Data "Swatches & Gallery" Metabox Manager
 *
 * Responsibilities:
 * 1. Registers the "Swatches & Gallery" tab inside WooCommerce's Product Data metabox
 *    for variable products (`woocommerce_product_data_tabs` / `_panels`).
 * 2. Renders the live Stock-Winning Media Status Banner (active winning variation,
 *    stock count, gallery count, thumbnail, and "Recalculate Now" AJAX button) plus
 *    a subtle dynamic indicator on the WP Admin Products list table.
 * 3. Allows per-product Flatsome Archive Hover Mode, Slideshow Delay, Primary Archive
 *    Attribute, and Lock Static Parent Image overrides.
 * 4. Enables in-product Attribute Swatch Type assignment (Color, Dual Color, Image,
 *    Auto Variation Image, Button, Radio, Select) for both global `pa_*` and custom
 *    local attributes, with optional two-way sync back to global term meta.
 * 5. Provides per-term swatch overrides and Attribute-Level (e.g. Color-Level) sortable
 *    gallery uploaders so teams upload a color's gallery once for all sizes.
 * 6. Exposes reusable resolution helpers (`resolve_attribute_type`, `get_term_override`)
 *    for the frontend swatch and archive renderers.
 *
 * @package WooVariationGalleryStudio\Admin
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WVGS_Product_Metabox', false ) ) :

	/**
	 * Class WVGS_Product_Metabox
	 *
	 * Manages the per-product Swatches & Gallery configuration panel and metadata.
	 */
	final class WVGS_Product_Metabox {

		/**
		 * Post meta key storing all per-product WVGS configuration on the parent product.
		 *
		 * @var string
		 */
		public const META_KEY = '_wvgs_product_settings';

		/**
		 * Register WooCommerce Product Data tab, save, AJAX, and Products list hooks.
		 *
		 * @return void
		 */
		public static function init(): void {
			// 1. Product Data tab & panel.
			add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_data_tab' ), 20 );
			add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_data_panel' ) );

			// 2. Save handler runs at priority 10 (before Stock Resolver runs on update_product at priority 20).
			add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_data' ), 10 );

			// 3. Live AJAX refresh when attributes are added/saved without reloading the edit screen.
			add_action( 'wp_ajax_wvgs_load_product_attribute_swatches', array( __CLASS__, 'ajax_load_attribute_swatches' ) );

			// 4. Admin transparency badge in the WP Admin > Products list table thumbnail column.
			add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_admin_list_winner_badge' ), 20, 2 );
		}

		/**
		 * Register the "Swatches & Gallery" tab in the Product Data metabox.
		 *
		 * @param array<string, array<string, mixed>> $tabs Existing Product Data tabs.
		 * @return array<string, array<string, mixed>>
		 */
		public static function add_product_data_tab( array $tabs ): array {
			$tabs['wvgs_swatches'] = array(
				'label'    => __( 'Swatches & Gallery', 'woo-variation-gallery-studio' ),
				'target'   => 'wvgs_swatches_data',
				'class'    => array( 'show_if_variable' ),
				'priority' => 65,
			);

			return $tabs;
		}

		/**
		 * Render the complete "Swatches & Gallery" panel inside Product Data.
		 *
		 * @return void
		 */
		public static function render_product_data_panel(): void {
			global $post;

			if ( ! $post instanceof \WP_Post ) {
				return;
			}

			$product_id      = (int) $post->ID;
			$settings        = self::get_product_settings( $product_id );
			$global_settings = WVGS_Plugin::instance()->get_settings();

			$hover_mode        = (string) ( $settings['archive_hover_mode'] ?? '' );
			$slideshow_delay   = ! empty( $settings['archive_slideshow_delay'] ) ? (string) absint( $settings['archive_slideshow_delay'] ) : '';
			$archive_attribute = (string) ( $settings['archive_attribute'] ?? '' );
			$lock_parent       = ! empty( $settings['lock_parent_image'] ) ? 'yes' : 'no';

			$product_attr_options = self::get_product_variation_attribute_options( $product_id );
			?>
			<div id="wvgs_swatches_data" class="panel woocommerce_options_panel hidden">
				<?php wp_nonce_field( 'wvgs_save_product_settings', 'wvgs_product_settings_nonce' ); ?>

				<?php
				// Section 1: Live Stock-Winning Media Status Banner + Recalculate Button.
				self::render_stock_winner_banner( $product_id, 'yes' === $lock_parent );
				?>

				<!-- Section 2: Flatsome Archive Hover & Parent Image Controls -->
				<div class="options_group wvgs-panel-section">
					<p class="form-field wvgs-section-heading">
						<strong><?php esc_html_e( 'Flatsome Archive Hover & Dynamic Media Overrides', 'woo-variation-gallery-studio' ); ?></strong>
					</p>
					<?php
					woocommerce_wp_select(
						array(
							'id'          => '_wvgs_archive_hover_mode',
							'label'       => __( 'Archive Hover Mode', 'woo-variation-gallery-studio' ),
							'value'       => $hover_mode,
							'options'     => array(
								''                    => sprintf(
									/* translators: %s: Global hover mode label. */
									__( 'Inherit Global Default (%s)', 'woo-variation-gallery-studio' ),
									self::get_hover_mode_label( (string) $global_settings['archive_hover_mode'] )
								),
								'variation_gallery'   => __( 'Mode 1: Active Variation\'s 2nd Image (Gallery)', 'woo-variation-gallery-studio' ),
								'next_variation'      => __( 'Mode 2: Next Available Variation\'s Main Image', 'woo-variation-gallery-studio' ),
								'variation_slideshow' => __( 'Mode 3: Slideshow All Available Variations', 'woo-variation-gallery-studio' ),
							),
							'desc_tip'    => true,
							'description' => __( 'Overrides the global Flatsome shop card hover effect for this specific product.', 'woo-variation-gallery-studio' ),
						)
					);

					woocommerce_wp_text_input(
						array(
							'id'                => '_wvgs_archive_slideshow_delay',
							'label'             => __( 'Slideshow Delay (ms)', 'woo-variation-gallery-studio' ),
							'value'             => $slideshow_delay,
							'placeholder'       => (string) (int) $global_settings['archive_slideshow_delay'],
							'type'              => 'number',
							'custom_attributes' => array(
								'min'  => '500',
								'max'  => '10000',
								'step' => '100',
							),
							'desc_tip'          => true,
							'description'       => __( 'Leave blank to inherit the global slideshow delay (500–10000 ms). Only applies to Mode 3 (Slideshow).', 'woo-variation-gallery-studio' ),
						)
					);

					woocommerce_wp_select(
						array(
							'id'          => '_wvgs_archive_attribute',
							'label'       => __( 'Primary Archive Swatch Attribute', 'woo-variation-gallery-studio' ),
							'value'       => $archive_attribute,
							'options'     => $product_attr_options,
							'desc_tip'    => true,
							'description' => __( 'Choose which attribute renders swatches on shop cards and deduplicates hover slideshow frames for this product.', 'woo-variation-gallery-studio' ),
						)
					);

					woocommerce_wp_checkbox(
						array(
							'id'          => '_wvgs_lock_parent_image',
							'label'       => __( 'Lock Static Parent Image', 'woo-variation-gallery-studio' ),
							'value'       => $lock_parent,
							'cbvalue'     => 'yes',
							'description' => __( 'Disable dynamic stock-winning image promotion for this product and keep manually uploaded parent images.', 'woo-variation-gallery-studio' ),
						)
					);
					?>
				</div>

				<!-- Section 3: In-Product Attribute Swatch Types & Attribute-Level Galleries -->
				<div class="options_group wvgs-attributes-swatch-config">
					<div class="wvgs-section-toolbar">
						<div>
							<strong><?php esc_html_e( 'Attribute Swatch Types, Term Overrides & Attribute-Level Galleries', 'woo-variation-gallery-studio' ); ?></strong>
							<p class="description">
								<?php esc_html_e( 'Assign swatch types per attribute right here without leaving the product editor. Upload an Attribute-Level Gallery (e.g. once for "Black") so every in-stock Black size automatically inherits those images.', 'woo-variation-gallery-studio' ); ?>
							</p>
						</div>
						<button
							type="button"
							class="button button-secondary"
							id="wvgs-refresh-attributes"
							data-product_id="<?php echo esc_attr( (string) $product_id ); ?>"
						>
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( 'Refresh Attributes', 'woo-variation-gallery-studio' ); ?>
						</button>
					</div>

					<div id="wvgs-product-attributes-container" data-product_id="<?php echo esc_attr( (string) $product_id ); ?>">
						<?php self::render_attributes_config_html( $product_id, $settings ); ?>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Render the live Stock-Winning Media Status Banner at the top of the panel.
		 *
		 * @param int  $product_id  Parent variable product ID.
		 * @param bool $is_locked   Whether static parent image lock is enabled.
		 * @return void
		 */
		private static function render_stock_winner_banner( int $product_id, bool $is_locked ): void {
			$resolved = null;
			if ( $product_id > 0 && null !== WVGS_Plugin::instance()->resolver ) {
				$resolved = WVGS_Plugin::instance()->resolver->get_resolved_media( $product_id );
			}

			$winner_id     = is_array( $resolved ) ? (int) ( $resolved['winner_variation_id'] ?? 0 ) : 0;
			$winner_label  = is_array( $resolved ) ? (string) ( $resolved['winner_label'] ?? '' ) : '';
			$winner_qty    = is_array( $resolved ) ? ( $resolved['winner_stock_qty'] ?? null ) : null;
			$winner_tier   = is_array( $resolved ) ? (int) ( $resolved['winner_tier'] ?? 4 ) : 4;
			$main_image_id = is_array( $resolved ) ? (int) ( $resolved['main_image_id'] ?? 0 ) : 0;
			$gallery_count = is_array( $resolved ) ? count( (array) ( $resolved['gallery_ids'] ?? array() ) ) : 0;
			$thumb_url     = $main_image_id > 0 ? (string) wp_get_attachment_image_url( $main_image_id, 'thumbnail' ) : '';

			if ( 1 === $winner_tier && null !== $winner_qty ) {
				/* translators: %d: Stock quantity. */
				$stock_text = sprintf( __( 'Stock: %d', 'woo-variation-gallery-studio' ), (int) $winner_qty );
			} elseif ( 2 === $winner_tier ) {
				$stock_text = __( 'In Stock (Unquantified)', 'woo-variation-gallery-studio' );
			} elseif ( 3 === $winner_tier ) {
				$stock_text = __( 'On Backorder', 'woo-variation-gallery-studio' );
			} else {
				$stock_text = __( 'Fallback / Out of Stock', 'woo-variation-gallery-studio' );
			}
			?>
			<div class="wvgs-stock-winner-banner<?php echo $is_locked ? ' is-locked' : ''; ?>" id="wvgs-stock-winner-banner">
				<div class="wvgs-winner-thumb">
					<?php if ( '' !== $thumb_url ) : ?>
						<img src="<?php echo esc_url( $thumb_url ); ?>" alt="" />
					<?php else : ?>
						<span class="dashicons dashicons-format-image"></span>
					<?php endif; ?>
				</div>
				<div class="wvgs-winner-meta">
					<div class="wvgs-winner-title">
						<?php if ( $is_locked ) : ?>
							<span class="wvgs-status-pill is-warning"><?php esc_html_e( 'Static Parent Image Locked', 'woo-variation-gallery-studio' ); ?></span>
						<?php elseif ( $winner_id > 0 ) : ?>
							<span class="wvgs-status-pill is-active"><?php esc_html_e( 'Dynamic Stock Winner Active', 'woo-variation-gallery-studio' ); ?></span>
						<?php else : ?>
							<span class="wvgs-status-pill is-neutral"><?php esc_html_e( 'Pending Variations', 'woo-variation-gallery-studio' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="wvgs-winner-details" id="wvgs-winner-details-text">
						<?php if ( $winner_id > 0 ) : ?>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: Winning variation label, 2: Variation ID, 3: Stock description, 4: Gallery image count. */
									__( 'Current Winner: %1$s (#%2$d) — %3$s — %4$d Additional Gallery Images', 'woo-variation-gallery-studio' ),
									$winner_label,
									$winner_id,
									$stock_text,
									$gallery_count
								)
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'Add published in-stock variations with images (or Attribute-Level Galleries below) and save to calculate the Stock Winner.', 'woo-variation-gallery-studio' ); ?>
						<?php endif; ?>
					</div>
				</div>
				<div class="wvgs-winner-actions">
					<button
						type="button"
						class="button button-secondary"
						id="wvgs-recalculate-stock-winner"
						data-product_id="<?php echo esc_attr( (string) $product_id ); ?>"
					>
						<?php esc_html_e( 'Recalculate Stock Winner Now', 'woo-variation-gallery-studio' ); ?>
					</button>
				</div>
			</div>
			<?php
		}

		/**
		 * Render all variation attribute accordions with Attribute Type selectors,
		 * per-term Color/Dual-Color/Image overrides, and Attribute-Level Gallery uploaders.
		 *
		 * @param int                  $product_id Parent variable product ID.
		 * @param array<string, mixed> $settings   Current `_wvgs_product_settings` array.
		 * @return void
		 */
		public static function render_attributes_config_html( int $product_id, array $settings ): void {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product ) {
				echo '<p class="wvgs-empty-state">' . esc_html__( 'Save this product as a draft first to configure per-product swatches and attribute galleries.', 'woo-variation-gallery-studio' ) . '</p>';
				return;
			}

			$attributes          = $product->get_attributes();
			$attribute_types     = is_array( $settings['attribute_types'] ?? null ) ? $settings['attribute_types'] : array();
			$term_overrides      = is_array( $settings['attribute_term_overrides'] ?? null ) ? $settings['attribute_term_overrides'] : array();
			$attribute_galleries = is_array( $settings['attribute_galleries'] ?? null ) ? $settings['attribute_galleries'] : array();

			$variation_attributes = array();
			foreach ( $attributes as $attribute ) {
				if ( $attribute instanceof \WC_Product_Attribute && $attribute->get_variation() ) {
					$variation_attributes[] = $attribute;
				}
			}

			if ( empty( $variation_attributes ) ) {
				echo '<p class="wvgs-empty-state">' . esc_html__( 'No variation attributes found yet. Add attributes in the "Attributes" tab, check "Used for variations", click "Save attributes", and then click "Refresh Attributes" above.', 'woo-variation-gallery-studio' ) . '</p>';
				return;
			}

			$can_manage_terms = current_user_can( 'manage_product_terms' );

			foreach ( $variation_attributes as $attribute ) {
				$is_taxonomy = $attribute->is_taxonomy();
				$raw_name    = (string) $attribute->get_name();
				$attr_key    = $is_taxonomy ? $raw_name : sanitize_title( $raw_name );
				$attr_label  = $is_taxonomy ? wc_attribute_label( $raw_name ) : $raw_name;

				$global_type   = ( $is_taxonomy && class_exists( 'WVGS_Term_Meta' ) ) ? WVGS_Term_Meta::get_attribute_type( $raw_name ) : 'select';
				$override_type = (string) ( $attribute_types[ $attr_key ] ?? '' );
				$effective_type = '' !== $override_type ? $override_type : $global_type;

				$terms = $is_taxonomy
					? wc_get_product_terms( $product_id, $raw_name, array( 'fields' => 'all' ) )
					: $attribute->get_options();

				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}
				?>
				<div class="wvgs-attr-accordion" data-attribute_key="<?php echo esc_attr( $attr_key ); ?>">
					<div class="wvgs-attr-header">
						<span class="wvgs-attr-title">
							<strong><?php echo esc_html( $attr_label ); ?></strong>
							<code><?php echo esc_html( $attr_key ); ?></code>
							<?php if ( ! $is_taxonomy ) : ?>
								<span class="wvgs-attr-badge"><?php esc_html_e( 'Custom Product Attribute', 'woo-variation-gallery-studio' ); ?></span>
							<?php endif; ?>
						</span>
						<span class="wvgs-attr-toggle dashicons dashicons-arrow-down-alt2"></span>
					</div>

					<div class="wvgs-attr-body">
						<div class="wvgs-attr-controls-row">
							<div class="wvgs-attr-type-control">
								<label for="wvgs_attr_type_<?php echo esc_attr( $attr_key ); ?>">
									<?php esc_html_e( 'Swatch Type for This Product', 'woo-variation-gallery-studio' ); ?>
								</label>
								<select
									id="wvgs_attr_type_<?php echo esc_attr( $attr_key ); ?>"
									name="wvgs_attribute_types[<?php echo esc_attr( $attr_key ); ?>]"
									class="wvgs-attr-type-selector"
								>
									<option value="" <?php selected( $override_type, '' ); ?>>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: Global attribute type label. */
												__( 'Inherit Global Default (%s)', 'woo-variation-gallery-studio' ),
												self::get_swatch_type_label( $global_type )
											)
										);
										?>
									</option>
									<option value="color" <?php selected( $override_type, 'color' ); ?>><?php esc_html_e( 'Color Swatch', 'woo-variation-gallery-studio' ); ?></option>
									<option value="dual_color" <?php selected( $override_type, 'dual_color' ); ?>><?php esc_html_e( 'Dual Color Swatch', 'woo-variation-gallery-studio' ); ?></option>
									<option value="image" <?php selected( $override_type, 'image' ); ?>><?php esc_html_e( 'Image Swatch', 'woo-variation-gallery-studio' ); ?></option>
									<option value="auto_image" <?php selected( $override_type, 'auto_image' ); ?>><?php esc_html_e( 'Auto Variation Image (Zero Extra Uploads)', 'woo-variation-gallery-studio' ); ?></option>
									<option value="button" <?php selected( $override_type, 'button' ); ?>><?php esc_html_e( 'Button / Label', 'woo-variation-gallery-studio' ); ?></option>
									<option value="radio" <?php selected( $override_type, 'radio' ); ?>><?php esc_html_e( 'Radio', 'woo-variation-gallery-studio' ); ?></option>
									<option value="select" <?php selected( $override_type, 'select' ); ?>><?php esc_html_e( 'Native Dropdown (<select>)', 'woo-variation-gallery-studio' ); ?></option>
								</select>
							</div>

							<?php if ( $is_taxonomy && $can_manage_terms ) : ?>
								<div class="wvgs-attr-sync-control">
									<label>
										<input
											type="checkbox"
											name="wvgs_sync_global_terms[<?php echo esc_attr( $attr_key ); ?>]"
											value="yes"
										/>
										<?php esc_html_e( 'Also save term colors/images to Global Attribute Terms', 'woo-variation-gallery-studio' ); ?>
									</label>
								</div>
							<?php endif; ?>
						</div>

						<table class="widefat striped wvgs-terms-table" data-effective-type="<?php echo esc_attr( $effective_type ); ?>">
							<thead>
								<tr>
									<th class="wvgs-col-term"><?php esc_html_e( 'Term', 'woo-variation-gallery-studio' ); ?></th>
									<th class="wvgs-col-swatch"><?php esc_html_e( 'Swatch Visual Override', 'woo-variation-gallery-studio' ); ?></th>
									<th class="wvgs-col-gallery"><?php esc_html_e( 'Attribute-Level Gallery (Inherited by All Sizes)', 'woo-variation-gallery-studio' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $terms as $term ) {
									$term_id   = ( $is_taxonomy && $term instanceof \WP_Term ) ? (int) $term->term_id : 0;
									$term_slug = ( $is_taxonomy && $term instanceof \WP_Term ) ? (string) $term->slug : sanitize_title( (string) $term );
									$term_name = ( $is_taxonomy && $term instanceof \WP_Term ) ? (string) $term->name : (string) $term;

									$map_key        = $attr_key . ':' . $term_slug;
									$legacy_map_key = ( $is_taxonomy && 0 === strpos( $attr_key, 'pa_' ) ) ? substr( $attr_key, 3 ) . ':' . $term_slug : '';

									// Merge global term defaults as placeholders alongside product-level overrides.
									$global_term = ( $term_id > 0 && class_exists( 'WVGS_Term_Meta' ) )
										? WVGS_Term_Meta::get_term_swatch_data( $term_id )
										: array(
											'primary_color'   => '',
											'is_dual_color'   => false,
											'secondary_color' => '',
											'dual_angle'      => 45,
											'image_id'        => 0,
										);

									$override = is_array( $term_overrides[ $map_key ] ?? null )
										? $term_overrides[ $map_key ]
										: ( '' !== $legacy_map_key && is_array( $term_overrides[ $legacy_map_key ] ?? null ) ? $term_overrides[ $legacy_map_key ] : array() );

									$primary_color   = (string) ( $override['primary_color'] ?? $global_term['primary_color'] ?? '' );
									$is_dual_color   = isset( $override['is_dual_color'] )
										? ( 'yes' === $override['is_dual_color'] || true === $override['is_dual_color'] )
										: ! empty( $global_term['is_dual_color'] );
									$secondary_color = (string) ( $override['secondary_color'] ?? $global_term['secondary_color'] ?? '' );
									$dual_angle      = isset( $override['dual_angle'] ) ? (int) $override['dual_angle'] : (int) ( $global_term['dual_angle'] ?? 45 );
									$swatch_img_id   = isset( $override['image_id'] ) ? absint( $override['image_id'] ) : absint( $global_term['image_id'] ?? 0 );

									// Resolve attribute-level gallery IDs (checking canonical key then legacy stripped key).
									$gallery_ids = array();
									if ( ! empty( $attribute_galleries[ $map_key ] ) && is_array( $attribute_galleries[ $map_key ] ) ) {
										$gallery_ids = array_map( 'absint', $attribute_galleries[ $map_key ] );
									} elseif ( '' !== $legacy_map_key && ! empty( $attribute_galleries[ $legacy_map_key ] ) && is_array( $attribute_galleries[ $legacy_map_key ] ) ) {
										$gallery_ids = array_map( 'absint', $attribute_galleries[ $legacy_map_key ] );
									}
									?>
									<tr class="wvgs-term-row" data-map_key="<?php echo esc_attr( $map_key ); ?>">
										<td class="wvgs-col-term">
											<strong><?php echo esc_html( $term_name ); ?></strong>
											<br />
											<small class="wvgs-term-slug"><?php echo esc_html( $term_slug ); ?></small>
										</td>

										<td class="wvgs-col-swatch">
											<!-- Color & Dual-Color Controls -->
											<div class="wvgs-swatch-field wvgs-swatch-field-color">
												<input
													type="text"
													name="wvgs_term_overrides[<?php echo esc_attr( $map_key ); ?>][primary_color]"
													value="<?php echo esc_attr( $primary_color ); ?>"
													class="wvgs-color-picker"
												/>
												<label class="wvgs-inline-dual-toggle">
													<input
														type="checkbox"
														name="wvgs_term_overrides[<?php echo esc_attr( $map_key ); ?>][is_dual_color]"
														value="yes"
														class="wvgs-row-dual-checkbox"
														<?php checked( $is_dual_color, true ); ?>
													/>
													<?php esc_html_e( 'Dual', 'woo-variation-gallery-studio' ); ?>
												</label>
												<div class="wvgs-row-secondary-color" <?php echo $is_dual_color ? '' : 'style="display:none;"'; ?>>
													<input
														type="text"
														name="wvgs_term_overrides[<?php echo esc_attr( $map_key ); ?>][secondary_color]"
														value="<?php echo esc_attr( $secondary_color ); ?>"
														class="wvgs-color-picker"
													/>
													<input
														type="number"
														name="wvgs_term_overrides[<?php echo esc_attr( $map_key ); ?>][dual_angle]"
														value="<?php echo esc_attr( (string) $dual_angle ); ?>"
														min="0"
														max="360"
														step="15"
														class="wvgs-angle-input"
														title="<?php esc_attr_e( 'Dual color split angle in degrees', 'woo-variation-gallery-studio' ); ?>"
													/>&deg;
												</div>
											</div>

											<!-- Image Swatch Control -->
											<div class="wvgs-swatch-field wvgs-swatch-field-image wvgs-term-image-wrap">
												<div class="wvgs-term-image-preview wvgs-mini-thumb">
													<?php
													if ( $swatch_img_id > 0 ) {
														echo wp_get_attachment_image( $swatch_img_id, 'thumbnail' );
													}
													?>
												</div>
												<input
													type="hidden"
													name="wvgs_term_overrides[<?php echo esc_attr( $map_key ); ?>][image_id]"
													class="wvgs-media-id-input"
													value="<?php echo esc_attr( $swatch_img_id > 0 ? (string) $swatch_img_id : '' ); ?>"
												/>
												<button type="button" class="button button-small wvgs-upload-image-btn">
													<?php esc_html_e( 'Swatch Image', 'woo-variation-gallery-studio' ); ?>
												</button>
												<button type="button" class="button-link-delete wvgs-remove-image-btn" <?php echo $swatch_img_id > 0 ? '' : 'style="display:none;"'; ?>>
													<?php esc_html_e( 'Remove', 'woo-variation-gallery-studio' ); ?>
												</button>
											</div>

											<!-- Auto-Image / Button / Radio Info Note -->
											<div class="wvgs-swatch-field wvgs-swatch-field-note">
												<span class="description">
													<?php esc_html_e( 'Auto-rendered from variation image or term label.', 'woo-variation-gallery-studio' ); ?>
												</span>
											</div>
										</td>

										<td class="wvgs-col-gallery">
											<div class="wvgs-mini-gallery" data-map_key="<?php echo esc_attr( $map_key ); ?>">
												<ul class="wvgs-variation-gallery-images">
													<?php
													foreach ( $gallery_ids as $att_id ) {
														$img_url = wp_get_attachment_image_url( $att_id, 'thumbnail' );
														if ( ! $img_url ) {
															continue;
														}

														$has_video = class_exists( 'WVGS_Variation_Gallery' )
															&& '' !== WVGS_Variation_Gallery::get_attachment_video_data( $att_id )['url'];
														?>
														<li class="image<?php echo $has_video ? ' wvgs-has-video' : ''; ?>" data-attachment_id="<?php echo esc_attr( (string) $att_id ); ?>">
															<img src="<?php echo esc_url( $img_url ); ?>" alt="" />
															<?php if ( $has_video ) : ?>
																<span class="wvgs-video-badge" title="<?php esc_attr_e( 'Video slide attached', 'woo-variation-gallery-studio' ); ?>">
																	<span class="dashicons dashicons-video-alt3"></span>
																</span>
															<?php endif; ?>
															<a href="#" class="delete wvgs-remove-variation-gallery-image" title="<?php esc_attr_e( 'Remove image', 'woo-variation-gallery-studio' ); ?>">
																<span class="dashicons dashicons-dismiss"></span>
															</a>
														</li>
														<?php
													}
													?>
												</ul>
												<input
													type="hidden"
													name="wvgs_attribute_galleries[<?php echo esc_attr( $map_key ); ?>]"
													class="wvgs-variation-gallery-ids"
													value="<?php echo esc_attr( implode( ',', $gallery_ids ) ); ?>"
												/>
												<div class="wvgs-mini-gallery-actions">
													<a
														href="#"
														class="button button-small wvgs-add-variation-gallery-image"
														data-choose="<?php esc_attr_e( 'Select Attribute-Level Gallery Images', 'woo-variation-gallery-studio' ); ?>"
														data-update="<?php esc_attr_e( 'Add to Gallery', 'woo-variation-gallery-studio' ); ?>"
													>
														<?php esc_html_e( 'Set Gallery', 'woo-variation-gallery-studio' ); ?>
													</a>
													<a
														href="#"
														class="button-link-delete wvgs-clear-variation-gallery"
														<?php echo empty( $gallery_ids ) ? 'style="display:none;"' : ''; ?>
													>
														<?php esc_html_e( 'Clear', 'woo-variation-gallery-studio' ); ?>
													</a>
												</div>
											</div>
										</td>
									</tr>
									<?php
								}
								?>
							</tbody>
						</table>
					</div>
				</div>
				<?php
			}
		}

		/**
		 * Persist per-product Swatches & Gallery settings securely on product save.
		 *
		 * @param int $post_id Product post ID.
		 * @return void
		 */
		public static function save_product_data( int $post_id ): void {
			if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			if (
				! isset( $_POST['wvgs_product_settings_nonce'] ) ||
				! wp_verify_nonce( sanitize_key( wp_unslash( (string) $_POST['wvgs_product_settings_nonce'] ) ), 'wvgs_save_product_settings' )
			) {
				return;
			}

			$settings = array();

			// 1. Archive Hover Mode override.
			$raw_hover_mode = isset( $_POST['_wvgs_archive_hover_mode'] )
				? sanitize_key( wp_unslash( (string) $_POST['_wvgs_archive_hover_mode'] ) )
				: '';
			$settings['archive_hover_mode'] = in_array( $raw_hover_mode, array( 'variation_gallery', 'next_variation', 'variation_slideshow' ), true )
				? $raw_hover_mode
				: '';

			// 2. Archive Slideshow Delay override (0 = inherit global, otherwise clamped 500–10000 ms).
			$raw_delay = isset( $_POST['_wvgs_archive_slideshow_delay'] )
				? trim( sanitize_text_field( wp_unslash( (string) $_POST['_wvgs_archive_slideshow_delay'] ) ) )
				: '';
			if ( '' !== $raw_delay && is_numeric( $raw_delay ) ) {
				$settings['archive_slideshow_delay'] = min( 10000, max( 500, absint( $raw_delay ) ) );
			} else {
				$settings['archive_slideshow_delay'] = 0;
			}

			// 3. Primary Archive Swatch Attribute override.
			$settings['archive_attribute'] = isset( $_POST['_wvgs_archive_attribute'] )
				? sanitize_title( wp_unslash( (string) $_POST['_wvgs_archive_attribute'] ) )
				: '';

			// 4. Lock Static Parent Image checkbox.
			$settings['lock_parent_image'] = ( isset( $_POST['_wvgs_lock_parent_image'] ) && 'yes' === sanitize_key( wp_unslash( (string) $_POST['_wvgs_lock_parent_image'] ) ) ) ? 1 : 0;

			// 5. Per-Product Attribute Swatch Types (validated against canonical WVGS_Plugin::SWATCH_TYPES).
			$settings['attribute_types'] = array();
			if ( isset( $_POST['wvgs_attribute_types'] ) && is_array( $_POST['wvgs_attribute_types'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per key/value inside loop.
				foreach ( wp_unslash( $_POST['wvgs_attribute_types'] ) as $attr_key => $attr_type ) {
					$clean_key  = sanitize_title( (string) $attr_key );
					$clean_type = sanitize_key( (string) $attr_type );

					if ( '' !== $clean_key && in_array( $clean_type, WVGS_Plugin::SWATCH_TYPES, true ) ) {
						$settings['attribute_types'][ $clean_key ] = $clean_type;
					}
				}
			}

			// 6. Per-Term Swatch Visual Overrides + optional two-way sync to Global Attribute Terms.
			$sync_global_attrs = array();
			if ( current_user_can( 'manage_product_terms' ) && isset( $_POST['wvgs_sync_global_terms'] ) && is_array( $_POST['wvgs_sync_global_terms'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per item.
				foreach ( wp_unslash( $_POST['wvgs_sync_global_terms'] ) as $attr_key => $flag ) {
					if ( 'yes' === sanitize_key( (string) $flag ) ) {
						$sync_global_attrs[ sanitize_title( (string) $attr_key ) ] = true;
					}
				}
			}

			$settings['attribute_term_overrides'] = array();
			if ( isset( $_POST['wvgs_term_overrides'] ) && is_array( $_POST['wvgs_term_overrides'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per nested field.
				foreach ( wp_unslash( $_POST['wvgs_term_overrides'] ) as $raw_map_key => $fields ) {
					if ( ! is_array( $fields ) ) {
						continue;
					}

					$clean_map_key = self::sanitize_map_key( (string) $raw_map_key );
					if ( '' === $clean_map_key ) {
						continue;
					}

					$primary_color   = isset( $fields['primary_color'] ) ? ( sanitize_hex_color( (string) $fields['primary_color'] ) ?: '' ) : '';
					$is_dual_color   = ( isset( $fields['is_dual_color'] ) && 'yes' === sanitize_key( (string) $fields['is_dual_color'] ) ) ? 'yes' : 'no';
					$secondary_color = isset( $fields['secondary_color'] ) ? ( sanitize_hex_color( (string) $fields['secondary_color'] ) ?: '' ) : '';
					$dual_angle      = isset( $fields['dual_angle'] ) ? min( 360, max( 0, absint( $fields['dual_angle'] ) ) ) : 45;
					$image_id        = isset( $fields['image_id'] ) ? absint( $fields['image_id'] ) : 0;

					if ( $image_id > 0 && ! wp_attachment_is_image( $image_id ) ) {
						$image_id = 0;
					}

					$term_entry = array(
						'primary_color'   => $primary_color,
						'is_dual_color'   => $is_dual_color,
						'secondary_color' => $secondary_color,
						'dual_angle'      => $dual_angle,
						'image_id'        => $image_id,
					);

					$settings['attribute_term_overrides'][ $clean_map_key ] = $term_entry;

					// Optional two-way global sync for `pa_*` taxonomies when explicitly opted in.
					list( $attr_key, $term_slug ) = explode( ':', $clean_map_key, 2 );
					if ( isset( $sync_global_attrs[ $attr_key ] ) && 0 === strpos( $attr_key, 'pa_' ) && taxonomy_exists( $attr_key ) ) {
						self::sync_term_override_to_global( $attr_key, $term_slug, $term_entry );
					}
				}
			}

			// 7. Attribute-Level Galleries (validated image attachment IDs in exact drag-drop order).
			$settings['attribute_galleries'] = array();
			if ( isset( $_POST['wvgs_attribute_galleries'] ) && is_array( $_POST['wvgs_attribute_galleries'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per map key and ID.
				foreach ( wp_unslash( $_POST['wvgs_attribute_galleries'] ) as $raw_map_key => $raw_csv ) {
					$clean_map_key = self::sanitize_map_key( (string) $raw_map_key );
					if ( '' === $clean_map_key ) {
						continue;
					}

					$raw_ids   = array_filter( explode( ',', sanitize_text_field( (string) $raw_csv ) ) );
					$valid_ids = array();

					foreach ( $raw_ids as $id ) {
						$id = absint( $id );
						if ( $id > 0 && ! in_array( $id, $valid_ids, true ) && wp_attachment_is_image( $id ) ) {
							$valid_ids[] = $id;
						}
					}

					if ( ! empty( $valid_ids ) ) {
						$settings['attribute_galleries'][ $clean_map_key ] = $valid_ids;
					}
				}
			}

			update_post_meta( $post_id, self::META_KEY, $settings );

			// Invalidate product caches and schedule stock-winner recalculation so attribute galleries take effect immediately.
			if ( class_exists( 'WVGS_Cache' ) ) {
				WVGS_Cache::invalidate_product( $post_id, false );
			}

			if ( null !== WVGS_Plugin::instance()->resolver ) {
				WVGS_Plugin::instance()->resolver->schedule_recalc_from_product_id( $post_id );
			}
		}

		/**
		 * AJAX handler to dynamically reload the Product Data > Swatches & Gallery attributes panel
		 * immediately after saving attributes in the native WooCommerce Attributes tab.
		 *
		 * @return void
		 */
		public static function ajax_load_attribute_swatches(): void {
			check_ajax_referer( 'wvgs_admin_nonce', 'nonce' );

			$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
			if ( $product_id <= 0 || ! current_user_can( 'edit_post', $product_id ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Unauthorized request.', 'woo-variation-gallery-studio' ) ),
					403
				);
			}

			$settings = self::get_product_settings( $product_id );

			ob_start();
			self::render_attributes_config_html( $product_id, $settings );
			$html = (string) ob_get_clean();

			wp_send_json_success( array( 'html' => $html ) );
		}

		/**
		 * Render a subtle dynamic Stock-Winner badge below the thumbnail in WP Admin > Products.
		 *
		 * @param string $column  Current column key.
		 * @param int    $post_id Product post ID.
		 * @return void
		 */
		public static function render_admin_list_winner_badge( string $column, int $post_id ): void {
			if ( 'thumb' !== $column || ! class_exists( 'WVGS_Cache' ) ) {
				return;
			}

			$is_dynamic = get_post_meta( $post_id, WVGS_Cache::DYNAMIC_FLAG_KEY, true );
			if ( '1' !== (string) $is_dynamic ) {
				return;
			}

			$resolved = WVGS_Cache::get_resolved_media( $post_id );
			if ( empty( $resolved['winner_label'] ) ) {
				return;
			}

			$tooltip = sprintf(
				/* translators: 1: Winning variation label, 2: Additional gallery count. */
				__( 'Dynamic Winner: %1$s (%2$d gallery imgs)', 'woo-variation-gallery-studio' ),
				(string) $resolved['winner_label'],
				count( (array) ( $resolved['gallery_ids'] ?? array() ) )
			);

			printf(
				'<span class="wvgs-list-winner-pill" title="%s">%s</span>',
				esc_attr( $tooltip ),
				esc_html__( 'Dynamic', 'woo-variation-gallery-studio' )
			);
		}

		/**
		 * Retrieve normalized per-product settings from `_wvgs_product_settings`.
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return array<string, mixed>
		 */
		public static function get_product_settings( int $product_id ): array {
			if ( $product_id <= 0 ) {
				return array();
			}

			$settings = get_post_meta( $product_id, self::META_KEY, true );
			return is_array( $settings ) ? $settings : array();
		}

		/**
		 * Resolve the effective swatch type for an attribute on a specific product.
		 *
		 * Resolution cascade:
		 * 1. Per-product override in `_wvgs_product_settings['attribute_types']`.
		 * 2. Global WooCommerce attribute taxonomy type (`WVGS_Term_Meta::get_attribute_type()`).
		 * 3. Global `auto_convert_dropdowns` fallback (`button` | `auto_image` | `none` -> `select`).
		 *
		 * @param int    $product_id     Parent variable product ID.
		 * @param string $attribute_name Attribute taxonomy (e.g. `pa_color`) or custom name.
		 * @return string Canonical swatch type from `WVGS_Plugin::SWATCH_TYPES`.
		 */
		public static function resolve_attribute_type( int $product_id, string $attribute_name ): string {
			$product_settings = self::get_product_settings( $product_id );
			$attr_key         = taxonomy_exists( $attribute_name ) ? $attribute_name : sanitize_title( $attribute_name );
			$attr_types       = is_array( $product_settings['attribute_types'] ?? null ) ? $product_settings['attribute_types'] : array();

			// 1. Explicit per-product override (including explicit 'select' to force native dropdown).
			if ( isset( $attr_types[ $attr_key ] ) && in_array( $attr_types[ $attr_key ], WVGS_Plugin::SWATCH_TYPES, true ) ) {
				return (string) $attr_types[ $attr_key ];
			}

			// 2. Global WooCommerce taxonomy attribute type.
			$global_type = 'select';
			if ( taxonomy_exists( $attribute_name ) && class_exists( 'WVGS_Term_Meta' ) ) {
				$global_type = WVGS_Term_Meta::get_attribute_type( $attribute_name );
			}

			if ( 'select' !== $global_type && in_array( $global_type, WVGS_Plugin::SWATCH_TYPES, true ) ) {
				return $global_type;
			}

			// 3. Global auto-convert dropdown fallback.
			$global_settings = WVGS_Plugin::instance()->get_settings();
			$auto_convert    = (string) ( $global_settings['auto_convert_dropdowns'] ?? 'button' );

			if ( 'button' === $auto_convert ) {
				return 'button';
			}

			if ( 'auto_image' === $auto_convert ) {
				return 'auto_image';
			}

			return 'select';
		}

		/**
		 * Retrieve per-product visual override data for a single attribute term, if configured.
		 *
		 * @param int    $product_id     Parent variable product ID.
		 * @param string $attribute_name Attribute taxonomy or custom attribute slug.
		 * @param string $term_slug      Term slug or custom option value.
		 * @return array<string, mixed>
		 */
		public static function get_term_override( int $product_id, string $attribute_name, string $term_slug ): array {
			$settings  = self::get_product_settings( $product_id );
			$overrides = is_array( $settings['attribute_term_overrides'] ?? null ) ? $settings['attribute_term_overrides'] : array();

			if ( empty( $overrides ) ) {
				return array();
			}

			$attr_key   = taxonomy_exists( $attribute_name ) ? $attribute_name : sanitize_title( $attribute_name );
			$clean_slug = sanitize_title( $term_slug );

			$keys = array(
				$attr_key . ':' . $term_slug,
				$attr_key . ':' . $clean_slug,
			);

			if ( 0 === strpos( $attr_key, 'pa_' ) ) {
				$keys[] = substr( $attr_key, 3 ) . ':' . $clean_slug;
			}

			foreach ( $keys as $key ) {
				if ( isset( $overrides[ $key ] ) && is_array( $overrides[ $key ] ) ) {
					return $overrides[ $key ];
				}
			}

			return array();
		}

		/**
		 * Sync a product-level term swatch override back to global `wp_termmeta`
		 * when the user checks "Also save term colors/images to Global Attribute Terms".
		 *
		 * @param string               $taxonomy   Global `pa_*` taxonomy slug.
		 * @param string               $term_slug  Term slug.
		 * @param array<string, mixed> $term_entry Sanitized override values.
		 * @return void
		 */
		private static function sync_term_override_to_global( string $taxonomy, string $term_slug, array $term_entry ): void {
			$term = get_term_by( 'slug', $term_slug, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				return;
			}

			$term_id = (int) $term->term_id;

			if ( '' !== (string) $term_entry['primary_color'] ) {
				update_term_meta( $term_id, '_wvgs_primary_color', (string) $term_entry['primary_color'] );
			}

			update_term_meta( $term_id, '_wvgs_is_dual_color', (string) $term_entry['is_dual_color'] );

			if ( '' !== (string) $term_entry['secondary_color'] ) {
				update_term_meta( $term_id, '_wvgs_secondary_color', (string) $term_entry['secondary_color'] );
			}

			update_term_meta( $term_id, '_wvgs_dual_angle', (int) $term_entry['dual_angle'] );

			if ( (int) $term_entry['image_id'] > 0 ) {
				update_term_meta( $term_id, '_wvgs_image_id', (int) $term_entry['image_id'] );
			}

			if ( class_exists( 'WVGS_Cache' ) ) {
				WVGS_Cache::bump_cache_salt();
			}
		}

		/**
		 * Build select options of a product's variation attributes for the Primary Archive Attribute field.
		 *
		 * @param int $product_id Product ID.
		 * @return array<string, string>
		 */
		private static function get_product_variation_attribute_options( int $product_id ): array {
			$global_settings = WVGS_Plugin::instance()->get_settings();
			$options         = array(
				'' => sprintf(
					/* translators: %s: Default global archive attribute slug. */
					__( 'Inherit Global Default (%s)', 'woo-variation-gallery-studio' ),
					(string) $global_settings['default_archive_attribute']
				),
			);

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product ) {
				return $options;
			}

			foreach ( $product->get_attributes() as $attribute ) {
				if ( ! $attribute instanceof \WC_Product_Attribute || ! $attribute->get_variation() ) {
					continue;
				}
				$name             = (string) $attribute->get_name();
				$key              = $attribute->is_taxonomy() ? $name : sanitize_title( $name );
				$label            = $attribute->is_taxonomy() ? wc_attribute_label( $name ) : $name;
				$options[ $key ]  = sprintf( '%s (%s)', $label, $key );
			}

			return $options;
		}

		/**
		 * Sanitize a composite `attribute_key:term_slug` map key.
		 *
		 * @param string $raw_key Raw map key.
		 * @return string Sanitized map key or empty string if malformed.
		 */
		private static function sanitize_map_key( string $raw_key ): string {
			if ( false === strpos( $raw_key, ':' ) ) {
				return '';
			}

			list( $attr_part, $term_part ) = explode( ':', $raw_key, 2 );
			$clean_attr                    = sanitize_title( $attr_part );
			$clean_term                    = sanitize_title( $term_part );

			if ( '' === $clean_attr || '' === $clean_term ) {
				return '';
			}

			return $clean_attr . ':' . $clean_term;
		}

		/**
		 * Human-readable label for a canonical hover mode key.
		 *
		 * @param string $mode Hover mode slug.
		 * @return string
		 */
		private static function get_hover_mode_label( string $mode ): string {
			$map = array(
				'variation_gallery'   => __( 'Mode 1: 2nd Gallery Image', 'woo-variation-gallery-studio' ),
				'next_variation'      => __( 'Mode 2: Next Variation', 'woo-variation-gallery-studio' ),
				'variation_slideshow' => __( 'Mode 3: Slideshow', 'woo-variation-gallery-studio' ),
			);

			return $map[ $mode ] ?? $mode;
		}

		/**
		 * Human-readable label for a canonical swatch type key.
		 *
		 * @param string $type Swatch type slug.
		 * @return string
		 */
		private static function get_swatch_type_label( string $type ): string {
			$map = array(
				'select'     => __( 'Select / Auto', 'woo-variation-gallery-studio' ),
				'color'      => __( 'Color', 'woo-variation-gallery-studio' ),
				'dual_color' => __( 'Dual Color', 'woo-variation-gallery-studio' ),
				'image'      => __( 'Image', 'woo-variation-gallery-studio' ),
				'auto_image' => __( 'Auto Variation Image', 'woo-variation-gallery-studio' ),
				'button'     => __( 'Button', 'woo-variation-gallery-studio' ),
				'radio'      => __( 'Radio', 'woo-variation-gallery-studio' ),
			);

			return $map[ $type ] ?? $type;
		}
	}

	WVGS_Product_Metabox::init();

endif;
