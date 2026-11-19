<?php
/**
 * Variation Multi-Image Gallery & Attachment Video Media Manager
 *
 * Responsibilities:
 * 1. Injects a drag-and-drop sortable multi-image WP Media uploader inside each
 *    WooCommerce variation accordion (`woocommerce_product_after_variable_attributes`).
 * 2. Persists ordered attachment IDs to `_wvgs_variation_gallery_ids` with explicit
 *    nonce verification, capability checks, and legacy `woo_variation_gallery_images` fallback.
 * 3. Adds "Product Gallery Video URL" (MP4/WebM, YouTube, Vimeo) and "Autoplay (Muted)"
 *    fields to WordPress Media Library image attachments (`attachment_fields_to_edit` / `_to_save`).
 * 4. Exposes reusable video URL parsing helpers for the Flatsome frontend gallery renderer.
 *
 * @package WooVariationGalleryStudio\Admin
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WVGS_Variation_Gallery', false ) ) :

	/**
	 * Class WVGS_Variation_Gallery
	 *
	 * Manages per-variation multi-image galleries and attachment video metadata.
	 */
	final class WVGS_Variation_Gallery {

		/**
		 * Post meta key storing the ordered array of gallery attachment IDs on a variation.
		 *
		 * @var string
		 */
		public const GALLERY_META_KEY = '_wvgs_variation_gallery_ids';

		/**
		 * Post meta key storing an optional video URL on an image attachment.
		 *
		 * @var string
		 */
		public const VIDEO_URL_META_KEY = '_wvgs_video_url';

		/**
		 * Post meta key storing whether a slide video should autoplay (muted).
		 *
		 * @var string
		 */
		public const VIDEO_AUTOPLAY_META_KEY = '_wvgs_video_autoplay';

		/**
		 * Register WooCommerce variation and WordPress media modal hooks.
		 *
		 * @return void
		 */
		public static function init(): void {
			// 1. Variation accordion multi-image gallery UI & save handler (priority 10 runs before Stock Resolver at 20).
			add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_gallery_ui' ), 10, 3 );
			add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_gallery' ), 10, 2 );

			// 2. WordPress Media Library / Media Modal video URL fields for image attachments.
			add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'add_attachment_video_fields' ), 10, 2 );
			add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_attachment_video_fields' ), 10, 2 );
		}

		/**
		 * Render the sortable multi-image gallery uploader inside a variation accordion row.
		 *
		 * @param int                   $loop           Position in the variations loop.
		 * @param array<string, mixed>  $variation_data Variation metadata array.
		 * @param \WP_Post              $variation      Variation post object.
		 * @return void
		 */
		public static function render_variation_gallery_ui( int $loop, array $variation_data, \WP_Post $variation ): void {
			$variation_id = (int) $variation->ID;
			$gallery_ids  = self::get_variation_gallery_ids( $variation_id );
			$ids_csv      = implode( ',', $gallery_ids );
			$nonce        = wp_create_nonce( 'wvgs_save_variation_gallery_' . $variation_id );
			?>
			<div class="form-row form-row-full wvgs-variation-gallery-wrapper" data-variation_id="<?php echo esc_attr( (string) $variation_id ); ?>">
				<div class="wvgs-variation-gallery-header">
					<h4>
						<?php esc_html_e( 'Variation Gallery Images', 'woo-variation-gallery-studio' ); ?>
						<span class="wvgs-gallery-count">(<?php echo esc_html( (string) count( $gallery_ids ) ); ?>)</span>
					</h4>
					<span class="description">
						<?php esc_html_e( 'Drag to reorder. Leave empty to inherit an Attribute-Level Gallery from the "Swatches & Gallery" tab.', 'woo-variation-gallery-studio' ); ?>
					</span>
				</div>

				<div class="wvgs-variation-gallery-image-container">
					<ul class="wvgs-variation-gallery-images" data-variation_id="<?php echo esc_attr( (string) $variation_id ); ?>">
						<?php
						foreach ( $gallery_ids as $attachment_id ) {
							$image_url = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
							if ( ! $image_url ) {
								continue;
							}

							$video_data  = self::get_attachment_video_data( $attachment_id );
							$has_video   = '' !== $video_data['url'];
							$video_class = $has_video ? ' wvgs-has-video' : '';
							?>
							<li class="image<?php echo esc_attr( $video_class ); ?>" data-attachment_id="<?php echo esc_attr( (string) $attachment_id ); ?>">
								<img src="<?php echo esc_url( $image_url ); ?>" alt="" />
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
						class="wvgs-variation-gallery-ids"
						name="wvgs_variation_gallery_ids[<?php echo esc_attr( (string) $variation_id ); ?>]"
						value="<?php echo esc_attr( $ids_csv ); ?>"
					/>
					<input
						type="hidden"
						name="wvgs_variation_gallery_nonce[<?php echo esc_attr( (string) $variation_id ); ?>]"
						value="<?php echo esc_attr( $nonce ); ?>"
					/>
				</div>

				<p class="wvgs-variation-gallery-actions hide-if-no-js">
					<a
						href="#"
						class="button button-secondary wvgs-add-variation-gallery-image"
						data-variation_id="<?php echo esc_attr( (string) $variation_id ); ?>"
						data-choose="<?php esc_attr_e( 'Select Variation Gallery Images', 'woo-variation-gallery-studio' ); ?>"
						data-update="<?php esc_attr_e( 'Add to Gallery', 'woo-variation-gallery-studio' ); ?>"
					>
						<?php esc_html_e( 'Add Gallery Images', 'woo-variation-gallery-studio' ); ?>
					</a>
					<a
						href="#"
						class="button-link-delete wvgs-clear-variation-gallery"
						<?php echo empty( $gallery_ids ) ? 'style="display:none;"' : ''; ?>
					>
						<?php esc_html_e( 'Clear All', 'woo-variation-gallery-studio' ); ?>
					</a>
				</p>
			</div>
			<?php
		}

		/**
		 * Persist variation gallery attachment IDs securely on variation save.
		 *
		 * Runs at priority 10 so `_wvgs_variation_gallery_ids` is committed before
		 * `WVGS_Stock_Resolver` recalculates stock-winning media at priority 20.
		 *
		 * @param int $variation_id Variation post ID.
		 * @param int $loop         Loop index.
		 * @return void
		 */
		public static function save_variation_gallery( int $variation_id, int $loop ): void {
			if ( $variation_id <= 0 ) {
				return;
			}

			$parent_id = (int) wp_get_post_parent_id( $variation_id );
			$target_id = $parent_id > 0 ? $parent_id : $variation_id;

			if ( ! current_user_can( 'edit_post', $target_id ) ) {
				return;
			}

			if (
				! isset( $_POST['wvgs_variation_gallery_nonce'][ $variation_id ] ) ||
				! wp_verify_nonce(
					sanitize_key( wp_unslash( (string) $_POST['wvgs_variation_gallery_nonce'][ $variation_id ] ) ),
					'wvgs_save_variation_gallery_' . $variation_id
				)
			) {
				return;
			}

			if ( ! isset( $_POST['wvgs_variation_gallery_ids'][ $variation_id ] ) ) {
				return;
			}

			$raw_ids   = sanitize_text_field( wp_unslash( (string) $_POST['wvgs_variation_gallery_ids'][ $variation_id ] ) );
			$clean_ids = self::sanitize_gallery_ids( $raw_ids );

			if ( empty( $clean_ids ) ) {
				delete_post_meta( $variation_id, self::GALLERY_META_KEY );
			} else {
				update_post_meta( $variation_id, self::GALLERY_META_KEY, $clean_ids );
			}

			if ( $parent_id > 0 && class_exists( 'WVGS_Cache' ) ) {
				WVGS_Cache::invalidate_product( $parent_id, false );
			}
		}

		/**
		 * Add "Variation Gallery Video URL" and "Autoplay Video (Muted)" fields to image attachments
		 * in the WordPress Media Library and Media Modal.
		 *
		 * @param array<string, mixed> $form_fields Existing attachment fields.
		 * @param \WP_Post             $post        Attachment post object.
		 * @return array<string, mixed>
		 */
		public static function add_attachment_video_fields( array $form_fields, \WP_Post $post ): array {
			if ( 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
				return $form_fields;
			}

			$video_data = self::get_attachment_video_data( (int) $post->ID );

			$form_fields['wvgs_video_url'] = array(
				'label' => __( 'Gallery Video URL', 'woo-variation-gallery-studio' ),
				'input' => 'text',
				'value' => esc_url( $video_data['url'] ),
				'helps' => __( 'Optional MP4, WebM, YouTube, or Vimeo URL. Replaces this image slide with an interactive video player inside variation galleries.', 'woo-variation-gallery-studio' ),
			);

			$checked = $video_data['autoplay'] ? ' checked="checked"' : '';
			$form_fields['wvgs_video_autoplay'] = array(
				'label' => __( 'Video Autoplay', 'woo-variation-gallery-studio' ),
				'input' => 'html',
				'html'  => sprintf(
					'<label for="attachments-%1$d-wvgs_video_autoplay"><input type="hidden" name="attachments[%1$d][wvgs_video_autoplay]" value="no" /><input type="checkbox" id="attachments-%1$d-wvgs_video_autoplay" name="attachments[%1$d][wvgs_video_autoplay]" value="yes"%2$s /> %3$s</label>',
					(int) $post->ID,
					$checked,
					esc_html__( 'Autoplay muted when this slide becomes active (MP4/WebM & supported embeds).', 'woo-variation-gallery-studio' )
				),
			);

			return $form_fields;
		}

		/**
		 * Save "Gallery Video URL" and "Video Autoplay" metadata on an image attachment.
		 *
		 * @param array<string, mixed> $post       Attachment post data array.
		 * @param array<string, mixed> $attachment Submitted attachment field values.
		 * @return array<string, mixed>
		 */
		public static function save_attachment_video_fields( array $post, array $attachment ): array {
			$attachment_id = isset( $post['ID'] ) ? absint( $post['ID'] ) : 0;
			if ( $attachment_id <= 0 || ! current_user_can( 'edit_post', $attachment_id ) ) {
				return $post;
			}

			if ( isset( $attachment['wvgs_video_url'] ) ) {
				$raw_url   = trim( (string) $attachment['wvgs_video_url'] );
				$valid_url = self::sanitize_video_url( $raw_url );

				if ( '' !== $valid_url ) {
					update_post_meta( $attachment_id, self::VIDEO_URL_META_KEY, $valid_url );
				} else {
					delete_post_meta( $attachment_id, self::VIDEO_URL_META_KEY );
				}
			}

			if ( isset( $attachment['wvgs_video_autoplay'] ) ) {
				$autoplay = ( 'yes' === sanitize_key( (string) $attachment['wvgs_video_autoplay'] ) ) ? 'yes' : 'no';
				update_post_meta( $attachment_id, self::VIDEO_AUTOPLAY_META_KEY, $autoplay );
			}

			if ( class_exists( 'WVGS_Cache' ) ) {
				WVGS_Cache::bump_cache_salt();
			}

			return $post;
		}

		/**
		 * Retrieve valid gallery attachment IDs for a variation, falling back to legacy
		 * `woo_variation_gallery_images` meta if `_wvgs_variation_gallery_ids` is empty.
		 *
		 * @param int $variation_id Variation post ID.
		 * @return array<int, int>
		 */
		public static function get_variation_gallery_ids( int $variation_id ): array {
			if ( $variation_id <= 0 ) {
				return array();
			}

			$raw = get_post_meta( $variation_id, self::GALLERY_META_KEY, true );
			if ( empty( $raw ) ) {
				$raw = get_post_meta( $variation_id, 'woo_variation_gallery_images', true );
			}

			return self::sanitize_gallery_ids( $raw );
		}

		/**
		 * Retrieve normalized video slide metadata for an image attachment,
		 * falling back to legacy `woo_variation_gallery_media_video` meta if present.
		 *
		 * @param int $attachment_id Attachment post ID.
		 * @return array{url: string, provider: string, embed_url: string, autoplay: bool}
		 */
		public static function get_attachment_video_data( int $attachment_id ): array {
			$empty = array(
				'url'       => '',
				'provider'  => '',
				'embed_url' => '',
				'autoplay'  => false,
			);

			if ( $attachment_id <= 0 ) {
				return $empty;
			}

			$raw_url = (string) get_post_meta( $attachment_id, self::VIDEO_URL_META_KEY, true );
			if ( '' === $raw_url ) {
				$raw_url = (string) get_post_meta( $attachment_id, 'woo_variation_gallery_media_video', true );
			}

			$url = self::sanitize_video_url( $raw_url );
			if ( '' === $url ) {
				return $empty;
			}

			$raw_autoplay = get_post_meta( $attachment_id, self::VIDEO_AUTOPLAY_META_KEY, true );
			$autoplay     = ( 'yes' === $raw_autoplay || '1' === (string) $raw_autoplay || true === $raw_autoplay );
			$parsed       = self::parse_video_provider( $url, $autoplay );

			return array(
				'url'       => $url,
				'provider'  => $parsed['provider'],
				'embed_url' => $parsed['embed_url'],
				'autoplay'  => $autoplay,
			);
		}

		/**
		 * Validate and sanitize a raw video URL (accepting only http/https MP4, WebM, OGV, YouTube, or Vimeo URLs).
		 *
		 * @param string $raw_url Raw user-submitted URL.
		 * @return string Sanitized URL or empty string if invalid.
		 */
		public static function sanitize_video_url( string $raw_url ): string {
			$raw_url = trim( $raw_url );
			if ( '' === $raw_url ) {
				return '';
			}

			$clean_url = esc_url_raw( $raw_url, array( 'http', 'https' ) );
			if ( '' === $clean_url ) {
				return '';
			}

			$parsed = self::parse_video_provider( $clean_url, false );
			return '' !== $parsed['provider'] ? $clean_url : '';
		}

		/**
		 * Detect video provider (`youtube`, `vimeo`, or `html5`) and construct a safe embed URL.
		 *
		 * @param string $url      Sanitized HTTP/HTTPS URL.
		 * @param bool   $autoplay Whether autoplay muted parameters should be appended.
		 * @return array{provider: string, embed_url: string}
		 */
		public static function parse_video_provider( string $url, bool $autoplay = false ): array {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );

			// 1. YouTube (youtube.com/watch?v=, youtu.be/, youtube.com/shorts/, youtube.com/embed/).
			if ( preg_match( '/(?:youtube\.com|youtu\.be|youtube-nocookie\.com)$/i', $host ) ) {
				$video_id = '';
				if ( preg_match( '/(?:watch\?.*v=|youtu\.be\/|shorts\/|embed\/)([A-Za-z0-9_-]{11})/', $url, $matches ) ) {
					$video_id = $matches[1];
				}

				if ( '' !== $video_id ) {
					$query_args = array(
						'rel'            => 0,
						'modestbranding' => 1,
						'playsinline'    => 1,
					);
					if ( $autoplay ) {
						$query_args['autoplay'] = 1;
						$query_args['mute']     = 1;
					}

					return array(
						'provider'  => 'youtube',
						'embed_url' => add_query_arg( $query_args, 'https://www.youtube-nocookie.com/embed/' . $video_id ),
					);
				}
			}

			// 2. Vimeo (vimeo.com/123456789 or player.vimeo.com/video/123456789).
			if ( preg_match( '/vimeo\.com$/i', $host ) ) {
				if ( preg_match( '/\/(?:video\/)?(\d+)/', $path, $matches ) ) {
					$video_id   = $matches[1];
					$query_args = array( 'dnt' => 1 );
					if ( $autoplay ) {
						$query_args['autoplay'] = 1;
						$query_args['muted']    = 1;
					}

					return array(
						'provider'  => 'vimeo',
						'embed_url' => add_query_arg( $query_args, 'https://player.vimeo.com/video/' . $video_id ),
					);
				}
			}

			// 3. Direct HTML5 video formats (MP4, WebM, OGV, M4V).
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, array( 'mp4', 'webm', 'ogv', 'm4v' ), true ) ) {
				return array(
					'provider'  => 'html5',
					'embed_url' => $url,
				);
			}

			return array(
				'provider'  => '',
				'embed_url' => '',
			);
		}

		/**
		 * Sanitize a comma-separated string or array of gallery IDs into unique, valid image attachment IDs.
		 *
		 * @param mixed $raw Raw gallery IDs.
		 * @return array<int, int>
		 */
		private static function sanitize_gallery_ids( $raw ): array {
			if ( is_string( $raw ) ) {
				$raw = array_filter( explode( ',', $raw ) );
			}

			if ( ! is_array( $raw ) ) {
				return array();
			}

			$clean = array();
			foreach ( $raw as $id ) {
				$id = absint( $id );
				if ( $id > 0 && ! in_array( $id, $clean, true ) && wp_attachment_is_image( $id ) ) {
					$clean[] = $id;
				}
			}

			return $clean;
		}
	}

	WVGS_Variation_Gallery::init();

endif;
