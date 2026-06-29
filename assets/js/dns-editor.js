jQuery(document).ready(function($) {
    // Function to show notification messages
    function showNotification(message, isError = false) {
        var $notification = $('#skyhshoso-dns-editor-notification');
        $notification.text(message).removeClass('skyhshoso-dns-editor-hidden');
        $notification.addClass(isError ? 'skyhshoso-dns-editor-notification-error' : 'skyhshoso-dns-editor-notification-success');
        setTimeout(function() {
            $notification.addClass('skyhshoso-dns-editor-hidden').removeClass(isError ? 'skyhshoso-dns-editor-notification-error' : 'skyhshoso-dns-editor-notification-success');
        }, 3000);
    }

    // Function to show a loading spinner
    function showLoading(element) {
        $(element).append('<span class="skyhshoso-dns-editor-loading"></span>');
    }

    // Function to hide the loading spinner
    function hideLoading(element) {
        $(element).find('.skyhshoso-dns-editor-loading').remove();
    }

    // Toggle visibility of the nameserver edit form
    $('#edit-nameservers').click(function() {
        $('#nameserver-form').removeClass('skyhshoso-dns-editor-hidden');
        // Populate nameserver fields with current values
        for (let i = 0; i < 4; i++) {
            $('#nameserver-' + (i + 1)).val(skyhshoso_dns_editor_ajax.current_nameservers[i] || '');
        }
    });

    // Cancel nameserver editing
    $('#cancel-nameservers').click(function() {
        $('#nameserver-form').addClass('skyhshoso-dns-editor-hidden');
    });

    // Handle nameserver update form submission
    $('#update-nameservers-form').submit(function(e) {
        e.preventDefault();
        var $form = $(this);
        var $submitButton = $form.find('button[type="submit"]');
        showLoading($submitButton);
        
        var nameservers = $form.find('input[name="nameservers[]"]').map(function() {
            return $(this).val();
        }).get();

        $.ajax({
            url: skyhshoso_dns_editor_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'skyhshoso_update_ns',
                nonce: skyhshoso_dns_editor_ajax.nonce,
                skyhshoso_domain: skyhshoso_dns_editor_ajax.domain_name,
                nameservers: nameservers
            },
            success: function(response) {
                hideLoading($submitButton);
                if (response.success) {
                    showNotification(skyhshoso_dns_editor_ajax.i18n.nameservers_updated);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification(skyhshoso_dns_editor_ajax.i18n.error_updating_nameservers + ' ' + response.data, true);
                }
            },
            error: function() {
                hideLoading($submitButton);
                showNotification(skyhshoso_dns_editor_ajax.i18n.error_occurred, true);
            }
        });
    });

    // Toggle MX preference field based on record type
    function toggleMXPrefField() {
        if ($('#record-type').val() === 'MX') {
            $('#mx-pref-group').show();
        } else {
            $('#mx-pref-group').hide();
        }
    }

    // Show form to add a new DNS record
    $('#add-record').click(function() {
        $('#form-mode').val('add');
        $('#dns-record-form')[0].reset(); // Reset form fields
        $('#record-form').removeClass('skyhshoso-dns-editor-hidden');
        toggleMXPrefField();
        $('html, body').animate({ scrollTop: $('#record-form').offset().top - 50 }, 500);
    });

    // Show form to edit an existing DNS record
    $('.edit-record').click(function() {
        $('#form-mode').val('update');
        $('#old-host-name').val($(this).data('name'));
        $('#old-address').val($(this).data('address'));
        $('#record-type').val($(this).data('type'));
        $('#host-name').val($(this).data('name'));
        $('#address').val($(this).data('address'));
        $('#mx-pref').val($(this).data('mxpref'));
        $('#record-form').removeClass('skyhshoso-dns-editor-hidden');
        toggleMXPrefField();
        $('html, body').animate({ scrollTop: $('#record-form').offset().top - 50 }, 500);
    });

    // Handle deletion of a DNS record
    $('.delete-record').click(function() {
        if (confirm(skyhshoso_dns_editor_ajax.i18n.confirm_delete)) {
            updateDNSRecord('delete', {
                record_type: $(this).data('type'),
                old_host_name: $(this).data('name'),
                old_address: $(this).data('address')
            }, $(this));
        }
    });

    // Cancel DNS record editing
    $('#cancel-record').click(function() {
        $('#record-form').addClass('skyhshoso-dns-editor-hidden');
    });

    // Update MX preference field visibility on record type change
    $('#record-type').change(toggleMXPrefField);

    // Handle DNS record form submission (for both add and update)
    $('#dns-record-form').submit(function(e) {
        e.preventDefault();
        var $form = $(this);
        var $submitButton = $form.find('button[type="submit"]');
        var formMode = $('#form-mode').val();
        var recordData = {
            record_type: $('#record-type').val(),
            host_name: $('#host-name').val(),
            address: $('#address').val(),
            mx_pref: $('#mx-pref').val(),
            old_host_name: $('#old-host-name').val(),
            old_address: $('#old-address').val()
        };
        updateDNSRecord(formMode, recordData, $submitButton);
    });

    // Function to send DNS record updates to the server
    function updateDNSRecord(action, recordData, $submitButton) {
        showLoading($submitButton);
        $.ajax({
            url: skyhshoso_dns_editor_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'skyhshoso_update_dns_record',
                nonce: skyhshoso_dns_editor_ajax.nonce,
                skyhshoso_domain: skyhshoso_dns_editor_ajax.domain_name,
                dns_action: action,
                record_data: recordData
            },
            success: function(response) {
                hideLoading($submitButton);
                if (response.success) {
                    showNotification(skyhshoso_dns_editor_ajax.i18n.dns_record_updated);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showNotification(skyhshoso_dns_editor_ajax.i18n.error_updating_dns + ' ' + (response.data.message || ''), true);
                }
            },
            error: function() {
                hideLoading($submitButton);
                showNotification(skyhshoso_dns_editor_ajax.i18n.error_occurred, true);
            }
        });
    }
    
    // Initial setup
    toggleMXPrefField();
});
