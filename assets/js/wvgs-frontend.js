/**
 * Woo Variation & Gallery Studio (Flatsome Edition) — Frontend Controller
 *
 * Modules:
 * 1. Utilities & Safe Escaping (XSS-safe attribute escaping, URL protocol guards, JSON parsing)
 * 2. Single Product Page (PDP) & Flatsome Quick View Swatch State Machine
 *    - WAI-ARIA radiogroup click & keyboard navigation (Arrow keys, Home/End, Space/Enter)
 *    - Smart In-Stock Auto-Fallback (automatically picks highest-stock sibling size if unavailable)
 *    - Real-time stock matrix evaluation (`woocommerce_update_variation_values`)
 *    - Partial-attribute gallery preview & Live URL deep-link sync (`history.replaceState`)
 *    - Optional Flatsome header price synchronization (`.price-wrapper`)
 * 3. CLS-Free Flatsome Flickity Gallery Bridge & Interactive Video Slide Player
 *    - Zero-flash initial load (skips redundant Flickity rebuild on first SSR paint)
 *    - Image preloading + min-height lock to prevent Cumulative Layout Shift (CLS)
 *    - Inline MP4/WebM, YouTube (youtube-nocookie), and Vimeo video playback with auto-pause on slide change
 *    - Flatsome Quick View variation handoff from shop archive cards
 * 4. Flatsome Shop Archive Swatches & 3-Mode Hover / Slideshow Engine
 *    - Mode 1: Active Variation's 2nd Image (`variation_gallery`)
 *    - Mode 2: Next Available Variation's Main Image (`next_variation`)
 *    - Mode 3: Deduplicated Variation Slideshow (`variation_slideshow`) with progress bar,
 *      live swatch sync, and attributed deep-link URL rewriting
 *
 * @package WooVariationGalleryStudio
 */

