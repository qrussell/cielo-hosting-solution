/**
 * SkyHS Dashboard JavaScript
 * Unified and deduplicated version.
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

    function initRedirect() {
        var redirectEl = document.getElementById('skyhshoso-redirect-url');
        if (redirectEl && redirectEl.dataset.url) {
            window.location.href = redirectEl.dataset.url;
        }
    }

    function initSearchInputs() {
        var searchInputs = document.querySelectorAll('.skyhshoso-search-input');
        searchInputs.forEach(function(input) {
            var clearBtn = input.parentElement.querySelector('.skyhshoso-search-clear');
            if (!clearBtn) return;

            input.setAttribute('data-value', input.value);
            input.addEventListener('input', function() {
                this.setAttribute('data-value', this.value);
            });

            clearBtn.addEventListener('click', function() {
                input.value = '';
                input.setAttribute('data-value', '');
                input.focus();
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    }

    function setupPagination(cfg) {
        var container = document.getElementById(cfg.paginationContainerId);
        var tbody = cfg.tbodyId ? document.getElementById(cfg.tbodyId) : null;
        var input = document.getElementById(cfg.searchInputId);
        var noResults = document.getElementById(cfg.noResultsId);
        var table = cfg.tableId ? document.getElementById(cfg.tableId) : null;

        if (!container || !tbody) return;

        var totalPages = parseInt(container.getAttribute('data-total-pages')) || 1;
        var currentPage = parseInt(container.getAttribute('data-current-page')) || 1;
        var searchTerm = '';
        var ajaxAction = cfg.ajaxAction;
        var debounceTimer;

        function fetchPage(page, search, callback) {
            var baseUrl = container.getAttribute('data-base-url') || '';
            var fd = new FormData();
            fd.append('action', ajaxAction);
            fd.append('paged', page);
            fd.append('search', search);
            fd.append('nonce', config.dashboardNonce);
            fd.append('base_url', baseUrl);

            fetch(config.ajaxUrl, {
                method: 'POST',
                body: new URLSearchParams(fd)
            })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    tbody.innerHTML = d.data.html;
                    currentPage = d.data.current_page;
                    totalPages = d.data.total_pages;
                    renderControls();
                    if (noResults) {
                        var hasRows = tbody.querySelectorAll('tr').length > 0;
                        noResults.style.display = hasRows ? 'none' : 'block';
                    }
                    if (table) {
                        table.style.display = tbody.querySelectorAll('tr').length > 0 ? 'table' : 'none';
                    }
                    if (callback) callback(d.data);
                }
            })
            .catch(function() {});
        }

        function renderControls() {
            container.innerHTML = '';
            if (totalPages <= 1) return;

            var prevBtn = document.createElement('button');
            prevBtn.className = 'skyhshoso-pagination-btn';
            prevBtn.innerHTML = '&laquo;';
            prevBtn.disabled = currentPage === 1;
            prevBtn.addEventListener('click', function() {
                if (currentPage > 1) fetchPage(currentPage - 1, searchTerm);
            });
            container.appendChild(prevBtn);

            for (var i = 1; i <= totalPages; i++) {
                (function(pageNum) {
                    var pageBtn = document.createElement('button');
                    pageBtn.className = 'skyhshoso-pagination-btn';
                    if (pageNum === currentPage) pageBtn.classList.add('active');
                    pageBtn.innerText = pageNum;
                    pageBtn.addEventListener('click', function() {
                        if (pageNum !== currentPage) fetchPage(pageNum, searchTerm);
                    });
                    container.appendChild(pageBtn);
                })(i);
            }

            var nextBtn = document.createElement('button');
            nextBtn.className = 'skyhshoso-pagination-btn';
            nextBtn.innerHTML = '&raquo;';
            nextBtn.disabled = currentPage === totalPages;
            nextBtn.addEventListener('click', function() {
                if (currentPage < totalPages) fetchPage(currentPage + 1, searchTerm);
            });
            container.appendChild(nextBtn);
        }

        if (input) {
            input.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(function() {
                    searchTerm = input.value.trim();
                    fetchPage(1, searchTerm);
                }, 300);
            });
        }
        
        renderControls();

        if (cfg.autoFetch) {
            fetchPage(1, '');
        }
    }

    function initPeriodToggle() {
        var toggles = document.querySelectorAll('.skyhshoso-material-toggle-input');
        var slider = document.querySelector('.skyhshoso-material-toggle-slider');
        var cards = document.querySelectorAll('.skyhshoso-period-card');

        function sync(inp) {
            if (!slider || !inp) return;
            var label = inp.nextElementSibling;
            slider.style.width = label.offsetWidth + 'px';
            slider.style.transform = 'translateX(' + inp.parentElement.offsetLeft + 'px)';
        }

        toggles.forEach(function(inp) {
            inp.addEventListener('change', function() {
                sync(this);
                var grp = this.getAttribute('data-period-group');
                cards.forEach(function(c) {
                    c.style.display = c.getAttribute('data-period-group') === grp ? 'block' : 'none';
                });
            });
        });

        var initChecked = document.querySelector('.skyhshoso-material-toggle-input:checked');
        if (initChecked) {
            sync(initChecked);
            var grp = initChecked.getAttribute('data-period-group');
            cards.forEach(function(c) {
                c.style.display = c.getAttribute('data-period-group') === grp ? 'block' : 'none';
            });
        }

        window.addEventListener('resize', function() {
            var active = document.querySelector('.skyhshoso-material-toggle-input:checked');
            if (active) sync(active);
        });
    }

    function initAddDomainForm() {
        var form = document.getElementById('skyhshoso-add-domain-form');
        var msgDiv = document.getElementById('skyhshoso-form-message');
        if (!form) return;

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = form.querySelector('button[type="submit"]');
            var btnTxt = btn.querySelector('.skyhshoso-button-text');
            var orig = btnTxt.textContent;

            if (msgDiv) {
                msgDiv.innerHTML = '';
                msgDiv.className = 'skyhshoso-message';
            }
            btnTxt.textContent = 'Adding...';
            btn.disabled = true;

            var fd = new FormData(form);
            fd.append('action', 'skyhshoso_add_domain');
            var nEl = document.getElementById('skyhshoso_domain_nonce');
            if (nEl) fd.append('nonce', nEl.value);

            fetch(config.ajaxUrl, {
                method: 'POST',
                body: new URLSearchParams(fd)
            })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) {
                    if (msgDiv) {
                        msgDiv.className = 'skyhshoso-message skyhshoso-message-success';
                        msgDiv.innerHTML = '<p>' + d.data.message + '</p>';
                    }
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    if (msgDiv) {
                        msgDiv.className = 'skyhshoso-message skyhshoso-message-error';
                        msgDiv.innerHTML = '<p>Error: ' + (d.data ? d.data.message : 'Unknown error') + '</p>';
                    }
                    btnTxt.textContent = orig;
                    btn.disabled = false;
                }
            })
            .catch(function() {
                if (msgDiv) {
                    msgDiv.className = 'skyhshoso-message skyhshoso-message-error';
                    msgDiv.innerHTML = '<p>An error occurred.</p>';
                }
                btnTxt.textContent = orig;
                btn.disabled = false;
            });
        });
    }

    // --- BULLETPROOF CPANEL & WP SSO LOGIN HANDLER (Event Delegation) ---
    function initCPanelLogin() {
        document.addEventListener('click', function(e) {
            
            // 1. Check if the user clicked a Standard cPanel Login Button
            var cpanelBtn = e.target.closest('#skyhshoso-cpanel-login-btn, .skyhshoso-cpanel-login-btn, .hm-cpanel-login-btn');
            if (cpanelBtn) {
                e.preventDefault();
                var txt = cpanelBtn.querySelector('.skyhshoso-button-text');
                var origText = txt ? txt.textContent : cpanelBtn.textContent;
                
                if (txt) {
                    txt.textContent = 'Connecting...';
                } else {
                    cpanelBtn.textContent = 'Connecting...';
                }
                cpanelBtn.disabled = true;

                var hostingId = cpanelBtn.getAttribute('data-hosting-id');
                var nonce = cpanelBtn.getAttribute('data-nonce') || config.dashboardNonce;

                var fd = new FormData();
                fd.append('action', 'skyhshoso_generate_cpanel_login_url');
                fd.append('hosting_id', hostingId);
                fd.append('nonce', nonce);

                fetch(config.ajaxUrl, {
                    method: 'POST',
                    body: new URLSearchParams(fd)
                })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success && d.data && d.data.login_url) {
                        window.open(d.data.login_url, '_blank');
                    } else {
                        alert('Failed to connect: ' + (d.data ? d.data.message : 'Unauthorized'));
                    }
                    if (txt) txt.textContent = origText; else cpanelBtn.textContent = origText;
                    cpanelBtn.disabled = false;
                })
                .catch(function() {
                    alert('A connection error occurred.');
                    if (txt) txt.textContent = origText; else cpanelBtn.textContent = origText;
                    cpanelBtn.disabled = false;
                });
            }

            // 2. Check if the user clicked a Softaculous WP SSO Login Button
            var wpSsoBtn = e.target.closest('.skyhshoso-wp-sso-btn');
            if (wpSsoBtn) {
                e.preventDefault();
                var origSsoText = wpSsoBtn.textContent;
                wpSsoBtn.textContent = 'Connecting...';
                wpSsoBtn.disabled = true;

                var wpHostingId = wpSsoBtn.getAttribute('data-hosting-id');
                var wpNonce = wpSsoBtn.getAttribute('data-nonce') || config.dashboardNonce;
                var insid = wpSsoBtn.getAttribute('data-insid') || '';

                var fdWp = new FormData();
                fdWp.append('action', 'skyhshoso_get_cpanel_section_url');
                fdWp.append('hosting_id', wpHostingId);
                fdWp.append('section', 'wordpress');
                fdWp.append('nonce', wpNonce);
                
                if (insid) {
                    fdWp.append('insid', insid);
                }

                fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fdWp) })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    wpSsoBtn.disabled = false;
                    wpSsoBtn.textContent = origSsoText;
                    if (d.success && d.data && d.data.url) {
                        window.open(d.data.url, '_blank');
                    } else {
                        alert('Could not auto-login. Try logging in to cPanel directly.');
                    }
                })
                .catch(function(err) {
                    wpSsoBtn.disabled = false;
                    wpSsoBtn.textContent = origSsoText;
                    alert('Connection error.');
                });
            }

            // 3. Check if the user clicked a Native WP Toolkit Direct SSO Login Button
            var directSsoBtn = e.target.closest('.skyhshoso-wp-direct-sso-btn');
            if (directSsoBtn) {
                e.preventDefault();
                var origDirectText = directSsoBtn.textContent;
                directSsoBtn.textContent = 'Connecting...';
                directSsoBtn.disabled = true;

                var hId = directSsoBtn.getAttribute('data-hosting-id');
                var sUrl = directSsoBtn.getAttribute('data-site-url');
                var n = directSsoBtn.getAttribute('data-nonce') || config.dashboardNonce;

                var fd = new FormData();
                fd.append('action', 'skyhshoso_generate_wp_sso');
                fd.append('hosting_id', hId);
                fd.append('site_url', sUrl);
                fd.append('nonce', n);

                fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
                .then(r => r.json())
                .then(d => {
                    directSsoBtn.disabled = false;
                    directSsoBtn.textContent = origDirectText;
                    if (d.success && d.data && d.data.url) {
                        window.open(d.data.url, '_blank');
                    } else {
                        alert('SSO Failed: ' + (d.data ? d.data.message : 'Unknown error'));
                    }
                })
                .catch(err => {
                    directSsoBtn.disabled = false;
                    directSsoBtn.textContent = origDirectText;
                    alert('Connection error.');
                });
            }

            // 4. Check if the user clicked the Specific WP Login inside Detail Panel
            var detailWpLoginBtn = e.target.closest('.skyhshoso-wp-login-btn');
            if (detailWpLoginBtn) {
                e.preventDefault();
                var hwId = detailWpLoginBtn.getAttribute('data-hosting-id');
                var selector = document.getElementById('skyhshoso-wp-selector-' + hwId);
                var swUrl = selector ? selector.value : '';
                
                if (!swUrl) return alert('Please select a WP site first.');

                var origLoginText = detailWpLoginBtn.textContent;
                detailWpLoginBtn.textContent = 'Connecting...';
                detailWpLoginBtn.disabled = true;

                var fdl = new FormData();
                fdl.append('action', 'skyhshoso_generate_wp_sso');
                fdl.append('hosting_id', hwId);
                fdl.append('site_url', swUrl);
                fdl.append('nonce', config.dashboardNonce);

                fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fdl) })
                .then(r => r.json())
                .then(d => {
                    detailWpLoginBtn.disabled = false;
                    detailWpLoginBtn.textContent = origLoginText;
                    if (d.success && d.data && d.data.url) {
                        window.open(d.data.url, '_blank');
                    } else {
                        alert('SSO Failed: ' + (d.data ? d.data.message : 'Unknown error'));
                    }
                })
                .catch(err => {
                    detailWpLoginBtn.disabled = false;
                    detailWpLoginBtn.textContent = origLoginText;
                    alert('Connection error.');
                });
            }

            // 5. Check if the user clicked the Change Domain Button (Handles both Fleet View and Dropdown UI)
            var changeDomainBtn = e.target.closest('.skyhshoso-wp-change-domain-btn');
            if (changeDomainBtn) {
                e.preventDefault();
                
                var cd_hId = changeDomainBtn.getAttribute('data-hosting-id');
                var cd_oUrl = changeDomainBtn.getAttribute('data-old-url');
                var cd_dRoot = changeDomainBtn.getAttribute('data-docroot');

                // Support for dynamically reading from dropdown when inside the detail panel
                if (!cd_oUrl) {
                    var dropdown = document.getElementById('skyhshoso-wp-selector-' + cd_hId);
                    if (dropdown && dropdown.options[dropdown.selectedIndex] && dropdown.value) {
                        cd_oUrl = dropdown.value;
                        cd_dRoot = dropdown.options[dropdown.selectedIndex].getAttribute('data-docroot') || '';
                    }
                }

                if (!cd_oUrl) return alert('Please select a WP site first.');
                
                var newDomain = prompt("Enter the new domain name (e.g., mynewsite.com):\n\nWARNING: Ensure your DNS A-Record points to this server's IP address before proceeding, otherwise the site will go offline.");
                if (!newDomain) return;

                var origText = changeDomainBtn.textContent;
                changeDomainBtn.textContent = 'Migrating...';
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
                        alert(d.data ? d.data.message : 'Domain updated successfully.');
                        location.reload(); 
                    } else {
                        alert('Error: ' + (d.data ? d.data.message : 'Failed to update domain.'));
                        changeDomainBtn.textContent = origText;
                        changeDomainBtn.disabled = false;
                    }
                })
                .catch(err => {
                    alert('An error occurred during domain assignment.');
                    changeDomainBtn.textContent = origText;
                    changeDomainBtn.disabled = false;
                });
            }
        });
    }

    // --- WP SITE SCANNER FOR HOSTING DETAIL DROPDOWN ---
    function initWpSiteScanner() {
        var wpSelectors = document.querySelectorAll('.skyhshoso-wp-site-selector');
        wpSelectors.forEach(function(selector) {
            var hostingId = selector.getAttribute('data-hosting-id');
            var nonce = selector.getAttribute('data-nonce') || config.dashboardNonce;
            
            var fd = new FormData();
            fd.append('action', 'skyhshoso_scan_wp_sites');
            fd.append('hosting_id', hostingId);
            fd.append('nonce', nonce);

            fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    selector.innerHTML = ''; 
                    if(res.success && res.data.sites && res.data.sites.length > 0) {
                        res.data.sites.forEach(function(site) {
                            var option = document.createElement('option');
                            option.value = site.url; 
                            option.setAttribute('data-docroot', site.doc_root || site.path);
                            option.setAttribute('data-insid', site.insid || '');
                            option.textContent = site.url;
                            selector.appendChild(option);
                        });
                        
                        // Optionally auto-enable adjacent buttons if desired
                        var loginBtn = document.querySelector('.skyhshoso-wp-login-btn[data-hosting-id="'+hostingId+'"]');
                        if (loginBtn) loginBtn.disabled = false;
                        var domainBtn = document.querySelector('.skyhshoso-wp-change-domain-btn[data-hosting-id="'+hostingId+'"]');
                        if (domainBtn) domainBtn.disabled = false;
                        
                    } else {
                        selector.innerHTML = '<option value="">No WP Installations Found</option>';
                    }
                })
                .catch(function() {
                    selector.innerHTML = '<option value="">Error scanning sites</option>';
                });
        });
    }

    function initCollaborators() {
        var openBtn = document.getElementById('skyhshoso-new-collaborator-btn');
        var cancelBtn = document.getElementById('skyhshoso-cancel-invite');
        var formCont = document.getElementById('skyhshoso-collaborator-form-container');
        var listCont = document.getElementById('skyhshoso-collaborator-lists');
        
        var invForm = document.getElementById('skyhshoso-invite-user-form');
        var msgDiv = document.getElementById('skyhshoso-invite-message');
        var mailInp = document.getElementById('invitee_email');

        if (mailInp) {
            var handleActive = function() {
                if (mailInp.value.trim() !== '') {
                    mailInp.parentElement.classList.add('has-value');
                } else {
                    mailInp.parentElement.classList.remove('has-value');
                }
            };
            mailInp.addEventListener('focus', function() { this.parentElement.classList.add('focused'); });
            mailInp.addEventListener('blur', function() { this.parentElement.classList.remove('focused'); handleActive(); });
            mailInp.addEventListener('input', handleActive);
            handleActive();
        }

        if (openBtn && formCont) {
            openBtn.addEventListener('click', function() {
                formCont.style.display = 'block';
                if (listCont) listCont.style.display = 'none';
            });
        }
        if (cancelBtn && formCont) {
            cancelBtn.addEventListener('click', function() {
                formCont.style.display = 'none';
                if (listCont) listCont.style.display = 'block';
            });
        }

        if (invForm) {
            invForm.addEventListener('submit', function(e) {
                e.preventDefault();
                var sBtn = invForm.querySelector('button[type="submit"]');
                var sTxt = sBtn.querySelector('.skyhshoso-button-text');
                var orig = sTxt.textContent;

                if (msgDiv) { msgDiv.style.display = 'none'; msgDiv.innerHTML = ''; }
                sTxt.textContent = 'Sending...';
                sBtn.disabled = true;

                var email = document.getElementById('invitee_email').value;
                
                var fd = new FormData();
                fd.append('action', 'skyhshoso_invite_user');
                fd.append('invitee_email', email);
                fd.append('nonce', config.collaboratorNonce);

                fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success) {
                        if (msgDiv) {
                            msgDiv.className = 'skyhshoso-message skyhshoso-message-success';
                            msgDiv.innerHTML = '<p>' + d.data.message + '</p>';
                            msgDiv.style.display = 'block';
                        }
                        invForm.reset();
                        refreshList();
                        setTimeout(function() {
                            formCont.style.display = 'none';
                            if (listCont) listCont.style.display = 'block';
                        }, 1500);
                    } else {
                        if (msgDiv) {
                            msgDiv.className = 'skyhshoso-message skyhshoso-message-error';
                            msgDiv.innerHTML = '<p>Error: ' + d.data.message + '</p>';
                            msgDiv.style.display = 'block';
                        }
                    }
                    sTxt.textContent = orig;
                    sBtn.disabled = false;
                })
                .catch(function() {
                    if (msgDiv) {
                        msgDiv.className = 'skyhshoso-message skyhshoso-message-error';
                        msgDiv.innerHTML = '<p>An error occurred.</p>';
                        msgDiv.style.display = 'block';
                    }
                    sTxt.textContent = orig;
                    sBtn.disabled = false;
                });
            });
        }

        document.addEventListener('click', function(e) {
            var lk = e.target.closest('.skyhshoso-remove-invite');
            if (!lk) return;
            e.preventDefault();
            
            if (!confirm('Are you sure you want to remove this collaborator?')) return;
            
            var uid = lk.getAttribute('data-user-id');
            var fd = new FormData();
            fd.append('action', 'skyhshoso_remove_invite');
            fd.append('user_id', uid);
            fd.append('nonce', config.collaboratorNonce);

            fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.success) refreshList();
                else alert('Error: ' + d.data.message);
            });
        });

        function refreshList() {
            if (!listCont) return;
            var fd = new FormData();
            fd.append('action', 'skyhshoso_get_collaborator_data');
            fd.append('nonce', config.collaboratorNonce);

            fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.success) return;
                listCont.innerHTML = '';
                var loaded = false;

                if (d.data.skyhshoso_invited_users && d.data.skyhshoso_invited_users.length > 0) {
                    loaded = true;
                    var sec = document.createElement('div');
                    sec.className = 'skyhshoso-collaborator-section';
                    sec.innerHTML = '<h3 class="skyhshoso-collaborator-title">Users You Invited</h3>' +
                                    '<div class="skyhshoso-table-wrapper skyhshoso-collaborator-table-wrapper">' +
                                    '<table class="skyhshoso-table skyhshoso-collaborator-table">' +
                                    '<thead><tr><th class="skyhshoso-column-name">Email</th><th class="skyhshoso-column-action">Action</th></tr></thead>' +
                                    '<tbody></tbody></table></div>';
                    
                    var tb = sec.querySelector('tbody');
                    d.data.skyhshoso_invited_users.forEach(function(u) {
                        var r = document.createElement('tr');
                        r.className = 'skyhshoso-collaborator-row';
                        r.innerHTML = '<td class="skyhshoso-column-name">' + escapeHtml(u.email) + '</td>' +
                                      '<td class="skyhshoso-column-action"><a href="#" class="skyhshoso-action-link skyhshoso-remove-invite" data-user-id="' + u.id + '">Remove</a></td>';
                        tb.appendChild(r);
                    });
                    listCont.appendChild(sec);
                }

                if (d.data.skyhshoso_invited_by && d.data.skyhshoso_invited_by.length > 0) {
                    loaded = true;
                    var sec2 = document.createElement('div');
                    sec2.className = 'skyhshoso-collaborator-section';
                    sec2.innerHTML = '<h3 class="skyhshoso-collaborator-title">Users Who Invited You</h3>' +
                                     '<div class="skyhshoso-table-wrapper skyhshoso-collaborator-table-wrapper">' +
                                     '<table class="skyhshoso-table skyhshoso-collaborator-table">' +
                                     '<thead><tr><th class="skyhshoso-column-name">Email</th><th class="skyhshoso-column-action">Action</th></tr></thead>' +
                                     '<tbody></tbody></table></div>';
                    
                    var tb2 = sec2.querySelector('tbody');
                    d.data.skyhshoso_invited_by.forEach(function(u) {
                        var r2 = document.createElement('tr');
                        r2.className = 'skyhshoso-collaborator-row';
                        r2.innerHTML = '<td class="skyhshoso-column-name">' + escapeHtml(u.email) + '</td>' +
                                       '<td class="skyhshoso-column-action"><a href="#" class="skyhshoso-action-link skyhshoso-remove-invite" data-user-id="' + u.id + '">Remove</a></td>';
                        tb2.appendChild(r2);
                    });
                    listCont.appendChild(sec2);
                }

                if (!loaded) {
                    listCont.innerHTML = '<div class="skyhshoso-empty-message">No collaborators found. Click "Invite User" to add a collaborator.</div>';
                }
            });
        }
    }

    function initSubscriptionSwitcher() {
        var containers = document.querySelectorAll('.skyhshoso-switch-container');
        if (!containers.length) return;

        containers.forEach(function(container) {
            var subId     = container.getAttribute('data-subscription-id');
            var select    = container.querySelector('.skyhshoso-switch-select');
            var switchBtn = container.querySelector('.skyhshoso-switch-btn');
            var btnText   = container.querySelector('.skyhshoso-switch-btn-text');
            var spinner   = container.querySelector('.skyhshoso-switch-spinner');
            var msgDiv    = container.querySelector('.skyhshoso-switch-message');

            if (!select || !switchBtn) return;

            var currentOption = select.querySelector('option[data-current="1"]');
            var currentValue  = currentOption ? currentOption.value : select.value;

            select.addEventListener('change', function() {
                if (this.value !== currentValue) {
                    switchBtn.style.display = 'inline-flex';
                    if (msgDiv) {
                        msgDiv.style.display = 'none';
                        msgDiv.innerHTML = '';
                    }
                } else {
                    switchBtn.style.display = 'none';
                }
            });

            switchBtn.addEventListener('click', function() {
                var newVariationId = select.value;
                if (newVariationId === currentValue) return;

                btnText.style.display = 'none';
                spinner.style.display = 'inline-block';
                switchBtn.disabled = true;
                select.disabled = true;

                var fd = new FormData();
                fd.append('action', 'skyhshoso_switch_to_cart');
                fd.append('subscription_id', subId);
                fd.append('new_variation_id', newVariationId);
                fd.append('nonce', config.switchNonce);

                fetch(config.ajaxUrl, {
                    method: 'POST',
                    body: new URLSearchParams(fd)
                })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d.success && d.data.checkout_url) {
                        window.location.href = d.data.checkout_url;
                    } else {
                        spinner.style.display = 'none';
                        btnText.style.display = 'inline';
                        switchBtn.disabled = false;
                        select.disabled = false;

                        var errMsg = (d.data && d.data.message) || 'Unknown error from server.';
                        if (msgDiv) {
                            msgDiv.className = 'skyhshoso-switch-message skyhshoso-switch-error';
                            msgDiv.innerHTML = errMsg;
                            msgDiv.style.display = 'block';
                        }
                    }
                })
                .catch(function(err) {
                    spinner.style.display = 'none';
                    btnText.style.display = 'inline';
                    switchBtn.disabled = false;
                    select.disabled = false;

                    if (msgDiv) {
                        msgDiv.className = 'skyhshoso-switch-message skyhshoso-switch-error';
                        msgDiv.innerHTML = 'Connection error: ' + (err.message || err);
                        msgDiv.style.display = 'block';
                    }
                });
            });
        });
    }

    function initDashboardStats() {
        document.querySelectorAll('[id^="skyhshoso-stats-grid-"]').forEach(function(grid) {
            var hostingId = grid.getAttribute('data-hosting-id');
            var nonce = grid.getAttribute('data-nonce');
            var fd = new FormData();
            fd.append('action', 'skyhshoso_get_cpanel_stats');
            fd.append('hosting_id', hostingId);
            fd.append('nonce', nonce);
            fetch(config.ajaxUrl, { method: 'POST', body: new URLSearchParams(fd) })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.success) return;
                var stats = d.data.stats || {};
                var usage = d.data.usage || {};
                var wpSites = stats.wordpress_sites || [];
                var diskPct = 0;
                if (usage && usage.disk_limit > 0) diskPct = Math.round((usage.disk_used / usage.disk_limit) * 100);
                grid.innerHTML =
                    '<div class="skyhshoso-stat-card"><span class="skyhshoso-stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" /></svg></span><span class="skyhshoso-stat-value">' + (diskPct > 0 ? diskPct + '%' : '-') + '</span><span class="skyhshoso-stat-label">Disk Usage</span></div>' +
                    '<div class="skyhshoso-stat-card"><span class="skyhshoso-stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg></span><span class="skyhshoso-stat-value">' + (stats.email_accounts ? stats.email_accounts.length : 0) + '</span><span class="skyhshoso-stat-label">Email</span></div>' +
                    '<div class="skyhshoso-stat-card"><span class="skyhshoso-stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg></span><span class="skyhshoso-stat-value">' + wpSites.length + '</span><span class="skyhshoso-stat-label">WordPress</span></div>' +
                    '<div class="skyhshoso-stat-card"><span class="skyhshoso-stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg></span><span class="skyhshoso-stat-value">' + (stats.subdomains ? stats.subdomains.length : 0) + '</span><span class="skyhshoso-stat-label">Subdomains</span></div>' +
                    '<div class="skyhshoso-stat-card"><span class="skyhshoso-stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" /></svg></span><span class="skyhshoso-stat-value">' + ((stats.addon_domains ? stats.addon_domains.length : 0) + (stats.parked_domains ? stats.parked_domains.length : 0)) + '</span><span class="skyhshoso-stat-label">Addon/Parked</span></div>' +
                    '<div class="skyhshoso-stat-card"><span class="skyhshoso-stat-icon"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg></span><span class="skyhshoso-stat-value">' + formatBytes(usage && usage.disk_limit ? usage.disk_limit : 0) + '</span><span class="skyhshoso-stat-label">Disk Limit</span></div>';
                var statusEl = document.querySelector('.skyhshoso-cpanel-status');
                if (statusEl) statusEl.textContent = 'Updated';
            });
        });
    }

    function initCpanelDashboard() {
        // Redundant due to removal of nested WP management view, but kept to not break anything.
    }

    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(1) + ' ' + units[i];
    }

    function escapeHtml(text) {
        if (!text) return '';
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function initWpSiteProvision() {
        var provisionBtn = document.getElementById('skyhshoso-wp-provision-btn');
        if (!provisionBtn) return;

        var wpProvisionNonce = typeof skyhshosoDashboard !== 'undefined' && skyhshosoDashboard.wpProvisionNonce ? skyhshosoDashboard.wpProvisionNonce : '';
        var resultEl = document.getElementById('skyhshoso-wp-provision-result');
        var domainInput = document.getElementById('skyhshoso-wp-domain-input');
        var formView = document.getElementById('skyhshoso-wp-provision-form');
        var loadingView = document.getElementById('skyhshoso-wp-loading');
        var loadIcon = document.getElementById('skyhshoso-wp-load-icon');
        var loadRing = document.getElementById('skyhshoso-wp-load-ring');
        var loadCheck = document.getElementById('skyhshoso-wp-load-check');
        var loadTitle = document.getElementById('skyhshoso-wp-load-title');
        var loadMsg = document.getElementById('skyhshoso-wp-load-msg');

        var steps = [
            {
                svg: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" /></svg>',
                msg: 'Setting up your environment'
            },
            {
                svg: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.58 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.58 4 8 4s8-1.79 8-4M4 7c0-2.21 3.58-4 8-4s8 1.79 8 4m0 5c0 2.21-3.58 4-8 4s-8-1.79-8-4" /></svg>',
                msg: 'Configuring the database'
            },
            {
                svg: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
                msg: 'Downloading WordPress core'
            },
            {
                svg: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" /></svg>',
                msg: 'Applying your settings'
            },
            {
                svg: '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:26px;height:26px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>',
                msg: 'Almost done...'
            }
        ];

        provisionBtn.addEventListener('click', function() {
            var wpSiteId = this.getAttribute('data-id');
            var domain = domainInput ? domainInput.value.trim() : '';

            if (!domain) {
                if (resultEl) resultEl.innerHTML = '<p style="color:#d63638;font-size:13px;">Please enter a domain.</p>';
                return;
            }

            var adminUser = document.getElementById('skyhshoso-wp-admin-user-input');
            var adminEmail = document.getElementById('skyhshoso-wp-admin-email-input');
            var adminPass = document.getElementById('skyhshoso-wp-admin-pass-input');

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
                stepIndex = (stepIndex + 1) % steps.length;
                transitionStep(steps[stepIndex]);
            }, 2800);

            function showSuccess() {
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
                        if (loadTitle) loadTitle.textContent = 'All done!';
                        if (loadMsg) {
                            loadMsg.textContent = 'Your WordPress site is ready';
                            loadMsg.style.opacity = '1';
                        }
                    }, 250);
                }
                setTimeout(function() { window.location.reload(); }, 3000);
            }

            function showError(msg) {
                clearInterval(stepInterval);
                if (loadIcon) {
                    loadIcon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:26px;height:26px;color:#d63638;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>';
                    loadIcon.classList.remove('skyhshoso-wp-fading');
                }
                if (loadTitle) loadTitle.textContent = 'Installation failed';
                if (loadMsg) {
                    loadMsg.textContent = msg || 'An error occurred. Please try again.';
                    loadMsg.style.opacity = '1';
                    loadMsg.style.color = '#d63638';
                }
            }

            var pluginCheckboxes = document.querySelectorAll('.skyhshoso-wp-plugin:checked');
            var plugins = [];
            pluginCheckboxes.forEach(function(cb) { plugins.push(cb.value); });

            var params = 'action=skyhshoso_wp_provision&wp_site_id=' + encodeURIComponent(wpSiteId) + '&domain=' + encodeURIComponent(domain) + '&nonce=' + encodeURIComponent(wpProvisionNonce);
            if (plugins.length) {
                params += '&plugins=' + encodeURIComponent(plugins.join(','));
            }
            if (adminUser && adminUser.value.trim()) {
                params += '&admin_user=' + encodeURIComponent(adminUser.value.trim());
            }
            if (adminEmail && adminEmail.value.trim()) {
                params += '&admin_email=' + encodeURIComponent(adminEmail.value.trim());
            }
            if (adminPass && adminPass.value.trim()) {
                params += '&admin_pass=' + encodeURIComponent(adminPass.value.trim());
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', config.ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        showSuccess();
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

    function initWpPasswordToggle() {
        var toggleBtn = document.getElementById('skyhshoso-wp-pass-toggle');
        var passDisplay = document.getElementById('skyhshoso-wp-pass-display');
        var copyBtn = document.getElementById('skyhshoso-wp-pass-copy');
        if (!toggleBtn || !passDisplay) return;

        toggleBtn.addEventListener('click', function() {
            var pass = this.getAttribute('data-pass');
            var visible = this.getAttribute('data-visible') === '1';
            if (visible) {
                passDisplay.innerHTML = '&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;';
                this.setAttribute('data-visible', '0');
            } else {
                passDisplay.textContent = pass;
                this.setAttribute('data-visible', '1');
            }
        });

        if (copyBtn) {
            copyBtn.addEventListener('click', function() {
                var pass = this.getAttribute('data-pass');
                if (!pass) return;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(pass).then(function() { showCopied(copyBtn); });
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = pass;
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); showCopied(copyBtn); } catch(e) {}
                    document.body.removeChild(ta);
                }
            });
        }

        function showCopied(btn) {
            var orig = btn.innerHTML;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:14px;height:14px;vertical-align:middle;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>';
            setTimeout(function() { btn.innerHTML = orig; }, 1500);
        }
    }

    function init() {
        initRedirect();
        initSearchInputs();
        initCPanelLogin();
        initCollaborators();
        initSubscriptionSwitcher();
        initDashboardStats();
        initCpanelDashboard();
        initWpSiteProvision();
        initWpPasswordToggle();
        initWpSiteScanner(); // Added WP Scanner Initializer
        
        setupPagination({
            paginationContainerId: 'skyhshoso-hosting-pagination',
            tbodyId: 'skyhshoso-hosting-tbody',
            searchInputId: 'skyhshoso-hosting-search',
            noResultsId: 'skyhshoso-hosting-no-results',
            tableId: 'skyhshoso-hosting-table',
            ajaxAction: 'skyhshoso_get_hosting_page'
        });

        setupPagination({
            paginationContainerId: 'skyhshoso-domain-pagination',
            tbodyId: 'skyhshoso-domain-tbody',
            searchInputId: 'skyhshoso-domain-search',
            noResultsId: 'skyhshoso-domain-no-results',
            tableId: 'skyhshoso-domain-table',
            ajaxAction: 'skyhshoso_get_domain_page'
        });

        if (document.getElementById('skyhshoso-wp-site-pagination')) {
            setupPagination({
                paginationContainerId: 'skyhshoso-wp-site-pagination',
                tbodyId: 'skyhshoso-wp-site-tbody',
                tableId: 'skyhshoso-wp-site-table',
                ajaxAction: 'skyhshoso_get_wp_site_page',
                autoFetch: true
            });
        }
        
        initPeriodToggle();
        initAddDomainForm();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();