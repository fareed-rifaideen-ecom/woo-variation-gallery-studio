<?php
/**
 * Flatsome Flickity & Quick View Variation Gallery Bridge
 *
 * Responsibilities:
 * 1. Filters `woocommerce_available_variation` to inject a strictly isolated
 *    `wvgs_gallery_images` array (responsive src/srcset/sizes, PhotoSwipe full
 *    dimensions, thumbnail URLs, and attached MP4/YouTube/Vimeo video metadata)
 *    for each variation, honoring both variation-level and Attribute-Level Galleries.
 * 2. Populates native `variation_data['image']` whenever a variation inherits its
 *    main image from an Attribute-Level Gallery so WooCommerce and Flatsome never
 *    fall back to a placeholder image.
 * 3. Enhances initial server-side rendered gallery slides (`woocommerce_single_product_image_thumbnail_html`)
 *    with accessible video play overlays whenever an initial slide has an attached video.
 * 4. Outputs a scoped, XSS-safe `<script type="application/json">` payload before the
 *    variations form (`woocommerce_before_variations_form`) containing the initial
 *    server-resolved `active_variation_id` (preventing redundant Flickity rebuilds on
 *    page load) and attribute-level gallery maps for instant partial-selection swaps.
 *
 * @package WooVariationGalleryStudio\Frontend
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WVGS_Flatsome_Gallery', false ) ) :

	/**
	 * Class WVGS_Flatsome_Gallery
	 *
	 * Bridges server-side strictly isolated variation media with Flatsome's Flickity
	 * single-product slider, PhotoSwipe lightbox, and Quick View modals.
	 */
	final class WVGS_Flatsome_Gallery {

		/**
		 * Per-request memoization of built attachment slide payloads to avoid
		 * redundant `wp_get_attachment_image_src` / `srcset` lookups across sizes
		 * sharing the same color gallery.
		 *
		 * @var array<string, array<string, mixed>|null>
		 */
		private static array $slide_cache = array();

		/**
		 * Register WooCommerce variation payload and gallery markup hooks.
		 *
		 * @return void
		 */
		public static function init(): void {
			// 1. Inject strictly isolated image + video slide arrays into each variation's JSON payload.
			add_filter( 'woocommerce_available_variation', array( __CLASS__, 'inject_variation_gallery_data' ), 20, 3 );

			// 2. Decorate initial SSR gallery slides that carry an attached video URL.
			add_filter( 'woocommerce_single_product_image_thumbnail_html', array( __CLASS__, 'enhance_initial_gallery_slide_html' ), 20, 2 );

			// 3. Emit scoped JSON state payload inside Single Product & Quick View forms.
			add_action( 'woocommerce_before_variations_form', array( __CLASS__, 'output_form_gallery_payload' ), 10 );
		}

		/**
		 * Inject strictly isolated gallery slides and video data into `woocommerce_available_variation`.
		 *
		 * @param array<string, mixed>  $variation_data Default WooCommerce variation payload.
		 * @param \WC_Product_Variable  $product        Parent variable product instance.
		 * @param \WC_Product_Variation $variation      Child variation instance.
		 * @return array<string, mixed>
		 */
		public static function inject_variation_gallery_data( array $variation_data, $product, $variation ): array {
			if ( ! $product instanceof \WC_Product_Variable || ! $variation instanceof \WC_Product_Variation ) {
				return $variation_data;
			}

			$product_id   = (int) $product->get_id();
			$variation_id = (int) $variation->get_id();
			$media        = self::resolve_variation_media_ids( $product_id, $variation );

			$main_image_id = (int) $media['main_image_id'];
			$gallery_ids   = (array) $media['gallery_ids'];

			// Assemble strictly isolated ordered attachment IDs: [main_image_id, ...gallery_ids].
			$ordered_ids = array();
			if ( $main_image_id > 0 ) {
				$ordered_ids[] = $main_image_id;
			}
			foreach ( $gallery_ids as $gid ) {
				$gid = absint( $gid );
				if ( $gid > 0 && ! in_array( $gid, $ordered_ids, true ) ) {
					$ordered_ids[] = $gid;
				}
			}

			$slides    = array();
			$has_video = false;

			foreach ( $ordered_ids as $index => $attachment_id ) {
				$slide = self::build_slide_payload( $attachment_id, 0 === $index );
				if ( null !== $slide ) {
					if ( ! empty( $slide['video']['has_video'] ) ) {
						$has_video = true;
					}
					$slides[] = $slide;
				}
			}

			// Ensure WooCommerce's native `image` object reflects an inherited Attribute-Level main image
			// whenever the variation's own `_thumbnail_id` was left blank.
			if ( $main_image_id > 0 && ( empty( $variation_data['image_id'] ) || (int) $variation_data['image_id'] !== $main_image_id ) ) {
				$wc_image_props = wc_get_product_attachment_props( $main_image_id, $variation );
				if ( ! empty( $wc_image_props['src'] ) ) {
					$variation_data['image_id'] = $main_image_id;
					$variation_data['image']    = $wc_image_props;
				}
			}

			$variation_data['wvgs_gallery_images'] = $slides;
			$variation_data['wvgs_has_video']      = $has_video;
			$variation_data['wvgs_stock_status']   = (string) $variation->get_stock_status();
			$variation_data['wvgs_stock_qty']      = $variation->managing_stock() ? $variation->get_stock_quantity() : null;

			/**
			 * Filter the enriched variation data array before WooCommerce serializes it to the frontend.
			 *
			 * @param array<string, mixed>  $variation_data Variation payload including `wvgs_gallery_images`.
			 * @param \WC_Product_Variable  $product        Parent variable product.
			 * @param \WC_Product_Variation $variation      Child variation.
			 */
			return (array) apply_filters( 'wvgs_available_variation_gallery_data', $variation_data, $product, $variation );
		}

		/**
		 * Enhance initial server-rendered gallery slide HTML when an attachment has an associated video.
		 *
		 * Adds a non-destructive `data-wvgs-video` attribute and accessible play trigger button so
		 * videos attached to the initial Stock-Winning (or URL-attributed) variation play immediately
		 * on first load without waiting for a swatch click.
		 *
		 * @param string $html          Original slide HTML from `wc_get_gallery_image_html()`.
		 * @param int    $attachment_id Attachment post ID for the slide.
		 * @return string
		 */
		public static function enhance_initial_gallery_slide_html( string $html, $attachment_id ): string {
			$attachment_id = absint( $attachment_id );
			if ( $attachment_id <= 0 || ! class_exists( 'WVGS_Variation_Gallery' ) ) {
				return $html;
			}

			$video = WVGS_Variation_Gallery::get_attachment_video_data( $attachment_id );
			if ( '' === $video['url'] || '' === $video['provider'] ) {
				return $html;
			}

			$video_payload = wp_json_encode(
				array(
					'provider'  => $video['provider'],
					'url'       => $video['url'],
					'embed_url' => $video['embed_url'],
					'autoplay'  => (bool) $video['autoplay'],
				)
			);

			if ( ! is_string( $video_payload ) ) {
				return $html;
			}

			$play_label = __( 'Play product video', 'woo-variation-gallery-studio' );

			$overlay_button = sprintf(
				'<button type="button" class="wvgs-video-play-btn" data-wvgs-video="%s" aria-label="%s"><span class="wvgs-video-play-icon" aria-hidden="true"></span></button>',
				esc_attr( $video_payload ),
				esc_attr( $play_label )
			);

			// Mark the outer slide container and inject the accessible play button right before its closing tag.
			$enhanced = (string) preg_replace(
				'/^<div\b([^>]*)class="([^"]*)"/i',
				'<div$1class="$2 wvgs-slide-has-video" data-wvgs-attachment-id="' . esc_attr( (string) $attachment_id ) . '"',
				trim( $html ),
				1
			);

			$last_div_pos = strrpos( $enhanced, '</div>' );
			if ( false !== $last_div_pos ) {
				$enhanced = substr( $enhanced, 0, $last_div_pos ) . $overlay_button . substr( $enhanced, $last_div_pos );
			} else {
				$enhanced .= $overlay_button;
			}

			return $enhanced;
		}

		/**
		 * Output a scoped JSON configuration block inside the variable product form
		 * (`woocommerce_before_variations_form`).
		 *
		 * Provides the frontend controller (`assets/js/wvgs-frontend.js`) with:
		 * - `active_variation_id`: Server-rendered variation ID (skips unnecessary Flickity rebuild on first paint).
		 * - `winner_variation_id`: Overall highest-stock variation ID.
		 * - `attribute_galleries`: Pre-built slide arrays keyed by `attribute_<slug>:<value>` for instant
		 *   partial-attribute gallery swapping (e.g., clicking a Color before choosing a Size).
		 * - `fallback_gallery`: Winning variation's isolated slide array for safe form resets.
		 *
		 * @return void
		 */
		public static function output_form_gallery_payload(): void {
			global $product;

			if ( ! $product instanceof \WC_Product_Variable ) {
				return;
			}

			$product_id = (int) $product->get_id();
			$resolver   = WVGS_Plugin::instance()->resolver;

			$resolved            = ( null !== $resolver ) ? $resolver->get_resolved_media( $product_id ) : null;
			$active_variation_id = ( null !== $resolver ) ? $resolver->resolve_active_variation_id( $product, $resolved ) : 0;
			$winner_variation_id = is_array( $resolved ) ? (int) ( $resolved['winner_variation_id'] ?? 0 ) : 0;

			// Build fallback gallery from the winning variation (or active variation if present).
			$fallback_slides = array();
			if ( is_array( $resolved ) && $winner_variation_id > 0 && isset( $resolved['variations'][ $winner_variation_id ] ) ) {
				$winner_entry    = $resolved['variations'][ $winner_variation_id ];
				$fallback_slides = self::build_slides_from_media_pair(
					(int) ( $winner_entry['main_image_id'] ?? 0 ),
					(array) ( $winner_entry['gallery_ids'] ?? array() )
				);
			}

			// Build partial-selection attribute gallery map keyed by `attribute_<key>:<value>`.
			// Uses explicit Attribute-Level Galleries first, then falls back to the highest-stock
			// in-stock variation for each visual attribute value.
			$attribute_galleries = self::build_partial_attribute_galleries( $product_id, $resolved );

			$payload = array(
				'product_id'          => $product_id,
				'active_variation_id' => $active_variation_id,
				'winner_variation_id' => $winner_variation_id,
				'attribute_galleries' => $attribute_galleries,
				'fallback_gallery'    => $fallback_slides,
			);

			$encoded = wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
			if ( ! is_string( $encoded ) ) {
				return;
			}

			// Output as inert application/json data block (cannot execute as script and safe for Flatsome Quick View AJAX).
			echo '<script type="application/json" class="wvgs-product-gallery-payload" data-product_id="' . esc_attr( (string) $product_id ) . '">' . $encoded . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded with JSON_HEX_* flags inside application/json script block.
		}

		/**
		 * Build a single responsive Flatsome Flickity + PhotoSwipe + Video slide payload for an attachment ID.
		 *
		 * @param int  $attachment_id Image attachment post ID.
		 * @param bool $is_main       Whether this slide is the primary (index 0) variation image.
		 * @return array<string, mixed>|null
		 */
		public static function build_slide_payload( int $attachment_id, bool $is_main = false ): ?array {
			if ( $attachment_id <= 0 ) {
				return null;
			}

			$cache_key = $attachment_id . '_' . ( $is_main ? '1' : '0' );
			if ( array_key_exists( $cache_key, self::$slide_cache ) ) {
				return self::$slide_cache[ $cache_key ];
			}

			$single_src = wp_get_attachment_image_src( $attachment_id, 'woocommerce_single' );
			$full_src   = wp_get_attachment_image_src( $attachment_id, 'full' );
			$thumb_src  = wp_get_attachment_image_src( $attachment_id, 'woocommerce_gallery_thumbnail' );

			if ( ! is_array( $single_src ) || empty( $single_src[0] ) ) {
				self::$slide_cache[ $cache_key ] = null;
				return null;
			}

			$full_url    = ( is_array( $full_src ) && ! empty( $full_src[0] ) ) ? (string) $full_src[0] : (string) $single_src[0];
			$full_width  = ( is_array( $full_src ) && ! empty( $full_src[1] ) ) ? (int) $full_src[1] : (int) $single_src[1];
			$full_height = ( is_array( $full_src ) && ! empty( $full_src[2] ) ) ? (int) $full_src[2] : (int) $single_src[2];

			$thumb_url = ( is_array( $thumb_src ) && ! empty( $thumb_src[0] ) ) ? (string) $thumb_src[0] : (string) $single_src[0];
			$thumb_w   = ( is_array( $thumb_src ) && ! empty( $thumb_src[1] ) ) ? (int) $thumb_src[1] : 100;
			$thumb_h   = ( is_array( $thumb_src ) && ! empty( $thumb_src[2] ) ) ? (int) $thumb_src[2] : 100;

			$srcset       = (string) ( wp_get_attachment_image_srcset( $attachment_id, 'woocommerce_single' ) ?: '' );
			$sizes        = (string) ( wp_get_attachment_image_sizes( $attachment_id, 'woocommerce_single' ) ?: '' );
			$thumb_srcset = (string) ( wp_get_attachment_image_srcset( $attachment_id, 'woocommerce_gallery_thumbnail' ) ?: '' );

			$alt     = trim( wp_strip_all_tags( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) );
			$title   = trim( wp_strip_all_tags( (string) get_the_title( $attachment_id ) ) );
			$caption = trim( wp_strip_all_tags( (string) wp_get_attachment_caption( $attachment_id ) ) );

			// Attach optional video player metadata (MP4, WebM, YouTube, Vimeo).
			$video_meta = class_exists( 'WVGS_Variation_Gallery' )
				? WVGS_Variation_Gallery::get_attachment_video_data( $attachment_id )
				: array(
					'url'       => '',
					'provider'  => '',
					'embed_url' => '',
					'autoplay'  => false,
				);

			$slide = array(
				'id'                       => $attachment_id,
				'is_main'                  => $is_main,
				'full_src'                 => $full_url,
				'full_width'               => $full_width,
				'full_height'              => $full_height,
				'src'                      => (string) $single_src[0],
				'width'                    => (int) $single_src[1],
				'height'                   => (int) $single_src[2],
				'srcset'                   => $srcset,
				'sizes'                    => $sizes,
				'gallery_thumbnail_src'    => $thumb_url,
				'gallery_thumbnail_srcset' => $thumb_srcset,
				'gallery_thumbnail_w'      => $thumb_w,
				'gallery_thumbnail_h'      => $thumb_h,
				'alt'                      => '' !== $alt ? $alt : $title,
				'title'                    => $title,
				'caption'                  => $caption,
				'video'                    => array(
					'has_video' => ( '' !== $video_meta['url'] && '' !== $video_meta['provider'] ),
					'provider'  => (string) $video_meta['provider'],
					'url'       => (string) $video_meta['url'],
					'embed_url' => (string) $video_meta['embed_url'],
					'autoplay'  => (bool) $video_meta['autoplay'],
				),
			);

			self::$slide_cache[ $cache_key ] = $slide;
			return $slide;
		}

		/**
		 * Resolve a single variation's main image ID and strictly isolated additional gallery IDs.
		 *
		 * Delegates to `WVGS_Stock_Resolver::resolve_single_variation_media()` when loaded,
		 * with a clean fallback if the resolver is unavailable.
		 *
		 * @param int                   $product_id Parent variable product ID.
		 * @param \WC_Product_Variation $variation  Child variation instance.
		 * @return array{main_image_id: int, gallery_ids: array<int, int>}
		 */
		private static function resolve_variation_media_ids( int $product_id, \WC_Product_Variation $variation ): array {
			$product_settings = class_exists( 'WVGS_Product_Metabox' )
				? WVGS_Product_Metabox::get_product_settings( $product_id )
				: array();

			$resolver = WVGS_Plugin::instance()->resolver;
			if ( null !== $resolver ) {
				return $resolver->resolve_single_variation_media( $variation, $product_settings );
			}

			$variation_id  = (int) $variation->get_id();
			$main_image_id = absint( get_post_meta( $variation_id, '_thumbnail_id', true ) );
			$gallery_ids   = class_exists( 'WVGS_Variation_Gallery' )
				? WVGS_Variation_Gallery::get_variation_gallery_ids( $variation_id )
				: array();

			return array(
				'main_image_id' => $main_image_id,
				'gallery_ids'   => $gallery_ids,
			);
		}

		/**
		 * Build a slide payload array from a main image ID and additional gallery IDs.
		 *
		 * @param int             $main_image_id Primary image attachment ID.
		 * @param array<int, int> $gallery_ids   Additional gallery attachment IDs.
		 * @return array<int, array<string, mixed>>
		 */
		private static function build_slides_from_media_pair( int $main_image_id, array $gallery_ids ): array {
			$ordered = array();

			if ( $main_image_id > 0 ) {
				$ordered[] = $main_image_id;
			}

			foreach ( $gallery_ids as $gid ) {
				$gid = absint( $gid );
				if ( $gid > 0 && ! in_array( $gid, $ordered, true ) ) {
					$ordered[] = $gid;
				}
			}

			$slides = array();
			foreach ( $ordered as $index => $attachment_id ) {
				$slide = self::build_slide_payload( $attachment_id, 0 === $index );
				if ( null !== $slide ) {
					$slides[] = $slide;
				}
			}

			return $slides;
		}

		/**
		 * Build an attribute-value-indexed gallery map (`attribute_pa_color:white => Slide[]`)
		 * so clicking a color swatch swaps the Flatsome gallery immediately even if the customer
		 * has not picked a size yet (partial selection).
		 *
		 * @param int                       $product_id Parent variable product ID.
		 * @param array<string, mixed>|null $resolved   Pre-computed resolved media payload.
		 * @return array<string, array<int, array<string, mixed>>>
		 */
		private static function build_partial_attribute_galleries( int $product_id, ?array $resolved ): array {
			$map = array();

			if ( ! is_array( $resolved ) || empty( $resolved['ranked_variation_ids'] ) || empty( $resolved['variations'] ) ) {
				return $map;
			}

			// Because `ranked_variation_ids` is ordered highest-stock first, the first in-stock
			// variation encountered for each `attribute_<key>:<value>` pair supplies the winning
			// isolated gallery for that attribute option.
			foreach ( (array) $resolved['ranked_variation_ids'] as $var_id ) {
				$var_id = (int) $var_id;
				if ( ! isset( $resolved['variations'][ $var_id ] ) ) {
					continue;
				}

				$var_data  = $resolved['variations'][ $var_id ];
				$main_id   = (int) ( $var_data['main_image_id'] ?? 0 );
				$gal_ids   = (array) ( $var_data['gallery_ids'] ?? array() );
				$var_attrs = (array) ( $var_data['attributes'] ?? array() );

				if ( $main_id <= 0 && empty( $gal_ids ) ) {
					continue;
				}

				$slides = null;

				foreach ( $var_attrs as $attr_key => $attr_val ) {
					$attr_val = (string) $attr_val;
					if ( '' === $attr_val ) {
						continue;
					}

					$lookup_key = 'attribute_' . sanitize_title( (string) $attr_key ) . ':' . $attr_val;
					if ( isset( $map[ $lookup_key ] ) ) {
						continue;
					}

					if ( null === $slides ) {
						$slides = self::build_slides_from_media_pair( $main_id, $gal_ids );
					}

					if ( ! empty( $slides ) ) {
						$map[ $lookup_key ] = $slides;
					}
				}
			}

			return $map;
		}
	}

	WVGS_Flatsome_Gallery::init();

endif;