( function ( $ ) {
	'use strict';

	var configData = window.wvgsFrontendData || {
		syncHeaderPrice: true,
		outOfStockBehavior: 'cross',
		enableTooltips: true,
		swatchShape: 'rounded',
		i18n: {}
	};

	var i18n = $.extend(
		{
			outOfStock: 'Out of stock',
			playVideo: 'Play product video'
		},
		configData.i18n || {}
	);

	// Stores selected variation query params when opening Flatsome Quick View from a shop card.
	var pendingQuickViewParams = null;

	// =========================================================================
	// 1. Utilities & Safe Escaping
	// =========================================================================

	/**
	 * Escape raw text for safe interpolation into HTML attributes and text nodes.
	 *
	 * @param {*} value Raw value.
	 * @return {string}
	 */
	function escapeAttr( value ) {
		return String( value === null || value === undefined ? '' : value )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	}

	/**
	 * Validate that a URL uses an allowed `http:` or `https:` protocol (blocks `javascript:` / `data:`).
	 *
	 * @param {*} rawUrl Candidate URL.
	 * @return {string} Safe URL string or empty string if invalid.
	 */
	function sanitizeUrl( rawUrl ) {
		var str = String( rawUrl || '' ).trim();
		if ( ! str ) {
			return '';
		}
		if ( str.charAt( 0 ) === '/' && str.charAt( 1 ) !== '/' ) {
			return str;
		}
		try {
			var parsed = new URL( str, window.location.origin );
			if ( parsed.protocol === 'http:' || parsed.protocol === 'https:' ) {
				return parsed.href;
			}
		} catch ( err ) {
			return '';
		}
		return '';
	}

	/**
	 * Safely parse a JSON payload from an inert `<script type="application/json">` element.
	 *
	 * @param {jQuery} $scriptEl Script tag carrying JSON text.
	 * @return {Object|null}
	 */
	function parseJsonScript( $scriptEl ) {
		if ( ! $scriptEl || ! $scriptEl.length ) {
			return null;
		}
		var raw = $scriptEl.text();
		if ( ! raw ) {
			return null;
		}
		try {
			return JSON.parse( raw );
		} catch ( err ) {
			return null;
		}
	}

	/**
	 * Check whether the user prefers reduced motion.
	 *
	 * @return {boolean}
	 */
	function prefersReducedMotion() {
		return Boolean(
			window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
		);
	}

	/**
	 * Find the product scope wrapper (Quick View modal or Single Product main container)
	 * associated with a `.variations_form`.
	 *
	 * @param {jQuery} $form Variations form element.
	 * @return {jQuery}
	 */
	function getProductScope( $form ) {
		var $quickView = $form.closest( '.product-quick-view-container' );
		if ( $quickView.length ) {
			return $quickView;
		}
		var $main = $form.closest( '.product-main' );
		if ( $main.length ) {
			return $main;
		}
		var $product = $form.closest( '.product' );
		if ( $product.length ) {
			return $product;
		}
		return $( document );
	}

	/**
	 * Build a deterministic signature for an array of gallery slide objects so identical
	 * galleries are never redundantly destroyed and rebuilt.
	 *
	 * @param {Array<Object>} slides Gallery slide objects.
	 * @return {string}
	 */
	function buildGallerySignature( slides ) {
		if ( ! Array.isArray( slides ) || ! slides.length ) {
			return '';
		}
		return slides
			.map( function ( s ) {
				var vid = ( s.video && s.video.has_video ) ? 'v' : 'i';
				return String( s.id || s.src || '' ) + ':' + vid;
			} )
			.join( '|' );
	}

	/**
	 * Retrieve cached form gallery payload emitted by `WVGS_Flatsome_Gallery::output_form_gallery_payload()`.
	 *
	 * @param {jQuery} $form Variations form element.
	 * @return {Object|null}
	 */
	function getFormPayload( $form ) {
		var cached = $form.data( 'wvgs-form-payload' );
		if ( cached !== undefined ) {
			return cached;
		}
		var parsed = parseJsonScript( $form.find( 'script.wvgs-product-gallery-payload' ).first() );
		if ( ! parsed ) {
			parsed = parseJsonScript( $form.prev( 'script.wvgs-product-gallery-payload' ) );
		}
		$form.data( 'wvgs-form-payload', parsed || null );
		return parsed || null;
	}

	/**
	 * Retrieve WooCommerce's preloaded `product_variations` array from a form.
	 *
	 * @param {jQuery} $form Variations form element.
	 * @return {Array<Object>}
	 */
	function getFormVariations( $form ) {
		var variations = $form.data( 'product_variations' );
		return Array.isArray( variations ) ? variations : [];
	}

	/**
	 * Check whether a variation object matches a map of selected attribute key/value pairs.
	 *
	 * @param {Object} variation    WooCommerce variation object.
	 * @param {Object} selectedMap  Map of `attribute_name -> value`.
	 * @return {boolean}
	 */
	function variationMatchesAttributes( variation, selectedMap ) {
		if ( ! variation || ! variation.attributes ) {
			return false;
		}
		var keys = Object.keys( selectedMap );
		for ( var i = 0; i < keys.length; i++ ) {
			var attrName = keys[ i ];
			var wanted   = String( selectedMap[ attrName ] || '' );
			if ( ! wanted ) {
				continue;
			}
			var varVal = String( variation.attributes[ attrName ] || '' );
			// Empty string on variation means WooCommerce wildcard ("Any ...").
			if ( varVal !== '' && varVal !== wanted ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Check if a variation is purchasable and available in stock.
	 *
	 * @param {Object} variation WooCommerce variation object.
	 * @return {boolean}
	 */
	function isVariationAvailable( variation ) {
		if ( ! variation ) {
			return false;
		}
		if ( variation.variation_is_active === false || variation.variation_is_visible === false ) {
			return false;
		}
		if ( variation.is_in_stock === false || variation.wvgs_stock_status === 'outofstock' ) {
			return false;
		}
		return true;
	}

	/**
	 * Rank two available variations highest-stock first.
	 *
	 * @param {Object} a First variation.
	 * @param {Object} b Second variation.
	 * @return {number}
	 */
	function compareVariationStockDesc( a, b ) {
		var qtyA = ( a.wvgs_stock_qty !== null && a.wvgs_stock_qty !== undefined ) ? parseInt( a.wvgs_stock_qty, 10 ) : -1;
		var qtyB = ( b.wvgs_stock_qty !== null && b.wvgs_stock_qty !== undefined ) ? parseInt( b.wvgs_stock_qty, 10 ) : -1;

		if ( qtyA !== qtyB ) {
			return qtyB - qtyA;
		}
		return 0;
	}

	// =========================================================================
	// 2. Single Product Page (PDP) & Quick View Swatch State Machine
	// =========================================================================

	/**
	 * Synchronize visual `.selected`, `aria-checked`, and roving `tabindex` states across
	 * all swatch rows inside a `.variations_form` from the underlying hidden `<select>` values.
	 *
	 * @param {jQuery} $form Variations form element.
	 * @return {void}
	 */
	function syncSwatchesFromSelects( $form ) {
		$form.find( '.wvgs-swatches-wrapper' ).each( function () {
			var $wrapper    = $( this );
			var selectName  = $wrapper.attr( 'data-attribute_name' );
			var $select     = $wrapper.siblings( 'select' ).first();
			if ( ! $select.length && selectName ) {
				$select = $form.find( 'select[name="' + selectName + '"]' ).first();
			}
			if ( ! $select.length ) {
				return;
			}

			var currentVal     = String( $select.val() || '' );
			var $items         = $wrapper.find( '.wvgs-swatch-item' );
			var hasSelectedTab = false;

			$items.each( function () {
				var $item     = $( this );
				var itemVal   = String( $item.attr( 'data-value' ) || '' );
				var isCurrent = ( currentVal !== '' && itemVal === currentVal );

				$item
					.toggleClass( 'selected', isCurrent )
					.attr( 'aria-checked', isCurrent ? 'true' : 'false' )
					.attr( 'tabindex', isCurrent ? '0' : '-1' );

				if ( isCurrent ) {
					hasSelectedTab = true;
				}
			} );

			if ( ! hasSelectedTab ) {
				var $firstEnabled = $items.not( '.disabled' ).first();
				if ( $firstEnabled.length ) {
					$firstEnabled.attr( 'tabindex', '0' );
				} else {
					$items.first().attr( 'tabindex', '0' );
				}
			}
		} );
	}

	/**
	 * Evaluate stock availability across all swatch rows inside a `.variations_form` and
	 * apply `.disabled` + `aria-disabled="true"` to out-of-stock or non-existent combinations.
	 *
	 * @param {jQuery} $form Variations form element.
	 * @return {void}
	 */
	function updateFormSwatchStockMatrix( $form ) {
		var variations = getFormVariations( $form );
		var selected   = {};

		$form.find( 'select[data-attribute_name], select[name^="attribute_"]' ).each( function () {
			var $sel = $( this );
			var name = $sel.attr( 'data-attribute_name' ) || $sel.attr( 'name' );
			if ( name ) {
				selected[ name ] = String( $sel.val() || '' );
			}
		} );

		$form.find( '.wvgs-swatches-wrapper' ).each( function () {
			var $wrapper   = $( this );
			var selectName = $wrapper.attr( 'data-attribute_name' );
			var $select    = $wrapper.siblings( 'select' ).first();

			if ( ! $select.length && selectName ) {
				$select = $form.find( 'select[name="' + selectName + '"]' ).first();
			}

			$wrapper.find( '.wvgs-swatch-item' ).each( function () {
				var $item   = $( this );
				var val     = String( $item.attr( 'data-value' ) || '' );
				var enabled = true;

				// 1. Check WooCommerce's filtered <select> options first.
				if ( $select.length ) {
					var $opt = $select.find( 'option' ).filter( function () {
						return String( $( this ).val() ) === val;
					} );
					if ( ! $opt.length || $opt.is( ':disabled' ) ) {
						enabled = false;
					}
				}

				// 2. When variation JSON is preloaded (within ajax_variation_threshold),
				// verify at least one in-stock variation exists for this option + other chosen attributes.
				if ( enabled && variations.length && selectName ) {
					var testCombo = $.extend( {}, selected );
					testCombo[ selectName ] = val;

					var hasInStockMatch = false;
					for ( var i = 0; i < variations.length; i++ ) {
						if ( isVariationAvailable( variations[ i ] ) && variationMatchesAttributes( variations[ i ], testCombo ) ) {
							hasInStockMatch = true;
							break;
						}
					}

					if ( ! hasInStockMatch ) {
						enabled = false;
					}
				}

				$item
					.toggleClass( 'disabled', ! enabled )
					.attr( 'aria-disabled', enabled ? 'false' : 'true' );
			} );
		} );

		syncSwatchesFromSelects( $form );
	}

	/**
	 * Smart In-Stock Auto-Fallback:
	 * When a shopper clicks an option (e.g., Color: White) whose combination with the currently
	 * selected sibling attribute (e.g., Size: Small) is out of stock or non-existent, automatically
	 * resolve the highest-stock available sibling option (e.g., Size: Large) so the customer never
	 * hits a dead-end "Sorry, no products matched your selection" message.
	 *
	 * @param {jQuery} $form         Variations form element.
	 * @param {string} clickedAttr   Attribute name just clicked (e.g., `attribute_pa_color`).
	 * @param {string} clickedValue  Attribute value just clicked (e.g., `white`).
	 * @return {boolean} True if sibling selects were auto-adjusted.
	 */
	function maybeSmartFallbackSiblings( $form, clickedAttr, clickedValue ) {
		var variations = getFormVariations( $form );
		if ( ! variations.length || ! clickedAttr || ! clickedValue ) {
			return false;
		}

		// Build current desired attribute combination.
		var desired = {};
		$form.find( 'select[data-attribute_name], select[name^="attribute_"]' ).each( function () {
			var $sel = $( this );
			var name = $sel.attr( 'data-attribute_name' ) || $sel.attr( 'name' );
			if ( name ) {
				desired[ name ] = ( name === clickedAttr ) ? clickedValue : String( $sel.val() || '' );
			}
		} );

		// If the current combination already has an in-stock match, no fallback is needed.
		for ( var i = 0; i < variations.length; i++ ) {
			if ( isVariationAvailable( variations[ i ] ) && variationMatchesAttributes( variations[ i ], desired ) ) {
				return false;
			}
		}

		// Otherwise, find all in-stock variations matching the newly clicked attribute alone.
		var candidates = [];
		for ( var j = 0; j < variations.length; j++ ) {
			var v      = variations[ j ];
			var vVal   = ( v && v.attributes ) ? String( v.attributes[ clickedAttr ] || '' ) : '';
			if ( isVariationAvailable( v ) && ( vVal === clickedValue || vVal === '' ) ) {
				candidates.push( v );
			}
		}

		if ( ! candidates.length ) {
			return false;
		}

		// Pick the highest-stock available variation for the newly clicked attribute.
		candidates.sort( compareVariationStockDesc );
		var bestVariation = candidates[ 0 ];
		var adjusted      = false;

		$form.find( 'select[data-attribute_name], select[name^="attribute_"]' ).each( function () {
			var $sel = $( this );
			var name = $sel.attr( 'data-attribute_name' ) || $sel.attr( 'name' );
			if ( ! name || name === clickedAttr ) {
				return;
			}
			var targetVal = ( bestVariation.attributes && bestVariation.attributes[ name ] !== undefined )
				? String( bestVariation.attributes[ name ] || '' )
				: '';

			if ( targetVal !== '' && String( $sel.val() || '' ) !== targetVal ) {
				// Temporarily ensure option exists (WooCommerce may have filtered it while prior color was active).
				if ( $sel.find( 'option[value="' + targetVal.replace( /"/g, '\\"' ) + '"]' ).length ) {
					$sel.val( targetVal );
					adjusted = true;
				}
			} else if ( targetVal === '' ) {
				// Wildcard sibling: keep existing selection if non-empty, otherwise pick first real option.
				if ( ! $sel.val() ) {
					var firstOpt = $sel.find( 'option[value!=""]' ).first().val();
					if ( firstOpt ) {
						$sel.val( firstOpt );
						adjusted = true;
					}
				}
			}
		} );

		return adjusted;
	}

	/**
	 * Handle selecting a single PDP / Quick View swatch item (via click or keyboard).
	 *
	 * @param {jQuery} $swatch Clicked or activated `.wvgs-swatch-item`.
	 * @return {void}
	 */
	function activatePdpSwatch( $swatch ) {
		if ( ! $swatch.length || $swatch.attr( 'aria-disabled' ) === 'true' || $swatch.hasClass( 'disabled' ) ) {
			return;
		}

		var $wrapper   = $swatch.closest( '.wvgs-swatches-wrapper' );
		var $form      = $swatch.closest( '.variations_form' );
		var selectName = $wrapper.attr( 'data-attribute_name' );
		var value      = String( $swatch.attr( 'data-value' ) || '' );
		var $select    = $wrapper.siblings( 'select' ).first();

		if ( ! $select.length && selectName ) {
			$select = $form.find( 'select[name="' + selectName + '"]' ).first();
		}
		if ( ! $select.length ) {
			return;
		}

		// Mark that the user explicitly interacted with this form so gallery swaps always execute.
		$form.data( 'wvgs-user-interacted', true );

		// Toggle selection off if clicking an already-selected swatch when user wants to clear.
		var isAlreadySelected = $swatch.hasClass( 'selected' ) && String( $select.val() ) === value;
		if ( isAlreadySelected ) {
			return;
		}

		// Ensure the clicked value exists in <select> (re-triggers WC check_variations if cleared).
		var hasOption = $select.find( 'option' ).filter( function () {
			return String( $( this ).val() ) === value;
		} ).length > 0;

		if ( ! hasOption ) {
			// Clear conflicting sibling selects and let WooCommerce restore all options first.
			$form.find( 'select[name^="attribute_"]' ).not( $select ).val( '' );
			$form.trigger( 'check_variations' );
		}

		$select.val( value );

		// Smart fallback to highest-stock sibling size if the current combo is unavailable.
		maybeSmartFallbackSiblings( $form, selectName, value );

		// Sync visual swatch UI immediately and notify WooCommerce's variation engine.
		syncSwatchesFromSelects( $form );
		$select.trigger( 'change' );

		// Partial-Selection Instant Gallery Preview:
		// If another attribute is still unselected (so `found_variation` hasn't fired),
		// immediately preview this attribute's isolated gallery from the server payload.
		var hasEmptyAttr = false;
		$form.find( 'select[data-attribute_name], select[name^="attribute_"]' ).each( function () {
			if ( ! $( this ).val() ) {
				hasEmptyAttr = true;
			}
		} );

		if ( hasEmptyAttr ) {
			var payload = getFormPayload( $form );
			var lookup  = selectName + ':' + value;
			if ( payload && payload.attribute_galleries && Array.isArray( payload.attribute_galleries[ lookup ] ) && payload.attribute_galleries[ lookup ].length ) {
				updateFlatsomeGallery( payload.attribute_galleries[ lookup ], $form );
			}
		}
	}

	/**
	 * Sync Flatsome's top header price range (`.price-wrapper`) with the active variation's price.
	 *
	 * @param {jQuery} $form     Variations form element.
	 * @param {string} priceHtml Variation `price_html` (empty string restores original range).
	 * @return {void}
	 */
	function syncFlatsomeHeaderPrice( $form, priceHtml ) {
		if ( ! configData.syncHeaderPrice ) {
			return;
		}

		var $scope        = getProductScope( $form );
		var $priceWrapper = $scope.find( '.product-info .price-wrapper, .product-summary .price-wrapper' ).first();
		if ( ! $priceWrapper.length ) {
			return;
		}

		if ( $priceWrapper.data( 'wvgs-original-price' ) === undefined ) {
			$priceWrapper.data( 'wvgs-original-price', $priceWrapper.html() );
		}

		if ( priceHtml && String( priceHtml ).trim() !== '' ) {
			$priceWrapper.html( priceHtml );
			$form.find( '.woocommerce-variation-price' ).hide();
		} else {
			$priceWrapper.html( $priceWrapper.data( 'wvgs-original-price' ) );
			$form.find( '.woocommerce-variation-price' ).show();
		}
	}

	/**
	 * Silently sync browser URL query parameters (`?attribute_*`) on Single Product Pages
	 * without breaking Flatsome Quick View modals or adding noisy browser history entries.
	 *
	 * @param {jQuery} $form      Variations form element.
	 * @param {Object} attributes Variation attributes map.
	 * @return {void}
	 */
	function syncBrowserUrl( $form, attributes ) {
		if ( ! window.history || typeof window.history.replaceState !== 'function' ) {
			return;
		}
		// Never mutate the shop page URL while interacting inside a Quick View modal.
		if ( $form.closest( '.product-quick-view-container' ).length ) {
			return;
		}
		if ( ! attributes || typeof attributes !== 'object' ) {
			return;
		}

		try {
			var url = new URL( window.location.href );
			$.each( attributes, function ( attrName, attrVal ) {
				if ( attrName && String( attrName ).indexOf( 'attribute_' ) === 0 ) {
					if ( attrVal ) {
						url.searchParams.set( attrName, attrVal );
					} else {
						url.searchParams.delete( attrName );
					}
				}
			} );
			window.history.replaceState( null, '', url.toString() );
		} catch ( err ) {
			// Ignore URL mutation errors in restricted browser contexts.
		}
	}

	// =========================================================================
	// 3. CLS-Free Flatsome Flickity Gallery Bridge & Interactive Video Player
	// =========================================================================

	/**
	 * Pause and remove any active inline video player elements inside a gallery container.
	 *
	 * @param {jQuery} $container Gallery slider or product scope container.
	 * @return {void}
	 */
	function stopAllGalleryVideos( $container ) {
		$container.find( '.wvgs-video-player-container' ).each( function () {
			var $player = $( this );
			var videoEl = $player.find( 'video' ).get( 0 );
			if ( videoEl && typeof videoEl.pause === 'function' ) {
				try {
					videoEl.pause();
				} catch ( err ) {
					// Ignore media pause error.
				}
			}
			$player.siblings( '.wvgs-video-play-btn' ).show();
			$player.remove();
		} );
	}

	/**
	 * Launch an inline video player (MP4/WebM, YouTube, or Vimeo) inside a gallery slide.
	 *
	 * @param {jQuery} $button Triggered `.wvgs-video-play-btn`.
	 * @return {void}
	 */
	function playSlideVideo( $button ) {
		var rawPayload = $button.attr( 'data-wvgs-video' );
		if ( ! rawPayload ) {
			return;
		}

		var videoData = null;
		try {
			videoData = JSON.parse( rawPayload );
		} catch ( err ) {
			return;
		}

		if ( ! videoData || ! videoData.provider ) {
			return;
		}

		var safeEmbedUrl = sanitizeUrl( videoData.embed_url || videoData.url );
		if ( ! safeEmbedUrl ) {
			return;
		}

		var $slide  = $button.closest( '.slide, .woocommerce-product-gallery__image' );
		var $slider = $button.closest( '.product-gallery-slider, .woocommerce-product-gallery' );

		if ( ! $slide.length ) {
			return;
		}

		// Stop any other video currently playing in this slider.
		if ( $slider.length ) {
			stopAllGalleryVideos( $slider );
		}

		var playerMarkup = '';
		if ( videoData.provider === 'html5' ) {
			playerMarkup =
				'<video class="wvgs-video-html5" controls playsinline autoplay' +
				( videoData.autoplay ? ' muted' : '' ) +
				'>' +
				'<source src="' + escapeAttr( safeEmbedUrl ) + '" />' +
				'</video>';
		} else if ( videoData.provider === 'youtube' || videoData.provider === 'vimeo' ) {
			// Ensure autoplay=1 when user explicitly clicks Play.
			var iframeSrc = safeEmbedUrl;
			try {
				var u = new URL( safeEmbedUrl );
				u.searchParams.set( 'autoplay', '1' );
				iframeSrc = u.href;
			} catch ( err ) {
				iframeSrc = safeEmbedUrl;
			}

			playerMarkup =
				'<iframe class="wvgs-video-iframe" src="' + escapeAttr( iframeSrc ) + '"' +
				' allow="autoplay; fullscreen; picture-in-picture" allowfullscreen' +
				' referrerpolicy="strict-origin-when-cross-origin"></iframe>';
		}

		if ( ! playerMarkup ) {
			return;
		}

		var $playerWrap = $(
			'<div class="wvgs-video-player-container">' +
				playerMarkup +
				'<button type="button" class="wvgs-video-close-btn" aria-label="Close video">&times;</button>' +
			'</div>'
		);

		$button.hide();
		$slide.append( $playerWrap );
	}

	/**
	 * Build Flatsome-compatible main slide HTML and thumbnail strip HTML from `wvgs_gallery_images`.
	 *
	 * @param {Array<Object>} slides Strictly isolated variation slide payloads.
	 * @return {{sliderHtml: string, thumbHtml: string}}
	 */
	function buildFlatsomeGalleryHtml( slides ) {
		var sliderHtml = '';
		var thumbHtml  = '';

		$.each( slides, function ( index, slide ) {
			var isFirst    = ( index === 0 );
			var mainSrc    = sanitizeUrl( slide.src );
			var fullSrc    = sanitizeUrl( slide.full_src || slide.src );
			var thumbSrc   = sanitizeUrl( slide.gallery_thumbnail_src || slide.src );
			var altText    = escapeAttr( slide.alt || slide.title || '' );
			var titleAttr  = escapeAttr( slide.title || '' );
			var caption    = escapeAttr( slide.caption || '' );
			var srcSet     = escapeAttr( slide.srcset || '' );
			var sizesAttr  = escapeAttr( typeof slide.sizes === 'string' ? slide.sizes : '' );
			var fullW      = parseInt( slide.full_width, 10 ) || parseInt( slide.width, 10 ) || '';
			var fullH      = parseInt( slide.full_height, 10 ) || parseInt( slide.height, 10 ) || '';
			var widthAttr  = parseInt( slide.width, 10 ) || '';
			var heightAttr = parseInt( slide.height, 10 ) || '';
			var hasVideo   = Boolean( slide.video && slide.video.has_video && slide.video.provider );

			if ( ! mainSrc ) {
				return;
			}

			var slideClasses = 'woocommerce-product-gallery__image slide' +
				( isFirst ? ' first' : '' ) +
				( hasVideo ? ' wvgs-slide-has-video' : '' );

			var videoBtnHtml = '';
			if ( hasVideo ) {
				var videoJson = JSON.stringify( {
					provider: slide.video.provider,
					url: sanitizeUrl( slide.video.url ),
					embed_url: sanitizeUrl( slide.video.embed_url ),
					autoplay: Boolean( slide.video.autoplay )
				} );
				videoBtnHtml =
					'<button type="button" class="wvgs-video-play-btn"' +
					' data-wvgs-video="' + escapeAttr( videoJson ) + '"' +
					' aria-label="' + escapeAttr( i18n.playVideo ) + '">' +
					'<span class="wvgs-video-play-icon" aria-hidden="true"></span>' +
					'</button>';
			}

			sliderHtml +=
				'<div class="' + slideClasses + '" data-thumb="' + escapeAttr( thumbSrc ) + '" data-wvgs-attachment-id="' + escapeAttr( slide.id || '' ) + '">' +
					'<a href="' + escapeAttr( fullSrc ) + '">' +
						'<img src="' + escapeAttr( mainSrc ) + '"' +
						( widthAttr ? ' width="' + escapeAttr( widthAttr ) + '"' : '' ) +
						( heightAttr ? ' height="' + escapeAttr( heightAttr ) + '"' : '' ) +
						( srcSet ? ' srcset="' + srcSet + '"' : '' ) +
						( sizesAttr ? ' sizes="' + sizesAttr + '"' : '' ) +
						' alt="' + altText + '"' +
						( titleAttr ? ' title="' + titleAttr + '"' : '' ) +
						( caption ? ' data-caption="' + caption + '"' : '' ) +
						' data-src="' + escapeAttr( fullSrc ) + '"' +
						' data-large_image="' + escapeAttr( fullSrc ) + '"' +
						( fullW ? ' data-large_image_width="' + escapeAttr( fullW ) + '"' : '' ) +
						( fullH ? ' data-large_image_height="' + escapeAttr( fullH ) + '"' : '' ) +
						' class="' + ( isFirst ? 'wp-post-image skip-lazy' : 'skip-lazy' ) + '" />' +
					'</a>' +
					videoBtnHtml +
				'</div>';

			var thumbColClasses = 'col' + ( isFirst ? ' is-nav-selected first' : '' );
			var thumbInnerClass = 'col-inner' + ( hasVideo ? ' wvgs-thumb-has-video' : '' );
			var thumbSrcSet     = escapeAttr( slide.gallery_thumbnail_srcset || '' );
			var thumbW          = parseInt( slide.gallery_thumbnail_w, 10 ) || 100;
			var thumbH          = parseInt( slide.gallery_thumbnail_h, 10 ) || 100;

			thumbHtml +=
				'<div class="' + thumbColClasses + '">' +
					'<a class="' + thumbInnerClass + '" href="#" aria-label="' + altText + '">' +
						'<img src="' + escapeAttr( thumbSrc ) + '"' +
						( thumbSrcSet ? ' srcset="' + thumbSrcSet + '"' : '' ) +
						' alt="' + altText + '" width="' + escapeAttr( thumbW ) + '" height="' + escapeAttr( thumbH ) + '" class="attachment-woocommerce_thumbnail" />' +
						( hasVideo ? '<span class="wvgs-thumb-video-icon" aria-hidden="true"></span>' : '' ) +
					'</a>' +
				'</div>';
		} );

		return {
			sliderHtml: sliderHtml,
			thumbHtml: thumbHtml
		};
	}

	/**
	 * Rebuild Flatsome's `.product-gallery-slider` and `.product-thumbnails` cleanly with
	 * zero layout shift (CLS height lock + first-image preloading) and full Flickity re-attachment.
	 *
	 * @param {Array<Object>} slides Strictly isolated variation slides.
	 * @param {jQuery}        $form  Triggering `.variations_form`.
	 * @return {void}
	 */
	function updateFlatsomeGallery( slides, $form ) {
		if ( ! Array.isArray( slides ) || ! slides.length ) {
			return;
		}

		var $scope      = getProductScope( $form );
		var $slider     = $scope.find( '.product-gallery-slider' ).first();
		var $thumbnails = $scope.find( '.product-thumbnails' ).first();

		if ( ! $slider.length ) {
			return;
		}

		// Skip duplicate rebuilds if this exact slide set is already mounted.
		var nextSignature = buildGallerySignature( slides );
		if ( nextSignature && $slider.data( 'wvgs-gallery-signature' ) === nextSignature ) {
			return;
		}
		$slider.data( 'wvgs-gallery-signature', nextSignature );

		// Capture a swap sequence token so rapid swatch clicks only apply the latest swap.
		var swapSeq = ( parseInt( $slider.data( 'wvgs-swap-seq' ), 10 ) || 0 ) + 1;
		$slider.data( 'wvgs-swap-seq', swapSeq );

		var $galleryBox = $slider.closest( '.product-images, .woocommerce-product-gallery' );
		var $heightHost = $galleryBox.length ? $galleryBox : $slider.parent();

		// Lock container height to prevent Cumulative Layout Shift (CLS).
		var lockedHeight = $heightHost.outerHeight();
		if ( lockedHeight > 0 ) {
			$heightHost.css( 'min-height', lockedHeight + 'px' );
		}
		$slider.addClass( 'wvgs-gallery-transitioning' );

		function commitSwap() {
			if ( parseInt( $slider.data( 'wvgs-swap-seq' ), 10 ) !== swapSeq ) {
				return; // Superseded by a newer swatch click.
			}

			stopAllGalleryVideos( $slider );

			// Destroy active Flickity instances cleanly.
			try {
				if ( $slider.hasClass( 'flickity-enabled' ) && typeof $.fn.flickity === 'function' ) {
					$slider.flickity( 'destroy' );
				}
				if ( $thumbnails.length && $thumbnails.hasClass( 'flickity-enabled' ) && typeof $.fn.flickity === 'function' ) {
					$thumbnails.flickity( 'destroy' );
				}
			} catch ( err ) {
				// Continue DOM replacement even if Flickity was partially unmounted.
			}

			var built = buildFlatsomeGalleryHtml( slides );
			$slider.html( built.sliderHtml );

			if ( $thumbnails.length ) {
				$thumbnails.html( built.thumbHtml );
				// Hide thumbnail strip cleanly when a variation only has 1 image.
				if ( slides.length <= 1 ) {
					$thumbnails.hide();
				} else {
					$thumbnails.show();
				}
			}

			// Re-attach Flatsome Flickity, Zoom, and Lightbox behaviors.
			try {
				if ( typeof window.Flatsome !== 'undefined' && typeof window.Flatsome.attach === 'function' ) {
					window.Flatsome.attach( 'flickity', $slider.parent() );
					if ( $thumbnails.length && slides.length > 1 ) {
						window.Flatsome.attach( 'flickity', $thumbnails.parent() );
					}
					window.Flatsome.attach( 'lightboxes-link', $slider.parent() );
				} else if ( typeof $.fn.flickity === 'function' ) {
					var sliderOpts = $slider.attr( 'data-flickity-options' );
					$slider.flickity( sliderOpts ? JSON.parse( sliderOpts ) : { pageDots: false, adaptiveHeight: true } );
					if ( $thumbnails.length && slides.length > 1 ) {
						var thumbOpts = $thumbnails.attr( 'data-flickity-options' );
						$thumbnails.flickity( thumbOpts ? JSON.parse( thumbOpts ) : { asNavFor: '.product-gallery-slider', contain: true, pageDots: false } );
					}
				}
			} catch ( err ) {
				// Prevent theme JS quirks from breaking swatch interaction.
			}

			// Pause videos whenever shopper swipes to another slide.
			$slider.off( 'change.wvgsVideo' ).on( 'change.wvgsVideo', function () {
				stopAllGalleryVideos( $slider );
			} );

			// Trigger WooCommerce gallery event for third-party zoom/lightbox listeners.
			$scope.find( '.woocommerce-product-gallery' ).trigger( 'wvgs_gallery_updated', [ slides ] );

			// Release height lock smoothly once Flickity settles.
			$slider.removeClass( 'wvgs-gallery-transitioning' );
			setTimeout( function () {
				if ( parseInt( $slider.data( 'wvgs-swap-seq' ), 10 ) === swapSeq ) {
					$heightHost.css( 'min-height', '' );
					if ( $slider.hasClass( 'flickity-enabled' ) && typeof $.fn.flickity === 'function' ) {
						try {
							$slider.flickity( 'resize' );
						} catch ( err ) {
							// Ignore resize error.
						}
					}
				}
			}, 260 );
		}

		// Preload the first slide image briefly (max 180ms) for a flicker-free swap.
		var firstSrc = sanitizeUrl( slides[ 0 ].src );
		if ( firstSrc ) {
			var committed = false;
			var finish    = function () {
				if ( ! committed ) {
					committed = true;
					commitSwap();
				}
			};
			var preloader     = new Image();
			preloader.onload  = finish;
			preloader.onerror = finish;
			preloader.src     = firstSrc;
			setTimeout( finish, 180 );
		} else {
			commitSwap();
		}
	}

	/**
	 * Initialize a `.variations_form` (Single Product Page or Flatsome Quick View modal).
	 *
	 * @param {jQuery} $form Variations form element.
	 * @return {void}
	 */
	function initVariationForm( $form ) {
		if ( ! $form.length || $form.data( 'wvgs-initialized' ) ) {
			return;
		}
		$form.data( 'wvgs-initialized', true );

		var payload = getFormPayload( $form );
		var $scope  = getProductScope( $form );
		var $slider = $scope.find( '.product-gallery-slider' ).first();

		// Record the server-rendered variation ID so the initial `found_variation` event
		// fired by WooCommerce on load does not unnecessarily destroy & rebuild Flickity.
		if ( payload && payload.active_variation_id ) {
			$form.data( 'wvgs-ssr-variation-id', parseInt( payload.active_variation_id, 10 ) );
		}

		// Bind video auto-pause on initial SSR Flickity slider.
		if ( $slider.length ) {
			$slider.off( 'change.wvgsVideo' ).on( 'change.wvgsVideo', function () {
				stopAllGalleryVideos( $slider );
			} );
		}

		// Apply Quick View handoff attributes if the user clicked a swatch on a shop card before opening Quick View.
		if ( $form.closest( '.product-quick-view-container' ).length && pendingQuickViewParams ) {
			var qvParams           = pendingQuickViewParams;
			pendingQuickViewParams = null;
			var appliedAny         = false;

			$.each( qvParams, function ( attrName, attrVal ) {
				var $sel = $form.find( 'select[name="' + attrName + '"]' ).first();
				if ( $sel.length && $sel.find( 'option[value="' + String( attrVal ).replace( /"/g, '\\"' ) + '"]' ).length ) {
					$sel.val( attrVal );
					appliedAny = true;
				}
			} );

			if ( appliedAny ) {
				$form.data( 'wvgs-user-interacted', true );
				syncSwatchesFromSelects( $form );
				$form.find( 'select[name^="attribute_"]' ).first().trigger( 'change' );
			}
		}

		syncSwatchesFromSelects( $form );
		updateFormSwatchStockMatrix( $form );
	}

	// =========================================================================
	// 4. Flatsome Shop Archive Swatches & 3-Mode Hover / Slideshow Engine
	// =========================================================================

	/**
	 * Parse and cache the 3-mode archive hover JSON payload emitted by `WVGS_Archive_Loop`.
	 *
	 * @param {jQuery} $wrapper `.wvgs-archive-swatches-wrapper` element.
	 * @return {Object|null}
	 */
	function getArchiveHoverConfig( $wrapper ) {
		if ( ! $wrapper || ! $wrapper.length ) {
			return null;
		}
		var cached = $wrapper.data( 'wvgs-archive-config' );
		if ( cached !== undefined ) {
			return cached;
		}
		var parsed = parseJsonScript( $wrapper.find( 'script.wvgs-archive-hover-payload' ).first() );
		$wrapper.data( 'wvgs-archive-config', parsed || null );
		return parsed || null;
	}

	/**
	 * Stop any running hover slideshow timer on a specific Flatsome `.product-small` card.
	 *
	 * @param {jQuery} $card Flatsome product card element.
	 * @return {void}
	 */
	function stopCardSlideshow( $card ) {
		var timerId = $card.data( 'wvgs-slideshow-timer' );
		if ( timerId ) {
			clearInterval( timerId );
			$card.removeData( 'wvgs-slideshow-timer' );
		}
		$card.find( '.box-image' ).removeClass( 'wvgs-is-sliding' );
		$card.find( '.wvgs-slideshow-progress' ).remove();
	}

	/**
	 * Update or create Flatsome's hover `.back-image` inside a card's `.box-image`.
	 *
	 * @param {jQuery} $imageBox   Card `.box-image` wrapper.
	 * @param {jQuery} $mainImg    Primary `<img>` inside `.box-image`.
	 * @param {string} hoverSrc    Hover image URL (empty string hides back-image).
	 * @param {string} hoverSrcset Optional hover `srcset`.
	 * @return {void}
	 */
	function updateCardBackImage( $imageBox, $mainImg, hoverSrc, hoverSrcset ) {
		var safeHoverSrc = sanitizeUrl( hoverSrc );
		var $backLayer   = $imageBox.find( '.back-image' ).first();

		if ( ! safeHoverSrc ) {
			if ( $backLayer.length ) {
				$backLayer.hide();
			}
			return;
		}

		if ( $backLayer.length ) {
			var $backImg = $backLayer.is( 'img' ) ? $backLayer : $backLayer.find( 'img' ).first();
			$backLayer.show();
			if ( $backImg.length ) {
				$backImg.attr( 'src', safeHoverSrc );
				if ( hoverSrcset ) {
					$backImg.attr( 'srcset', hoverSrcset );
				} else {
					$backImg.removeAttr( 'srcset' );
				}
			}
		} else if ( $mainImg.length ) {
			$mainImg.after(
				'<img src="' + escapeAttr( safeHoverSrc ) + '"' +
				( hoverSrcset ? ' srcset="' + escapeAttr( hoverSrcset ) + '"' : '' ) +
				' class="show-on-hover absolute fill hide-for-small back-image" alt="" loading="lazy" />'
			);
		}
	}

	/**
	 * Apply a specific variation slide to a Flatsome shop product card:
	 * updates primary image, hover back-image (Mode 1/2), active swatch ring, ARIA state,
	 * card product links (`?attribute_*`), and Quick View handoff parameters.
	 *
	 * @param {jQuery}  $card       Flatsome `.product-small` card.
	 * @param {jQuery}  $wrapper    Card `.wvgs-archive-swatches-wrapper`.
	 * @param {Object}  config      Parsed archive hover payload.
	 * @param {Object}  slideData   Target slide object from `config.slides`.
	 * @param {boolean} isAutoSlide True when called by Mode 3 slideshow timer.
	 * @return {void}
	 */
	function applyArchiveSlide( $card, $wrapper, config, slideData, isAutoSlide ) {
		if ( ! slideData ) {
			return;
		}

		if ( ! isAutoSlide ) {
			stopCardSlideshow( $card );
		}

		// 1. Sync active swatch highlight & WAI-ARIA radio state.
		var $items = $wrapper.find( '.wvgs-archive-swatch-item' );
		$items.removeClass( 'selected' ).attr( 'aria-checked', 'false' ).attr( 'tabindex', '-1' );

		var $activeItem = $items.filter( '[data-variation-id="' + String( slideData.variation_id ) + '"]' ).first();
		if ( $activeItem.length ) {
			$activeItem.addClass( 'selected' ).attr( 'aria-checked', 'true' ).attr( 'tabindex', '0' );
		}

		// 2. Swap primary product card image with smooth optional crossfade.
		var $imageBox = $card.find( '.box-image' ).first();
		var $mainImg  = $imageBox.find( 'img.wp-post-image' ).first();
		if ( ! $mainImg.length ) {
			$mainImg = $imageBox.find( 'img' ).not( '.back-image' ).first();
		}

		var safeMainSrc = sanitizeUrl( slideData.src );
		if ( $mainImg.length && safeMainSrc && $mainImg.attr( 'src' ) !== safeMainSrc ) {
			if ( isAutoSlide && ! prefersReducedMotion() ) {
				$mainImg.addClass( 'wvgs-fade-swap wvgs-is-swapping' );
			}
			$mainImg.attr( 'src', safeMainSrc );
			if ( slideData.srcset ) {
				$mainImg.attr( 'srcset', slideData.srcset );
			} else {
				$mainImg.removeAttr( 'srcset' );
			}
			if ( slideData.sizes ) {
				$mainImg.attr( 'sizes', slideData.sizes );
			}
			if ( isAutoSlide ) {
				setTimeout( function () {
					$mainImg.removeClass( 'wvgs-is-swapping' );
				}, 60 );
			}
		}

		// 3. Synchronize Flatsome `.back-image` based on active Hover Mode.
		if ( config.hover_mode === 'variation_gallery' ) {
			// Mode 1: Active variation's 2nd image (additional gallery image).
			updateCardBackImage( $imageBox, $mainImg, slideData.hover_src, slideData.hover_srcset );
		} else if ( config.hover_mode === 'next_variation' && Array.isArray( config.slides ) ) {
			// Mode 2: Next visually distinct available variation's main image.
			var nextSlide = null;
			var currentIdx = config.slides.findIndex( function ( s ) {
				return String( s.variation_id ) === String( slideData.variation_id );
			} );
			for ( var offset = 1; offset < config.slides.length; offset++ ) {
				var candidate = config.slides[ ( currentIdx + offset ) % config.slides.length ];
				if ( candidate && candidate.src && candidate.image_id !== slideData.image_id ) {
					nextSlide = candidate;
					break;
				}
			}
			updateCardBackImage(
				$imageBox,
				$mainImg,
				nextSlide ? nextSlide.src : '',
				nextSlide ? nextSlide.srcset : ''
			);
		}

		// 4. Rewrite card image & title links to the selected variation's attributed deep link.
		var safeUrl = sanitizeUrl( slideData.url );
		if ( safeUrl ) {
			$card
				.find( '.box-image a, .box-text .name a, .woocommerce-LoopProduct-link' )
				.not( '.add_to_cart_button, .quick-view, .wvgs-archive-swatch-more a' )
				.attr( 'href', safeUrl );

			// Record active attribute query params on the card for Flatsome Quick View handoff.
			try {
				var parsedUrl = new URL( safeUrl, window.location.origin );
				var qvMap     = {};
				parsedUrl.searchParams.forEach( function ( val, key ) {
					if ( key.indexOf( 'attribute_' ) === 0 ) {
						qvMap[ key ] = val;
					}
				} );
				$card.data( 'wvgs-active-attrs', qvMap );
			} catch ( err ) {
				// Ignore URL parse error.
			}
		}
	}

	/**
	 * Start Mode 3 (`variation_slideshow`) playback on a Flatsome `.product-small` card.
	 *
	 * @param {jQuery} $imageBox Hovered `.box-image` element.
	 * @return {void}
	 */
	function startCardSlideshow( $imageBox ) {
		var $card    = $imageBox.closest( '.product-small' );
		var $wrapper = $card.find( '.wvgs-archive-swatches-wrapper' ).first();
		var config   = getArchiveHoverConfig( $wrapper );

		if ( ! config || config.hover_mode !== 'variation_slideshow' || ! Array.isArray( config.slides ) ) {
			return;
		}

		// Build deduplicated list of visually distinct slides (so same-photo sizes never repeat).
		var distinctSlides = config.slides.filter( function ( s ) {
			return Boolean( s && s.src && s.is_distinct_image !== false );
		} );
		if ( distinctSlides.length < 2 ) {
			distinctSlides = config.slides.filter( function ( s ) {
				return Boolean( s && s.src );
			} );
		}
		if ( distinctSlides.length < 2 ) {
			return;
		}

		stopCardSlideshow( $card );
		$imageBox.addClass( 'wvgs-is-sliding' );

		var delayMs    = Math.min( 10000, Math.max( 500, parseInt( config.slideshow_delay, 10 ) || 1500 ) );
		var selectedId = $wrapper.find( '.wvgs-archive-swatch-item.selected' ).first().attr( 'data-variation-id' );
		var currentIdx = distinctSlides.findIndex( function ( s ) {
			return String( s.variation_id ) === String( selectedId );
		} );
		if ( currentIdx === -1 ) {
			currentIdx = 0;
		}

		var $progress = $( '<div class="wvgs-slideshow-progress" aria-hidden="true"></div>' );
		$imageBox.append( $progress );

		function animateProgress() {
			if ( prefersReducedMotion() ) {
				$progress.css( { width: '100%', transition: 'none' } );
				return;
			}
			$progress.css( { width: '0%', transition: 'none' } );
			if ( $progress[ 0 ] ) {
				void $progress[ 0 ].offsetWidth; // Force CSS reflow.
			}
			$progress.css( { width: '100%', transition: 'width ' + delayMs + 'ms linear' } );
		}

		animateProgress();

		var timerId = setInterval( function () {
			currentIdx = ( currentIdx + 1 ) % distinctSlides.length;
			applyArchiveSlide( $card, $wrapper, config, distinctSlides[ currentIdx ], true );
			animateProgress();
		}, delayMs );

		$card.data( 'wvgs-slideshow-timer', timerId );
	}

	// =========================================================================
	// 5. Document Ready — Global Delegated Event Bindings
	// =========================================================================

	$( function () {
		// Initialize all variation forms present on initial page load.
		$( '.variations_form' ).each( function () {
			initVariationForm( $( this ) );
		} );

		// A. PDP / Quick View Swatch Click & WAI-ARIA Keyboard Navigation.
		$( document ).on( 'click', '.wvgs-swatch-item', function ( e ) {
			e.preventDefault();
			activatePdpSwatch( $( this ) );
		} );

		$( document ).on( 'keydown', '.wvgs-swatch-item', function ( e ) {
			var key     = e.which || e.keyCode;
			var $swatch = $( this );

			if ( key === 13 || key === 32 ) {
				e.preventDefault();
				activatePdpSwatch( $swatch );
				return;
			}

			// Arrow / Home / End navigation across enabled items in the radiogroup.
			if ( key === 37 || key === 38 || key === 39 || key === 40 || key === 35 || key === 36 ) {
				e.preventDefault();
				var $enabled = $swatch.closest( '.wvgs-swatches' ).find( '.wvgs-swatch-item' ).not( '.disabled, [aria-disabled="true"]' );
				if ( ! $enabled.length ) {
					return;
				}
				var idx     = $enabled.index( $swatch );
				var nextIdx = idx;

				if ( key === 37 || key === 38 ) {
					nextIdx = ( idx - 1 + $enabled.length ) % $enabled.length;
				} else if ( key === 39 || key === 40 ) {
					nextIdx = ( idx + 1 ) % $enabled.length;
				} else if ( key === 36 ) {
					nextIdx = 0;
				} else if ( key === 35 ) {
					nextIdx = $enabled.length - 1;
				}

				var $target = $enabled.eq( nextIdx );
				$target.trigger( 'focus' );
				activatePdpSwatch( $target );
			}
		} );

		// B. WooCommerce Variation Lifecycle Events (Delegated to Support Flatsome Quick View).
		$( document ).on( 'found_variation', '.variations_form', function ( event, variation ) {
			var $form = $( this );
			initVariationForm( $form );
			syncSwatchesFromSelects( $form );

			if ( ! variation ) {
				return;
			}

			var variationId    = parseInt( variation.variation_id, 10 ) || 0;
			var ssrVariationId = parseInt( $form.data( 'wvgs-ssr-variation-id' ), 10 ) || 0;
			var userInteracted = Boolean( $form.data( 'wvgs-user-interacted' ) );

			// 1. Sync Flatsome header price if enabled.
			syncFlatsomeHeaderPrice( $form, variation.price_html || '' );

			// 2. Sync browser URL on Single Product Page after user interaction (or on initial stock-winner match).
			if ( userInteracted ) {
				syncBrowserUrl( $form, variation.attributes );
			}

			// 3. Strictly isolated Flatsome gallery swap:
			// On initial load, if WooCommerce resolved the exact variation already server-rendered by PHP,
			// record its signature without destroying/rebuilding Flatsome's Flickity slider (zero flash!).
			if ( Array.isArray( variation.wvgs_gallery_images ) && variation.wvgs_gallery_images.length ) {
				var $slider = getProductScope( $form ).find( '.product-gallery-slider' ).first();

				if ( ! userInteracted && ssrVariationId > 0 && variationId === ssrVariationId ) {
					if ( $slider.length && ! $slider.data( 'wvgs-gallery-signature' ) ) {
						$slider.data( 'wvgs-gallery-signature', buildGallerySignature( variation.wvgs_gallery_images ) );
					}
					return;
				}

				updateFlatsomeGallery( variation.wvgs_gallery_images, $form );
			}
		} );

		$( document ).on( 'woocommerce_update_variation_values', '.variations_form', function () {
			updateFormSwatchStockMatrix( $( this ) );
		} );

		$( document ).on( 'reset_data', '.variations_form', function () {
			var $form = $( this );
			syncSwatchesFromSelects( $form );
			syncFlatsomeHeaderPrice( $form, '' );

			// Only restore winning fallback gallery when user explicitly clicks "Clear".
			if ( $form.data( 'wvgs-clearing' ) ) {
				$form.data( 'wvgs-clearing', false );
				var payload = getFormPayload( $form );
				if ( payload && Array.isArray( payload.fallback_gallery ) && payload.fallback_gallery.length ) {
					updateFlatsomeGallery( payload.fallback_gallery, $form );
				}
			}
		} );

		$( document ).on( 'click', '.variations_form .reset_variations', function () {
			var $form = $( this ).closest( '.variations_form' );
			$form.data( 'wvgs-user-interacted', true );
			$form.data( 'wvgs-clearing', true );
		} );

		// C. Inline Video Slide Play & Close Controls.
		$( document ).on( 'click', '.wvgs-video-play-btn', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			playSlideVideo( $( this ) );
		} );

		$( document ).on( 'click', '.wvgs-video-close-btn', function ( e ) {
			e.preventDefault();
			e.stopPropagation();
			var $container = $( this ).closest( '.product-gallery-slider, .woocommerce-product-gallery' );
			stopAllGalleryVideos( $container.length ? $container : $( this ).closest( '.slide' ) );
		} );

		// D. Flatsome Quick View Handoff & Dynamic Form Initialization.
		$( document ).on( 'click', '.product-small .quick-view', function () {
			var $card  = $( this ).closest( '.product-small' );
			var active = $card.data( 'wvgs-active-attrs' );
			pendingQuickViewParams = ( active && typeof active === 'object' ) ? active : null;
		} );

		$( document ).on( 'wc_variation_form', '.variations_form', function () {
			initVariationForm( $( this ) );
		} );

		$( document ).ajaxComplete( function ( event, xhr, settings ) {
			if ( settings && settings.data && String( settings.data ).indexOf( 'flatsome_quickview' ) !== -1 ) {
				setTimeout( function () {
					$( '.product-quick-view-container .variations_form' ).each( function () {
						initVariationForm( $( this ) );
					} );
				}, 80 );
			}
		} );

		// E. Flatsome Shop Archive Swatches (Desktop Hover, Mobile Tap & Keyboard Radio Group).
		$( document ).on( 'mouseenter click', '.wvgs-archive-swatch-item', function ( e ) {
			if ( e.type === 'click' ) {
				e.preventDefault();
				e.stopPropagation();
			}

			var $swatch  = $( this );
			var $card    = $swatch.closest( '.product-small' );
			var $wrapper = $swatch.closest( '.wvgs-archive-swatches-wrapper' );
			var config   = getArchiveHoverConfig( $wrapper );

			if ( ! config || ! Array.isArray( config.slides ) ) {
				return;
			}

			var targetVarId = String( $swatch.attr( 'data-variation-id' ) || '' );
			var slideData   = config.slides.find( function ( s ) {
				return String( s.variation_id ) === targetVarId;
			} );

			applyArchiveSlide( $card, $wrapper, config, slideData, false );
		} );

		$( document ).on( 'keydown', '.wvgs-archive-swatch-item', function ( e ) {
			var key     = e.which || e.keyCode;
			var $swatch = $( this );

			if ( key === 13 || key === 32 ) {
				e.preventDefault();
				$swatch.trigger( 'click' );
				return;
			}

			if ( key === 37 || key === 39 ) {
				e.preventDefault();
				var $items  = $swatch.closest( '.wvgs-archive-swatches' ).find( '.wvgs-archive-swatch-item' );
				var idx     = $items.index( $swatch );
				var nextIdx = ( key === 37 )
					? ( idx - 1 + $items.length ) % $items.length
					: ( idx + 1 ) % $items.length;

				$items.eq( nextIdx ).trigger( 'focus' ).trigger( 'click' );
			}
		} );

		// F. Flatsome Shop Archive Mode 3 Slideshow Triggers (`.product-small .box-image`).
		$( document ).on( 'mouseenter', '.product-small .box-image', function () {
			startCardSlideshow( $( this ) );
		} );

		$( document ).on( 'mouseleave', '.product-small .box-image', function () {
			stopCardSlideshow( $( this ).closest( '.product-small' ) );
		} );
	} );
} )( jQuery );
