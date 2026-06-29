/**
 * SkyHS Custom Hosting Manager JS
 * Polished, reactive, and sleek user experience.
 */

jQuery(document).ready(function($) {
    // Translation helper fallback to prevent ReferenceError
    function __(key, domain) {
        if (typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n.__ === 'function') {
            return wp.i18n.__(key, domain);
        }
        return key;
    }

    // HTML escape helper to prevent external WordPress script dependencies from crashing the page
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    if (typeof skyhshoso_hm === 'undefined') {
        return;
    }

    // State tracking
    var currentPage = 1;
    var totalPages = 1;
    var hostings = [];

    var strings = skyhshoso_hm.strings;

    // Dom cache
    var $container = $('#skyhshoso-hm-container');
    var $form = $('#skyhshoso-hm-form');
    var $formPanel = $('#skyhshoso-hm-form-panel');
    var $formTitle = $('#skyhshoso-hm-form-title');
    var $hostingId = $('#hm_hosting_id');
    var $titleInput = $('#hm_title');
    var $productSelect = $('#hm_product_id');
    var $productSearch = $('#hm_product_search');
    var $ownerSelect = $('#hm_owner_id');
    var $ownerSearch = $('#hm_owner_search');
    var $domainInput = $('#hm_domain');
    var $cancelBtn = $('#hm-cancel-btn');
    var $loader = $('#hm-loader');
    var $notice = $('#skyhshoso-hm-notice');

    // Track selected product metadata
    var selectedProductServerId = null;

    // Initial AJAX list fetch
    fetchHostings(1);

    // Toggle Account Source (new vs existing cPanel vs none)
    $('#hm_account_source').on('change', function() {
        var val = $(this).val();
        var $domainContainer = $('#hm_domain_container');
        if (val === 'existing') {
            var $search = $('#hm_existing_cpanel_user_search');
            var $hidden = $('#hm_existing_cpanel_user');
            var $results = $('#hm_existing_cpanel_search_results');
            $results.hide().empty();
            if ($productSelect.val() && selectedProductServerId) {
                $search.prop('disabled', false);
            } else {
                $search.prop('disabled', true).val('');
                $hidden.val('');
            }
            $('#hm_existing_account_container').fadeIn(150);
            $domainContainer.fadeOut(150);
            $('#hm-cpanel-user-display').hide();
        } else if (val === 'new') {
            $('#hm_existing_account_container').fadeOut(150);
            $('#hm_existing_cpanel_user').val('');
            $('#hm_existing_cpanel_user_search').val('').prop('disabled', true);
            $('#hm_existing_cpanel_search_results').hide().empty();
            $('#hm-cpanel-user-display').hide();
            $domainContainer.fadeIn(150);
        } else {
            $('#hm_existing_account_container').fadeOut(150);
            $('#hm_existing_cpanel_user').val('');
            $('#hm_existing_cpanel_user_search').val('').prop('disabled', true);
            $('#hm_existing_cpanel_search_results').hide().empty();
            $('#hm-cpanel-user-display').hide();
            $domainContainer.fadeOut(150);
        }
    });

    // Autocomplete for product search
    $(document).on('keyup input focus', '#hm_product_search', function(e) {
        var $input = $(this);
        var term = $input.val().trim();
        var $results = $('#hm_product_search_results');

        if (term.length < 2) {
            $results.hide().empty();
            return;
        }

        clearTimeout($input.data('product-timeout'));

        var timeout = setTimeout(function() {
            $.post(skyhshoso_hm.ajax_url, {
                action: 'skyhshoso_search_products',
                nonce: skyhshoso_hm.nonce_search_products,
                product_type: skyhshoso_hm.product_type,
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
                            selectedProductServerId = product.server_id || null;
                            // Auto-fill title if empty
                            if (!$titleInput.val()) {
                                $titleInput.val(product.label.split(' — ')[0]);
                            }
                            // Enable/disable cPanel search based on server
                            var sourceVal = $('#hm_account_source').val();
                            var $search = $('#hm_existing_cpanel_user_search');
                            var $hidden = $('#hm_existing_cpanel_user');
                            var $cpanelResults = $('#hm_existing_cpanel_search_results');
                            $cpanelResults.hide().empty();
                            if (product.server_id && sourceVal === 'existing') {
                                $search.prop('disabled', false);
                            } else {
                                $search.prop('disabled', true).val('');
                                $hidden.val('');
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

    // Autocomplete for existing cPanel account search
    $(document).on('keyup input focus', '#hm_existing_cpanel_user_search', function(e) {
        var $input = $(this);
        var serverId = selectedProductServerId;
        var $results = $('#hm_existing_cpanel_search_results');
        var term = $input.val().trim();

        if (!serverId) {
            $results.hide().empty();
            return;
        }

        clearTimeout($input.data('search-timeout'));

        var timeout = setTimeout(function() {
            $.post(skyhshoso_hm.ajax_url, {
                action: 'skyhshoso_get_cpanel_accounts',
                nonce: skyhshoso_hm.nonce_cpanel_accounts,
                server_id: serverId,
                term: term
            }, function(resp) {
                $results.empty();
                if (resp.success && resp.data && resp.data.length > 0) {
                    $.each(resp.data, function(i, account) {
                        var label = account.username + ' (' + account.domain + ')';
                        var $row = $('<div class="hm-autocomplete-row" style="padding:8px 10px;cursor:pointer;border-bottom:1px solid #eee;font-size:12px;">' + label + '</div>');

                        $row.hover(function() {
                            $(this).css('background', '#f5f5f5');
                        }, function() {
                            $(this).css('background', '#ffffff');
                        });

                        $row.on('click', function() {
                            $input.val(account.username);
                            $('#hm_existing_cpanel_user').val(account.username);
                            if (account.domain) {
                                $domainInput.val(account.domain);
                            }
                            $results.hide().empty();
                        });
                        $results.append($row);
                    });
                    $results.show();
                } else {
                    $results.html('<div style="padding:10px;color:#888;font-size:12px;">No accounts found</div>').show();
                }
            }).fail(function() {
                $results.html('<div style="padding:10px;color:#dc2626;font-size:12px;">Error loading accounts</div>').show();
            });
        }, 250);

        $input.data('search-timeout', timeout);
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('#hm_existing_account_container').length) {
            $('#hm_existing_cpanel_search_results').hide();
        }
        if (!$(e.target).closest('.hm-search-owner-wrapper').length) {
            $('#hm_owner_search_results').hide();
        }
        if (!$(e.target).closest('.hm-search-product-wrapper').length) {
            $('#hm_product_search_results').hide();
        }
    });

    // Autocomplete for owner search
    $(document).on('keyup input focus', '#hm_owner_search', function(e) {
        var $input = $(this);
        var term = $input.val().trim();
        var $results = $('#hm_owner_search_results');

        if (term.length < 1) {
            $results.hide().empty();
            return;
        }

        clearTimeout($input.data('owner-timeout'));

        var timeout = setTimeout(function() {
            $.post(skyhshoso_hm.ajax_url, {
                action: 'skyhshoso_search_users',
                nonce: skyhshoso_hm.nonce_search_users,
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

    // "Add Hosting" button — open form panel in create mode
    $('#hm-add-hosting-btn').on('click', function() {
        resetForm();
        $formPanel.slideDown(250);
        $('html, body').animate({ scrollTop: $formPanel.offset().top - 40 }, 250);
    });

    // Toggle existing subscription container
    $('#hm_sub_action').on('change', function() {
        var val = $(this).val();
        if (val === 'link') {
            $('#hm_existing_sub_container').fadeIn(150);
            $('#hm_existing_sub_id').val('');
            $('#hm_existing_sub_search').val('');
        } else {
            $('#hm_existing_sub_container').fadeOut(150);
            if (val === 'create') {
                $('#hm_existing_sub_id').val('');
                $('#hm_existing_sub_search').val('');
            }
        }
    });

    // Subscription Autocomplete Search
    var subSearchTimeout = null;
    $('#hm_existing_sub_search').on('keyup input', function() {
        var $input = $(this);
        var term = $input.val().trim();
        var $results = $('#hm-sub-search-results');

        clearTimeout(subSearchTimeout);
        if (term.length < 1) {
            $results.hide().empty();
            return;
        }

        subSearchTimeout = setTimeout(function() {
            var data = {
                action: 'skyhshoso_search_subscriptions',
                nonce: skyhshoso_hm.nonce_search_subs,
                term: term
            };

            $.post(skyhshoso_hm.ajax_url, data, function(res) {
                if (res.success && res.data.length > 0) {
                    $results.empty();
                    $.each(res.data, function(i, item) {
                        var $row = $('<div class="hm-autocomplete-row" style="padding:10px;cursor:pointer;border-bottom:1px solid #eee;font-size:12px;" data-id="' + item.id + '">' + escapeHtml(item.label) + '</div>');
                        
                        // Hover highlight styling
                        $row.hover(function() {
                            $(this).css('background', '#f5f5f5');
                        }, function() {
                            $(this).css('background', '#ffffff');
                        });

                        $row.on('click', function() {
                            $('#hm_existing_sub_id').val(item.id);
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
            $('#hm-sub-search-results').hide();
        }
    });

    // Fetch dynamic hostings paginated list
    function fetchHostings(page) {
        if (!page) page = 1;
        currentPage = page;
        $loader.show();

        var data = {
            action: 'skyhshoso_get_hostings',
            nonce: skyhshoso_hm.nonce_get,
            paged: currentPage,
            search: $('#hm-search-input').val(),
            status: $('#hm-status-filter').val(),
            limit: 10
        };

        $.post(skyhshoso_hm.ajax_url, data, function(res) {
            $loader.hide();
            if (res.success) {
                hostings = res.data.hostings;
                totalPages = res.data.total_pages;
                currentPage = res.data.current_page;
                
                renderHostings();
                updatePagination();
            } else {
                showNotice('error', res.data.message || strings.error);
            }
        }).fail(function() {
            $loader.hide();
            showNotice('error', strings.error);
        });
    }

    function renderHostings() {
        $container.empty();

        if (hostings.length === 0) {
            $container.append('<div class="skyhshoso-hm-empty"><p>' + escapeHtml(__('No hosting deployments found matching the criteria.', 'skyhs-hosting-solution')) + '</p></div>');
            return;
        }

        var tableHtml = '<table class="skyhshoso-hm-table">' +
            '  <thead>' +
            '    <tr>' +
            '      <th>' + escapeHtml(__('Product / Plan', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Owner', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Domain', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Subscription', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Server', 'skyhs-hosting-solution')) + '</th>' +
            '      <th style="text-align:right;">' + escapeHtml(__('Actions', 'skyhs-hosting-solution')) + '</th>' +
            '    </tr>' +
            '  </thead>' +
            '  <tbody id="skyhshoso-hm-table-body"></tbody>' +
            '</table>';

        var $table = $(tableHtml);
        var $tbody = $table.find('#skyhshoso-hm-table-body');

        $.each(hostings, function(i, h) {
            var row = createHostingRow(h);
            $tbody.append(row);
        });

        $container.append($table);
    }

    function updatePagination() {
        $('#hm-page-info').text(__('Page', 'skyhs-hosting-solution') + ' ' + currentPage + ' ' + __('of', 'skyhs-hosting-solution') + ' ' + totalPages);
        $('#hm-prev-page').prop('disabled', currentPage <= 1);
        $('#hm-next-page').prop('disabled', currentPage >= totalPages);
    }

    // Pagination events
    $('#hm-prev-page').on('click', function(e) {
        e.preventDefault();
        if (currentPage > 1) {
            fetchHostings(currentPage - 1);
        }
    });

    $('#hm-next-page').on('click', function(e) {
        e.preventDefault();
        if (currentPage < totalPages) {
            fetchHostings(currentPage + 1);
        }
    });

    // Reactive search and filter
    var searchTimeout = null;
    $('#hm-search-input').on('keyup input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            fetchHostings(1);
        }, 350);
    });

    $('#hm-status-filter').on('change', function() {
        fetchHostings(1);
    });

    function createHostingRow(h) {
        // subscription badge class
        var badgeClass = 'hm-badge-default';
        var statusLabel = h.sub_status_label;
        
        switch (h.sub_status) {
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

        var domainDisplay = h.domain ? '<a href="http://' + h.domain + '" target="_blank" style="color:#3b82f6; text-decoration:none; font-weight:600;">' + escapeHtml(h.domain) + '</a>' : '<span style="color:#9ca3af; font-style:italic;">' + escapeHtml(__('None mapped', 'skyhs-hosting-solution')) + '</span>';
        var cpanelDisplay = '';
        if (h.cpanel_username) {
            cpanelDisplay = h.cpanel_username;
        } else if (h.server_title && h.server_title !== '—') {
            cpanelDisplay = '<span style="color:#9ca3af;font-style:italic;">' + escapeHtml(__('Pending provisioning', 'skyhs-hosting-solution')) + '</span>';
        }
        var subDisplay = h.subscription_id ? '<a href="#" class="skyhshoso-edit-sub-link" data-sub-id="' + h.subscription_id + '" style="font-weight:600; color:#3b82f6; text-decoration:none;">#' + h.subscription_id + '</a>' : '<span style="color:#9ca3af; font-style:italic;">' + escapeHtml(__('None', 'skyhs-hosting-solution')) + '</span>';

        var productDisplay = '<strong>' + escapeHtml(h.product_title) + '</strong>';
        if (h.plan && h.plan !== '—') {
            productDisplay += '<br/><span style="color:#6b7280; font-size:11px;">' + escapeHtml(h.plan) + '</span>';
        }

        var rowHtml = '<tr data-id="' + h.id + '">' +
            '  <td style="font-size:13px; color:#374151;">' + productDisplay + '</td>' +
            '  <td style="color:#4b5563; font-size:13px;" title="' + escapeHtml(h.owner_name) + '">' + escapeHtml(h.owner_name) + '</td>' +
            '  <td style="font-size:13px;">' + domainDisplay + '</td>' +
            '  <td style="font-size:13px;">' +
            '    <div style="display:flex; align-items:center; gap:8px;">' +
            '      <span style="font-weight:600;">' + subDisplay + '</span>' +
            '      <span class="hm-badge ' + badgeClass + '">' +
            '        <span class="hm-badge-status-dot"></span>' + escapeHtml(statusLabel) +
            '      </span>' +
            '    </div>' +
            '  </td>' +
            '  <td style="color:#4b5563; font-size:13px;">' + escapeHtml(h.server_title) + '<br/><span style="font-size:11px;color:#6b7280;">' + cpanelDisplay + '</span></td>' +
            '  <td style="text-align:right;">' +
            '    <div style="display:inline-flex; gap:6px;">' +
            '      <button class="hm-btn-edit button button-small" data-id="' + h.id + '">' + escapeHtml(__('Edit', 'skyhs-hosting-solution')) + '</button>' +
            '      <button class="hm-btn-sync button button-small" data-id="' + h.id + '">' + escapeHtml(__('Sync', 'skyhs-hosting-solution')) + '</button>' +
            '      <button class="hm-btn-delete button button-small" data-id="' + h.id + '">' + escapeHtml(__('Delete', 'skyhs-hosting-solution')) + '</button>' +
            '    </div>' +
            '  </td>' +
            '</tr>';

        return $(rowHtml);
    }

    // Edit button click
    $container.on('click', '.hm-btn-edit', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var h = hostings.find(x => x.id == id);
        if (!h) return;

        // Set inputs
        $hostingId.val(h.id);
        $titleInput.val(h.title);
        $productSearch.val(h.product_title);
        $productSelect.val(h.product_id);
        selectedProductServerId = h.server_id || null;
        
        $ownerSearch.val(h.owner_name);
        $ownerSelect.val(h.owner_id);
        $domainInput.val(h.domain);

        // Show cPanel user if connected to existing account
        var accountSource = h.account_source || 'new';
        $('#hm_account_source').val(accountSource);
        if (accountSource === 'existing' && h.cpanel_username) {
            $('#hm_existing_cpanel_user').val(h.cpanel_username);
            $('#hm_existing_cpanel_user_search').val(h.cpanel_username).prop('disabled', false);
            $('#hm_existing_account_container').show();
            $('#hm-cpanel-user-value').text(h.cpanel_username);
            $('#hm-cpanel-user-display').show();
            $('#hm_domain_container').hide();
        } else if (accountSource === 'new') {
            $('#hm_existing_account_container').hide();
            $('#hm-cpanel-user-display').hide();
            $('#hm_domain_container').show();
        } else {
            $('#hm_existing_account_container').hide();
            $('#hm-cpanel-user-display').hide();
            $('#hm_domain_container').hide();
        }

        // Populate subscription settings
        if (h.subscription_id) {
            // Show current subscription info
            $('#hm-current-sub-info').html(
                '<span style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:12px;color:#166534;">' +
                '<strong>' + __('Current:', 'skyhs-hosting-solution') + '</strong> #' + h.subscription_id + ' — ' + escapeHtml(h.sub_status_label) +
                '</span>'
            ).show();
            // Default to "keep" — don't change anything
            $('#hm_sub_action').val('keep');
            $('#hm_existing_sub_id').val(h.subscription_id);
            $('#hm_existing_sub_search').val('');
            $('#hm_existing_sub_container').hide();
        } else {
            $('#hm-current-sub-info').hide().empty();
            $('#hm_sub_action').val('create');
            $('#hm_existing_sub_id').val('');
            $('#hm_existing_sub_search').val('');
            $('#hm_existing_sub_container').hide();
        }

        // UI transitions
        $formTitle.text(__('Edit Hosting Account', 'skyhs-hosting-solution'));
        $cancelBtn.show();
        $formPanel.slideDown(250, function() {
            $('html, body').animate({ scrollTop: $formPanel.offset().top - 40 }, 250);
        });
    });

    // Cancel edit
    $cancelBtn.on('click', function(e) {
        e.preventDefault();
        resetForm();
        $formPanel.slideUp(250);
    });

    function resetForm() {
        $hostingId.val('0');
        $titleInput.val('');
        $productSearch.val('');
        $productSelect.val('');
        selectedProductServerId = null;
        $('#hm_product_search_results').hide().empty();
        $ownerSearch.val('');
        $ownerSelect.val('');
        $('#hm_owner_search_results').hide().empty();
        $domainInput.val('');
        $('#hm_account_source').val('new');
        $('#hm_domain_container').show();
        $('#hm_existing_account_container').hide();
        $('#hm-cpanel-user-display').hide();
        $('#hm_existing_cpanel_user').val('');
        $('#hm_existing_cpanel_user_search').val('').prop('disabled', true);
        $('#hm_existing_cpanel_search_results').hide().empty();
        $('#hm-no-accounts-msg').hide();
        $('#hm-cpanel-user-display').hide();
        $('#hm_sub_action').val('create');
        $('#hm_existing_sub_id').val('');
        $('#hm_existing_sub_search').val('');
        $('#hm_existing_sub_container').hide();
        $('#hm-current-sub-info').hide().empty();
        $formTitle.text(__('Create Hosting Account', 'skyhs-hosting-solution'));
        $cancelBtn.hide();
    }

    // Submit save hosting form via AJAX
    $form.on('submit', function(e) {
        e.preventDefault();

        var pid = $productSelect.val();
        var oid = $ownerSelect.val();

        if (!pid || !oid) {
            showNotice('error', strings.fill_fields);
            return;
        }

        $loader.show();
        $notice.hide();

        var accountSource = $('#hm_account_source').val();
        var existingCpanelUser = accountSource === 'existing' ? $('#hm_existing_cpanel_user').val() : '';

        var data = {
            action: 'skyhshoso_save_hosting',
            nonce: skyhshoso_hm.nonce_save,
            hosting_id: $hostingId.val(),
            title: $titleInput.val(),
            product_id: pid,
            owner_id: oid,
            domain: $domainInput.val(),
            account_source: accountSource,
            sub_action: $('#hm_sub_action').val(),
            existing_sub_id: $('#hm_existing_sub_id').val(),
            existing_cpanel_user: existingCpanelUser
        };

        $.post(skyhshoso_hm.ajax_url, data, function(res) {
            $loader.hide();
            if (res.success) {
                showNotice('success', res.data.message);
                resetForm();
                $formPanel.slideUp(250);
                fetchHostings(currentPage);
            } else {
                showNotice('error', res.data.message || strings.error);
            }
        }).fail(function() {
            $loader.hide();
            showNotice('error', strings.error);
        });
    });

    // Delete hosting row via AJAX
    $container.on('click', '.hm-btn-delete', function(e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        var id = $(this).data('id');

        if (!confirm(strings.confirm_delete)) {
            return;
        }

        var data = {
            action: 'skyhshoso_delete_hosting',
            nonce: skyhshoso_hm.nonce_delete,
            hosting_id: id
        };

        $.post(skyhshoso_hm.ajax_url, data, function(res) {
            if (res.success) {
                $row.fadeOut(400, function() {
                    $row.remove();
                    hostings = hostings.filter(x => x.id != id);
                    if (hostings.length === 0) {
                        fetchHostings(currentPage > 1 ? currentPage - 1 : 1);
                    } else {
                        fetchHostings(currentPage);
                    }
                });
            } else {
                alert(res.data.message || strings.error);
            }
        }).fail(function() {
            alert(strings.error);
        });
    });

    // Sync individual row status dynamically
    $container.on('click', '.hm-btn-sync', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var id = $btn.data('id');

        $btn.text(__('Syncing...', 'skyhs-hosting-solution')).prop('disabled', true);

        var data = {
            action: 'skyhshoso_quick_sync_hosting',
            nonce: skyhshoso_hm.nonce_sync,
            hosting_id: id
        };

        $.post(skyhshoso_hm.ajax_url, data, function(res) {
            $btn.text(__('Sync', 'skyhs-hosting-solution')).prop('disabled', false);

            if (res.success) {
                var h = res.data;
                var idx = hostings.findIndex(x => x.id == id);
                if (idx !== -1) {
                    hostings[idx] = h;
                }

                var newRow = createHostingRow(h);
                $row.replaceWith(newRow);
            } else {
                alert(res.data.message || strings.error);
            }
        }).fail(function() {
            $btn.text(__('Sync', 'skyhs-hosting-solution')).prop('disabled', false);
            alert(strings.error);
        });
    });

    function showNotice(type, msg) {
        $notice.removeClass('success error').addClass(type).html(msg).fadeIn(150);
        setTimeout(function() {
            $notice.fadeOut(300);
        }, 5000);
    }

    $(document).on('skyhshoso_subscription_updated', function(e, subId) {
        fetchHostings(currentPage);
    });
});
