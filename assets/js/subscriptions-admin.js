jQuery(document).ready(function($) {
    function __(key, domain) {
        if (typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n.__ === 'function') {
            return wp.i18n.__(key, domain);
        }
        return key;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    if (typeof skyhshoso_sa === 'undefined') {
        return;
    }

    var currentPage = 1;
    var totalPages = 1;
    var subscriptions = [];
    var statuses = skyhshoso_sa.statuses;
    var strings = skyhshoso_sa.strings;

    var $container = $('#skyhshoso-hm-container');
    var $notice = $('#skyhshoso-hm-notice');

    fetchSubscriptions(1);

    function fetchSubscriptions(page) {
        if (!page) page = 1;
        currentPage = page;

        var data = {
            action: 'skyhshoso_get_subscriptions',
            nonce: skyhshoso_sa.nonce_get,
            paged: currentPage,
            search: $('#sa-search-input').val(),
            product_id: $('#sa-product-filter').val(),
            status: $('#sa-status-filter').val(),
            next_payment: $('#sa-next-payment-filter').val(),
            orderby: $('#sa-sort-by').val(),
            limit: 10
        };

        $.post(skyhshoso_sa.ajax_url, data, function(res) {
            if (res.success) {
                subscriptions = res.data.subscriptions;
                totalPages = res.data.total_pages;
                currentPage = res.data.current_page;
                renderSubscriptions();
                updatePagination();
            } else {
                showNotice('error', res.data.message || strings.error);
            }
        }).fail(function() {
            showNotice('error', strings.error);
        });
    }

    function renderSubscriptions() {
        $container.empty();

        if (subscriptions.length === 0) {
            $container.append('<div class="skyhshoso-hm-empty"><p>' + escapeHtml(strings.no_results) + '</p></div>');
            return;
        }

        var tableHtml = '<table class="skyhshoso-hm-table">' +
            '  <thead>' +
            '    <tr>' +
            '      <th>' + escapeHtml(__('ID', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Customer', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Product', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Status', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Order', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Billing', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Amount', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Next Payment', 'skyhs-hosting-solution')) + '</th>' +
            '      <th style="text-align:right;">' + escapeHtml(__('Actions', 'skyhs-hosting-solution')) + '</th>' +
            '    </tr>' +
            '  </thead>' +
            '  <tbody id="sa-table-body"></tbody>' +
            '</table>';

        var $table = $(tableHtml);
        var $tbody = $table.find('#sa-table-body');

        $.each(subscriptions, function(i, s) {
            var row = createSubscriptionRow(s);
            $tbody.append(row);
            var invoicesRow = createInvoicesRow(s);
            $tbody.append(invoicesRow);
        });

        $container.append($table);
    }

    function updatePagination() {
        $('#sa-page-info').text(__('Page', 'skyhs-hosting-solution') + ' ' + currentPage + ' ' + __('of', 'skyhs-hosting-solution') + ' ' + totalPages);
        $('#sa-prev-page').prop('disabled', currentPage <= 1);
        $('#sa-next-page').prop('disabled', currentPage >= totalPages);
    }

    $('#sa-prev-page').on('click', function(e) {
        e.preventDefault();
        if (currentPage > 1) {
            fetchSubscriptions(currentPage - 1);
        }
    });

    $('#sa-next-page').on('click', function(e) {
        e.preventDefault();
        if (currentPage < totalPages) {
            fetchSubscriptions(currentPage + 1);
        }
    });

    var searchTimeout = null;
    $('#sa-search-input').on('keyup input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            fetchSubscriptions(1);
        }, 350);
    });

    $('#sa-status-filter').on('change', function() {
        fetchSubscriptions(1);
    });

    $('#sa-next-payment-filter').on('change', function() {
        fetchSubscriptions(1);
    });

    $('#sa-product-filter').on('change', function() {
        fetchSubscriptions(1);
    });

    $('#sa-sort-by').on('change', function() {
        fetchSubscriptions(1);
    });

    function getBadgeClass(status) {
        switch (status) {
            case 'active':
                return 'hm-badge-active';
            case 'pending-cancel':
            case 'on-hold':
            case 'pending':
                return 'hm-badge-pending';
            case 'cancelled':
            case 'expired':
                return 'hm-badge-cancelled';
            default:
                return 'hm-badge-default';
        }
    }

    function createSubscriptionRow(s) {
        var badgeClass = getBadgeClass(s.status);

        var billingText = strings.every + ' ' + s.billing_interval + ' ' + s.billing_period + (s.billing_interval > 1 ? 's' : '');

        var customerDisplay = '<strong>' + escapeHtml(s.customer_name) + '</strong>';
        if (s.customer_email) {
            customerDisplay += '<br/><span style="color:#6b7280; font-size:11px;">' + escapeHtml(s.customer_email) + '</span>';
        }

        var nextPaymentDisplay = s.next_payment ? s.next_payment : '<span style="color:#9ca3af; font-style:italic;">&mdash;</span>';

        var orderIndicator = s.has_parent_order && s.order_id && s.order_edit_url
            ? '<a href="' + escapeHtml(s.order_edit_url) + '" title="View Order #' + escapeHtml(s.order_id) + '" style="color:#3b82f6; font-weight:600; font-size:13px; text-decoration:none; transition:color 0.15s ease;" onmouseover="this.style.color=\'#2563eb\'; this.style.textDecoration=\'underline\';" onmouseout="this.style.color=\'#3b82f6\'; this.style.textDecoration=\'none\';">#' + escapeHtml(s.order_id) + '</a>'
            : '<span style="color:#9ca3af; font-style:italic;">&mdash;</span>';

        var statusOptions = '';
        $.each(statuses, function(key, label) {
            if (!key) return;
            var selected = key === s.status ? ' selected' : '';
            statusOptions += '<option value="' + escapeHtml(key) + '"' + selected + '>' + escapeHtml(label) + '</option>';
        });

        var invoiceCount = s.invoices ? s.invoices.length : 0;

        var rowHtml = '<tr data-id="' + s.id + '">' +
            '  <td style="font-weight:600; color:#111827;">#' + escapeHtml(s.id) + '</td>' +
            '  <td style="font-size:13px;">' + customerDisplay + '</td>' +
            '  <td style="font-size:13px; font-weight:500; color:#374151;">' + (s.product_name ? escapeHtml(s.product_name) : '<span style="color:#9ca3af; font-style:italic;">&mdash;</span>') + '</td>' +
            '  <td style="font-size:13px;">' +
            '    <span class="hm-badge ' + badgeClass + '">' +
            '      <span class="hm-badge-status-dot"></span>' + escapeHtml(s.status_label) +
            '    </span>' +
            '  </td>' +
            '  <td style="font-size:13px; text-align:center;">' + orderIndicator + '</td>' +
            '  <td style="font-size:13px; color:#4b5563;">' + escapeHtml(billingText) + '</td>' +
            '  <td style="font-size:13px; font-weight:600; color:#059669;">' + s.amount_formatted + '</td>' +
            '  <td style="font-size:13px;">' + nextPaymentDisplay + '</td>' +
            '  <td style="text-align:right;">' +
            '      <div style="display:inline-flex; gap:6px; align-items:center;">' +
            '      <button class="button button-small sa-edit-btn hm-btn-edit" data-id="' + s.id + '" style="font-size:11px; padding:4px 8px; height:30px;">' + escapeHtml(__('Edit', 'skyhs-hosting-solution')) + '</button>' +
            '      <select class="sa-status-select hm-control-select" data-id="' + s.id + '" data-orig-status="' + escapeHtml(s.status) + '" style="min-width:130px; padding:4px 8px; font-size:12px; height:30px;">' +
                         statusOptions +
            '      </select>' +
            '      <button class="button button-small sa-toggle-invoices-btn" data-id="' + s.id + '" style="font-size:11px; padding:4px 8px; height:30px; background:#f0f2f5; color:#111518; border:1px solid #dbe1e6; font-weight:500;">' +
                         escapeHtml(__('Invoices', 'skyhs-hosting-solution')) + ' (' + invoiceCount + ')' +
            '      </button>' +
            '      <button class="hm-btn-delete button button-small sa-delete-btn" data-id="' + s.id + '" style="font-size:11px; padding:4px 8px; height:30px;">' + escapeHtml(__('Delete', 'skyhs-hosting-solution')) + '</button>' +
            '    </div>' +
            '  </td>' +
            '</tr>';

        return $(rowHtml);
    }

    $container.on('change', '.sa-status-select', function() {
        var $select = $(this);
        var id = $select.data('id');
        var newStatus = $select.val();
        var oldStatus = $select.data('orig-status') || '';

        if (newStatus === oldStatus) return;

        if (!confirm(__('Are you sure you want to change the status?', 'skyhs-hosting-solution'))) {
            $select.val(oldStatus);
            return;
        }

        $select.prop('disabled', true);

        var data = {
            action: 'skyhshoso_update_subscription_ajax',
            nonce: skyhshoso_sa.nonce_update,
            subscription_id: id,
            new_status: newStatus
        };

        $.post(skyhshoso_sa.ajax_url, data, function(res) {
            if (res.success) {
                showNotice('success', res.data.message);
                fetchSubscriptions(currentPage);
            } else {
                showNotice('error', res.data.message || strings.error);
                $select.prop('disabled', false);
            }
        }).fail(function() {
            showNotice('error', strings.error);
            $select.prop('disabled', false);
        });
    });

    $container.on('click', '.sa-delete-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var id = $btn.data('id');

        if (!confirm(strings.confirm_delete)) {
            return;
        }

        $btn.prop('disabled', true).text(__('Deleting...', 'skyhs-hosting-solution'));

        var data = {
            action: 'skyhshoso_delete_subscription_ajax',
            nonce: skyhshoso_sa.nonce_delete,
            subscription_id: id
        };

        $.post(skyhshoso_sa.ajax_url, data, function(res) {
            if (res.success) {
                showNotice('success', res.data.message);
                subscriptions = subscriptions.filter(function(s) { return s.id != id; });
                if (subscriptions.length === 0 && currentPage > 1) {
                    fetchSubscriptions(currentPage - 1);
                } else {
                    fetchSubscriptions(currentPage);
                }
            } else {
                showNotice('error', res.data.message || strings.error);
                $btn.prop('disabled', false).text(__('Delete', 'skyhs-hosting-solution'));
            }
        }).fail(function() {
            showNotice('error', strings.error);
            $btn.prop('disabled', false).text(__('Delete', 'skyhs-hosting-solution'));
        });
    });

    $container.on('click', '.sa-toggle-invoices-btn', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var subId = $btn.data('id');
        var $row = $('#invoices-row-' + subId);

        $row.slideToggle(150);
        $btn.toggleClass('active');
        if ($btn.hasClass('active')) {
            $btn.css('background', '#e2e8f0');
        } else {
            $btn.css('background', '#f0f2f5');
        }
    });

    function createInvoicesRow(s) {
        var invoices = s.invoices || [];
        var html = '<tr id="invoices-row-' + s.id + '" class="sa-invoices-row" style="display:none; background:#f9fafb;">' +
            '  <td colspan="9" style="padding:16px 24px; border-top:1px solid #e5e7eb; border-bottom:1px solid #e5e7eb;">' +
            '    <div style="font-weight:600; font-size:14px; color:#374151; margin-bottom:10px; text-align:left;">' + escapeHtml(__('Invoices & Billing History', 'skyhs-hosting-solution')) + '</div>';

        if (invoices.length === 0) {
            html += '    <p style="color:#6b7280; font-size:13px; font-style:italic; margin:0; text-align:left;">' + escapeHtml(__('No invoices found for this subscription.', 'skyhs-hosting-solution')) + '</p>';
        } else {
            html += '    <table style="width:100%; border-collapse:collapse; font-size:13px; background:#ffffff; border:1px solid #e5e7eb; border-radius:6px; overflow:hidden; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">' +
                '      <thead>' +
                '        <tr style="background:#f3f4f6; color:#4b5563; text-align:left; border-bottom:1px solid #e5e7eb;">' +
                '          <th style="padding:10px 12px; font-weight:600;">' + escapeHtml(__('Order ID', 'skyhs-hosting-solution')) + '</th>' +
                '          <th style="padding:10px 12px; font-weight:600;">' + escapeHtml(__('Date', 'skyhs-hosting-solution')) + '</th>' +
                '          <th style="padding:10px 12px; font-weight:600;">' + escapeHtml(__('Amount', 'skyhs-hosting-solution')) + '</th>' +
                '          <th style="padding:10px 12px; font-weight:600;">' + escapeHtml(__('Status', 'skyhs-hosting-solution')) + '</th>' +
                '          <th style="padding:10px 12px; font-weight:600; text-align:right;">' + escapeHtml(__('Actions', 'skyhs-hosting-solution')) + '</th>' +
                '        </tr>' +
                '      </thead>' +
                '      <tbody>';

            $.each(invoices, function(idx, inv) {
                var statusColor = '#4b5563';
                var statusBg = '#f3f4f6';
                if (inv.status === 'completed' || inv.status === 'processing') {
                    statusColor = '#065f46';
                    statusBg = '#d1fae5';
                } else if (inv.status === 'failed' || inv.status === 'cancelled') {
                    statusColor = '#991b1b';
                    statusBg = '#fee2e2';
                } else if (inv.status === 'pending' || inv.status === 'on-hold') {
                    statusColor = '#92400e';
                    statusBg = '#fef3c7';
                }

                html += '        <tr style="border-bottom:1px solid #e5e7eb;">' +
                    '          <td style="padding:10px 12px; font-weight:600; color:#111827; text-align:left;">#' + escapeHtml(inv.id) + '</td>' +
                    '          <td style="padding:10px 12px; color:#4b5563; text-align:left;">' + escapeHtml(inv.date) + '</td>' +
                    '          <td style="padding:10px 12px; font-weight:600; color:#059669; text-align:left;">' + inv.amount_formatted + '</td>' +
                    '          <td style="padding:10px 12px; text-align:left;">' +
                    '            <span style="display:inline-flex; align-items:center; padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:500; color:' + statusColor + '; background-color:' + statusBg + ';">' +
                    escapeHtml(inv.status_label) +
                    '            </span>' +
                    '          </td>' +
                    '          <td style="padding:10px 12px; text-align:right;">' +
                    '            <a href="' + escapeHtml(inv.url) + '" target="_blank" class="button button-small" style="font-size:11px; padding:2px 8px; height:24px; line-height:22px;">' + escapeHtml(__('View Invoice', 'skyhs-hosting-solution')) + '</a>' +
                    '          </td>' +
                    '        </tr>';
            });

            html += '      </tbody>' +
                '    </table>';
        }

        html += '  </td>' +
            '</tr>';

        return $(html);
    }

    function showNotice(type, msg) {
        $notice.removeClass('success error').addClass(type).html(msg).fadeIn(150);
        setTimeout(function() {
            $notice.fadeOut(300);
        }, 5000);
    }

    // ---- Edit Modal ----
    $container.on('click', '.sa-edit-btn', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        if (window.skyhshosoOpenSubscriptionModal) {
            window.skyhshosoOpenSubscriptionModal(id);
        }
    });

    // Initialize product filter selectWoo autocomplete search
    var $productFilter = $('#sa-product-filter');
    if ($.fn.selectWoo && !$productFilter.data('select2')) {
        $productFilter.selectWoo({
            ajax: {
                url: skyhshoso_sa.ajax_url,
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        action: 'woocommerce_json_search_products',
                        security: skyhshoso_sa.nonce_search_products,
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
            placeholder: __('Filter by product...', 'skyhs-hosting-solution'),
            allowClear: true
        });
    }

    $(document).on('skyhshoso_subscription_updated', function(e, subId) {
        fetchSubscriptions(currentPage);
    });
});
