<?php
/**
 * Flatsome Shop Archive Swatches & 3-Mode Hover Payload Renderer
 *
 * Responsibilities:
 * 1. Renders compact, accessible in-stock variation swatches inside Flatsome product cards
 *    (`.product-small .box-text` via `woocommerce_after_shop_loop_item`) and via the
 *    optional `[wvgs_archive_swatches]` shortcode for custom UX Builder grids.
 * 2. Shares `WVGS_Swatch_Renderer::resolve_term_visual()` and `render_swatch_visual_inner()`
 *    so archive cards maintain 100% visual parity across all 6 canonical swatch types
 *    (`color`, `dual_color`, `image`, `auto_image`, `button`, `radio`).
 * 3. Reads pre-cached payloads from `WVGS_Stock_Resolver::get_archive_hover_config()` and
 *    memoizes taxonomy term lookups per request to prevent N+1 queries on catalog pages.
 * 4. Emits an inert, XSS-safe `<script type="application/json">` hover payload powering all
 *    3 Flatsome archive hover modes (`variation_gallery`, `next_variation`, `variation_slideshow`)
 *    with configurable slideshow delay, live swatch sync, and attributed deep-link URL rewriting.
 *
 * @package WooVariationGalleryStudio\Frontend
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WVGS_Archive_Loop', false ) ) :

	/**
	 * Class WVGS_Archive_Loop
	 *
	 * Handles Flatsome shop, category, related-products, and `[ux_products]` card swatches
	 * and 3-mode hover data emission.
	 */
	final class WVGS_Archive_Loop {

		/**
		 * Per-request memoization of `get_term_by( 'slug', ... )` lookups keyed by `taxonomy:slug`.
		 *
		 * Eliminates repeated term queries across product cards sharing common colors.
		 *
		 * @var array<string, \WP_Term|null>
		 */
		private static array $term_cache = array();

		/**
		 * Guard preventing duplicate rendering on the same product card within a single loop iteration.
		 *
		 * @var array<int, bool>
		 */
		private static array $rendered_in_loop = array();

		/**
		 * Register WooCommerce/Flatsome archive loop hooks and optional shortcode.
		 *
		 * @return void
		 */
		public static function init(): void {
			/**
			 * Filter the archive hook used to inject swatches onto Flatsome product cards.
			 *
			 * @param string $hook Default WooCommerce/Flatsome card hook.
			 */
			$hook = (string) apply_filters( 'wvgs_archive_swatches_hook', 'woocommerce_after_shop_loop_item' );

			/**
			 * Filter the priority for archive swatch injection.
			 *
			 * @param int $priority Default hook priority.
			 */
			$priority = (int) apply_filters( 'wvgs_archive_swatches_priority', 15 );

			add_action( $hook, array( __CLASS__, 'render_archive_swatches' ), $priority );

			// Reset per-card guard at the start of each shop loop item (supports same product appearing in multiple grids on a page).
			add_action( 'woocommerce_before_shop_loop_item', array( __CLASS__, 'reset_card_render_guard' ), 5 );

			// Register optional shortcode for custom Flatsome UX Builder layouts.
			add_shortcode( 'wvgs_archive_swatches', array( __CLASS__, 'render_shortcode' ) );
		}

		/**
		 * Clear the per-card render guard when a new loop item starts.
		 *
		 * @return void
		 */
		public static function reset_card_render_guard(): void {
			global $product;
			if ( $product instanceof \WC_Product ) {
				unset( self::$rendered_in_loop[ (int) $product->get_id() ] );
			}
		}

		/**
		 * Shortcode callback `[wvgs_archive_swatches product_id="123"]`.
		 *
		 * @param array<string, mixed>|string $atts Shortcode attributes.
		 * @return string
		 */
		public static function render_shortcode( $atts = array() ): string {
			$atts = shortcode_atts(
				array(
					'product_id' => 0,
				),
				is_array( $atts ) ? $atts : array(),
				'wvgs_archive_swatches'
			);

			$product_id = absint( $atts['product_id'] );
			$product    = $product_id > 0 ? wc_get_product( $product_id ) : ( $GLOBALS['product'] ?? null );

			if ( ! $product instanceof \WC_Product_Variable ) {
				return '';
			}

			ob_start();
			self::render_for_product( $product, true );
			return (string) ob_get_clean();
		}

		/**
		 * Hook callback rendering archive swatches and hover config for the current loop product.
		 *
		 * @return void
		 */
		public static function render_archive_swatches(): void {
			global $product;

			if ( ! $product instanceof \WC_Product_Variable ) {
				return;
			}

			$product_id = (int) $product->get_id();
			if ( isset( self::$rendered_in_loop[ $product_id ] ) ) {
				return;
			}

			self::$rendered_in_loop[ $product_id ] = true;
			self::render_for_product( $product, false );
		}

		/**
		 * Core renderer for a single variable product card.
		 *
		 * @param \WC_Product_Variable $product          Variable product instance.
		 * @param bool                 $force_show_bar   Whether shortcode invocation forces swatch bar visibility.
		 * @return void
		 */
		public static function render_for_product( \WC_Product_Variable $product, bool $force_show_bar = false ): void {
			$resolver = WVGS_Plugin::instance()->resolver;
			if ( null === $resolver ) {
				return;
			}

			$global_settings = WVGS_Plugin::instance()->get_settings();
			$show_swatches   = $force_show_bar || ! empty( $global_settings['enable_archive_swatches'] );

			$product_id = (int) $product->get_id();
			$config     = $resolver->get_archive_hover_config( $product_id );

			if ( empty( $config ) || empty( $config['slides'] ) || ! is_array( $config['slides'] ) ) {
				return;
			}

			$hover_mode      = (string) ( $config['hover_mode'] ?? 'variation_gallery' );
			$slideshow_delay = (int) ( $config['slideshow_delay'] ?? 1500 );
			$all_slides      = $config['slides'];

			// If swatches are globally disabled and hover mode does not require JS slideshow orchestration, exit early.
			if ( ! $show_swatches && 'variation_slideshow' !== $hover_mode ) {
				return;
			}

			/**
			 * Minimum number of distinct in-stock options required to render the visible archive swatch bar.
			 *
			 * @param int                  $min_swatches Default minimum (1).
			 * @param \WC_Product_Variable $product      Current variable product.
			 */
			$min_swatches = max( 1, (int) apply_filters( 'wvgs_archive_min_swatches', 1, $product ) );
			$total_slides = count( $all_slides );

			// Encode the hover payload safely for inert `<script type="application/json">` embedding.
			$encoded_config = wp_json_encode(
				$config,
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
			);

			if ( ! is_string( $encoded_config ) ) {
				return;
			}

			$render_visible_bar = $show_swatches && ( $total_slides >= $min_swatches );

			// If swatches are hidden (or below min threshold) but Mode 3 Slideshow is active,
			// output a visually hidden payload wrapper so JS still runs the hover slideshow smoothly.
			if ( ! $render_visible_bar ) {
				if ( 'variation_slideshow' === $hover_mode && $total_slides >= 2 ) {
					printf(
						'<div class="wvgs-archive-swatches-wrapper wvgs-archive-swatches-hidden" data-product-id="%1$s" data-hover-mode="%2$s" data-slideshow-delay="%3$s"><script type="application/json" class="wvgs-archive-hover-payload">%4$s</script></div>',
						esc_attr( (string) $product_id ),
						esc_attr( $hover_mode ),
						esc_attr( (string) $slideshow_delay ),
						$encoded_config // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded with JSON_HEX_* flags inside inert application/json block.
					);
				}
				return;
			}

			$limit           = (int) ( $global_settings['archive_swatch_limit'] ?? 5 );
			$shape           = (string) ( $global_settings['swatch_shape'] ?? 'rounded' );
			$enable_tooltips = ! empty( $global_settings['enable_tooltips'] );

			$visible_slides    = array_slice( $all_slides, 0, $limit );
			$overflow_count    = max( 0, $total_slides - $limit );
			$archive_attribute = (string) ( $config['archive_attribute'] ?? '' );
			$winner_id         = (int) ( $config['winner_variation_id'] ?? 0 );

			$swatch_type   = self::resolve_archive_swatch_type( $product_id, $archive_attribute );
			$attr_label    = '' !== $archive_attribute ? wc_attribute_label( $archive_attribute, $product ) : __( 'Options', 'woo-variation-gallery-studio' );
			$is_taxonomy   = ( '' !== $archive_attribute && taxonomy_exists( $archive_attribute ) );
			$permalink     = (string) get_permalink( $product_id );

			// Determine which visible swatch is initially active (defaults to winner or index 0).
			$selected_index = 0;
			foreach ( $visible_slides as $idx => $slide ) {
				if ( (int) ( $slide['variation_id'] ?? 0 ) === $winner_id ) {
					$selected_index = $idx;
					break;
				}
			}

			$wrapper_classes = array(
				'wvgs-archive-swatches-wrapper',
				'wvgs-shape-' . sanitize_html_class( $shape ),
				'wvgs-archive-type-' . sanitize_html_class( $swatch_type ),
				'wvgs-hover-mode-' . sanitize_html_class( $hover_mode ),
			);
			?>
			<div
				class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>"
				data-product-id="<?php echo esc_attr( (string) $product_id ); ?>"
				data-hover-mode="<?php echo esc_attr( $hover_mode ); ?>"
				data-slideshow-delay="<?php echo esc_attr( (string) $slideshow_delay ); ?>"
			>
				<script type="application/json" class="wvgs-archive-hover-payload"><?php echo $encoded_config; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Encoded with JSON_HEX_* flags inside inert application/json block. ?></script>

				<ul
					class="wvgs-archive-swatches"
					role="radiogroup"
					aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Attribute label e.g. Color. */ __( 'Select %s', 'woo-variation-gallery-studio' ), $attr_label ) ); ?>"
				>
					<?php
					foreach ( $visible_slides as $idx => $slide ) {
						$variation_id = (int) ( $slide['variation_id'] ?? 0 );
						$attr_key     = (string) ( $slide['attribute_key'] ?? $archive_attribute );
						$attr_slug    = (string) ( $slide['attribute_slug'] ?? '' );
						$slide_url    = (string) ( $slide['url'] ?? $permalink );
						$is_selected  = ( $idx === $selected_index );

						$term_id = 0;
						$label   = $attr_slug;

						if ( $is_taxonomy && '' !== $attr_slug ) {
							$term = self::get_cached_term( $attr_key, $attr_slug );
							if ( $term instanceof \WP_Term ) {
								$term_id = (int) $term->term_id;
								$label   = (string) $term->name;
							}
						} elseif ( '' !== $attr_slug ) {
							$label = ucwords( str_replace( array( '-', '_' ), ' ', $attr_slug ) );
						}

						$visual = class_exists( 'WVGS_Swatch_Renderer' )
							? WVGS_Swatch_Renderer::resolve_term_visual(
								$product_id,
								$attr_key,
								$attr_slug,
								$label,
								$swatch_type,
								$term_id
							)
							: array(
								'render_type'       => 'button',
								'primary_color'     => '',
								'is_dual_color'     => false,
								'secondary_color'   => '',
								'dual_angle'        => 45,
								'image_url'         => '',
								'tooltip_type'      => 'text',
								'tooltip_text'      => $label,
								'tooltip_image_url' => '',
							);

						$item_classes = array(
							'wvgs-archive-swatch-item',
							'wvgs-item-type-' . sanitize_html_class( (string) $visual['render_type'] ),
						);

						if ( $is_selected ) {
							$item_classes[] = 'selected';
						}

						if ( $enable_tooltips && 'none' !== (string) $visual['tooltip_type'] ) {
							$item_classes[] = 'wvgs-has-tooltip';
						}
						?>
						<li
							class="<?php echo esc_attr( implode( ' ', $item_classes ) ); ?>"
							role="radio"
							aria-checked="<?php echo $is_selected ? 'true' : 'false'; ?>"
							aria-label="<?php echo esc_attr( $label ); ?>"
							tabindex="<?php echo $is_selected ? '0' : '-1'; ?>"
							data-variation-id="<?php echo esc_attr( (string) $variation_id ); ?>"
							data-attribute-slug="<?php echo esc_attr( $attr_slug ); ?>"
							data-url="<?php echo esc_url( $slide_url ); ?>"
						>
							<?php
							if ( class_exists( 'WVGS_Swatch_Renderer' ) ) {
								echo WVGS_Swatch_Renderer::render_swatch_visual_inner( $visual, $label, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside helper.
							} else {
								echo '<span class="wvgs-swatch-inner wvgs-swatch-button">' . esc_html( $label ) . '</span>';
							}

							if ( $enable_tooltips && 'none' !== (string) $visual['tooltip_type'] ) {
								$tooltip_text = '' !== (string) $visual['tooltip_text'] ? (string) $visual['tooltip_text'] : $label;
								echo '<span class="wvgs-tooltip wvgs-tooltip-text" aria-hidden="true">' . esc_html( $tooltip_text ) . '</span>';
							}
							?>
						</li>
						<?php
					}

					if ( $overflow_count > 0 ) {
						$more_aria_label = sprintf(
							/* translators: 1: Count of additional options, 2: Product title. */
							_n(
								'View %1$d more option for %2$s',
								'View %1$d more options for %2$s',
								$overflow_count,
								'woo-variation-gallery-studio'
							),
							$overflow_count,
							$product->get_name()
						);
						?>
						<li class="wvgs-archive-swatch-more">
							<a href="<?php echo esc_url( $permalink ); ?>" aria-label="<?php echo esc_attr( $more_aria_label ); ?>">
								+<?php echo esc_html( (string) $overflow_count ); ?>
							</a>
						</li>
						<?php
					}
					?>
				</ul>
			</div>
			<?php
		}

		/**
		 * Resolve the swatch rendering type for the archive card's primary visual attribute.
		 *
		 * Never outputs a native `<select>` on a shop card; gracefully falls back to `button`
		 * when an attribute resolves to `select`.
		 *
		 * @param int    $product_id     Parent variable product ID.
		 * @param string $attribute_name Primary archive attribute taxonomy or slug.
		 * @return string Canonical visual swatch type (`color`, `dual_color`, `image`, `auto_image`, `button`, `radio`).
		 */
		private static function resolve_archive_swatch_type( int $product_id, string $attribute_name ): string {
			if ( '' === $attribute_name ) {
				return 'button';
			}

			$resolved_type = class_exists( 'WVGS_Product_Metabox' )
				? WVGS_Product_Metabox::resolve_attribute_type( $product_id, $attribute_name )
				: ( ( taxonomy_exists( $attribute_name ) && class_exists( 'WVGS_Term_Meta' ) )
					? WVGS_Term_Meta::get_attribute_type( $attribute_name )
					: 'button' );

			if ( 'select' === $resolved_type || '' === $resolved_type ) {
				return 'button';
			}

			return $resolved_type;
		}

		/**
		 * Fetch a taxonomy term by slug with per-request memoization.
		 *
		 * @param string $taxonomy Taxonomy slug (e.g. `pa_color`).
		 * @param string $slug     Term slug.
		 * @return \WP_Term|null
		 */
		private static function get_cached_term( string $taxonomy, string $slug ): ?\WP_Term {
			$cache_key = $taxonomy . ':' . $slug;
			if ( array_key_exists( $cache_key, self::$term_cache ) ) {
				return self::$term_cache[ $cache_key ];
			}

			$term                           = get_term_by( 'slug', $slug, $taxonomy );
			self::$term_cache[ $cache_key ] = ( $term instanceof \WP_Term ) ? $term : null;

			return self::$term_cache[ $cache_key ];
		}
	}

	WVGS_Archive_Loop::init();

endif;
