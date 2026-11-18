<?php
/**
 * Plugin Name:       Woo Variation & Gallery Studio (Flatsome Edition)
 * Plugin URI:        https://example.com/plugins/woo-variation-gallery-studio/
 * Description:       Unified Variation Swatches, Strict Single-Variation Gallery Isolation, Dynamic Stock-Winning Main Image & Gallery Resolver, and 3-Mode Flatsome Archive Hover Engine.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Custom WooCommerce Engineering
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-variation-gallery-studio
 * Domain Path:       /languages
 * WC requires at least: 9.0
 * WC tested up to:      9.5
 *
 * @package WooVariationGalleryStudio
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Core plugin constants.
 */
define( 'WVGS_VERSION', '1.0.0' );
define( 'WVGS_FILE', __FILE__ );
define( 'WVGS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WVGS_URL', plugin_dir_url( __FILE__ ) );
define( 'WVGS_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS)
 * and Cart/Checkout Blocks.
 *
 * Official reference: https://developer.woocommerce.com/docs/hpos-extension-recipe-book/
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WVGS_FILE,
				true
			);
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'cart_checkout_blocks',
				WVGS_FILE,
				true
			);
		}
	}
);

if ( ! class_exists( 'WVGS_Cache' ) ) :

	/**
	 * Class WVGS_Cache
	 *
	 * Handles object caching, product-level resolved media metadata persistence,
	 * transient payloads, and targeted single-product page cache invalidation when
	 * the stock-winning variation changes.
	 */
	final class WVGS_Cache {

		/**
		 * Object cache group name.
		 *
		 * @var string
		 */
		public const CACHE_GROUP = 'wvgs_media';

		/**
		 * Post meta key storing the pre-computed stock-winning media & archive payload.
		 *
		 * @var string
		 */
		public const RESOLVED_META_KEY = '_wvgs_resolved_media';

		/**
		 * Post meta key flagging that parent _thumbnail_id is managed dynamically.
		 *
		 * @var string
		 */
		public const DYNAMIC_FLAG_META_KEY = '_wvgs_is_dynamic_media';

		/**
		 * Transient prefix for full frontend variation gallery payloads.
		 *
		 * @var string
		 */
		public const TRANSIENT_PREFIX = 'wvgs_payload_';

		/**
		 * Retrieve cached resolved media array for a parent variable product.
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return array<string, mixed>|null Resolved media payload or null if cache miss.
		 */
		public static function get_resolved_media( int $product_id ): ?array {
			if ( $product_id <= 0 ) {
				return null;
			}

			$cache_key = 'resolved_' . $product_id;
			$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

			if ( is_array( $cached ) && isset( $cached['schema_version'] ) && WVGS_VERSION === $cached['schema_version'] ) {
				return $cached;
			}

			$meta = get_post_meta( $product_id, self::RESOLVED_META_KEY, true );
			if ( is_array( $meta ) && isset( $meta['schema_version'] ) && WVGS_VERSION === $meta['schema_version'] ) {
				wp_cache_set( $cache_key, $meta, self::CACHE_GROUP, HOUR_IN_SECONDS );
				return $meta;
			}

			return null;
		}

		/**
		 * Persist resolved media array into post meta and object cache.
		 *
		 * @param int                  $product_id Parent variable product ID.
		 * @param array<string, mixed> $payload    Computed media payload.
		 * @return void
		 */
		public static function set_resolved_media( int $product_id, array $payload ): void {
			if ( $product_id <= 0 ) {
				return;
			}

			$payload['schema_version'] = WVGS_VERSION;
			$payload['updated_at']     = time();

			update_post_meta( $product_id, self::RESOLVED_META_KEY, $payload );
			wp_cache_set( 'resolved_' . $product_id, $payload, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		/**
		 * Invalidate all plugin caches for a specific product and optionally purge
		 * external page caches (LiteSpeed, WP Rocket, W3TC, WP Super Cache) if the
		 * winning variation or available stock matrix changed.
		 *
		 * @param int  $product_id        Parent product ID.
		 * @param bool $purge_page_cache  Whether to purge full-page HTML cache for this product.
		 * @return void
		 */
		public static function invalidate_product( int $product_id, bool $purge_page_cache = false ): void {
			if ( $product_id <= 0 ) {
				return;
			}

			wp_cache_delete( 'resolved_' . $product_id, self::CACHE_GROUP );
			delete_transient( self::TRANSIENT_PREFIX . $product_id );

			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $product_id );
			}

			if ( $purge_page_cache ) {
				clean_post_cache( $product_id );

				// LiteSpeed Cache targeted post purge.
				if ( has_action( 'litespeed_purge_post' ) ) {
					do_action( 'litespeed_purge_post', $product_id );
				}

				// WP Rocket targeted post purge.
				if ( function_exists( 'rocket_clean_post' ) ) {
					rocket_clean_post( $product_id );
				}

				// W3 Total Cache targeted post purge.
				if ( function_exists( 'w3tc_flush_post' ) ) {
					w3tc_flush_post( $product_id );
				}

				// WP Super Cache targeted post purge.
				if ( function_exists( 'wp_cache_post_change' ) ) {
					wp_cache_post_change( $product_id );
				}
			}
		}
	}

endif;

