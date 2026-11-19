<?php
/**
 * Global Attribute Types & Term Meta Manager
 *
 * Responsibilities:
 * 1. Registers canonical swatch types (`color`, `dual_color`, `image`, `auto_image`, `button`, `radio`)
 *    under `Products > Attributes`.
 * 2. Hooks into `woocommerce_product_option_terms` so WooCommerce renders the term multi-select picker
 *    inside `Product Data > Attributes` for all custom attribute types.
 * 3. Renders Color, Dual-Color (with angle), Image, and Tooltip (text/image) fields on Add/Edit Term screens.
 * 4. Adds a live swatch Preview column to the attribute terms list table.
 * 5. Provides reusable static helpers with legacy `woo-variation-swatches` term meta fallback reads.
 *
 * @package WooVariationGalleryStudio\Admin
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WVGS_Term_Meta', false ) ) :

	/**
	 * Class WVGS_Term_Meta
	 *
	 * Manages global WooCommerce attribute taxonomy types and per-term swatch metadata.
	 */
	final class WVGS_Term_Meta {

		/**
		 * Per-request cache mapping attribute taxonomy slug (e.g. `pa_color`) to registered type.
		 *
		 * @var array<string, string>|null
		 */
		private static ?array $attribute_types_cache = null;

		/**
		 * Initialize admin and frontend hooks.
		 *
		 * @return void
		 */
		public static function init(): void {
			// 1. Register custom swatch types in WooCommerce's Attribute Type dropdown.
			add_filter( 'product_attributes_type_selector', array( __CLASS__, 'register_attribute_types' ) );

			// 2. Render term selector dropdown inside Product Data > Attributes for custom attribute types.
			add_action( 'woocommerce_product_option_terms', array( __CLASS__, 'render_product_option_terms' ), 10, 3 );

			// 3. Attach Add/Edit term form fields and Preview table columns to all `pa_*` taxonomies.
			add_action( 'admin_init', array( __CLASS__, 'register_taxonomy_hooks' ), 20 );

			// 4. Persist term metadata securely on term creation and update.
			add_action( 'created_term', array( __CLASS__, 'save_term_meta' ), 10, 3 );
			add_action( 'edit_term', array( __CLASS__, 'save_term_meta' ), 10, 3 );

			// 5. Clear attribute type memoization when global attributes are added/updated/deleted.
			add_action( 'woocommerce_attribute_added', array( __CLASS__, 'flush_attribute_types_cache' ) );
			add_action( 'woocommerce_attribute_updated', array( __CLASS__, 'flush_attribute_types_cache' ) );
			add_action( 'woocommerce_attribute_deleted', array( __CLASS__, 'flush_attribute_types_cache' ) );
		}

		/**
		 * Add canonical WVGS swatch types to WooCommerce's Attribute Type selector.
		 *
		 * @param array<string, string> $types Existing WooCommerce attribute types.
		 * @return array<string, string>
		 */
		public static function register_attribute_types( array $types ): array {
			$custom_types = array(
				'color'      => __( 'Color Swatch', 'woo-variation-gallery-studio' ),
				'dual_color' => __( 'Dual Color Swatch', 'woo-variation-gallery-studio' ),
				'image'      => __( 'Image Swatch', 'woo-variation-gallery-studio' ),
				'auto_image' => __( 'Auto Variation Image', 'woo-variation-gallery-studio' ),
				'button'     => __( 'Button / Label', 'woo-variation-gallery-studio' ),
				'radio'      => __( 'Radio', 'woo-variation-gallery-studio' ),
			);

			return array_merge( $types, $custom_types );
		}

		/**
		 * Render WooCommerce's native term selector UI inside `Product Data > Attributes`
		 * for custom attribute types.
		 *
		 * WooCommerce core only renders this `<select>` automatically for type `'select'`.
		 * Without this hook, switching an attribute to `color`, `image`, `button`, etc. would
		 * hide the term picker on the Product Edit screen.
		 *
		 * @param object                $attribute_taxonomy Attribute taxonomy object.
		 * @param int                   $i                  Row index in Product Data > Attributes.
		 * @param \WC_Product_Attribute $attribute          Product attribute object.
		 * @return void
		 */
		public static function render_product_option_terms( $attribute_taxonomy, int $i, $attribute ): void {
			$type = isset( $attribute_taxonomy->attribute_type ) ? (string) $attribute_taxonomy->attribute_type : '';

			if ( 'select' === $type || ! in_array( $type, WVGS_Plugin::SWATCH_TYPES, true ) ) {
				return;
			}

			$taxonomy_name = wc_attribute_taxonomy_name( (string) $attribute_taxonomy->attribute_name );
			$all_terms     = get_terms(
				array(
					'taxonomy'   => $taxonomy_name,
					'orderby'    => apply_filters( 'woocommerce_product_attribute_terms_orderby', 'name' ),
					'hide_empty' => false,
				)
			);

			$selected_ids = ( $attribute instanceof \WC_Product_Attribute ) ? $attribute->get_options() : array();
			?>
			<select
				multiple="multiple"
				data-return_id="id"
				data-placeholder="<?php esc_attr_e( 'Select terms', 'woo-variation-gallery-studio' ); ?>"
				class="multiselect attribute_values wc-enhanced-select"
				name="attribute_values[<?php echo esc_attr( (string) $i ); ?>][]"
			>
				<?php
				if ( is_array( $all_terms ) && ! is_wp_error( $all_terms ) ) {
					foreach ( $all_terms as $term ) {
						$is_selected = in_array( (int) $term->term_id, array_map( 'intval', (array) $selected_ids ), true );
						printf(
							'<option value="%d"%s>%s</option>',
							(int) $term->term_id,
							selected( $is_selected, true, false ),
							esc_html( apply_filters( 'woocommerce_product_attribute_term_name', $term->name, $term ) )
						);
					}
				}
				?>
			</select>
			<button class="button plus select_all_attributes"><?php esc_html_e( 'Select all', 'woo-variation-gallery-studio' ); ?></button>
			<button class="button minus select_no_attributes"><?php esc_html_e( 'Select none', 'woo-variation-gallery-studio' ); ?></button>
			<button class="button fr plus add_new_attribute"><?php esc_html_e( 'Create value', 'woo-variation-gallery-studio' ); ?></button>
			<?php
		}

		/**
		 * Attach Add/Edit form field hooks and Preview column hooks to every registered `pa_*` taxonomy.
		 *
		 * @return void
		 */
		public static function register_taxonomy_hooks(): void {
			if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
				return;
			}

			$taxonomies = wc_get_attribute_taxonomies();
			if ( empty( $taxonomies ) || ! is_array( $taxonomies ) ) {
				return;
			}

			foreach ( $taxonomies as $tax ) {
				$taxonomy_name = wc_attribute_taxonomy_name( (string) $tax->attribute_name );

				add_action( "{$taxonomy_name}_add_form_fields", array( __CLASS__, 'render_add_term_fields' ), 10, 1 );
				add_action( "{$taxonomy_name}_edit_form_fields", array( __CLASS__, 'render_edit_term_fields' ), 10, 2 );

				add_filter( "manage_edit-{$taxonomy_name}_columns", array( __CLASS__, 'add_preview_column' ) );
				add_filter( "manage_{$taxonomy_name}_custom_column", array( __CLASS__, 'render_preview_column' ), 10, 3 );
			}
		}

		/**
		 * Render swatch & tooltip fields on the "Add New Term" screen.
		 *
		 * @param string $taxonomy Attribute taxonomy slug (e.g. `pa_color`).
		 * @return void
		 */
		public static function render_add_term_fields( string $taxonomy ): void {
			$attr_type = self::get_attribute_type( $taxonomy );
			wp_nonce_field( 'wvgs_save_term_meta', 'wvgs_term_meta_nonce' );

			if ( in_array( $attr_type, array( 'color', 'dual_color' ), true ) ) {
				$default_dual = ( 'dual_color' === $attr_type );
				?>
				<div class="form-field wvgs-term-field wvgs-term-color-wrap">
					<label for="wvgs_primary_color"><?php esc_html_e( 'Primary Swatch Color', 'woo-variation-gallery-studio' ); ?></label>
					<input type="text" name="wvgs_primary_color" id="wvgs_primary_color" value="" class="wvgs-color-picker" />
					<p class="description"><?php esc_html_e( 'Choose the main hex color for this attribute term.', 'woo-variation-gallery-studio' ); ?></p>
				</div>

				<div class="form-field wvgs-term-field wvgs-term-dual-toggle-wrap">
					<label for="wvgs_is_dual_color">
						<input type="checkbox" name="wvgs_is_dual_color" id="wvgs_is_dual_color" value="yes" <?php checked( $default_dual, true ); ?> />
						<?php esc_html_e( 'Enable Dual Color Split', 'woo-variation-gallery-studio' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Display a two-tone split swatch using Primary and Secondary colors.', 'woo-variation-gallery-studio' ); ?></p>
				</div>

				<div class="form-field wvgs-term-field wvgs-term-secondary-color-wrap" <?php echo $default_dual ? '' : 'style="display:none;"'; ?>>
					<label for="wvgs_secondary_color"><?php esc_html_e( 'Secondary Swatch Color', 'woo-variation-gallery-studio' ); ?></label>
					<input type="text" name="wvgs_secondary_color" id="wvgs_secondary_color" value="" class="wvgs-color-picker" />
				</div>

				<div class="form-field wvgs-term-field wvgs-term-dual-angle-wrap" <?php echo $default_dual ? '' : 'style="display:none;"'; ?>>
					<label for="wvgs_dual_angle"><?php esc_html_e( 'Dual Color Split Angle (deg)', 'woo-variation-gallery-studio' ); ?></label>
					<input type="number" name="wvgs_dual_angle" id="wvgs_dual_angle" value="45" min="0" max="360" step="15" style="width:90px;" />
					<p class="description"><?php esc_html_e( 'Split angle in degrees (45 = diagonal, 90 = vertical, 180 = horizontal).', 'woo-variation-gallery-studio' ); ?></p>
				</div>
				<?php
			}

			if ( 'image' === $attr_type ) {
				?>
				<div class="form-field wvgs-term-field wvgs-term-image-wrap">
					<label><?php esc_html_e( 'Swatch Image', 'woo-variation-gallery-studio' ); ?></label>
					<div class="wvgs-term-image-preview"></div>
					<input type="hidden" name="wvgs_image_id" class="wvgs-media-id-input" value="" />
					<button type="button" class="button wvgs-upload-image-btn"><?php esc_html_e( 'Upload / Select Image', 'woo-variation-gallery-studio' ); ?></button>
					<button type="button" class="button wvgs-remove-image-btn" style="display:none;"><?php esc_html_e( 'Remove Image', 'woo-variation-gallery-studio' ); ?></button>
					<p class="description"><?php esc_html_e( 'Recommended square texture or color sample thumbnail (100x100 px).', 'woo-variation-gallery-studio' ); ?></p>
				</div>
				<?php
			}
			?>

			<div class="form-field wvgs-term-field wvgs-term-tooltip-type-wrap">
				<label for="wvgs_tooltip_type"><?php esc_html_e( 'Tooltip Type', 'woo-variation-gallery-studio' ); ?></label>
				<select name="wvgs_tooltip_type" id="wvgs_tooltip_type" class="wvgs-tooltip-type-select">
					<option value="text"><?php esc_html_e( 'Text Tooltip (Default)', 'woo-variation-gallery-studio' ); ?></option>
					<option value="image"><?php esc_html_e( 'Image Tooltip', 'woo-variation-gallery-studio' ); ?></option>
					<option value="none"><?php esc_html_e( 'No Tooltip', 'woo-variation-gallery-studio' ); ?></option>
				</select>
			</div>

			<div class="form-field wvgs-term-field wvgs-term-tooltip-text-wrap">
				<label for="wvgs_tooltip_text"><?php esc_html_e( 'Custom Tooltip Text', 'woo-variation-gallery-studio' ); ?></label>
				<input type="text" name="wvgs_tooltip_text" id="wvgs_tooltip_text" value="" />
				<p class="description"><?php esc_html_e( 'Optional override. Leave blank to use the term name automatically.', 'woo-variation-gallery-studio' ); ?></p>
			</div>

			<div class="form-field wvgs-term-field wvgs-term-tooltip-image-wrap wvgs-term-image-wrap" style="display:none;">
				<label><?php esc_html_e( 'Tooltip Image', 'woo-variation-gallery-studio' ); ?></label>
				<div class="wvgs-term-image-preview"></div>
				<input type="hidden" name="wvgs_tooltip_image_id" class="wvgs-media-id-input" value="" />
				<button type="button" class="button wvgs-upload-image-btn"><?php esc_html_e( 'Upload / Select Tooltip Image', 'woo-variation-gallery-studio' ); ?></button>
				<button type="button" class="button wvgs-remove-image-btn" style="display:none;"><?php esc_html_e( 'Remove Image', 'woo-variation-gallery-studio' ); ?></button>
			</div>
			<?php
		}

		/**
		 * Render swatch & tooltip fields on the "Edit Term" screen.
		 *
		 * @param \WP_Term $term     Term object.
		 * @param string   $taxonomy Attribute taxonomy slug.
		 * @return void
		 */
		public static function render_edit_term_fields( \WP_Term $term, string $taxonomy ): void {
			$attr_type = self::get_attribute_type( $taxonomy );
			$data      = self::get_term_swatch_data( (int) $term->term_id );

			wp_nonce_field( 'wvgs_save_term_meta', 'wvgs_term_meta_nonce' );

			if ( in_array( $attr_type, array( 'color', 'dual_color' ), true ) ) {
				$is_dual = $data['is_dual_color'] || ( 'dual_color' === $attr_type && '' !== $data['secondary_color'] );
				?>
				<tr class="form-field wvgs-term-field wvgs-term-color-wrap">
					<th scope="row" valign="top">
						<label for="wvgs_primary_color"><?php esc_html_e( 'Primary Swatch Color', 'woo-variation-gallery-studio' ); ?></label>
					</th>
					<td>
						<input type="text" name="wvgs_primary_color" id="wvgs_primary_color" value="<?php echo esc_attr( $data['primary_color'] ); ?>" class="wvgs-color-picker" />
						<p class="description"><?php esc_html_e( 'Main hex color for this attribute term.', 'woo-variation-gallery-studio' ); ?></p>
					</td>
				</tr>

				<tr class="form-field wvgs-term-field wvgs-term-dual-toggle-wrap">
					<th scope="row" valign="top">
						<label for="wvgs_is_dual_color"><?php esc_html_e( 'Dual Color Split', 'woo-variation-gallery-studio' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" name="wvgs_is_dual_color" id="wvgs_is_dual_color" value="yes" <?php checked( $is_dual, true ); ?> />
							<?php esc_html_e( 'Enable two-tone dual color swatch', 'woo-variation-gallery-studio' ); ?>
						</label>
					</td>
				</tr>

				<tr class="form-field wvgs-term-field wvgs-term-secondary-color-wrap" <?php echo $is_dual ? '' : 'style="display:none;"'; ?>>
					<th scope="row" valign="top">
						<label for="wvgs_secondary_color"><?php esc_html_e( 'Secondary Swatch Color', 'woo-variation-gallery-studio' ); ?></label>
					</th>
					<td>
						<input type="text" name="wvgs_secondary_color" id="wvgs_secondary_color" value="<?php echo esc_attr( $data['secondary_color'] ); ?>" class="wvgs-color-picker" />
					</td>
				</tr>

				<tr class="form-field wvgs-term-field wvgs-term-dual-angle-wrap" <?php echo $is_dual ? '' : 'style="display:none;"'; ?>>
					<th scope="row" valign="top">
						<label for="wvgs_dual_angle"><?php esc_html_e( 'Dual Color Split Angle (deg)', 'woo-variation-gallery-studio' ); ?></label>
					</th>
					<td>
						<input type="number" name="wvgs_dual_angle" id="wvgs_dual_angle" value="<?php echo esc_attr( (string) $data['dual_angle'] ); ?>" min="0" max="360" step="15" style="width:90px;" />
						<p class="description"><?php esc_html_e( 'Split angle in degrees (45 = diagonal, 90 = vertical, 180 = horizontal).', 'woo-variation-gallery-studio' ); ?></p>
					</td>
				</tr>
				<?php
			}

			if ( 'image' === $attr_type ) {
				$image_id = (int) $data['image_id'];
				?>
				<tr class="form-field wvgs-term-field wvgs-term-image-wrap">
					<th scope="row" valign="top">
						<label><?php esc_html_e( 'Swatch Image', 'woo-variation-gallery-studio' ); ?></label>
					</th>
					<td>
						<div class="wvgs-term-image-preview">
							<?php
							if ( $image_id > 0 ) {
								echo wp_get_attachment_image( $image_id, 'thumbnail' );
							}
							?>
						</div>
						<input type="hidden" name="wvgs_image_id" class="wvgs-media-id-input" value="<?php echo esc_attr( $image_id > 0 ? (string) $image_id : '' ); ?>" />
						<button type="button" class="button wvgs-upload-image-btn"><?php esc_html_e( 'Upload / Select Image', 'woo-variation-gallery-studio' ); ?></button>
						<button type="button" class="button wvgs-remove-image-btn" <?php echo $image_id > 0 ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove Image', 'woo-variation-gallery-studio' ); ?></button>
					</td>
				</tr>
				<?php
			}

			$tooltip_type     = $data['tooltip_type'];
			$tooltip_image_id = (int) $data['tooltip_image_id'];
			?>
			<tr class="form-field wvgs-term-field wvgs-term-tooltip-type-wrap">
				<th scope="row" valign="top">
					<label for="wvgs_tooltip_type"><?php esc_html_e( 'Tooltip Type', 'woo-variation-gallery-studio' ); ?></label>
				</th>
				<td>
					<select name="wvgs_tooltip_type" id="wvgs_tooltip_type" class="wvgs-tooltip-type-select">
						<option value="text" <?php selected( $tooltip_type, 'text' ); ?>><?php esc_html_e( 'Text Tooltip (Default)', 'woo-variation-gallery-studio' ); ?></option>
						<option value="image" <?php selected( $tooltip_type, 'image' ); ?>><?php esc_html_e( 'Image Tooltip', 'woo-variation-gallery-studio' ); ?></option>
						<option value="none" <?php selected( $tooltip_type, 'none' ); ?>><?php esc_html_e( 'No Tooltip', 'woo-variation-gallery-studio' ); ?></option>
					</select>
				</td>
			</tr>

			<tr class="form-field wvgs-term-field wvgs-term-tooltip-text-wrap" <?php echo 'text' === $tooltip_type ? '' : 'style="display:none;"'; ?>>
				<th scope="row" valign="top">
					<label for="wvgs_tooltip_text"><?php esc_html_e( 'Custom Tooltip Text', 'woo-variation-gallery-studio' ); ?></label>
				</th>
				<td>
					<input type="text" name="wvgs_tooltip_text" id="wvgs_tooltip_text" value="<?php echo esc_attr( $data['tooltip_text'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional override. Leave blank to display the term name.', 'woo-variation-gallery-studio' ); ?></p>
				</td>
			</tr>

			<tr class="form-field wvgs-term-field wvgs-term-tooltip-image-wrap wvgs-term-image-wrap" <?php echo 'image' === $tooltip_type ? '' : 'style="display:none;"'; ?>>
				<th scope="row" valign="top">
					<label><?php esc_html_e( 'Tooltip Image', 'woo-variation-gallery-studio' ); ?></label>
				</th>
				<td>
					<div class="wvgs-term-image-preview">
						<?php
						if ( $tooltip_image_id > 0 ) {
							echo wp_get_attachment_image( $tooltip_image_id, 'thumbnail' );
						}
						?>
					</div>
					<input type="hidden" name="wvgs_tooltip_image_id" class="wvgs-media-id-input" value="<?php echo esc_attr( $tooltip_image_id > 0 ? (string) $tooltip_image_id : '' ); ?>" />
					<button type="button" class="button wvgs-upload-image-btn"><?php esc_html_e( 'Upload / Select Tooltip Image', 'woo-variation-gallery-studio' ); ?></button>
					<button type="button" class="button wvgs-remove-image-btn" <?php echo $tooltip_image_id > 0 ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove Image', 'woo-variation-gallery-studio' ); ?></button>
				</td>
			</tr>
			<?php
		}

		/**
		 * Persist term meta securely with nonce, capability, and input validation checks.
		 *
		 * @param int    $term_id  Term ID.
		 * @param int    $tt_id    Term taxonomy ID.
		 * @param string $taxonomy Taxonomy slug.
		 * @return void
		 */
		public static function save_term_meta( int $term_id, int $tt_id, string $taxonomy ): void {
			if ( 0 !== strpos( $taxonomy, 'pa_' ) ) {
				return;
			}

			if ( ! current_user_can( 'manage_product_terms' ) ) {
				return;
			}

			if (
				! isset( $_POST['wvgs_term_meta_nonce'] ) ||
				! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wvgs_term_meta_nonce'] ) ), 'wvgs_save_term_meta' )
			) {
				return;
			}

			// 1. Primary Color.
			if ( isset( $_POST['wvgs_primary_color'] ) ) {
				$primary = sanitize_hex_color( wp_unslash( (string) $_POST['wvgs_primary_color'] ) );
				if ( $primary ) {
					update_term_meta( $term_id, '_wvgs_primary_color', $primary );
				} else {
					delete_term_meta( $term_id, '_wvgs_primary_color' );
				}
			}

			// 2. Dual Color toggle, Secondary Color, and Angle.
			if ( isset( $_POST['wvgs_primary_color'] ) || isset( $_POST['wvgs_is_dual_color'] ) ) {
				$is_dual = ( isset( $_POST['wvgs_is_dual_color'] ) && 'yes' === sanitize_key( wp_unslash( (string) $_POST['wvgs_is_dual_color'] ) ) ) ? 'yes' : 'no';
				update_term_meta( $term_id, '_wvgs_is_dual_color', $is_dual );
			}

			if ( isset( $_POST['wvgs_secondary_color'] ) ) {
				$secondary = sanitize_hex_color( wp_unslash( (string) $_POST['wvgs_secondary_color'] ) );
				if ( $secondary ) {
					update_term_meta( $term_id, '_wvgs_secondary_color', $secondary );
				} else {
					delete_term_meta( $term_id, '_wvgs_secondary_color' );
				}
			}

			if ( isset( $_POST['wvgs_dual_angle'] ) ) {
				$angle = min( 360, max( 0, absint( wp_unslash( $_POST['wvgs_dual_angle'] ) ) ) );
				update_term_meta( $term_id, '_wvgs_dual_angle', $angle );
			}

			// 3. Swatch Image Attachment ID.
			if ( isset( $_POST['wvgs_image_id'] ) ) {
				$image_id = absint( wp_unslash( $_POST['wvgs_image_id'] ) );
				if ( $image_id > 0 && wp_attachment_is_image( $image_id ) ) {
					update_term_meta( $term_id, '_wvgs_image_id', $image_id );
				} else {
					delete_term_meta( $term_id, '_wvgs_image_id' );
				}
			}

			// 4. Tooltip Type, Custom Text, and Tooltip Image Attachment ID.
			if ( isset( $_POST['wvgs_tooltip_type'] ) ) {
				$tooltip_type = sanitize_key( wp_unslash( (string) $_POST['wvgs_tooltip_type'] ) );
				if ( ! in_array( $tooltip_type, array( 'text', 'image', 'none' ), true ) ) {
					$tooltip_type = 'text';
				}
				update_term_meta( $term_id, '_wvgs_tooltip_type', $tooltip_type );
			}

			if ( isset( $_POST['wvgs_tooltip_text'] ) ) {
				$tooltip_text = sanitize_text_field( wp_unslash( (string) $_POST['wvgs_tooltip_text'] ) );
				if ( '' !== $tooltip_text ) {
					update_term_meta( $term_id, '_wvgs_tooltip_text', $tooltip_text );
				} else {
					delete_term_meta( $term_id, '_wvgs_tooltip_text' );
				}
			}

			if ( isset( $_POST['wvgs_tooltip_image_id'] ) ) {
				$tooltip_image_id = absint( wp_unslash( $_POST['wvgs_tooltip_image_id'] ) );
				if ( $tooltip_image_id > 0 && wp_attachment_is_image( $tooltip_image_id ) ) {
					update_term_meta( $term_id, '_wvgs_tooltip_image_id', $tooltip_image_id );
				} else {
					delete_term_meta( $term_id, '_wvgs_tooltip_image_id' );
				}
			}

			if ( class_exists( 'WVGS_Cache' ) ) {
				WVGS_Cache::bump_cache_salt();
			}
		}

		/**
		 * Insert a live swatch "Preview" column right after the checkbox column in the terms table.
		 *
		 * @param array<string, string> $columns Existing columns.
		 * @return array<string, string>
		 */
		public static function add_preview_column( array $columns ): array {
			$new_columns = array();

			foreach ( $columns as $key => $label ) {
				$new_columns[ $key ] = $label;
				if ( 'cb' === $key ) {
					$new_columns['wvgs_preview'] = __( 'Preview', 'woo-variation-gallery-studio' );
				}
			}

			if ( ! isset( $new_columns['wvgs_preview'] ) ) {
				$new_columns['wvgs_preview'] = __( 'Preview', 'woo-variation-gallery-studio' );
			}

			return $new_columns;
		}

		/**
		 * Render the live visual swatch preview inside the `wvgs_preview` column.
		 *
		 * @param string $content     Default column HTML.
		 * @param string $column_name Column identifier.
		 * @param int    $term_id     Term ID.
		 * @return string
		 */
		public static function render_preview_column( string $content, string $column_name, int $term_id ): string {
			if ( 'wvgs_preview' !== $column_name ) {
				return $content;
			}

			$term = get_term( $term_id );
			if ( ! $term instanceof \WP_Term ) {
				return $content;
			}

			$attr_type = self::get_attribute_type( $term->taxonomy );
			$data      = self::get_term_swatch_data( $term_id );

			if ( in_array( $attr_type, array( 'color', 'dual_color' ), true ) ) {
				$primary   = $data['primary_color'] ?: '#e2e2e2';
				$secondary = $data['secondary_color'];
				$is_dual   = ( $data['is_dual_color'] || 'dual_color' === $attr_type ) && '' !== $secondary;

				$bg_style = $is_dual
					? 'background:' . self::get_dual_color_css( $primary, $secondary, (int) $data['dual_angle'] ) . ';'
					: 'background-color:' . $primary . ';';

				return sprintf(
					'<span class="wvgs-admin-preview-swatch wvgs-preview-color" style="%s" title="%s"></span>',
					esc_attr( $bg_style ),
					esc_attr( $term->name )
				);
			}

			if ( 'image' === $attr_type && ! empty( $data['image_id'] ) ) {
				$thumb_url = wp_get_attachment_image_url( (int) $data['image_id'], 'thumbnail' );
				if ( $thumb_url ) {
					return sprintf(
						'<span class="wvgs-admin-preview-swatch wvgs-preview-image"><img src="%s" alt="%s" /></span>',
						esc_url( $thumb_url ),
						esc_attr( $term->name )
					);
				}
			}

			if ( 'auto_image' === $attr_type ) {
				return '<span class="wvgs-admin-preview-badge">' . esc_html__( 'Auto Img', 'woo-variation-gallery-studio' ) . '</span>';
			}

			return sprintf(
				'<span class="wvgs-admin-preview-badge">%s</span>',
				esc_html( $term->name )
			);
		}

		/**
		 * Retrieve the registered WooCommerce attribute type for a taxonomy (e.g. `pa_color`).
		 *
		 * Memoized per request to avoid repeated database/transient calls.
		 *
		 * @param string $taxonomy Attribute taxonomy slug (with or without `pa_` prefix).
		 * @return string Canonical type (`select`, `color`, `dual_color`, `image`, `auto_image`, `button`, `radio`).
		 */
		public static function get_attribute_type( string $taxonomy ): string {
			if ( null === self::$attribute_types_cache ) {
				self::$attribute_types_cache = array();
				if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
					$taxonomies = wc_get_attribute_taxonomies();
					if ( is_array( $taxonomies ) ) {
						foreach ( $taxonomies as $tax ) {
							$slug                                  = 'pa_' . sanitize_title( (string) $tax->attribute_name );
							$type                                  = sanitize_key( (string) $tax->attribute_type );
							self::$attribute_types_cache[ $slug ]  = in_array( $type, WVGS_Plugin::SWATCH_TYPES, true ) ? $type : 'select';
						}
					}
				}
			}

			$normalized = ( 0 === strpos( $taxonomy, 'pa_' ) ) ? $taxonomy : 'pa_' . sanitize_title( $taxonomy );
			return self::$attribute_types_cache[ $normalized ] ?? 'select';
		}

		/**
		 * Flush memoized attribute types cache.
		 *
		 * @return void
		 */
		public static function flush_attribute_types_cache(): void {
			self::$attribute_types_cache = null;
		}

		/**
		 * Retrieve normalized swatch visual & tooltip data for a term ID,
		 * automatically falling back to legacy `woo-variation-swatches` meta keys if present.
		 *
		 * @param int $term_id Term ID.
		 * @return array{
		 *     primary_color: string,
		 *     is_dual_color: bool,
		 *     secondary_color: string,
		 *     dual_angle: int,
		 *     image_id: int,
		 *     tooltip_type: string,
		 *     tooltip_text: string,
		 *     tooltip_image_id: int
		 * }
		 */
		public static function get_term_swatch_data( int $term_id ): array {
			$primary = (string) get_term_meta( $term_id, '_wvgs_primary_color', true );
			if ( '' === $primary ) {
				$primary = (string) get_term_meta( $term_id, 'product_attribute_color', true );
			}
			$primary = sanitize_hex_color( $primary ) ?: '';

			$raw_dual = get_term_meta( $term_id, '_wvgs_is_dual_color', true );
			if ( '' === $raw_dual ) {
				$raw_dual = get_term_meta( $term_id, 'is_dual_color', true );
			}
			$is_dual = ( 'yes' === $raw_dual || '1' === (string) $raw_dual || true === $raw_dual );

			$secondary = (string) get_term_meta( $term_id, '_wvgs_secondary_color', true );
			if ( '' === $secondary ) {
				$secondary = (string) get_term_meta( $term_id, 'secondary_color', true );
			}
			$secondary = sanitize_hex_color( $secondary ) ?: '';

			$raw_angle = get_term_meta( $term_id, '_wvgs_dual_angle', true );
			$angle     = ( '' !== $raw_angle ) ? min( 360, max( 0, absint( $raw_angle ) ) ) : 45;

			$image_id = absint( get_term_meta( $term_id, '_wvgs_image_id', true ) );
			if ( 0 === $image_id ) {
				$image_id = absint( get_term_meta( $term_id, 'product_attribute_image', true ) );
			}

			$tooltip_type = sanitize_key( (string) get_term_meta( $term_id, '_wvgs_tooltip_type', true ) );
			if ( ! in_array( $tooltip_type, array( 'text', 'image', 'none' ), true ) ) {
				$tooltip_type = 'text';
			}

			$tooltip_text     = sanitize_text_field( (string) get_term_meta( $term_id, '_wvgs_tooltip_text', true ) );
			$tooltip_image_id = absint( get_term_meta( $term_id, '_wvgs_tooltip_image_id', true ) );

			return array(
				'primary_color'    => $primary,
				'is_dual_color'    => $is_dual,
				'secondary_color'  => $secondary,
				'dual_angle'       => $angle,
				'image_id'         => $image_id,
				'tooltip_type'     => $tooltip_type,
				'tooltip_text'     => $tooltip_text,
				'tooltip_image_id' => $tooltip_image_id,
			);
		}

		/**
		 * Build a safe CSS `linear-gradient` value for a dual-color split swatch.
		 *
		 * @param string $primary   Primary hex color.
		 * @param string $secondary Secondary hex color.
		 * @param int    $angle     Split angle in degrees (0–360).
		 * @return string
		 */
		public static function get_dual_color_css( string $primary, string $secondary, int $angle = 45 ): string {
			$c1  = sanitize_hex_color( $primary ) ?: '#cccccc';
			$c2  = sanitize_hex_color( $secondary ) ?: '#333333';
			$deg = min( 360, max( 0, $angle ) );

			return sprintf( 'linear-gradient(%ddeg, %s 50%%, %s 50%%)', $deg, $c1, $c2 );
		}
	}

	WVGS_Term_Meta::init();

endif;
