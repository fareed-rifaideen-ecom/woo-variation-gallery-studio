<?php
/**
 * Global WooCommerce Settings Page ("Variation Studio")
 *
 * Registers the `WooCommerce > Settings > Variation Studio` tab covering all 16 global
 * configuration options across four sections:
 * 1. Dynamic Stock-Winning Media & Gallery Isolation
 * 2. Single Product Page (PDP) Swatches & UX
 * 3. Flatsome Shop Archive & 3-Mode Hover Engine
 * 4. Performance & Uninstall Controls
 *
 * @package WooVariationGalleryStudio\Admin
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WC_Settings_Page', false ) ) {
	return null;
}

if ( ! class_exists( 'WVGS_Admin_Settings', false ) ) :

	/**
	 * Class WVGS_Admin_Settings
	 *
	 * Integrates with WooCommerce's Settings API (`WC_Settings_Page`) using WC 9.0+
	 * section methods (`get_settings_for_default_section`), strict input sanitization,
	 * and automatic WVGS cache invalidation when settings are saved.
	 */
	final class WVGS_Admin_Settings extends \WC_Settings_Page {

		/**
		 * Constructor.
		 */
		public function __construct() {
			$this->id    = 'wvgs_settings';
			$this->label = __( 'Variation Studio', 'woo-variation-gallery-studio' );

			parent::__construct();

			// Sanitize the full `wvgs_settings` array whenever WooCommerce saves options.
			add_filter(
				'woocommerce_admin_settings_sanitize_option_' . WVGS_Plugin::OPTION_KEY,
				array( $this, 'sanitize_settings_option' ),
				10,
				3
			);
		}

		/**
		 * Return the full settings field definitions for the default Variation Studio tab.
		 *
		 * Explicitly passes normalized current values (`'yes'`/`'no'` for checkboxes) so
		 * activation-seeded boolean defaults always render accurately on first visit.
		 *
		 * @return array<int, array<string, mixed>>
		 */
		protected function get_settings_for_default_section(): array {
			$current = WVGS_Plugin::instance()->get_settings( true );
			$opt     = WVGS_Plugin::OPTION_KEY;

			$settings = array(
				// =========================================================================
				// Section 1: Dynamic Stock-Winning Media & Gallery Isolation
				// =========================================================================
				array(
					'title' => __( 'Dynamic Stock-Winning Media & Gallery Isolation', 'woo-variation-gallery-studio' ),
					'type'  => 'title',
					'desc'  => __( 'Automatically promote the highest-stock in-stock variation to the parent Main Image and isolate Single Product Page galleries exclusively to the active variation.', 'woo-variation-gallery-studio' ),
					'id'    => 'wvgs_section_stock_winner',
				),
				array(
					'title'   => __( 'Enable Stock-Winning Media Engine', 'woo-variation-gallery-studio' ),
					'desc'    => __( 'Dynamically use the highest-stock variation\'s main image and gallery for variable products (purges out-of-stock images from shop cards, feeds, and galleries).', 'woo-variation-gallery-studio' ),
					'id'      => $opt . '[enable_stock_winner]',
					'type'    => 'checkbox',
					'default' => 'yes',
					'value'   => ! empty( $current['enable_stock_winner'] ) ? 'yes' : 'no',
				),
				array(
					'title'   => __( 'Auto-Select Stock Winner on Product Page', 'woo-variation-gallery-studio' ),
					'desc'    => __( 'Pre-select the highest-stock variation on direct Single Product Page visits (URL attribution links like ?attribute_pa_color=white automatically override this).', 'woo-variation-gallery-studio' ),
					'id'      => $opt . '[auto_select_stock_winner]',
					'type'    => 'checkbox',
					'default' => 'yes',
					'value'   => ! empty( $current['auto_select_stock_winner'] ) ? 'yes' : 'no',
				),
				array(
					'title'   => __( 'Hide Completely Out-of-Stock Options', 'woo-variation-gallery-studio' ),
					'desc'    => __( 'Omit attribute options (such as a Color) from swatches whenever every variation for that option is out of stock.', 'woo-variation-gallery-studio' ),
					'id'      => $opt . '[hide_out_of_stock_variations]',
					'type'    => 'checkbox',
					'default' => 'yes',
					'value'   => ! empty( $current['hide_out_of_stock_variations'] ) ? 'yes' : 'no',
				),
				array(
					'title'    => __( 'Unavailable Combination Indicator', 'woo-variation-gallery-studio' ),
					'desc_tip' => __( 'How to display unavailable sibling options (e.g., an out-of-stock Size for the currently selected Color) on the Single Product Page.', 'woo-variation-gallery-studio' ),
					'id'       => $opt . '[out_of_stock_behavior]',
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'default'  => 'cross',
					'value'    => (string) $current['out_of_stock_behavior'],
					'options'  => array(
						'cross' => __( 'Cross Out (Diagonal Line + Dimmed)', 'woo-variation-gallery-studio' ),
						'blur'  => __( 'Blur & Dim (No Cross Line)', 'woo-variation-gallery-studio' ),
						'hide'  => __( 'Hide Unavailable Options', 'woo-variation-gallery-studio' ),
					),
				),
				array(
					'title'   => __( 'Sync Flatsome Header Price', 'woo-variation-gallery-studio' ),
					'desc'    => __( 'Update the top product price range (.price-wrapper) to display the exact active variation price when a variation is selected.', 'woo-variation-gallery-studio' ),
					'id'      => $opt . '[sync_header_price]',
					'type'    => 'checkbox',
					'default' => 'yes',
					'value'   => ! empty( $current['sync_header_price'] ) ? 'yes' : 'no',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'wvgs_section_stock_winner',
				),

				// =========================================================================
				// Section 2: Single Product Page (PDP) Swatches & Visual Styling
				// =========================================================================
				array(
					'title' => __( 'Swatches & Visual Styling', 'woo-variation-gallery-studio' ),
					'type'  => 'title',
					'desc'  => __( 'Configure global swatch geometry, fallback conversion for standard dropdowns, and hover tooltips. Individual products can override attribute types under Product Data > Swatches & Gallery.', 'woo-variation-gallery-studio' ),
					'id'    => 'wvgs_section_swatches',
				),
				array(
					'title'    => __( 'Swatch Shape', 'woo-variation-gallery-studio' ),
					'desc_tip' => __( 'Global border-radius style applied to color, image, and button swatches.', 'woo-variation-gallery-studio' ),
					'id'       => $opt . '[swatch_shape]',
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'default'  => 'rounded',
					'value'    => (string) $current['swatch_shape'],
					'options'  => array(
						'rounded' => __( 'Rounded Square (4px)', 'woo-variation-gallery-studio' ),
						'circle'  => __( 'Circle (50%)', 'woo-variation-gallery-studio' ),
						'square'  => __( 'Sharp Square (0px)', 'woo-variation-gallery-studio' ),
					),
				),
				array(
					'title'             => __( 'Swatch Size (px)', 'woo-variation-gallery-studio' ),
					'desc_tip'          => __( 'Width and height in pixels for color and image swatches on Single Product Pages (20 to 64 px).', 'woo-variation-gallery-studio' ),
					'id'                => $opt . '[swatch_size]',
					'type'              => 'number',
					'default'           => 34,
					'value'             => (int) $current['swatch_size'],
					'css'               => 'width: 90px;',
					'custom_attributes' => array(
						'min'  => 20,
						'max'  => 64,
						'step' => 2,
					),
				),
				array(
					'title'    => __( 'Auto-Convert Standard Dropdowns', 'woo-variation-gallery-studio' ),
					'desc_tip' => __( 'Automatically convert attributes left as WooCommerce "Select" (including custom local attributes) into visual swatches.', 'woo-variation-gallery-studio' ),
					'id'       => $opt . '[auto_convert_dropdowns]',
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'default'  => 'button',
					'value'    => (string) $current['auto_convert_dropdowns'],
					'options'  => array(
						'none'       => __( 'Keep Native Dropdown (<select>)', 'woo-variation-gallery-studio' ),
						'button'     => __( 'Auto-Convert to Button / Label Swatches', 'woo-variation-gallery-studio' ),
						'auto_image' => __( 'Auto-Convert to Variation Image Swatches (if variation has image)', 'woo-variation-gallery-studio' ),
					),
				),
				array(
					'title'   => __( 'Enable Swatch Tooltips', 'woo-variation-gallery-studio' ),
					'desc'    => __( 'Display accessible text or image tooltips when hovering or focusing swatch items.', 'woo-variation-gallery-studio' ),
					'id'      => $opt . '[enable_tooltips]',
					'type'    => 'checkbox',
					'default' => 'yes',
					'value'   => ! empty( $current['enable_tooltips'] ) ? 'yes' : 'no',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'wvgs_section_swatches',
				),

				// =========================================================================
				// Section 3: Flatsome Shop Archive & 3-Mode Hover Engine
				// =========================================================================
				array(
					'title' => __( 'Flatsome Shop Archive & 3-Mode Hover Engine', 'woo-variation-gallery-studio' ),
					'type'  => 'title',
					'desc'  => __( 'Control how variable product cards display swatches and swap images on Shop, Category, and Flatsome [ux_products] grids.', 'woo-variation-gallery-studio' ),
					'id'    => 'wvgs_section_archive',
				),
				array(
					'title'   => __( 'Enable Shop Archive Swatches', 'woo-variation-gallery-studio' ),
					'desc'    => __( 'Display compact in-stock swatches on Flatsome product cards with instant image swapping and deep-link URL rewriting.', 'woo-variation-gallery-studio' ),
					'id'      => $opt . '[enable_archive_swatches]',
					'type'    => 'checkbox',
					'default' => 'yes',
					'value'   => ! empty( $current['enable_archive_swatches'] ) ? 'yes' : 'no',
				),
				array(
					'title'    => __( 'Primary Visual Archive Attribute', 'woo-variation-gallery-studio' ),
					'desc_tip' => __( 'The primary attribute (typically Color) displayed on shop cards and used to deduplicate archive hover slides. Falls back to the product\'s first attribute if absent.', 'woo-variation-gallery-studio' ),
					'id'       => $opt . '[default_archive_attribute]',
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'default'  => 'pa_color',
					'value'    => (string) $current['default_archive_attribute'],
					'options'  => $this->get_attribute_taxonomy_options( (string) $current['default_archive_attribute'] ),
				),
				array(
					'title'             => __( 'Max Swatches Per Product Card', 'woo-variation-gallery-studio' ),
					'desc_tip'          => __( 'Maximum number of swatches rendered on a shop card before collapsing remaining options into a "+N" link (1 to 20).', 'woo-variation-gallery-studio' ),
					'id'                => $opt . '[archive_swatch_limit]',
					'type'              => 'number',
					'default'           => 5,
					'value'             => (int) $current['archive_swatch_limit'],
					'css'               => 'width: 90px;',
					'custom_attributes' => array(
						'min'  => 1,
						'max'  => 20,
						'step' => 1,
					),
				),
				array(
					'title'    => __( 'Flatsome Card Hover Mode', 'woo-variation-gallery-studio' ),
					'desc_tip' => __( 'Choose what displays when a shopper hovers over a variable product card image. Can also be overridden per product under Product Data > Swatches & Gallery.', 'woo-variation-gallery-studio' ),
					'id'       => $opt . '[archive_hover_mode]',
					'type'     => 'select',
					'class'    => 'wc-enhanced-select',
					'default'  => 'variation_gallery',
					'value'    => (string) $current['archive_hover_mode'],
					'options'  => array(
						'variation_gallery'   => __( 'Mode 1: Active Variation\'s 2nd Image (Additional Gallery Image)', 'woo-variation-gallery-studio' ),
						'next_variation'      => __( 'Mode 2: Next Available Variation\'s Main Image', 'woo-variation-gallery-studio' ),
						'variation_slideshow' => __( 'Mode 3: Slideshow All Available Variations (Synced with Swatches & URL)', 'woo-variation-gallery-studio' ),
					),
				),
				array(
					'title'             => __( 'Archive Slideshow Delay (ms)', 'woo-variation-gallery-studio' ),
					'desc_tip'          => __( 'Interval in milliseconds between variation frames when Mode 3 (Slideshow) is active (500 to 10000 ms).', 'woo-variation-gallery-studio' ),
					'id'                => $opt . '[archive_slideshow_delay]',
					'type'              => 'number',
					'default'           => 1500,
					'value'             => (int) $current['archive_slideshow_delay'],
					'css'               => 'width: 110px;',
					'custom_attributes' => array(
						'min'  => 500,
						'max'  => 10000,
						'step' => 100,
					),
				),
				array(
					'type' => 'sectionend',
					'id'   => 'wvgs_section_archive',
				),

				// =========================================================================
				// Section 4: Performance & Uninstall Controls
				// =========================================================================
				array(
					'title' => __( 'Performance & Maintenance', 'woo-variation-gallery-studio' ),
					'type'  => 'title',
					'desc'  => __( 'Tune variation payload thresholds for large catalogs and control uninstall cleanup behavior.', 'woo-variation-gallery-studio' ),
					'id'    => 'wvgs_section_performance',
				),
				array(
					'title'             => __( 'AJAX Variation Threshold', 'woo-variation-gallery-studio' ),
					'desc_tip'          => __( 'Maximum number of variations preloaded on Single Product Pages for instant client-side stock cross-out and gallery swapping. WooCommerce default is 30; recommended 120 (max 500).', 'woo-variation-gallery-studio' ),
					'id'                => $opt . '[ajax_variation_threshold]',
					'type'              => 'number',
					'default'           => 120,
					'value'             => (int) $current['ajax_variation_threshold'],
					'css'               => 'width: 110px;',
					'custom_attributes' => array(
						'min'  => 30,
						'max'  => 500,
						'step' => 10,
					),
				),
				array(
					'title'   => __( 'Delete Plugin Data on Uninstall', 'woo-variation-gallery-studio' ),
					'desc'    => __( 'WARNING: Permanently remove plugin options, cached media payloads, and variation gallery/swatch metadata when this plugin is deleted from WordPress. Leave unchecked to keep data safely intact.', 'woo-variation-gallery-studio' ),
					'id'      => $opt . '[delete_data_on_uninstall]',
					'type'    => 'checkbox',
					'default' => 'no',
					'value'   => ! empty( $current['delete_data_on_uninstall'] ) ? 'yes' : 'no',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'wvgs_section_performance',
				),
			);

			/**
			 * Filter the global Variation Studio settings array.
			 *
			 * @param array<int, array<string, mixed>> $settings Settings definitions.
			 */
			return apply_filters( 'wvgs_admin_settings_fields', $settings );
		}

		/**
		 * Save settings with explicit capability enforcement and cache invalidation.
		 *
		 * @return void
		 */
		public function save(): void {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			parent::save();

			WVGS_Plugin::instance()->flush_settings_cache();

			if ( class_exists( 'WVGS_Cache' ) ) {
				WVGS_Cache::bump_cache_salt();
			}
		}

		/**
		 * Sanitize individual option values written into the `wvgs_settings` array.
		 *
		 * @param mixed                $value     Sanitized value from WooCommerce.
		 * @param array<string, mixed> $option    Option field definition.
		 * @param mixed                $raw_value Raw submitted value.
		 * @return mixed
		 */
		public function sanitize_settings_option( $value, array $option, $raw_value ) {
			$field_id = isset( $option['id'] ) ? (string) $option['id'] : '';

			if ( preg_match( '/\[([^\]]+)\]/', $field_id, $matches ) ) {
				$key = $matches[1];
			} else {
				return $value;
			}

			switch ( $key ) {
				case 'enable_stock_winner':
				case 'auto_select_stock_winner':
				case 'hide_out_of_stock_variations':
				case 'sync_header_price':
				case 'enable_tooltips':
				case 'enable_archive_swatches':
				case 'delete_data_on_uninstall':
					return ( 'yes' === $value || true === $value || '1' === (string) $value ) ? 'yes' : 'no';

				case 'out_of_stock_behavior':
					return in_array( $value, array( 'cross', 'blur', 'hide' ), true ) ? $value : 'cross';

				case 'swatch_shape':
					return in_array( $value, array( 'rounded', 'circle', 'square' ), true ) ? $value : 'rounded';

				case 'auto_convert_dropdowns':
					return in_array( $value, array( 'none', 'button', 'auto_image' ), true ) ? $value : 'button';

				case 'archive_hover_mode':
					return in_array( $value, array( 'variation_gallery', 'next_variation', 'variation_slideshow' ), true ) ? $value : 'variation_gallery';

				case 'swatch_size':
					return min( 64, max( 20, absint( $value ) ) );

				case 'archive_swatch_limit':
					return min( 20, max( 1, absint( $value ) ) );

				case 'archive_slideshow_delay':
					return min( 10000, max( 500, absint( $value ) ) );

				case 'ajax_variation_threshold':
					return min( 500, max( 30, absint( $value ) ) );

				case 'default_archive_attribute':
					$clean = sanitize_key( (string) $value );
					return '' !== $clean ? $clean : 'pa_color';

				default:
					return sanitize_text_field( (string) $value );
			}
		}

		/**
		 * Build the select options list of registered global WooCommerce attribute taxonomies.
		 *
		 * Always guarantees that `pa_color` and the currently saved attribute slug exist as choices
		 * even on fresh stores before attributes are created.
		 *
		 * @param string $saved_value Currently saved attribute taxonomy key.
		 * @return array<string, string>
		 */
		private function get_attribute_taxonomy_options( string $saved_value ): array {
			$options = array();

			if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
				$taxonomies = wc_get_attribute_taxonomies();
				if ( is_array( $taxonomies ) ) {
					foreach ( $taxonomies as $tax ) {
						$slug             = 'pa_' . sanitize_title( (string) $tax->attribute_name );
						$label            = ! empty( $tax->attribute_label ) ? (string) $tax->attribute_label : $slug;
						$options[ $slug ] = sprintf( '%s (%s)', $label, $slug );
					}
				}
			}

			if ( ! isset( $options['pa_color'] ) ) {
				$options['pa_color'] = __( 'Color (pa_color)', 'woo-variation-gallery-studio' );
			}

			if ( '' !== $saved_value && ! isset( $options[ $saved_value ] ) ) {
				$options[ $saved_value ] = $saved_value;
			}

			return $options;
		}
	}

endif;

return new WVGS_Admin_Settings();
