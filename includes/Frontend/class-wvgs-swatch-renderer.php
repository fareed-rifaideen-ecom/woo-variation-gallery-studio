<?php
/**
 * Frontend Accessible Variation Swatch Renderer
 *
 * Responsibilities:
 * 1. Filters `woocommerce_dropdown_variation_attribute_options_args` to omit completely
 *    out-of-stock attribute options (e.g. a Color with 0 stock across all sizes), with
 *    built-in protection for WooCommerce "Any ..." wildcard variations.
 * 2. Intercepts `woocommerce_dropdown_variation_attribute_options_html` (priority 200)
 *    to render accessible HTML swatches (`role="radiogroup"`, `role="radio"`, roving
 *    `tabindex`, `aria-checked`, `aria-disabled`) while keeping the native `<select>`
 *    synchronized and hidden for full WooCommerce plugin compatibility.
 * 3. Supports all 6 canonical swatch types (`color`, `dual_color`, `image`, `auto_image`,
 *    `button`, `radio`), respecting per-product overrides from `WVGS_Product_Metabox` and
 *    global term defaults from `WVGS_Term_Meta`.
 * 4. Renders accessible text and image tooltips when enabled.
 * 5. Exposes public static visual-resolution helpers (`resolve_term_visual`,
 *    `render_swatch_visual_inner`) reused by `WVGS_Archive_Loop` (File 10) for visual parity.
 *
 * @package WooVariationGalleryStudio\Frontend
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WVGS_Swatch_Renderer', false ) ) :

	/**
	 * Class WVGS_Swatch_Renderer
	 *
	 * Renders accessible variation swatches on Single Product Pages and Flatsome Quick View modals.
	 */
	final class WVGS_Swatch_Renderer {

		/**
		 * Register WooCommerce variation dropdown filter hooks.
		 *
		 * @return void
		 */
		public static function init(): void {
			// 1. Prune completely out-of-stock options before WooCommerce builds the <select> and swatches.
			add_filter( 'woocommerce_dropdown_variation_attribute_options_args', array( __CLASS__, 'filter_dropdown_options_args' ), 20 );

			// 2. Replace visible <select> dropdowns with accessible swatches (priority 200 runs after standard filters).
			add_filter( 'woocommerce_dropdown_variation_attribute_options_html', array( __CLASS__, 'render_swatches_html' ), 200, 2 );
		}

		/**
		 * Remove attribute options that have zero stock across every variation when
		 * "Hide Completely Out-of-Stock Options" is enabled.
		 *
		 * Wildcard-safe: if any in-stock variation uses "Any <Attribute>" (`""`), options
		 * for that attribute are preserved so valid wildcard combinations are never hidden.
		 *
		 * @param array<string, mixed> $args Arguments passed to `wc_dropdown_variation_attribute_options()`.
		 * @return array<string, mixed>
		 */
		public static function filter_dropdown_options_args( array $args ): array {
			$product   = $args['product'] ?? null;
			$attribute = isset( $args['attribute'] ) ? (string) $args['attribute'] : '';
			$options   = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();

			if ( ! $product instanceof \WC_Product_Variable || '' === $attribute ) {
				return $args;
			}

			$global_settings = WVGS_Plugin::instance()->get_settings();
			if ( empty( $global_settings['hide_out_of_stock_variations'] ) ) {
				return $args;
			}

			$resolver = WVGS_Plugin::instance()->resolver;
			if ( null === $resolver ) {
				return $args;
			}

			if ( empty( $options ) ) {
				$all_attributes = $product->get_variation_attributes();
				$options        = isset( $all_attributes[ $attribute ] ) && is_array( $all_attributes[ $attribute ] )
					? $all_attributes[ $attribute ]
					: array();
			}

			if ( empty( $options ) ) {
				return $args;
			}

			$product_id = (int) $product->get_id();
			$resolved   = $resolver->get_resolved_media( $product_id );
			if ( empty( $resolved['variations'] ) || ! is_array( $resolved['variations'] ) ) {
				return $args;
			}

			$normalized_attr = sanitize_title( $attribute );

			// Check if any in-stock variation uses a wildcard ("Any ...") for this attribute.
			foreach ( $resolved['variations'] as $var_data ) {
				$var_attrs = isset( $var_data['attributes'] ) && is_array( $var_data['attributes'] ) ? $var_data['attributes'] : array();
				$val       = $var_attrs[ $normalized_attr ] ?? ( $var_attrs[ $attribute ] ?? null );

				if ( '' === $val ) {
					return $args;
				}
			}

			$in_stock_values = $resolver->get_in_stock_attribute_values( $product_id, $attribute );
			if ( empty( $in_stock_values ) ) {
				return $args;
			}

			// Build normalized lookup set supporting both taxonomy slugs and custom local attribute labels.
			$in_stock_lookup = array();
			foreach ( $in_stock_values as $val ) {
				$val_str                                    = (string) $val;
				$in_stock_lookup[ strtolower( $val_str ) ]  = true;
				$in_stock_lookup[ sanitize_title( $val_str ) ] = true;
			}

			$filtered_options = array();
			foreach ( $options as $option ) {
				$opt_str = (string) $option;
				if (
					isset( $in_stock_lookup[ strtolower( $opt_str ) ] ) ||
					isset( $in_stock_lookup[ sanitize_title( $opt_str ) ] )
				) {
					$filtered_options[] = $option;
				}
			}

			// Only replace options if at least one in-stock option remains (all-out-of-stock fallback safety).
			if ( ! empty( $filtered_options ) ) {
				$args['options'] = $filtered_options;
			}

			return $args;
		}

		/**
		 * Render accessible swatches alongside the hidden native WooCommerce `<select>`.
		 *
		 * @param string               $html Original `<select>` HTML.
		 * @param array<string, mixed> $args Arguments passed to `wc_dropdown_variation_attribute_options()`.
		 * @return string
		 */
		public static function render_swatches_html( string $html, array $args ): string {
			$product   = $args['product'] ?? null;
			$attribute = isset( $args['attribute'] ) ? (string) $args['attribute'] : '';
			$options   = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();

			if ( ! $product instanceof \WC_Product_Variable || '' === $attribute ) {
				return $html;
			}

			if ( empty( $options ) ) {
				$all_attributes = $product->get_variation_attributes();
				$options        = isset( $all_attributes[ $attribute ] ) && is_array( $all_attributes[ $attribute ] )
					? $all_attributes[ $attribute ]
					: array();
			}

			if ( empty( $options ) ) {
				return $html;
			}

			$product_id  = (int) $product->get_id();
			$swatch_type = self::resolve_effective_swatch_type( $product_id, $attribute );

			// Honor explicit or global 'select' setting by returning the native dropdown untouched.
			if ( 'select' === $swatch_type ) {
				return $html;
			}

			$global_settings = WVGS_Plugin::instance()->get_settings();
			$shape           = (string) ( $global_settings['swatch_shape'] ?? 'rounded' );
			$oos_behavior    = (string) ( $global_settings['out_of_stock_behavior'] ?? 'cross' );
			$enable_tooltips = ! empty( $global_settings['enable_tooltips'] );

			$select_name = ! empty( $args['name'] )
				? (string) $args['name']
				: 'attribute_' . sanitize_title( $attribute );

			$selected_raw    = isset( $args['selected'] ) ? (string) $args['selected'] : '';
			$attribute_label = wc_attribute_label( $attribute, $product );
			$is_taxonomy     = taxonomy_exists( $attribute );

			// Assemble ordered swatch entries preserving WooCommerce taxonomy term order.
			$items = self::build_ordered_items( $product_id, $attribute, $options, $is_taxonomy );
			if ( empty( $items ) ) {
				return $html;
			}

			// Determine which item receives initial roving tabindex="0" (the selected one, or index 0).
			$selected_index = -1;
			foreach ( $items as $index => $item ) {
				if ( self::is_option_selected( $selected_raw, (string) $item['value'], $is_taxonomy ) ) {
					$selected_index = $index;
					break;
				}
			}

			// Hide the native <select> without removing it from the DOM so WC variation JS operates natively.
			$hidden_select_html = (string) preg_replace(
				'/<select\b/i',
				'<select style="display:none;" data-wvgs-select="1" aria-hidden="true" tabindex="-1"',
				$html,
				1
			);

			$wrapper_classes = array(
				'wvgs-swatches-wrapper',
				'wvgs-type-' . sanitize_html_class( $swatch_type ),
				'wvgs-shape-' . sanitize_html_class( $shape ),
				'wvgs-oos-' . sanitize_html_class( $oos_behavior ),
			);

			ob_start();
			?>
			<div
				class="<?php echo esc_attr( implode( ' ', $wrapper_classes ) ); ?>"
				data-attribute_name="<?php echo esc_attr( $select_name ); ?>"
				data-attribute_key="<?php echo esc_attr( $attribute ); ?>"
				data-attribute_label="<?php echo esc_attr( $attribute_label ); ?>"
				data-swatch_type="<?php echo esc_attr( $swatch_type ); ?>"
			>
				<ul
					class="wvgs-swatches"
					role="radiogroup"
					aria-label="<?php echo esc_attr( $attribute_label ); ?>"
				>
					<?php
					foreach ( $items as $index => $item ) {
						$value       = (string) $item['value'];
						$label       = (string) $item['label'];
						$term_id     = (int) $item['term_id'];
						$is_selected = ( $index === $selected_index );
						$tabindex    = ( $is_selected || ( -1 === $selected_index && 0 === $index ) ) ? '0' : '-1';

						$visual = self::resolve_term_visual(
							$product_id,
							$attribute,
							$value,
							$label,
							$swatch_type,
							$term_id
						);

						$item_classes = array(
							'wvgs-swatch-item',
							'wvgs-item-type-' . sanitize_html_class( $visual['render_type'] ),
						);

						if ( $is_selected ) {
							$item_classes[] = 'selected';
						}

						if ( $enable_tooltips && 'none' !== $visual['tooltip_type'] ) {
							$item_classes[] = 'wvgs-has-tooltip';
						}
						?>
						<li
							class="<?php echo esc_attr( implode( ' ', $item_classes ) ); ?>"
							role="radio"
							aria-checked="<?php echo $is_selected ? 'true' : 'false'; ?>"
							aria-disabled="false"
							aria-label="<?php echo esc_attr( $label ); ?>"
							tabindex="<?php echo esc_attr( $tabindex ); ?>"
							data-value="<?php echo esc_attr( $value ); ?>"
							data-label="<?php echo esc_attr( $label ); ?>"
						>
							<?php
							// Output visual swatch inner element (color, dual_color, image, button, or radio).
							echo self::render_swatch_visual_inner( $visual, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside helper.

							// Output accessible tooltip if globally enabled and not explicitly disabled on term.
							if ( $enable_tooltips && 'none' !== $visual['tooltip_type'] ) {
								echo self::render_tooltip_html( $visual, $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside helper.
							}
							?>
						</li>
						<?php
					}
					?>
				</ul>
			</div>
			<?php
			$swatches_html = (string) ob_get_clean();

			/**
			 * Filter the final rendered swatch + hidden select HTML for a variation attribute.
			 *
			 * @param string               $output    Combined swatches and hidden select markup.
			 * @param array<string, mixed> $args      Original WooCommerce dropdown arguments.
			 * @param string               $swatch_type Effective swatch type used.
			 */
			return (string) apply_filters( 'wvgs_variation_attribute_swatches_html', $swatches_html . $hidden_select_html, $args, $swatch_type );
		}

		/**
		 * Resolve the final visual & tooltip representation for a single attribute term/value.
		 *
		 * Public static helper shared between `WVGS_Swatch_Renderer` (PDP/Quick View) and
		 * `WVGS_Archive_Loop` (File 10) so swatches match across shop cards and product pages.
		 *
		 * Resolution cascade:
		 * 1. Product-level term override (`WVGS_Product_Metabox::get_term_override`).
		 * 2. Global taxonomy term meta (`WVGS_Term_Meta::get_term_swatch_data`).
		 * 3. Variation auto-image (`WVGS_Stock_Resolver::get_auto_swatch_image_id`) when type is `auto_image`.
		 * 4. Graceful fallback to `button` if an `image`/`auto_image` swatch has no image assigned.
		 *
		 * @param int    $product_id  Parent variable product ID.
		 * @param string $attribute   Attribute taxonomy (`pa_color`) or custom name.
		 * @param string $value       Option value / term slug.
		 * @param string $label       Human-readable display label.
		 * @param string $swatch_type Effective attribute swatch type.
		 * @param int    $term_id     Taxonomy term ID (0 for custom local attributes).
		 * @return array{
		 *     render_type: string,
		 *     primary_color: string,
		 *     is_dual_color: bool,
		 *     secondary_color: string,
		 *     dual_angle: int,
		 *     image_url: string,
		 *     tooltip_type: string,
		 *     tooltip_text: string,
		 *     tooltip_image_url: string
		 * }
		 */
		public static function resolve_term_visual(
			int $product_id,
			string $attribute,
			string $value,
			string $label,
			string $swatch_type,
			int $term_id = 0
		): array {
			$global_term = ( $term_id > 0 && class_exists( 'WVGS_Term_Meta' ) )
				? WVGS_Term_Meta::get_term_swatch_data( $term_id )
				: array(
					'primary_color'    => '',
					'is_dual_color'    => false,
					'secondary_color'  => '',
					'dual_angle'       => 45,
					'image_id'         => 0,
					'tooltip_type'     => 'text',
					'tooltip_text'     => '',
					'tooltip_image_id' => 0,
				);

			$override = class_exists( 'WVGS_Product_Metabox' )
				? WVGS_Product_Metabox::get_term_override( $product_id, $attribute, $value )
				: array();

			$primary_color = ( ! empty( $override['primary_color'] ) )
				? (string) $override['primary_color']
				: (string) ( $global_term['primary_color'] ?? '' );

			$is_dual_color = isset( $override['is_dual_color'] )
				? ( 'yes' === $override['is_dual_color'] || true === $override['is_dual_color'] )
				: ! empty( $global_term['is_dual_color'] );

			$secondary_color = ( ! empty( $override['secondary_color'] ) )
				? (string) $override['secondary_color']
				: (string) ( $global_term['secondary_color'] ?? '' );

			$dual_angle = isset( $override['dual_angle'] )
				? (int) $override['dual_angle']
				: (int) ( $global_term['dual_angle'] ?? 45 );

			$image_id = ! empty( $override['image_id'] )
				? absint( $override['image_id'] )
				: absint( $global_term['image_id'] ?? 0 );

			$image_url   = '';
			$render_type = $swatch_type;

			// Resolve image URL for `auto_image` and `image` types.
			if ( 'auto_image' === $swatch_type ) {
				$auto_img_id = 0;
				if ( null !== WVGS_Plugin::instance()->resolver ) {
					$auto_img_id = WVGS_Plugin::instance()->resolver->get_auto_swatch_image_id( $product_id, $attribute, $value );
				}
				$resolved_img_id = $auto_img_id > 0 ? $auto_img_id : $image_id;
				if ( $resolved_img_id > 0 ) {
					$image_url   = (string) wp_get_attachment_image_url( $resolved_img_id, 'woocommerce_gallery_thumbnail' );
					$render_type = '' !== $image_url ? 'image' : 'button';
				} else {
					$render_type = 'button';
				}
			} elseif ( 'image' === $swatch_type ) {
				if ( $image_id > 0 ) {
					$image_url = (string) wp_get_attachment_image_url( $image_id, 'thumbnail' );
				}
				if ( '' === $image_url && null !== WVGS_Plugin::instance()->resolver ) {
					// Fallback to matching variation image if term swatch image wasn't uploaded yet.
					$fallback_id = WVGS_Plugin::instance()->resolver->get_auto_swatch_image_id( $product_id, $attribute, $value );
					if ( $fallback_id > 0 ) {
						$image_url = (string) wp_get_attachment_image_url( $fallback_id, 'woocommerce_gallery_thumbnail' );
					}
				}
				if ( '' === $image_url ) {
					$render_type = 'button';
				}
			} elseif ( in_array( $swatch_type, array( 'color', 'dual_color' ), true ) ) {
				if ( ( $is_dual_color || 'dual_color' === $swatch_type ) && '' !== $secondary_color ) {
					$render_type = 'dual_color';
				} elseif ( '' !== $primary_color ) {
					$render_type = 'color';
				} else {
					// Graceful fallback when a color term hasn't been assigned a hex code yet.
					$render_type = 'button';
				}
			}

			// Resolve tooltip data.
			$tooltip_type      = (string) ( $global_term['tooltip_type'] ?? 'text' );
			$tooltip_text      = '' !== (string) ( $global_term['tooltip_text'] ?? '' ) ? (string) $global_term['tooltip_text'] : $label;
			$tooltip_image_id  = absint( $global_term['tooltip_image_id'] ?? 0 );
			$tooltip_image_url = ( 'image' === $tooltip_type && $tooltip_image_id > 0 )
				? (string) wp_get_attachment_image_url( $tooltip_image_id, 'thumbnail' )
				: '';

			if ( 'image' === $tooltip_type && '' === $tooltip_image_url ) {
				$tooltip_type = 'text';
			}

			return array(
				'render_type'       => $render_type,
				'primary_color'     => sanitize_hex_color( $primary_color ) ?: '',
				'is_dual_color'     => $is_dual_color,
				'secondary_color'   => sanitize_hex_color( $secondary_color ) ?: '',
				'dual_angle'        => min( 360, max( 0, $dual_angle ) ),
				'image_url'         => $image_url,
				'tooltip_type'      => $tooltip_type,
				'tooltip_text'      => $tooltip_text,
				'tooltip_image_url' => $tooltip_image_url,
			);
		}

		/**
		 * Render the inner HTML element for a swatch item based on its resolved visual payload.
		 *
		 * All attributes and text nodes are strictly escaped for safe direct output.
		 *
		 * @param array<string, mixed> $visual Resolved visual array from `resolve_term_visual()`.
		 * @param string               $label  Human-readable option label.
		 * @param bool                 $compact Whether rendering in compact shop archive card mode.
		 * @return string Escaped HTML string.
		 */
		public static function render_swatch_visual_inner( array $visual, string $label, bool $compact = false ): string {
			$render_type = (string) ( $visual['render_type'] ?? 'button' );

			if ( 'dual_color' === $render_type ) {
				$gradient = class_exists( 'WVGS_Term_Meta' )
					? WVGS_Term_Meta::get_dual_color_css(
						(string) $visual['primary_color'],
						(string) $visual['secondary_color'],
						(int) $visual['dual_angle']
					)
					: sprintf(
						'linear-gradient(%ddeg, %s 50%%, %s 50%%)',
						(int) $visual['dual_angle'],
						sanitize_hex_color( (string) $visual['primary_color'] ) ?: '#cccccc',
						sanitize_hex_color( (string) $visual['secondary_color'] ) ?: '#333333'
					);

				return sprintf(
					'<span class="wvgs-swatch-inner wvgs-swatch-color wvgs-swatch-dual-color" style="background:%s;"></span>',
					esc_attr( $gradient )
				);
			}

			if ( 'color' === $render_type ) {
				$hex = sanitize_hex_color( (string) $visual['primary_color'] ) ?: '#cccccc';
				return sprintf(
					'<span class="wvgs-swatch-inner wvgs-swatch-color" style="background-color:%s;"></span>',
					esc_attr( $hex )
				);
			}

			if ( 'image' === $render_type && ! empty( $visual['image_url'] ) ) {
				return sprintf(
					'<span class="wvgs-swatch-inner wvgs-swatch-image"><img src="%s" alt="%s" loading="lazy" decoding="async" /></span>',
					esc_url( (string) $visual['image_url'] ),
					esc_attr( $label )
				);
			}

			if ( 'radio' === $render_type && ! $compact ) {
				return sprintf(
					'<span class="wvgs-swatch-inner wvgs-swatch-radio"><span class="wvgs-radio-dot" aria-hidden="true"></span><span class="wvgs-radio-label">%s</span></span>',
					esc_html( $label )
				);
			}

			// Default `button` / label swatch (abbreviated cleanly if compact archive swatch exceeds length).
			$display_label = $compact && function_exists( 'mb_strimwidth' )
				? mb_strimwidth( $label, 0, 8, '' )
				: $label;

			return sprintf(
				'<span class="wvgs-swatch-inner wvgs-swatch-button">%s</span>',
				esc_html( $display_label )
			);
		}

		/**
		 * Render accessible tooltip markup (`aria-hidden="true"`, since parent carries `aria-label`).
		 *
		 * @param array<string, mixed> $visual Resolved visual array.
		 * @param string               $label  Option label.
		 * @return string Escaped tooltip HTML.
		 */
		private static function render_tooltip_html( array $visual, string $label ): string {
			$tooltip_type = (string) ( $visual['tooltip_type'] ?? 'text' );
			$tooltip_text = (string) ( $visual['tooltip_text'] ?? $label );

			if ( 'image' === $tooltip_type && ! empty( $visual['tooltip_image_url'] ) ) {
				return sprintf(
					'<span class="wvgs-tooltip wvgs-tooltip-image" aria-hidden="true"><img src="%s" alt="" loading="lazy" /><span class="wvgs-tooltip-caption">%s</span></span>',
					esc_url( (string) $visual['tooltip_image_url'] ),
					esc_html( $tooltip_text )
				);
			}

			return sprintf(
				'<span class="wvgs-tooltip wvgs-tooltip-text" aria-hidden="true">%s</span>',
				esc_html( $tooltip_text )
			);
		}

		/**
		 * Resolve the effective swatch type for an attribute on a variable product.
		 *
		 * Delegates to `WVGS_Product_Metabox::resolve_attribute_type()` when available,
		 * with safe fallback to global settings if the admin metabox class is absent.
		 *
		 * @param int    $product_id Parent variable product ID.
		 * @param string $attribute  Attribute taxonomy or custom name.
		 * @return string
		 */
		private static function resolve_effective_swatch_type( int $product_id, string $attribute ): string {
			if ( class_exists( 'WVGS_Product_Metabox' ) ) {
				return WVGS_Product_Metabox::resolve_attribute_type( $product_id, $attribute );
			}

			$global_type = ( taxonomy_exists( $attribute ) && class_exists( 'WVGS_Term_Meta' ) )
				? WVGS_Term_Meta::get_attribute_type( $attribute )
				: 'select';

			if ( 'select' !== $global_type ) {
				return $global_type;
			}

			$settings     = WVGS_Plugin::instance()->get_settings();
			$auto_convert = (string) ( $settings['auto_convert_dropdowns'] ?? 'button' );

			return in_array( $auto_convert, array( 'button', 'auto_image' ), true ) ? $auto_convert : 'select';
		}

		/**
		 * Build an ordered array of swatch items preserving WooCommerce attribute term order.
		 *
		 * @param int                $product_id  Parent variable product ID.
		 * @param string             $attribute   Attribute taxonomy or custom name.
		 * @param array<int, string> $options     Available option values/slugs.
		 * @param bool               $is_taxonomy Whether the attribute is a registered global taxonomy.
		 * @return array<int, array{value: string, label: string, term_id: int}>
		 */
		private static function build_ordered_items( int $product_id, string $attribute, array $options, bool $is_taxonomy ): array {
			$items = array();

			if ( $is_taxonomy ) {
				$terms        = wc_get_product_terms( $product_id, $attribute, array( 'fields' => 'all' ) );
				$matched_slugs = array();

				if ( is_array( $terms ) && ! is_wp_error( $terms ) ) {
					foreach ( $terms as $term ) {
						if ( ! $term instanceof \WP_Term || ! in_array( $term->slug, $options, true ) ) {
							continue;
						}

						$items[]                       = array(
							'value'   => (string) $term->slug,
							'label'   => (string) apply_filters( 'woocommerce_variation_option_name', $term->name, $term, $attribute, wc_get_product( $product_id ) ),
							'term_id' => (int) $term->term_id,
						);
						$matched_slugs[ $term->slug ] = true;
					}
				}

				// Fallback for any option slug not returned by term query.
				foreach ( $options as $opt ) {
					$opt_str = (string) $opt;
					if ( ! isset( $matched_slugs[ $opt_str ] ) ) {
						$items[] = array(
							'value'   => $opt_str,
							'label'   => $opt_str,
							'term_id' => 0,
						);
					}
				}

				return $items;
			}

			// Custom local product attributes preserve exact product option order.
			$product = wc_get_product( $product_id );
			foreach ( $options as $opt ) {
				$opt_str = (string) $opt;
				$items[] = array(
					'value'   => $opt_str,
					'label'   => (string) apply_filters( 'woocommerce_variation_option_name', $opt_str, null, $attribute, $product ),
					'term_id' => 0,
				);
			}

			return $items;
		}

		/**
		 * Replicate WooCommerce core's exact comparison logic to determine whether an option is selected.
		 *
		 * @param string $selected    Currently selected attribute value from WooCommerce/URL/Stock Winner.
		 * @param string $option      Option value or term slug.
		 * @param bool   $is_taxonomy Whether this is a global taxonomy attribute.
		 * @return bool
		 */
		private static function is_option_selected( string $selected, string $option, bool $is_taxonomy ): bool {
			if ( '' === $selected ) {
				return false;
			}

			if ( $is_taxonomy ) {
				return sanitize_title( $selected ) === sanitize_title( $option );
			}

			return ( sanitize_title( $selected ) === $selected )
				? ( $selected === sanitize_title( $option ) )
				: ( $selected === $option );
		}
	}

	WVGS_Swatch_Renderer::init();

endif;
