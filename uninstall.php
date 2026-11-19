<?php
/**
 * Woo Variation & Gallery Studio (Flatsome Edition) — Uninstaller
 *
 * Triggered automatically by WordPress when the plugin is deleted via the Plugins screen
 * or `wp plugin delete`.
 *
 * Data-Safety Policy:
 * - Always clears ephemeral `wvgs_archive_*` transients and object cache entries.
 * - By default (`delete_data_on_uninstall` unchecked), preserves `wvgs_settings`,
 *   variation galleries, swatch term metadata, and product overrides so reinstalling
 *   the plugin restores all store configurations seamlessly.
 * - Only when `wvgs_settings['delete_data_on_uninstall']` is explicitly enabled does
 *   this script remove plugin options, `_wvgs_*` post/term metadata, and restore custom
 *   WooCommerce attribute types back to native `'select'`.
 * - NEVER deletes native WooCommerce `_thumbnail_id` or `_product_image_gallery` meta,
 *   ensuring products never lose their synced main images or galleries on uninstall.
 *
 * @package WooVariationGalleryStudio
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // Exit if accessed directly outside WordPress uninstall lifecycle.
}

/**
 * Determine whether the current site has explicitly opted in to full data removal on uninstall.
 *
 * Normalizes both WooCommerce Settings API checkbox values (`'yes'`/`'no'`) and native booleans.
 *
 * @return bool
 */
function wvgs_uninstall_should_delete_data(): bool {
	$settings = get_option( 'wvgs_settings', array() );
	if ( ! is_array( $settings ) || ! array_key_exists( 'delete_data_on_uninstall', $settings ) ) {
		return false;
	}

	$flag = $settings['delete_data_on_uninstall'];
	return ( true === $flag || 1 === $flag || '1' === $flag || 'yes' === $flag );
}

/**
 * Delete all ephemeral `wvgs_archive_*` transients for the current site using prepared SQL.
 *
 * @return void
 */
function wvgs_uninstall_delete_archive_transients(): void {
	global $wpdb;

	$transient_like = $wpdb->esc_like( '_transient_wvgs_archive_' ) . '%';
	$timeout_like   = $wpdb->esc_like( '_transient_timeout_wvgs_archive_' ) . '%';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Standard wildcard transient cleanup during uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$transient_like,
			$timeout_like
		)
	);
}

/**
 * Restore WVGS custom attribute types (`color`, `dual_color`, `image`, `auto_image`, `button`, `radio`)
 * in WooCommerce's `woocommerce_attribute_taxonomies` table back to native `'select'`.
 *
 * Prevents broken attribute term pickers inside `Product Data > Attributes` after permanent removal.
 *
 * @return void
 */
function wvgs_uninstall_restore_wc_attribute_types(): void {
	global $wpdb;

	$table_name = $wpdb->prefix . 'woocommerce_attribute_taxonomies';

	try {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking WooCommerce table presence before uninstall cleanup.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW TABLES LIKE %s',
				$wpdb->esc_like( $table_name )
			)
		);

		if ( $table_exists !== $table_name ) {
			return;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name verified against $wpdb->prefix and SHOW TABLES LIKE.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$table_name}` SET attribute_type = %s WHERE attribute_type IN (%s, %s, %s, %s, %s, %s)",
				'select',
				'color',
				'dual_color',
				'image',
				'auto_image',
				'button',
				'radio'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Clear WooCommerce's cached attribute taxonomy list so native 'select' takes effect immediately.
		delete_transient( 'wc_attribute_taxonomies' );
		if ( class_exists( 'WC_Cache_Helper' ) && method_exists( 'WC_Cache_Helper', 'invalidate_cache_group' ) ) {
			\WC_Cache_Helper::invalidate_cache_group( 'woocommerce-attributes' );
		}
	} catch ( \Throwable $e ) {
		// Never block plugin uninstall if WooCommerce was already removed or table schema differs.
		return;
	}
}

/**
 * Execute uninstall cleanup for the currently active site.
 *
 * @return void
 */
function wvgs_uninstall_single_site(): void {
	// 1. Always purge ephemeral archive transients and plugin object cache group.
	wvgs_uninstall_delete_archive_transients();

	if ( function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'wvgs_variations' );
	}

	// 2. Stop here unless the site explicitly opted in to deleting persistent plugin data.
	if ( ! wvgs_uninstall_should_delete_data() ) {
		return;
	}

	// 3. Remove plugin options.
	delete_option( 'wvgs_settings' );
	delete_option( 'wvgs_cache_salt' );

	// 4. Remove all `_wvgs_*` post metadata via core `delete_metadata()` (also clears meta object caches).
	// Intentionally leaves native `_thumbnail_id` and `_product_image_gallery` untouched.
	$post_meta_keys = array(
		'_wvgs_resolved_media',
		'_wvgs_is_dynamic_media',
		'_wvgs_product_settings',
		'_wvgs_variation_gallery_ids',
		'_wvgs_video_url',
		'_wvgs_video_autoplay',
	);

	foreach ( $post_meta_keys as $meta_key ) {
		delete_metadata( 'post', 0, $meta_key, '', true );
	}

	// 5. Remove all `_wvgs_*` attribute term metadata via core `delete_metadata()`.
	$term_meta_keys = array(
		'_wvgs_primary_color',
		'_wvgs_is_dual_color',
		'_wvgs_secondary_color',
		'_wvgs_dual_angle',
		'_wvgs_image_id',
		'_wvgs_tooltip_type',
		'_wvgs_tooltip_text',
		'_wvgs_tooltip_image_id',
	);

	foreach ( $term_meta_keys as $meta_key ) {
		delete_metadata( 'term', 0, $meta_key, '', true );
	}

	// 6. Restore custom WVGS attribute types back to native WooCommerce 'select'.
	wvgs_uninstall_restore_wc_attribute_types();
}

// Run uninstall cleanly across single-site or Multisite networks.
if ( is_multisite() ) {
	$wvgs_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	if ( is_array( $wvgs_site_ids ) ) {
		foreach ( $wvgs_site_ids as $wvgs_site_id ) {
			switch_to_blog( (int) $wvgs_site_id );
			wvgs_uninstall_single_site();
			restore_current_blog();
		}
	}
} else {
	wvgs_uninstall_single_site();
}
