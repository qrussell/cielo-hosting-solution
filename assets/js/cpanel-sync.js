/**
 * SkyHS cPanel Accounts Sync UI
 * Full-featured sync manager matching ENOM Manager UX.
 */
jQuery(document).ready(function($) {
    'use strict';

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    var data = window.skyhshoso_cpanel_sync || {};
    if (!data.servers) return;

    var strs = data.strings;
    var isSyncing = false;
    var currentServerId = 0;
    var currentPage = 1;
    var totalPages = 1;
    var accountsData = [];

    // DOM cache
    var $notice = $('#skyhshoso-cpanel-notice');
    var $container = $('#cpanel-accounts-container');
    var $serverSelect = $('#cpanel-server-select');
    var $syncBtn = $('#cpanel-sync-btn');
    var $loader = $('#cpanel-loader');
    var $syncStatus = $('#cpanel-sync-status');
    var $statsBar = $('#cpanel-stats-bar');
    var $totalCount = $('#cpanel-total-count');
    var $lastSync = $('#cpanel-last-sync');
    var $serverNameLabel = $('#cpanel-server-name');
    var $searchInput = $('#cpanel-search-input');
    var $pageInfo = $('#cpanel-page-info');
    var $prevBtn = $('#cpanel-prev-page');
    var $nextBtn = $('#cpanel-next-page');

    // Populate server dropdown
    $.each(data.servers, function(i, s) {
        $serverSelect.append($('<option>', {
            value: s.id,
            text: s.name + (s.host ? ' (' + s.host + ')' : '')
        }));
    });

    // ---- Server selection + auto-load ----
    $serverSelect.on('change', function() {
        currentServerId = $(this).val();
        currentPage = 1;
        $syncBtn.prop('disabled', !currentServerId);
        if (currentServerId) {
            loadCached();
        } else {
            showEmptyState(strs.select_server);
            $statsBar.hide();
            resetPagination();
        }
    });

    // ---- Sync ----
    $syncBtn.on('click', function() {
        if (!currentServerId) {
            showNotice('error', strs.select_server);
            return;
        }
        doSync();
    });

    function doSync() {
        isSyncing = true;
        $syncBtn.prop('disabled', true);
        $syncStatus.text(strs.syncing);
        $loader.addClass('is-active');
        $container.hide();
        $statsBar.hide();

        $.ajax({
            url: data.ajax_url,
            method: 'POST',
            data: {
                action: 'skyhshoso_cpanel_sync_fetch',
                nonce: data.nonce_fetch,
                server_id: currentServerId
            },
            timeout: 120000,
            success: function(resp) {
                if (resp.success) {
                    showNotice('success', resp.data.message);
                    currentPage = 1;
                    loadCached();
                } else {
                    syncDone();
                    showNotice('error', resp.data.message || strs.error);
                }
            },
            error: function(jqXHR, textStatus) {
                syncDone();
                var msg = 'AJAX ' + textStatus;
                if (textStatus === 'timeout') {
                    msg = 'Sync request timed out. The server may have too many accounts or PHP max_execution_time is too low.';
                } else if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
                    msg = jqXHR.responseJSON.data.message;
                }
                showNotice('error', msg);
            }
        });
    }

    function syncDone() {
        isSyncing = false;
        $syncBtn.prop('disabled', false);
        $loader.removeClass('is-active');
        $syncStatus.text('');
    }

    // ---- Load cached accounts from DB ----
    function loadCached() {
        $loader.addClass('is-active');
        $syncStatus.text(strs.loading);

        $.ajax({
            url: data.ajax_url,
            method: 'POST',
            data: {
                action: 'skyhshoso_cpanel_get_cached',
                nonce: data.nonce_cached,
                server_id: currentServerId,
                paged: currentPage,
                search: $searchInput.val(),
                limit: 20
            },
            timeout: 30000,
            success: function(resp) {
                $loader.removeClass('is-active');
                $syncStatus.text('');
                if (resp.success) {
                    accountsData = resp.data.accounts || [];
                    totalPages = resp.data.total_pages;
                    currentPage = resp.data.current_page;
                    renderAccounts();
                    updateStats(resp.data);
                    updatePagination();
                    $container.show();
                } else {
                    showNotice('error', resp.data.message || strs.error);
                }
            },
            error: function() {
                $loader.removeClass('is-active');
                $syncStatus.text('');
                showNotice('error', strs.error);
            }
        });
    }

    // ---- Render ----
    function renderAccounts() {
        $container.empty();

        if (accountsData.length === 0) {
            $container.append(
                '<div class="skyhshoso-hm-empty"><p>' + escapeHtml(strs.no_results) + '</p></div>'
            );
            return;
        }

        var tableHtml =
            '<table class="skyhshoso-hm-table">' +
            '  <thead>' +
            '    <tr>' +
            '      <th>Username</th>' +
            '      <th>Domain</th>' +
            '      <th>Plan</th>' +
            '      <th>Status</th>' +
            '      <th>Disk</th>' +
            '      <th>Created</th>' +
            '      <th style="text-align:right;">Actions</th>' +
            '    </tr>' +
            '  </thead>' +
            '  <tbody id="cpanel-accounts-body"></tbody>' +
            '</table>';

        var $table = $(tableHtml);
        var $tbody = $table.find('#cpanel-accounts-body');

        $.each(accountsData, function(i, acct) {
            var statusHtml = acct.suspended
                ? '<span style="color:#dc2626;font-weight:600;">Suspended</span>'
                : '<span style="color:#16a34a;font-weight:600;">Active</span>';

            var diskHtml = formatDisk(acct.disk_used, acct.disk_limit);
            var startdate = acct.startdate || '—';
            if (startdate !== '—' && !isNaN(startdate)) {
                var d = new Date(parseInt(startdate) * 1000);
                if (!isNaN(d.getTime())) {
                    startdate = d.toLocaleDateString();
                }
            }

            var rowHtml =
                '<tr data-id="' + acct.id + '">' +
                '  <td><strong>' + escapeHtml(acct.username) + '</strong></td>' +
                '  <td>' + escapeHtml(acct.domain) + '</td>' +
                '  <td>' + escapeHtml(acct.plan) + '</td>' +
                '  <td>' + statusHtml + '</td>' +
                '  <td style="font-size:12px;">' + diskHtml + '</td>' +
                '  <td style="font-size:12px;color:#6b7280;">' + escapeHtml(startdate) + '</td>' +
                '  <td style="text-align:right;">' +
                '    <button class="button button-small cpanel-delete-btn" data-id="' + acct.id + '" style="color:#dc2626;border-color:#fecaca;">Delete</button>' +
                '  </td>' +
                '</tr>';

            $tbody.append($(rowHtml));
        });

        $container.append($table);
    }

    function formatDisk(used, limit) {
        if (!used && !limit) return '—';
        var usedStr = used ? (used >= 1024 ? (used / 1024).toFixed(1) + ' GB' : used.toFixed(0) + ' MB') : '0 MB';
        var limitStr = limit ? (limit >= 1024 ? (limit / 1024).toFixed(1) + ' GB' : limit.toFixed(0) + ' MB') : '∞';
        return usedStr + ' / ' + limitStr;
    }

    function updateStats(data) {
        var label = data.total_records === 1 ? strs.account : strs.accounts;
        $totalCount.text(data.total_records + ' ' + label);
        $lastSync.text(data.last_sync ? 'Last synced: ' + data.last_sync : strs.never_synced);
        $serverNameLabel.text(data.server_name ? 'Server: ' + data.server_name : '');
        $statsBar.show();
    }

    function updatePagination() {
        $pageInfo.text('Page ' + currentPage + ' of ' + totalPages);
        $prevBtn.prop('disabled', currentPage <= 1);
        $nextBtn.prop('disabled', currentPage >= totalPages);
    }

    function resetPagination() {
        $pageInfo.text('Page 1 of 1');
        $prevBtn.prop('disabled', true);
        $nextBtn.prop('disabled', true);
    }

    function showEmptyState(msg) {
        $container.html('<div class="skyhshoso-hm-empty"><p>' + escapeHtml(msg) + '</p></div>');
    }

    // ---- Search ----
    var searchTimeout = null;
    $searchInput.on('keyup input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            if (currentServerId) {
                currentPage = 1;
                loadCached();
            }
        }, 350);
    });

    // ---- Pagination ----
    $prevBtn.on('click', function() {
        if (currentPage > 1) { currentPage--; loadCached(); }
    });
    $nextBtn.on('click', function() {
        if (currentPage < totalPages) { currentPage++; loadCached(); }
    });

    // ---- Delete ----
    $container.on('click', '.cpanel-delete-btn', function() {
        if (!confirm(strs.confirm_delete)) return;

        var $btn = $(this);
        var id = $btn.data('id');
        $btn.prop('disabled', true).text('...');

        $.post(data.ajax_url, {
            action: 'skyhshoso_cpanel_delete_cached',
            nonce: data.nonce_delete,
            id: id
        }, function(resp) {
            if (resp.success) {
                showNotice('success', resp.data.message);
                loadCached();
            } else {
                showNotice('error', resp.data.message || strs.error);
                $btn.prop('disabled', false).text('Delete');
            }
        }).fail(function() {
            showNotice('error', strs.error);
            $btn.prop('disabled', false).text('Delete');
        });
    });

    // ---- Utilities ----
    function showNotice(type, msg) {
        $notice.removeClass('notice-success notice-error notice-info').addClass('notice-' + type)
            .html('<p>' + msg + '</p>').show();
        $('html, body').animate({ scrollTop: $notice.offset().top - 40 }, 150);
        if (type === 'success') {
            setTimeout(function() { $notice.fadeOut(5000); }, 5000);
        }
    }

    // ---- Init ----
    // Auto-load if first server has cached data
    if (data.servers.length > 0) {
        var first = data.servers[0];
        $serverSelect.val(first.id).trigger('change');
    }
});
