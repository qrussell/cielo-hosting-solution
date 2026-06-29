/**
 * SkyHS WP Site Manager Admin JS
 */

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

    if (typeof skyhshoso_wpm === 'undefined') {
        return;
    }

    var strings = skyhshoso_wpm.strings;
    var currentPage = 1;
    var totalPages = 1;
    var sites = [];

    var $container = $('#skyhshoso-wpm-container');
    var $notice = $('#skyhshoso-wpm-notice');

    function fetchSites(page) {
        if (!page) page = 1;
        currentPage = page;

        $container.html('<div style="padding:40px;text-align:center;color:#6b7280;">' + escapeHtml(strings.loading) + '</div>');

        $.post(skyhshoso_wpm.ajax_url, {
            action: 'skyhshoso_wp_admin_get_sites',
            nonce: skyhshoso_wpm.nonce,
            paged: currentPage,
            search: $('#wpm-search-input').val(),
            status: $('#wpm-status-filter').val(),
            limit: 10
        }, function(resp) {
            if (resp.success) {
                sites = resp.data.sites;
                totalPages = resp.data.total_pages;
                currentPage = resp.data.current_page;
                renderSites();
                updatePagination();
            } else {
                showNotice('error', resp.data.message || strings.error);
            }
        }).fail(function() {
            showNotice('error', strings.error);
        });
    }

    function renderSites() {
        $container.empty();

        if (sites.length === 0) {
            $container.append('<div class="skyhshoso-hm-empty"><p>' + escapeHtml(strings.no_sites) + '</p></div>');
            return;
        }

        var tableHtml =
            '<table class="skyhshoso-hm-table">' +
            '  <thead>' +
            '    <tr>' +
            '      <th>' + escapeHtml(__('Title', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Domain', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Server', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Subscription', 'skyhs-hosting-solution')) + '</th>' +
            '      <th style="text-align:right;">' + escapeHtml(__('Actions', 'skyhs-hosting-solution')) + '</th>' +
            '    </tr>' +
            '  </thead>' +
            '  <tbody id="skyhshoso-wpm-table-body"></tbody>' +
            '</table>';

        var $table = $(tableHtml);
        var $tbody = $table.find('#skyhshoso-wpm-table-body');

        $.each(sites, function(i, s) {
            var row = createSiteRow(s);
            $tbody.append(row);
        });

        $container.append($table);
    }

    function updatePagination() {
        $('#wpm-page-info').text(__('Page', 'skyhs-hosting-solution') + ' ' + currentPage + ' ' + __('of', 'skyhs-hosting-solution') + ' ' + totalPages);
        $('#wpm-prev-page').prop('disabled', currentPage <= 1);
        $('#wpm-next-page').prop('disabled', currentPage >= totalPages);
    }

    function createSiteRow(s) {
        var badgeClass = 'hm-badge-default';
        var statusLabel = s.sub_status_label || ucwords(s.status.replace(/-/g, ' '));

        switch (s.status) {
            case 'active':
                badgeClass = 'hm-badge-active';
                break;
            case 'pending':
            case 'on-hold':
            case 'pending-cancel':
                badgeClass = 'hm-badge-pending';
                break;
            case 'cancelled':
            case 'suspended':
                badgeClass = 'hm-badge-cancelled';
                break;
        }

        var domainDisplay = s.domain ?
            '<a href="http://' + s.domain + '" target="_blank" style="color:#3b82f6;text-decoration:none;font-weight:600;">' + escapeHtml(s.domain) + '</a>' :
            '<span style="color:#9ca3af;font-style:italic;">' + escapeHtml(__('None', 'skyhs-hosting-solution')) + '</span>';

        var subDisplay = s.subscription_id ?
            '<a href="#" class="skyhshoso-edit-sub-link" data-sub-id="' + s.subscription_id + '" style="font-weight:600; color:#3b82f6; text-decoration:none;">#' + s.subscription_id + '</a>' :
            '<span style="color:#9ca3af;font-style:italic;">' + escapeHtml(__('None', 'skyhs-hosting-solution')) + '</span>';

        var actionsHtml = '';

        if (s.provisioned && s.site_url) {
            actionsHtml += '<a href="' + escapeHtml(s.site_url) + '/wp-admin" target="_blank" class="button button-small hm-btn-sync" style="text-decoration:none;">' + escapeHtml(__('WP Admin', 'skyhs-hosting-solution')) + '</a>';
        }

        if (s.provisioned && s.status === 'active') {
            actionsHtml += '<button class="button button-small wpm-suspend hm-btn-sync" data-id="' + s.id + '">' + escapeHtml(__('Suspend', 'skyhs-hosting-solution')) + '</button>';
        }

        if (s.provisioned && s.status !== 'active' && s.status !== 'pending') {
            actionsHtml += '<button class="button button-small wpm-unsuspend hm-btn-sync" data-id="' + s.id + '">' + escapeHtml(__('Reactivate', 'skyhs-hosting-solution')) + '</button>';
        }

        actionsHtml += '<button class="button button-small wpm-edit hm-btn-edit" data-id="' + s.id + '">' + escapeHtml(__('Edit', 'skyhs-hosting-solution')) + '</button>';

        actionsHtml += '<button class="button button-small wpm-terminate hm-btn-delete" data-id="' + s.id + '">' + escapeHtml(__('Terminate', 'skyhs-hosting-solution')) + '</button>';

        actionsHtml += '<button class="button button-small wpm-delete" data-id="' + s.id + '" style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626;border-radius:6px;font-weight:600;padding:4px 10px;font-size:11px;cursor:pointer;">' + escapeHtml(__('Delete', 'skyhs-hosting-solution')) + '</button>';

        var rowHtml =
            '<tr data-id="' + s.id + '">' +
            '  <td style="font-size:13px;color:#374151;font-weight:600;">' + escapeHtml(s.title) + '</td>' +
            '  <td style="font-size:13px;">' + domainDisplay + '</td>' +
            '  <td style="color:#4b5563;font-size:13px;">' + escapeHtml(s.server) + '</td>' +
            '  <td style="font-size:13px;">' +
            '    <div style="display:flex;align-items:center;gap:8px;">' +
            '      ' + subDisplay +
            '      <span class="hm-badge ' + badgeClass + '">' +
            '        <span class="hm-badge-status-dot"></span>' + escapeHtml(statusLabel) +
            '      </span>' +
            '    </div>' +
            '  </td>' +
            '  <td style="text-align:right;">' +
            '    <div style="display:inline-flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;">' + actionsHtml + '</div>' +
            '  </td>' +
            '</tr>';

        return $(rowHtml);
    }

    function ucwords(str) {
        return String(str).replace(/\b\w/g, function(c) { return c.toUpperCase(); });
    }

    // Pagination
    $('#wpm-prev-page').on('click', function(e) {
        e.preventDefault();
        if (currentPage > 1) fetchSites(currentPage - 1);
    });

    $('#wpm-next-page').on('click', function(e) {
        e.preventDefault();
        if (currentPage < totalPages) fetchSites(currentPage + 1);
    });

    // Search
    var searchTimeout = null;
    $('#wpm-search-input').on('keyup input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            fetchSites(1);
        }, 350);
    });

    // Status filter
    $('#wpm-status-filter').on('change', function() {
        fetchSites(1);
    });

    // Suspend
    $container.on('click', '.wpm-suspend', function() {
        var btn = $(this);
        var id = btn.data('id');
        btn.text(strings.suspending).prop('disabled', true);

        $.post(skyhshoso_wpm.ajax_url, {
            action: 'skyhshoso_wp_admin_suspend',
            wp_site_id: id,
            nonce: skyhshoso_wpm.nonce
        }, function(resp) {
            showNotice(resp.success ? 'success' : 'error', resp.data.message);
            fetchSites(currentPage);
        }).fail(function() {
            showNotice('error', strings.error);
            fetchSites(currentPage);
        });
    });

    // Unsuspend / Reactivate
    $container.on('click', '.wpm-unsuspend', function() {
        var btn = $(this);
        var id = btn.data('id');
        btn.text(strings.reactivating).prop('disabled', true);

        $.post(skyhshoso_wpm.ajax_url, {
            action: 'skyhshoso_wp_admin_unsuspend',
            wp_site_id: id,
            nonce: skyhshoso_wpm.nonce
        }, function(resp) {
            showNotice(resp.success ? 'success' : 'error', resp.data.message);
            fetchSites(currentPage);
        }).fail(function() {
            showNotice('error', strings.error);
            fetchSites(currentPage);
        });
    });

    // Terminate
    $container.on('click', '.wpm-terminate', function() {
        if (!confirm(strings.confirm_terminate)) return;
        var btn = $(this);
        var id = btn.data('id');
        btn.text(strings.terminating).prop('disabled', true);

        $.post(skyhshoso_wpm.ajax_url, {
            action: 'skyhshoso_wp_admin_terminate',
            wp_site_id: id,
            nonce: skyhshoso_wpm.nonce
        }, function(resp) {
            showNotice(resp.success ? 'success' : 'error', resp.data.message);
            fetchSites(currentPage);
        }).fail(function() {
            showNotice('error', strings.error);
            fetchSites(currentPage);
        });
    });

    // Delete (local record only)
    $container.on('click', '.wpm-delete', function() {
        if (!confirm(strings.confirm_delete)) return;
        var btn = $(this);
        var id = btn.data('id');
        btn.text(strings.deleting).prop('disabled', true);

        $.post(skyhshoso_wpm.ajax_url, {
            action: 'skyhshoso_wp_admin_delete',
            wp_site_id: id,
            nonce: skyhshoso_wpm.nonce
        }, function(resp) {
            if (resp.success) {
                showNotice('success', resp.data.message);
                sites = sites.filter(function(s) { return s.id != id; });
                if (sites.length === 0 && currentPage > 1) {
                    fetchSites(currentPage - 1);
                } else {
                    fetchSites(currentPage);
                }
            } else {
                showNotice('error', resp.data.message || strings.error);
                fetchSites(currentPage);
            }
        }).fail(function() {
            showNotice('error', strings.error);
            fetchSites(currentPage);
        });
    });

    function showNotice(type, msg) {
        $notice.removeClass('notice-success notice-error').addClass('notice-' + (type === 'success' ? 'success' : 'error')).show().html('<p>' + msg + '</p>');
        setTimeout(function() {
            $notice.fadeOut(300);
        }, 5000);
    }

    // ──────────────────────────────────────────────
    // Form panel — create / edit WP site
    // ──────────────────────────────────────────────

    var $formPanel = $('#skyhshoso-wpm-form-panel');
    var $formTitle = $('#skyhshoso-wpm-form-title');
    var $form      = $('#skyhshoso-wpm-form');
    var $siteId    = $('#wpm_wp_site_id');
    var $titleInput = $('#wpm_title');
    var $productSelect  = $('#wpm_product_id');
    var $productSearch  = $('#wpm_product_search');
    var $ownerSelect    = $('#wpm_owner_id');
    var $ownerSearch    = $('#wpm_owner_search');
    var $domainInput    = $('#wpm_domain');
    var $loader         = $('#wpm-loader');
    var $cancelBtn      = $('#wpm-cancel-btn');

    // "Add WordPress Site" button
    $('#wpm-add-wp-site-btn').on('click', function() {
        resetForm();
        $formPanel.slideDown(250);
        $('html, body').animate({ scrollTop: $formPanel.offset().top - 40 }, 250);
    });

    // Cancel button
    $('#wpm-cancel-btn').on('click', function() {
        $formPanel.slideUp(250);
    });

    // Autocomplete for product search
    $(document).on('keyup input focus', '#wpm_product_search', function(e) {
        var $input = $(this);
        var term = $input.val().trim();
        var $results = $('#wpm_product_search_results');

        if (term.length < 2) {
            $results.hide().empty();
            return;
        }

        clearTimeout($input.data('product-timeout'));

        var timeout = setTimeout(function() {
            $.post(skyhshoso_wpm.ajax_url, {
                action: 'skyhshoso_search_products',
                nonce: skyhshoso_wpm.nonce_search_products,
                product_type: skyhshoso_wpm.product_type,
                term: term
            }, function(resp) {
                $results.empty();
                if (resp.success && resp.data && resp.data.length > 0) {
                    $.each(resp.data, function(i, product) {
                        var $row = $('<div class="hm-autocomplete-row" style="padding:8px 10px;cursor:pointer;border-bottom:1px solid #eee;font-size:12px;">' + escapeHtml(product.label) + '</div>');

                        $row.hover(function() {
                            $(this).css('background', '#f5f5f5');
                        }, function() {
                            $(this).css('background', '#ffffff');
                        });

                        $row.on('click', function() {
                            $input.val(product.label);
                            $productSelect.val(product.id);
                            // Auto-fill title if empty
                            if (!$titleInput.val()) {
                                $titleInput.val(product.label.split(' — ')[0]);
                            }
                            $results.hide().empty();
                        });
                        $results.append($row);
                    });
                    $results.show();
                } else {
                    $results.html('<div style="padding:10px;color:#888;font-size:12px;">' + escapeHtml(__('No products found', 'skyhs-hosting-solution')) + '</div>').show();
                }
            }).fail(function() {
                $results.html('<div style="padding:10px;color:#dc2626;font-size:12px;">' + escapeHtml(__('Error searching products', 'skyhs-hosting-solution')) + '</div>').show();
            });
        }, 300);

        $input.data('product-timeout', timeout);
    });

    // Toggle existing subscription container
    $('#wpm_sub_action').on('change', function() {
        var val = $(this).val();
        if (val === 'link') {
            $('#wpm_existing_sub_container').fadeIn(150);
            $('#wpm_existing_sub_id').val('');
            $('#wpm_existing_sub_search').val('');
        } else {
            $('#wpm_existing_sub_container').fadeOut(150);
            if (val === 'create') {
                $('#wpm_existing_sub_id').val('');
                $('#wpm_existing_sub_search').val('');
            }
        }
    });

    // Subscription autocomplete search
    var subSearchTimeout = null;
    $('#wpm_existing_sub_search').on('keyup input', function() {
        var $input = $(this);
        var term = $input.val().trim();
        var $results = $('#wpm-sub-search-results');

        clearTimeout(subSearchTimeout);
        if (term.length < 1) {
            $results.hide().empty();
            return;
        }

        subSearchTimeout = setTimeout(function() {
            $.post(skyhshoso_wpm.ajax_url, {
                action: 'skyhshoso_search_subscriptions',
                nonce: skyhshoso_wpm.nonce_search_subs,
                term: term
            }, function(res) {
                if (res.success && res.data.length > 0) {
                    $results.empty();
                    $.each(res.data, function(i, item) {
                        var $row = $('<div class="hm-autocomplete-row" style="padding:10px;cursor:pointer;border-bottom:1px solid #eee;font-size:12px;" data-id="' + item.id + '">' + escapeHtml(item.label) + '</div>');
                        $row.hover(function() {
                            $(this).css('background', '#f5f5f5');
                        }, function() {
                            $(this).css('background', '#ffffff');
                        });
                        $row.on('click', function() {
                            $('#wpm_existing_sub_id').val(item.id);
                            $input.val(item.label);
                            $results.hide().empty();
                        });
                        $results.append($row);
                    });
                    $results.show();
                } else {
                    $results.html('<div style="padding:10px;color:#888;font-size:12px;">' + escapeHtml(__('No subscriptions found', 'skyhs-hosting-solution')) + '</div>').show();
                }
            });
        }, 300);
    });

    // Hide autocomplete results when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.hm-search-sub-wrapper').length) {
            $('#wpm-sub-search-results').hide();
        }
        if (!$(e.target).closest('.hm-search-owner-wrapper').length) {
            $('#wpm_owner_search_results').hide();
        }
        if (!$(e.target).closest('.hm-search-product-wrapper').length) {
            $('#wpm_product_search_results').hide();
        }
    });

    // Autocomplete for owner search
    $(document).on('keyup input focus', '#wpm_owner_search', function(e) {
        var $input = $(this);
        var term = $input.val().trim();
        var $results = $('#wpm_owner_search_results');

        if (term.length < 1) {
            $results.hide().empty();
            return;
        }

        clearTimeout($input.data('owner-timeout'));

        var timeout = setTimeout(function() {
            $.post(skyhshoso_wpm.ajax_url, {
                action: 'skyhshoso_search_users',
                nonce: skyhshoso_wpm.nonce_search_users,
                term: term
            }, function(resp) {
                $results.empty();
                if (resp.success && resp.data && resp.data.length > 0) {
                    $.each(resp.data, function(i, user) {
                        var $row = $('<div class="hm-autocomplete-row" style="padding:8px 10px;cursor:pointer;border-bottom:1px solid #eee;font-size:12px;">' + escapeHtml(user.label) + '</div>');

                        $row.hover(function() {
                            $(this).css('background', '#f5f5f5');
                        }, function() {
                            $(this).css('background', '#ffffff');
                        });

                        $row.on('click', function() {
                            $input.val(user.label);
                            $ownerSelect.val(user.id);
                            $results.hide().empty();
                        });
                        $results.append($row);
                    });
                    $results.show();
                } else {
                    $results.html('<div style="padding:10px;color:#888;font-size:12px;">No users found</div>').show();
                }
            }).fail(function() {
                $results.html('<div style="padding:10px;color:#dc2626;font-size:12px;">Error searching users</div>').show();
            });
        }, 250);

        $input.data('owner-timeout', timeout);
    });

    // Edit button on row
    $container.on('click', '.wpm-edit', function() {
        var id = $(this).data('id');

        $.post(skyhshoso_wpm.ajax_url, {
            action: 'skyhshoso_wp_admin_get_details',
            wp_site_id: id,
            nonce: skyhshoso_wpm.nonce
        }, function(resp) {
            if (resp.success) {
                var d = resp.data;
                $siteId.val(d.id);
                $titleInput.val(d.title);
                // Build compound product ID value: "parent_id" or "parent_id|variation_id"
                var prodVal = d.product_id;
                if (d.variation_id) {
                    prodVal = d.product_id + '|' + d.variation_id;
                }
                $productSearch.val(d.product_title);
                $productSelect.val(prodVal);
                $ownerSearch.val(d.owner_name);
                $ownerSelect.val(d.owner_id);
                $domainInput.val(d.domain);

                if (d.subscription_id) {
                    $('#wpm-current-sub-info').show();
                    $('#wpm-current-sub-info').html('<span style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:12px;color:#166534;"><strong>' + escapeHtml(__('Current Subscription:', 'skyhs-hosting-solution')) + '</strong> #' + d.subscription_id + '</span>');
                    $('#wpm_sub_action').val('keep');
                } else {
                    $('#wpm-current-sub-info').hide().empty();
                    $('#wpm_sub_action').val('create');
                }
                $('#wpm_existing_sub_container').hide();

                $formTitle.text(__('Edit WordPress Site', 'skyhs-hosting-solution'));
                $cancelBtn.show();
                $formPanel.slideDown(250);
                $('html, body').animate({ scrollTop: $formPanel.offset().top - 40 }, 250);
            } else {
                showNotice('error', resp.data.message || strings.error);
            }
        }).fail(function() {
            showNotice('error', strings.error);
        });
    });

    // Submit form
    $form.on('submit', function(e) {
        e.preventDefault();

        var pid = $productSelect.val();
        var oid = $ownerSelect.val();

        if (!pid || !oid) {
            showNotice('error', skyhshoso_wpm.strings.fill_fields);
            return;
        }

        $loader.show();
        $notice.hide();

        $.post(skyhshoso_wpm.ajax_url, {
            action: 'skyhshoso_wp_admin_save',
            nonce: skyhshoso_wpm.nonce_save,
            wp_site_id: $siteId.val(),
            title: $titleInput.val(),
            product_id: pid,
            owner_id: oid,
            domain: $domainInput.val(),
            sub_action: $('#wpm_sub_action').val(),
            existing_sub_id: $('#wpm_existing_sub_id').val()
        }, function(res) {
            $loader.hide();
            if (res.success) {
                showNotice('success', res.data.message);
                resetForm();
                $formPanel.slideUp(250);
                fetchSites(currentPage);
            } else {
                showNotice('error', res.data.message || strings.error);
            }
        }).fail(function() {
            $loader.hide();
            showNotice('error', strings.error);
        });
    });

    function resetForm() {
        $form[0].reset();
        $siteId.val('0');
        $titleInput.val('');
        $productSearch.val('');
        $productSelect.val('');
        $('#wpm_product_search_results').hide().empty();
        $ownerSearch.val('');
        $ownerSelect.val('');
        $('#wpm_owner_search_results').hide().empty();
        $domainInput.val('');
        $('#wpm-current-sub-info').hide().empty();
        $('#wpm_existing_sub_container').hide();
        $('#wpm_existing_sub_id').val('');
        $('#wpm_existing_sub_search').val('');
        $formTitle.text(__('Create WordPress Site', 'skyhs-hosting-solution'));
        $cancelBtn.hide();
    }

    // Initial load
    fetchSites(1);

    $(document).on('skyhshoso_subscription_updated', function(e, subId) {
        fetchSites(currentPage);
    });
});