if ( ! class_exists( 'WVGS_Stock_Resolver' ) ) :

	/**
	 * Class WVGS_Stock_Resolver
	 *
	 * Core engine implementing:
	 * 1. 3-Tier Stock-Winning Variation Calculation (Quantified > Unquantified > Backorder).
	 * 2. Strict Single-Variation Media Isolation (only the active variation's images show in PDP gallery).
	 * 3. URL Attribution Deep-Link Resolution (?attribute_pa_color=white&attribute_pa_size=large).
	 * 4. Color/Attribute-Level Gallery Inheritance -> Variation Gallery fallback.
	 * 5. Physical Parent _thumbnail_id & _product_image_gallery Sync for third-party SQL feeds.
	 * 6. 3-Mode Flatsome Archive Hover Payload (Variation Gallery, Next Variation, Variation Slideshow).
	 */
	final class WVGS_Stock_Resolver {

		/**
		 * Recursion guard flag when syncing physical post meta or resolving runtime getters.
		 *
		 * @var array<int, bool>
		 */
		private array $resolving_locks = array();

		/**
		 * Per-request memoization of resolved active variation ID per product on Single Product Page.
		 *
		 * @var array<int, int>
		 */
		private array $request_active_variation = array();

		/**
		 * Register all WooCommerce CRUD, stock lifecycle, SEO, and admin AJAX hooks.
		 *
		 * @return void
		 */
		public function register_hooks(): void {
			// Runtime WooCommerce Product Image & Gallery Getters.
			add_filter( 'woocommerce_product_get_image_id', array( $this, 'filter_product_image_id' ), 20, 2 );
			add_filter( 'woocommerce_product_get_gallery_image_ids', array( $this, 'filter_product_gallery_image_ids' ), 20, 2 );

			// Variation-level image fallback (supports Color/Attribute-Level Gallery inheritance in Cart/Checkout/Emails).
			add_filter( 'woocommerce_product_variation_get_image_id', array( $this, 'filter_variation_image_id' ), 20, 2 );

			// Pre-select the winning variation (or URL-attributed variation) on Single Product Page & Quick View.
			add_filter( 'woocommerce_product_get_default_attributes', array( $this, 'filter_default_attributes' ), 20, 2 );

			// Hide out-of-stock variations when configured.
			add_filter( 'woocommerce_hide_invisible_variations', array( $this, 'filter_hide_out_of_stock_variations' ), 20, 3 );

			// Event-driven recalculation hooks when variations or stock change.
			add_action( 'woocommerce_save_product_variation', array( $this, 'on_variation_saved' ), 30, 2 );
			add_action( 'woocommerce_update_product', array( $this, 'on_product_updated' ), 30, 1 );
			add_action( 'woocommerce_variation_set_stock', array( $this, 'on_variation_stock_changed' ), 30, 1 );
			add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_variation_stock_status_changed' ), 30, 3 );

			// OpenGraph image override for shared URL attribution links (?attribute_pa_color=...).
			add_filter( 'wpseo_opengraph_image', array( $this, 'filter_opengraph_image_url' ), 20, 1 );
			add_filter( 'rank_math/opengraph/facebook/image', array( $this, 'filter_opengraph_image_url' ), 20, 1 );
			add_filter( 'rank_math/opengraph/twitter/image', array( $this, 'filter_opengraph_image_url' ), 20, 1 );

			// Admin AJAX endpoint to manually recalculate a product's stock winner.
			add_action( 'wp_ajax_wvgs_recalculate_product_media', array( $this, 'ajax_recalculate_product_media' ) );
		}

		/**
		 * Determine if the current request is viewing a single product context
		 * (either standard Single Product Page or Flatsome Quick View AJAX).
		 *
		 * @param int $product_id Product ID being evaluated.
		 * @return bool
		 */
		public function is_single_product_context( int $product_id ): bool {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return false;
			}

			// Flatsome Quick View AJAX check.
			if ( wp_doing_ajax() ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only check of public AJAX action name.
				$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
				if ( in_array( $action, array( 'flatsome_quickview', 'wvgs_get_variation_gallery' ), true ) ) {
					return true;
				}
			}

			if ( is_product() ) {
				$queried_id = get_queried_object_id();
				// Only treat the main queried product as the primary PDP context so related/upsell loops on the same page behave as archive cards.
				if ( $queried_id === $product_id ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Filter the parent variable product's Main Image ID (`woocommerce_product_get_image_id`).
		 *
		 * - On Shop/Archive pages: returns the Stock-Winning variation's main image ID.
		 * - On Single Product Page / Quick View: returns the active variation's main image ID
		 *   (accounting for URL `?attribute_*` parameters or the Stock-Winning variation).
		 *
		 * @param int|string  $image_id Existing image ID.
		 * @param \WC_Product $product  Product instance.
		 * @return int|string
		 */
		public function filter_product_image_id( $image_id, $product ) {
			if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
				return $image_id;
			}

			$product_id = $product->get_id();
			if ( ! empty( $this->resolving_locks[ $product_id ] ) ) {
				return $image_id;
			}

			// Respect per-product static parent image lock if explicitly set by store manager.
			if ( $this->is_parent_image_locked( $product_id ) && ! empty( $image_id ) ) {
				return $image_id;
			}

			$resolved = $this->get_or_compute_product_media( $product_id );
			if ( empty( $resolved['variations'] ) ) {
				return $image_id;
			}

			if ( $this->is_single_product_context( $product_id ) ) {
				$active_var_id = $this->resolve_active_variation_id( $product_id, $resolved );
				if ( $active_var_id > 0 && ! empty( $resolved['variations'][ $active_var_id ]['main_image_id'] ) ) {
					return (int) $resolved['variations'][ $active_var_id ]['main_image_id'];
				}
			}

			if ( ! empty( $resolved['winner_main_image_id'] ) ) {
				return (int) $resolved['winner_main_image_id'];
			}

			return $image_id;
		}

		/**
		 * Filter the parent variable product's Gallery Image IDs (`woocommerce_product_get_gallery_image_ids`).
		 *
		 * Enforces Strict Single-Variation Gallery Isolation:
		 * - On Single Product Page: returns ONLY the additional gallery images of the currently active
		 *   variation (URL-attributed variation or Stock-Winning variation). Never mixes images from other variations.
		 * - On Shop/Archive Cards: returns the hover back-image gallery ID depending on the configured
		 *   Flatsome Archive Hover Mode (`variation_gallery`, `next_variation`, or `variation_slideshow`).
		 *
		 * @param array<int|string> $gallery_ids Existing gallery attachment IDs.
		 * @param \WC_Product       $product     Product instance.
		 * @return array<int>
		 */
		public function filter_product_gallery_image_ids( $gallery_ids, $product ): array {
			if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
				return is_array( $gallery_ids ) ? array_map( 'absint', $gallery_ids ) : array();
			}

			$product_id = $product->get_id();
			if ( ! empty( $this->resolving_locks[ $product_id ] ) ) {
				return is_array( $gallery_ids ) ? array_map( 'absint', $gallery_ids ) : array();
			}

			if ( $this->is_parent_image_locked( $product_id ) && ! empty( $gallery_ids ) ) {
				return array_map( 'absint', (array) $gallery_ids );
			}

			$resolved = $this->get_or_compute_product_media( $product_id );
			if ( empty( $resolved['variations'] ) ) {
				return is_array( $gallery_ids ) ? array_map( 'absint', $gallery_ids ) : array();
			}

			// 1. Single Product Page or Quick View: Strict Single-Variation Gallery Isolation.
			if ( $this->is_single_product_context( $product_id ) ) {
				$active_var_id = $this->resolve_active_variation_id( $product_id, $resolved );
				if ( $active_var_id > 0 && isset( $resolved['variations'][ $active_var_id ]['gallery_image_ids'] ) ) {
					return array_values(
						array_filter(
							array_map( 'absint', (array) $resolved['variations'][ $active_var_id ]['gallery_image_ids'] )
						)
					);
				}

				return array();
			}

			// 2. Shop / Category Archive Cards: Provide Flatsome's native `.back-image` first gallery ID
			// based on the configured Archive Hover Mode (`variation_gallery`, `next_variation`, `variation_slideshow`).
			$hover_mode = $this->get_effective_hover_mode( $product_id );

			if ( 'next_variation' === $hover_mode || 'variation_slideshow' === $hover_mode ) {
				if ( ! empty( $resolved['next_variation_image_id'] ) ) {
					return array( (int) $resolved['next_variation_image_id'] );
				}
			}

			// Default Mode ('variation_gallery'): return the Winning Variation's own additional gallery images.
			if ( ! empty( $resolved['winner_gallery_image_ids'] ) && is_array( $resolved['winner_gallery_image_ids'] ) ) {
				return array_values( array_filter( array_map( 'absint', $resolved['winner_gallery_image_ids'] ) ) );
			}

			return array();
		}

		/**
		 * Filter individual `WC_Product_Variation` image ID (`woocommerce_product_variation_get_image_id`).
		 *
		 * Ensures that if a variation inherits its images from an Attribute-Level Gallery (e.g., Color = Black)
		 * rather than having an individual variation thumbnail uploaded, Cart, Mini-Cart, Checkout, and Order
		 * Emails still display the correct color thumbnail.
		 *
		 * @param int|string            $image_id  Variation image ID.
		 * @param \WC_Product_Variation $variation Variation object.
		 * @return int|string
		 */
		public function filter_variation_image_id( $image_id, $variation ) {
			if ( ! empty( $image_id ) || ! $variation instanceof \WC_Product_Variation ) {
				return $image_id;
			}

			$parent_id = $variation->get_parent_id();
			if ( $parent_id <= 0 || ! empty( $this->resolving_locks[ $parent_id ] ) ) {
				return $image_id;
			}

			$resolved     = $this->get_or_compute_product_media( $parent_id );
			$variation_id = $variation->get_id();

			if ( ! empty( $resolved['variations'][ $variation_id ]['main_image_id'] ) ) {
				return (int) $resolved['variations'][ $variation_id ]['main_image_id'];
			}

			return $image_id;
		}

		/**
		 * Filter default selected variation attributes (`woocommerce_product_get_default_attributes`).
		 *
		 * Automatically pre-selects the Stock-Winning variation's attributes (e.g., Color: Black, Size: Large)
		 * on the Single Product Page, or overrides with valid in-stock URL attribution attributes when a customer
		 * clicks a partial or full attribution deep link (`?attribute_pa_color=white`).
		 *
		 * @param array<string, string> $default_attributes Existing default attributes.
		 * @param \WC_Product           $product            Product instance.
		 * @return array<string, string>
		 */
		public function filter_default_attributes( $default_attributes, $product ): array {
			if ( ! $product instanceof \WC_Product || ! $product->is_type( 'variable' ) ) {
				return is_array( $default_attributes ) ? $default_attributes : array();
			}

			// Do not override inside the standard WP Admin product editor screen so admins see raw DB state.
			if ( is_admin() && ! wp_doing_ajax() ) {
				return is_array( $default_attributes ) ? $default_attributes : array();
			}

			$settings = WVGS_Plugin::instance()->get_settings();
			if ( empty( $settings['auto_select_stock_winner'] ) ) {
				return is_array( $default_attributes ) ? $default_attributes : array();
			}

			$product_id = $product->get_id();
			if ( ! empty( $this->resolving_locks[ $product_id ] ) ) {
				return is_array( $default_attributes ) ? $default_attributes : array();
			}

			$resolved = $this->get_or_compute_product_media( $product_id );
			if ( empty( $resolved['variations'] ) ) {
				return is_array( $default_attributes ) ? $default_attributes : array();
			}

			$active_var_id = $this->resolve_active_variation_id( $product_id, $resolved );
			if ( $active_var_id > 0 && ! empty( $resolved['variations'][ $active_var_id ]['attributes'] ) ) {
				$clean_defaults = array();
				foreach ( $resolved['variations'][ $active_var_id ]['attributes'] as $attr_key => $attr_val ) {
					// Convert 'attribute_pa_color' -> 'pa_color' for WooCommerce default_attributes format.
					$normalized_key = ( 0 === strpos( (string) $attr_key, 'attribute_' ) )
						? substr( (string) $attr_key, 10 )
						: (string) $attr_key;

					if ( '' !== $attr_val ) {
						$clean_defaults[ $normalized_key ] = (string) $attr_val;
					}
				}

				if ( ! empty( $clean_defaults ) ) {
					return $clean_defaults;
				}
			}

			return is_array( $default_attributes ) ? $default_attributes : array();
		}

		/**
		 * Hide out-of-stock variations from frontend variation payloads when configured,
		 * ensuring customers are never shown unavailable colors.
		 *
		 * @param bool                  $hide       Whether to hide invisible variations.
		 * @param int                   $product_id Parent product ID.
		 * @param \WC_Product_Variation $variation  Variation instance.
		 * @return bool
		 */
		public function filter_hide_out_of_stock_variations( bool $hide, int $product_id, $variation ): bool {
			$settings = WVGS_Plugin::instance()->get_settings();
			if ( ! empty( $settings['hide_out_of_stock_variations'] ) && $variation instanceof \WC_Product_Variation ) {
				if ( ! $variation->is_in_stock() ) {
					return true;
				}
			}
			return $hide;
		}

		/**
		 * Resolve which variation ID should be active on the current request.
		 *
		 * Priority:
		 * 1. Validated URL Query Attribution (`?attribute_pa_color=white&attribute_pa_size=small` or partial `?attribute_pa_color=white`).
		 *    If a partial URL attribution is given (e.g. only Color), selects the highest-stock in-stock variation matching that Color!
		 * 2. Overall Stock-Winning Variation ID (`winner_variation_id`).
		 *
		 * @param int                  $product_id Parent variable product ID.
		 * @param array<string, mixed> $resolved   Resolved media payload for the product.
		 * @return int Active variation ID.
		 */
		public function resolve_active_variation_id( int $product_id, array $resolved ): int {
			if ( isset( $this->request_active_variation[ $product_id ] ) ) {
				return $this->request_active_variation[ $product_id ];
			}

			$winner_id        = isset( $resolved['winner_variation_id'] ) ? (int) $resolved['winner_variation_id'] : 0;
			$requested_params = $this->get_sanitized_url_attribute_params();

			if ( empty( $requested_params ) || empty( $resolved['variations'] ) || ! is_array( $resolved['variations'] ) ) {
				$this->request_active_variation[ $product_id ] = $winner_id;
				return $winner_id;
			}

			// Evaluate all in-stock ranked variations (already ordered highest-stock first) against the URL attributes.
			$best_match_id    = 0;
			$best_match_count = 0;

			foreach ( $resolved['variations'] as $var_id => $var_data ) {
				if ( empty( $var_data['is_in_stock'] ) || empty( $var_data['attributes'] ) || ! is_array( $var_data['attributes'] ) ) {
					continue;
				}

				$matched_count = 0;
				$is_compatible = true;

				foreach ( $requested_params as $req_attr_key => $req_attr_val ) {
					if ( ! array_key_exists( $req_attr_key, $var_data['attributes'] ) ) {
						continue;
					}

					$var_attr_val = (string) $var_data['attributes'][ $req_attr_key ];
					// Empty string in variation attribute means "Any [Size/Color]" wildcard in WooCommerce.
					if ( '' === $var_attr_val || $var_attr_val === $req_attr_val ) {
						++$matched_count;
					} else {
						$is_compatible = false;
						break;
					}
				}

				if ( $is_compatible && $matched_count > $best_match_count ) {
					$best_match_count = $matched_count;
					$best_match_id    = (int) $var_id;

					// Because $resolved['variations'] is already sorted in descending stock order,
					// the first full match is guaranteed to be the highest-stock matching variation.
					if ( count( $requested_params ) === $matched_count ) {
						break;
					}
				}
			}

			$final_id = ( $best_match_id > 0 ) ? $best_match_id : $winner_id;

			$this->request_active_variation[ $product_id ] = $final_id;
			return $final_id;
		}

		/**
		 * Extract and strictly sanitize `attribute_*` query parameters from `$_GET`.
		 *
		 * @return array<string, string> Map of `'attribute_pa_...' => 'term-slug'`.
		 */
		private function get_sanitized_url_attribute_params(): array {
			$params = array();

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only inspection of public product URL query args.
			if ( empty( $_GET ) || ! is_array( $_GET ) ) {
				return $params;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			foreach ( $_GET as $raw_key => $raw_value ) {
				if ( ! is_string( $raw_key ) || ! is_scalar( $raw_value ) ) {
					continue;
				}

				$key = sanitize_key( $raw_key );
				if ( 0 !== strpos( $key, 'attribute_' ) ) {
					continue;
				}

				$value = sanitize_title( wp_unslash( (string) $raw_value ) );
				if ( '' !== $value ) {
					$params[ $key ] = $value;
				}
			}

			return $params;
		}

		/**
		 * Retrieve cached resolved media payload or compute and persist it on cache miss.
		 *
		 * @param int  $product_id    Parent variable product ID.
		 * @param bool $force_refresh Whether to bypass existing cache and recalculate from DB.
		 * @return array<string, mixed>
		 */
		public function get_or_compute_product_media( int $product_id, bool $force_refresh = false ): array {
			if ( ! $force_refresh ) {
				$cached = WVGS_Cache::get_resolved_media( $product_id );
				if ( null !== $cached ) {
					return $cached;
				}
			}

			return $this->recalculate_product_media( $product_id );
		}

		/**
		 * Core 5-Step Resolution Algorithm:
		 *
		 * 1. Load all published child variations and rank in-stock variations using the 3-tier stock score.
		 * 2. Resolve each variation's Main Image and Additional Gallery Images (supporting Variation Gallery
		 *    -> Color/Attribute-Level Gallery Inheritance -> Parent fallback).
		 * 3. Promote the #1 Highest-Stock Variation as the Winning Variation.
		 * 4. Build the deduplicated 3-Mode Flatsome Archive Hover & Slideshow dataset.
		 * 5. Physically sync the Winning Variation's main image and gallery to parent `_thumbnail_id` and
		 *    `_product_image_gallery` so SQL-based feeds and search plugins stay in sync, and purge page
		 *    cache if the winning variation changed.
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return array<string, mixed>
		 */
		public function recalculate_product_media( int $product_id ): array {
			if ( $product_id <= 0 || ! empty( $this->resolving_locks[ $product_id ] ) ) {
				return array();
			}

			$this->resolving_locks[ $product_id ] = true;

			try {
				$previous_resolved  = WVGS_Cache::get_resolved_media( $product_id );
				$previous_winner_id = isset( $previous_resolved['winner_variation_id'] ) ? (int) $previous_resolved['winner_variation_id'] : 0;
				$previous_stock_sig = isset( $previous_resolved['stock_signature'] ) ? (string) $previous_resolved['stock_signature'] : '';

				$product = wc_get_product( $product_id );
				if ( ! $product instanceof \WC_Product_Variable ) {
					return array();
				}

				$product_settings    = $this->get_product_swatch_settings( $product_id );
				$attribute_galleries = isset( $product_settings['attribute_galleries'] ) && is_array( $product_settings['attribute_galleries'] )
					? $product_settings['attribute_galleries']
					: array();

				$child_ids        = $product->get_children();
				$scored_in_stock  = array();
				$scored_fallback  = array();

				foreach ( $child_ids as $child_id ) {
					$variation = wc_get_product( $child_id );
					if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() ) {
						continue;
					}

					$var_id       = $variation->get_id();
					$is_in_stock  = $variation->is_in_stock();
					$manage_stock = $variation->get_manage_stock();
					$stock_qty    = $variation->get_stock_quantity();
					$stock_status = $variation->get_stock_status();
					$menu_order   = (int) $variation->get_menu_order();
					$attributes   = $variation->get_variation_attributes();

					// Resolve variation main image & additional gallery images with Attribute-Gallery inheritance.
					$media = $this->resolve_single_variation_media( $variation, $attribute_galleries );

					// Compute 3-tier stock priority score:
					// Tier 1 (300): Quantified in-stock (manage_stock = true & stock_qty > 0)
					// Tier 2 (200): Unquantified in-stock (manage_stock = false & stock_status = 'instock')
					// Tier 3 (100): On backorder ('onbackorder')
					// Tier 0 (0):   Out of stock ('outofstock')
					$tier          = 0;
					$effective_qty = 0;

					if ( $is_in_stock ) {
						if ( $manage_stock && null !== $stock_qty && $stock_qty > 0 ) {
							$tier          = 300;
							$effective_qty = (int) $stock_qty;
						} elseif ( 'instock' === $stock_status && ! $manage_stock ) {
							$tier          = 200;
							$effective_qty = 1;
						} else {
							$tier          = 100;
							$effective_qty = max( 0, (int) $stock_qty );
						}
					}

					$entry = array(
						'variation_id'      => $var_id,
						'is_in_stock'       => $is_in_stock,
						'tier'              => $tier,
						'stock_quantity'    => $effective_qty,
						'raw_stock_qty'     => $stock_qty,
						'stock_status'      => $stock_status,
						'menu_order'        => $menu_order,
						'attributes'        => $attributes,
						'main_image_id'     => $media['main_image_id'],
						'gallery_image_ids' => $media['gallery_image_ids'],
						'price_html'        => $variation->get_price_html(),
					);

					if ( $is_in_stock && $media['main_image_id'] > 0 ) {
						$scored_in_stock[] = $entry;
					} else {
						$scored_fallback[] = $entry;
					}
				}

				// Sort comparator: Tier DESC -> Stock Quantity DESC -> Menu Order ASC -> Variation ID ASC.
				$sorter = static function ( array $a, array $b ): int {
					if ( $a['tier'] !== $b['tier'] ) {
						return $b['tier'] <=> $a['tier'];
					}
					if ( $a['stock_quantity'] !== $b['stock_quantity'] ) {
						return $b['stock_quantity'] <=> $a['stock_quantity'];
					}
					if ( $a['menu_order'] !== $b['menu_order'] ) {
						return $a['menu_order'] <=> $b['menu_order'];
					}
					return $a['variation_id'] <=> $b['variation_id'];
				};

				usort( $scored_in_stock, $sorter );
				usort( $scored_fallback, $sorter );

				// Determine Winning Variation (falls back to first fallback variation only if 100% of product is out of stock).
				$winner = ! empty( $scored_in_stock ) ? $scored_in_stock[0] : ( ! empty( $scored_fallback ) ? $scored_fallback[0] : null );

				$winner_variation_id      = $winner ? (int) $winner['variation_id'] : 0;
				$winner_main_image_id     = $winner ? (int) $winner['main_image_id'] : 0;
				$winner_gallery_image_ids = $winner ? (array) $winner['gallery_image_ids'] : array();

				// Build ordered variations lookup map (in-stock ranked first, then out-of-stock).
				$variations_map   = array();
				$stock_sig_parts  = array();
				$all_ordered_vars = array_merge( $scored_in_stock, $scored_fallback );

				foreach ( $all_ordered_vars as $item ) {
					$vid                    = (int) $item['variation_id'];
					$variations_map[ $vid ] = $item;
					$stock_sig_parts[]      = $vid . ':' . ( $item['is_in_stock'] ? '1' : '0' ) . ':' . $item['stock_quantity'] . ':' . $item['main_image_id'];
				}

				// Build deduplicated in-stock variation visual slides for Flatsome Archive Hover Modes 2 & 3.
				$primary_attr_key        = $this->detect_primary_visual_attribute( $product, $product_settings );
				$archive_slides          = array();
				$seen_visual_keys        = array();
				$next_variation_image_id = 0;

				foreach ( $scored_in_stock as $stock_var ) {
					$img_id = (int) $stock_var['main_image_id'];
					if ( $img_id <= 0 ) {
						continue;
					}

					$attr_slug = '';
					if ( '' !== $primary_attr_key && ! empty( $stock_var['attributes'][ 'attribute_' . $primary_attr_key ] ) ) {
						$attr_slug = (string) $stock_var['attributes'][ 'attribute_' . $primary_attr_key ];
					}

					// Deduplicate by primary visual attribute (e.g. pa_color=black) or attachment ID so
					// Black/Small and Black/Large do not create duplicate Black slides in the hover slideshow.
					$dedupe_key = ( '' !== $attr_slug ) ? 'attr_' . $attr_slug : 'img_' . $img_id;
					if ( isset( $seen_visual_keys[ $dedupe_key ] ) ) {
						continue;
					}
					$seen_visual_keys[ $dedupe_key ] = true;

					if ( 0 === $next_variation_image_id && (int) $stock_var['variation_id'] !== $winner_variation_id && $img_id !== $winner_main_image_id ) {
						$next_variation_image_id = $img_id;
					}

					$first_gallery_id = ! empty( $stock_var['gallery_image_ids'][0] ) ? (int) $stock_var['gallery_image_ids'][0] : 0;

					$archive_slides[] = array(
						'variation_id'     => (int) $stock_var['variation_id'],
						'attribute_key'    => $primary_attr_key,
						'attribute_slug'   => $attr_slug,
						'stock_quantity'   => (int) $stock_var['stock_quantity'],
						'main_image_id'    => $img_id,
						'hover_gallery_id' => $first_gallery_id,
						'attributes'       => $stock_var['attributes'],
						'deep_link_query'  => http_build_query( array_filter( $stock_var['attributes'] ) ),
					);
				}

				$new_stock_signature = md5( implode( '|', $stock_sig_parts ) );

				$payload = array(
					'product_id'               => $product_id,
					'winner_variation_id'      => $winner_variation_id,
					'winner_main_image_id'     => $winner_main_image_id,
					'winner_gallery_image_ids' => $winner_gallery_image_ids,
					'winner_attributes'        => $winner ? $winner['attributes'] : array(),
					'winner_stock_quantity'    => $winner ? (int) $winner['stock_quantity'] : 0,
					'next_variation_image_id'  => $next_variation_image_id,
					'primary_visual_attribute' => $primary_attr_key,
					'archive_slides'           => $archive_slides,
					'variations'               => $variations_map,
					'stock_signature'          => $new_stock_signature,
				);

				// Sync physical parent `_thumbnail_id` and `_product_image_gallery` unless locked.
				if ( ! $this->is_parent_image_locked( $product_id ) && $winner_main_image_id > 0 ) {
					update_post_meta( $product_id, '_thumbnail_id', $winner_main_image_id );
					update_post_meta( $product_id, '_product_image_gallery', implode( ',', $winner_gallery_image_ids ) );
					update_post_meta( $product_id, WVGS_Cache::DYNAMIC_FLAG_META_KEY, 1 );
				}

				WVGS_Cache::set_resolved_media( $product_id, $payload );

				// Purge single-product page cache ONLY if the winning variation or stock signature actually changed.
				$winner_changed = ( $previous_winner_id !== $winner_variation_id ) || ( $previous_stock_sig !== $new_stock_signature );
				WVGS_Cache::invalidate_product( $product_id, $winner_changed );
				WVGS_Cache::set_resolved_media( $product_id, $payload );

				return $payload;
			} finally {
				unset( $this->resolving_locks[ $product_id ] );
			}
		}

		/**
		 * Resolve the Main Image ID and Additional Gallery Image IDs for a single variation.
		 *
		 * Resolution hierarchy:
		 * 1. Variation's own uploaded featured image (`_thumbnail_id`) & variation gallery (`_wvgs_variation_gallery_ids` or legacy `woo_variation_gallery_images`).
		 * 2. Attribute-Level Gallery Inheritance configured under Product Data > Swatches & Gallery (e.g. `pa_color:black`).
		 *
		 * @param \WC_Product_Variation      $variation           Variation instance.
		 * @param array<string, array<int>>  $attribute_galleries Product-level attribute gallery map.
		 * @return array{main_image_id: int, gallery_image_ids: array<int>}
		 */
		private function resolve_single_variation_media( \WC_Product_Variation $variation, array $attribute_galleries ): array {
			$variation_id = $variation->get_id();

			// Raw variation _thumbnail_id without parent fallback.
			$raw_thumb_id = (int) get_post_meta( $variation_id, '_thumbnail_id', true );

			// Check custom variation gallery meta key + legacy woo-variation-gallery meta key fallback.
			$raw_gallery = get_post_meta( $variation_id, '_wvgs_variation_gallery_ids', true );
			if ( empty( $raw_gallery ) ) {
				$raw_gallery = get_post_meta( $variation_id, 'woo_variation_gallery_images', true );
			}

			$gallery_ids = array();
			if ( is_array( $raw_gallery ) ) {
				$gallery_ids = array_map( 'absint', $raw_gallery );
			} elseif ( is_string( $raw_gallery ) && '' !== trim( $raw_gallery ) ) {
				$gallery_ids = array_map( 'absint', explode( ',', $raw_gallery ) );
			}

			// Check Attribute-Level Gallery inheritance (e.g., 'pa_color:black' => [101, 102, 103]).
			$inherited_ids = array();
			$var_attrs     = $variation->get_variation_attributes();
			foreach ( $var_attrs as $attr_key => $attr_val ) {
				if ( '' === $attr_val ) {
					continue;
				}
				$clean_attr = ( 0 === strpos( (string) $attr_key, 'attribute_' ) )
					? substr( (string) $attr_key, 10 )
					: (string) $attr_key;
				$map_key    = $clean_attr . ':' . $attr_val;

				if ( ! empty( $attribute_galleries[ $map_key ] ) && is_array( $attribute_galleries[ $map_key ] ) ) {
					$inherited_ids = array_merge( $inherited_ids, array_map( 'absint', $attribute_galleries[ $map_key ] ) );
				}
			}

			// If variation has no explicit gallery, inherit from attribute gallery.
			if ( empty( $gallery_ids ) && ! empty( $inherited_ids ) ) {
				$gallery_ids = $inherited_ids;
			}

			// If variation has no explicit main image, promote the first image of its gallery (or inherited attribute gallery).
			if ( $raw_thumb_id <= 0 && ! empty( $gallery_ids ) ) {
				$raw_thumb_id = (int) array_shift( $gallery_ids );
			}

			// Deduplicate and ensure the main image is not duplicated inside the additional gallery array.
			$gallery_ids = array_values(
				array_unique(
					array_filter(
						$gallery_ids,
						static function ( int $att_id ) use ( $raw_thumb_id ): bool {
							return $att_id > 0 && $att_id !== $raw_thumb_id;
						}
					)
				)
			);

			return array(
				'main_image_id'     => $raw_thumb_id,
				'gallery_image_ids' => $gallery_ids,
			);
		}

		/**
		 * Detect the primary visual variation attribute (e.g. `pa_color` or `color`) for archive deduplication.
		 *
		 * @param \WC_Product_Variable $product          Variable product.
		 * @param array<string, mixed> $product_settings Per-product settings.
		 * @return string Attribute taxonomy/slug.
		 */
		private function detect_primary_visual_attribute( \WC_Product_Variable $product, array $product_settings ): string {
			if ( ! empty( $product_settings['archive_attribute'] ) && is_string( $product_settings['archive_attribute'] ) ) {
				return $product_settings['archive_attribute'];
			}

			$global_settings = WVGS_Plugin::instance()->get_settings();
			$preferred_slug  = isset( $global_settings['default_archive_attribute'] ) ? (string) $global_settings['default_archive_attribute'] : 'pa_color';

			$variation_attributes = $product->get_variation_attributes();
			if ( isset( $variation_attributes[ $preferred_slug ] ) ) {
				return $preferred_slug;
			}

			foreach ( array_keys( $variation_attributes ) as $attr_name ) {
				if ( false !== stripos( (string) $attr_name, 'color' ) || false !== stripos( (string) $attr_name, 'colour' ) ) {
					return (string) $attr_name;
				}
			}

			$first_key = array_key_first( $variation_attributes );
			return is_string( $first_key ) ? $first_key : '';
		}

		/**
		 * Build the complete frontend Archive Hover & Slideshow configuration for a Flatsome product card.
		 *
		 * Supports the 3 requested Flatsome Hover Back-Image modes:
		 * - `variation_gallery`: Shows the first additional gallery image of the active/winning variation.
		 * - `next_variation`: Shows the main image of the next highest-stock available variation.
		 * - `variation_slideshow`: Cycles through all available in-stock variation main images with a configurable delay.
		 *
		 * @param int    $product_id Parent variable product ID.
		 * @param string $image_size WooCommerce image size slug (default `woocommerce_thumbnail`).
		 * @return array<string, mixed>
		 */
		public function get_archive_hover_config( int $product_id, string $image_size = 'woocommerce_thumbnail' ): array {
			$resolved   = $this->get_or_compute_product_media( $product_id );
			$hover_mode = $this->get_effective_hover_mode( $product_id );
			$delay_ms   = $this->get_effective_slideshow_delay( $product_id );
			$permalink  = get_permalink( $product_id );

			$slides = array();
			if ( ! empty( $resolved['archive_slides'] ) && is_array( $resolved['archive_slides'] ) ) {
				foreach ( $resolved['archive_slides'] as $slide ) {
					$main_id  = isset( $slide['main_image_id'] ) ? (int) $slide['main_image_id'] : 0;
					$hover_id = isset( $slide['hover_gallery_id'] ) ? (int) $slide['hover_gallery_id'] : 0;

					if ( $main_id <= 0 ) {
						continue;
					}

					$main_src    = wp_get_attachment_image_url( $main_id, $image_size );
					$main_srcset = wp_get_attachment_image_srcset( $main_id, $image_size );
					$main_sizes  = wp_get_attachment_image_sizes( $main_id, $image_size );

					if ( ! $main_src ) {
						continue;
					}

					$hover_src    = $hover_id > 0 ? wp_get_attachment_image_url( $hover_id, $image_size ) : '';
					$hover_srcset = $hover_id > 0 ? wp_get_attachment_image_srcset( $hover_id, $image_size ) : '';

					$deep_link = $permalink;
					if ( ! empty( $slide['deep_link_query'] ) && is_string( $permalink ) ) {
						$separator = ( false === strpos( $permalink, '?' ) ) ? '?' : '&';
						$deep_link = $permalink . $separator . $slide['deep_link_query'];
					}

					$slides[] = array(
						'variation_id'   => (int) $slide['variation_id'],
						'attribute_key'  => (string) $slide['attribute_key'],
						'attribute_slug' => (string) $slide['attribute_slug'],
						'stock_quantity' => (int) $slide['stock_quantity'],
						'src'            => (string) $main_src,
						'srcset'         => $main_srcset ? (string) $main_srcset : '',
						'sizes'          => $main_sizes ? (string) $main_sizes : '',
						'hover_src'      => $hover_src ? (string) $hover_src : '',
						'hover_srcset'   => $hover_srcset ? (string) $hover_srcset : '',
						'url'            => (string) $deep_link,
					);
				}
			}

			return array(
				'product_id'          => $product_id,
				'hover_mode'          => $hover_mode,
				'slideshow_delay'     => $delay_ms,
				'winner_variation_id' => isset( $resolved['winner_variation_id'] ) ? (int) $resolved['winner_variation_id'] : 0,
				'slides'              => $slides,
			);
		}

		/**
		 * Get the effective Archive Hover Mode (`variation_gallery` | `next_variation` | `variation_slideshow`).
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return string
		 */
		public function get_effective_hover_mode( int $product_id ): string {
			$allowed          = array( 'variation_gallery', 'next_variation', 'variation_slideshow' );
			$product_settings = $this->get_product_swatch_settings( $product_id );

			if ( ! empty( $product_settings['archive_hover_mode'] ) && in_array( $product_settings['archive_hover_mode'], $allowed, true ) ) {
				return (string) $product_settings['archive_hover_mode'];
			}

			$global_settings = WVGS_Plugin::instance()->get_settings();
			$global_mode     = isset( $global_settings['archive_hover_mode'] ) ? (string) $global_settings['archive_hover_mode'] : 'variation_gallery';

			return in_array( $global_mode, $allowed, true ) ? $global_mode : 'variation_gallery';
		}

		/**
		 * Get the effective Archive Slideshow Delay in milliseconds (bounded between 500ms and 10000ms).
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return int Delay in milliseconds.
		 */
		public function get_effective_slideshow_delay( int $product_id ): int {
			$product_settings = $this->get_product_swatch_settings( $product_id );
			if ( ! empty( $product_settings['archive_slideshow_delay'] ) ) {
				return min( 10000, max( 500, absint( $product_settings['archive_slideshow_delay'] ) ) );
			}

			$global_settings = WVGS_Plugin::instance()->get_settings();
			$delay           = isset( $global_settings['archive_slideshow_delay'] ) ? absint( $global_settings['archive_slideshow_delay'] ) : 1500;

			return min( 10000, max( 500, $delay ) );
		}

		/**
		 * Check whether a specific product has locked its static parent image.
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return bool
		 */
		public function is_parent_image_locked( int $product_id ): bool {
			$product_settings = $this->get_product_swatch_settings( $product_id );
			return ! empty( $product_settings['lock_parent_image'] );
		}

		/**
		 * Retrieve per-product settings stored in `_wvgs_product_settings`.
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return array<string, mixed>
		 */
		public function get_product_swatch_settings( int $product_id ): array {
			$meta = get_post_meta( $product_id, '_wvgs_product_settings', true );
			return is_array( $meta ) ? $meta : array();
		}

		/**
		 * Hook: Recalculate parent product media when a child variation is saved.
		 *
		 * @param int $variation_id Variation ID.
		 * @param int $loop_index   Variation loop index.
		 * @return void
		 */
		public function on_variation_saved( int $variation_id, int $loop_index = 0 ): void {
			$parent_id = wp_get_post_parent_id( $variation_id );
			if ( $parent_id > 0 ) {
				$this->recalculate_product_media( (int) $parent_id );
			}
		}

		/**
		 * Hook: Recalculate when a variable product is saved/updated.
		 *
		 * @param int $product_id Product ID.
		 * @return void
		 */
		public function on_product_updated( int $product_id ): void {
			$product = wc_get_product( $product_id );
			if ( $product instanceof \WC_Product_Variable ) {
				$this->recalculate_product_media( $product_id );
			} elseif ( $product instanceof \WC_Product_Variation ) {
				$parent_id = $product->get_parent_id();
				if ( $parent_id > 0 ) {
					$this->recalculate_product_media( $parent_id );
				}
			}
		}

		/**
		 * Hook: Recalculate immediately when a variation's stock quantity changes (e.g., checkout order or ERP sync).
		 *
		 * @param \WC_Product $variation Variation product object.
		 * @return void
		 */
		public function on_variation_stock_changed( $variation ): void {
			if ( $variation instanceof \WC_Product_Variation ) {
				$parent_id = $variation->get_parent_id();
				if ( $parent_id > 0 ) {
					$this->recalculate_product_media( $parent_id );
				}
			}
		}

		/**
		 * Hook: Recalculate immediately when a variation's stock status flips (`instock` / `outofstock`).
		 *
		 * @param int         $variation_id Variation ID.
		 * @param string      $stock_status New stock status.
		 * @param \WC_Product $variation    Variation object.
		 * @return void
		 */
		public function on_variation_stock_status_changed( int $variation_id, string $stock_status, $variation ): void {
			$parent_id = ( $variation instanceof \WC_Product_Variation )
				? $variation->get_parent_id()
				: (int) wp_get_post_parent_id( $variation_id );

			if ( $parent_id > 0 ) {
				$this->recalculate_product_media( $parent_id );
			}
		}

		/**
		 * Filter OpenGraph image URL (Yoast SEO & RankMath) when a Single Product URL includes
		 * `?attribute_*` query arguments so shared variation links show the exact color image.
		 *
		 * @param string $image_url Default OpenGraph image URL.
		 * @return string
		 */
		public function filter_opengraph_image_url( $image_url ): string {
			if ( ! is_product() ) {
				return (string) $image_url;
			}

			$product_id = get_queried_object_id();
			if ( $product_id <= 0 ) {
				return (string) $image_url;
			}

			$resolved      = $this->get_or_compute_product_media( $product_id );
			$active_var_id = $this->resolve_active_variation_id( $product_id, $resolved );

			if ( $active_var_id > 0 && ! empty( $resolved['variations'][ $active_var_id ]['main_image_id'] ) ) {
				$var_img_url = wp_get_attachment_image_url( (int) $resolved['variations'][ $active_var_id ]['main_image_id'], 'full' );
				if ( $var_img_url ) {
					return (string) $var_img_url;
				}
			}

			return (string) $image_url;
		}

		/**
		 * Admin AJAX handler: Manually recalculate a variable product's stock-winning media.
		 *
		 * Security controls:
		 * - Verifies `wvgs_admin_nonce` via `check_ajax_referer`.
		 * - Verifies user capability `current_user_can( 'edit_post', $product_id )`.
		 *
		 * @return void
		 */
		public function ajax_recalculate_product_media(): void {
			check_ajax_referer( 'wvgs_admin_nonce', 'nonce' );

			$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
			if ( $product_id <= 0 || ! current_user_can( 'edit_post', $product_id ) ) {
				wp_send_json_error(
					array( 'message' => esc_html__( 'Unauthorized or invalid product ID.', 'woo-variation-gallery-studio' ) ),
					403
				);
			}

			$payload = $this->recalculate_product_media( $product_id );
			if ( empty( $payload ) ) {
				wp_send_json_error(
					array( 'message' => esc_html__( 'Product is not a variable product or has no variations.', 'woo-variation-gallery-studio' ) ),
					400
				);
			}

			wp_send_json_success(
				array(
					'message'              => esc_html__( 'Stock-winning variation and gallery recalculated.', 'woo-variation-gallery-studio' ),
					'winner_variation_id'  => $payload['winner_variation_id'],
					'winner_main_image_id' => $payload['winner_main_image_id'],
					'winner_gallery_count' => count( $payload['winner_gallery_image_ids'] ),
					'winner_stock_qty'     => $payload['winner_stock_quantity'],
					'winner_attributes'    => $payload['winner_attributes'],
				)
			);
		}
	}

