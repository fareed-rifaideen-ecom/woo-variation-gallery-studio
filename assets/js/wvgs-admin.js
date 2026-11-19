/**
 * Woo Variation & Gallery Studio (Flatsome Edition) — Admin Controller
 *
 * Powers:
 * 1. Sortable multi-image WP Media uploaders for Variation Galleries & Attribute-Level Galleries
 *    (with deduplication, count badges, Clear All, and WooCommerce variation save-button activation).
 * 2. Single-image WP Media pickers for Global Term Swatches, Tooltip Images, and Product Overrides.
 * 3. WP Color Picker initialization, Dual-Color split toggles, and Tooltip type field toggles
 *    (including clean form resets after WordPress AJAX `add-tag` submissions).
 * 4. Product Data > Swatches & Gallery accordions, live `data-effective-type` field switching,
 *    and Lock Static Parent Image visual feedback.
 * 5. Live AJAX Attribute Refresh (manual button + automatic sync after saving WC Attributes).
 * 6. Live AJAX Stock Winner Recalculation updating the Status Banner without page reload.
 *
 * @package WooVariationGalleryStudio
 */

( function ( $ ) {
	'use strict';

	var adminData = window.wvgsAdminData || {
		ajaxUrl: window.ajaxurl || '',
		nonce: '',
		postId: 0,
		i18n: {}
	};

	var i18n = $.extend(
		{
			selectImages: 'Select Gallery Images',
			addToGallery: 'Add to Gallery',
			selectSwatch: 'Select Swatch Image',
			useImage: 'Use Image',
			recalculating: 'Recalculating...',
			recalculateBtn: 'Recalculate Stock Winner Now'
		},
		adminData.i18n || {}
	);

	/**
	 * Safely escape string values interpolated into HTML attributes or text nodes.
	 *
	 * @param {*} value Raw value.
	 * @return {string}
	 */
	function escapeHtml( value ) {
		return String( value === null || value === undefined ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	/**
	 * Resolve the current Product post ID from localized data, button attribute, or `#post_ID`.
	 *
	 * @param {jQuery} [$el] Optional element carrying `data-product_id`.
	 * @return {number}
	 */
	function resolveProductId( $el ) {
		var fromEl = ( $el && $el.length ) ? parseInt( $el.attr( 'data-product_id' ), 10 ) : 0;
		if ( fromEl > 0 ) {
			return fromEl;
		}
		var fromData = parseInt( adminData.postId, 10 ) || 0;
		if ( fromData > 0 ) {
			return fromData;
		}
		var fromDom = parseInt( $( '#post_ID' ).val(), 10 ) || 0;
		return fromDom > 0 ? fromDom : 0;
	}

	/**
	 * Notify WooCommerce that a variation row was modified so its "Save changes" button activates.
	 *
	 * @param {jQuery} $context Element inside a variation gallery wrapper.
	 * @return {void}
	 */
	function markVariationNeedsUpdate( $context ) {
		var $variationRow = $context.closest( '.woocommerce_variation' );
		if ( ! $variationRow.length ) {
			return;
		}

		$variationRow.addClass( 'variation-needs-update' );
		$( 'button.cancel-variation-changes, button.save-variation-changes' ).prop( 'disabled', false );
		$( '#variable_product_options' ).trigger( 'woocommerce_variations_input_changed' );
	}

	/**
	 * Synchronize a gallery container's hidden CSV input, item counter badge, and Clear All button.
	 *
	 * @param {jQuery} $list The `.wvgs-variation-gallery-images` `<ul>` element.
	 * @return {void}
	 */
	function syncGalleryContainer( $list ) {
		var $wrapper = $list.closest( '.wvgs-variation-gallery-wrapper, .wvgs-mini-gallery' );
		var $input   = $wrapper.find( '.wvgs-variation-gallery-ids' ).first();
		var ids      = [];

		$list.find( 'li.image' ).each( function () {
			var attId = parseInt( $( this ).attr( 'data-attachment_id' ), 10 );
			if ( attId > 0 && $.inArray( attId, ids ) === -1 ) {
				ids.push( attId );
			}
		} );

		$input.val( ids.join( ',' ) ).trigger( 'change' );

		// Update optional count badge in variation header.
		var $countBadge = $wrapper.find( '.wvgs-gallery-count' );
		if ( $countBadge.length ) {
			$countBadge.text( '(' + ids.length + ')' );
		}

		// Toggle Clear / Clear All link visibility.
		var $clearBtn = $wrapper.find( '.wvgs-clear-variation-gallery' );
		if ( $clearBtn.length ) {
			if ( ids.length > 0 ) {
				$clearBtn.show();
			} else {
				$clearBtn.hide();
			}
		}

		markVariationNeedsUpdate( $wrapper );
	}

	/**
	 * Initialize jQuery UI Sortable on all variation and attribute-level gallery lists.
	 *
	 * @param {jQuery} [$scope] Optional DOM scope to limit initialization.
	 * @return {void}
	 */
	function initSortableGalleries( $scope ) {
		if ( typeof $.fn.sortable !== 'function' ) {
			return;
		}

		var $root = ( $scope && $scope.length ) ? $scope : $( document );

		$root.find( '.wvgs-variation-gallery-images' ).each( function () {
			var $list = $( this );

			try {
				if ( $list.hasClass( 'ui-sortable' ) ) {
					$list.sortable( 'destroy' );
				}

				$list.sortable( {
					items: 'li.image',
					cursor: 'move',
					scrollSensitivity: 40,
					forcePlaceholderSize: true,
					forceHelperSize: false,
					helper: 'clone',
					opacity: 0.65,
					placeholder: 'wc-metabox-sortable-placeholder',
					start: function ( event, ui ) {
						ui.item.css( 'background-color', '#f6f7f7' );
					},
					stop: function ( event, ui ) {
						ui.item.removeAttr( 'style' );
					},
					update: function () {
						syncGalleryContainer( $list );
					}
				} );
			} catch ( err ) {
				// Prevent sortable conflicts from interrupting admin UI.
			}
		} );
	}

	/**
	 * Initialize WP Color Picker widgets safely without double-wrapping already initialized inputs.
	 *
	 * @param {jQuery} [$scope] Optional DOM scope.
	 * @return {void}
	 */
	function initColorPickers( $scope ) {
		if ( typeof $.fn.wpColorPicker !== 'function' ) {
			return;
		}

		var $root = ( $scope && $scope.length ) ? $scope : $( document );

		$root.find( '.wvgs-color-picker' ).each( function () {
			var $input = $( this );
			if ( $input.hasClass( 'wp-color-picker' ) ) {
				return;
			}
			try {
				$input.wpColorPicker();
			} catch ( err ) {
				// Ignore color picker initialization errors in non-standard contexts.
			}
		} );
	}

	/**
	 * Cache initial inherited `data-effective-type` on each Product Data attribute table so
	 * switching back to "Inherit Global Default" restores the exact inherited CSS visibility.
	 *
	 * @param {jQuery} [$scope] Optional DOM scope.
	 * @return {void}
	 */
	function cacheDefaultEffectiveTypes( $scope ) {
		var $root = ( $scope && $scope.length ) ? $scope : $( document );

		$root.find( '.wvgs-terms-table' ).each( function () {
			var $table = $( this );
			if ( ! $table.data( 'default-effective-type' ) ) {
				$table.data( 'default-effective-type', $table.attr( 'data-effective-type' ) || 'select' );
			}
		} );
	}

	/**
	 * Sync visibility of Dual-Color fields on Global Attribute Term Add/Edit screens.
	 *
	 * @return {void}
	 */
	function syncTermDualColorFields() {
		var $checkbox = $( '#wvgs_is_dual_color' );
		if ( ! $checkbox.length ) {
			return;
		}

		var isDual = $checkbox.is( ':checked' );
		$( '.wvgs-term-secondary-color-wrap, .wvgs-term-dual-angle-wrap' ).toggle( isDual );
	}

	/**
	 * Sync visibility of Tooltip Text vs. Tooltip Image fields on Global Attribute Term screens.
	 *
	 * @return {void}
	 */
	function syncTermTooltipFields() {
		var $select = $( '#wvgs_tooltip_type' );
		if ( ! $select.length ) {
			return;
		}

		var type = String( $select.val() || 'text' );
		$( '.wvgs-term-tooltip-text-wrap' ).toggle( type === 'text' );
		$( '.wvgs-term-tooltip-image-wrap' ).toggle( type === 'image' );
	}

	/**
	 * Refresh the `Product Data > Swatches & Gallery` attributes container via AJAX.
	 *
	 * @param {number} productId Parent variable product ID.
	 * @return {void}
	 */
	function refreshProductAttributesPanel( productId ) {
		var $container = $( '#wvgs-product-attributes-container' );
		if ( ! $container.length || ! productId || ! adminData.ajaxUrl ) {
			return;
		}

		var $refreshBtn = $( '#wvgs-refresh-attributes' );
		$container.addClass( 'wvgs-is-loading' );
		$refreshBtn.prop( 'disabled', true );

		$.ajax( {
			url: adminData.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'wvgs_load_product_attribute_swatches',
				nonce: adminData.nonce,
				product_id: productId
			}
		} )
			.done( function ( response ) {
				if ( response && response.success && response.data && typeof response.data.html === 'string' ) {
					$container.html( response.data.html );
					cacheDefaultEffectiveTypes( $container );
					initColorPickers( $container );
					initSortableGalleries( $container );
				}
			} )
			.always( function () {
				$container.removeClass( 'wvgs-is-loading' );
				$refreshBtn.prop( 'disabled', false );
			} );
	}

	/**
	 * Document Ready — Bind all delegated Admin UI handlers.
	 */
	$( function () {
		// Initial setup on page load.
		cacheDefaultEffectiveTypes();
		initColorPickers();
		initSortableGalleries();
		syncTermDualColorFields();
		syncTermTooltipFields();

		// =========================================================================
		// 1. Multi-Image Gallery WP Media Uploader (Variations + Attribute Galleries)
		// =========================================================================
		$( document ).on( 'click', '.wvgs-add-variation-gallery-image', function ( e ) {
			e.preventDefault();

			if ( typeof wp === 'undefined' || ! wp.media ) {
				return;
			}

			var $button  = $( this );
			var $wrapper = $button.closest( '.wvgs-variation-gallery-wrapper, .wvgs-mini-gallery' );
			var $list    = $wrapper.find( '.wvgs-variation-gallery-images' ).first();
			var $input   = $wrapper.find( '.wvgs-variation-gallery-ids' ).first();

			var frameTitle  = $button.attr( 'data-choose' ) || i18n.selectImages;
			var frameButton = $button.attr( 'data-update' ) || i18n.addToGallery;

			try {
				var frame = wp.media( {
					title: frameTitle,
					button: { text: frameButton },
					library: { type: 'image' },
					multiple: 'add'
				} );

				frame.on( 'select', function () {
					var selection   = frame.state().get( 'selection' );
					var existingIds = $input.val()
						? String( $input.val() ).split( ',' ).map( function ( v ) { return parseInt( v, 10 ); } ).filter( Boolean )
						: [];

					selection.each( function ( model ) {
						var attachment = model.toJSON();
						var attId      = parseInt( attachment.id, 10 );

						if ( ! attId || $.inArray( attId, existingIds ) !== -1 ) {
							return;
						}

						existingIds.push( attId );

						var thumbUrl = ( attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url )
							? attachment.sizes.thumbnail.url
							: ( attachment.url || '' );

						var itemHtml =
							'<li class="image" data-attachment_id="' + escapeHtml( attId ) + '">' +
								'<img src="' + escapeHtml( thumbUrl ) + '" alt="" />' +
								'<a href="#" class="delete wvgs-remove-variation-gallery-image" title="Remove image">' +
									'<span class="dashicons dashicons-dismiss"></span>' +
								'</a>' +
							'</li>';

						$list.append( itemHtml );
					} );

					syncGalleryContainer( $list );
					initSortableGalleries( $wrapper );
				} );

				frame.open();
			} catch ( err ) {
				// Gracefully fail if WP Media modal cannot open.
			}
		} );

		// Remove single image from Variation or Attribute-Level Gallery.
		$( document ).on( 'click', '.wvgs-remove-variation-gallery-image', function ( e ) {
			e.preventDefault();

			var $item = $( this ).closest( 'li.image' );
			var $list = $item.closest( '.wvgs-variation-gallery-images' );

			$item.remove();
			syncGalleryContainer( $list );
		} );

		// Clear All images from Variation or Attribute-Level Gallery.
		$( document ).on( 'click', '.wvgs-clear-variation-gallery', function ( e ) {
			e.preventDefault();

			var $wrapper = $( this ).closest( '.wvgs-variation-gallery-wrapper, .wvgs-mini-gallery' );
			var $list    = $wrapper.find( '.wvgs-variation-gallery-images' ).first();

			$list.empty();
			syncGalleryContainer( $list );
		} );

		// Re-initialize sortables whenever WooCommerce dynamically loads or adds variation rows.
		$( '#woocommerce-product-data' ).on( 'woocommerce_variations_loaded woocommerce_variations_added', function () {
			initSortableGalleries( $( '#variable_product_options' ) );
		} );

		// =========================================================================
		// 2. Single-Image WP Media Picker (Term Swatches, Tooltips & Product Overrides)
		// =========================================================================
		$( document ).on( 'click', '.wvgs-upload-image-btn', function ( e ) {
			e.preventDefault();

			if ( typeof wp === 'undefined' || ! wp.media ) {
				return;
			}

			var $btn     = $( this );
			var $wrap    = $btn.closest( '.wvgs-term-image-wrap' );
			var $input   = $wrap.find( '.wvgs-media-id-input' ).first();
			var $preview = $wrap.find( '.wvgs-term-image-preview' ).first();
			var $remove  = $wrap.find( '.wvgs-remove-image-btn' ).first();

			try {
				var frame = wp.media( {
					title: i18n.selectSwatch,
					button: { text: i18n.useImage },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					var attId      = parseInt( attachment.id, 10 );

					if ( ! attId ) {
						return;
					}

					var thumbUrl = ( attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url )
						? attachment.sizes.thumbnail.url
						: ( attachment.url || '' );

					$input.val( attId ).trigger( 'change' );
					$preview.html( '<img src="' + escapeHtml( thumbUrl ) + '" alt="" />' );
					$remove.show();
				} );

				frame.open();
			} catch ( err ) {
				// Ignore media frame exceptions.
			}
		} );

		$( document ).on( 'click', '.wvgs-remove-image-btn', function ( e ) {
			e.preventDefault();

			var $btn     = $( this );
			var $wrap    = $btn.closest( '.wvgs-term-image-wrap' );
			var $input   = $wrap.find( '.wvgs-media-id-input' ).first();
			var $preview = $wrap.find( '.wvgs-term-image-preview' ).first();

			$input.val( '' ).trigger( 'change' );
			$preview.empty();
			$btn.hide();
		} );

		// =========================================================================
		// 3. Global Attribute Term Screen Toggles & AJAX `add-tag` Reset
		// =========================================================================
		$( document ).on( 'change', '#wvgs_is_dual_color', syncTermDualColorFields );
		$( document ).on( 'change', '#wvgs_tooltip_type', syncTermTooltipFields );

		// Reset Add New Term fields cleanly after WordPress creates a term via AJAX.
		$( document ).ajaxComplete( function ( event, xhr, settings ) {
			if ( ! xhr || xhr.status !== 200 || ! settings || ! settings.data ) {
				return;
			}

			var requestData = String( settings.data );

			// A. Reset Add Term form fields on `edit-tags.php`.
			if ( requestData.indexOf( 'action=add-tag' ) !== -1 && $( '#addtag' ).length ) {
				$( '#addtag .wvgs-color-picker' ).each( function () {
					var $picker = $( this );
					$picker.val( '' );
					$picker.closest( '.wp-picker-container' ).find( '.wp-picker-clear' ).trigger( 'click' );
				} );

				$( '#addtag .wvgs-media-id-input' ).val( '' );
				$( '#addtag .wvgs-term-image-preview' ).empty();
				$( '#addtag .wvgs-remove-image-btn' ).hide();
				$( '#addtag #wvgs_tooltip_text' ).val( '' );
				$( '#addtag #wvgs_tooltip_type' ).val( 'text' );
				$( '#addtag #wvgs_dual_angle' ).val( '45' );

				syncTermDualColorFields();
				syncTermTooltipFields();
			}

			// B. Auto-refresh Product Data > Swatches & Gallery tab when WooCommerce saves attributes via AJAX.
			if ( requestData.indexOf( 'action=woocommerce_save_attributes' ) !== -1 && $( '#wvgs-product-attributes-container' ).length ) {
				var pid = resolveProductId( $( '#wvgs-product-attributes-container' ) );
				if ( pid > 0 ) {
					refreshProductAttributesPanel( pid );
				}
			}
		} );

		// =========================================================================
		// 4. Product Data > Swatches & Gallery Accordions & Live Controls
		// =========================================================================

		// Toggle attribute accordion open/collapsed.
		$( document ).on( 'click', '.wvgs-attr-header', function () {
			$( this ).closest( '.wvgs-attr-accordion' ).toggleClass( 'is-collapsed' );
		} );

		// Live Attribute Swatch Type switching inside Product Data tab.
		$( document ).on( 'change', '.wvgs-attr-type-selector', function () {
			var $select     = $( this );
			var $accordion  = $select.closest( '.wvgs-attr-accordion' );
			var $table      = $accordion.find( '.wvgs-terms-table' ).first();
			var chosenType  = String( $select.val() || '' );
			var defaultType = String( $table.data( 'default-effective-type' ) || 'select' );
			var effective   = chosenType !== '' ? chosenType : defaultType;

			$table.attr( 'data-effective-type', effective );

			// Auto-toggle dual color secondary inputs if user selects Dual Color Swatch.
			if ( effective === 'dual_color' ) {
				$table.find( '.wvgs-row-dual-checkbox' ).each( function () {
					var $cb = $( this );
					if ( ! $cb.is( ':checked' ) ) {
						$cb.prop( 'checked', true ).trigger( 'change' );
					}
				} );
			}

			initColorPickers( $table );
		} );

		// Per-row Dual Color checkbox toggle inside Product Data > Swatches & Gallery.
		$( document ).on( 'change', '.wvgs-row-dual-checkbox', function () {
			var $cb  = $( this );
			var $row = $cb.closest( '.wvgs-swatch-field-color' ).find( '.wvgs-row-secondary-color' );

			if ( $cb.is( ':checked' ) ) {
				$row.css( 'display', 'flex' );
				initColorPickers( $row );
			} else {
				$row.hide();
			}
		} );

		// Visual feedback when toggling "Lock Static Parent Image".
		$( document ).on( 'change', '#_wvgs_lock_parent_image', function () {
			$( '#wvgs-stock-winner-banner' ).toggleClass( 'is-locked', $( this ).is( ':checked' ) );
		} );

		// Manual "Refresh Attributes" button.
		$( document ).on( 'click', '#wvgs-refresh-attributes', function ( e ) {
			e.preventDefault();
			var pid = resolveProductId( $( this ) );
			refreshProductAttributesPanel( pid );
		} );

		// =========================================================================
		// 5. Live AJAX Stock Winner Recalculation Button
		// =========================================================================
		$( document ).on( 'click', '#wvgs-recalculate-stock-winner', function ( e ) {
			e.preventDefault();

			var $btn       = $( this );
			var productId  = resolveProductId( $btn );
			var $banner    = $( '#wvgs-stock-winner-banner' );
			var $details   = $( '#wvgs-winner-details-text' );
			var $thumbWrap = $banner.find( '.wvgs-winner-thumb' );
			var $pill      = $banner.find( '.wvgs-status-pill' );

			if ( ! productId || ! adminData.ajaxUrl ) {
				return;
			}

			$btn.prop( 'disabled', true ).text( i18n.recalculating );

			$.ajax( {
				url: adminData.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'wvgs_recalculate_stock_winner',
					nonce: adminData.nonce,
					product_id: productId
				}
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success || ! response.data ) {
						var errMsg = ( response && response.data && response.data.message )
							? response.data.message
							: 'Unable to recalculate stock winner.';
						$details.text( errMsg );
						return;
					}

					var d = response.data;

					if ( d.thumbnail_url ) {
						$thumbWrap.html( '<img src="' + escapeHtml( d.thumbnail_url ) + '" alt="" />' );
					}

					if ( parseInt( d.winner_variation_id, 10 ) > 0 ) {
						var stockStr = ( d.winner_stock_qty !== null && d.winner_stock_qty !== undefined )
							? 'Stock: ' + d.winner_stock_qty
							: 'In Stock';

						var summary = 'Current Winner: ' +
							( d.winner_label || ( '#' + d.winner_variation_id ) ) +
							' (#' + d.winner_variation_id + ') — ' +
							stockStr + ' — ' +
							( parseInt( d.gallery_count, 10 ) || 0 ) + ' Additional Gallery Images';

						$details.text( summary );

						if ( ! $( '#_wvgs_lock_parent_image' ).is( ':checked' ) ) {
							$pill.removeClass( 'is-neutral is-warning' ).addClass( 'is-active' ).text( 'Dynamic Stock Winner Active' );
						}
					} else {
						$details.text( d.message || 'No in-stock variations with images found.' );
						$pill.removeClass( 'is-active' ).addClass( 'is-neutral' ).text( 'Pending Variations' );
					}
				} )
				.fail( function () {
					$details.text( 'Request failed while recalculating stock winner. Please try again.' );
				} )
				.always( function () {
					$btn.prop( 'disabled', false ).text( i18n.recalculateBtn );
				} );
		} );
	} );
} )( jQuery );
