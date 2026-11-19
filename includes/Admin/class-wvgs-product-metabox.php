<?php
/**
 * Product Data Metabox UI
 *
 * Adds the "Swatches & Gallery" tab in the Product Data panel.
 *
 * @package WooVariationGalleryStudio\Admin
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WVGS_Product_Metabox' ) ) :

	final class WVGS_Product_Metabox {

		/**
		 * Initialize hooks.
		 */
		public static function init(): void {
			add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_data_tab' ) );
			add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_product_data_panel' ) );
			add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_data' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
			add_action( 'wp_ajax_wvgs_load_product_attribute_swatches', array( __CLASS__, 'ajax_load_attribute_swatches' ) );
		}

		/**
		 * Add "Swatches & Gallery" tab to WooCommerce Product Data metabox.
		 *
		 * @param array $tabs Existing tabs.
		 * @return array
		 */
		public static function add_product_data_tab( array $tabs ): array {
			$tabs['wvgs_swatches'] = array(
				'label'  => __( 'Swatches & Gallery', 'woo-variation-gallery-studio' ),
				'target' => 'wvgs_swatches_data',
				'class'  => array( 'show_if_variable' ),
			);
			return $tabs;
		}

		/**
		 * Render the panel content.
		 */
		public static function render_product_data_panel(): void {
			global $post;
			if ( ! $post ) {
				return;
			}
			$product_id = $post->ID;
			$settings   = get_post_meta( $product_id, '_wvgs_product_settings', true );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			$hover_mode      = $settings['archive_hover_mode'] ?? '';
			$slideshow_delay = $settings['archive_slideshow_delay'] ?? '';
			$lock_parent     = $settings['lock_parent_image'] ?? '0';

			?>
			<div id="wvgs_swatches_data" class="panel woocommerce_options_panel hidden">
				<div class="options_group">
					<p class="form-field">
						<strong><?php esc_html_e( 'Flatsome Archive Hover Behavior', 'woo-variation-gallery-studio' ); ?></strong>
					</p>
					<?php
					woocommerce_wp_select(
						array(
							'id'          => '_wvgs_archive_hover_mode',
							'label'       => __( 'Hover Mode', 'woo-variation-gallery-studio' ),
							'value'       => $hover_mode,
							'options'     => array(
								''                    => __( 'Inherit Global Default', 'woo-variation-gallery-studio' ),
								'variation_gallery'   => __( 'Active Variation 2nd Image', 'woo-variation-gallery-studio' ),
								'next_variation'      => __( 'Next Available Variation Main Image', 'woo-variation-gallery-studio' ),
								'variation_slideshow' => __( 'Slideshow All Available Variations', 'woo-variation-gallery-studio' ),
							),
							'desc_tip'    => true,
							'description' => __( 'Controls what happens when a customer hovers over this product on shop pages.', 'woo-variation-gallery-studio' ),
						)
					);

					woocommerce_wp_text_input(
						array(
							'id'          => '_wvgs_archive_slideshow_delay',
							'label'       => __( 'Slideshow Delay (ms)', 'woo-variation-gallery-studio' ),
							'value'       => $slideshow_delay,
							'placeholder' => '1500',
							'type'        => 'number',
							'custom_attributes' => array(
								'min'  => '500',
								'max'  => '10000',
								'step' => '100',
							),
							'desc_tip'    => true,
							'description' => __( 'Only applies if Hover Mode is set to Slideshow. Value between 500 and 10000.', 'woo-variation-gallery-studio' ),
						)
					);

					woocommerce_wp_checkbox(
						array(
							'id'          => '_wvgs_lock_parent_image',
							'label'       => __( 'Lock Parent Image', 'woo-variation-gallery-studio' ),
							'value'       => $lock_parent,
							'cbvalue'     => '1',
							'description' => __( 'Prevent the plugin from dynamically overriding this product\'s parent Main Image and Gallery.', 'woo-variation-gallery-studio' ),
						)
					);
					?>
				</div>

				<div class="options_group wvgs-attributes-swatch-config">
					<p class="form-field">
						<strong><?php esc_html_e( 'Attribute-Level Swatches & Galleries', 'woo-variation-gallery-studio' ); ?></strong>
						<span class="description"><?php esc_html_e( 'Configure custom swatches and assign galleries at the attribute level (e.g. upload Black gallery once for all Black sizes).', 'woo-variation-gallery-studio' ); ?></span>
					</p>
					<div id="wvgs-product-attributes-container">
						<?php self::render_attributes_config_html( $product_id, $settings ); ?>
					</div>
					<p class="form-field">
						<button type="button" class="button button-secondary" id="wvgs-refresh-attributes"><?php esc_html_e( 'Refresh Attributes', 'woo-variation-gallery-studio' ); ?></button>
						<span class="description"><?php esc_html_e( 'Click this if you just saved new attributes in the Attributes tab.', 'woo-variation-gallery-studio' ); ?></span>
					</p>
				</div>
			</div>
			<?php
		}

		/**
		 * Helper to render the attributes configuration UI.
		 *
		 * @param int   $product_id Product ID.
		 * @param array $settings   Existing settings.
		 */
		public static function render_attributes_config_html( int $product_id, array $settings ): void {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return;
			}

			$attributes          = $product->get_attributes();
			$attribute_galleries = $settings['attribute_galleries'] ?? array();
			$attribute_types     = $settings['attribute_types'] ?? array();

			if ( empty( $attributes ) ) {
				echo '<p style="padding: 0 12px;">' . esc_html__( 'No attributes found. Please add attributes in the "Attributes" tab and save.', 'woo-variation-gallery-studio' ) . '</p>';
				return;
			}

			foreach ( $attributes as $attribute ) {
				if ( ! $attribute->get_variation() ) {
					continue; // Only process attributes used for variations.
				}

				$attr_name  = $attribute->get_name();
				$clean_name = ( $attribute->is_taxonomy() ) ? $attr_name : sanitize_title( $attr_name );
				$attr_label = ( $attribute->is_taxonomy() ) ? wc_attribute_label( $attr_name ) : $attr_name;
				$current_type = $attribute_types[ $clean_name ] ?? '';

				echo '<div class="wvgs-attr-accordion">';
				echo '<h3 class="wvgs-attr-header"><strong>' . esc_html( $attr_label ) . '</strong></h3>';
				echo '<div class="wvgs-attr-body">';
				
				// Attribute Type Selector
				echo '<p class="form-row form-row-full">';
				echo '<label>' . esc_html__( 'Attribute Type', 'woo-variation-gallery-studio' ) . '</label>';
				echo '<select name="wvgs_attribute_types[' . esc_attr( $clean_name ) . ']" class="wvgs-attr-type-selector">';
				echo '<option value="" ' . selected( $current_type, '', false ) . '>' . esc_html__( 'Inherit Global Default', 'woo-variation-gallery-studio' ) . '</option>';
				echo '<option value="color" ' . selected( $current_type, 'color', false ) . '>' . esc_html__( 'Color', 'woo-variation-gallery-studio' ) . '</option>';
				echo '<option value="image" ' . selected( $current_type, 'image', false ) . '>' . esc_html__( 'Image', 'woo-variation-gallery-studio' ) . '</option>';
				echo '<option value="button" ' . selected( $current_type, 'button', false ) . '>' . esc_html__( 'Button / Label', 'woo-variation-gallery-studio' ) . '</option>';
				echo '<option value="radio" ' . selected( $current_type, 'radio', false ) . '>' . esc_html__( 'Radio', 'woo-variation-gallery-studio' ) . '</option>';
				echo '</select>';
				echo '</p>';

				// Terms Loop
				$terms = $attribute->is_taxonomy() ? wc_get_product_terms( $product_id, $attr_name, array( 'fields' => 'all' ) ) : $attribute->get_options();
				
				echo '<table class="widefat wvgs-terms-table">';
				echo '<thead><tr><th>' . esc_html__( 'Term', 'woo-variation-gallery-studio' ) . '</th><th>' . esc_html__( 'Attribute-Level Gallery', 'woo-variation-gallery-studio' ) . '</th></tr></thead>';
				echo '<tbody>';

				foreach ( $terms as $term ) {
					$term_slug = $attribute->is_taxonomy() ? $term->slug : $term;
					$term_name = $attribute->is_taxonomy() ? $term->name : $term;
					$map_key   = ( $attribute->is_taxonomy() ? substr( $attr_name, 3 ) : $clean_name ) . ':' . $term_slug;
					
					$term_gallery_ids = $attribute_galleries[ $map_key ] ?? array();

					echo '<tr>';
					echo '<td><strong>' . esc_html( $term_name ) . '</strong></td>';
					echo '<td>';
					// Mini Gallery Uploader
					echo '<div class="wvgs-mini-gallery" data-map_key="' . esc_attr( $map_key ) . '">';
					echo '<ul class="wvgs-variation-gallery-images">';
					foreach ( $term_gallery_ids as $att_id ) {
						$img_url = wp_get_attachment_image_url( (int) $att_id, 'thumbnail' );
						if ( $img_url ) {
							echo '<li class="image" data-attachment_id="' . esc_attr( $att_id ) . '">';
							echo '<img src="' . esc_url( $img_url ) . '" alt="" />';
							echo '<a href="#" class="delete wvgs-remove-variation-gallery-image"><span class="dashicons dashicons-dismiss"></span></a>';
							echo '</li>';
						}
					}
					echo '</ul>';
					echo '<input type="hidden" name="wvgs_attribute_galleries[' . esc_attr( $map_key ) . ']" class="wvgs-variation-gallery-ids" value="' . esc_attr( implode( ',', $term_gallery_ids ) ) . '" />';
					echo '<a href="#" class="button wvgs-add-variation-gallery-image button-small">' . esc_html__( 'Set Gallery', 'woo-variation-gallery-studio' ) . '</a>';
					echo '</div>';
					echo '</td>';
					echo '</tr>';
				}

				echo '</tbody></table>';
				echo '</div></div>';
			}
		}

		/**
		 * Save product meta.
		 *
		 * @param int $post_id Post ID.
		 */
		public static function save_product_data( int $post_id ): void {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return;
			}

			// Save basic scalar fields directly to settings array to avoid meta bloat.
			$settings = get_post_meta( $post_id, '_wvgs_product_settings', true );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing
			$settings['archive_hover_mode']      = isset( $_POST['_wvgs_archive_hover_mode'] ) ? sanitize_key( $_POST['_wvgs_archive_hover_mode'] ) : '';
			$settings['archive_slideshow_delay'] = isset( $_POST['_wvgs_archive_slideshow_delay'] ) ? absint( $_POST['_wvgs_archive_slideshow_delay'] ) : 1500;
			$settings['lock_parent_image']       = isset( $_POST['_wvgs_lock_parent_image'] ) ? 1 : 0;

			// Save Attribute Types
			$settings['attribute_types'] = array();
			if ( isset( $_POST['wvgs_attribute_types'] ) && is_array( $_POST['wvgs_attribute_types'] ) ) {
				foreach ( wp_unslash( $_POST['wvgs_attribute_types'] ) as $attr_name => $attr_type ) {
					$settings['attribute_types'][ sanitize_key( $attr_name ) ] = sanitize_key( $attr_type );
				}
			}

			// Save Attribute Galleries
			$settings['attribute_galleries'] = array();
			if ( isset( $_POST['wvgs_attribute_galleries'] ) && is_array( $_POST['wvgs_attribute_galleries'] ) ) {
				foreach ( wp_unslash( $_POST['wvgs_attribute_galleries'] ) as $map_key => $raw_ids ) {
					$clean_key = sanitize_text_field( $map_key );
					$ids       = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
					if ( ! empty( $ids ) ) {
						$settings['attribute_galleries'][ $clean_key ] = $ids;
					}
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			update_post_meta( $post_id, '_wvgs_product_settings', $settings );
			
			// Trigger cache invalidation since setting changes affect the payload
			if ( class_exists( 'WVGS_Cache' ) ) {
				WVGS_Cache::invalidate_product( $post_id, true );
			}
		}

		/**
		 * AJAX handler to refresh the attributes panel dynamically.
		 */
		public static function ajax_load_attribute_swatches(): void {
			check_ajax_referer( 'wvgs_admin_nonce', 'nonce' );

			$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
			if ( $product_id <= 0 || ! current_user_can( 'edit_post', $product_id ) ) {
				wp_send_json_error( 'Unauthorized' );
			}

			$settings = get_post_meta( $product_id, '_wvgs_product_settings', true );
			if ( ! is_array( $settings ) ) {
				$settings = array();
			}

			ob_start();
			self::render_attributes_config_html( $product_id, $settings );
			$html = ob_get_clean();

			wp_send_json_success( array( 'html' => $html ) );
		}

		/**
		 * Enqueue admin scripts and styles.
		 *
		 * @param string $hook Admin page hook.
		 */
		public static function enqueue_admin_assets( string $hook ): void {
			global $post_type;
			
			// Only load on WooCommerce product edit pages or attribute pages
			if ( ( 'post.php' === $hook || 'post-new.php' === $hook ) && 'product' === $post_type || isset( $_GET['page'] ) && 'product_attributes' === $_GET['page'] ) {
				
				wp_enqueue_media();
				wp_enqueue_style( 'wp-color-picker' );
				
				// Inline CSS for Phase 2 instead of separate file to ensure load reliability 
				// (in production these would be physical files, but mapping to single file approach for Phase 2 components)
				wp_enqueue_style( 'wvgs-admin', WVGS_URL . 'assets/css/wvgs-admin.css', array(), WVGS_VERSION );
				
				wp_enqueue_script( 'wvgs-admin', WVGS_URL . 'assets/js/wvgs-admin.js', array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ), WVGS_VERSION, true );
				wp_localize_script(
					'wvgs-admin',
					'wvgs_admin_data',
					array(
						'nonce'  => wp_create_nonce( 'wvgs_admin_nonce' ),
						'ajax'   => admin_url( 'admin-ajax.php' ),
						'postID' => isset( $GLOBALS['post'] ) ? $GLOBALS['post']->ID : 0,
					)
				);
			}
		}
	}

	WVGS_Product_Metabox::init();

endif;
