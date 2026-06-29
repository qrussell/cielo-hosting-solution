jQuery(document).ready(function ($) {
    const popularTLDs = ['.com', '.net', '.org', '.io', '.co', '.app'];
    let currentTLDIndex = popularTLDs.length;
    const additionalTLDs = [
        '.info', '.biz', '.me', '.tv', '.online', '.site', '.store', '.xyz',
        '.ngo', '.ong', '.us', '.asia', '.la', '.mobi', '.bible', '.blog',
        '.co.uk', '.uk', '.org.uk', '.com.tw', '.tw', '.org.tw', '.idv.tw',
        '.ca', '.cn', '.eu', '.name', '.cc', '.ac', '.sh', '.bz', '.nu',
        '.ws', '.tm', '.com.cn', '.net.cn', '.org.cn', '.de', '.be', '.tc',
        '.vg', '.cm', '.ms', '.gs', '.jp', '.net.nz', '.co.nz', '.org.nz',
        '.com.mx', '.br.com', '.cn.com', '.jpn.com', '.eu.com', '.uk.com',
        '.uk.net', '.co.com', '.com.de', '.pw', '.us.com', '.ru.com',
        '.sa.com', '.se.net', '.za.com', '.de.com', '.in', '.me.uk', '.at',
        '.am', '.nl', '.fm', '.it', '.radio.am', '.radio.fm', '.tel', '.co',
        '.com.co', '.net.co', '.nom.co', '.pro', '.aca.pro', '.acct.pro',
        '.avocat.pro', '.bar.pro', '.cpa.pro', '.eng.pro', '.jur.pro',
        '.law.pro', '.med.pro', '.gr.com', '.us.org', '.xxx', '.pe',
        '.com.pe', '.net.pe', '.org.pe', '.es', '.com.es', '.nom.es',
        '.org.es', '.au'
    ];

    function domainCardCss() {
        return `<style>
            .dc-card { margin-bottom:12px; border:1px solid #dbe1e6; border-radius:12px; padding:20px 24px; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.04); display:flex; align-items:center; gap:16px; flex-wrap:wrap; font-family:Inter,"Noto Sans",sans-serif; }
            .dc-card-left { display:flex; align-items:center; gap:10px; flex:1; min-width:160px; }
            .dc-card-name { font-size:16px; font-weight:700; color:#111518; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .dc-card-badge { display:inline-flex; align-items:center; padding:3px 10px; font-size:11px; font-weight:700; line-height:1.3; color:#fff; border-radius:9999px; white-space:nowrap; flex-shrink:0; }
            .dc-card-badge-avail { background:#059669; }
            .dc-card-badge-unavail { background:#dc2626; }
            .dc-card-pricing { display:flex; gap:20px; flex-wrap:wrap; }
            .dc-card-price { display:flex; flex-direction:column; }
            .dc-card-price-label { font-size:11px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
            .dc-card-price-val { font-size:16px; font-weight:700; color:#111518; }
            .dc-card-btn { padding:10px 20px; background:#111518; color:#fff; border:none; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; transition:background .2s; font-family:inherit; white-space:nowrap; }
            .dc-card-btn:hover { background:#2c3136; }
            .dc-card-btn:disabled { opacity:.65; cursor:not-allowed; }
            .dc-card-unavail { color:#6b7280; font-size:13px; }
            @media (max-width:600px) { .dc-card { flex-direction:column; align-items:stretch; text-align:center; } .dc-card-left { justify-content:center; } .dc-card-pricing { justify-content:center; } }
        </style>`;
    }

    function displayDomainResult(domainData, elementId) {
        var host = document.getElementById(elementId.replace('#', ''));
        if (!host) return;
        if (!host.shadowRoot) {
            host.attachShadow({ mode: 'open' });
        }
        var root = host.shadowRoot;

        if (domainData.available) {
            root.innerHTML = domainCardCss() + `
                <div class="dc-card">
                    <div class="dc-card-left">
                        <span class="dc-card-name">${domainData.name}</span>
                        <span class="dc-card-badge dc-card-badge-avail">${skyhshoso_domain_checker_vars.i18n.available}</span>
                    </div>
                    <div class="dc-card-pricing">
                        <div class="dc-card-price">
                            <span class="dc-card-price-label">${skyhshoso_domain_checker_vars.i18n.registration_price}</span>
                            <span class="dc-card-price-val">$${domainData.registration_price.toFixed(2)}</span>
                        </div>
                        <div class="dc-card-price">
                            <span class="dc-card-price-label">${skyhshoso_domain_checker_vars.i18n.renewal_price}</span>
                            <span class="dc-card-price-val">$${domainData.renewal_price.toFixed(2)}</span>
                        </div>
                    </div>
                    <button class="dc-card-btn" data-domain="${domainData.name}">${skyhshoso_domain_checker_vars.i18n.add_to_cart}</button>
                </div>
            `;
            root.querySelector('button[data-domain]').addEventListener('click', function () {
                skyhshoso_addToCart(this.getAttribute('data-domain'), this);
            });
        } else {
            root.innerHTML = domainCardCss() + `
                <div class="dc-card">
                    <div class="dc-card-left">
                        <span class="dc-card-name">${domainData.name}</span>
                        <span class="dc-card-badge dc-card-badge-unavail">${skyhshoso_domain_checker_vars.i18n.not_available}</span>
                    </div>
                    <div class="dc-card-unavail">${skyhshoso_domain_checker_vars.i18n.not_available_message}</div>
                </div>
            `;
        }
    }

    function checkDomain(domain, elementId) {
        return $.ajax({
            url: skyhshoso_domain_checker_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'skyhshoso_check_domain',
                skyhshoso_domain: domain,
                nonce: skyhshoso_domain_checker_vars.nonce
            },
            success: function (response) {
                // Debug: Log the response to console
                if (!response.error) {
                    displayDomainResult(response, elementId);
                } else {
                    const errorMessage = response.error || skyhshoso_domain_checker_vars.i18n.error_occurred;
                    $(elementId).html(`<div class="skyhshoso-domain-result-item"><div class="skyhshoso-domain-result-col skyhshoso-domain-checker-text-danger">${errorMessage}</div></div>`);
                }
            },
            error: function (xhr, status, error) {
                $(elementId).html(`<div class="skyhshoso-domain-result-item"><div class="skyhshoso-domain-result-col skyhshoso-domain-checker-text-danger">${skyhshoso_domain_checker_vars.i18n.error_occurred}</div></div>`);
            }
        });
    }

    function createSkeletonLoader() {
        return `
            <div style="border:1px solid #dbe1e6;border-radius:12px;padding:20px 24px;background:#fff;display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:12px;">
                <div style="display:flex;align-items:center;gap:10px;flex:1;min-width:160px;">
                    <div class="skyhshoso-domain-result-placeholder lg"></div>
                    <div class="skyhshoso-domain-result-placeholder sm"></div>
                </div>
                <div style="display:flex;gap:20px;">
                    <div class="skyhshoso-domain-result-placeholder md"></div>
                    <div class="skyhshoso-domain-result-placeholder md"></div>
                </div>
                <div class="skyhshoso-domain-result-placeholder button"></div>
            </div>
        `;
    }

    $('#domain-search-form').on('submit', function (e) {

        e.preventDefault();

        const domain = $('#newdomain').val();
        const domainRegex = /^[a-zA-Z0-9][a-zA-Z0-9-]{1,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$/;

        if (!domainRegex.test(domain)) {
            alert(skyhshoso_domain_checker_vars.i18n.enter_valid_domain);
            return;
        }

        // Create main result container
        $('#main-result').html(`<div id="main-skeleton"></div>`);

        // Create suggestions container
        $('#suggestions').html(`<div id="suggestions-body"></div>`);

        $('#search-results').removeClass('skyhshoso-domain-checker-hidden');
        $('#loading').removeClass('skyhshoso-domain-checker-hidden');

        // Loading placeholder
        $('#main-skeleton').html(createSkeletonLoader());

        $('#suggestions-body').empty();
        $('#load-more-container').addClass('skyhshoso-domain-checker-hidden');

        currentTLDIndex = popularTLDs.length;

        checkDomain(domain, '#main-skeleton').always(function () {
            $('#loading').addClass('skyhshoso-domain-checker-hidden');
        });

        const domainName = domain.split('.')[0];
        checkSuggestedDomains(domainName, popularTLDs);
    });

    function checkSuggestedDomains(domainName, tlds) {
        tlds.forEach(function (tld, index) {
            const suggDomain = domainName + tld;
            const suggElementId = `suggestion-${Date.now()}-${index}`; // Use timestamp to ensure unique IDs

            $('#suggestions-body').append(`<div id="${suggElementId}">${createSkeletonLoader()}</div>`);

            setTimeout(function () {
                checkDomain(suggDomain, '#' + suggElementId);
            }, index * 300);
        });

        if (currentTLDIndex < (popularTLDs.length + additionalTLDs.length)) {
            $('#load-more-container').removeClass('skyhshoso-domain-checker-hidden');
        }
    }

    $('#load-more').on('click', function () {
        const domain = $('#newdomain').val();
        const domainName = domain.split('.')[0];
        const newTLDs = additionalTLDs.slice(currentTLDIndex - popularTLDs.length, currentTLDIndex - popularTLDs.length + 3);

        checkSuggestedDomains(domainName, newTLDs);

        currentTLDIndex += 3;
        if (currentTLDIndex >= (popularTLDs.length + additionalTLDs.length)) {
            $('#load-more-container').addClass('skyhshoso-domain-checker-hidden');
        }
    });
});

