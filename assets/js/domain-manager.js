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

    if (typeof skyhshoso_dm === 'undefined') {
        return;
    }

    var currentPage = 1;
    var totalPages = 1;
    var domains = [];

    var strings = skyhshoso_dm.strings;

    var $container = $('#skyhshoso-hm-container');
    var $form = $('#skyhshoso-hm-form');
    var $formPanel = $('#skyhshoso-hm-form-panel');
    var $formTitle = $('#skyhshoso-hm-form-title');
    var $domainId = $('#dm_domain_id');
    var $titleInput = $('#dm_title');
    var $ownerSelect = $('#hm_owner_id');
    var $ownerSearch = $('#dm_owner_search');
    var $domainSearch = $('#dm_domain_search');
    var $searchBtn = $('#dm-search-btn');
    var $searchResults = $('#dm-search-results');
    var $searchLoader = $('#dm-search-loader');
    var $regSection = $('#dm-registration-section');
    var $registerBtn = $('#dm-register-btn');
    var $cancelBtn = $('#dm-cancel-btn');
    var $loader = $('#hm-loader');
    var $notice = $('#skyhshoso-hm-notice');
    var $selectedDomain = $('#dm_selected_domain');
    var $domainPrice = $('#dm_domain_price');
    var $domainTld = $('#dm_domain_tld');

    var selectedDomainData = null;

    fetchDomains(1);

    $('#hm-add-hosting-btn').on('click', function() {
        openCreatePanel();
    });

    function openCreatePanel() {
        resetForm();
        $formPanel.slideDown(250);
        $('html, body').animate({ scrollTop: $formPanel.offset().top - 40 }, 250);
        setTimeout(function() {
            $domainSearch.focus();
        }, 300);
    }

    $searchBtn.on('click', function() {
        doDomainSearch();
    });

    $domainSearch.on('keydown', function(e) {
        if (e.keyCode === 13) {
            e.preventDefault();
            doDomainSearch();
        }
    });

    function doDomainSearch() {
        var term = $domainSearch.val().trim();
        if (!term) {
            showNotice('error', __('Please enter a domain name.', 'skyhs-hosting-solution'));
            return;
        }

        $searchBtn.prop('disabled', true);
        $searchResults.hide().empty();
        $searchLoader.show();
        $regSection.hide();
        selectedDomainData = null;
        $selectedDomain.val('');

        var data = {
            action: 'skyhshoso_dm_domain_search',
            nonce: skyhshoso_dm.nonce_search,
            domain: term
        };

        $.post(skyhshoso_dm.ajax_url, data, function(res) {
            $searchLoader.hide();
            $searchBtn.prop('disabled', false);

            if (res.success) {
                if (res.data.api_error) {
                    renderApiError(res.data);
                } else {
                    renderSearchResults(res.data);
                }
            } else {
                $searchResults.html(
                    '<div style="padding:16px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:13px;font-weight:600;">' +
                    escapeHtml(res.data.message || strings.error) +
                    '</div>'
                ).show();
            }
        }).fail(function() {
            $searchLoader.hide();
            $searchBtn.prop('disabled', false);
            showNotice('error', strings.error);
        });
    }

    var syncedPage = 1;
    var syncedHasMore = false;

    $('#dm-show-synced-btn').on('click', function() {
        var $list = $('#dm-synced-list');
        if ($list.is(':visible')) {
            $list.slideUp(150);
            return;
        }
        syncedPage = 1;
        $list.html('<div style="text-align:center;padding:20px;color:#6b7280;font-size:13px;"><span class="spinner" style="float:none;visibility:visible;margin:0 6px 0 0;"></span> Loading...</div>').show();
        loadSyncedPage(1, true, $list);
    });

    function loadSyncedPage(page, replace, $list) {
        if (!$list) $list = $('#dm-synced-list');
        $.post(skyhshoso_dm.ajax_url, {
            action: 'skyhshoso_dm_get_synced_domains',
            nonce: skyhshoso_dm.nonce_synced,
            page: page
        }, function(res) {
            if (res.success) {
                if (res.data.domains.length > 0 || page === 1) {
                    syncedPage = res.data.page;
                    syncedHasMore = res.data.has_more;

                    if (replace) {
                        var html = '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px;">';
                        html += '<div style="font-size:12px;font-weight:700;color:#4b5563;margin-bottom:8px;text-transform:uppercase;letter-spacing:0.05em;">' + __('Synced from Enom', 'skyhs-hosting-solution') + ' (' + res.data.total + ')</div>';
                        html += '<div id="dm-synced-rows">';
                        $.each(res.data.domains, function(i, d) {
                            html += renderSyncedRow(d);
                        });
                        html += '</div>';
                        if (syncedHasMore) {
                            html += '<div style="text-align:center;margin-top:8px;"><button type="button" id="dm-load-more-synced" class="button button-small" style="font-weight:600;">' + __('Load More', 'skyhs-hosting-solution') + '</button></div>';
                        }
                        html += '</div>';
                        $list.html(html);
                    } else {
                        $.each(res.data.domains, function(i, d) {
                            $('#dm-synced-rows').append(renderSyncedRow(d));
                        });
                        if (syncedHasMore) {
                            if (!$('#dm-load-more-synced').length) {
                                $('#dm-synced-rows').after('<div style="text-align:center;margin-top:8px;"><button type="button" id="dm-load-more-synced" class="button button-small" style="font-weight:600;">' + __('Load More', 'skyhs-hosting-solution') + '</button></div>');
                            }
                        } else {
                            $('#dm-load-more-synced').remove();
                        }
                    }
                } else {
                    $list.html('<div style="padding:16px;text-align:center;color:#9ca3af;font-size:13px;background:#f9fafb;border:1px dashed #d1d5db;border-radius:8px;">' + __('No synced domains available. Sync domains from Enom Manager first.', 'skyhs-hosting-solution') + '</div>');
                }
            } else {
                $list.html('<div style="padding:16px;text-align:center;color:#dc2626;font-size:13px;">' + __('Failed to load synced domains.', 'skyhs-hosting-solution') + '</div>');
            }
        }).fail(function() {
            $list.html('<div style="padding:16px;text-align:center;color:#dc2626;font-size:13px;">' + __('Failed to load synced domains.', 'skyhs-hosting-solution') + '</div>');
        });
    }

    function renderSyncedRow(d) {
        var lockLabel = d.reg_lock === '1' ? 'Locked' : (d.reg_lock === '0' ? 'Unlocked' : '?');
        return '<div class="dm-synced-row" style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:6px;cursor:pointer;transition:all 0.15s;" data-domain="' + escapeHtml(d.domain) + '">' +
            '  <div>' +
            '    <strong style="color:#1f2937;font-size:13px;">' + escapeHtml(d.domain) + '</strong>' +
            '    <div style="font-size:11px;color:#6b7280;margin-top:2px;">Exp: ' + escapeHtml(d.expiration_date || '--') + ' &middot; Lock: ' + lockLabel + '</div>' +
            '  </div>' +
            '  <button type="button" class="button button-small dm-select-synced" data-domain="' + escapeHtml(d.domain) + '" style="font-weight:600;">' + __('Select', 'skyhs-hosting-solution') + '</button>' +
            '</div>';
    }

    $('body').on('click', '#dm-load-more-synced', function() {
        var $list = $('#dm-synced-list');
        $(this).prop('disabled', true).text(__('Loading...', 'skyhs-hosting-solution'));
        loadSyncedPage(syncedPage + 1, false, $list);
    });

    $('body').on('click', '.dm-synced-row, .dm-select-synced', function(e) {
        var $row = $(this).closest('.dm-synced-row');
        if ($(this).is('.dm-select-synced, .dm-synced-row')) {
            var domain = $row.data('domain');
            selectedDomainData = { domain: domain, price: 0, tld: '' };
            $selectedDomain.val(domain);
            $domainPrice.val(0);
            $domainTld.val('');
            $domainSearch.val(domain);
            $titleInput.val(domain);
            $searchResults.hide().empty();
            $('#dm-synced-list').slideUp(150);
            $regSection.show();
            $('html, body').animate({ scrollTop: $regSection.offset().top - 40 }, 250);
            lookupSyncedDomainOwner(domain);
        }
    });

    function lookupSyncedDomainOwner(domain) {
        $ownerSelect.val('');
        $('#dm-owner-status').remove();
        hideOwnerNotFoundInline();

        $.post(skyhshoso_dm.ajax_url, {
            action: 'skyhshoso_dm_lookup_owner',
            nonce: skyhshoso_dm.nonce_lookup_owner,
            domain: domain
        }, function(res) {
            if (res.success) {
                if (res.data.user) {
                    $ownerSearch.val(res.data.user.name);
                    $ownerSelect.val(res.data.user.id);
                    $regSection.find('.skyhshoso-hm-section').first().append(
                        '<div id="dm-owner-status" style="margin-top:8px;padding:6px 10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:6px;font-size:12px;color:#065f46;">' +
                        '<span class="dashicons dashicons-yes-alt" style="font-size:14px;width:14px;height:14px;margin-right:4px;"></span> ' +
                        escapeHtml(strings.owner_found) + ' <strong>' + escapeHtml(res.data.user.name) + '</strong>' +
                        '</div>'
                    );
                } else if (res.data.email) {
                    showOwnerNotFoundInline(domain, res.data.email);
                }
            }
        });
    }

    function showOwnerNotFoundInline(domain, email) {
        var $inline = $('#dm-create-user-inline');
        $('#dm-inline-owner-msg').html(sprintf(strings.owner_not_found, escapeHtml(email)) + ' ' + __('Create a new user for this email, or assign a different owner.', 'skyhs-hosting-solution'));
        $('#dm-inline-email').val(email);
        $('#dm-inline-first-name').val('');
        $('#dm-inline-last-name').val('');
        $('#dm-inline-create-msg').hide().empty();
        $inline.show();
    }

    function hideOwnerNotFoundInline() {
        $('#dm-create-user-inline').hide();
        $('#dm-inline-create-msg').hide().empty();
    }

    $('#dm-inline-create-btn').on('click', function() {
        var $btn = $(this).prop('disabled', true).text(strings.creating_user);
        var $msg = $('#dm-inline-create-msg').hide().removeClass('notice-error notice-success');
        var fname = $('#dm-inline-first-name').val().trim();
        var lname = $('#dm-inline-last-name').val().trim();
        var femail = $('#dm-inline-email').val().trim();

        $.post(skyhshoso_dm.ajax_url, {
            action: 'skyhshoso_dm_create_user',
            nonce: skyhshoso_dm.nonce_create_user,
            email: femail,
            first_name: fname,
            last_name: lname
        }, function(res) {
            if (res.success) {
                $msg.show().removeClass('notice-error').addClass('notice-success')
                    .html('<span style="color:#059669;">' + escapeHtml(strings.user_created) + ' ' + escapeHtml(__('User:', 'skyhs-hosting-solution')) + ' ' + escapeHtml(res.data.name) + ' (' + escapeHtml(res.data.email) + ')</span>');

                var userName = res.data.name + ' (' + res.data.email + ')';
                $ownerSearch.val(userName);
                $ownerSelect.val(res.data.user_id);

                setTimeout(function() {
                    hideOwnerNotFoundInline();
                }, 1500);
                $btn.prop('disabled', false).text(strings.create_user);
            } else {
                $msg.show().removeClass('notice-success').addClass('notice-error')
                    .html('<span style="color:#dc2626;">' + escapeHtml(res.data.message || strings.error) + '</span>');
                $btn.prop('disabled', false).text(strings.create_user);
            }
        }).fail(function() {
            $msg.show().removeClass('notice-success').addClass('notice-error')
                .html('<span style="color:#dc2626;">' + escapeHtml(strings.error) + '</span>');
            $btn.prop('disabled', false).text(strings.create_user);
        });
    });

    $('#dm-inline-assign-btn').on('click', function() {
        hideOwnerNotFoundInline();
    });

    function sprintf(str) {
        var args = arguments;
        return str.replace(/%s/g, function() {
            args.index = (args.index || 1) + 1;
            return args[args.index - 1] !== undefined ? args[args.index - 1] : '%s';
        });
    }

    function renderSearchResults(data) {
        var html = '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:16px;">';

        // Primary result
        if (data.available) {
            var price = data.registration_price ? '$' + parseFloat(data.registration_price).toFixed(2) : '—';
            html += '<div class="dm-primary-result" style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;margin-bottom:12px;">' +
                '  <div>' +
                '    <strong style="font-size:16px;color:#065f46;">' + escapeHtml(data.name) + '</strong>' +
                '    <span style="display:inline-block;margin-left:10px;padding:2px 10px;background:#059669;color:#fff;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase;">' + __('Available', 'skyhs-hosting-solution') + '</span>' +
                '    <div style="margin-top:4px;font-size:13px;color:#047857;"><strong>' + __('Registration:', 'skyhs-hosting-solution') + '</strong> ' + price + '</div>' +
                '  </div>' +
                '  <button type="button" class="button button-primary dm-select-domain" data-domain="' + escapeHtml(data.name) + '" data-price="' + (data.registration_price || 0) + '" data-tld="' + escapeHtml(data.tld || '') + '" style="font-weight:600;">' +
                '    ' + __('Select', 'skyhs-hosting-solution') +
                '  </button>' +
                '</div>';
        } else {
            html += '<div class="dm-primary-result" style="display:flex;align-items:center;padding:14px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;margin-bottom:12px;">' +
                '  <div>' +
                '    <strong style="font-size:16px;color:#991b1b;">' + escapeHtml(data.name) + '</strong>' +
                '    <span style="display:inline-block;margin-left:10px;padding:2px 10px;background:#dc2626;color:#fff;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase;">' + __('Unavailable', 'skyhs-hosting-solution') + '</span>' +
                '    <div style="margin-top:4px;font-size:13px;color:#991b1b;">' + escapeHtml(data.status || '') + '</div>' +
                '  </div>' +
                '</div>';
        }

        // Suggestions
        if (data.suggestions && data.suggestions.length > 0) {
            html += '<h4 style="margin:16px 0 10px 0;font-size:13px;font-weight:700;color:#4b5563;text-transform:uppercase;letter-spacing:0.05em;">' + __('Suggestions', 'skyhs-hosting-solution') + '</h4>';
            html += '<div style="display:flex;flex-direction:column;gap:6px;">';
            $.each(data.suggestions, function(i, sug) {
                var sugPrice = sug.registration_price ? '$' + parseFloat(sug.registration_price).toFixed(2) : '—';
                html += '<div class="dm-suggestion" style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:#ffffff;border:1px solid #e5e7eb;border-radius:6px;">' +
                    '  <div>' +
                    '    <span style="font-weight:600;color:#1f2937;">' + escapeHtml(sug.name) + '</span>' +
                    '    <span style="margin-left:8px;color:#059669;font-size:12px;font-weight:600;">' + sugPrice + '</span>' +
                    '  </div>' +
                    '  <button type="button" class="button button-secondary dm-select-domain" data-domain="' + escapeHtml(sug.name) + '" data-price="' + (sug.registration_price || 0) + '" data-tld="' + escapeHtml(sug.tld || '') + '" style="font-weight:600;">' +
                    '    ' + __('Select', 'skyhs-hosting-solution') +
                    '  </button>' +
                    '</div>';
            });
            html += '</div>';
        }

        html += '</div>';
        $searchResults.html(html).show();
    }

    function renderApiError(data) {
        var domain = data.domain || '';
        var errMsg = data.error || __('Could not check domain availability.', 'skyhs-hosting-solution');
        var manualLabel = __('Register Manually', 'skyhs-hosting-solution');
        var html = '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:16px;">' +
            '  <div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:12px;">' +
            '    <span class="dashicons dashicons-warning" style="color:#d97706;font-size:20px;width:20px;height:20px;"></span>' +
            '    <div>' +
            '      <strong style="color:#92400e;font-size:13px;">' + __('Domain registrar API unreachable', 'skyhs-hosting-solution') + '</strong>' +
            '      <p style="margin:4px 0 0 0;font-size:12px;color:#b45309;">' + escapeHtml(errMsg) + '</p>' +
            '    </div>' +
            '  </div>' +
            '  <p style="font-size:12px;color:#92400e;margin:0 0 12px 0;">' + __('You can still register the domain manually. The subscription will be created without an availability check.', 'skyhs-hosting-solution') + '</p>' +
            '  <button type="button" class="button button-primary dm-select-domain" data-domain="' + escapeHtml(domain) + '" data-price="0" data-tld="" style="font-weight:600;">' +
            '    <span class="dashicons dashicons-yes-alt" style="vertical-align:middle;margin-right:4px;font-size:16px;"></span> ' +
            escapeHtml(manualLabel) +
            '  </button>' +
            '</div>';
        $searchResults.html(html).show();
    }

    $searchResults.on('click', '.dm-select-domain', function() {
        var $btn = $(this);
        var domain = $btn.data('domain');
        var price = $btn.data('price');
        var tld = $btn.data('tld');

        selectedDomainData = {
            domain: domain,
            price: price,
            tld: tld
        };

        $selectedDomain.val(domain);
        $domainPrice.val(price);
        $domainTld.val(tld);

        $searchResults.find('.dm-primary-result, .dm-suggestion').css('border-color', '#e5e7eb').css('background', '');
        $btn.closest('.dm-primary-result, .dm-suggestion').css('border-color', '#3b82f6').css('background', '#eff6ff');

        $domainSearch.val(domain);
        $titleInput.val(domain);

        if (price && parseFloat(price) > 0) {
            $regSection.show();
            $('html, body').animate({ scrollTop: $regSection.offset().top - 40 }, 250);
        } else {
            $regSection.show();
            $('html, body').animate({ scrollTop: $regSection.offset().top - 40 }, 250);
        }
    });

    $('#dm_sub_action').on('change', function() {
        var val = $(this).val();
        if (val === 'link') {
            $('#dm_existing_sub_container').fadeIn(150);
            $('#dm_existing_sub_id').val('');
            $('#dm_existing_sub_search').val('');
        } else {
            $('#dm_existing_sub_container').fadeOut(150);
            if (val === 'create') {
                $('#dm_existing_sub_id').val('');
                $('#dm_existing_sub_search').val('');
            }
        }
    });

    var subSearchTimeout = null;
    $('#dm_existing_sub_search').on('keyup input', function() {
        var $input = $(this);
        var term = $input.val().trim();
        var $results = $('#dm-sub-search-results');

        clearTimeout(subSearchTimeout);
        if (term.length < 1) {
            $results.hide().empty();
            return;
        }

        subSearchTimeout = setTimeout(function() {
            var data = {
                action: 'skyhshoso_search_subscriptions',
                nonce: skyhshoso_dm.nonce_search_subs,
                term: term
            };

            $.post(skyhshoso_dm.ajax_url, data, function(res) {
                if (res.success && res.data.length > 0) {
                    $results.empty();
                    $.each(res.data, function(i, item) {
                        var $row = $('<div class="hm-autocomplete-row" data-id="' + item.id + '">' + escapeHtml(item.label) + '</div>');
                        $row.hover(function() { $(this).css('background', '#f5f5f5'); }, function() { $(this).css('background', '#ffffff'); });
                        $row.on('click', function() {
                            $('#dm_existing_sub_id').val(item.id);
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

    $(document).on('click', function(e) {
        if (!$(e.target).closest('.hm-search-sub-wrapper').length) {
            $('#dm-sub-search-results').hide();
        }
        if (!$(e.target).closest('.hm-search-owner-wrapper').length) {
            $('#dm_owner_search_results').hide();
        }
    });

    // Autocomplete for owner search
    $(document).on('keyup input focus', '#dm_owner_search', function(e) {
        var $input = $(this);
        var term = $input.val().trim();
        var $results = $('#dm_owner_search_results');

        if (term.length < 1) {
            $results.hide().empty();
            return;
        }

        clearTimeout($input.data('owner-timeout'));

        var timeout = setTimeout(function() {
            $.post(skyhshoso_dm.ajax_url, {
                action: 'skyhshoso_search_users',
                nonce: skyhshoso_dm.nonce_search_users,
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

    $form.on('submit', function(e) {
        e.preventDefault();

        var domain = $selectedDomain.val();
        var oid = $ownerSelect.val();

        if (!domain || !oid) {
            showNotice('error', strings.fill_fields);
            return;
        }

        $loader.show();
        $notice.hide();
        $registerBtn.prop('disabled', true);

        var data = {
            action: 'skyhshoso_register_domain',
            nonce: skyhshoso_dm.nonce_register,
            domain: domain,
            owner_id: oid,
            title: $titleInput.val(),
            domain_id: $domainId.val(),
            sub_action: $('#dm_sub_action').val(),
            existing_sub_id: $('#dm_existing_sub_id').val()
        };

        $.post(skyhshoso_dm.ajax_url, data, function(res) {
            $loader.hide();
            $registerBtn.prop('disabled', false);
            if (res.success) {
                showNotice('success', res.data.message);
                resetForm();
                $formPanel.slideUp(250);
                fetchDomains(currentPage);
            } else {
                showNotice('error', res.data.message || strings.error);
            }
        }).fail(function() {
            $loader.hide();
            $registerBtn.prop('disabled', false);
            showNotice('error', strings.error);
        });
    });

    $cancelBtn.on('click', function(e) {
        e.preventDefault();
        resetForm();
        $formPanel.slideUp(250);
    });

    function resetForm() {
        $domainId.val('0');
        $titleInput.val('');
        $ownerSearch.val('');
        $ownerSelect.val('');
        $('#dm_owner_search_results').hide().empty();
        $domainSearch.val('');
        $selectedDomain.val('');
        $domainPrice.val('');
        $domainTld.val('');
        $searchResults.hide().empty();
        $regSection.hide();
        $('#dm_sub_action').val('create');
        $('#dm_existing_sub_id').val('');
        $('#dm_existing_sub_search').val('');
        $('#dm_existing_sub_container').hide();
        $('#dm-current-sub-info').hide().empty();
        $('#dm-owner-status').remove();
        hideOwnerNotFoundInline();
        $formTitle.text(__('Register a New Domain', 'skyhs-hosting-solution'));
        $cancelBtn.hide();
        selectedDomainData = null;
    }

    function fetchDomains(page) {
        if (!page) page = 1;
        currentPage = page;
        $loader.show();

        var data = {
            action: 'skyhshoso_get_domains',
            nonce: skyhshoso_dm.nonce_get,
            paged: currentPage,
            search: $('#hm-search-input').val(),
            status: $('#hm-status-filter').val(),
            limit: 10
        };

        $.post(skyhshoso_dm.ajax_url, data, function(res) {
            $loader.hide();
            if (res.success) {
                domains = res.data.domains;
                totalPages = res.data.total_pages;
                currentPage = res.data.current_page;
                renderDomains();
                updatePagination();
            } else {
                showNotice('error', res.data.message || strings.error);
            }
        }).fail(function() {
            $loader.hide();
            showNotice('error', strings.error);
        });
    }

    function renderDomains() {
        $container.empty();

        if (domains.length === 0) {
            $container.append('<div class="skyhshoso-hm-empty"><p>' + escapeHtml(strings.no_results) + '</p></div>');
            return;
        }

        var tableHtml = '<table class="skyhshoso-hm-table">' +
            '  <thead>' +
            '    <tr>' +
            '      <th>' + escapeHtml(__('Domain', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Owner', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Product / Price', 'skyhs-hosting-solution')) + '</th>' +
            '      <th>' + escapeHtml(__('Subscription', 'skyhs-hosting-solution')) + '</th>' +
            '      <th style="text-align:right;">' + escapeHtml(__('Actions', 'skyhs-hosting-solution')) + '</th>' +
            '    </tr>' +
            '  </thead>' +
            '  <tbody id="skyhshoso-hm-table-body"></tbody>' +
            '</table>';

        var $table = $(tableHtml);
        var $tbody = $table.find('#skyhshoso-hm-table-body');

        $.each(domains, function(i, d) {
            $tbody.append(createDomainRow(d));
        });

        $container.append($table);
    }

    function updatePagination() {
        $('#hm-page-info').text(__('Page', 'skyhs-hosting-solution') + ' ' + currentPage + ' ' + __('of', 'skyhs-hosting-solution') + ' ' + totalPages);
        $('#hm-prev-page').prop('disabled', currentPage <= 1);
        $('#hm-next-page').prop('disabled', currentPage >= totalPages);
    }

    $('#hm-prev-page').on('click', function(e) {
        e.preventDefault();
        if (currentPage > 1) fetchDomains(currentPage - 1);
    });

    $('#hm-next-page').on('click', function(e) {
        e.preventDefault();
        if (currentPage < totalPages) fetchDomains(currentPage + 1);
    });

    var searchTimeout = null;
    $('#hm-search-input').on('keyup input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() { fetchDomains(1); }, 350);
    });

    $('#hm-status-filter').on('change', function() {
        fetchDomains(1);
    });

    function createDomainRow(d) {
        var badgeClass = 'hm-badge-default';
        var statusLabel = d.sub_status_label;
        switch (d.sub_status) {
            case 'active': badgeClass = 'hm-badge-active'; break;
            case 'pending': case 'on-hold': case 'pending-cancel': badgeClass = 'hm-badge-pending'; break;
            case 'cancelled': case 'suspended': badgeClass = 'hm-badge-cancelled'; break;
        }

        var domainDisplay = '<a href="http://' + d.domain + '" target="_blank" style="color:#3b82f6;text-decoration:none;font-weight:600;">' + escapeHtml(d.domain) + '</a>';
        var subDisplay = d.subscription_id ? '<a href="#" class="skyhshoso-edit-sub-link" data-sub-id="' + d.subscription_id + '" style="font-weight:600; color:#3b82f6; text-decoration:none;">#' + d.subscription_id + '</a>' : '<span style="color:#9ca3af;font-style:italic;">' + escapeHtml(__('None', 'skyhs-hosting-solution')) + '</span>';

        var productDisplay = '<strong>' + escapeHtml(d.product_title) + '</strong>';
        if (d.product_price) {
            productDisplay += '<br/><span style="color:#6b7280;font-size:11px;">' + escapeHtml(d.product_price) + '</span>';
        }

        var rowHtml = '<tr data-id="' + d.id + '">' +
            '  <td style="font-size:13px;color:#374151;">' + domainDisplay + '</td>' +
            '  <td style="color:#4b5563;font-size:13px;" title="' + escapeHtml(d.owner_name) + '">' + escapeHtml(d.owner_name) + '</td>' +
            '  <td style="font-size:13px;">' + productDisplay + '</td>' +
            '  <td style="font-size:13px;">' +
            '    <div style="display:flex;align-items:center;gap:8px;">' +
            '      <span style="font-weight:600;">' + subDisplay + '</span>' +
            '      <span class="hm-badge ' + badgeClass + '"><span class="hm-badge-status-dot"></span>' + escapeHtml(statusLabel) + '</span>' +
            '    </div>' +
            '  </td>' +
            '  <td style="text-align:right;">' +
            '    <div style="display:inline-flex;gap:6px;">' +
            '      <button class="hm-btn-edit button button-small" data-id="' + d.id + '">' + escapeHtml(__('Edit', 'skyhs-hosting-solution')) + '</button>' +
            '      <button class="hm-btn-sync button button-small" data-id="' + d.id + '">' + escapeHtml(__('Sync', 'skyhs-hosting-solution')) + '</button>' +
            '      <button class="hm-btn-delete button button-small" data-id="' + d.id + '">' + escapeHtml(__('Delete', 'skyhs-hosting-solution')) + '</button>' +
            '    </div>' +
            '  </td>' +
            '</tr>';

        return $(rowHtml);
    }

    $container.on('click', '.hm-btn-edit', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var d = domains.find(function(x) { return x.id == id; });
        if (!d) return;

        $domainId.val(d.id);
        $titleInput.val(d.title);
        $ownerSearch.val(d.owner_name);
        $ownerSelect.val(d.owner_id);
        $domainSearch.val(d.domain);
        $selectedDomain.val(d.domain);
        selectedDomainData = { domain: d.domain, price: 0, tld: '' };

        $searchResults.hide().empty();
        $regSection.show();

        if (d.subscription_id) {
            $('#dm-current-sub-info').html(
                '<span style="display:inline-flex;align-items:center;gap:6px;padding:6px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:12px;color:#166534;">' +
                '<strong>' + __('Current:', 'skyhs-hosting-solution') + '</strong> #' + d.subscription_id + ' — ' + escapeHtml(d.sub_status_label) +
                '</span>'
            ).show();
            $('#dm_sub_action').val('keep');
            $('#dm_existing_sub_id').val(d.subscription_id);
            $('#dm_existing_sub_search').val('');
            $('#dm_existing_sub_container').hide();
        } else {
            $('#dm-current-sub-info').hide().empty();
            $('#dm_sub_action').val('create');
            $('#dm_existing_sub_id').val('');
            $('#dm_existing_sub_search').val('');
            $('#dm_existing_sub_container').hide();
        }

        $formTitle.text(__('Edit Domain Account', 'skyhs-hosting-solution'));
        $cancelBtn.show();
        $formPanel.slideDown(250, function() {
            $('html, body').animate({ scrollTop: $formPanel.offset().top - 40 }, 250);
        });
    });

    $container.on('click', '.hm-btn-delete', function(e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        var id = $(this).data('id');

        if (!confirm(strings.confirm_delete)) return;

        var data = {
            action: 'skyhshoso_delete_domain',
            nonce: skyhshoso_dm.nonce_delete,
            hosting_id: id
        };

        $.post(skyhshoso_dm.ajax_url, data, function(res) {
            if (res.success) {
                $row.fadeOut(400, function() {
                    $row.remove();
                    domains = domains.filter(function(x) { return x.id != id; });
                    if (domains.length === 0) {
                        fetchDomains(currentPage > 1 ? currentPage - 1 : 1);
                    } else {
                        fetchDomains(currentPage);
                    }
                });
            } else {
                alert(res.data.message || strings.error);
            }
        }).fail(function() {
            alert(strings.error);
        });
    });

    $container.on('click', '.hm-btn-sync', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var $row = $btn.closest('tr');
        var id = $btn.data('id');

        $btn.text(__('Syncing...', 'skyhs-hosting-solution')).prop('disabled', true);

        var data = {
            action: 'skyhshoso_quick_sync_domain',
            nonce: skyhshoso_dm.nonce_sync,
            hosting_id: id
        };

        $.post(skyhshoso_dm.ajax_url, data, function(res) {
            $btn.text(__('Sync', 'skyhs-hosting-solution')).prop('disabled', false);
            if (res.success) {
                var d = res.data;
                var idx = domains.findIndex(function(x) { return x.id == id; });
                if (idx !== -1) domains[idx] = d;
                $row.replaceWith(createDomainRow(d));
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
        setTimeout(function() { $notice.fadeOut(300); }, 5000);
    }

    $(document).on('skyhshoso_subscription_updated', function(e, subId) {
        fetchDomains(currentPage);
    });
});
