/**
 * SkyHS Dashboard JavaScript
 * Phase 1 UX Master Version: Toasts, Skeletons, & Live DOM Updates
 */
(function() {
    'use strict';

    var config = {
        ajaxUrl: typeof skyhshosoDashboard !== 'undefined' && skyhshosoDashboard.ajaxUrl ? skyhshosoDashboard.ajaxUrl : (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'),
        collaboratorNonce: typeof skyhshosoDashboard !== 'undefined' && skyhshosoDashboard.collaboratorNonce ? skyhshosoDashboard.collaboratorNonce : '',
        dashboardNonce: typeof skyhshosoDashboard !== 'undefined' && skyhshosoDashboard.nonce ? skyhshosoDashboard.nonce : '',
        switchNonce: typeof skyhshosoDashboard !== 'undefined' && skyhshosoDashboard.switchNonce ? skyhshosoDashboard.switchNonce : '',
        i18n: typeof skyhshosoDashboard !== 'undefined' && skyhshosoDashboard.i18n ? skyhshosoDashboard.i18n : {}
    };

    // --- PHASE 1: UI/UX INJECTOR ---
    function injectModernStyles() {
        if (document.getElementById('skyhs-modern-ux-styles')) return;
        var style = document.createElement('style');
        style.id = 'skyhs-modern-ux-styles';
        style.innerHTML = `
            /* Toast Notifications */
            .skyhs-toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 999999; display: flex; flex-direction: column; gap: 12px; pointer-events: none; }
            .skyhs-toast { min-width: 280px; max-width: 400px; background: #fff; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); padding: 16px 20px; display: flex; align-items: flex-start; gap: 14px; transform: translateX(120%); transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); border-left: 4px solid #3b82f6; pointer-events: auto; }
            .skyhs-toast.skyhs-toast-visible { transform: translateX(0); }
            .skyhs-toast-success { border-left-color: #10b981; }
            .skyhs-toast-error { border-left-color: #ef4444; }
            .skyhs-toast-icon { flex-shrink: 0; width: 22px; height: 22px; margin-top: 2px; }
            .skyhs-toast-success .skyhs-toast-icon { color: #10b981; }
            .skyhs-toast-error .skyhs-toast-icon { color: #ef4444; }
            .skyhs-toast-message { font-size: 14px; color: #374151; line-height: 1.5; margin: 0; font-family: system-ui, -apple-system, sans-serif; font-weight: 500; }
            /* Skeleton Loaders */
            @keyframes skyhs-shimmer { 0% { background-position: -468px 0; } 100% { background-position: 468px 0; } }
            .skyhs-skeleton { background: #f6f7f8; background-image: linear-gradient(to right, #f6f7f8 0%, #edeef1 20%, #f6f7f8 40%, #f6f7f8 100%); background-repeat: no-repeat; background-size: 800px 100%; animation: skyhs-shimmer 1.5s linear infinite; border-radius: 4px; }
            /* Live Fade Transitions */
            .skyhs-row-updated { animation: skyhs-highlight-row 2s ease-out; }
            @keyframes skyhs-highlight-row { 0% { background-color: #fef3c7; } 100% { background-color: transparent; } }
        `;
        document.head.appendChild(style);
    }

    // --- PHASE 1: TOAST SYSTEM ---
    window.skyhshosoToast = function(msg, type = 'success') {
        let container = document.querySelector('.skyhs-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'skyhs-toast-container';
            document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.className = `skyhs-toast skyhs-toast-${type}`;
        
        const iconSvg = type === 'success' 
            ? `<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>`
            : `<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>`;
        
        toast.innerHTML = `<div class="skyhs-toast-icon">${iconSvg}</div><p class="skyhs-toast-message">${msg}</p>`;
        container.appendChild(toast);
        
        // Trigger sliding animation
        requestAnimationFrame(() => toast.classList.add('skyhs-toast-visible'));
        
        setTimeout(() => {
            toast.classList.remove('skyhs-toast-visible');
            setTimeout(() => toast.remove(), 400); // Wait for slide-out transition
        }, 4500);
    };

    // Global Fleet Scanner Function (Upgraded with Skeletons)
    window.skyhshosoProcessAsyncFleetScan = function(hostingIds) {
        var tbody = document.getElementById('skyhshoso-wp-site-tbody');
        if (!tbody) return;

        // PHASE 1: Shimmering Skeleton Loader
        var scanningRow = document.createElement('tr');
        scanningRow.id = 'skyhs-fleet-scanning-row';
        scanningRow.innerHTML = `
            <td style="padding:16px;vertical-align:middle;">
                <div class="skyhs-skeleton" style="width: 45%; height: 16px; margin-bottom: 8px;"></div>
                <div class="skyhs-skeleton" style="width: 25%; height: 12px;"></div>
            </td>
            <td style="padding:16px;vertical-align:middle;">
                <div class="skyhs-skeleton" style="width: 55px; height: 22px; border-radius: 12px;"></div>
            </td>
            <td style="padding:16px;text-align:right;vertical-align:middle;">
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <div class="skyhs-skeleton" style="width: 110px; height: 32px; border-radius: 6px;"></div>
                    <div class="skyhs-skeleton" style="width: 90px; height: 32px; border-radius: 6px;"></div>
                    <div class="skyhs-skeleton" style="width: 90px; height: 32px; border-radius: 6px;"></div>
                </div>
            </td>
        `;
        tbody.appendChild(scanningRow);

        var existingUrls = [];
        tbody.querySelectorAll('.skyhshoso-wp-row').forEach(function(row) {
            existingUrls.push(row.getAttribute('data-url'));
        });

        var promises = hostingIds.map(function(hId) {
            var fd = new FormData();
            fd.append('action', 'skyhshoso_get_scan_targets');
            fd.append('hosting_id', hId);
            fd.append('nonce', config.dashboardNonce);

            return fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
            .then(r => r.json())
            .then(d => {
                if (d.success && d.data.targets) {
                    var targetPromises = d.data.targets.map(function(target) {
                        var cdF = new FormData();
                        cdF.append('action', 'skyhshoso_check_wp_target');
                        cdF.append('hosting_id', hId);
                        cdF.append('username', d.data.username);
                        cdF.append('doc_root', target.doc_root);
                        cdF.append('url', target.url);
                        cdF.append('nonce', config.dashboardNonce);

                        return fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(cdF) })
                        .then(r => r.json())
                        .then(res => {
                            if (res.success && res.data.is_wp) {
                                var cleanUrl = target.url.replace(/^https?:\/\//,'');
                                if (!existingUrls.includes(cleanUrl)) {
                                    existingUrls.push(cleanUrl);
                                    var tempDiv = document.createElement('tbody');
                                    tempDiv.innerHTML = res.data.row_html;
                                    tbody.insertBefore(tempDiv.firstChild, scanningRow);
                                }
                            }
                        }).catch(() => {});
                    });
                    return Promise.all(targetPromises);
                }
            }).catch(() => {});
        });

        Promise.all(promises).then(function() {
            scanningRow.remove();
            if (existingUrls.length === 0) {
                 tbody.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:30px;color:#6b7280;">No WordPress installations found on this server.</td></tr>';
            }
        });
    };

    function initRedirect() {
        var redirectEl = document.getElementById('skyhshoso-redirect-url');
        if (redirectEl && redirectEl.dataset.url) window.location.href = redirectEl.dataset.url;
    }

    function initCPanelLogin() {
        document.addEventListener('click', function(e) {
            
            // 1. WP Toolkit / Main cPanel Button
            var cpanelBtn = e.target.closest('#skyhshoso-cpanel-login-btn, .skyhshoso-cpanel-login-btn, .hm-cpanel-login-btn');
            if (cpanelBtn) {
                e.preventDefault();
                
                // 1. GRAB DATA FIRST
                var hostingId = cpanelBtn.getAttribute('data-hosting-id');
                var btnNonce = cpanelBtn.getAttribute('data-nonce') || config.dashboardNonce;

                if (!hostingId) {
                    window.skyhshosoToast('Error: Hosting ID is missing from this button.', 'error');
                    return;
                }
                
                // 2. SAVE ORIGINAL HTML TO RESTORE ICONS LATER
                var origHTML = cpanelBtn.innerHTML; 
                var txtSpan = cpanelBtn.querySelector('.skyhshoso-button-text');
                var spinnerHtml = '<span style="display:inline-block;width:12px;height:12px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite;margin-right:6px;vertical-align:middle;"></span>';
                
                if (txtSpan) {
                    txtSpan.innerHTML = spinnerHtml + 'Connecting...';
                } else {
                    cpanelBtn.innerHTML = spinnerHtml + 'Connecting...';
                }
                
                cpanelBtn.disabled = true;

                // 3. FIRE AJAX
                var fd = new FormData();
                fd.append('action', 'skyhshoso_generate_cpanel_login_url');
                fd.append('hosting_id', hostingId);
                fd.append('nonce', btnNonce);

                fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
                .then(r => r.json())
                .then(d => {
                    if (d.success && d.data && d.data.login_url) {
                        window.open(d.data.login_url, '_blank');
                    } else {
                        window.skyhshosoToast('Failed to connect: ' + (d.data ? d.data.message : 'Unauthorized'), 'error');
                    }
                    cpanelBtn.innerHTML = origHTML; 
                    cpanelBtn.disabled = false;
                }).catch(() => {
                    window.skyhshosoToast('A network connection error occurred.', 'error');
                    cpanelBtn.innerHTML = origHTML; 
                    cpanelBtn.disabled = false;
                });
            }

            // 2. Direct SSO Button (WP Sites Tab)
            var directSsoBtn = e.target.closest('.skyhshoso-wp-direct-sso-btn');
            if (directSsoBtn) {
                e.preventDefault();
                
                // 1. GRAB DATA FIRST
                var hId = directSsoBtn.getAttribute('data-hosting-id');
                var sUrl = directSsoBtn.getAttribute('data-site-url');
                var n = directSsoBtn.getAttribute('data-nonce') || config.dashboardNonce;
                
                // 2. DOM MANIPULATION
                var origDirectHTML = directSsoBtn.innerHTML;
                directSsoBtn.innerHTML = '<span style="display:inline-block;width:12px;height:12px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite;margin-right:6px;vertical-align:middle;"></span>';
                directSsoBtn.disabled = true;

                var fd = new FormData();
                fd.append('action', 'skyhshoso_generate_wp_sso');
                fd.append('hosting_id', hId);
                fd.append('site_url', sUrl);
                fd.append('nonce', n);

                fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
                .then(r => r.json())
                .then(d => {
                    directSsoBtn.disabled = false;
                    directSsoBtn.innerHTML = origDirectHTML;
                    if (d.success && d.data && d.data.url) {
                        window.open(d.data.url, '_blank');
                    } else {
                        window.skyhshosoToast('SSO Failed: ' + (d.data ? d.data.message : 'Unknown error'), 'error');
                    }
                }).catch(err => {
                    directSsoBtn.disabled = false;
                    directSsoBtn.innerHTML = origDirectHTML;
                    window.skyhshosoToast('A network connection error occurred.', 'error');
                });
            }

            // 3. Dropdown SSO Button (Hosting Tab) - THE RESTORED BUTTON!
            var detailWpLoginBtn = e.target.closest('.skyhshoso-wp-login-btn');
            if (detailWpLoginBtn) {
                e.preventDefault();
                
                // 1. GRAB DATA FIRST
                var hwId = detailWpLoginBtn.getAttribute('data-hosting-id');
                var selector = document.getElementById('skyhshoso-wp-selector-' + hwId);
                var swUrl = selector ? selector.value : '';
                
                if (!swUrl) {
                    window.skyhshosoToast('Please select a WP site from the dropdown first.', 'error');
                    return;
                }

                var n = detailWpLoginBtn.getAttribute('data-nonce') || config.dashboardNonce;

                // 2. DOM MANIPULATION
                var origLoginHTML = detailWpLoginBtn.innerHTML;
                detailWpLoginBtn.innerHTML = '<span style="display:inline-block;width:12px;height:12px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite;margin-right:6px;vertical-align:middle;"></span>';
                detailWpLoginBtn.disabled = true;

                var fdl = new FormData();
                fdl.append('action', 'skyhshoso_generate_wp_sso');
                fdl.append('hosting_id', hwId);
                fdl.append('site_url', swUrl);
                fdl.append('nonce', n);

                fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fdl) })
                .then(r => r.json())
                .then(d => {
                    detailWpLoginBtn.disabled = false;
                    detailWpLoginBtn.innerHTML = origLoginHTML;
                    if (d.success && d.data && d.data.url) {
                        window.open(d.data.url, '_blank');
                    } else {
                        window.skyhshosoToast('SSO Failed: ' + (d.data ? d.data.message : 'Unknown error'), 'error');
                    }
                })
                .catch(err => {
                    detailWpLoginBtn.disabled = false;
                    detailWpLoginBtn.innerHTML = origLoginHTML;
                    window.skyhshosoToast('A network connection error occurred.', 'error');
                });
            }

            // 4. Change Domain Button (PHASE 1 Live Updates)
            var changeDomainBtn = e.target.closest('.skyhshoso-wp-change-domain-btn');
            if (changeDomainBtn) {
                e.preventDefault();
                
                var cd_hId = changeDomainBtn.getAttribute('data-hosting-id');
                var cd_oUrl = changeDomainBtn.getAttribute('data-old-url');
                var cd_dRoot = changeDomainBtn.getAttribute('data-docroot');

                if (!cd_oUrl) {
                    var dropdown = document.getElementById('skyhshoso-wp-selector-' + cd_hId);
                    if (dropdown && dropdown.options[dropdown.selectedIndex] && dropdown.value) {
                        cd_oUrl = dropdown.value;
                        cd_dRoot = dropdown.options[dropdown.selectedIndex].getAttribute('data-docroot') || '';
                    }
                }

                if (!cd_oUrl) {
                    window.skyhshosoToast('Please wait for the scanner to finish or select a site.', 'error');
                    return;
                }
                
                var newDomain = prompt("Enter the new domain name (e.g., mynewsite.com):\n\nEnsure your DNS A-Record points to this server's IP address.");
                if (!newDomain) return;

                var origText = changeDomainBtn.innerHTML;
                changeDomainBtn.innerHTML = '<span style="display:inline-block;width:12px;height:12px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite;margin-right:6px;vertical-align:middle;"></span> Migrating...';
                changeDomainBtn.disabled = true;

                var cd_n = changeDomainBtn.getAttribute('data-nonce') || config.dashboardNonce;

                var fdCd = new FormData();
                fdCd.append('action', 'skyhshoso_assign_custom_domain');
                fdCd.append('hosting_id', cd_hId);
                fdCd.append('new_domain', newDomain);
                fdCd.append('old_url', cd_oUrl);
                fdCd.append('doc_root', cd_dRoot);
                fdCd.append('nonce', cd_n);

                fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fdCd) })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        window.skyhshosoToast(d.data ? d.data.message : 'Domain updated successfully!', 'success');
                        
                        var cleanNewDomain = newDomain.replace(/^https?:\/\//,'').replace(/^www\./,'');
                        var row = changeDomainBtn.closest('tr.skyhshoso-wp-row');
                        
                        if (row) {
                            var link = row.querySelector('a');
                            if (link) { link.href = 'https://' + cleanNewDomain; link.textContent = cleanNewDomain; }
                            row.setAttribute('data-url', cleanNewDomain);
                            changeDomainBtn.setAttribute('data-old-url', 'https://' + cleanNewDomain);
                            
                            var ssoBtn = row.querySelector('.skyhshoso-wp-direct-sso-btn');
                            if (ssoBtn) ssoBtn.setAttribute('data-site-url', 'https://' + cleanNewDomain);

                            row.classList.add('skyhs-row-updated');
                            setTimeout(() => row.classList.remove('skyhs-row-updated'), 2000);
                        } else {
                            var selectMenu = document.getElementById('skyhshoso-wp-selector-' + cd_hId);
                            if (selectMenu) {
                                var opt = selectMenu.options[selectMenu.selectedIndex];
                                opt.value = 'https://' + cleanNewDomain;
                                opt.textContent = cleanNewDomain;
                                selectMenu.classList.add('skyhs-row-updated');
                                setTimeout(() => selectMenu.classList.remove('skyhs-row-updated'), 2000);
                            }
                        }

                        changeDomainBtn.innerHTML = origText;
                        changeDomainBtn.disabled = false;
                    } else {
                        window.skyhshosoToast(d.data ? d.data.message : 'Failed to update domain.', 'error');
                        changeDomainBtn.innerHTML = origText;
                        changeDomainBtn.disabled = false;
                    }
                })
                .catch(err => {
                    window.skyhshosoToast('A network error occurred during migration.', 'error');
                    changeDomainBtn.innerHTML = origText;
                    changeDomainBtn.disabled = false;
                });
            }
        });
    }

    // --- WP SITE ASYNC SCANNER FOR DROPDOWN ---
    function initWpSiteScanner() {
        var wpSelectors = document.querySelectorAll('.skyhshoso-wp-site-selector');
        wpSelectors.forEach(function(selector) {
            var hostingId = selector.getAttribute('data-hosting-id');
            var nonce = selector.getAttribute('data-nonce') || config.dashboardNonce;
            
            selector.innerHTML = '<option value="" disabled>Locating installations...</option>';
            
            var fd = new FormData();
            fd.append('action', 'skyhshoso_scan_wp_sites');
            fd.append('hosting_id', hostingId);
            fd.append('nonce', nonce);

            fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    selector.innerHTML = ''; 
                    var existingUrls = [];
                    
                    if(res.success && res.data.local_sites && res.data.local_sites.length > 0) {
                        res.data.local_sites.forEach(function(site) {
                            var cleanUrl = site.url.replace(/^https?:\/\//,'');
                            existingUrls.push(cleanUrl);
                            var option = document.createElement('option');
                            option.value = site.url; 
                            option.setAttribute('data-docroot', site.doc_root || site.path);
                            option.setAttribute('data-insid', site.insid || '');
                            option.textContent = cleanUrl;
                            selector.appendChild(option);
                        });
                    }

                    var scanOpt = document.createElement('option');
                    scanOpt.value = "";
                    scanOpt.disabled = true;
                    scanOpt.textContent = "Scanning directories...";
                    selector.appendChild(scanOpt);

                    var tFd = new FormData();
                    tFd.append('action', 'skyhshoso_get_scan_targets');
                    tFd.append('hosting_id', hostingId);
                    tFd.append('nonce', nonce);

                    fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(tFd) })
                    .then(r => r.json())
                    .then(d => {
                        if (d.success && d.data.targets) {
                            var targetPromises = d.data.targets.map(function(target) {
                                var cFd = new FormData();
                                cFd.append('action', 'skyhshoso_check_wp_target');
                                cFd.append('hosting_id', hostingId);
                                cFd.append('username', d.data.username);
                                cFd.append('doc_root', target.doc_root);
                                cFd.append('url', target.url);
                                cFd.append('nonce', nonce);

                                return fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(cFd) })
                                .then(r => r.json())
                                .then(checkRes => {
                                    if (checkRes.success && checkRes.data.is_wp) {
                                        var cleanUrl = target.url.replace(/^https?:\/\//,'');
                                        if (!existingUrls.includes(cleanUrl)) {
                                            existingUrls.push(cleanUrl);
                                            var tempSel = document.createElement('select');
                                            tempSel.innerHTML = checkRes.data.option_html;
                                            
                                            // Strip https for display
                                            var newOpt = tempSel.firstChild;
                                            newOpt.textContent = cleanUrl;
                                            
                                            selector.insertBefore(newOpt, scanOpt);
                                        }
                                    }
                                }).catch(()=>{});
                            });
                            
                            Promise.all(targetPromises).then(function() {
                                scanOpt.remove();
                                if (existingUrls.length === 0) {
                                    selector.innerHTML = '<option value="">No WP Installations Found</option>';
                                }
                                var loginBtn = document.querySelector('.skyhshoso-wp-login-btn[data-hosting-id="'+hostingId+'"]');
                                if (loginBtn) loginBtn.disabled = false;
                                var domainBtn = document.querySelector('.skyhshoso-wp-change-domain-btn[data-hosting-id="'+hostingId+'"]');
                                if (domainBtn) domainBtn.disabled = false;
                            });
                        } else {
                            scanOpt.remove();
                        }
                    }).catch(()=>{ scanOpt.remove(); });
                });
        });
    }

    function initWpSiteProvision() {
        var provisionBtn = document.getElementById('skyhshoso-wp-provision-btn');
        if (!provisionBtn) return;

        var wpProvisionNonce = typeof skyhshosoDashboard !== 'undefined' && skyhshosoDashboard.wpProvisionNonce ? skyhshosoDashboard.wpProvisionNonce : '';
        var resultEl = document.getElementById('skyhshoso-wp-provision-result');
        var formView = document.getElementById('skyhshoso-wp-provision-form');
        var loadingView = document.getElementById('skyhshoso-wp-loading');
        var loadIcon = document.getElementById('skyhshoso-wp-load-icon');
        var loadRing = document.getElementById('skyhshoso-wp-load-ring');
        var loadCheck = document.getElementById('skyhshoso-wp-load-check');
        var loadTitle = document.getElementById('skyhshoso-wp-load-title');
        var loadMsg = document.getElementById('skyhshoso-wp-load-msg');

        var steps = [
            { svg: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" /></svg>', msg: 'Prepping Application Container' },
            { svg: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.58 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.58 4 8 4s8-1.79 8-4M4 7c0-2.21 3.58-4 8-4s8 1.79 8 4m0 5c0 2.21-3.58 4-8 4s-8-1.79-8-4" /></svg>', msg: 'Isolating Network Routing' },
            { svg: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>', msg: 'Sweeping Default Files' },
            { svg: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>', msg: 'Finalizing secure setup...' }
        ];

        provisionBtn.addEventListener('click', function() {
            var wpSiteId = this.getAttribute('data-id');
            var prefixInput = document.getElementById('skyhshoso-wp-domain-prefix');
            var baseSelect = document.getElementById('skyhshoso-wp-domain-base');
            var fallbackInput = document.getElementById('skyhshoso-wp-domain-input'); 
            
            var domain = '';
            if (prefixInput && baseSelect) {
                var rawPrefix = prefixInput.value.trim().replace(/[^a-zA-Z0-9-]/g, ''); 
                if (!rawPrefix) rawPrefix = 'wp' + Math.floor(Math.random() * 900 + 100);
                domain = rawPrefix + '.' + baseSelect.value.trim();
            } else if (fallbackInput) {
                domain = fallbackInput.value.trim();
            }

            if (!domain) {
                if (resultEl) resultEl.innerHTML = '<p style="color:#d63638;font-size:13px;">Please enter a domain.</p>';
                return;
            }
			// Capture the chosen Installer Engine
            var engineSelect = document.getElementById('skyhshoso-wp-installer-engine');
            var installerEngine = engineSelect ? engineSelect.value : '';

            // Capture the chosen Plugin Set ID
            var setSelect = document.getElementById('skyhshoso-wp-plugin-set');
            var pluginSetId = setSelect ? setSelect.value : '0';
			
            if (formView) formView.style.display = 'none';
            if (loadingView) loadingView.style.display = 'flex';

            var stepIndex = 0;
            var stepInterval;

            function transitionStep(step) {
                var iconEl = loadIcon;
                var msgEl = loadMsg;
                if (!iconEl || !msgEl) return;
                iconEl.classList.add('skyhshoso-wp-fading');
                msgEl.style.opacity = '0';
                setTimeout(function() {
                    iconEl.innerHTML = step.svg;
                    msgEl.textContent = step.msg;
                    iconEl.classList.remove('skyhshoso-wp-fading');
                    msgEl.style.opacity = '1';
                }, 250);
            }

            stepInterval = setInterval(function() {
                if (stepIndex < steps.length - 1) {
                    stepIndex++;
                    transitionStep(steps[stepIndex]);
                } else {
                    clearInterval(stepInterval);
                }
            }, 2800);

            function showSuccess(serverMessage) {
                clearInterval(stepInterval);
                if (loadIcon) {
                    loadIcon.classList.add('skyhshoso-wp-fading');
                    setTimeout(function() {
                        loadIcon.style.display = 'none';
                        if (loadRing) loadRing.style.borderColor = 'rgba(16,185,129,0.4)';
                        if (loadCheck) {
                            loadCheck.style.display = 'flex';
                            loadCheck.classList.remove('skyhshoso-wp-fading');
                        }
                        if (loadTitle) loadTitle.textContent = 'Container Ready!';
                        if (loadMsg) {
                            loadMsg.innerHTML = serverMessage || 'Your domain environment is ready.';
                            loadMsg.style.opacity = '1';
                            var btnWrap = document.createElement('div');
                            btnWrap.style.marginTop = '24px';
                            btnWrap.innerHTML = '<button onclick="window.location.reload()" style="background:#166534; color:#fff; border:none; padding:10px 20px; font-size:14px; border-radius:6px; cursor:pointer; font-weight:600; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">Got it, refresh dashboard</button>';
                            loadMsg.appendChild(btnWrap);
                        }
                    }, 250);
                }
            }

            function showError(msg) {
                clearInterval(stepInterval);
                if (loadIcon) {
                    loadIcon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:26px;height:26px;color:#d63638;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>';
                    loadIcon.classList.remove('skyhshoso-wp-fading');
                }
                if (loadTitle) loadTitle.textContent = 'Configuration failed';
                if (loadMsg) {
                    loadMsg.innerHTML = msg || 'An error occurred. Please try again.';
                    loadMsg.style.opacity = '1';
                    loadMsg.style.color = '#d63638';
                }
                var btnWrap = document.createElement('div');
                btnWrap.style.marginTop = '24px';
                btnWrap.innerHTML = '<button onclick="window.location.reload()" style="background:#d63638; color:#fff; border:none; padding:10px 20px; font-size:14px; border-radius:6px; cursor:pointer; font-weight:600;">Go Back</button>';
                loadMsg.appendChild(btnWrap);
            }

            var params = 'action=skyhshoso_wp_provision&wp_site_id=' + encodeURIComponent(wpSiteId) + '&domain=' + encodeURIComponent(domain) + '&installer_engine=' + encodeURIComponent(installerEngine) + '&plugin_set=' + encodeURIComponent(pluginSetId) + '&nonce=' + encodeURIComponent(wpProvisionNonce);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', config.ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        showSuccess(resp.data.message);
                    } else {
                        showError(resp.data.message || 'Installation failed.');
                    }
                } catch(e) {
                    showError('Unexpected error. Please try again.');
                }
            };
            xhr.onerror = function() {
                showError('Network error. Please try again.');
            };
            xhr.send(params);
        });
    }

    function setupPagination(cfg) {
        var container = document.getElementById(cfg.paginationContainerId);
        var tbody = cfg.tbodyId ? document.getElementById(cfg.tbodyId) : null;
        var input = document.getElementById(cfg.searchInputId);
        
        if (!container || !tbody) return;

        var totalPages = parseInt(container.getAttribute('data-total-pages')) || 1;
        var currentPage = parseInt(container.getAttribute('data-current-page')) || 1;
        var searchTerm = '';

        function fetchPage(page, search) {
            var fd = new FormData();
            fd.append('action', cfg.ajaxAction);
            fd.append('paged', page);
            fd.append('search', search);
            fd.append('nonce', config.dashboardNonce);

            fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
            .then(r => r.json())
            .then(d => {
                if (d.success) {
                    tbody.innerHTML = d.data.html;
                    currentPage = d.data.current_page;
                    totalPages = d.data.total_pages;
                    
                    if (d.data.hosting_queue && typeof window.skyhshosoProcessAsyncFleetScan === 'function') {
                        window.skyhshosoProcessAsyncFleetScan(d.data.hosting_queue);
                    }
                }
            });
        }
        
        if (cfg.autoFetch) fetchPage(1, '');
    }
	
	// --- TWO-WAY SERVER MANAGEMENT ---
    function initAccountManagement() {
        document.addEventListener('click', function(e) {

            // 1. Sync Account
            var syncBtn = e.target.closest('.skyhs-sync-btn');
            if (syncBtn) {
                e.preventDefault();
                var origHtml = syncBtn.innerHTML;
                syncBtn.innerHTML = '<span style="display:inline-block;width:12px;height:12px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite;"></span>';
                syncBtn.disabled = true;

                var fd = new FormData();
                fd.append('action', 'skyhshoso_sync_account');
                fd.append('hosting_id', syncBtn.getAttribute('data-hosting-id'));
                fd.append('nonce', config.dashboardNonce);

                fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
                .then(r => r.json()).then(d => {
                    syncBtn.disabled = false;
                    syncBtn.innerHTML = origHtml;
                    if (d.success) {
                        window.skyhshosoToast(d.data.message, 'success');
                        setTimeout(() => window.location.reload(), 1500); // Reload to show updated stats
                    } else {
                        window.skyhshosoToast(d.data.message, 'error');
                    }
                });
            }

            // 2. Toggle Suspend/Unsuspend
            var toggleBtn = e.target.closest('.skyhs-toggle-btn');
            if (toggleBtn) {
                e.preventDefault();
                var action = toggleBtn.getAttribute('data-action');
                var confirmMsg = action === 'suspend' 
                    ? 'Are you sure you want to suspend this account? All websites on this account will go offline.' 
                    : 'Are you sure you want to reactivate this account?';
                
                if (!confirm(confirmMsg)) return;

                var origHtml = toggleBtn.innerHTML;
                toggleBtn.innerHTML = 'Processing...';
                toggleBtn.disabled = true;

                var fd = new FormData();
                fd.append('action', 'skyhshoso_toggle_suspend');
                fd.append('hosting_id', toggleBtn.getAttribute('data-hosting-id'));
                fd.append('status_action', action);
                fd.append('nonce', config.dashboardNonce);

                fetch(config.ajaxUrl, { method: 'POST', body: newSearchParams(fd) })
                .then(r => r.json()).then(d => {
                    if (d.success) {
                        window.skyhshosoToast(d.data.message, 'success');
                        setTimeout(() => window.location.reload(), 1500);
                    } else {
                        toggleBtn.disabled = false;
                        toggleBtn.innerHTML = origHtml;
                        window.skyhshosoToast(d.data.message, 'error');
                    }
                });
            }

            // 3. Terminate Account
            var terminateBtn = e.target.closest('.skyhs-terminate-btn');
            if (terminateBtn) {
                e.preventDefault();
                var text1 = "DANGER: Are you sure you want to permanently delete this account?";
                var text2 = "This will permanently destroy all files, databases, and emails on the server. Your subscription will be cancelled immediately. This cannot be undone.\n\nType 'DELETE' to confirm:";
                
                if (!confirm(text1)) return;
                var verify = prompt(text2);
                if (verify !== 'DELETE') {
                    window.skyhshosoToast('Termination cancelled.', 'success');
                    return;
                }

                var origHtml = terminateBtn.innerHTML;
                terminateBtn.innerHTML = 'Terminating...';
                terminateBtn.disabled = true;

                var fd = new FormData();
                fd.append('action', 'skyhshoso_terminate_account');
                fd.append('hosting_id', terminateBtn.getAttribute('data-hosting-id'));
                fd.append('nonce', config.dashboardNonce);

                fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
                .then(r => r.json()).then(d => {
                    if (d.success) {
                        window.skyhshosoToast(d.data.message, 'success');
                        var row = terminateBtn.closest('tr');
                        if (row) {
                            row.style.opacity = '0.3';
                            row.style.pointerEvents = 'none';
                        }
                        setTimeout(() => window.location.reload(), 2000);
                    } else {
                        terminateBtn.disabled = false;
                        terminateBtn.innerHTML = origHtml;
                        window.skyhshosoToast(d.data.message, 'error');
                    }
                });
            }
        });
    }

    function init() {
        injectModernStyles(); // Inject CSS for Toasts & Skeletons
        initRedirect();
        initCPanelLogin();
        initWpSiteProvision();
        initWpSiteScanner(); 
		initAccountManagement();

        if (document.getElementById('skyhshoso-wp-site-pagination')) {
            setupPagination({
                paginationContainerId: 'skyhshoso-wp-site-pagination',
                tbodyId: 'skyhshoso-wp-site-tbody',
                ajaxAction: 'skyhshoso_get_wp_site_page',
                autoFetch: true
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

jQuery(document).ready(function($) {
    if (typeof skyhshosoDashboard === 'undefined') return;

    var ajaxurl = skyhshosoDashboard.ajaxUrl;
    var nonce = skyhshosoDashboard.nonce;

    // --- 1. Password Reset Modal ---
    $('#skyhshoso-trigger-pass-reset').on('click', function(e) {
        e.preventDefault();
        $('#skyhs-new-pass').val('');
        $('#skyhshoso-pass-modal').css('display', 'flex').hide().fadeIn(200);
    });

    $('#skyhshoso-cancel-pass').on('click', function(e) {
        e.preventDefault();
        $('#skyhshoso-pass-modal').fadeOut(200);
    });

    $('#skyhshoso-save-pass').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var hostingId = btn.data('hosting-id');
        var newPass = $('#skyhs-new-pass').val();

        if (!newPass || newPass.length < 8) {
            alert('Password must be at least 8 characters long.');
            return;
        }

        var originalText = btn.text();
        btn.text('Saving...').prop('disabled', true);

        $.post(ajaxurl, {
            action: 'skyhshoso_reset_cpanel_pass',
            nonce: nonce,
            hosting_id: hostingId,
            new_pass: newPass
        }, function(res) {
            btn.text(originalText).prop('disabled', false);
            if (res.success) {
                alert('Password updated successfully!');
                $('#skyhshoso-pass-modal').fadeOut(200);
            } else {
                alert('Error: ' + res.data.message);
            }
        }).fail(function() {
            btn.text(originalText).prop('disabled', false);
            alert('Server connection error.');
        });
    });

    // --- 2. SSH Access Toggle ---
    $('#skyhshoso-ssh-toggle').on('change', function() {
        var isChecked = $(this).is(':checked');
        var hostingId = $(this).data('hosting-id');
        var switchEl = $(this);

        switchEl.prop('disabled', true);

        $.post(ajaxurl, {
            action: 'skyhshoso_toggle_ssh',
            nonce: nonce,
            hosting_id: hostingId,
            enable_ssh: isChecked
        }, function(res) {
            switchEl.prop('disabled', false);
            if (!res.success) {
                alert('Error: ' + res.data.message);
                switchEl.prop('checked', !isChecked); 
            }
        }).fail(function() {
            switchEl.prop('disabled', false);
            switchEl.prop('checked', !isChecked);
            alert('Server connection error.');
        });
    });

    // --- 3. Live Stats Fetcher ---
    function fetchCpanelStats() {
        var statsGrid = $('.skyhshoso-stats-grid');
        var diskContainer = $('#skyhshoso-disk-usage-container');

        if (statsGrid.length > 0 || diskContainer.length > 0) {
            var hostingId = statsGrid.length > 0 ? statsGrid.data('hosting-id') : diskContainer.data('hosting-id');
            var fetchNonce = (typeof nonce !== 'undefined') ? nonce : (typeof skyhshosoDashboard !== 'undefined' ? skyhshosoDashboard.nonce : '');

            $.post(ajaxurl, {
                action: 'skyhshoso_get_cpanel_stats',
                nonce: fetchNonce,
                hosting_id: hostingId
            }, function(res) {
                $('.skyhshoso-cpanel-refresh-btn span').text('↻ Refresh');
                
                if (res.success) {
                    var s = res.data.stats;
                    
                    if ($('#skyhshoso-ssh-toggle').length) {
                        $('#skyhshoso-ssh-toggle').prop('checked', res.data.ssh_active);
                    }

                    if (s.diskusage && $('#skyhs-disk-bar').length) {
                        var percent = parseFloat(s.diskusage.percent);
                        var val = s.diskusage.value;
                        var max = s.diskusage.max;
                        
                        $('#skyhs-disk-bar').css('width', percent + '%');
                        if(percent > 80) $('#skyhs-disk-bar').css('background', '#f59e0b');
                        if(percent > 95) $('#skyhs-disk-bar').css('background', '#ef4444');
                        
                        var maxText = (max === 'unlimited' || max === '0' || !max) ? 'Unlimited' : max;
                        $('#skyhs-disk-text').text(val + ' used of ' + maxText);
                    }

                    if (statsGrid.length > 0) {
                        var gridHtml = '';
                        var displayMap = [
                            { id: 'diskusage', icon: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>', title: 'Disk Usage' },
                            { id: 'sqldiskusage', icon: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>', title: 'DB Disk' },
                            { id: 'mysqldbs', icon: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>', title: 'Databases' },
                            { id: 'subdomains', icon: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>', title: 'Subdomains' },
                            { id: 'addondomains', icon: '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>', title: 'Addons' }
                        ];

                        displayMap.forEach(function(metric) {
                            var val = '0 / 0';
                            if(s[metric.id]) {
                                var usage = s[metric.id].value || '0';
                                var max = s[metric.id].max || '0';
                                var maxText = (max === 'unlimited' || max === '0') ? '&infin;' : max;
                                val = usage + ' / ' + maxText;
                            }
                            
                            gridHtml += '<div class="skyhshoso-stat-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:16px; display:flex; align-items:center; gap:16px;">';
                            gridHtml += '<div style="background:#f0f6fc; color:#2563eb; width:48px; height:48px; border-radius:8px; display:flex; align-items:center; justify-content:center;">' + metric.icon + '</div>';
                            gridHtml += '<div><span style="display:block; font-size:18px; font-weight:700; color:#0f172a;">' + val + '</span><span style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase;">' + metric.title + '</span></div>';
                            gridHtml += '</div>';
                        });

                        statsGrid.css({
                            'display': 'grid',
                            'grid-template-columns': 'repeat(auto-fit, minmax(200px, 1fr))',
                            'gap': '16px'
                        });

                        statsGrid.html(gridHtml);
                        $('.skyhshoso-cpanel-status').text('Data synced securely from cPanel.').css('color', '#10b981');
                    }
                } else {
                    if (statsGrid.length > 0) {
                        statsGrid.html('<p style="color:#d63638; grid-column: 1 / -1;">Failed to load statistics: ' + res.data.message + '</p>');
                        $('.skyhshoso-cpanel-status').text('Sync failed.').css('color', '#d63638');
                    }
                }
            });
        }
    }

    // Run stats fetcher on page load
    fetchCpanelStats();

    // Wire up the manual refresh button to the fetcher
    $('.skyhshoso-cpanel-refresh-btn').off('click').on('click', function(e) {
        e.preventDefault();
        $(this).find('span').text('↻ Syncing...');
        fetchCpanelStats();
    });

    // --- 4. Secure Sync Server Status ---
    $('.skyhs-secure-sync-btn').off('click').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var originalHtml = btn.html();
        var fetchNonce = (typeof nonce !== 'undefined') ? nonce : (typeof skyhshosoDashboard !== 'undefined' ? skyhshosoDashboard.nonce : '');
        
        btn.html('Syncing...').prop('disabled', true).css('opacity', '0.6');
        
        $.post(ajaxurl, {
            action: 'skyhshoso_frontend_sync',
            nonce: fetchNonce,
            hosting_id: btn.data('hosting-id')
        }, function(res) {
            btn.html(originalHtml).prop('disabled', false).css('opacity', '1');
            alert(res.data ? res.data.message : 'Synced');
            if (res.success) location.reload();
        }).fail(function() {
            btn.html(originalHtml).prop('disabled', false).css('opacity', '1');
            alert('Server connection error.');
        });
    });

    // --- 5. Secure Suspend / Unsuspend Server ---
    $('.skyhs-secure-toggle-btn').off('click').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var act = btn.data('action');
        var fetchNonce = (typeof nonce !== 'undefined') ? nonce : (typeof skyhshosoDashboard !== 'undefined' ? skyhshosoDashboard.nonce : '');
        
        if(!confirm('Are you sure you want to ' + act + ' this server?')) return;
        
        var originalHtml = btn.html();
        btn.html('Working...').prop('disabled', true).css('opacity', '0.6');
        
        $.post(ajaxurl, {
            action: 'skyhshoso_frontend_toggle_status',
            nonce: fetchNonce,
            hosting_id: btn.data('hosting-id'),
            account_action: act
        }, function(res) {
            alert(res.data ? res.data.message : 'Updated');
            location.reload();
        }).fail(function() {
            btn.html(originalHtml).prop('disabled', false).css('opacity', '1');
            alert('Server connection error.');
        });
    });

    // --- 6. Secure Terminate Server (Delete) ---
    $('.skyhs-secure-terminate-btn').off('click').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        var fetchNonce = (typeof nonce !== 'undefined') ? nonce : (typeof skyhshosoDashboard !== 'undefined' ? skyhshosoDashboard.nonce : '');
        
        if(!confirm('DANGER: This will permanently delete this cPanel account, all files, and databases from the server! Continue?')) return;
        
        var originalHtml = btn.html();
        btn.html('Deleting...').prop('disabled', true).css('opacity', '0.6');
        
        $.post(ajaxurl, {
            action: 'skyhshoso_frontend_terminate',
            nonce: fetchNonce,
            hosting_id: btn.data('hosting-id')
        }, function(res) {
            if (res.success) {
                alert(res.data.message);
                window.location.href = window.location.href.split('&hosting_id')[0]; 
            } else {
                btn.html(originalHtml).prop('disabled', false).css('opacity', '1');
                alert('Deletion Failed: ' + res.data.message);
            }
        }).fail(function() {
            btn.html(originalHtml).prop('disabled', false).css('opacity', '1');
            alert('Server connection error.');
        });
    });
});