var skyhshoso_transfer_host = null;

jQuery(document).ready(function ($) {
    var host = document.getElementById('transfer-results');
    if (host) {
        skyhshoso_transfer_host = host.attachShadow({ mode: 'open' });
    }

    $('#domain-transfer-form').on('submit', function (e) {
        e.preventDefault();

        const domain = $('#transfer-domain').val();
        const authCode = $('#transfer-auth-code').val();
        const domainRegex = /^[a-zA-Z0-9][a-zA-Z0-9-]{1,61}[a-zA-Z0-9]\.[a-zA-Z]{2,}$/;

        if (!domainRegex.test(domain)) {
            alert(skyhshoso_domain_checker_vars.i18n.enter_valid_domain);
            return;
        }

        if (!authCode.trim()) {
            alert(skyhshoso_domain_checker_vars.i18n.auth_code_required);
            return;
        }

        $('#transfer-results').addClass('skyhshoso-domain-checker-hidden');
        $('#transfer-loading').removeClass('skyhshoso-domain-checker-hidden');

        $.ajax({
            url: skyhshoso_domain_checker_vars.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'skyhshoso_check_transfer',
                skyhshoso_domain: domain,
                nonce: skyhshoso_domain_checker_vars.nonce
            },
            success: function (response) {
                if (!skyhshoso_transfer_host) return;

                var css = skyhshoso_get_transfer_card_css();

                if (response.transferable) {
                    skyhshoso_transfer_host.innerHTML = css + `
                        <div class="card card-success">
                            <div class="card-header">
                                <span class="card-domain">${skyhshoso_escapeHtml(response.name)}</span>
                                <span class="card-badge">${skyhshoso_domain_checker_vars.i18n.transferable}</span>
                            </div>
                            <div class="card-pricing">
                                <div class="card-price-row">
                                    <span class="card-label">${skyhshoso_domain_checker_vars.i18n.transfer_price}</span>
                                    <span class="card-value">$${response.transfer_price.toFixed(2)}</span>
                                </div>
                                <div class="card-price-row">
                                    <span class="card-label">${skyhshoso_domain_checker_vars.i18n.renewal_price}</span>
                                    <span class="card-value">$${response.renewal_price.toFixed(2)}</span>
                                </div>
                            </div>
                            <div class="card-renewal-note">${skyhshoso_domain_checker_vars.i18n.include_renewal}</div>
                            <button class="card-btn" data-domain="${skyhshoso_escapeHtml(response.name)}">
                                ${skyhshoso_domain_checker_vars.i18n.add_to_cart_transfer}
                            </button>
                        </div>
                    `;
                    
                    var btn = skyhshoso_transfer_host.querySelector('button[data-domain]');
                    if (btn) {
                        btn.addEventListener('click', function () {
                            skyhshoso_addTransferToCart(this.getAttribute('data-domain'), this);
                        });
                    }
                } else {
                    var errorMsg = response.error || skyhshoso_domain_checker_vars.i18n.not_transferable_message;
                    skyhshoso_transfer_host.innerHTML = css + `
                        <div class="card card-error">
                            <div class="card-header">
                                <span class="card-domain">${skyhshoso_escapeHtml(response.name || domain)}</span>
                                <span class="card-badge card-badge-error">${skyhshoso_domain_checker_vars.i18n.not_transferable}</span>
                            </div>
                            <div class="card-error-msg">${skyhshoso_escapeHtml(errorMsg)}</div>
                        </div>
                    `;
                }

                $('#transfer-results').removeClass('skyhshoso-domain-checker-hidden');
            },
            error: function () {
                if (!skyhshoso_transfer_host) return;
                skyhshoso_transfer_host.innerHTML = skyhshoso_get_transfer_card_css() + `
                    <div class="card card-error">
                        <div class="card-error-msg">${skyhshoso_domain_checker_vars.i18n.error_occurred}</div>
                    </div>
                `;
                $('#transfer-results').removeClass('skyhshoso-domain-checker-hidden');
            },
            complete: function () {
                $('#transfer-loading').addClass('skyhshoso-domain-checker-hidden');
            }
        });
    });

});

