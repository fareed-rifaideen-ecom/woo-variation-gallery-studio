<?php
/**
 * Injects multi-image gallery data into the WooCommerce variation JSON payload.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WVGS_Flatsome_Gallery {

	public static function init() {
		add_filter( 'woocommerce_available_variation', [ __CLASS__, 'inject_variation_gallery_data' ], 10, 3 );
	}

	public static function inject_variation_gallery_data( $variation_data, $product, $variation ) {
		$variation_id = absint( $variation->get_id() );
		$gallery_ids  = get_post_meta( $variation_id, '_wvgs_variation_gallery_ids', true );
		
		// Always include the variation's main image as the first slide
		$main_image_id = $variation->get_image_id();
		$all_image_ids = [];
		
		if ( $main_image_id ) {
			$all_image_ids[] = $main_image_id;
		}

		if ( is_array( $gallery_ids ) ) {
			$all_image_ids = array_merge( $all_image_ids, $gallery_ids );
		}

		$all_image_ids = array_unique( array_filter( $all_image_ids ) );
		
		$variation_gallery = [];
		foreach ( $all_image_ids as $attachment_id ) {
			$full_src  = wp_get_attachment_image_src( $attachment_id, 'full' );
			$large_src = wp_get_attachment_image_src( $attachment_id, 'woocommerce_single' );
			$thumb_src = wp_get_attachment_image_src( $attachment_id, 'woocommerce_gallery_thumbnail' );
			
			if ( $large_src && $thumb_src ) {
				$variation_gallery[] = [
					'id'                 => $attachment_id,
					'full_src'           => $full_src[0],
					'src'                => $large_src[0],
					'srcset'             => wp_get_attachment_image_srcset( $attachment_id, 'woocommerce_single' ),
					'sizes'              => wp_get_attachment_image_sizes( $attachment_id, 'woocommerce_single' ),
					'gallery_thumbnail_src' => $thumb_src[0],
					'alt'                => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
					'title'              => get_the_title( $attachment_id ),
				];
			}
		}

		$variation_data['wvgs_gallery_images'] = $variation_gallery;

		return $variation_data;
	}
}

WVGS_Flatsome_Gallery::init();
