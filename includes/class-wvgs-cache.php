<?php
/**
 * Multi-Layer Cache Manager & Targeted Page-Cache Purger
 *
 * Manages per-product resolved stock-winning media (`_wvgs_resolved_media`),
 * pre-computed Flatsome archive hover payloads, O(1) global cache-salt invalidation,
 * and targeted single-post page cache purges when a winning variation changes.
 *
 * @package WooVariationGalleryStudio
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WVGS_Cache' ) ) :

	/**
	 * Class WVGS_Cache
	 *
	 * Coordinates WordPress Object Cache, persistent post meta, transients,
	 * and third-party page cache invalidation hooks.
	 */
	final class WVGS_Cache {

		/**
		 * Post meta key storing the resolved stock-winning media payload on the parent product.
		 *
		 * @var string
		 */
		public const META_KEY = '_wvgs_resolved_media';

		/**
		 * Post meta key flagging that a parent product's `_thumbnail_id` and
		 * `_product_image_gallery` are dynamically managed by WVGS.
		 *
		 * @var string
		 */
		public const DYNAMIC_FLAG_KEY = '_wvgs_is_dynamic_media';

		/**
		 * WordPress object cache group name.
		 *
		 * @var string
		 */
		public const CACHE_GROUP = 'wvgs_variations';

		/**
		 * Option key storing the O(1) global cache salt (bumped on global setting updates).
		 *
		 * @var string
		 */
		public const SALT_OPTION_KEY = 'wvgs_cache_salt';

		/**
		 * Transient prefix for lightweight shop/archive card payloads.
		 *
		 * @var string
		 */
		public const ARCHIVE_TRANSIENT_PREFIX = 'wvgs_archive_';

		/**
		 * Schema version for cached payloads. Incrementing this safely invalidates
		 * legacy serialized structures across plugin upgrades.
		 *
		 * @var int
		 */
		public const SCHEMA_VERSION = 1;

		/**
		 * Per-request memoized global cache salt.
		 *
		 * @var string|null
		 */
		private static ?string $cache_salt = null;

		/**
		 * Per-request guard preventing redundant page-cache purges for the same product ID.
		 *
		 * @var array<int, bool>
		 */
		private static array $purged_in_request = array();

		/**
		 * Register lifecycle hooks for automatic cache invalidation.
		 *
		 * @return void
		 */
		public static function init(): void {
			// Bump the O(1) cache salt whenever global plugin settings are updated.
			if ( class_exists( 'WVGS_Plugin' ) ) {
				add_action( 'update_option_' . WVGS_Plugin::OPTION_KEY, array( __CLASS__, 'bump_cache_salt' ) );
			}

			// Bump the O(1) cache salt when WooCommerce's "Hide out of stock items" setting changes.
			add_action( 'update_option_woocommerce_hide_out_of_stock_items', array( __CLASS__, 'bump_cache_salt' ) );

			// Bump the O(1) cache salt when product attribute terms are edited (color/image swatch changes).
			add_action( 'edited_term', array( __CLASS__, 'maybe_bump_salt_on_term_change' ), 20, 3 );
		}

		/**
		 * Retrieve the current global cache salt.
		 *
		 * @return string
		 */
		public static function get_cache_salt(): string {
			if ( null !== self::$cache_salt ) {
				return self::$cache_salt;
			}

			$salt = get_option( self::SALT_OPTION_KEY, '' );
			if ( ! is_string( $salt ) || '' === $salt ) {
				$salt = '1';
				update_option( self::SALT_OPTION_KEY, $salt, true );
			}

			self::$cache_salt = $salt;
			return self::$cache_salt;
		}

		/**
		 * Bump the global cache salt to invalidate stale cached payloads in O(1) time.
		 *
		 * @return string New salt value.
		 */
		public static function bump_cache_salt(): string {
			$new_salt         = (string) time() . '_' . wp_rand( 100, 999 );
			self::$cache_salt = $new_salt;
			update_option( self::SALT_OPTION_KEY, $new_salt, true );

			/**
			 * Fires after WVGS bumps its global cache salt.
			 *
			 * @param string $new_salt Newly generated cache salt.
			 */
			do_action( 'wvgs_cache_salt_bumped', $new_salt );

			return $new_salt;
		}

		/**
		 * Bump the global cache salt when a WooCommerce product attribute term (`pa_*`) is edited.
		 *
		 * Ensures archive swatch colors and images reflect edits without manual cache clearing.
		 *
		 * @param int    $term_id  Term ID.
		 * @param int    $tt_id    Term taxonomy ID.
		 * @param string $taxonomy Taxonomy slug.
		 * @return void
		 */
		public static function maybe_bump_salt_on_term_change( int $term_id, int $tt_id, string $taxonomy ): void {
			if ( 0 === strpos( $taxonomy, 'pa_' ) ) {
				self::bump_cache_salt();
			}
		}

		/**
		 * Retrieve the cached stock-winning resolved media payload for a variable product.
		 *
		 * Checks WordPress Object Cache first, then falls back to persistent post meta.
		 * Returns `null` if missing or invalidated by a schema/salt change.
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return array<string, mixed>|null
		 */
		public static function get_resolved_media( int $product_id ): ?array {
			if ( $product_id <= 0 ) {
				return null;
			}

			$cache_key = self::build_object_cache_key( 'resolved', $product_id );
			$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

			if ( self::is_payload_valid( $cached ) ) {
				return $cached;
			}

			$meta = get_post_meta( $product_id, self::META_KEY, true );
			if ( self::is_payload_valid( $meta ) ) {
				wp_cache_set( $cache_key, $meta, self::CACHE_GROUP, HOUR_IN_SECONDS );
				return $meta;
			}

			return null;
		}

		/**
		 * Persist the resolved stock-winning media payload to post meta and object cache.
		 *
		 * @param int                  $product_id Parent variable product ID.
		 * @param array<string, mixed> $payload    Computed media resolution payload.
		 * @return bool
		 */
		public static function set_resolved_media( int $product_id, array $payload ): bool {
			if ( $product_id <= 0 ) {
				return false;
			}

			$payload['schema_version'] = self::SCHEMA_VERSION;
			$payload['cache_salt']     = self::get_cache_salt();
			$payload['updated_at']     = time();

			update_post_meta( $product_id, self::META_KEY, $payload );

			$cache_key = self::build_object_cache_key( 'resolved', $product_id );
			wp_cache_set( $cache_key, $payload, self::CACHE_GROUP, HOUR_IN_SECONDS );

			return true;
		}

		/**
		 * Retrieve the cached lightweight archive hover & swatch slide payload for shop cards.
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return array<string, mixed>|null
		 */
		public static function get_archive_payload( int $product_id ): ?array {
			if ( $product_id <= 0 ) {
				return null;
			}

			$cache_key = self::build_object_cache_key( 'archive', $product_id );
			$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

			if ( self::is_payload_valid( $cached ) ) {
				return $cached;
			}

			$transient = get_transient( self::ARCHIVE_TRANSIENT_PREFIX . $product_id );
			if ( self::is_payload_valid( $transient ) ) {
				wp_cache_set( $cache_key, $transient, self::CACHE_GROUP, HOUR_IN_SECONDS );
				return $transient;
			}

			return null;
		}

		/**
		 * Store the lightweight archive hover & swatch slide payload in object cache and transient.
		 *
		 * @param int                  $product_id Parent variable product ID.
		 * @param array<string, mixed> $payload    Archive card hover/swatch payload.
		 * @param int                  $ttl        Expiration time in seconds (defaults to 6 hours).
		 * @return bool
		 */
		public static function set_archive_payload( int $product_id, array $payload, int $ttl = 0 ): bool {
			if ( $product_id <= 0 ) {
				return false;
			}

			$ttl = $ttl > 0 ? $ttl : ( 6 * HOUR_IN_SECONDS );

			$payload['schema_version'] = self::SCHEMA_VERSION;
			$payload['cache_salt']     = self::get_cache_salt();
			$payload['updated_at']     = time();

			$cache_key = self::build_object_cache_key( 'archive', $product_id );
			wp_cache_set( $cache_key, $payload, self::CACHE_GROUP, $ttl );

			return set_transient( self::ARCHIVE_TRANSIENT_PREFIX . $product_id, $payload, $ttl );
		}

		/**
		 * Invalidate all WVGS caches for a specific parent variable product.
		 *
		 * Optionally purges third-party full-page caches when `$purge_page_cache` is true
		 * (triggered only when the winning variation or in-stock signature actually changes).
		 *
		 * @param int  $product_id       Parent variable product ID.
		 * @param bool $purge_page_cache Whether to trigger targeted page-cache purges.
		 * @return void
		 */
		public static function invalidate_product( int $product_id, bool $purge_page_cache = false ): void {
			if ( $product_id <= 0 ) {
				return;
			}

			wp_cache_delete( self::build_object_cache_key( 'resolved', $product_id ), self::CACHE_GROUP );
			wp_cache_delete( self::build_object_cache_key( 'archive', $product_id ), self::CACHE_GROUP );
			delete_transient( self::ARCHIVE_TRANSIENT_PREFIX . $product_id );
			delete_post_meta( $product_id, self::META_KEY );

			if ( $purge_page_cache ) {
				self::purge_page_caches( $product_id );
			}
		}

		/**
		 * Perform targeted single-post and shop-archive page cache purges.
		 *
		 * Supports core post cache, WooCommerce product transients, LiteSpeed Cache,
		 * WP Rocket, W3 Total Cache, WP Super Cache, SiteGround Optimizer, and FlyingPress.
		 *
		 * @param int $product_id Parent variable product ID.
		 * @return void
		 */
		public static function purge_page_caches( int $product_id ): void {
			if ( $product_id <= 0 || isset( self::$purged_in_request[ $product_id ] ) ) {
				return;
			}

			self::$purged_in_request[ $product_id ] = true;

			try {
				// 1. WordPress core post cache and WooCommerce product transients.
				clean_post_cache( $product_id );

				if ( function_exists( 'wc_delete_product_transients' ) ) {
					wc_delete_product_transients( $product_id );
				}

				$shop_page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0;

				// 2. LiteSpeed Cache.
				if ( defined( 'LSCWP_V' ) ) {
					do_action( 'litespeed_purge_post', $product_id );
					if ( $shop_page_id > 0 ) {
						do_action( 'litespeed_purge_post', $shop_page_id );
					}
				}

				// 3. WP Rocket.
				if ( function_exists( 'rocket_clean_post' ) ) {
					rocket_clean_post( $product_id );
					if ( $shop_page_id > 0 ) {
						rocket_clean_post( $shop_page_id );
					}
				}

				// 4. W3 Total Cache.
				if ( function_exists( 'w3tc_flush_post' ) ) {
					w3tc_flush_post( $product_id );
				}

				// 5. WP Super Cache.
				if ( function_exists( 'wpsc_delete_post_cache' ) ) {
					wpsc_delete_post_cache( $product_id );
				}

				// 6. SiteGround Speed Optimizer.
				if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
					$permalink = get_permalink( $product_id );
					if ( is_string( $permalink ) && '' !== $permalink ) {
						sg_cachepress_purge_cache( $permalink );
					}
				}

				// 7. FlyingPress.
				if ( class_exists( '\FlyingPress\Purge' ) && method_exists( '\FlyingPress\Purge', 'purge_url' ) ) {
					$permalink = get_permalink( $product_id );
					if ( is_string( $permalink ) && '' !== $permalink ) {
						\FlyingPress\Purge::purge_url( $permalink );
					}
				}

				/**
				 * Fires after WVGS purges targeted page caches for a product whose
				 * stock-winning variation changed. Useful for Cloudflare/Varnish hooks.
				 *
				 * @param int $product_id Parent variable product ID.
				 */
				do_action( 'wvgs_after_purge_product_page_cache', $product_id );
			} catch ( \Throwable $e ) {
				// Prevent third-party cache plugin exceptions from interrupting checkout or stock updates.
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->warning(
						sprintf( 'WVGS page cache purge warning for product #%d: %s', $product_id, $e->getMessage() ),
						array( 'source' => 'woo-variation-gallery-studio' )
					);
				}
			}
		}

		/**
		 * Validate that a cached payload is an array matching the active schema version and salt.
		 *
		 * @param mixed $payload Raw cached value.
		 * @return bool
		 */
		private static function is_payload_valid( $payload ): bool {
			if ( ! is_array( $payload ) ) {
				return false;
			}

			$version = isset( $payload['schema_version'] ) ? (int) $payload['schema_version'] : 0;
			$salt    = isset( $payload['cache_salt'] ) ? (string) $payload['cache_salt'] : '';

			return ( self::SCHEMA_VERSION === $version && self::get_cache_salt() === $salt );
		}

		/**
		 * Build a scoped object cache key for a product.
		 *
		 * @param string $type       Payload type ('resolved' or 'archive').
		 * @param int    $product_id Parent variable product ID.
		 * @return string
		 */
		private static function build_object_cache_key( string $type, int $product_id ): string {
			return sprintf( 'wvgs_%s_%d_%s', sanitize_key( $type ), $product_id, self::get_cache_salt() );
		}
	}

	WVGS_Cache::init();

endif;