endif;

if ( ! class_exists( 'WVGS_Plugin' ) ) :

	/**
	 * Class WVGS_Plugin
	 *
	 * Main plugin service container managing global settings, WooCommerce threshold
	 * overrides, and module initialization.
	 */
	final class WVGS_Plugin {

		/**
		 * Option key for global plugin settings.
		 *
		 * @var string
		 */
		public const OPTION_KEY = 'wvgs_settings';

		/**
		 * Singleton instance.
		 *
		 * @var WVGS_Plugin|null
		 */
		private static ?WVGS_Plugin $instance = null;

		/**
		 * Stock & Media Resolver service.
		 *
		 * @var WVGS_Stock_Resolver
		 */
		public WVGS_Stock_Resolver $resolver;

		/**
		 * Retrieve singleton instance.
		 *
		 * @return WVGS_Plugin
		 */
		public static function instance(): WVGS_Plugin {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor.
		 */
		private function __construct() {
			$this->resolver = new WVGS_Stock_Resolver();
		}

		/**
		 * Initialize plugin services after `plugins_loaded`.
		 *
		 * @return void
		 */
		public function boot(): void {
			if ( ! class_exists( 'WooCommerce' ) ) {
				add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );
				return;
			}

			// Register core Stock-Winning Media & Attribution Resolver hooks.
			$this->resolver->register_hooks();

			// Raise WooCommerce AJAX variation threshold so products with 30-120 variations support instant swatches & gallery swaps.
			add_filter( 'woocommerce_ajax_variation_threshold', array( $this, 'filter_ajax_variation_threshold' ), 20, 2 );

			// Load optional modular files for Phase 2 (Admin) & Phase 3 (Frontend) if present in includes/.
			$this->load_optional_modules();
		}

		/**
		 * Default global plugin settings.
		 *
		 * @return array<string, mixed>
		 */
		public static function get_default_settings(): array {
			return array(
				// Dynamic Stock-Winning & Selection Rules.
				'enable_stock_winner'          => true,
				'auto_select_stock_winner'     => true,
				'hide_out_of_stock_variations' => true,
				'sync_header_price'            => true,
				'ajax_variation_threshold'     => 120,

				// Flatsome Shop Archive Hover & Swatch Settings.
				'enable_archive_swatches'      => true,
				'default_archive_attribute'    => 'pa_color',
				'archive_swatch_limit'         => 5,
				'archive_hover_mode'           => 'variation_gallery', // 'variation_gallery' | 'next_variation' | 'variation_slideshow'.
				'archive_slideshow_delay'      => 1500,                // Milliseconds (500 - 10000).

				// Swatch Visual Design Tokens.
				'swatch_shape'                 => 'rounded',           // 'circle' | 'rounded' | 'square'.
				'swatch_size_pdp'              => 38,                  // Pixels.
				'swatch_size_archive'          => 24,                  // Pixels.
				'enable_tooltips'              => true,
				'out_of_stock_behavior'        => 'hide',              // 'hide' | 'cross' | 'blur'.
			);
		}

		/**
		 * Retrieve merged global settings.
		 *
		 * @return array<string, mixed>
		 */
		public function get_settings(): array {
			$saved = get_option( self::OPTION_KEY, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			return wp_parse_args( $saved, self::get_default_settings() );
		}

		/**
		 * Filter `woocommerce_ajax_variation_threshold` so variable products up to the
		 * configured threshold (default 120) have full client-side variation JSON.
		 *
		 * @param int         $threshold Default threshold (30).
		 * @param \WC_Product $product   Product object.
		 * @return int
		 */
		public function filter_ajax_variation_threshold( int $threshold, $product ): int {
			$settings        = $this->get_settings();
			$custom_limit    = isset( $settings['ajax_variation_threshold'] ) ? absint( $settings['ajax_variation_threshold'] ) : 120;
			return max( $threshold, min( 500, $custom_limit ) );
		}

		/**
		 * Load Phase 2 and Phase 3 sub-modules when deployed as separate files in `includes/`.
		 *
		 * @return void
		 */
		private function load_optional_modules(): void {
			$module_files = array(
				'includes/Admin/class-wvgs-admin-settings.php',
				'includes/Admin/class-wvgs-term-meta.php',
				'includes/Admin/class-wvgs-product-metabox.php',
				'includes/Admin/class-wvgs-variation-gallery.php',
				'includes/Frontend/class-wvgs-swatch-renderer.php',
				'includes/Frontend/class-wvgs-flatsome-gallery.php',
				'includes/Frontend/class-wvgs-archive-loop.php',
			);

			foreach ( $module_files as $relative_path ) {
				$full_path = WVGS_PATH . $relative_path;
				if ( file_exists( $full_path ) ) {
					require_once $full_path;
				}
			}
		}

		/**
		 * Render admin notice if WooCommerce is inactive.
		 *
		 * @return void
		 */
		public function render_missing_woocommerce_notice(): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Woo Variation & Gallery Studio requires WooCommerce 9.0+ to be installed and active.', 'woo-variation-gallery-studio' )
			);
		}
	}

endif;

/**
 * Activation hook: seed default settings if not present.
 */
register_activation_hook(
	WVGS_FILE,
	static function (): void {
		if ( false === get_option( WVGS_Plugin::OPTION_KEY, false ) ) {
			add_option( WVGS_Plugin::OPTION_KEY, WVGS_Plugin::get_default_settings(), '', 'yes' );
		}
	}
);

/**
 * Boot the plugin on `plugins_loaded`.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		WVGS_Plugin::instance()->boot();
	},
	20
);
