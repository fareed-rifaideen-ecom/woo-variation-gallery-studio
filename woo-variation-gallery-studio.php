<?php
/**
 * Plugin Name:       Woo Variation & Gallery Studio (Flatsome Edition)
 * Plugin URI:        https://github.com/
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
define( 'WVGS_MIN_PHP', '8.1' );
define( 'WVGS_MIN_WP', '6.5' );
define( 'WVGS_MIN_WC', '9.0' );
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

if ( ! class_exists( 'WVGS_Plugin' ) ) :

	/**
	 * Class WVGS_Plugin
	 *
	 * Main service container and lifecycle bootstrapper for Woo Variation & Gallery Studio.
	 * Safely loads core services, admin screens, frontend renderers, and conditional assets.
	 */
	final class WVGS_Plugin {

		/**
		 * Option key storing global plugin settings.
		 *
		 * @var string
		 */
		public const OPTION_KEY = 'wvgs_settings';

		/**
		 * Canonical allowlist of supported swatch types across global and product levels.
		 *
		 * @var array<int, string>
		 */
		public const SWATCH_TYPES = array(
			'select',
			'color',
			'dual_color',
			'image',
			'auto_image',
			'button',
			'radio',
		);

		/**
		 * Singleton instance.
		 *
		 * @var WVGS_Plugin|null
		 */
		private static ?WVGS_Plugin $instance = null;

		/**
		 * Dynamic Stock-Winning Media & Attribution Resolver service.
		 *
		 * @var WVGS_Stock_Resolver|null
		 */
		public ?WVGS_Stock_Resolver $resolver = null;

		/**
		 * Cached normalized global settings for the current request.
		 *
		 * @var array<string, mixed>|null
		 */
		private ?array $settings_cache = null;

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
		 * Private constructor to enforce singleton pattern.
		 */
		private function __construct() {}

		/**
		 * Return default global settings.
		 *
		 * @return array<string, mixed>
		 */
		public static function get_default_settings(): array {
			return array(
				'enable_stock_winner'          => true,
				'auto_select_stock_winner'     => true,
				'hide_out_of_stock_variations' => true,
				'out_of_stock_behavior'        => 'cross',
				'sync_header_price'            => true,
				'ajax_variation_threshold'     => 120,
				'swatch_shape'                 => 'rounded',
				'swatch_size'                  => 34,
				'auto_convert_dropdowns'       => 'button',
				'enable_tooltips'              => true,
				'enable_archive_swatches'      => true,
				'default_archive_attribute'    => 'pa_color',
				'archive_swatch_limit'         => 5,
				'archive_hover_mode'           => 'variation_gallery',
				'archive_slideshow_delay'      => 1500,
				'delete_data_on_uninstall'     => false,
			);
		}

		/**
		 * Retrieve normalized global settings merged with defaults.
		 *
		 * Normalizes WooCommerce Settings API `'yes'`/`'no'` checkboxes into strict booleans
		 * and clamps numeric/enum settings to safe bounds.
		 *
		 * @param bool $refresh Whether to bypass per-request memoization.
		 * @return array<string, mixed>
		 */
		public function get_settings( bool $refresh = false ): array {
			if ( ! $refresh && null !== $this->settings_cache ) {
				return $this->settings_cache;
			}

			$saved = get_option( self::OPTION_KEY, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}

			$merged = wp_parse_args( $saved, self::get_default_settings() );

			$boolean_keys = array(
				'enable_stock_winner',
				'auto_select_stock_winner',
				'hide_out_of_stock_variations',
				'sync_header_price',
				'enable_tooltips',
				'enable_archive_swatches',
				'delete_data_on_uninstall',
			);

			foreach ( $boolean_keys as $key ) {
				$val            = $merged[ $key ] ?? false;
				$merged[ $key ] = ( true === $val || 1 === $val || '1' === $val || 'yes' === $val );
			}

			// Validate allowlisted enum options.
			$merged['out_of_stock_behavior'] = in_array( $merged['out_of_stock_behavior'], array( 'cross', 'blur', 'hide' ), true )
				? (string) $merged['out_of_stock_behavior']
				: 'cross';

			$merged['swatch_shape'] = in_array( $merged['swatch_shape'], array( 'rounded', 'circle', 'square' ), true )
				? (string) $merged['swatch_shape']
				: 'rounded';

			$merged['auto_convert_dropdowns'] = in_array( $merged['auto_convert_dropdowns'], array( 'none', 'button', 'auto_image' ), true )
				? (string) $merged['auto_convert_dropdowns']
				: 'button';

			$merged['archive_hover_mode'] = in_array( $merged['archive_hover_mode'], array( 'variation_gallery', 'next_variation', 'variation_slideshow' ), true )
				? (string) $merged['archive_hover_mode']
				: 'variation_gallery';

			// Clamp numeric bounds.
			$merged['ajax_variation_threshold'] = min( 500, max( 30, absint( $merged['ajax_variation_threshold'] ) ) );
			$merged['swatch_size']              = min( 64, max( 20, absint( $merged['swatch_size'] ) ) );
			$merged['archive_swatch_limit']     = min( 20, max( 1, absint( $merged['archive_swatch_limit'] ) ) );
			$merged['archive_slideshow_delay']  = min( 10000, max( 500, absint( $merged['archive_slideshow_delay'] ) ) );

			// Sanitize attribute slug.
			$merged['default_archive_attribute'] = sanitize_key( (string) $merged['default_archive_attribute'] );
			if ( '' === $merged['default_archive_attribute'] ) {
				$merged['default_archive_attribute'] = 'pa_color';
			}

			$this->settings_cache = $merged;
			return $this->settings_cache;
		}

		/**
		 * Clear memoized settings cache (called when WooCommerce settings are saved).
		 *
		 * @return void
		 */
		public function flush_settings_cache(): void {
			$this->settings_cache = null;
		}

		/**
		 * Boot the plugin on `plugins_loaded`.
		 *
		 * @return void
		 */
		public function boot(): void {
			// Enforce PHP minimum version.
			if ( version_compare( PHP_VERSION, WVGS_MIN_PHP, '<' ) ) {
				add_action( 'admin_notices', array( $this, 'render_php_version_notice' ) );
				return;
			}

			// Enforce WooCommerce presence and minimum version.
			if ( ! class_exists( 'WooCommerce' ) || ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, WVGS_MIN_WC, '<' ) ) ) {
				add_action( 'admin_notices', array( $this, 'render_missing_woocommerce_notice' ) );
				return;
			}

			// Load translations on `init` (WordPress 6.7+ compliant).
			add_action( 'init', array( $this, 'load_textdomain' ) );

			// Flush in-memory settings cache whenever options update.
			add_action( 'update_option_' . self::OPTION_KEY, array( $this, 'flush_settings_cache' ) );

			// Load core service classes (Files 2 and 3).
			$this->load_core_services();

			// Raise WooCommerce AJAX variation threshold so variable products with 30-120+ variations get instant client-side swatches & gallery swaps.
			add_filter( 'woocommerce_ajax_variation_threshold', array( $this, 'filter_ajax_variation_threshold' ), 20, 2 );

			// Register WooCommerce > Settings > Variation Studio tab (File 4).
			add_filter( 'woocommerce_get_settings_pages', array( $this, 'register_wc_settings_page' ) );

			// Add Settings shortcut on the Plugins screen.
			add_filter( 'plugin_action_links_' . WVGS_BASENAME, array( $this, 'add_plugin_action_links' ) );

			// Conditional asset loading for Admin and Frontend.
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ), 20 );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ), 20 );

			// Load Admin and Frontend feature modules (Files 5 through 10).
			$this->load_feature_modules();
		}

		/**
		 * Load plugin textdomain for translations.
		 *
		 * @return void
		 */
		public function load_textdomain(): void {
			load_plugin_textdomain(
				'woo-variation-gallery-studio',
				false,
				dirname( WVGS_BASENAME ) . '/languages'
			);
		}

		/**
		 * Load core Cache and Stock-Winning Media Resolver classes if present.
		 *
		 * @return void
		 */
		private function load_core_services(): void {
			$cache_file    = WVGS_PATH . 'includes/class-wvgs-cache.php';
			$resolver_file = WVGS_PATH . 'includes/class-wvgs-stock-resolver.php';

			if ( file_exists( $cache_file ) ) {
				require_once $cache_file;
			}

			if ( file_exists( $resolver_file ) ) {
				require_once $resolver_file;
			}

			if ( class_exists( 'WVGS_Stock_Resolver' ) ) {
				$this->resolver = new WVGS_Stock_Resolver();
				$this->resolver->register_hooks();
			}
		}

		/**
		 * Load Admin and Frontend modular classes safely when present in `includes/`.
		 *
		 * @return void
		 */
		private function load_feature_modules(): void {
			$admin_modules = array(
				'includes/Admin/class-wvgs-term-meta.php',
				'includes/Admin/class-wvgs-variation-gallery.php',
				'includes/Admin/class-wvgs-product-metabox.php',
			);

			foreach ( $admin_modules as $relative_path ) {
				$full_path = WVGS_PATH . $relative_path;
				if ( file_exists( $full_path ) ) {
					require_once $full_path;
				}
			}

			$frontend_modules = array(
				'includes/Frontend/class-wvgs-swatch-renderer.php',
				'includes/Frontend/class-wvgs-flatsome-gallery.php',
				'includes/Frontend/class-wvgs-archive-loop.php',
			);

			foreach ( $frontend_modules as $relative_path ) {
				$full_path = WVGS_PATH . $relative_path;
				if ( file_exists( $full_path ) ) {
					require_once $full_path;
				}
			}
		}

		/**
		 * Filter WooCommerce's AJAX variation threshold.
		 *
		 * @param int         $threshold Default threshold (30).
		 * @param \WC_Product $product   Variable product instance.
		 * @return int
		 */
		public function filter_ajax_variation_threshold( int $threshold, $product ): int {
			$settings = $this->get_settings();
			return max( $threshold, (int) $settings['ajax_variation_threshold'] );
		}

		/**
		 * Register the Variation Studio tab under WooCommerce > Settings.
		 *
		 * @param array<int, \WC_Settings_Page> $settings Existing WooCommerce settings pages.
		 * @return array<int, \WC_Settings_Page>
		 */
		public function register_wc_settings_page( array $settings ): array {
			$settings_file = WVGS_PATH . 'includes/Admin/class-wvgs-admin-settings.php';
			if ( file_exists( $settings_file ) ) {
				$page = include $settings_file;
				if ( $page instanceof \WC_Settings_Page ) {
					$settings[] = $page;
				}
			}
			return $settings;
		}

		/**
		 * Add a direct Settings link on the WordPress Plugins page.
		 *
		 * @param array<int, string> $links Existing action links.
		 * @return array<int, string>
		 */
		public function add_plugin_action_links( array $links ): array {
			$settings_url = admin_url( 'admin.php?page=wc-settings&tab=wvgs_settings' );
			array_unshift(
				$links,
				'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'woo-variation-gallery-studio' ) . '</a>'
			);
			return $links;
		}

		/**
		 * Conditionally enqueue Admin CSS & JS on Product Edit, Attribute, Term, and Plugin Settings screens.
		 *
		 * @param string $hook Current admin screen hook suffix.
		 * @return void
		 */
		public function enqueue_admin_assets( string $hook ): void {
			global $post_type, $post;

			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
			$page     = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			$tab      = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
			$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
			// phpcs:enable WordPress.Security.NonceVerification.Recommended

			$is_product_screen   = in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && 'product' === $post_type;
			$is_attribute_screen = ( 'product_attributes' === $page );
			$is_term_screen      = in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) && 0 === strpos( $taxonomy, 'pa_' );
			$is_settings_screen  = ( 'wc-settings' === $page && 'wvgs_settings' === $tab );

			if ( ! $is_product_screen && ! $is_attribute_screen && ! $is_term_screen && ! $is_settings_screen ) {
				return;
			}

			wp_enqueue_media();
			wp_enqueue_style( 'wp-color-picker' );

			$admin_css = WVGS_PATH . 'assets/css/wvgs-admin.css';
			if ( file_exists( $admin_css ) ) {
				wp_enqueue_style(
					'wvgs-admin',
					WVGS_URL . 'assets/css/wvgs-admin.css',
					array( 'wp-color-picker' ),
					WVGS_VERSION
				);
			}

			$admin_js = WVGS_PATH . 'assets/js/wvgs-admin.js';
			if ( file_exists( $admin_js ) ) {
				wp_enqueue_script(
					'wvgs-admin',
					WVGS_URL . 'assets/js/wvgs-admin.js',
					array( 'jquery', 'jquery-ui-sortable', 'wp-color-picker' ),
					WVGS_VERSION,
					true
				);

				wp_localize_script(
					'wvgs-admin',
					'wvgsAdminData',
					array(
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'nonce'   => wp_create_nonce( 'wvgs_admin_nonce' ),
						'postId'  => ( $post instanceof \WP_Post ) ? (int) $post->ID : 0,
						'i18n'    => array(
							'selectImages'   => __( 'Select Gallery Images', 'woo-variation-gallery-studio' ),
							'addToGallery'   => __( 'Add to Gallery', 'woo-variation-gallery-studio' ),
							'selectSwatch'   => __( 'Select Swatch Image', 'woo-variation-gallery-studio' ),
							'useImage'       => __( 'Use Image', 'woo-variation-gallery-studio' ),
							'recalculating'  => __( 'Recalculating...', 'woo-variation-gallery-studio' ),
							'recalculateBtn' => __( 'Recalculate Stock Winner Now', 'woo-variation-gallery-studio' ),
						),
					)
				);
			}
		}

		/**
		 * Conditionally enqueue Frontend CSS & JS on WooCommerce product, archive, and Flatsome catalog pages.
		 *
		 * @return void
		 */
		public function enqueue_frontend_assets(): void {
			if ( is_admin() ) {
				return;
			}

			$should_load = is_product() || is_shop() || is_product_taxonomy() || is_front_page();

			if ( ! $should_load && is_singular() ) {
				global $post;
				if ( $post instanceof \WP_Post ) {
					$content = (string) $post->post_content;
					if (
						has_shortcode( $content, 'products' ) ||
						has_shortcode( $content, 'product_page' ) ||
						has_shortcode( $content, 'ux_products' ) ||
						has_block( 'woocommerce/product-collection', $post )
					) {
						$should_load = true;
					}
				}
			}

			/**
			 * Filter whether to enqueue WVGS frontend swatch and gallery assets on the current request.
			 *
			 * @param bool $should_load Default load decision.
			 */
			if ( ! apply_filters( 'wvgs_enqueue_frontend_assets', $should_load ) ) {
				return;
			}

			$settings     = $this->get_settings();
			$frontend_css = WVGS_PATH . 'assets/css/wvgs-frontend.css';

			if ( file_exists( $frontend_css ) ) {
				wp_enqueue_style(
					'wvgs-frontend',
					WVGS_URL . 'assets/css/wvgs-frontend.css',
					array(),
					WVGS_VERSION
				);

				$swatch_size = (int) $settings['swatch_size'];
				$radius_map  = array(
					'circle'  => '50%',
					'rounded' => '4px',
					'square'  => '0px',
				);
				$radius      = $radius_map[ $settings['swatch_shape'] ] ?? '4px';

				$inline_css = sprintf(
					':root{--wvgs-swatch-size:%dpx;--wvgs-swatch-radius:%s;}',
					$swatch_size,
					$radius
				);
				wp_add_inline_style( 'wvgs-frontend', $inline_css );
			}

			$frontend_js = WVGS_PATH . 'assets/js/wvgs-frontend.js';
			if ( file_exists( $frontend_js ) ) {
				$deps = array( 'jquery' );
				if ( is_product() || wp_script_is( 'wc-add-to-cart-variation', 'registered' ) ) {
					$deps[] = 'wc-add-to-cart-variation';
				}

				wp_enqueue_script(
					'wvgs-frontend',
					WVGS_URL . 'assets/js/wvgs-frontend.js',
					$deps,
					WVGS_VERSION,
					true
				);

				wp_localize_script(
					'wvgs-frontend',
					'wvgsFrontendData',
					array(
						'syncHeaderPrice'    => (bool) $settings['sync_header_price'],
						'outOfStockBehavior' => (string) $settings['out_of_stock_behavior'],
						'enableTooltips'     => (bool) $settings['enable_tooltips'],
						'swatchShape'        => (string) $settings['swatch_shape'],
						'i18n'               => array(
							'outOfStock' => __( 'Out of stock', 'woo-variation-gallery-studio' ),
							'playVideo'  => __( 'Play product video', 'woo-variation-gallery-studio' ),
						),
					)
				);
			}
		}

		/**
		 * Render admin notice when PHP version is below minimum requirement.
		 *
		 * @return void
		 */
		public function render_php_version_notice(): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: Required PHP version, 2: Current PHP version. */
						__( 'Woo Variation & Gallery Studio requires PHP %1$s or higher. You are currently running PHP %2$s.', 'woo-variation-gallery-studio' ),
						WVGS_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}

		/**
		 * Render admin notice when WooCommerce is inactive or outdated.
		 *
		 * @return void
		 */
		public function render_missing_woocommerce_notice(): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: Minimum WooCommerce version. */
						__( 'Woo Variation & Gallery Studio requires WooCommerce %s or higher to be installed and active.', 'woo-variation-gallery-studio' ),
						WVGS_MIN_WC
					)
				)
			);
		}
	}

endif;

/**
 * Activation hook: seed default global settings if not already stored.
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
