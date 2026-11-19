/Admin JavaScript for Variation Galleries & Swatches
*/
jQuery(function ($) {
'use strict';// --- 1. Variation Gallery WP Media Uploader ---
$(document).on('click', '.wvgs-add-variation-gallery-image', function (e) {
e.preventDefault(); var $button =$(this);
 var $wrapper =$button.closest('.wvgs-variation-gallery-wrapper, .wvgs-mini-gallery');
 var $image_gallery_ids =$wrapper.find('.wvgs-variation-gallery-ids');
 var $image_gallery_ul =$wrapper.find('.wvgs-variation-gallery-images');

 var frame = wp.media({
 	title: $button.data('choose') || 'Add Images to Gallery',
 	button: { text: $button.data('update') || 'Add to gallery' },
 	states: [
 		new wp.media.controller.Library({
 			title: $button.data('choose') || 'Add Images to Gallery',
 			filterable: 'all',
 			multiple: true
 		})
 	]
 });

 frame.on('select', function () {
 	var selection = frame.state().get('selection');
 	var attachment_ids = $image_gallery_ids.val() ?$image_gallery_ids.val().split(',') : [];

 	selection.map(function (attachment) {
 		attachment = attachment.toJSON();
 		if (attachment.id) {
 			var attachment_image = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;
 			attachment_ids.push(attachment.id);

 			$image_gallery_ul.append(
 				'<li class="image" data-attachment_id="' + attachment.id + '">' +
 				'<img src="' + attachment_image + '" alt="" />' +
 				'<a href="#" class="delete wvgs-remove-variation-gallery-image"><span class="dashicons dashicons-dismiss"></span></a>' +
 				'</li>'
 			);
 		}
 	});

 	$image_gallery_ids.val(attachment_ids.join(','));$wrapper.find('.wvgs-variation-gallery-images').trigger('update_gallery_ids');
 });

 frame.open();
});// --- 2. Remove Variation Gallery Image ---
$(document).on('click', '.wvgs-remove-variation-gallery-image', function (e) {
e.preventDefault();
var $image =$(this).closest('li.image');
var $wrapper =$image.closest('.wvgs-variation-gallery-wrapper, .wvgs-mini-gallery'); $image.remove();$wrapper.find('.wvgs-variation-gallery-images').trigger('update_gallery_ids');
});// --- 3. jQuery UI Sortable for Gallery Images ---
function init_sortable() {
$('.wvgs-variation-gallery-images').sortable({
items: 'li.image',
cursor: 'move',
scrollSensitivity: 40,
forcePlaceholderSize: true,
forceHelperSize: false,
helper: 'clone',
opacity: 0.65,
placeholder: 'wc-metabox-sortable-placeholder',
update: function (event, ui) {
$(this).trigger('update_gallery_ids');
}
});
}// Delegate update logic so drag-drop rebuilds the hidden input array correctly
$(document).on('update_gallery_ids', '.wvgs-variation-gallery-images', function () {
var attachment_ids = [];
$(this).find('li.image').each(function () {
attachment_ids.push($(this).data('attachment_id'));
});
$(this).siblings('.wvgs-variation-gallery-ids').val(attachment_ids.join(','));
});// WooCommerce dynamic variation loaded event trigger
$('#woocommerce-product-data').on('woocommerce_variations_loaded woocommerce_variations_added', function () {
init_sortable();
});
init_sortable(); // Init on page load for mini-galleries and existing items// --- 4. Refresh Product Attributes AJAX ---
$('#wvgs-refresh-attributes').on('click', function (e) {
e.preventDefault();
if (!wvgs_admin_data || !wvgs_admin_data.postID) return; var $container =$('#wvgs-product-attributes-container');
 $container.block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });

 $.post(wvgs_admin_data.ajax, {
 	action: 'wvgs_load_product_attribute_swatches',
 	nonce: wvgs_admin_data.nonce,
 	product_id: wvgs_admin_data.postID
 }, function (res) {
 	if (res.success && res.data.html) {
 		$container.html(res.data.html);
 		init_sortable();
 	}
 	$container.unblock();
 });
});// --- 5. Term Meta Image Uploader ---
$(document).on('click', '.wvgs-upload-image-btn', function (e) {
e.preventDefault();
var $btn =$(this);
var $wrap =$btn.closest('.wvgs-term-image-wrap'); var term_frame = wp.media({
 	title: 'Select Term Image',
 	multiple: false
 });

 term_frame.on('select', function () {
 	var attachment = term_frame.state().get('selection').first().toJSON();
 	$wrap.find('#wvgs_image_id').val(attachment.id);
 	$wrap.find('.wvgs-term-image-preview').html('<img src="' + (attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url) + '" />');
 	$wrap.find('.wvgs-remove-image-btn').show();
 });

 term_frame.open();
});$(document).on('click', '.wvgs-remove-image-btn', function (e) {
e.preventDefault();
var $wrap = $(this).closest('.wvgs-term-image-wrap');$wrap.find('#wvgs_image_id').val('');
$wrap.find('.wvgs-term-image-preview').html('');$(this).hide();
});// --- 6. WP Color Picker Init ---
if ($.fn.wpColorPicker) {
$('.wvgs-color-picker').wpColorPicker();
// Re-init when WP appends new rows in taxonomy AJAX saves
$(document).ajaxComplete(function(event, request, options) {
if (request && 4 === request.readyState && 200 === request.status && options.data && options.data.indexOf('action=add-tag') >= 0) {
$('.wvgs-term-color-wrap .wp-color-result').remove(); // clear duplicate UI$('.wvgs-color-picker').wpColorPicker();
}
});
}
});
