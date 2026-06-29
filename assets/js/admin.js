/**
 * Admin JavaScript for Hosting Solution plugin
 */
jQuery(document).ready(function($) {
    // Media uploader for custom logo
    $('#skyhshoso-upload-logo-btn').on('click', function(e) {
        e.preventDefault();
        
        // If the media frame already exists, reopen it.
        var custom_uploader = wp.media({
            title: 'Choose Logo',
            button: {
                text: 'Use Image'
            },
            multiple: false
        }).on('select', function() {
            var attachment = custom_uploader.state().get('selection').first().toJSON();
            $('#skyhshoso-custom-logo-url').val(attachment.url);
            $('#skyhshoso-logo-preview').attr('src', attachment.url).show();
        }).open();
    });

    $('#skyhshoso-remove-logo-btn').on('click', function(e) {
        e.preventDefault();
        $('#skyhshoso-custom-logo-url').val('');
        $('#skyhshoso-logo-preview').attr('src', '').hide();
    });
}); 