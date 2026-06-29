jQuery(document).ready(function($) {
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    if (typeof skyhshoso_enom_sync === 'undefined') return;

    var strs = skyhshoso_enom_sync.strings;
    var domainsData = [];
    var currentDetailDomain = null;
    var isSyncing = false;
    var isProcessing = false;
    var processingPaused = false;
    var processingStopped = false;

    var $notice = $('#skyhshoso-es-notice');
    var $container = $('#es-container');
    var $loader = $('#es-loader');
    var $loaderText = $('#es-loader-text');
    var $tableBody = $('#es-table-body');
    var $totalCount = $('#es-total-count');
    var $lastSynced = $('#es-last-synced');
    var $queueStatus = $('#es-queue-status');
    var $searchInput = $('#es-search-input');
    var $detailPanel = $('#es-detail-panel');
    var $detailContent = $('#es-detail-content');
    var $detailTitle = $('#es-detail-title');
    var $refreshBtn = $('#es-refresh-btn');

    $refreshBtn.on('click', function() { if (!isSyncing) doSync(); });
    $searchInput.on('keyup input', function() { renderDomains(); });

    // ---- Sync: fetch domain list from Enom, save to DB ----
    function doSync() {
        isSyncing = true;
        $refreshBtn.prop('disabled', true);
        showLoader('Syncing domains from Enom...');
        $container.hide();
        $detailPanel.hide();
        hideQueueControls();

        $.ajax({
            url: skyhshoso_enom_sync.ajax_url,
            method: 'POST',
            data: {
                action: 'skyhshoso_enom_sync_fetch',
                nonce: skyhshoso_enom_sync.nonce_fetch,
            },
            timeout: 120000,
            success: function(res) {
                if (res.success) {
                    showNotice('success', res.data.message);
                    loadCached(function() {
                        startProcessing();
                    });
                } else {
                    syncDone();
                    showNotice('error', res.data.message || strs.error);
                }
            },
            error: function(jqXHR, textStatus) {
                syncDone();
                var msg = 'AJAX ' + textStatus;
                if (textStatus === 'timeout') msg = 'Request timed out. PHP max_execution_time may be too low. Check debug.log.';
                else if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) msg = jqXHR.responseJSON.data.message;
                showNotice('error', msg);
            }
        });
    }

    // ---- Process one domain at a time via AJAX (no WP Cron dependency) ----
    function startProcessing() {
        isProcessing = true;
        processingPaused = false;
        processingStopped = false;
        $queueStatus.show();
        showQueueControls();
        processNext();
    }

    function processNext() {
        if (processingStopped) {
            isProcessing = false;
            $queueStatus.text('Queue stopped.').css('color', '#dc2626');
            hideQueueControls();
            return;
        }
        if (processingPaused) {
            $queueStatus.text('Queue paused. Click Resume to continue.').css('color', '#d97706');
            return;
        }

        $.ajax({
            url: skyhshoso_enom_sync.ajax_url,
            method: 'POST',
            data: {
                action: 'skyhshoso_enom_process_next',
                nonce: skyhshoso_enom_sync.nonce_process,
            },
            timeout: 60000,
            success: function(res) {
                if (res.success) {
                    if (res.data.done) {
                        isProcessing = false;
                        $queueStatus.hide();
                        hideQueueControls();
                        showNotice('success', 'Status loaded for all ' + res.data.total + ' domains.');
                        syncDone();
                        loadCached();
                    } else {
                        var done = res.data.total - res.data.remaining;
                        $queueStatus.html('Processing <strong>' + escapeHtml(res.data.domain) + '</strong> &mdash; ' + done + ' / ' + res.data.total + ' complete.').css('color', '#2563eb');
                        setTimeout(processNext, 1200);
                    }
                } else {
                    showNotice('error', res.data.message || strs.error);
                    isProcessing = false;
                    hideQueueControls();
                }
            },
            error: function() {
                $queueStatus.text('AJAX error — retrying in 5s...').css('color', '#dc2626');
                setTimeout(processNext, 5000);
            }
        });
    }

    function syncDone() {
        isSyncing = false;
        $refreshBtn.prop('disabled', false);
        hideLoader();
    }

    // ---- Queue control buttons ----
    function showQueueControls() {
        var $controls = $('#es-queue-controls');
        if (!$controls.length) {
            $controls = $(
'<div id="es-queue-controls" style="display:inline-flex;gap:6px;margin-left:12px;">' +
'<button type="button" class="button button-small es-pause-btn" style="border-color:#d97706;color:#d97706;font-weight:600;">Pause</button>' +
'<button type="button" class="button button-small es-resume-btn" style="border-color:#059669;color:#059669;font-weight:600;display:none;">Resume</button>' +
'<button type="button" class="button button-small es-stop-btn" style="border-color:#dc2626;color:#dc2626;font-weight:600;">Stop</button>' +
'</div>'
            );
            $queueStatus.after($controls);
        }
        $controls.show().find('.es-pause-btn, .es-resume-btn, .es-stop-btn').show();
    }

    function hideQueueControls() {
        $('#es-queue-controls').hide();
    }

    $('body').on('click', '.es-pause-btn', function() {
        processingPaused = true;
        $(this).hide();
        $('.es-resume-btn').show();
    });

    $('body').on('click', '.es-resume-btn', function() {
        processingPaused = false;
        $(this).hide();
        $('.es-pause-btn').show();
        if (isProcessing) processNext();
    });

    $('body').on('click', '.es-stop-btn', function() {
        if (!confirm('Stop processing the remaining domains?')) return;
        processingStopped = true;
        processingPaused = false;
        isProcessing = false;
        $('.es-pause-btn, .es-resume-btn').hide();
        $queueStatus.text('Queue stopped.').css('color', '#dc2626');
        $.ajax({
            url: skyhshoso_enom_sync.ajax_url, method: 'POST', timeout: 15000,
            data: { action: 'skyhshoso_enom_stop_queue', nonce: skyhshoso_enom_sync.nonce_stop },
        });
        syncDone();
    });

    // ---- Load cached data from DB ----
    function loadCached(callback) {
        showLoader('Loading...');
        $.ajax({
            url: skyhshoso_enom_sync.ajax_url,
            method: 'POST',
            data: {
                action: 'skyhshoso_enom_get_cached',
                nonce: skyhshoso_enom_sync.nonce_cached,
            },
            timeout: 30000,
            success: function(res) {
                hideLoader();
                if (res.success) {
                    domainsData = res.data.domains || [];
                    renderDomains();
                    $totalCount.text((res.data.total_count || 0) + ' domain' + ((res.data.total_count || 0) !== 1 ? 's' : ''));
                    $lastSynced.text(res.data.last_synced ? 'Last synced: ' + res.data.last_synced : '');
                    $container.show();
                    if (callback) callback();
                } else {
                    showNotice('error', res.data.message || strs.error);
                    if (callback) callback();
                }
            },
            error: function() { hideLoader(); showNotice('error', strs.error); if (callback) callback(); }
        });
    }

    // ---- Check if domains need processing on load ----
    function checkPendingOnLoad() {
        $.ajax({
            url: skyhshoso_enom_sync.ajax_url, method: 'POST', timeout: 15000,
            data: { action: 'skyhshoso_enom_poll_queue', nonce: skyhshoso_enom_sync.nonce_poll },
            success: function(res) {
                if (res.success && res.data.remaining > 0 && !res.data.done) {
                    showNotice('notice-warning', res.data.remaining + ' domain(s) have pending status. Click "Sync from Enom" to resume, or <a href="#" id="es-resume-stale-link">resume here</a>.');
                    $('body').on('click', '#es-resume-stale-link', function(e) {
                        e.preventDefault(); doSync();
                    });
                }
            }
        });
    }

    // ---- Render table ----
    function renderDomains() {
        $tableBody.empty();
        if (domainsData.length === 0) {
            $tableBody.append('<tr><td colspan="5" style="text-align:center;padding:40px;color:#9ca3af;font-size:14px;">No domains found. Click <strong>Sync from Enom</strong> to fetch your domains.</td></tr>');
            return;
        }
        var term = $searchInput.val().toLowerCase().trim();
        var filtered = term ? domainsData.filter(function(d) { return d.domain.toLowerCase().indexOf(term) !== -1; }) : domainsData;
        if (filtered.length === 0) {
            $tableBody.append('<tr><td colspan="5" style="text-align:center;padding:30px;color:#9ca3af;">' + escapeHtml(strs.no_results) + '</td></tr>');
            return;
        }
        filtered.forEach(function(d) { $tableBody.append(createRow(d)); });
    }

    function createRow(d) {
        var lockLabel = d.reg_lock === '1' ? 'Locked' : (d.reg_lock === '0' ? 'Unlocked' : '?');
        var lockClass = d.reg_lock === '1' ? 'hm-badge-active' : 'hm-badge-pending';
        var statusLabel = d.ns_status === 'YES' ? 'Active' : 'Inactive';
        var statusClass = d.ns_status === 'YES' ? 'hm-badge-active' : 'hm-badge-pending';

        return $(

'<tr data-domain="' + escapeHtml(d.domain) + '">' +
'<td style="font-size:13px;color:#374151;font-weight:600;">' + escapeHtml(d.domain) + '</td>' +
'<td style="font-size:13px;color:#4b5563;">' + escapeHtml(d.expiration_date || '--') + '</td>' +
'<td><span class="hm-badge ' + statusClass + '">' + statusLabel + '</span></td>' +
'<td><span class="hm-badge ' + lockClass + '">' + lockLabel + '</span></td>' +
'<td style="text-align:right;"><div style="display:inline-flex;gap:4px;flex-wrap:wrap;justify-content:flex-end;">' +
'<button class="button button-small es-view-btn" data-domain="' + escapeHtml(d.domain) + '">View / Edit</button>' +
'<button class="button button-small es-lock-btn" data-domain="' + escapeHtml(d.domain) + '" data-locked="' + (d.reg_lock === '1' ? '1' : '0') + '">' + (d.reg_lock === '1' ? 'Unlock' : 'Lock') + '</button>' +
'<button class="button button-small es-delete-btn" data-domain="' + escapeHtml(d.domain) + '" style="color:#dc2626;border-color:#fecaca;">Delete</button>' +
'</div></td></tr>'
        );
    }

    // ---- Event handlers ----
    $tableBody.on('click', '.es-view-btn', function() { showDomainDetail($(this).data('domain')); });
    $tableBody.on('click', '.es-lock-btn', function() {
        var $btn = $(this);
        var locked = $btn.data('locked') == 1;
        if (!confirm(locked ? 'Unlock this domain for transfer?' : 'Lock this domain to prevent transfers?')) return;
        doToggleLock($btn.data('domain'), !locked, $btn);
    });
    $tableBody.on('click', '.es-delete-btn', function() {
        var $btn = $(this);
        var domain = $btn.data('domain');
        if (!confirm('Remove ' + domain + ' from the local cache? This does not affect your Enom account.')) return;
        doDeleteDomain(domain, $btn);
    });

    function doToggleLock(domain, lock, $btn) {
        $btn.prop('disabled', true).text('Saving...');
        $.ajax({
            url: skyhshoso_enom_sync.ajax_url, method: 'POST', timeout: 30000,
            data: { action: 'skyhshoso_enom_toggle_lock', nonce: skyhshoso_enom_sync.nonce_lock, domain: domain, lock: lock ? 1 : 0 },
            success: function(res) {
                if (res.success) {
                    showNotice('success', res.data.message);
                    $btn.closest('tr').find('td:eq(3) .hm-badge').text(lock ? 'Locked' : 'Unlocked')
                        .removeClass('hm-badge-active hm-badge-pending').addClass(lock ? 'hm-badge-active' : 'hm-badge-pending');
                    $btn.data('locked', lock ? '1' : '0').text(lock ? 'Unlock' : 'Lock').prop('disabled', false);
                    updateDetailLock(domain, lock);
                } else { showNotice('error', res.data.message || strs.error); $btn.prop('disabled', false).text(lock ? 'Lock' : 'Unlock'); }
            },
            error: function() { showNotice('error', strs.error); $btn.prop('disabled', false).text(lock ? 'Lock' : 'Unlock'); }
        });
    }

    function doDeleteDomain(domain, $btn) {
        $btn.prop('disabled', true).text('Removing...');
        $.ajax({
            url: skyhshoso_enom_sync.ajax_url, method: 'POST', timeout: 30000,
            data: { action: 'skyhshoso_enom_delete_domain', nonce: skyhshoso_enom_sync.nonce_delete, domain: domain },
            success: function(res) {
                if (res.success) {
                    showNotice('success', res.data.message);
                    $btn.closest('tr').remove();
                    var idx = -1;
                    for (var i = 0; i < domainsData.length; i++) { if (domainsData[i].domain === domain) { idx = i; break; } }
                    if (idx >= 0) domainsData.splice(idx, 1);
                    $totalCount.text(domainsData.length + ' domain' + (domainsData.length !== 1 ? 's' : ''));
                    if (currentDetailDomain === domain) $detailPanel.slideUp(250);
                } else { showNotice('error', res.data.message || strs.error); $btn.prop('disabled', false).text('Delete'); }
            },
            error: function() { showNotice('error', strs.error); $btn.prop('disabled', false).text('Delete'); }
        });
    }

    // ---- Detail panel ----
    function showDomainDetail(domain) {
        currentDetailDomain = domain;
        showLoader('Loading domain details...');
        $detailPanel.hide();
        $.ajax({
            url: skyhshoso_enom_sync.ajax_url, method: 'POST', timeout: 30000,
            data: { action: 'skyhshoso_enom_get_details', nonce: skyhshoso_enom_sync.nonce_details, domain: domain },
            success: function(res) {
                hideLoader();
                if (res.success) { renderDetailPanel(domain, res.data); $detailPanel.show(); $('html, body').animate({ scrollTop: $detailPanel.offset().top - 40 }, 250); }
                else { showNotice('error', res.data.message || strs.error); }
            },
            error: function() { hideLoader(); showNotice('error', strs.error); }
        });
    }

    function renderDetailPanel(domain, data) {
        var info = data.info || {};
        $detailTitle.text('Details: ' + domain);
        var cd = findDomainData(domain);
        var regLock = cd ? cd.reg_lock : '?';

        var nsHtml = (info.nameservers && info.nameservers.length)
            ? info.nameservers.map(function(ns) { return '<div style="font-family:monospace;font-size:12px;padding:2px 0;">' + escapeHtml(ns) + '</div>'; }).join('')
            : '<span style="color:#9ca3af;">--</span>';

        var html =
'<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">' +
'  <div class="skyhshoso-hm-section"><h3>Domain Info</h3>' +
'    <table style="width:100%;border-collapse:collapse;font-size:13px;">' +
'      <tr><td style="padding:6px 0;color:#6b7280;font-weight:500;width:140px;">Registration Status</td><td>' + escapeHtml(info.registration_status || '--') + '</td></tr>' +
'      <tr><td style="padding:6px 0;color:#6b7280;font-weight:500;">Purchase Status</td><td>' + escapeHtml(info.purchase_status || '--') + '</td></tr>' +
'      <tr><td style="padding:6px 0;color:#6b7280;font-weight:500;">Expiration</td><td>' + escapeHtml(info.expiration || '--') + '</td></tr>' +
'      <tr><td style="padding:6px 0;color:#6b7280;font-weight:500;">Registrar</td><td>' + escapeHtml(info.registrar || '--') + '</td></tr>' +
'    </table></div>' +
'  <div class="skyhshoso-hm-section"><h3>Nameservers</h3><div style="padding:8px 0;">' + nsHtml + '</div></div>' +
'</div>' +
'<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:16px;">' +
'  <div class="skyhshoso-hm-section"><h3>Registrar Lock</h3><div style="display:flex;align-items:center;gap:12px;padding:8px 0;"><span class="hm-badge ' + (regLock === '1' ? 'hm-badge-active' : 'hm-badge-pending') + '">' + (regLock === '1' ? 'Locked' : (regLock === '0' ? 'Unlocked' : '?')) + '</span>' +
'    <button class="button button-small es-detail-lock-btn" data-domain="' + escapeHtml(domain) + '" data-locked="' + (regLock === '1' ? '1' : '0') + '">' + (regLock === '1' ? 'Unlock' : 'Lock') + '</button></div></div>' +
'</div>' +
'<div class="skyhshoso-hm-section" style="margin-top:16px;"><h3>WHOIS Contacts</h3><div id="es-contacts-container" style="padding:8px 0;"><div style="color:#6b7280;font-size:13px;">Loading contacts...</div></div></div>' +
'<div class="skyhshoso-hm-actions" style="margin-top:16px;"><div class="skyhshoso-hm-actions-right"><button type="button" id="es-close-detail-btn" class="button">Close</button></div></div>';

        $detailContent.html(html);
        loadContacts(domain);
    }

    function loadContacts(domain) {
        $.ajax({
            url: skyhshoso_enom_sync.ajax_url, method: 'POST', timeout: 30000,
            data: { action: 'skyhshoso_enom_get_contacts', nonce: skyhshoso_enom_sync.nonce_contacts, domain: domain },
            success: function(res) {
                if (res.success) renderContactsUI(res.data);
                else $('#es-contacts-container').html('<div style="color:#dc2626;font-size:13px;">' + escapeHtml(res.data.message || strs.error) + '</div>');
            },
            error: function() { $('#es-contacts-container').html('<div style="color:#dc2626;font-size:13px;">' + escapeHtml(strs.error) + '</div>'); }
        });
    }

    function renderContactsUI(contacts) {
        var types = ['registrant', 'admin', 'tech', 'auxbilling'];
        var labels = { registrant: 'Registrant', admin: 'Admin', tech: 'Tech', auxbilling: 'Aux Billing' };
        var html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';
        types.forEach(function(t) {
            var c = contacts[t]; if (!c) return;
            html += '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px;" class="es-contact-card">' +
'<h4 style="margin:0 0 8px 0;font-size:13px;font-weight:700;color:#374151;">' + escapeHtml(labels[t]) + '</h4>' +
'<div class="es-contact-display" style="font-size:12px;color:#4b5563;line-height:1.8;">' +
'<div><strong>Name:</strong> ' + escapeHtml(c.first_name + ' ' + c.last_name) + '</div>' +
'<div><strong>Org:</strong> ' + escapeHtml(c.organization || '--') + '</div>' +
'<div><strong>Email:</strong> ' + escapeHtml(c.email || '--') + '</div>' +
'<div><strong>Phone:</strong> ' + escapeHtml(c.phone || '--') + '</div>' +
'<div><strong>Address:</strong> ' + escapeHtml(c.address1 || '') + (c.address2 ? ', ' + escapeHtml(c.address2) : '') + '</div>' +
'<div><strong>City:</strong> ' + escapeHtml(c.city || '') + ', ' + escapeHtml(c.state_province || '') + ' ' + escapeHtml(c.postal_code || '') + '</div>' +
'<div><strong>Country:</strong> ' + escapeHtml(c.country || '--') + '</div></div>' +
'<button class="button button-small es-edit-contact-btn" style="margin-top:8px;" data-type="' + escapeHtml(t) + '">Edit</button></div>';
        });
        html += '</div>';
        $('#es-contacts-container').html(html);
    }

    // ---- Detail panel event handlers ----
    $detailContent.on('click', '.es-detail-lock-btn', function() { var $b = $(this); doToggleLock($b.data('domain'), !($b.data('locked') == 1), $b); });
    $detailContent.on('click', '#es-close-detail-btn', function() { $detailPanel.slideUp(250); currentDetailDomain = null; });

    $detailContent.on('click', '.es-edit-contact-btn', function() {
        var $card = $(this).closest('.es-contact-card');
        var type = $(this).data('type');
        var ct = type.charAt(0).toUpperCase() + type.slice(1);
        if (ct === 'Auxbilling') ct = 'AuxBilling';

        var $form = $(
'<div class="es-contact-form" style="margin-top:10px;padding:12px;background:#fff;border:1px solid #d1d5db;border-radius:6px;">' +
'<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">' +
'<div><label style="font-size:11px;font-weight:600;color:#6b7280;">First Name</label><input type="text" class="es-cf-first_name hm-control-input" style="width:100%;" /></div>' +
'<div><label style="font-size:11px;font-weight:600;color:#6b7280;">Last Name</label><input type="text" class="es-cf-last_name hm-control-input" style="width:100%;" /></div>' +
'<div style="grid-column:span 2;"><label style="font-size:11px;font-weight:600;color:#6b7280;">Organization</label><input type="text" class="es-cf-organization hm-control-input" style="width:100%;" /></div>' +
'<div style="grid-column:span 2;"><label style="font-size:11px;font-weight:600;color:#6b7280;">Address 1</label><input type="text" class="es-cf-address1 hm-control-input" style="width:100%;" /></div>' +
'<div style="grid-column:span 2;"><label style="font-size:11px;font-weight:600;color:#6b7280;">Address 2</label><input type="text" class="es-cf-address2 hm-control-input" style="width:100%;" /></div>' +
'<div><label style="font-size:11px;font-weight:600;color:#6b7280;">City</label><input type="text" class="es-cf-city hm-control-input" style="width:100%;" /></div>' +
'<div><label style="font-size:11px;font-weight:600;color:#6b7280;">State/Province</label><input type="text" class="es-cf-state_province hm-control-input" style="width:100%;" /></div>' +
'<div><label style="font-size:11px;font-weight:600;color:#6b7280;">Postal Code</label><input type="text" class="es-cf-postal_code hm-control-input" style="width:100%;" /></div>' +
'<div><label style="font-size:11px;font-weight:600;color:#6b7280;">Country</label><input type="text" class="es-cf-country hm-control-input" style="width:100%;" /></div>' +
'<div style="grid-column:span 2;"><label style="font-size:11px;font-weight:600;color:#6b7280;">Email</label><input type="email" class="es-cf-email hm-control-input" style="width:100%;" /></div>' +
'<div style="grid-column:span 2;"><label style="font-size:11px;font-weight:600;color:#6b7280;">Phone</label><input type="text" class="es-cf-phone hm-control-input" style="width:100%;" /></div>' +
'</div>' +
'<div style="margin-top:10px;display:flex;gap:8px;">' +
'<button class="button button-primary es-save-contact-btn" data-type="' + escapeHtml(type) + '" data-domain="' + escapeHtml(currentDetailDomain) + '">Save</button>' +
'<button class="button es-cancel-contact-btn">Cancel</button></div></div>'
        );

        var pairs = {};
        $card.find('.es-contact-display div').each(function() {
            var t = $(this).text().split(':');
            if (t.length >= 2) pairs[t[0].trim().toLowerCase()] = t.slice(1).join(':').trim().replace(/^--$/, '');
        });
        var n = (pairs['name'] || '').split(' ');
        $form.find('.es-cf-first_name').val(n[0] || '');
        $form.find('.es-cf-last_name').val(n.slice(1).join(' ') || '');
        $form.find('.es-cf-organization').val(pairs['org'] || '');
        $form.find('.es-cf-email').val(pairs['email'] || '');
        $form.find('.es-cf-phone').val(pairs['phone'] || '');
        $form.find('.es-cf-address1').val(pairs['address'] || '');
        var cp = (pairs['city'] || '').split(', ');
        $form.find('.es-cf-city').val(cp[0] || '');
        if (cp.length > 1) { var sp = cp[1].split(' '); $form.find('.es-cf-state_province').val(sp[0] || ''); $form.find('.es-cf-postal_code').val(sp.slice(1).join(' ') || ''); }
        $form.find('.es-cf-country').val(pairs['country'] || '');
        $card.find('.es-contact-form').remove();
        $card.append($form);
    });

    $detailContent.on('click', '.es-cancel-contact-btn', function() { $(this).closest('.es-contact-form').remove(); });
    $detailContent.on('click', '.es-save-contact-btn', function() {
        var $btn = $(this);
        var $form = $btn.closest('.es-contact-form');
        var data = {
            FirstName: $form.find('.es-cf-first_name').val(), LastName: $form.find('.es-cf-last_name').val(),
            OrganizationName: $form.find('.es-cf-organization').val(), Address1: $form.find('.es-cf-address1').val(),
            Address2: $form.find('.es-cf-address2').val(), City: $form.find('.es-cf-city').val(),
            StateProvince: $form.find('.es-cf-state_province').val(), PostalCode: $form.find('.es-cf-postal_code').val(),
            Country: $form.find('.es-cf-country').val(), EmailAddress: $form.find('.es-cf-email').val(),
            Phone: $form.find('.es-cf-phone').val()
        };
        var type = $btn.data('type');
        var ct = type.charAt(0).toUpperCase() + type.slice(1);
        if (ct === 'Auxbilling') ct = 'AuxBilling';
        $btn.prop('disabled', true).text('Saving...');
        $.ajax({
            url: skyhshoso_enom_sync.ajax_url, method: 'POST', timeout: 30000,
            data: { action: 'skyhshoso_enom_update_contacts', nonce: skyhshoso_enom_sync.nonce_update_contacts, domain: $btn.data('domain'), contact_type: ct, data: data },
            success: function(res) {
                if (res.success) { showNotice('success', res.data.message); $form.remove(); loadContacts(currentDetailDomain); }
                else { showNotice('error', res.data.message || strs.error); $btn.prop('disabled', false).text('Save'); }
            },
            error: function() { showNotice('error', strs.error); $btn.prop('disabled', false).text('Save'); }
        });
    });

    function updateDetailLock(domain, locked) { if (currentDetailDomain !== domain) return; $detailContent.find('.hm-badge').first().text(locked ? 'Locked' : 'Unlocked').removeClass('hm-badge-active hm-badge-pending').addClass(locked ? 'hm-badge-active' : 'hm-badge-pending'); $detailContent.find('.es-detail-lock-btn').data('locked', locked ? '1' : '0').text(locked ? 'Unlock' : 'Lock'); }
    function findDomainData(domain) { for (var i = 0; i < domainsData.length; i++) { if (domainsData[i].domain === domain) return domainsData[i]; } return null; }
    function showLoader(text) { $loaderText.text(text || strs.loading); $loader.show(); }
    function hideLoader() { $loader.hide(); }
    function showNotice(type, msg) { $notice.removeClass('success error notice-warning').addClass(type).html('<p>' + msg + '</p>').fadeIn(150); setTimeout(function() { $notice.fadeOut(5000); }, 5000); $('html, body').animate({ scrollTop: $notice.offset().top - 40 }, 150); }

    // ---- Start ----
    loadCached(checkPendingOnLoad);
});
