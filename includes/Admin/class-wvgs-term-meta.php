<?php
/**
 * Global Attribute Term Meta UI
 *
 * Adds Color, Image, and Button type selectors to WC Attributes taxonomy.
 *
 * @package WooVariationGalleryStudio\Admin
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WVGS_Term_Meta' ) ) :

	final class WVGS_Term_Meta {

		/**
		 * Initialize hooks.
		 */
		public static function init(): void {
			add_filter( 'product_attributes_type_selector', array( __CLASS__, 'add_attribute_types' ) );
			add_action( 'admin_init', array( __CLASS__, 'init_taxonomy_hooks' ) );
		}

		/**
		 * Add custom types to WooCommerce attribute type selector.
		 *
		 * @param array $types Existing types.
		 * @return array
		 */
		public static function add_attribute_types( array $types ): array {
			$types['color']  = __( 'Color', 'woo-variation-gallery-studio' );
			$types['image']  = __( 'Image', 'woo-variation-gallery-studio' );
			$types['button'] = __( 'Button', 'woo-variation-gallery-studio' );
			$types['radio']  = __( 'Radio', 'woo-variation-gallery-studio' );
			return $types;
		}

		/**
		 * Attach form field hooks to all product attribute taxonomies.
		 */
		public static function init_taxonomy_hooks(): void {
			if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
				return;
			}

			$taxonomies = wc_get_attribute_taxonomies();
			foreach ( $taxonomies as $tax ) {
				$taxonomy_name = wc_attribute_taxonomy_name( $tax->attribute_name );
				
				// Only show fields if the attribute type is color or image (or handle all and hide via JS).
				// We attach to all pa_ taxonomies to be safe.
				add_action( "{$taxonomy_name}_add_form_fields", array( __CLASS__, 'add_term_fields' ), 10, 1 );
				add_action( "{$taxonomy_name}_edit_form_fields", array( __CLASS__, 'edit_term_fields' ), 10, 2 );
			}

			// Attach save hooks to all created/edited terms to catch our fields.
			add_action( 'created_term', array( __CLASS__, 'save_term_meta' ), 10, 3 );
			add_action( 'edit_term', array( __CLASS__, 'save_term_meta' ), 10, 3 );
		}

		/**
		 * Add fields to 'Add New Term' screen.
		 *
		 * @param string $taxonomy Taxonomy slug.
		 */
		public static function add_term_fields( string $taxonomy ): void {
			?>
			<div class="form-field wvgs-term-color-wrap">
				<label for="wvgs_primary_color"><?php esc_html_e( 'Color', 'woo-variation-gallery-studio' ); ?></label>
				<input type="text" name="wvgs_primary_color" id="wvgs_primary_color" value="" class="wvgs-color-picker" />
				<p class="description"><?php esc_html_e( 'Select the swatch color.', 'woo-variation-gallery-studio' ); ?></p>
			</div>
			<div class="form-field wvgs-term-image-wrap">
				<label><?php esc_html_e( 'Image', 'woo-variation-gallery-studio' ); ?></label>
				<div class="wvgs-term-image-preview" style="margin-bottom:10px;"></div>
				<input type="hidden" name="wvgs_image_id" id="wvgs_image_id" value="" />
				<button type="button" class="button wvgs-upload-image-btn"><?php esc_html_e( 'Upload/Add image', 'woo-variation-gallery-studio' ); ?></button>
				<button type="button" class="button wvgs-remove-image-btn" style="display:none;"><?php esc_html_e( 'Remove image', 'woo-variation-gallery-studio' ); ?></button>
			</div>
			<?php
		}

		/**
		 * Add fields to 'Edit Term' screen.
		 *
		 * @param \WP_Term $term     Term object.
		 * @param string   $taxonomy Taxonomy slug.
		 */
		public static function edit_term_fields( \WP_Term $term, string $taxonomy ): void {
			$color    = get_term_meta( $term->term_id, '_wvgs_primary_color', true );
			$image_id = get_term_meta( $term->term_id, '_wvgs_image_id', true );
			?>
			<tr class="form-field wvgs-term-color-wrap">
				<th scope="row" valign="top"><label for="wvgs_primary_color"><?php esc_html_e( 'Color', 'woo-variation-gallery-studio' ); ?></label></th>
				<td>
					<input type="text" name="wvgs_primary_color" id="wvgs_primary_color" value="<?php echo esc_attr( $color ); ?>" class="wvgs-color-picker" />
				</td>
			</tr>
			<tr class="form-field wvgs-term-image-wrap">
				<th scope="row" valign="top"><label><?php esc_html_e( 'Image', 'woo-variation-gallery-studio' ); ?></label></th>
				<td>
					<div class="wvgs-term-image-preview" style="margin-bottom:10px;">
						<?php if ( $image_id ) : ?>
							<?php echo wp_get_attachment_image( $image_id, 'thumbnail' ); ?>
						<?php endif; ?>
					</div>
					<input type="hidden" name="wvgs_image_id" id="wvgs_image_id" value="<?php echo esc_attr( $image_id ); ?>" />
					<button type="button" class="button wvgs-upload-image-btn"><?php esc_html_e( 'Upload/Add image', 'woo-variation-gallery-studio' ); ?></button>
					<button type="button" class="button wvgs-remove-image-btn" <?php echo empty( $image_id ) ? 'style="display:none;"' : ''; ?>><?php esc_html_e( 'Remove image', 'woo-variation-gallery-studio' ); ?></button>
				</td>
			</tr>
			<?php
		}

		/**
		 * Save term meta.
		 *
		 * @param int    $term_id  Term ID.
		 * @param int    $tt_id    Term taxonomy ID.
		 * @param string $taxonomy Taxonomy slug.
		 */
		public static function save_term_meta( int $term_id, int $tt_id, string $taxonomy ): void {
			// Ensure it's a product attribute taxonomy.
			if ( 0 !== strpos( $taxonomy, 'pa_' ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return;
			}

			// phpcs:disable WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST['wvgs_primary_color'] ) ) {
				update_term_meta( $term_id, '_wvgs_primary_color', sanitize_hex_color( wp_unslash( $_POST['wvgs_primary_color'] ) ) );
			}
			if ( isset( $_POST['wvgs_image_id'] ) ) {
				update_term_meta( $term_id, '_wvgs_image_id', absint( wp_unslash( $_POST['wvgs_image_id'] ) ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		}
	}

	WVGS_Term_Meta::init();

endif;