function skyhshoso_get_transfer_card_css() {
    return `<style>
        .card {
            margin-top:20px; border:1px solid #dbe1e6; border-radius:12px;
            padding:32px 28px; background:#fff;
            box-shadow:0 1px 3px rgba(0,0,0,0.06); text-align:center;
            font-family:Inter,"Noto Sans",sans-serif;
        }
        .card-header {
            display:flex; align-items:center; justify-content:center;
            gap:12px; margin-bottom:24px; flex-wrap:nowrap;
        }
        .card-domain {
            font-size:22px; font-weight:700; color:#111518;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:60%;
        }
        .card-badge {
            display:inline-flex; align-items:center; padding:4px 12px;
            font-size:12px; font-weight:700; line-height:1.4;
            color:#fff; background:#059669; border-radius:9999px;
            white-space:nowrap; flex-shrink:0;
        }
        .card-badge-error { background:#dc2626; }
        .card-pricing {
            display:flex; gap:32px; justify-content:center; margin-bottom:4px;
        }
        .card-price-row {
            display:flex; flex-direction:column; align-items:center; gap:4px;
        }
        .card-label {
            font-size:12px; color:#6b7280; font-weight:600;
            text-transform:uppercase; letter-spacing:.06em;
        }
        .card-value {
            font-size:24px; font-weight:700; color:#111518; line-height:1.2;
        }
        .card-renewal-note {
            font-size:13px; color:#6b7280; margin-bottom:24px;
        }
        .card-btn {
            width:100%; padding:14px 24px; background:#111518; color:#fff;
            border:none; border-radius:8px; font-size:15px; font-weight:600;
            cursor:pointer; transition:background .2s; line-height:1.4;
            font-family:inherit;
        }
        .card-btn:hover { background:#2c3136; }
        .card-btn:disabled, .card-btn.disabled { opacity:.65; cursor:not-allowed; }
        .card-error-msg { color:#6b7280; font-size:14px; margin-top:8px; line-height:1.5; }
        @media (max-width:480px) {
            .card { padding:24px 18px; }
            .card-header { flex-wrap:wrap; }
            .card-domain { max-width:100%; white-space:normal; }
            .card-pricing { gap:20px; }
            .card-value { font-size:20px; }
        }
    </style>`;
}

