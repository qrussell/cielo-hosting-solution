/**
 * SkyHS Product Manager UI
 */
jQuery(document).ready(function($) {
    'use strict';

    var data = window.skyhshoso_pm || {};

    // Dom cache
    var $formPanel = $('#skyhshoso-pm-form-panel');
    var $formTitle = $('#skyhshoso-pm-form-title');
    var $cancelBtn = $('#pm-cancel-btn');
    var $form = $('#skyhshoso-pm-form');
    var $notice = $('#skyhshoso-pm-notice');
    var $loader = $('#pm-loader');

    // WP Site Dom cache
    var $wpsFormPanel = $('#skyhshoso-wps-form-panel');
    var $wpsFormTitle = $('#skyhshoso-wps-form-title');
    var $wpsCancelBtn = $('#wps-cancel-btn');
    var $wpsForm = $('#skyhshoso-wps-form');
    var $wpsLoader = $('#wps-loader');

    // Translation helper fallback to prevent ReferenceError
    function __(key, domain) {
        if (typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n.__ === 'function') {
            return wp.i18n.__(key, domain);
        }
        return key;
    }

    // HTML escape helper
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // State tracking
    var currentPage = 1;
    var totalPages = 1;
    var $container = $('#skyhshoso-pm-container');
    var $listLoader = $('#pm-list-loader');

    // Fetch dynamic products list
    function fetchProducts(page) {
        if (!page) page = 1;
        currentPage = page;
        $listLoader.show().addClass('is-active');

        var postData = {
            action: 'skyhshoso_get_products',
            nonce: data.nonce_get,
            paged: currentPage,
            search: $('#pm-search-input').val(),
            product_type: $('#pm-type-filter').val(),
            payment: $('#pm-payment-filter').val(),
            limit: 10
        };

        $.post(data.ajax_url, postData, function(res) {
            $listLoader.hide().removeClass('is-active');
            if (res.success) {
                data.products = res.data.products;
                totalPages = res.data.total_pages;
                currentPage = res.data.current_page;
                
                renderProducts();
                updatePagination();
            } else {
                showNotice('error', res.data.message || 'Error loading products.');
            }
        }).fail(function() {
            $listLoader.hide().removeClass('is-active');
            showNotice('error', 'Error loading products.');
        });
    }

    function renderProducts() {
        $container.empty();

        if (!data.products || data.products.length === 0) {
            $container.append('<div class="skyhshoso-hm-empty"><p>' + escapeHtml(__('No products found matching the criteria.', 'skyhs-hosting-solution')) + '</p></div>');
            return;
        }

        var tableHtml = '<div style="overflow-x:auto;">' +
            '<table class="skyhshoso-hm-table" id="skyhshoso-pm-list">' +
            '  <thead>' +
            '    <tr>' +
            '      <th>' + escapeHtml(__('Name', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Type', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Price', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Subscription', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Server', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Plan/Config', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Actions', 'skyhs-hosting-solution')) + '</th>' +
            '    </tr>' +
            '  </thead>' +
            '  <tbody id="skyhshoso-pm-list-body"></tbody>' +
            '</table>' +
            '</div>';

        var $table = $(tableHtml);
        var $tbody = $table.find('#skyhshoso-pm-list-body');

        $.each(data.products, function(i, p) {
            var row = createProductRow(p);
            $tbody.append(row);
        });

        $container.append($table);
    }

    function createProductRow(p) {
        var name = p.name;
        var typeLabel = p.type_label;
        var priceDisplay = p.price_display; // Keep raw HTML for WC price formatting
        var subscription = p.is_sub ? __('Yes', 'skyhs-hosting-solution') : __('No', 'skyhs-hosting-solution');
        var server = p.server ? p.server : '—';
        var planConfig = '';

        if (p.type === 'skyhshoso_wp_site') {
            if (p.is_variable) {
                planConfig = __('Multiple Plans', 'skyhs-hosting-solution');
            } else {
                var storageDisplay = parseInt(p.wp_storage);
                if (storageDisplay >= 1024) {
                    storageDisplay = (Math.round((storageDisplay / 1024) * 100) / 100) + 'GB';
                } else {
                    storageDisplay = storageDisplay + 'MB';
                }
                planConfig = p.wp_host_user + ' (' + storageDisplay + ' / ' + p.wp_memory + ')';
            }
        } else {
            planConfig = p.plan ? p.plan : '—';
        }

        var editLabel = __('Edit', 'skyhs-hosting-solution');
        var deleteLabel = __('Delete', 'skyhs-hosting-solution');
        var copyTitle = __('Copy shortcode', 'skyhs-hosting-solution');
        var shortcode = '[skyhshoso_hosting_plan id="' + p.id + '"]';

        var rowHtml = '<tr>' +
            '  <td><strong>' + escapeHtml(name) + '</strong></td>' +
            '  <td>' + escapeHtml(typeLabel) + '</td>' +
            '  <td>' + priceDisplay + '</td>' +
            '  <td>' + escapeHtml(subscription) + '</td>' +
            '  <td>' + escapeHtml(server) + '</td>' +
            '  <td>' + escapeHtml(planConfig) + '</td>' +
            '  <td>' +
            '    <div style="display:inline-flex;gap:6px;align-items:center;">' +
            '      <button type="button" class="button button-small pm-edit-product" data-id="' + p.id + '">' +
            '        ' + escapeHtml(editLabel) +
            '      </button>' +
            '      <button type="button" class="button button-small pm-delete-product" data-id="' + p.id + '" style="color:#dc2626;border-color:#fecaca;">' +
            '        ' + escapeHtml(deleteLabel) +
            '      </button>' +
            '      <span class="pm-copy-shortcode dashicons dashicons-admin-page" data-shortcode="' + escapeHtml(shortcode) + '" title="' + escapeHtml(copyTitle) + '" style="cursor:pointer;color:#2271b1;font-size:18px;line-height:1.4;"></span>' +
            '    </div>' +
            '  </td>' +
            '</tr>';

        return $(rowHtml);
    }

    function updatePagination() {
        $('#pm-page-info').text(__('Page', 'skyhs-hosting-solution') + ' ' + currentPage + ' ' + __('of', 'skyhs-hosting-solution') + ' ' + totalPages);
        $('#pm-prev-page').prop('disabled', currentPage <= 1);
        $('#pm-next-page').prop('disabled', currentPage >= totalPages);
    }

    // Pagination events
    $('#pm-prev-page').on('click', function(e) {
        e.preventDefault();
        if (currentPage > 1) {
            fetchProducts(currentPage - 1);
        }
    });

    $('#pm-next-page').on('click', function(e) {
        e.preventDefault();
        if (currentPage < totalPages) {
            fetchProducts(currentPage + 1);
        }
    });

    // Reactive search and filters
    var searchTimeout = null;
    $('#pm-search-input').on('keyup input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            fetchProducts(1);
        }, 350);
    });

    $('#pm-type-filter, #pm-payment-filter').on('change', function() {
        fetchProducts(1);
    });

    // Initial AJAX list fetch
    fetchProducts(1);

    // -------------------------------------------------------------------------
    // Add Product button — open form panel
    // -------------------------------------------------------------------------

    $('#pm-add-hosting-btn').on('click', function() {
        resetForm();
        $wpsFormPanel.slideUp(150);
        $formPanel.slideDown(250);
        $('html, body').animate({ scrollTop: $formPanel.offset().top - 40 }, 250);
    });

    $('#pm-add-wpsite-btn').on('click', function() {
        resetWpsForm();
        $formPanel.slideUp(150);
        $wpsFormPanel.slideDown(250);
        $('html, body').animate({ scrollTop: $wpsFormPanel.offset().top - 40 }, 250);
    });

    // -------------------------------------------------------------------------
    // Cancel button — close form panel
    // -------------------------------------------------------------------------

    $cancelBtn.on('click', function() {
        resetForm();
        $formPanel.slideUp(250);
    });

    $wpsCancelBtn.on('click', function() {
        resetWpsForm();
        $wpsFormPanel.slideUp(250);
    });

    // -------------------------------------------------------------------------
    // Reset form to create mode
    // -------------------------------------------------------------------------

    function resetForm() {
        $form[0].reset();
        $('#pm_product_id').val('0');
        $('#pm-variations-tbody').empty();
        $formTitle.text('Create New Product');
        $('#pm-submit').text('Create Product');
        $form.data('edit-mode', '0');
        $notice.hide().removeClass('notice-success notice-error notice-info');
        $cancelBtn.hide();
        toggleForm();
    }

    function resetWpsForm() {
        $wpsForm[0].reset();
        $('#wps_product_id').val('0');
        $('#wps-variations-tbody').empty();
        $('#wps_wp_host_user').val('');
        $('#wps_wp_host_user_search').val('').prop('disabled', true).attr('placeholder', 'Select server first...');
        $wpsFormTitle.text('Create New WordPress Site Product');
        $('#wps-submit').text('Create Product');
        $wpsForm.data('edit-mode', '0');
        $notice.hide().removeClass('notice-success notice-error notice-info');
        $wpsCancelBtn.hide();
        toggleWpsForm();
    }

    // -------------------------------------------------------------------------
    // Structure (Simple/Variable) + Payment (One-Time/Subscription) toggles
    // -------------------------------------------------------------------------

    function toggleForm() {
        var structure = $('#pm_structure').val();
        var payment   = $('#pm_payment').val();

        if (structure === 'simple') {
            var varServer = $('#pm_variable_server_id').val();
            if (varServer) {
                $('#pm_server_id').val(varServer).trigger('change');
            }
            $('#pm-section-simple').show();
            $('#pm-section-variable').hide();

            if (payment === 'one-time') {
                $('#pm-simple-once-fields').show();
                $('#pm-simple-sub-fields').hide();
            } else {
                $('#pm-simple-once-fields').hide();
                $('#pm-simple-sub-fields').show();
            }
        } else {
            var simpleServer = $('#pm_server_id').val();
            if (simpleServer) {
                $('#pm_variable_server_id').val(simpleServer).trigger('change');
            }
            $('#pm-section-simple').hide();
            $('#pm-section-variable').show();
        }

        if (payment === 'subscription') {
            $('.vp-sub-only').show();
        } else {
            $('.vp-sub-only').hide();
        }
    }

    $('#pm_structure, #pm_payment').on('change', toggleForm);
    toggleForm();

    function toggleWpsForm() {
        var structure = $('#wps_structure').val();
        var payment   = $('#wps_payment').val();

        if (structure === 'simple') {
            var varServer = $('#wps_variable_server_id').val();
            if (varServer) {
                $('#wps_server_id').val(varServer).trigger('change');
            }
            $('#wps-section-simple').show();
            $('#wps-section-variable').hide();

            if (payment === 'one-time') {
                $('#wps-simple-once-fields').show();
                $('#wps-simple-sub-fields').hide();
            } else {
                $('#wps-simple-once-fields').hide();
                $('#wps-simple-sub-fields').show();
            }
        } else {
            var simpleServer = $('#wps_server_id').val();
            if (simpleServer) {
                $('#wps_variable_server_id').val(simpleServer).trigger('change');
            }
            $('#wps-section-simple').hide();
            $('#wps-section-variable').show();
        }

        if (payment === 'subscription') {
            $('#wps-variations-table .vp-sub-only').show();
        } else {
            $('#wps-variations-table .vp-sub-only').hide();
        }
    }

    $('#wps_structure, #wps_payment').on('change', toggleWpsForm);
    toggleWpsForm();

    // -------------------------------------------------------------------------
    // Server → Plan dropdown population (simple mode)
    // -------------------------------------------------------------------------

    $('#pm_server_id').on('change', function() {
        populatePlanDropdown($(this).val(), $('#pm_hosting_plan'));
    });

    $('#wps_server_id').on('change', function() {
        var serverId = $(this).val();
        var $search = $('#wps_wp_host_user_search');
        var $hidden = $('#wps_wp_host_user');
        var $results = $('#wps_cpanel_search_results');
        $results.hide().empty();
        if (serverId) {
            $search.prop('disabled', false).attr('placeholder', 'Type cPanel username to search...');
        } else {
            $search.prop('disabled', true).val('').attr('placeholder', 'Select server first...');
            $hidden.val('');
        }
    });

    $('#wps_variable_server_id').on('change', function() {
        updateWpsVariableHostSearchState($(this).val());
    });

    function updateWpsVariableHostSearchState(serverId) {
        $('#wps-variations-tbody tr').each(function() {
            var $search = $(this).find('.vp-wp-host-search');
            var $hidden = $(this).find('.vp-wp-host-value');
            var $results = $(this).find('.wps-cpanel-search-results');
            $results.hide().empty();
            if (serverId) {
                $search.prop('disabled', false).attr('placeholder', 'Type username...');
            } else {
                $search.prop('disabled', true).val('').attr('placeholder', 'Select server first...');
                $hidden.val('');
            }
        });
    }

    // -------------------------------------------------------------------------
    // Server → Plan dropdown population (variable mode)
    // -------------------------------------------------------------------------

    $('#pm_variable_server_id').on('change', function() {
        updateVariablePlanDropdowns($(this).val());
    });

    function populatePlanDropdown(serverId, $planSelect) {
        $planSelect.empty().prop('disabled', true);

        if (!serverId) {
            $planSelect.append('<option value="">Select server first</option>');
            return;
        }

        $planSelect.append('<option value="">Loading...</option>');

        var found = false;
        if (data.servers) {
            $.each(data.servers, function(i, server) {
                if (String(server.id) === String(serverId)) {
                    $planSelect.empty();
                    $planSelect.append('<option value="">Select Plan</option>');
                    $.each(server.plans, function(j, plan) {
                        var label = plan.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                        $planSelect.append('<option value="' + plan + '">' + label + '</option>');
                    });
                    $planSelect.prop('disabled', false);
                    found = true;
                    return false;
                }
            });
        }

        if (!found) {
            $.post(data.ajax_url, {
                action: 'skyhshoso_fetch_server_plans',
                nonce: data.nonce_plans,
                server_id: serverId
            }, function(resp) {
                $planSelect.empty();
                if (resp.success && resp.data.plans) {
                    $planSelect.append('<option value="">Select Plan</option>');
                    $.each(resp.data.plans, function(planKey, planLabel) {
                        $planSelect.append('<option value="' + planKey + '">' + planLabel + '</option>');
                    });
                    $planSelect.prop('disabled', false);
                } else {
                    $planSelect.append('<option value="">No plans found</option>');
                }
            }).fail(function() {
                $planSelect.empty().append('<option value="">Error loading plans</option>');
            });
        }
    }

    function updateVariablePlanDropdowns(serverId) {
        $('#pm-variations-tbody tr').each(function() {
            populatePlanSelectInRow($(this).find('.vp-hosting-plan'), serverId);
        });
    }

    function populatePlanSelectInRow($select, serverId) {
        $select.empty().prop('disabled', true);

        if (!serverId) {
            $select.append('<option value="">Select server first</option>');
            return;
        }

        $select.append('<option value="">Select Plan</option>');

        if (data.servers) {
            $.each(data.servers, function(i, server) {
                if (String(server.id) === String(serverId)) {
                    $.each(server.plans, function(j, plan) {
                        var label = plan.replace(/_/g, ' ').replace(/\b\w/g, function(l) { return l.toUpperCase(); });
                        $select.append('<option value="' + plan + '">' + label + '</option>');
                    });
                    $select.prop('disabled', false);
                    return false;
                }
            });
        }

        if ($select.find('option').length <= 1) {
            $select.empty().append('<option value="">Loading...</option>');
            $.post(data.ajax_url, {
                action: 'skyhshoso_fetch_server_plans',
                nonce: data.nonce_plans,
                server_id: serverId
            }, function(resp) {
                $select.empty();
                if (resp.success && resp.data.plans) {
                    $select.append('<option value="">Select Plan</option>');
                    $.each(resp.data.plans, function(planKey, planLabel) {
                        $select.append('<option value="' + planKey + '">' + planLabel + '</option>');
                    });
                    $select.prop('disabled', false);
                } else {
                    $select.append('<option value="">No plans found</option>');
                }
            });
        }
    }

    // -------------------------------------------------------------------------
    // Variable plan rows
    // -------------------------------------------------------------------------

    var planCounter = 0;

    function addPlanRow(data) {
        data = data || {};
        var id = planCounter++;
        var name        = data.name || '';
        var price       = data.price !== undefined ? data.price : '';
        var period      = data.period || 'month';
        var interval    = data.interval || 1;
        var hostingPlan = data.hosting_plan || '';
        var features    = data.features || '';
        var isSub       = $('#pm_payment').val() === 'subscription';

        var row = [
            '<tr data-id="' + id + '">',
            '<td><input type="text" name="variations[' + id + '][name]" value="' + $('<span>').text(name).html() + '" class="pm-input vp-name" placeholder="e.g. Basic" /></td>',
            '<td><input type="number" name="variations[' + id + '][price]" value="' + price + '" class="pm-input vp-price" min="0" step="0.01" placeholder="9.99" /></td>',
            '<td class="vp-sub-only"><select name="variations[' + id + '][period]" class="pm-input vp-period">',
            '    <option value="month"' + (period === 'month' ? ' selected' : '') + '>Month</option>',
            '    <option value="year"' + (period === 'year' ? ' selected' : '') + '>Year</option>',
            '    <option value="week"' + (period === 'week' ? ' selected' : '') + '>Week</option>',
            '    <option value="day"' + (period === 'day' ? ' selected' : '') + '>Day</option>',
            '</select></td>',
            '<td class="vp-sub-only"><input type="number" name="variations[' + id + '][interval]" value="' + interval + '" class="pm-input vp-interval" min="1" step="1" /></td>',
            '<td><select name="variations[' + id + '][hosting_plan]" class="pm-input vp-hosting-plan" disabled>',
            '    <option value="">Select server first</option>',
            '</select></td>',
            '<td><textarea name="variations[' + id + '][features]" class="pm-input pm-textarea vp-features" rows="2" placeholder="1 feature per line">' + $('<span>').text(features).html() + '</textarea></td>',
            '<td><button type="button" class="button button-small pm-remove-variation">&times;</button></td>',
            '</tr>'
        ].join('\n');

        $('#pm-variations-tbody').append(row);

        // Populate hosting plan if server selected
        var serverId = $('#pm_variable_server_id').val();
        if (serverId) {
            var $select = $('#pm-variations-tbody tr:last .vp-hosting-plan');
            populatePlanSelectInRow($select, serverId);
            if (hostingPlan) {
                $select.val(hostingPlan);
            }
        }

        if (!isSub) {
            $('#pm-variations-tbody tr:last .vp-sub-only').hide();
        }
    }

    $('#pm-add-variation').on('click', function() {
        addPlanRow({});
    });

    $(document).on('click', '.pm-remove-variation', function() {
        if ($('#pm-variations-tbody tr').length <= 1) return;
        $(this).closest('tr').remove();
    });

    // -------------------------------------------------------------------------
    // Edit product — populate form
    // -------------------------------------------------------------------------

    $(document).on('click', '.pm-edit-product', function() {
        var id = $(this).data('id');

        // Find product in local data
        var product = null;
        $.each(data.products, function(i, p) {
            if (String(p.id) === String(id)) { product = p; return false; }
        });

        if (!product) {
            showNotice('error', 'Product data not found. Please reload the page.');
            return;
        }

        if (product.type === 'skyhshoso_wp_site') {
            // Edit WP Site product
            resetWpsForm();
            $('#skyhshoso-pm-form-panel').slideUp(150);
            $('#wps_product_id').val(product.id);

            // Set structure & payment
            $('#wps_structure').val(product.structure || 'simple');
            $('#wps_payment').val(product.payment || 'subscription');
            toggleWpsForm();

            // Set name
            $('#wps_product_name').val(product.name);

            if (product.structure === 'simple') {
                // Populate server dropdown & wp host user
                if (product.server_id) {
                    $('#wps_server_id').val(product.server_id);
                    $('#wps_wp_host_user').val(product.wp_host_user || '');
                    $('#wps_wp_host_user_search').val(product.wp_host_user || '').prop('disabled', false);
                }

                // Storage and PHP memory limits
                $('#wps_wp_storage').val(product.wp_storage || 500);
                $('#wps_wp_memory').val(product.wp_memory || '128M');

                // Set features
                if (product.features) {
                    $('#wps_features').val(product.features);
                }

                // Set pricing
                if (product.payment === 'one-time') {
                    $('#wps_price_once').val(product.price);
                } else {
                    $('#wps_sub_price').val(product.sub_price || product.price);
                    if (product.sub_period) $('#wps_sub_period').val(product.sub_period);
                    if (product.sub_interval) $('#wps_sub_interval').val(product.sub_interval);
                }
            } else {
                // Variable — set server
                if (product.server_id) {
                    $('#wps_variable_server_id').val(product.server_id);
                }

                // Add variation rows
                if (product.variations && product.variations.length > 0) {
                    $.each(product.variations, function(i, v) {
                        addWpsPlanRow(v);
                    });
                }
            }

            // Update header & button
            $wpsFormTitle.html('Edit WordPress Site Product <small><a href="javascript:void(0)" class="wps-cancel-edit" style="font-size:13px;font-weight:400;text-decoration:none;">&larr; New Product</a></small>');
            $('#wps-submit').text('Update Product');
            $wpsForm.data('edit-mode', '1');

            $wpsCancelBtn.show();
            $wpsFormPanel.slideDown(250, function() {
                $('html, body').animate({ scrollTop: $wpsFormPanel.offset().top - 40 }, 250);
            });

            return; // stop execution here
        }

        // Reset form first
        $('#skyhshoso-pm-form')[0].reset();
        $('#pm-variations-tbody').empty();
        $('#pm_product_id').val(product.id);

        // Set structure & payment
        $('#pm_structure').val(product.structure || 'simple');
        $('#pm_payment').val(product.payment || 'subscription');
        toggleForm();

        // Set name
        $('#pm_product_name').val(product.name);

        if (product.structure === 'simple') {
            // Populate server dropdown & plan
            if (product.server_id) {
                $('#pm_server_id').val(product.server_id).trigger('change');
                // Plan populates async, set value after delay
                var checkPlan = setInterval(function() {
                    if ($('#pm_hosting_plan option').length > 1) {
                        if (product.hosting_plan) {
                            $('#pm_hosting_plan').val(product.hosting_plan);
                        }
                        clearInterval(checkPlan);
                    }
                }, 100);
                setTimeout(function() { clearInterval(checkPlan); }, 5000);
            }

            // Set features
            if (product.features) {
                $('#pm_features').val(product.features);
            }

            // Set pricing
            if (product.payment === 'one-time') {
                $('#pm_price_once').val(product.price);
            } else {
                $('#pm_sub_price').val(product.sub_price || product.price);
                if (product.sub_period) $('#pm_sub_period').val(product.sub_period);
                if (product.sub_interval) $('#pm_sub_interval').val(product.sub_interval);
            }
        } else {
            // Variable — set server
            if (product.server_id) {
                $('#pm_variable_server_id').val(product.server_id).trigger('change');
            }

            // Add variation rows
            if (product.variations && product.variations.length > 0) {
                $.each(product.variations, function(i, v) {
                    addPlanRow(v);
                });

                // Set hosting plan values after dropdowns populate
                var checkVarPlans = setInterval(function() {
                    var allSet = true;
                    $('#pm-variations-tbody tr').each(function(idx) {
                        var $sel = $(this).find('.vp-hosting-plan');
                        if ($sel.is(':disabled') && $sel.find('option').length <= 1) {
                            allSet = false;
                        }
                    });
                    if (allSet) {
                        $('#pm-variations-tbody tr').each(function(idx) {
                            var v = product.variations[idx];
                            if (v && v.hosting_plan) {
                                $(this).find('.vp-hosting-plan').val(v.hosting_plan);
                            }
                        });
                        clearInterval(checkVarPlans);
                    }
                }, 200);
                setTimeout(function() { clearInterval(checkVarPlans); }, 5000);
            }
        }

        // Update header & button
        $formTitle.html('Edit Product <small><a href="javascript:void(0)" class="pm-cancel-edit" style="font-size:13px;font-weight:400;text-decoration:none;">&larr; New Product</a></small>');
        $('#pm-submit').text('Update Product');
        $form.data('edit-mode', '1');

        $cancelBtn.show();
        $formPanel.slideDown(250, function() {
            $('html, body').animate({ scrollTop: $formPanel.offset().top - 40 }, 250);
        });
    });

    // -------------------------------------------------------------------------
    // "New Product" link in edit header
    // -------------------------------------------------------------------------

    $(document).on('click', '.pm-cancel-edit', function() {
        resetForm();
        $formPanel.slideUp(250);
    });

    // -------------------------------------------------------------------------
    // Form submission
    // -------------------------------------------------------------------------

    $('#skyhshoso-pm-form').on('submit', function(e) {
        e.preventDefault();

        var name = $('#pm_product_name').val().trim();
        if (!name) {
            showNotice('error', data.strings.fill_required);
            $('#pm_product_name').focus();
            return;
        }

        var structure = $('#pm_structure').val();
        var payment   = $('#pm_payment').val();
        var productId = $('#pm_product_id').val();

        var formData = {
            action: 'skyhshoso_create_product',
            nonce: data.nonce_create,
            product_name: name,
            structure: structure,
            payment: payment,
            product_id: productId
        };

        if (structure === 'simple') {
            if (!$('#pm_server_id').val()) {
                showNotice('error', 'Please select a server.');
                return;
            }
            if (!$('#pm_hosting_plan').val()) {
                showNotice('error', 'Please select a hosting plan.');
                return;
            }

            formData.server_id = $('#pm_server_id').val();
            formData.hosting_plan = $('#pm_hosting_plan').val();
            formData.features = $('#pm_features').val();

            if (payment === 'one-time') {
                var priceOnceVal = $('#pm_price_once').val();
                var priceOnce = parseFloat(priceOnceVal);
                if (priceOnceVal === '' || isNaN(priceOnce) || priceOnce < 0) {
                    showNotice('error', 'Please enter a valid price.');
                    $('#pm_price_once').focus();
                    return;
                }
                formData.price = priceOnce;
            } else {
                var subPriceVal = $('#pm_sub_price').val();
                var subPrice = parseFloat(subPriceVal);
                if (subPriceVal === '' || isNaN(subPrice) || subPrice < 0) {
                    showNotice('error', 'Please enter a valid subscription amount.');
                    $('#pm_sub_price').focus();
                    return;
                }
                formData.sub_price = subPrice;
                formData.sub_period = $('#pm_sub_period').val();
                formData.sub_interval = $('#pm_sub_interval').val();
            }
        } else {
            if (!$('#pm_variable_server_id').val()) {
                showNotice('error', 'Please select a server.');
                return;
            }

            formData.server_id = $('#pm_variable_server_id').val();

            var variations = [];
            var hasValid = false;
            $('#pm-variations-tbody tr').each(function() {
                var $row = $(this);
                var planName = $row.find('.vp-name').val().trim();
                var price = $row.find('.vp-price').val();
                var period = $row.find('.vp-period').val() || 'month';
                var interval = $row.find('.vp-interval').val() || 1;
                var hostingPlan = $row.find('.vp-hosting-plan').val() || '';
                var features = $row.find('.vp-features').val() || '';

                if (!planName) return;
                hasValid = true;

                variations.push({
                    name: planName,
                    price: price,
                    period: period,
                    interval: interval,
                    hosting_plan: hostingPlan,
                    features: features
                });
            });

            if (!hasValid) {
                showNotice('error', 'Please add at least one plan with a name.');
                return;
            }

            var hasInvalidPrice = false;
            $.each(variations, function(i, v) {
                var p = parseFloat(v.price);
                if (v.price === '' || isNaN(p) || p < 0) {
                    hasInvalidPrice = true;
                }
            });
            if (hasInvalidPrice) {
                showNotice('error', 'Please set a valid price for all plans.');
                return;
            }

            formData.variations = variations;
        }

        // Determine button text
        var isEdit = parseInt(productId) > 0;
        var submitLabel = isEdit ? 'Updating product...' : data.strings.creating;

        var $btn = $('#pm-submit');
        var $loader = $('#pm-loader');
        $btn.prop('disabled', true);
        $loader.addClass('is-active');
        showNotice('info', submitLabel);

        $.post(data.ajax_url, formData, function(resp) {
            if (resp.success) {
                showNotice('success', resp.data.message);
                resetForm();
                $formPanel.slideUp(250);
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                showNotice('error', resp.data.message || data.strings.error);
            }
        }).fail(function() {
            showNotice('error', data.strings.error);
        }).always(function() {
            $btn.prop('disabled', false);
            $loader.removeClass('is-active');
        });
    });

    // -------------------------------------------------------------------------
    // Delete product
    // -------------------------------------------------------------------------

    $(document).on('click', '.pm-delete-product', function(e) {
        e.preventDefault();
        var id = $(this).data('id');

        if (!confirm(data.strings.confirm_delete)) {
            return;
        }

        $.post(data.ajax_url, {
            action: 'skyhshoso_delete_product',
            nonce: data.nonce_delete,
            product_id: id
        }, function(resp) {
            if (resp.success) {
                showNotice('success', resp.data.message);
                setTimeout(function() { window.location.reload(); }, 1000);
            } else {
                alert(resp.data.message || data.strings.error);
            }
        }).fail(function() {
            alert(data.strings.error);
        });
    });

    // -------------------------------------------------------------------------
    // Copy shortcode to clipboard
    // -------------------------------------------------------------------------

    $(document).on('click', '.pm-copy-shortcode', function(e) {
        e.preventDefault();
        var shortcode = $(this).data('shortcode');

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(shortcode).then(function() {
                showNotice('success', data.strings.copied);
            });
        } else {
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(shortcode).select();
            document.execCommand('copy');
            $temp.remove();
            showNotice('success', data.strings.copied);
        }
    });

    // -------------------------------------------------------------------------
    // WP Site Variation Rows & Forms
    // -------------------------------------------------------------------------

    var wpsPlanCounter = 0;

    function addWpsPlanRow(row_data) {
        row_data = row_data || {};
        var id = wpsPlanCounter++;
        var name        = row_data.name || '';
        var price       = row_data.price !== undefined ? row_data.price : '';
        var period      = row_data.period || 'month';
        var interval    = row_data.interval || 1;
        var wpHostUser  = row_data.wp_host_user || '';
        var wpStorage   = row_data.wp_storage || 500;
        var wpMemory    = row_data.wp_memory || '128M';
        var features    = row_data.features || '';
        var isSub       = $('#wps_payment').val() === 'subscription';

        var serverId = $('#wps_variable_server_id').val();

        var row = [
            '<tr data-id="' + id + '">',
            '<td><input type="text" name="variations[' + id + '][name]" value="' + $('<span>').text(name).html() + '" class="pm-input vp-name" placeholder="e.g. Starter" /></td>',
            '<td><input type="number" name="variations[' + id + '][price]" value="' + price + '" class="pm-input vp-price" min="0" step="0.01" placeholder="9.99" /></td>',
            '<td class="vp-sub-only"><select name="variations[' + id + '][period]" class="pm-input vp-period">',
            '    <option value="month"' + (period === 'month' ? ' selected' : '') + '>Month</option>',
            '    <option value="year"' + (period === 'year' ? ' selected' : '') + '>Year</option>',
            '    <option value="week"' + (period === 'week' ? ' selected' : '') + '>Week</option>',
            '    <option value="day"' + (period === 'day' ? ' selected' : '') + '>Day</option>',
            '</select></td>',
            '<td class="vp-sub-only"><input type="number" name="variations[' + id + '][interval]" value="' + interval + '" class="pm-input vp-interval" min="1" step="1" /></td>',
            '<td>',
            '  <div class="wps-cpanel-search-wrapper" style="position:relative;">',
            '      <input type="text" class="pm-input vp-wp-host-search wps-wp-host-search" placeholder="' + (serverId ? 'Type username...' : 'Select server first...') + '" value="' + wpHostUser + '" autocomplete="off"' + (serverId ? '' : ' disabled') + ' />',
            '      <input type="hidden" name="variations[' + id + '][wp_host_user]" class="vp-wp-host-value" value="' + wpHostUser + '" />',
            '      <div class="wps-cpanel-search-results hm-autocomplete-results" style="display:none;position:absolute;z-index:999;width:100%;background:#fff;border:1px solid #ddd;max-height:150px;overflow-y:auto;box-shadow:0 4px 6px rgba(0,0,0,0.1);"></div>',
            '  </div>',
            '</td>',
            '<td><input type="number" name="variations[' + id + '][wp_storage]" value="' + wpStorage + '" class="pm-input vp-storage-input" min="1" /></td>',
            '<td><select name="variations[' + id + '][wp_memory]" class="pm-input vp-memory-select">',
            '    <option value="64M"' + (wpMemory === '64M' ? ' selected' : '') + '>64M</option>',
            '    <option value="128M"' + (wpMemory === '128M' ? ' selected' : '') + '>128M</option>',
            '    <option value="256M"' + (wpMemory === '256M' ? ' selected' : '') + '>256M</option>',
            '    <option value="512M"' + (wpMemory === '512M' ? ' selected' : '') + '>512M</option>',
            '</select></td>',
            '<td><textarea name="variations[' + id + '][features]" class="pm-input pm-textarea vp-features" rows="2" placeholder="1 feature per line">' + $('<span>').text(features).html() + '</textarea></td>',
            '<td><button type="button" class="button button-small wps-remove-variation">&times;</button></td>',
            '</tr>'
        ].join('\n');

        $('#wps-variations-tbody').append(row);

        if (!isSub) {
            $('#wps-variations-tbody tr:last .vp-sub-only').hide();
        }
    }

    $('#wps-add-variation').on('click', function() {
        addWpsPlanRow({});
    });

    $(document).on('click', '.wps-remove-variation', function() {
        if ($('#wps-variations-tbody tr').length <= 1) return;
        $(this).closest('tr').remove();
    });

    $(document).on('click', '.wps-cancel-edit', function() {
        resetWpsForm();
        $wpsFormPanel.slideUp(250);
    });

    // WP Site Submit
    $('#skyhshoso-wps-form').on('submit', function(e) {
        e.preventDefault();

        var name = $('#wps_product_name').val().trim();
        if (!name) {
            showNotice('error', data.strings.fill_required);
            $('#wps_product_name').focus();
            return;
        }

        var structure = $('#wps_structure').val();
        var payment   = $('#wps_payment').val();
        var productId = $('#wps_product_id').val();

        var formData = {
            action: 'skyhshoso_create_product',
            nonce: data.nonce_create,
            product_type: 'skyhshoso_wp_site',
            product_name: name,
            structure: structure,
            payment: payment,
            product_id: productId
        };

        if (structure === 'simple') {
            if (!$('#wps_server_id').val()) {
                showNotice('error', 'Please select a server.');
                return;
            }
            if (!$('#wps_wp_host_user').val()) {
                showNotice('error', 'Please select a cPanel host user.');
                return;
            }

            formData.server_id = $('#wps_server_id').val();
            formData.wp_host_user = $('#wps_wp_host_user').val();
            formData.wp_storage = $('#wps_wp_storage').val();
            formData.wp_memory = $('#wps_wp_memory').val();
            formData.features = $('#wps_features').val();

            if (payment === 'one-time') {
                var priceOnceVal = $('#wps_price_once').val();
                var priceOnce = parseFloat(priceOnceVal);
                if (priceOnceVal === '' || isNaN(priceOnce) || priceOnce < 0) {
                    showNotice('error', 'Please enter a valid price.');
                    $('#wps_price_once').focus();
                    return;
                }
                formData.price = priceOnce;
            } else {
                var subPriceVal = $('#wps_sub_price').val();
                var subPrice = parseFloat(subPriceVal);
                if (subPriceVal === '' || isNaN(subPrice) || subPrice < 0) {
                    showNotice('error', 'Please enter a valid subscription amount.');
                    $('#wps_sub_price').focus();
                    return;
                }
                formData.sub_price = subPrice;
                formData.sub_period = $('#wps_sub_period').val();
                formData.sub_interval = $('#wps_sub_interval').val();
            }
        } else {
            if (!$('#wps_variable_server_id').val()) {
                showNotice('error', 'Please select a server.');
                return;
            }

            formData.server_id = $('#wps_variable_server_id').val();

            var variations = [];
            var hasValid = false;
            $('#wps-variations-tbody tr').each(function() {
                var $row = $(this);
                var planName = $row.find('.vp-name').val().trim();
                var price = $row.find('.vp-price').val();
                var period = $row.find('.vp-period').val() || 'month';
                var interval = $row.find('.vp-interval').val() || 1;
                var wpHostUser = $row.find('.vp-wp-host-value').val() || '';
                var wpStorage = $row.find('.vp-storage-input').val() || 500;
                var wpMemory = $row.find('.vp-memory-select').val() || '128M';
                var features = $row.find('.vp-features').val() || '';

                if (!planName) return;
                hasValid = true;

                variations.push({
                    name: planName,
                    price: price,
                    period: period,
                    interval: interval,
                    wp_host_user: wpHostUser,
                    wp_storage: wpStorage,
                    wp_memory: wpMemory,
                    features: features
                });
            });

            if (!hasValid) {
                showNotice('error', 'Please add at least one plan with a name.');
                return;
            }

            var hasInvalidPrice = false;
            var hasMissingHost = false;
            $.each(variations, function(i, v) {
                var p = parseFloat(v.price);
                if (v.price === '' || isNaN(p) || p < 0) {
                    hasInvalidPrice = true;
                }
                if (!v.wp_host_user) {
                    hasMissingHost = true;
                }
            });
            if (hasInvalidPrice) {
                showNotice('error', 'Please set a valid price for all plans.');
                return;
            }
            if (hasMissingHost) {
                showNotice('error', 'Please select a WordPress Host cPanel for all plans.');
                return;
            }

            formData.variations = variations;
        }

        // Determine button text
        var isEdit = parseInt(productId) > 0;
        var submitLabel = isEdit ? 'Updating product...' : data.strings.creating;

        var $btn = $('#wps-submit');
        var $btnLoader = $('#wps-loader');
        $btn.prop('disabled', true);
        $btnLoader.addClass('is-active');
        showNotice('info', submitLabel);

        $.post(data.ajax_url, formData, function(resp) {
            if (resp.success) {
                showNotice('success', resp.data.message);
                resetWpsForm();
                $wpsFormPanel.slideUp(250);
                setTimeout(function() { window.location.reload(); }, 1500);
            } else {
                showNotice('error', resp.data.message || data.strings.error);
            }
        }).fail(function() {
            showNotice('error', data.strings.error);
        }).always(function() {
            $btn.prop('disabled', false);
            $btnLoader.removeClass('is-active');
        });
    });

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    function showNotice(type, message) {
        $notice.removeClass('notice-success notice-error notice-info').addClass('notice-' + type).html('<p>' + message + '</p>').show();
        if (type === 'success') {
            setTimeout(function() { $notice.fadeOut(); }, 8000);
        }
    }

    // Autocomplete for Simple & Variable WordPress Host cPanel search
    $(document).on('keyup input focus', '.wps-wp-host-search, .vp-wp-host-search', function(e) {
        var $input = $(this);
        var isVariable = $input.hasClass('vp-wp-host-search');
        var serverId = isVariable ? $('#wps_variable_server_id').val() : $('#wps_server_id').val();
        var $wrapper = $input.closest('.wps-cpanel-search-wrapper');
        var $results = $wrapper.find('.wps-cpanel-search-results');
        var term = $input.val().trim();

        if (!serverId) {
            $results.hide().empty();
            return;
        }

        clearTimeout($input.data('search-timeout'));
        
        var timeout = setTimeout(function() {
            $.post(data.ajax_url, {
                action: 'skyhshoso_get_cpanel_accounts',
                nonce: data.nonce_cpanel_accounts,
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
                            if (isVariable) {
                                $wrapper.find('.vp-wp-host-value').val(account.username);
                            } else {
                                $('#wps_wp_host_user').val(account.username);
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
        if (!$(e.target).closest('.wps-cpanel-search-wrapper').length) {
            $('.wps-cpanel-search-results').hide();
        }
    });
});
