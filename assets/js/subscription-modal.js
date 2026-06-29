jQuery(document).ready(function($) {
    if (typeof skyhshoso_sm === 'undefined') {
        return;
    }

    var strings = skyhshoso_sm.strings;
    var $modal = $('#skyhshoso-edit-modal');

    // Define a global function that other scripts can call
    window.skyhshosoOpenSubscriptionModal = function(subId) {
        var $btn = $('#sem-save-btn');
        $btn.prop('disabled', true).text(strings.update);
        
        // Show empty/loading modal first
        $('#sem-sub-id').text(subId);
        $('#sem-sub-id-input').val(subId);
        $('#sem-amount').val('');
        $('#sem-billing-period').val('month');
        $('#sem-billing-interval').val('1');
        $('#sem-next-payment').val('');
        $('#sem-end-date').val('');
        $('#sem-status').val('');

        var $productSelect = $('#sem-product-search');
        if ($productSelect.data('select2')) {
            $productSelect.val(null).trigger('change');
        }

        $modal.fadeIn(150);
        $('body').css('overflow', 'hidden');

        // Fetch subscription details via AJAX
        $.post(skyhshoso_sm.ajax_url, {
            action: 'skyhshoso_get_subscription_details',
            nonce: skyhshoso_sm.nonce_get_details,
            subscription_id: subId
        }, function(res) {
            if (res.success) {
                var sub = res.data;
                $('#sem-amount').val(sub.amount);
                $('#sem-billing-period').val(sub.billing_period);
                $('#sem-billing-interval').val(sub.billing_interval);
                $('#sem-next-payment').val(sub.next_payment_ymd || '');
                $('#sem-end-date').val(sub.end_date_ymd || '');

                var $statusSelect = $('#sem-status');
                var statusVal = sub.status || '';
                $statusSelect.val(statusVal);
                if ($statusSelect.val() !== statusVal) {
                    $statusSelect.find('option').each(function() {
                        if ($(this).val() === statusVal) {
                            $(this).prop('selected', true);
                            return false;
                        }
                    });
                }

                if (sub.product_id && sub.product_name) {
                    var option = new Option(sub.product_name, sub.product_id, true, true);
                    $productSelect.append(option).trigger('change');
                }

                $btn.prop('disabled', false);
            } else {
                alert(res.data.message || strings.error);
                closeEditModal();
            }
        }).fail(function() {
            alert(strings.error);
            closeEditModal();
        });
    };

    function closeEditModal() {
        $modal.fadeOut(100);
        $('body').css('overflow', '');
    }

    $('#sem-close-btn, #sem-cancel-btn').on('click', function(e) {
        e.preventDefault();
        closeEditModal();
    });

    $modal.on('click', '.skyhshoso-modal-backdrop', function(e) {
        closeEditModal();
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $modal.is(':visible')) {
            closeEditModal();
        }
    });

    // Global listener for edit links
    $(document).on('click', '.skyhshoso-edit-sub-link', function(e) {
        e.preventDefault();
        var subId = $(this).data('sub-id');
        if (subId) {
            window.skyhshosoOpenSubscriptionModal(subId);
        }
    });

    $('#sem-save-btn').on('click', function(e) {
        e.preventDefault();

        var subId = $('#sem-sub-id-input').val();
        var $btn = $(this);
        var $form = $('#sem-edit-form');
        var formData = $form.serializeArray();

        var data = {
            action: 'skyhshoso_edit_subscription_ajax',
            nonce: skyhshoso_sm.nonce_edit,
            subscription_id: subId
        };

        $.each(formData, function(i, field) {
            data[field.name] = field.value;
        });

        $btn.prop('disabled', true).text(strings.saving);

        $.post(skyhshoso_sm.ajax_url, data, function(res) {
            if (res.success) {
                // Show floating notice or standard WooCommerce alert
                var noticeHtml = '<div class="notice notice-success is-dismissible" style="position:fixed;top:40px;right:20px;z-index:999999;box-shadow:0 4px 12px rgba(0,0,0,0.15);"><p>' + escapeHtml(res.data.message || strings.saved) + '</p></div>';
                var $notice = $(noticeHtml).appendTo('body');
                setTimeout(function() {
                    $notice.fadeOut(300, function() { $(this).remove(); });
                }, 4000);

                closeEditModal();

                // Trigger a global custom event so the host page can refresh its lists
                $(document).trigger('skyhshoso_subscription_updated', [subId]);

            } else {
                alert(res.data.message || strings.error);
                $btn.prop('disabled', false).text(strings.update);
            }
        }).fail(function() {
            alert(strings.error);
            $btn.prop('disabled', false).text(strings.update);
        });
    });

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Ensure product search selectWoo is initialized
    var $productSelect = $('#sem-product-search');
    if ($.fn.selectWoo && !$productSelect.data('select2')) {
        $productSelect.selectWoo({
            ajax: {
                url: skyhshoso_sm.ajax_url,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        action: 'woocommerce_json_search_products',
                        security: skyhshoso_sm.nonce_search_products,
                        term: params.term,
                        limit: 20
                    };
                },
                processResults: function(data) {
                    var results = [];
                    if (data) {
                        $.each(data, function(id, name) {
                            results.push({ id: id, text: name });
                        });
                    }
                    return { results: results };
                },
                cache: true
            },
            minimumInputLength: 2,
            placeholder: strings.search_products,
            allowClear: true
        });
    }
});
