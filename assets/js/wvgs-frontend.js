/**
 * Swatch State Machine & Flatsome Gallery Bridge
 */
jQuery( document ).ready( function( $ ) {

    var $variationsForm = $( '.variations_form' );

    // 1. Handle Swatch Click Interactions
    $( document ).on( 'click keydown', '.wvgs-swatch-item', function( e ) {
        // Support keyboard accessibility (Enter/Space)
        if ( e.type === 'keydown' && e.which !== 13 && e.which !== 32 ) {
            return;
        }
        
        e.preventDefault();
        
        var $swatch = $( this );
        if ( $swatch.attr( 'aria-disabled' ) === 'true' ) {
            return;
        }

        var $wrapper = $swatch.closest( '.wvgs-swatches-wrapper' );
        var selectName = $wrapper.data( 'attribute_name' );
        var value = $swatch.data( 'value' );
        var $select = $wrapper.siblings( 'select[name="' + selectName + '"]' );

        // Toggle visual selection and ARIA states
        $wrapper.find( '.wvgs-swatch-item' ).removeClass( 'selected' ).attr( 'aria-checked', 'false' );
        $swatch.addClass( 'selected' ).attr( 'aria-checked', 'true' );

        // Update standard WC select and trigger change to calculate stock matrix
        $select.val( value ).trigger( 'change' );
    });

    // 2. Sync URL when Variation is Found
    $variationsForm.on( 'found_variation', function( event, variation ) {
        // Update URL
        if ( window.history && window.history.replaceState ) {
            var url = new URL( window.location.href );
            $.each( variation.attributes, function( name, val ) {
                url.searchParams.set( name, val );
            });
            window.history.replaceState( null, '', url.toString() );
        }

        // Trigger Flatsome Gallery Swap if dynamic images exist
        if ( variation.wvgs_gallery_images && variation.wvgs_gallery_images.length > 0 ) {
            updateFlatsomeGallery( variation.wvgs_gallery_images );
        }
    });

    // 3. Update Disabled/Out of Stock Swatch UI
    $variationsForm.on( 'woocommerce_update_variation_values', function() {
        $( '.wvgs-swatches-wrapper' ).each( function() {
            var $wrapper = $( this );
            var $select  = $wrapper.siblings( 'select' );
            
            $wrapper.find( '.wvgs-swatch-item' ).each( function() {
                var $swatch = $( this );
                var val = $swatch.data( 'value' );
                
                // Check if the option exists in the updated select
                if ( $select.find( 'option[value="' + val + '"]' ).length === 0 ) {
                    $swatch.addClass( 'disabled' ).attr( 'aria-disabled', 'true' );
                } else {
                    $swatch.removeClass( 'disabled' ).attr( 'aria-disabled', 'false' );
                }
            });
        });
    });

    // 4. Flatsome Flickity Gallery Bridge
    function updateFlatsomeGallery( images ) {
        var $slider = $( '.product-gallery-slider' );
        var $thumbnails = $( '.product-thumbnails' );
        
        if ( ! $slider.length ) {
            return; // Not using Flatsome or gallery is missing
        }

        // Lock container height to prevent Layout Shift
        var currentHeight = $slider.parent().height();
        $slider.parent().css( 'min-height', currentHeight + 'px' );

        // Destroy existing Flatsome Flickity instances
        if ( $slider.hasClass( 'flickity-enabled' ) ) {
            $slider.flickity( 'destroy' );
        }
        if ( $thumbnails.hasClass( 'flickity-enabled' ) ) {
            $thumbnails.flickity( 'destroy' );
        }

        // Build new HTML payloads
        var sliderHtml = '';
        var thumbHtml = '';

        $.each( images, function( index, img ) {
            // Main Slides
            sliderHtml += '<div class="slide first">' +
                '<a href="' + img.full_src + '" data-large_image_width="' + img.sizes[0] + '" data-large_image_height="' + img.sizes[1] + '">' +
                '<img src="' + img.src + '" srcset="' + img.srcset + '" sizes="(max-width: ' + img.sizes[0] + 'px) 100vw, ' + img.sizes[0] + 'px" alt="' + img.alt + '" class="wp-post-image" />' +
                '</a></div>';
                
            // Thumbnail Strip
            thumbHtml += '<div class="col"><div class="col-inner">' +
                '<img src="' + img.gallery_thumbnail_src + '" alt="' + img.alt + '" width="100" height="100" />' +
                '</div></div>';
        });

        // Inject HTML
        $slider.html( sliderHtml );
        $thumbnails.html( thumbHtml );

        // Re-initialize Flatsome Flickity via global Flatsome core events
        if ( typeof window.Flatsome !== 'undefined' ) {
            window.Flatsome.attach( 'flickity', $slider.parent() );
        }

        // Release min-height lock
        setTimeout( function() {
            $slider.parent().css( 'min-height', '' );
        }, 300 );
    }

});