function skyhshoso_escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

function skyhshoso_addTransferToCart(domain, button) {
    const authCode = jQuery('#transfer-auth-code').val();

    button.classList.add('disabled');
    button.disabled = true;
    button.innerHTML = skyhshoso_domain_checker_vars.i18n.adding;

    jQuery.ajax({
        url: skyhshoso_domain_checker_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'skyhshoso_add_transfer_to_cart',
            skyhshoso_domain: domain,
            skyhshoso_auth_code: authCode,
            nonce: skyhshoso_domain_checker_vars.add_to_cart_nonce
        },
        success: function (response) {
            if (response.success) {
                window.location.href = skyhshoso_domain_checker_vars.checkout_url;
            } else {
                button.classList.remove('disabled');
                button.disabled = false;
                button.innerHTML = skyhshoso_domain_checker_vars.i18n.add_to_cart_transfer;
                alert(response.data.message || skyhshoso_domain_checker_vars.i18n.error_occurred);
            }
        },
        error: function () {
            button.classList.remove('disabled');
            button.disabled = false;
            button.innerHTML = skyhshoso_domain_checker_vars.i18n.add_to_cart_transfer;
            alert(skyhshoso_domain_checker_vars.i18n.error_occurred);
        }
    });
}

function skyhshoso_addToCart(domain, button) {
    button.classList.add('disabled');
    button.disabled = true;
    button.innerHTML = `
        <span class="skyhshoso-domain-checker-spinner skyhshoso-domain-checker-spinner-sm" role="status" aria-hidden="true"></span>
        ${skyhshoso_domain_checker_vars.i18n.adding}
    `;

    jQuery.ajax({
        url: skyhshoso_domain_checker_vars.ajax_url,
        type: 'POST',
        data: {
            action: 'skyhshoso_add_domain_to_cart',
            skyhshoso_domain: domain,
            nonce: skyhshoso_domain_checker_vars.add_to_cart_nonce
        },
        success: function (response) {
            if (response.success) {
                window.location.href = skyhshoso_domain_checker_vars.checkout_url;
            } else {
                button.classList.remove('disabled');
                button.disabled = false;
                button.innerHTML = skyhshoso_domain_checker_vars.i18n.add_to_cart;
                alert(skyhshoso_domain_checker_vars.i18n.error_occurred);
            }
        },
        error: function () {
            button.classList.remove('disabled');
            button.disabled = false;
            button.innerHTML = skyhshoso_domain_checker_vars.i18n.add_to_cart;
            alert(skyhshoso_domain_checker_vars.i18n.error_occurred);
        }
    });
}