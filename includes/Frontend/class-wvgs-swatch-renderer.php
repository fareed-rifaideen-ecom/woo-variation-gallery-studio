<?php
/**
 * Replaces default WooCommerce select dropdowns with accessible HTML swatches.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WVGS_Swatch_Renderer {

	public static function init() {
		// Priority 200 to ensure we run after WooCommerce and other plugins
		add_filter( 'woocommerce_dropdown_variation_attribute_options_html', [ __CLASS__, 'render_swatches' ], 200, 2 );
	}

	public static function render_swatches( $html, $args ) {
		$options   = $args['options'];
		$product   = $args['product'];
		$attribute = $args['attribute'];
		$name      = $args['name'] ? $args['name'] : 'attribute_' . sanitize_title( $attribute );
		$id        = $args['id'] ? $args['id'] : sanitize_title( $attribute );
		$class     = $args['class'];
		
		if ( empty( $options ) && ! empty( $product ) && ! empty( $attribute ) ) {
			$attributes = $product->get_variation_attributes();
			$options    = $attributes[ $attribute ];
		}

		if ( empty( $options ) ) {
			return $html;
		}

		$is_taxonomy = taxonomy_exists( $attribute );
		$terms       = [];

		if ( $is_taxonomy ) {
			$terms = wc_get_product_terms( $product->get_id(), $attribute, [ 'fields' => 'all' ] );
		}

		// Keep original select but hide it via CSS class
		$select_html = str_replace( '<select', '<select style="display:none;"', $html );

		ob_start();
		?>
		<div class="wvgs-swatches-wrapper" data-attribute_name="<?php echo esc_attr( $name ); ?>">
			<ul class="wvgs-swatches" role="radiogroup" aria-label="<?php echo esc_attr( wc_attribute_label( $attribute ) ); ?>">
				<?php
				if ( ! empty( $terms ) && $is_taxonomy ) {
					foreach ( $terms as $term ) {
						if ( ! in_array( $term->slug, $options, true ) ) {
							continue;
						}
						
						$swatch_type  = get_term_meta( $term->term_id, '_wvgs_swatch_type', true ) ?: 'button';
						$color        = get_term_meta( $term->term_id, '_wvgs_primary_color', true ) ?: '#cccccc';
						$is_selected  = ( $args['selected'] === $term->slug );
						
						self::render_swatch_item( $term->slug, $term->name, $swatch_type, $color, $is_selected );
					}
				} else {
					// Custom product attributes (non-taxonomy) fallback to buttons
					foreach ( $options as $option ) {
						$is_selected = ( $args['selected'] === $option );
						self::render_swatch_item( $option, $option, 'button', '', $is_selected );
					}
				}
				?>
			</ul>
		</div>
		<?php
		$swatch_html = ob_get_clean();

		return $swatch_html . $select_html;
	}

	private static function render_swatch_item( $value, $label, $type, $color, $is_selected ) {
		$selected_class = $is_selected ? 'selected' : '';
		$aria_checked   = $is_selected ? 'true' : 'false';
		$style_attr     = ( $type === 'wvgs_color' && $color ) ? 'background-color:' . esc_attr( $color ) . ';' : '';
		
		echo '<li class="wvgs-swatch-item wvgs-type-' . esc_attr( $type ) . ' ' . esc_attr( $selected_class ) . '" 
				  role="radio" 
				  aria-checked="' . esc_attr( $aria_checked ) . '" 
				  aria-disabled="false"
				  tabindex="0"
				  data-value="' . esc_attr( $value ) . '"
				  title="' . esc_attr( $label ) . '">';
				  
		if ( $type === 'wvgs_color' ) {
			echo '<span class="wvgs-swatch-inner" style="' . $style_attr . '"></span>';
		} else {
			echo '<span class="wvgs-swatch-inner">' . esc_html( $label ) . '</span>';
		}
		
		echo '</li>';
	}
}

WVGS_Swatch_Renderer::init();
