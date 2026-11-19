<?php
/**
 * Dynamic Stock-Winning Media & Attribution Resolver
 *
 * Core media engine that:
 * 1. Ranks in-stock variations using a 3-tier stock priority (Quantified -> Unquantified -> Backorder).
 * 2. Promotes the winning variation's main image and gallery to the parent product (_thumbnail_id & _product_image_gallery).
 * 3. Enforces strict single-variation gallery isolation on Single Product Pages and Flatsome Quick View.
 * 4. Resolves full and partial URL attribution deep links (?attribute_pa_color=white).
 * 5. Pre-computes deduplicated slide payloads for the 3-Mode Flatsome Archive Hover Engine.
 *
 * @package WooVariationGalleryStudio
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WVGS_Stock_Resolver' ) ) :

	/**
	 * Class WVGS_Stock_Resolver
	 *
	 * Coordinates stock-based media promotion, single-variation gallery isolation,
	 * default variation auto-selection, and archive hover slide generation.
	 */
	final class WVGS_Stock_Resolver {

		/**
		 * Recursion guard indexed by product ID.
		 *
		 * @var array<int, bool>
		 */
		private array $resolving_products = array();

		/**
		 * Queue of parent variable product IDs scheduled for batched recalculation on `shutdown`.
		 *
		 * @var array<int, bool>
		 */
		private array $pending_recalculations = array();

		/**
		 * Per-request memoization of active variation ID per product ID.
		 *
		 * @var array<int, int>
		 */
		private array $active_variation_cache = array();

		/**
		 * Register all WooCommerce, WordPress, SEO, and AJAX lifecycle hooks.
		 *
		 * @return void
		 */
		public function register_hooks(): void {
			// 1. Runtime media & default attribute filters (PDP, Quick View, Shop Archive, Feeds).
			add_filter( 'woocommerce_product_get_image_id', array( $this, 'filter_product_image_id' ), 20, 2 );
			add_filter( 'woocommerce_product_get_gallery_image_ids', array( $this, 'filter_product_gallery_image_ids' ), 20, 2 );
			add_filter( 'woocommerce_product_get_default_attributes', array( $this, 'filter_default_attributes' ), 20, 2 );

			// 2. Variation-level thumbnail inheritance (Cart, Mini-Cart, Checkout, Order Emails).
			add_filter( 'woocommerce_product_variation_get_image_id', array( $this, 'filter_variation_image_id' ), 20, 2 );

			// 3. Social Sharing / OpenGraph image override for attributed deep links.
			add_filter( 'wpseo_opengraph_image', array( $this, 'filter_opengraph_image' ), 20 );
			add_filter( 'rank_math/opengraph/facebook/image', array( $this, 'filter_opengraph_image' ), 20 );

			// 4. Event-driven stock & variation save triggers (batched on shutdown to avoid duplicate work).
			add_action( 'woocommerce_save_product_variation', array( $this, 'schedule_recalc_from_variation_id' ), 20, 1 );
			add_action( 'woocommerce_ajax_save_product_variations', array( $this, 'schedule_recalc_from_product_id' ), 20, 1 );
			add_action( 'woocommerce_update_product', array( $this, 'schedule_recalc_from_product_id' ), 20, 1 );
			add_action( 'woocommerce_variation_set_stock', array( $this, 'schedule_recalc_from_product_object' ), 20, 1 );
			add_action( 'woocommerce_variation_set_stock_status', array( $this, 'schedule_recalc_from_stock_status_hook' ), 20, 3 );
			add_action( 'woocommerce_product_set_stock', array( $this, 'schedule_recalc_from_product_object' ), 20, 1 );
			add_action( 'woocommerce_product_set_stock_status', array( $this, 'schedule_recalc_from_stock_status_hook' ), 20, 3 );
			add_action( 'woocommerce_delete_product_variation', array( $this, 'schedule_recalc_from_variation_id' ), 20, 1 );
			add_action( 'woocommerce_trash_product_variation', array( $this, 'schedule_recalc_from_variation_id' ), 20, 1 );

			// Process queued recalculations once per HTTP request on shutdown.
			add_action( 'shutdown', array( $this, 'process_pending_recalculations' ), 20 );

			// 5. Admin AJAX manual recalculation endpoint (triggered from Product Data > Swatches & Gallery).
			add_action( 'wp_ajax_wvgs_recalculate_stock_winner', array( $this, 'ajax_recalculate_stock_winner' ) );
		}

		/**
		 * Queue a parent variable product for recalculation via its product ID.
		 *
		 * @param int $product_id Product ID.
		 * @return void
		 */
		public function schedule_recalc_from_product_id( int $product_id ): void {
			if ( $product_id <= 0 ) {
				return;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return;
			}

			if ( $product->is_type( 'variation' ) ) {
				$parent_id = (int) $product->get_parent_id();
				if ( $parent_id > 0 ) {
					$this->pending_recalculations[ $parent_id ] = true;
				}
				return;
			}

			if ( $product->is_type( 'variable' ) ) {
				$this->pending_recalculations[ $product_id ] = true;
			}
		}

		/**
		 * Queue a parent variable product for recalculation from a child variation ID.
		 *
		 * @param int $variation_id Variation post ID.
		 * @return void
		 */
		public function schedule_recalc_from_variation_id( int $variation_id ): void {
			if ( $variation_id <= 0 ) {
				return;
			}

			$parent_id = (int) wp_get_post_parent_id( $variation_id );
			if ( $parent_id > 0 ) {
				$this->pending_recalculations[ $parent_id ] = true;
			}
		}

		/**
		 * Queue a parent variable product from a WC_Product object passed by stock hooks.
		 *
		 * @param \WC_Product|int $product Product object or ID.
		 * @return void
		 */
		public function schedule_recalc_from_product_object( $product ): void {
			if ( is_numeric( $product ) ) {
				$this->schedule_recalc_from_product_id( (int) $product );
				return;
			}

			if ( ! $product instanceof \WC_Product ) {
				return;
			}

			if ( $product->is_type( 'variation' ) ) {
				$parent_id = (int) $product->get_parent_id();
				if ( $parent_id > 0 ) {
					$this->pending_recalculations[ $parent_id ] = true;
				}
			} elseif ( $product->is_type( 'variable' ) ) {
				$this->pending_recalculations[ (int) $product->get_id() ] = true;
			}
		}

		/**
		 * Queue recalculation from WooCommerce's `*_set_stock_status` hooks.
		 *
		 * @param int         $product_id   Product or variation ID.
		 * @param string      $stock_status New stock status.
		 * @param \WC_Product $product      Product instance.
		 * @return void
		 */
		public function schedule_recalc_from_stock_status_hook( int $product_id, string $stock_status, $product = null ): void {
			if ( $product instanceof \WC_Product ) {
				$this->schedule_recalc_from_product_object( $product );
			} else {
				$this->schedule_recalc_from_product_id( $product_id );
			}
		}

		/**
		 * Execute all queued product recalculations once at request shutdown.
		 *
		 * @return void
		 */
		public function process_pending_recalculations(): void {
			if ( empty( $this->pending_recalculations ) ) {
				return;
			}

			$product_ids                  = array_keys( $this->pending_recalculations );
			$this->pending_recalculations = array();

			foreach ( $product_ids as $product_id ) {
				$this->recalculate_product_media( (int) $product_id );
			}
		}

		/**
		 * Recalculate stock rankings, winning variation, strict variation media map,
		 * physical parent meta sync, and targeted page-cache invalidation for a variable product.
		 *
		 * @param int  $product_id Parent variable product ID.
		 * @param bool $force_sync Whether to force physical meta write even if unchanged.
		 * @return array<string, mixed>|null Resolved media payload, or null if not a variable product.
		 */
		public function recalculate_product_media( int $product_id, bool $force_sync = false ): ?array {
			if ( $product_id <= 0 || isset( $this->resolving_products[ $product_id ] ) ) {
				return null;
			}

			$this->resolving_products[ $product_id ] = true;

			try {
				$product = wc_get_product( $product_id );
				if ( ! $product instanceof \WC_Product_Variable ) {
					return null;
				}

				$previous_payload = class_exists( 'WVGS_Cache' ) ? WVGS_Cache::get_resolved_media( $product_id ) : null;
				$product_settings = $this->get_product_settings( $product_id );
				$variation_ids    = $product->get_children();

				$ranked_candidates = array();
				$fallback_candidate = null;

				foreach ( $variation_ids as $variation_id ) {
					$variation_id = (int) $variation_id;
					$variation    = wc_get_product( $variation_id );

					if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() ) {
						continue;
					}

					$media        = $this->resolve_single_variation_media( $variation, $product_settings );
					$tier_info    = $this->compute_stock_tier( $variation );
					$raw_attrs    = $this->normalize_variation_attributes( $variation->get_attributes() );

					$entry = array(
						'variation_id'  => $variation_id,
						'tier'          => $tier_info['tier'],
						'stock_qty'     => $tier_info['stock_qty'],
						'stock_status'  => $tier_info['stock_status'],
						'is_in_stock'   => $tier_info['is_in_stock'],
						'menu_order'    => (int) $variation->get_menu_order(),
						'main_image_id' => $media['main_image_id'],
						'gallery_ids'   => $media['gallery_ids'],
						'attributes'    => $raw_attrs,
						'label'         => $this->build_variation_human_label( $variation ),
					);

					// Track first published variation with an image as an all-out-of-stock safety net.
					if ( null === $fallback_candidate && $entry['main_image_id'] > 0 ) {
						$fallback_candidate = $entry;
					}

					if ( $entry['is_in_stock'] ) {
						$ranked_candidates[] = $entry;
					}
				}

				// Sort eligible in-stock variations: Tier ASC (1 -> 2 -> 3), Stock QTY DESC, Menu Order ASC, ID ASC.
				usort(
					$ranked_candidates,
					static function ( array $a, array $b ): int {
						if ( $a['tier'] !== $b['tier'] ) {
							return $a['tier'] <=> $b['tier'];
						}
						if ( 1 === $a['tier'] && $a['stock_qty'] !== $b['stock_qty'] ) {
							return $b['stock_qty'] <=> $a['stock_qty'];
						}
						if ( $a['menu_order'] !== $b['menu_order'] ) {
							return $a['menu_order'] <=> $b['menu_order'];
						}
						return $a['variation_id'] <=> $b['variation_id'];
					}
				);

				// Choose the Stock Winner: highest-ranked in-stock variation (preferring one with an image if available).
				$winner = null;
				foreach ( $ranked_candidates as $candidate ) {
					if ( $candidate['main_image_id'] > 0 ) {
						$winner = $candidate;
						break;
					}
				}
				if ( null === $winner && ! empty( $ranked_candidates ) ) {
					$winner = $ranked_candidates[0];
				}
				if ( null === $winner && null !== $fallback_candidate ) {
					$winner = $fallback_candidate;
				}

				// Build compact indexed maps for fast O(1) runtime lookups on PDP & Archives.
				$variations_map        = array();
				$in_stock_attr_values  = array();
				$stock_signature_parts = array();

				foreach ( $ranked_candidates as $candidate ) {
					$vid                    = (int) $candidate['variation_id'];
					$variations_map[ $vid ] = $candidate;

					$stock_signature_parts[] = sprintf(
						'%d:%d:%d:%s',
						$vid,
						$candidate['tier'],
						$candidate['main_image_id'],
						implode( ',', $candidate['gallery_ids'] )
					);

					foreach ( $candidate['attributes'] as $attr_key => $attr_val ) {
						if ( '' === $attr_val ) {
							continue;
						}
						if ( ! isset( $in_stock_attr_values[ $attr_key ] ) ) {
							$in_stock_attr_values[ $attr_key ] = array();
						}
						$in_stock_attr_values[ $attr_key ][ $attr_val ] = true;
					}
				}

				// Normalize in-stock attribute value arrays.
				foreach ( $in_stock_attr_values as $attr_key => $val_map ) {
					$in_stock_attr_values[ $attr_key ] = array_keys( $val_map );
				}

				// Find the next visually distinct in-stock variation's main image (for Archive Mode 2: next_variation).
				$next_variation_image_id = 0;
				$winner_main_image_id    = $winner ? (int) $winner['main_image_id'] : 0;

				foreach ( $ranked_candidates as $candidate ) {
					if ( $candidate['main_image_id'] > 0 && $candidate['main_image_id'] !== $winner_main_image_id ) {
						$next_variation_image_id = (int) $candidate['main_image_id'];
						break;
					}
				}

				$stock_signature = md5( implode( '|', $stock_signature_parts ) );

				$payload = array(
					'product_id'              => $product_id,
					'winner_variation_id'     => $winner ? (int) $winner['variation_id'] : 0,
					'winner_label'            => $winner ? (string) $winner['label'] : '',
					'winner_stock_qty'        => $winner ? $winner['stock_qty'] : null,
					'winner_tier'             => $winner ? (int) $winner['tier'] : 4,
					'winner_attributes'       => $winner ? $winner['attributes'] : array(),
					'main_image_id'           => $winner_main_image_id,
					'gallery_ids'             => $winner ? $winner['gallery_ids'] : array(),
					'next_variation_image_id' => $next_variation_image_id,
					'ranked_variation_ids'    => array_keys( $variations_map ),
					'variations'              => $variations_map,
					'in_stock_attributes'     => $in_stock_attr_values,
					'stock_signature'         => $stock_signature,
				);

				// Persist resolved media to Cache + Post Meta.
				if ( class_exists( 'WVGS_Cache' ) ) {
					WVGS_Cache::set_resolved_media( $product_id, $payload );
					// Clear archive transient so next shop render rebuilds URLs/srcsets cleanly.
					delete_transient( WVGS_Cache::ARCHIVE_TRANSIENT_PREFIX . $product_id );
				}

				// Clear per-request active variation memoization.
				unset( $this->active_variation_cache[ $product_id ] );

				// Detect whether the winning variation or stock media signature actually changed.
				$prev_winner_id = is_array( $previous_payload ) ? (int) ( $previous_payload['winner_variation_id'] ?? 0 ) : -1;
				$prev_signature = is_array( $previous_payload ) ? (string) ( $previous_payload['stock_signature'] ?? '' ) : '';
				$has_changed    = $force_sync || ( $prev_winner_id !== $payload['winner_variation_id'] ) || ( $prev_signature !== $stock_signature );

				// Sync winning media physically to parent _thumbnail_id and _product_image_gallery for external SQL feeds.
				$this->sync_physical_parent_meta( $product_id, $payload, $product_settings );

				// Purge targeted page caches ONLY when the winning variation or stock signature actually changed.
				if ( $has_changed && class_exists( 'WVGS_Cache' ) ) {
					WVGS_Cache::purge_page_caches( $product_id );
				}

				/**
				 * Fires after WVGS recalculates stock-winning media for a variable product.
				 *
				 * @param int                  $product_id  Parent variable product ID.
				 * @param array<string, mixed> $payload     Newly computed resolved media payload.
				 * @param bool                 $has_changed Whether winner or in-stock media changed.
				 */
				do_action( 'wvgs_after_recalculate_product_media', $product_id, $payload, $has_changed );

				return $payload;
			} finally {
				unset( $this->resolving_products[ $product_id ] );
			}
		}

		/**
		 * Retrieve cached resolved media payload for a variable product, recalculating on cache miss.
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return array<string, mixed>|null
		 */
		public function get_resolved_media( int $product_id ): ?array {
			if ( $product_id <= 0 ) {
				return null;
			}

			if ( class_exists( 'WVGS_Cache' ) ) {
				$cached = WVGS_Cache::get_resolved_media( $product_id );
				if ( null !== $cached ) {
					return $cached;
				}
			}

			return $this->recalculate_product_media( $product_id );
		}

		/**
		 * Resolve a single variation's main image ID and strictly isolated additional gallery IDs.
		 *
		 * Resolution priority:
		 * 1. Variation-level main image (`_thumbnail_id`) + variation-level gallery (`_wvgs_variation_gallery_ids` or legacy `woo_variation_gallery_images`).
		 * 2. Attribute-Level Gallery fallback (`_wvgs_product_settings['attribute_galleries']`), supporting both `pa_color:slug` and `color:slug`.
		 *    If variation `_thumbnail_id` is empty, the first image of the matched Attribute-Level Gallery becomes its main image!
		 *
		 * @param \WC_Product_Variation $variation        Variation instance.
		 * @param array<string, mixed>  $product_settings Parent product WVGS settings.
		 * @return array{main_image_id: int, gallery_ids: array<int, int>}
		 */
		public function resolve_single_variation_media( \WC_Product_Variation $variation, array $product_settings = array() ): array {
			$variation_id = (int) $variation->get_id();

			// Read raw variation _thumbnail_id directly to avoid triggering our own variation image filter.
			$raw_thumb_id  = absint( get_post_meta( $variation_id, '_thumbnail_id', true ) );
			$main_image_id = ( $raw_thumb_id > 0 && $this->is_valid_attachment( $raw_thumb_id ) ) ? $raw_thumb_id : 0;

			// 1. Variation-level gallery IDs (with legacy woo-variation-gallery fallback read).
			$var_gallery = get_post_meta( $variation_id, '_wvgs_variation_gallery_ids', true );
			if ( empty( $var_gallery ) ) {
				$var_gallery = get_post_meta( $variation_id, 'woo_variation_gallery_images', true );
			}
			$gallery_ids = $this->sanitize_attachment_id_list( $var_gallery );

			// 2. Attribute-Level Gallery fallback (e.g. Color = Black uploaded once for all Black sizes).
			if ( empty( $product_settings ) ) {
				$product_settings = $this->get_product_settings( (int) $variation->get_parent_id() );
			}

			$attr_gallery_ids = $this->match_attribute_level_gallery( $variation, $product_settings );

			if ( empty( $gallery_ids ) && ! empty( $attr_gallery_ids ) ) {
				$gallery_ids = $attr_gallery_ids;
			}

			// If variation has no direct main image, promote the first gallery image (e.g., from Attribute-Level Gallery).
			if ( 0 === $main_image_id && ! empty( $gallery_ids ) ) {
				$main_image_id = (int) array_shift( $gallery_ids );
			}

			// Ensure main image is never duplicated inside the additional gallery array.
			if ( $main_image_id > 0 && ! empty( $gallery_ids ) ) {
				$gallery_ids = array_values(
					array_filter(
						$gallery_ids,
						static fn ( int $id ): bool => $id !== $main_image_id
					)
				);
			}

			return array(
				'main_image_id' => $main_image_id,
				'gallery_ids'   => $gallery_ids,
			);
		}

		/**
		 * Filter parent variable product Main Image ID (`woocommerce_product_get_image_id`).
		 *
		 * @param int|string  $image_id Existing image ID.
		 * @param \WC_Product $product  Product instance.
		 * @return int|string
		 */
		public function filter_product_image_id( $image_id, $product ) {
			if ( ! $product instanceof \WC_Product_Variable ) {
				return $image_id;
			}

			$product_id = (int) $product->get_id();
			if ( $product_id <= 0 || isset( $this->resolving_products[ $product_id ] ) ) {
				return $image_id;
			}

			$global_settings = WVGS_Plugin::instance()->get_settings();
			if ( empty( $global_settings['enable_stock_winner'] ) ) {
				return $image_id;
			}

			$product_settings = $this->get_product_settings( $product_id );
			if ( ! empty( $product_settings['lock_parent_image'] ) ) {
				return $image_id;
			}

			$resolved = $this->get_resolved_media( $product_id );
			if ( empty( $resolved ) ) {
				return $image_id;
			}

			// On Single Product Page or Flatsome Quick View, honor active variation (URL attribution or stock winner).
			if ( $this->is_single_product_context( $product_id ) ) {
				$active_var_id = $this->resolve_active_variation_id( $product, $resolved );
				if ( $active_var_id > 0 && isset( $resolved['variations'][ $active_var_id ]['main_image_id'] ) ) {
					$active_image_id = (int) $resolved['variations'][ $active_var_id ]['main_image_id'];
					if ( $active_image_id > 0 ) {
						return $active_image_id;
					}
				}
			}

			// On Shop Archives, Related Products, and Feeds, return the highest-stock Winning Variation image.
			if ( ! empty( $resolved['main_image_id'] ) ) {
				return (int) $resolved['main_image_id'];
			}

			return $image_id;
		}

		/**
		 * Filter parent variable product Gallery Image IDs (`woocommerce_product_get_gallery_image_ids`).
		 *
		 * Enforces:
		 * - Strict Single-Variation Gallery Isolation on PDP / Quick View (ONLY active variation's gallery slides).
		 * - 3-Mode Flatsome Archive Hover Back-Image on Shop / Category grids.
		 *
		 * @param array<int, int> $gallery_ids Existing gallery attachment IDs.
		 * @param \WC_Product     $product     Product instance.
		 * @return array<int, int>
		 */
		public function filter_product_gallery_image_ids( array $gallery_ids, $product ): array {
			if ( ! $product instanceof \WC_Product_Variable ) {
				return $gallery_ids;
			}

			$product_id = (int) $product->get_id();
			if ( $product_id <= 0 || isset( $this->resolving_products[ $product_id ] ) ) {
				return $gallery_ids;
			}

			$global_settings = WVGS_Plugin::instance()->get_settings();
			if ( empty( $global_settings['enable_stock_winner'] ) ) {
				return $gallery_ids;
			}

			$product_settings = $this->get_product_settings( $product_id );
			if ( ! empty( $product_settings['lock_parent_image'] ) ) {
				return $gallery_ids;
			}

			$resolved = $this->get_resolved_media( $product_id );
			if ( empty( $resolved ) ) {
				return $gallery_ids;
			}

			// A. Single Product Page / Flatsome Quick View: STRICT single-variation gallery isolation.
			if ( $this->is_single_product_context( $product_id ) ) {
				$active_var_id = $this->resolve_active_variation_id( $product, $resolved );
				if ( $active_var_id > 0 && isset( $resolved['variations'][ $active_var_id ] ) ) {
					return array_map( 'intval', (array) $resolved['variations'][ $active_var_id ]['gallery_ids'] );
				}
				return array_map( 'intval', (array) ( $resolved['gallery_ids'] ?? array() ) );
			}

			// B. Shop / Category Archive / Related Products Grid: Respect 3-Mode Flatsome Hover Setting.
			if ( ! is_admin() ) {
				$hover_mode = $this->resolve_product_hover_mode( $product_settings, $global_settings );

				if ( 'next_variation' === $hover_mode ) {
					$next_id = (int) ( $resolved['next_variation_image_id'] ?? 0 );
					return $next_id > 0 ? array( $next_id ) : array();
				}

				if ( 'variation_slideshow' === $hover_mode ) {
					// Return winning variation's 2nd image as fallback if JS is unavailable; JS hides .back-image during slideshow.
					$winner_gallery = (array) ( $resolved['gallery_ids'] ?? array() );
					return ! empty( $winner_gallery ) ? array( (int) $winner_gallery[0] ) : array();
				}

				// Default Mode 1 (`variation_gallery`): Return winning variation's additional gallery images only.
				return array_map( 'intval', (array) ( $resolved['gallery_ids'] ?? array() ) );
			}

			return array_map( 'intval', (array) ( $resolved['gallery_ids'] ?? array() ) );
		}

		/**
		 * Filter WooCommerce Default Attributes (`woocommerce_product_get_default_attributes`).
		 *
		 * Pre-selects the URL-attributed variation (full or partial) OR the highest-stock Winning Variation
		 * on the Single Product Page and Flatsome Quick View.
		 *
		 * @param array<string, string> $defaults Existing default attributes.
		 * @param \WC_Product           $product  Product instance.
		 * @return array<string, string>
		 */
		public function filter_default_attributes( array $defaults, $product ): array {
			if ( is_admin() && ! wp_doing_ajax() ) {
				return $defaults;
			}

			if ( ! $product instanceof \WC_Product_Variable ) {
				return $defaults;
			}

			$product_id = (int) $product->get_id();
			if ( $product_id <= 0 || isset( $this->resolving_products[ $product_id ] ) ) {
				return $defaults;
			}

			if ( ! $this->is_single_product_context( $product_id ) ) {
				return $defaults;
			}

			$global_settings = WVGS_Plugin::instance()->get_settings();
			$url_attributes  = $this->extract_url_attributes();

			// If neither URL attribution nor Auto-Select Stock Winner is active, leave defaults untouched.
			if ( empty( $url_attributes ) && empty( $global_settings['auto_select_stock_winner'] ) ) {
				return $defaults;
			}

			$resolved = $this->get_resolved_media( $product_id );
			if ( empty( $resolved ) ) {
				return $defaults;
			}

			$active_var_id = $this->resolve_active_variation_id( $product, $resolved );
			if ( $active_var_id > 0 && isset( $resolved['variations'][ $active_var_id ]['attributes'] ) ) {
				$resolved_attrs = array_filter(
					(array) $resolved['variations'][ $active_var_id ]['attributes'],
					static fn ( string $v ): bool => '' !== $v
				);
				if ( ! empty( $resolved_attrs ) ) {
					return $resolved_attrs;
				}
			}

			return $defaults;
		}

		/**
		 * Filter child variation Image ID (`woocommerce_product_variation_get_image_id`).
		 *
		 * Ensures variations using Color-Level / Attribute-Level Galleries display their inherited
		 * primary image in Flatsome Mini-Cart, Cart, Checkout, and Order Emails.
		 *
		 * @param int|string  $image_id  Existing variation image ID.
		 * @param \WC_Product $variation Variation instance.
		 * @return int|string
		 */
		public function filter_variation_image_id( $image_id, $variation ) {
			if ( ! empty( $image_id ) || ! $variation instanceof \WC_Product_Variation ) {
				return $image_id;
			}

			$parent_id = (int) $variation->get_parent_id();
			if ( $parent_id <= 0 || isset( $this->resolving_products[ $parent_id ] ) ) {
				return $image_id;
			}

			$product_settings = $this->get_product_settings( $parent_id );
			$attr_gallery     = $this->match_attribute_level_gallery( $variation, $product_settings );

			if ( ! empty( $attr_gallery ) ) {
				return (int) $attr_gallery[0];
			}

			return $image_id;
		}

		/**
		 * Override OpenGraph social share image (Yoast SEO & RankMath) when an attributed URL is shared.
		 *
		 * @param string $image_url Default OpenGraph image URL.
		 * @return string
		 */
		public function filter_opengraph_image( $image_url ) {
			if ( ! is_product() ) {
				return $image_url;
			}

			$url_attributes = $this->extract_url_attributes();
			if ( empty( $url_attributes ) ) {
				return $image_url;
			}

			$product_id = (int) get_queried_object_id();
			$product    = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product_Variable ) {
				return $image_url;
			}

			$resolved      = $this->get_resolved_media( $product_id );
			$active_var_id = $this->resolve_active_variation_id( $product, $resolved );

			if ( $active_var_id > 0 && ! empty( $resolved['variations'][ $active_var_id ]['main_image_id'] ) ) {
				$attributed_url = wp_get_attachment_image_url( (int) $resolved['variations'][ $active_var_id ]['main_image_id'], 'full' );
				if ( is_string( $attributed_url ) && '' !== $attributed_url ) {
					return $attributed_url;
				}
			}

			return $image_url;
		}

		/**
		 * Resolve the active variation ID for a variable product on the current request.
		 *
		 * Priority:
		 * 1. Exact in-stock match for URL `?attribute_*` query parameters.
		 * 2. Highest-stock in-stock match for partial URL `?attribute_*` parameters (e.g. `?attribute_pa_color=white`).
		 * 3. Overall highest-stock Winning Variation (including fallback if requested URL color is out of stock).
		 *
		 * @param \WC_Product_Variable      $product  Variable product instance.
		 * @param array<string, mixed>|null $resolved Optional pre-fetched resolved media payload.
		 * @return int Active variation ID (0 if none).
		 */
		public function resolve_active_variation_id( \WC_Product_Variable $product, ?array $resolved = null ): int {
			$product_id = (int) $product->get_id();

			if ( isset( $this->active_variation_cache[ $product_id ] ) ) {
				return $this->active_variation_cache[ $product_id ];
			}

			if ( null === $resolved ) {
				$resolved = $this->get_resolved_media( $product_id );
			}

			if ( empty( $resolved ) || empty( $resolved['variations'] ) ) {
				$winner_id = (int) ( $resolved['winner_variation_id'] ?? 0 );
				$this->active_variation_cache[ $product_id ] = $winner_id;
				return $winner_id;
			}

			$url_attributes = $this->extract_url_attributes();

			// If URL parameters exist, search ranked in-stock variations (already ordered highest-stock first!).
			if ( ! empty( $url_attributes ) ) {
				$best_exact_match    = 0;
				$best_wildcard_match = 0;

				foreach ( (array) $resolved['ranked_variation_ids'] as $var_id ) {
					$var_id = (int) $var_id;
					if ( ! isset( $resolved['variations'][ $var_id ] ) ) {
						continue;
					}

					$var_attrs = (array) $resolved['variations'][ $var_id ]['attributes'];
					$match     = $this->match_variation_against_url_attributes( $var_attrs, $url_attributes );

					if ( 'exact' === $match && 0 === $best_exact_match ) {
						$best_exact_match = $var_id;
						break; // Variations are already ranked highest-stock first, so first exact match wins!
					}

					if ( 'wildcard' === $match && 0 === $best_wildcard_match ) {
						$best_wildcard_match = $var_id;
					}
				}

				if ( $best_exact_match > 0 ) {
					$this->active_variation_cache[ $product_id ] = $best_exact_match;
					return $best_exact_match;
				}

				if ( $best_wildcard_match > 0 ) {
					$this->active_variation_cache[ $product_id ] = $best_wildcard_match;
					return $best_wildcard_match;
				}
			}

			// Default or out-of-stock deep link fallback: return overall stock-winning variation.
			$winner_id = (int) ( $resolved['winner_variation_id'] ?? 0 );
			$this->active_variation_cache[ $product_id ] = $winner_id;
			return $winner_id;
		}

		/**
		 * Build or retrieve the cached 3-Mode Flatsome Archive Hover & Swatch payload for shop cards.
		 *
		 * Deduplicates slides by primary visual attribute slug (e.g., `pa_color`) and image ID so
		 * Black/S and Black/L never produce duplicate Black cards or slideshow frames.
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return array<string, mixed>
		 */
		public function get_archive_hover_config( int $product_id ): array {
			if ( $product_id <= 0 ) {
				return array();
			}

			if ( class_exists( 'WVGS_Cache' ) ) {
				$cached = WVGS_Cache::get_archive_payload( $product_id );
				if ( null !== $cached ) {
					return $cached;
				}
			}

			$resolved = $this->get_resolved_media( $product_id );
			if ( empty( $resolved ) || empty( $resolved['variations'] ) ) {
				return array();
			}

			$global_settings  = WVGS_Plugin::instance()->get_settings();
			$product_settings = $this->get_product_settings( $product_id );

			$hover_mode        = $this->resolve_product_hover_mode( $product_settings, $global_settings );
			$slideshow_delay   = $this->resolve_product_slideshow_delay( $product_settings, $global_settings );
			$archive_attribute = $this->resolve_archive_attribute_key( $product_id, $resolved, $product_settings, $global_settings );
			$base_permalink    = (string) get_permalink( $product_id );

			$slides        = array();
			$seen_attr_val = array();
			$seen_img_ids  = array();

			foreach ( (array) $resolved['ranked_variation_ids'] as $var_id ) {
				$var_id = (int) $var_id;
				if ( ! isset( $resolved['variations'][ $var_id ] ) ) {
					continue;
				}

				$var_data = $resolved['variations'][ $var_id ];
				$attrs    = (array) $var_data['attributes'];
				$img_id   = (int) ( $var_data['main_image_id'] ?? 0 );

				// Determine the primary attribute slug for this variation.
				$attr_slug = '';
				if ( '' !== $archive_attribute && isset( $attrs[ $archive_attribute ] ) && '' !== $attrs[ $archive_attribute ] ) {
					$attr_slug = (string) $attrs[ $archive_attribute ];
				} elseif ( ! empty( $attrs ) ) {
					foreach ( $attrs as $k => $v ) {
						if ( '' !== $v ) {
							$archive_attribute = (string) $k;
							$attr_slug         = (string) $v;
							break;
						}
					}
				}

				if ( '' === $attr_slug ) {
					continue;
				}

				// Deduplicate by primary visual attribute slug (keeps the highest-stock size for each color).
				if ( isset( $seen_attr_val[ $attr_slug ] ) ) {
					continue;
				}
				$seen_attr_val[ $attr_slug ] = true;

				// Resolve responsive shop catalog image markup data.
				$main_src    = $img_id > 0 ? (string) wp_get_attachment_image_url( $img_id, 'woocommerce_thumbnail' ) : '';
				$main_srcset = $img_id > 0 ? (string) wp_get_attachment_image_srcset( $img_id, 'woocommerce_thumbnail' ) : '';
				$main_sizes  = $img_id > 0 ? (string) wp_get_attachment_image_sizes( $img_id, 'woocommerce_thumbnail' ) : '';

				// Resolve hover back-image (first additional gallery image of this same variation).
				$hover_id     = ! empty( $var_data['gallery_ids'][0] ) ? (int) $var_data['gallery_ids'][0] : 0;
				$hover_src    = $hover_id > 0 ? (string) wp_get_attachment_image_url( $hover_id, 'woocommerce_thumbnail' ) : '';
				$hover_srcset = $hover_id > 0 ? (string) wp_get_attachment_image_srcset( $hover_id, 'woocommerce_thumbnail' ) : '';

				// Build deep-link URL attributing this exact winning variation for this color.
				$query_args = array();
				foreach ( $attrs as $attr_key => $attr_val ) {
					if ( '' !== $attr_val ) {
						$query_args[ 'attribute_' . sanitize_title( (string) $attr_key ) ] = $attr_val;
					}
				}
				$variation_url = ! empty( $query_args ) ? add_query_arg( $query_args, $base_permalink ) : $base_permalink;

				// Track whether this image is visually distinct for slideshow cycling.
				$is_distinct_image = ( $img_id > 0 && ! isset( $seen_img_ids[ $img_id ] ) );
				if ( $img_id > 0 ) {
					$seen_img_ids[ $img_id ] = true;
				}

				$slides[] = array(
					'variation_id'      => $var_id,
					'attribute_key'     => $archive_attribute,
					'attribute_slug'    => $attr_slug,
					'image_id'          => $img_id,
					'src'               => $main_src,
					'srcset'            => $main_srcset,
					'sizes'             => $main_sizes,
					'hover_image_id'    => $hover_id,
					'hover_src'         => $hover_src,
					'hover_srcset'      => $hover_srcset,
					'url'               => $variation_url,
					'stock_qty'         => $var_data['stock_qty'],
					'is_distinct_image' => $is_distinct_image,
				);
			}

			$payload = array(
				'product_id'          => $product_id,
				'winner_variation_id' => (int) $resolved['winner_variation_id'],
				'hover_mode'          => $hover_mode,
				'slideshow_delay'     => $slideshow_delay,
				'archive_attribute'   => $archive_attribute,
				'slides'              => $slides,
			);

			if ( class_exists( 'WVGS_Cache' ) ) {
				WVGS_Cache::set_archive_payload( $product_id, $payload );
			}

			return $payload;
		}

		/**
		 * Return the list of in-stock attribute slugs for a specific attribute on a variable product.
		 *
		 * Used by `WVGS_Swatch_Renderer` to completely omit colors/options that have zero stock across all sizes.
		 *
		 * @param int    $product_id Parent variable product ID.
		 * @param string $attribute  Attribute taxonomy or slug (e.g. `pa_color`).
		 * @return array<int, string>
		 */
		public function get_in_stock_attribute_values( int $product_id, string $attribute ): array {
			$resolved = $this->get_resolved_media( $product_id );
			if ( empty( $resolved['in_stock_attributes'] ) ) {
				return array();
			}

			$normalized_key = sanitize_title( $attribute );
			if ( isset( $resolved['in_stock_attributes'][ $normalized_key ] ) ) {
				return (array) $resolved['in_stock_attributes'][ $normalized_key ];
			}

			if ( isset( $resolved['in_stock_attributes'][ $attribute ] ) ) {
				return (array) $resolved['in_stock_attributes'][ $attribute ];
			}

			return array();
		}

		/**
		 * Find the best in-stock variation main image ID for a specific attribute value (used by `auto_image` swatches).
		 *
		 * @param int    $product_id Parent variable product ID.
		 * @param string $attribute  Attribute name/taxonomy.
		 * @param string $term_slug  Attribute value/slug.
		 * @return int Attachment ID or 0 if not found.
		 */
		public function get_auto_swatch_image_id( int $product_id, string $attribute, string $term_slug ): int {
			$resolved = $this->get_resolved_media( $product_id );
			if ( empty( $resolved['variations'] ) ) {
				return 0;
			}

			$normalized_key = sanitize_title( $attribute );

			foreach ( (array) $resolved['ranked_variation_ids'] as $var_id ) {
				$var_id = (int) $var_id;
				if ( ! isset( $resolved['variations'][ $var_id ] ) ) {
					continue;
				}
				$var_data = $resolved['variations'][ $var_id ];
				$attrs    = (array) $var_data['attributes'];
				$val      = $attrs[ $normalized_key ] ?? ( $attrs[ $attribute ] ?? '' );

				if ( $val === $term_slug && ! empty( $var_data['main_image_id'] ) ) {
					return (int) $var_data['main_image_id'];
				}
			}

			return 0;
		}

		/**
		 * AJAX handler for the admin "Recalculate Stock Winner Now" button.
		 *
		 * Verifies both nonce and `edit_post` capability before running recalculation.
		 *
		 * @return void
		 */
		public function ajax_recalculate_stock_winner(): void {
			check_ajax_referer( 'wvgs_admin_nonce', 'nonce' );

			$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
			if ( $product_id <= 0 || ! current_user_can( 'edit_post', $product_id ) ) {
				wp_send_json_error(
					array( 'message' => __( 'You do not have permission to recalculate media for this product.', 'woo-variation-gallery-studio' ) ),
					403
				);
			}

			if ( class_exists( 'WVGS_Cache' ) ) {
				WVGS_Cache::invalidate_product( $product_id, false );
			}

			$payload = $this->recalculate_product_media( $product_id, true );
			if ( null === $payload ) {
				wp_send_json_error(
					array( 'message' => __( 'This product is not a variable product or has no variations.', 'woo-variation-gallery-studio' ) ),
					400
				);
			}

			$thumb_url = ! empty( $payload['main_image_id'] )
				? (string) wp_get_attachment_image_url( (int) $payload['main_image_id'], 'thumbnail' )
				: '';

			wp_send_json_success(
				array(
					'winner_variation_id' => $payload['winner_variation_id'],
					'winner_label'        => $payload['winner_label'],
					'winner_stock_qty'    => $payload['winner_stock_qty'],
					'gallery_count'       => count( (array) $payload['gallery_ids'] ),
					'thumbnail_url'       => $thumb_url,
					'message'             => __( 'Stock-winning main image and gallery recalculated successfully.', 'woo-variation-gallery-studio' ),
				)
			);
		}

		/**
		 * Compute the 3-tier stock priority classification for a single variation.
		 *
		 * - Tier 1: Manage stock enabled and stock_quantity > 0 (not outofstock).
		 * - Tier 2: Manage stock disabled and stock_status === 'instock'.
		 * - Tier 3: stock_status === 'onbackorder' (or backorders allowed with <= 0 stock).
		 * - Tier 4: Out of stock (excluded).
		 *
		 * @param \WC_Product_Variation $variation Variation instance.
		 * @return array{tier: int, stock_qty: int|null, stock_status: string, is_in_stock: bool}
		 */
		private function compute_stock_tier( \WC_Product_Variation $variation ): array {
			$stock_status  = (string) $variation->get_stock_status();
			$managing      = (bool) $variation->managing_stock();
			$stock_qty_raw = $variation->get_stock_quantity();
			$stock_qty     = ( null !== $stock_qty_raw ) ? (int) $stock_qty_raw : null;

			if ( 'outofstock' === $stock_status ) {
				return array(
					'tier'         => 4,
					'stock_qty'    => 0,
					'stock_status' => $stock_status,
					'is_in_stock'  => false,
				);
			}

			if ( $managing ) {
				if ( null !== $stock_qty && $stock_qty > 0 ) {
					return array(
						'tier'         => 1,
						'stock_qty'    => $stock_qty,
						'stock_status' => $stock_status,
						'is_in_stock'  => true,
					);
				}

				if ( $variation->backorders_allowed() || 'onbackorder' === $stock_status ) {
					return array(
						'tier'         => 3,
						'stock_qty'    => $stock_qty ?? 0,
						'stock_status' => 'onbackorder',
						'is_in_stock'  => true,
					);
				}

				return array(
					'tier'         => 4,
					'stock_qty'    => 0,
					'stock_status' => 'outofstock',
					'is_in_stock'  => false,
				);
			}

			if ( 'instock' === $stock_status ) {
				return array(
					'tier'         => 2,
					'stock_qty'    => null,
					'stock_status' => $stock_status,
					'is_in_stock'  => true,
				);
			}

			if ( 'onbackorder' === $stock_status ) {
				return array(
					'tier'         => 3,
					'stock_qty'    => null,
					'stock_status' => $stock_status,
					'is_in_stock'  => true,
				);
			}

			return array(
				'tier'         => 4,
				'stock_qty'    => 0,
				'stock_status' => $stock_status,
				'is_in_stock'  => false,
			);
		}

		/**
		 * Physically sync the winning variation's main image and gallery IDs to the parent product's
		 * `_thumbnail_id` and `_product_image_gallery` post meta for external SQL feeds & search plugins.
		 *
		 * Respects both the global `enable_stock_winner` setting and per-product `lock_parent_image`.
		 *
		 * @param int                  $product_id       Parent variable product ID.
		 * @param array<string, mixed> $payload          Resolved media payload.
		 * @param array<string, mixed> $product_settings Per-product settings.
		 * @return void
		 */
		private function sync_physical_parent_meta( int $product_id, array $payload, array $product_settings ): void {
			$global_settings = WVGS_Plugin::instance()->get_settings();
			if ( empty( $global_settings['enable_stock_winner'] ) || ! empty( $product_settings['lock_parent_image'] ) ) {
				return;
			}

			$main_image_id = (int) ( $payload['main_image_id'] ?? 0 );
			$gallery_ids   = array_map( 'intval', (array) ( $payload['gallery_ids'] ?? array() ) );
			$gallery_csv   = implode( ',', $gallery_ids );

			if ( $main_image_id > 0 ) {
				$current_thumb = (int) get_post_meta( $product_id, '_thumbnail_id', true );
				if ( $current_thumb !== $main_image_id ) {
					update_post_meta( $product_id, '_thumbnail_id', $main_image_id );
				}
			}

			$current_gallery = (string) get_post_meta( $product_id, '_product_image_gallery', true );
			if ( $current_gallery !== $gallery_csv ) {
				update_post_meta( $product_id, '_product_image_gallery', $gallery_csv );
			}

			if ( class_exists( 'WVGS_Cache' ) ) {
				update_post_meta( $product_id, WVGS_Cache::DYNAMIC_FLAG_KEY, '1' );
			}
		}

		/**
		 * Match a variation's attributes against parent product's Attribute-Level Galleries.
		 *
		 * Supports both `pa_color:black` and `color:black` map keys so global taxonomy
		 * and custom local attributes resolve reliably.
		 *
		 * @param \WC_Product_Variation $variation        Variation instance.
		 * @param array<string, mixed>  $product_settings Parent product WVGS settings.
		 * @return array<int, int>
		 */
		private function match_attribute_level_gallery( \WC_Product_Variation $variation, array $product_settings ): array {
			if ( empty( $product_settings['attribute_galleries'] ) || ! is_array( $product_settings['attribute_galleries'] ) ) {
				return array();
			}

			$galleries = $product_settings['attribute_galleries'];
			$attrs     = $this->normalize_variation_attributes( $variation->get_attributes() );

			// Check configured primary visual attribute first so Color gallery takes priority over Size.
			$global_settings = WVGS_Plugin::instance()->get_settings();
			$primary_attr    = ! empty( $product_settings['archive_attribute'] )
				? sanitize_title( (string) $product_settings['archive_attribute'] )
				: sanitize_title( (string) $global_settings['default_archive_attribute'] );

			$ordered_keys = array_keys( $attrs );
			if ( '' !== $primary_attr && in_array( $primary_attr, $ordered_keys, true ) ) {
				$ordered_keys = array_values( array_unique( array_merge( array( $primary_attr ), $ordered_keys ) ) );
			}

			foreach ( $ordered_keys as $attr_key ) {
				$attr_val = (string) ( $attrs[ $attr_key ] ?? '' );
				if ( '' === $attr_val ) {
					continue;
				}

				$candidate_keys = array(
					$attr_key . ':' . $attr_val,
				);

				if ( 0 === strpos( $attr_key, 'pa_' ) ) {
					$candidate_keys[] = substr( $attr_key, 3 ) . ':' . $attr_val;
				} else {
					$candidate_keys[] = 'pa_' . $attr_key . ':' . $attr_val;
				}

				foreach ( $candidate_keys as $map_key ) {
					if ( ! empty( $galleries[ $map_key ] ) ) {
						$ids = $this->sanitize_attachment_id_list( $galleries[ $map_key ] );
						if ( ! empty( $ids ) ) {
							return $ids;
						}
					}
				}
			}

			return array();
		}

		/**
		 * Extract and sanitize `$_GET['attribute_*']` URL query parameters.
		 *
		 * @return array<string, string> Map of normalized attribute keys (without `attribute_` prefix) to values.
		 */
		private function extract_url_attributes(): array {
			$attributes = array();

			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public read-only URL deep-link parameters.
			if ( empty( $_GET ) || ! is_array( $_GET ) ) {
				return $attributes;
			}

			foreach ( $_GET as $raw_key => $raw_val ) {
				$key = sanitize_key( (string) $raw_key );
				if ( 0 !== strpos( $key, 'attribute_' ) || is_array( $raw_val ) ) {
					continue;
				}

				$attr_name = substr( $key, 10 );
				$attr_val  = sanitize_text_field( wp_unslash( (string) $raw_val ) );

				if ( '' !== $attr_name && '' !== $attr_val ) {
					$attributes[ sanitize_title( $attr_name ) ] = $attr_val;
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			return $attributes;
		}

		/**
		 * Compare a variation's normalized attributes against sanitized URL query attributes.
		 *
		 * @param array<string, string> $var_attrs Variation attribute map.
		 * @param array<string, string> $url_attrs URL attribute map.
		 * @return string `'exact'`, `'wildcard'`, or `'none'`.
		 */
		private function match_variation_against_url_attributes( array $var_attrs, array $url_attrs ): string {
			$used_wildcard = false;

			foreach ( $url_attrs as $url_key => $url_val ) {
				$matched_key = null;

				if ( array_key_exists( $url_key, $var_attrs ) ) {
					$matched_key = $url_key;
				} elseif ( 0 === strpos( $url_key, 'pa_' ) && array_key_exists( substr( $url_key, 3 ), $var_attrs ) ) {
					$matched_key = substr( $url_key, 3 );
				} elseif ( array_key_exists( 'pa_' . $url_key, $var_attrs ) ) {
					$matched_key = 'pa_' . $url_key;
				}

				// Ignore unrelated query parameters not belonging to this product's variation attributes.
				if ( null === $matched_key ) {
					continue;
				}

				$var_val = (string) $var_attrs[ $matched_key ];
				if ( '' === $var_val ) {
					// Variation uses "Any ..." wildcard for this attribute.
					$used_wildcard = true;
					continue;
				}

				if ( strtolower( $var_val ) !== strtolower( $url_val ) && sanitize_title( $var_val ) !== sanitize_title( $url_val ) ) {
					return 'none';
				}
			}

			return $used_wildcard ? 'wildcard' : 'exact';
		}

		/**
		 * Determine whether the current request is rendering the Single Product Page
		 * or Flatsome Quick View modal for the given product ID.
		 *
		 * Prevents Related Products / Upsells loops on a Single Product Page from being
		 * misclassified as the main single product.
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return bool
		 */
		private function is_single_product_context( int $product_id ): bool {
			// 1. Main Single Product Page (and NOT currently inside a WooCommerce loop like Related/Upsells).
			if ( is_product() && (int) get_queried_object_id() === $product_id ) {
				$loop_name = function_exists( 'wc_get_loop_prop' ) ? (string) wc_get_loop_prop( 'name' ) : '';
				if ( '' === $loop_name ) {
					return true;
				}
			}

			// 2. Flatsome Quick View AJAX modal.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only AJAX context detection.
			if ( wp_doing_ajax() && isset( $_REQUEST['action'] ) && 'flatsome_quickview' === sanitize_key( wp_unslash( $_REQUEST['action'] ) ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Retrieve normalized per-product settings array from `_wvgs_product_settings`.
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return array<string, mixed>
		 */
		private function get_product_settings( int $product_id ): array {
			$settings = get_post_meta( $product_id, '_wvgs_product_settings', true );
			return is_array( $settings ) ? $settings : array();
		}

		/**
		 * Resolve effective Flatsome Archive Hover Mode (per-product override -> global default).
		 *
		 * @param array<string, mixed> $product_settings Per-product settings.
		 * @param array<string, mixed> $global_settings  Global settings.
		 * @return string `'variation_gallery' | 'next_variation' | 'variation_slideshow'`
		 */
		private function resolve_product_hover_mode( array $product_settings, array $global_settings ): string {
			$allowed = array( 'variation_gallery', 'next_variation', 'variation_slideshow' );
			$local   = isset( $product_settings['archive_hover_mode'] ) ? (string) $product_settings['archive_hover_mode'] : '';

			if ( in_array( $local, $allowed, true ) ) {
				return $local;
			}

			$global = isset( $global_settings['archive_hover_mode'] ) ? (string) $global_settings['archive_hover_mode'] : 'variation_gallery';
			return in_array( $global, $allowed, true ) ? $global : 'variation_gallery';
		}

		/**
		 * Resolve effective archive slideshow delay in milliseconds (bounded 500–10000 ms).
		 *
		 * @param array<string, mixed> $product_settings Per-product settings.
		 * @param array<string, mixed> $global_settings  Global settings.
		 * @return int
		 */
		private function resolve_product_slideshow_delay( array $product_settings, array $global_settings ): int {
			$local = isset( $product_settings['archive_slideshow_delay'] ) ? absint( $product_settings['archive_slideshow_delay'] ) : 0;
			if ( $local >= 500 ) {
				return min( 10000, $local );
			}

			$global = isset( $global_settings['archive_slideshow_delay'] ) ? absint( $global_settings['archive_slideshow_delay'] ) : 1500;
			return min( 10000, max( 500, $global ) );
		}

		/**
		 * Determine which attribute key drives the shop card swatches and slideshow deduplication.
		 *
		 * Priority: Per-product `archive_attribute` -> Global `default_archive_attribute` -> First available attribute on the product.
		 *
		 * @param int                  $product_id       Parent variable product ID.
		 * @param array<string, mixed> $resolved         Resolved media payload.
		 * @param array<string, mixed> $product_settings Per-product settings.
		 * @param array<string, mixed> $global_settings  Global settings.
		 * @return string
		 */
		private function resolve_archive_attribute_key( int $product_id, array $resolved, array $product_settings, array $global_settings ): string {
			$available_keys = array_keys( (array) ( $resolved['in_stock_attributes'] ?? array() ) );
			if ( empty( $available_keys ) ) {
				return '';
			}

			$preferred = array();
			if ( ! empty( $product_settings['archive_attribute'] ) ) {
				$preferred[] = sanitize_title( (string) $product_settings['archive_attribute'] );
			}
			if ( ! empty( $global_settings['default_archive_attribute'] ) ) {
				$preferred[] = sanitize_title( (string) $global_settings['default_archive_attribute'] );
			}

			foreach ( $preferred as $candidate ) {
				if ( in_array( $candidate, $available_keys, true ) ) {
					return $candidate;
				}
				if ( 0 === strpos( $candidate, 'pa_' ) && in_array( substr( $candidate, 3 ), $available_keys, true ) ) {
					return substr( $candidate, 3 );
				}
			}

			return (string) $available_keys[0];
		}

		/**
		 * Normalize a variation's attribute array keys to clean slugs.
		 *
		 * @param array<string, string> $attributes Raw WooCommerce variation attributes.
		 * @return array<string, string>
		 */
		private function normalize_variation_attributes( array $attributes ): array {
			$normalized = array();
			foreach ( $attributes as $key => $val ) {
				$clean_key                = sanitize_title( (string) $key );
				$normalized[ $clean_key ] = (string) $val;
			}
			return $normalized;
		}

		/**
		 * Build a readable variation summary label (e.g. `Black / Large`) for the admin Stock Winner banner.
		 *
		 * @param \WC_Product_Variation $variation Variation instance.
		 * @return string
		 */
		private function build_variation_human_label( \WC_Product_Variation $variation ): string {
			$parts = array();
			foreach ( $variation->get_attributes() as $taxonomy => $slug ) {
				if ( '' === $slug ) {
					continue;
				}
				if ( taxonomy_exists( $taxonomy ) ) {
					$term    = get_term_by( 'slug', $slug, $taxonomy );
					$parts[] = ( $term instanceof \WP_Term ) ? $term->name : $slug;
				} else {
					$parts[] = $slug;
				}
			}
			return ! empty( $parts ) ? implode( ' / ', $parts ) : sprintf( '#%d', $variation->get_id() );
		}

		/**
		 * Sanitize a mixed array or comma-separated string of attachment IDs into valid unique integer IDs.
		 *
		 * @param mixed $raw Raw attachment ID list.
		 * @return array<int, int>
		 */
		private function sanitize_attachment_id_list( $raw ): array {
			if ( is_string( $raw ) ) {
				$raw = array_filter( explode( ',', $raw ) );
			}

			if ( ! is_array( $raw ) ) {
				return array();
			}

			$clean = array();
			foreach ( $raw as $id ) {
				$id = absint( $id );
				if ( $id > 0 && ! in_array( $id, $clean, true ) && $this->is_valid_attachment( $id ) ) {
					$clean[] = $id;
				}
			}

			return $clean;
		}

		/**
		 * Verify an attachment ID points to an existing WordPress attachment post.
		 *
		 * @param int $attachment_id Attachment post ID.
		 * @return bool
		 */
		private function is_valid_attachment( int $attachment_id ): bool {
			return ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) );
		}
	}

endif;
