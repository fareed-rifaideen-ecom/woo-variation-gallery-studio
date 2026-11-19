<?php
/**
 * Variation Gallery Uploader
 *
 * Injects a multi-image WP Media uploader into every single variation row.
 *
 * @package WooVariationGalleryStudio\Admin
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WVGS_Variation_Gallery' ) ) :

	final class WVGS_Variation_Gallery {

		/**
		 * Initialize hooks.
		 */
		public static function init(): void {
			add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_gallery_admin_ui' ), 10, 3 );
			add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_gallery' ), 10, 2 );
		}

		/**
		 * Render the gallery uploader UI inside the variation accordion.
		 *
		 * @param int     $loop           Position in the loop.
		 * @param array   $variation_data Variation data array.
		 * @param WP_Post $variation      Variation post object.
		 */
		public static function render_variation_gallery_admin_ui( int $loop, array $variation_data, \WP_Post $variation ): void {
			$variation_id = $variation->ID;
			
			// Retrieve existing gallery IDs
			$gallery_meta = get_post_meta( $variation_id, '_wvgs_variation_gallery_ids', true );
			$gallery_ids  = is_array( $gallery_meta ) ? $gallery_meta : ( is_string( $gallery_meta ) ? array_filter( explode( ',', $gallery_meta ) ) : array() );
			?>
			<div class="form-row form-row-full wvgs-variation-gallery-wrapper" data-variation_id="<?php echo esc_attr( $variation_id ); ?>">
				<h4><?php esc_html_e( 'Variation Gallery Images', 'woo-variation-gallery-studio' ); ?></h4>
				<div class="wvgs-variation-gallery-image-container">
					<ul class="wvgs-variation-gallery-images">
						<?php
						if ( ! empty( $gallery_ids ) ) {
							foreach ( $gallery_ids as $attachment_id ) {
								$attachment_id = absint( $attachment_id );
								$image_url     = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
								if ( $image_url ) {
									echo '<li class="image" data-attachment_id="' . esc_attr( $attachment_id ) . '">';
									echo '<img src="' . esc_url( $image_url ) . '" alt="" />';
									echo '<a href="#" class="delete wvgs-remove-variation-gallery-image"><span class="dashicons dashicons-dismiss"></span></a>';
									echo '</li>';
								}
							}
						}
						?>
					</ul>
					<input type="hidden" class="wvgs-variation-gallery-ids" name="wvgs_variation_gallery_ids[<?php echo esc_attr( $variation_id ); ?>]" value="<?php echo esc_attr( implode( ',', $gallery_ids ) ); ?>" />
				</div>
				<p class="add-wvgs-variation-gallery-image-wrapper hide-if-no-js">
					<a href="#" class="button wvgs-add-variation-gallery-image" data-choose="<?php esc_attr_e( 'Add Images to Variation Gallery', 'woo-variation-gallery-studio' ); ?>" data-update="<?php esc_attr_e( 'Add to gallery', 'woo-variation-gallery-studio' ); ?>">
						<?php esc_html_e( 'Add Gallery Images', 'woo-variation-gallery-studio' ); ?>
					</a>
				</p>
			</div>
			<?php
		}

		/**
		 * Save the variation gallery IDs when the variation is saved.
		 *
		 * @param int $variation_id Variation ID.
		 * @param int $i            Loop iteration.
		 */
		public static function save_variation_gallery( int $variation_id, int $i ): void {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce core during save.
			if ( ! isset( $_POST['wvgs_variation_gallery_ids'][ $variation_id ] ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_ids = sanitize_text_field( wp_unslash( $_POST['wvgs_variation_gallery_ids'][ $variation_id ] ) );
			$ids     = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );

			if ( empty( $ids ) ) {
				delete_post_meta( $variation_id, '_wvgs_variation_gallery_ids' );
			} else {
				update_post_meta( $variation_id, '_wvgs_variation_gallery_ids', $ids );
			}
		}
	}

	WVGS_Variation_Gallery::init();

endif;
