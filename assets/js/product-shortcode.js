/**
 * SkyHS Hosting Plan Shortcode — Shadow DOM Renderer
 *
 * Reads plan data from JSON script blocks, attaches a Shadow DOM to each
 * host element, and renders a fully self-contained, modern SaaS-style
 * pricing card UI with zero CSS leakage from the host theme.
 */
(function () {
    'use strict';

    /* =====================================================================
     * 1. CSS — injected into each Shadow Root
     * =================================================================== */

    var CSS = [
        /* ---------- reset & base ---------- */
        ':host { display: block; font-family: "Inter", "Segoe UI", system-ui, -apple-system, sans-serif; line-height: 1.5; color: #334155; -webkit-font-smoothing: antialiased; outline: none !important; border: none !important; box-shadow: none !important; }',
        ':host(:focus), :host(:focus-within), :host(:active) { outline: none !important; border: none !important; box-shadow: none !important; }',
        '*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }',

        /* ---------- wrapper ---------- */
        '.sp-wrapper { max-width: 1200px; margin: 0 auto; padding: 8px 0; }',

        /* ---------- title ---------- */
        '.sp-title { font-size: 28px; font-weight: 800; color: #0f172a; margin-bottom: 16px; letter-spacing: -0.02em; text-align: center; }',

        /* ---------- toggle ---------- */
        '.sp-toggle-container { display: flex; justify-content: center; width: 100%; margin-bottom: 32px; }',
        '.sp-toggle-bar { display: inline-flex; position: relative; background: #f1f5f9; border-radius: 999px; padding: 4px; box-shadow: inset 0 1px 2px rgba(0,0,0,.06); }',
        '.sp-toggle-option { position: relative; z-index: 2; }',
        '.sp-toggle-radio { position: absolute; opacity: 0; width: 0; height: 0; pointer-events: none; }',
        '.sp-toggle-radio:focus, .sp-toggle-radio:focus-visible { outline: none !important; }',
        '.sp-toggle-label { display: inline-block; padding: 8px 22px; font-size: 14px; font-weight: 600; color: #64748b; cursor: pointer; border-radius: 999px; transition: color .25s ease; user-select: none; white-space: nowrap; }',
        '.sp-toggle-radio:checked + .sp-toggle-label { color: #1e40af; }',
        '.sp-toggle-slider { position: absolute; top: 4px; left: 4px; height: calc(100% - 8px); border-radius: 999px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.1), 0 1px 2px rgba(0,0,0,.06); transition: transform .3s cubic-bezier(.4,0,.2,1), width .25s ease; z-index: 1; }',

        /* ---------- grid ---------- */
        '.sp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }',

        /* ---------- card ---------- */
        '.sp-card { position: relative; background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 32px 28px 28px; display: flex; flex-direction: column; transition: transform .25s ease, box-shadow .25s ease; }',
        '.sp-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(0,0,0,.08); }',
        '.sp-card.sp-popular { border-color: #3b82f6; box-shadow: 0 0 0 1px #3b82f6, 0 8px 24px rgba(59,130,246,.12); }',

        /* ---------- badges ---------- */
        '.sp-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }',
        '.sp-badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }',
        '.sp-badge-sale { background: #0f172a; color: #fff; }',
        '.sp-badge-trial { background: #dbeafe; color: #1e40af; }',

        /* ---------- plan name ---------- */
        '.sp-plan-name { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 6px; }',

        /* ---------- price ---------- */
        '.sp-price { margin-bottom: 20px; }',
        '.sp-price-html { font-size: 15px; font-weight: 600; color: #0f172a; }',
        '.sp-price-html del { color: #94a3b8; font-weight: 400; }',
        '.sp-price-html ins { text-decoration: none; color: #0f172a; }',
        '.sp-price-html .woocommerce-Price-amount { font-size: 32px; font-weight: 800; letter-spacing: -0.02em; }',
        '.sp-price-html .woocommerce-Price-currencySymbol { font-size: 18px; font-weight: 700; vertical-align: super; margin-right: 2px; }',
        '.sp-period-label { font-size: 14px; font-weight: 500; color: #64748b; }',

        /* ---------- divider ---------- */
        '.sp-divider { width: 100%; height: 1px; background: #e2e8f0; margin-bottom: 18px; }',

        /* ---------- features ---------- */
        '.sp-features { list-style: none; margin: 0 0 24px; padding: 0; flex: 1; }',
        '.sp-features li { display: flex; align-items: center; gap: 10px; padding: 6px 0; font-size: 14px; color: #475569; }',
        '.sp-features li.sp-feat-cross { color: #94a3b8; }',
        '.sp-fi { flex-shrink: 0; width: 20px; height: 20px; }',

        /* ---------- button ---------- */
        '.sp-btn { display: block; width: 100%; padding: 12px 24px; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; color: #fff; cursor: pointer; text-align: center; text-decoration: none; transition: background .2s ease, transform .15s ease, box-shadow .2s ease; background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); box-shadow: 0 2px 8px rgba(37,99,235,.25); }',
        '.sp-btn:hover { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,.35); }',
        '.sp-btn:active { transform: translateY(0); }',

        /* ---------- single card (simple product) ---------- */
        '.sp-single .sp-card { max-width: 400px; }',

        /* ---------- responsive ---------- */
        '@media (max-width: 640px) {',
        '  .sp-grid { grid-template-columns: 1fr; }',
        '  .sp-card { padding: 24px 20px 20px; }',
        '  .sp-title { font-size: 22px; }',
        '}'
    ].join('\n');


    /* =====================================================================
     * 2. SVG Icons
     * =================================================================== */

    var SVG_CHECK = '<svg class="sp-fi" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">'
        + '<circle cx="10" cy="10" r="10" fill="#dbeafe"/>'
        + '<path d="M6 10.5l2.5 2.5L14 7.5" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        + '</svg>';

    var SVG_CROSS = '<svg class="sp-fi" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">'
        + '<circle cx="10" cy="10" r="10" fill="#f1f5f9"/>'
        + '<path d="M7 7l6 6M13 7l-6 6" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
        + '</svg>';

    var SVG_NEUTRAL = '<svg class="sp-fi" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">'
        + '<circle cx="10" cy="10" r="10" fill="#f1f5f9"/>'
        + '<path d="M7 10h6" stroke="#94a3b8" stroke-width="2" stroke-linecap="round"/>'
        + '</svg>';


    /* =====================================================================
     * 3. Helpers
     * =================================================================== */

    function esc(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function featureIcon(type) {
        if (type === 'check') return SVG_CHECK;
        if (type === 'cross') return SVG_CROSS;
        return SVG_NEUTRAL;
    }


    /* =====================================================================
     * 4. Build HTML
     * =================================================================== */

    function buildHTML(data) {
        var h = '';
        h += '<div class="sp-wrapper' + (data.type === 'simple' ? ' sp-single' : '') + '">';

        // Title (for variable products with toggle).
        if (data.type === 'variable' && data.period_groups && data.period_groups.length > 1) {
            if (data.show_title !== false) {
                h += '<h2 class="sp-title">' + esc(data.title) + '</h2>';
            }
            h += buildToggle(data.period_groups, data.id);
        }

        // Cards grid.
        h += '<div class="sp-grid">';
        for (var i = 0; i < data.plans.length; i++) {
            h += buildCard(data.plans[i], data, i);
        }
        h += '</div>';

        h += '</div>';
        return h;
    }

    function buildToggle(groups, productId) {
        var h = '<div class="sp-toggle-container">';
        h += '<div class="sp-toggle-bar">';
        for (var i = 0; i < groups.length; i++) {
            var g = groups[i];
            var inputId = 'sp-tg-' + productId + '-' + i;
            h += '<div class="sp-toggle-option">';
            h += '<input type="radio" class="sp-toggle-radio" id="' + inputId + '"'
               + ' name="sp-period-' + productId + '"'
               + ' data-period="' + esc(g.slug) + '"'
               + (i === 0 ? ' checked' : '') + '>';
            h += '<label class="sp-toggle-label" for="' + inputId + '">' + esc(g.label) + '</label>';
            h += '</div>';
        }
        h += '<div class="sp-toggle-slider"></div>';
        h += '</div>';
        h += '</div>';
        return h;
    }

    function buildCard(plan, data, index) {
        var hasBadges = plan.on_sale || plan.trial_length > 0;
        var isSimple  = data.type === 'simple';

        var h = '<div class="sp-card' + (index === 1 && !isSimple ? ' sp-popular' : '') + '"'
              + (plan.period_group ? ' data-period="' + esc(plan.period_group) + '"' : '')
              + '>';

        // Badges.
        if (hasBadges) {
            h += '<div class="sp-badges">';
            if (plan.on_sale) {
                h += '<span class="sp-badge sp-badge-sale">' + esc(data.i18n.sale) + '</span>';
            }
            if (plan.trial_length > 0) {
                h += '<span class="sp-badge sp-badge-trial">' + plan.trial_length + ' ' + esc(plan.trial_period) + ' ' + esc(data.i18n.free_trial) + '</span>';
            }
            h += '</div>';
        }

        // Plan name.
        if (!isSimple) {
            h += '<div class="sp-plan-name">' + esc(plan.name) + '</div>';
        } else {
            h += '<div class="sp-plan-name">' + esc(data.title) + '</div>';
        }

        // Price.
        h += '<div class="sp-price">';
        h += '<span class="sp-price-html">' + plan.price_html + '</span>';
        if (plan.period_label) {
            h += '<span class="sp-period-label">' + esc(plan.period_label) + '</span>';
        }
        h += '</div>';

        // Divider.
        h += '<div class="sp-divider"></div>';

        // Features.
        if (plan.features && plan.features.length > 0) {
            h += '<ul class="sp-features">';
            for (var f = 0; f < plan.features.length; f++) {
                var feat = plan.features[f];
                h += '<li class="' + (feat.type === 'cross' ? 'sp-feat-cross' : '') + '">';
                h += featureIcon(feat.type);
                h += '<span>' + esc(feat.text) + '</span>';
                h += '</li>';
            }
            h += '</ul>';
        }

        // Button.
        h += '<a href="' + plan.buy_url + '" class="sp-btn">' + esc(data.i18n.buy) + '</a>';

        h += '</div>';
        return h;
    }


    /* =====================================================================
     * 5. Toggle Logic (inside Shadow DOM)
     * =================================================================== */

    function initToggle(shadowRoot) {
        var bar    = shadowRoot.querySelector('.sp-toggle-bar');
        if (!bar) return;

        var radios = bar.querySelectorAll('.sp-toggle-radio');
        var slider = bar.querySelector('.sp-toggle-slider');
        var cards  = shadowRoot.querySelectorAll('.sp-card[data-period]');

        if (!radios.length || !slider || !cards.length) return;

        function sync(radio) {
            var label = radio.nextElementSibling;
            if (!label) return;
            slider.style.width     = label.offsetWidth + 'px';
            slider.style.transform = 'translateX(' + (radio.parentElement.offsetLeft - 4) + 'px)';
        }

        function filterCards(selectedPeriod) {
            for (var i = 0; i < cards.length; i++) {
                cards[i].style.display = cards[i].getAttribute('data-period') === selectedPeriod ? '' : 'none';
            }
        }

        // Bind change events.
        for (var i = 0; i < radios.length; i++) {
            (function (radio) {
                radio.addEventListener('change', function () {
                    sync(radio);
                    filterCards(radio.getAttribute('data-period'));
                });
            })(radios[i]);
        }

        // Initial state.
        var checked = bar.querySelector('.sp-toggle-radio:checked');
        if (checked) {
            // Use rAF to ensure layout is ready (elements have offsetWidth).
            requestAnimationFrame(function () {
                sync(checked);
                filterCards(checked.getAttribute('data-period'));
            });
        }

        // Resize handler.
        window.addEventListener('resize', function () {
            var active = bar.querySelector('.sp-toggle-radio:checked');
            if (active) sync(active);
        });
    }


    /* =====================================================================
     * 6. Bootstrap — find containers, attach Shadow DOM
     * =================================================================== */

    function bootstrap() {
        var scripts = document.querySelectorAll('script[data-skyhshoso-plan]');

        for (var i = 0; i < scripts.length; i++) {
            var script      = scripts[i];
            var containerId = script.getAttribute('data-skyhshoso-plan');
            var host        = document.getElementById(containerId);

            if (!host || host.shadowRoot) continue; // already initialized

            var data;
            try {
                data = JSON.parse(script.textContent);
            } catch (e) {
                continue;
            }

            // Attach shadow root.
            var shadow = host.attachShadow({ mode: 'open' });

            // Inject styles.
            var style = document.createElement('style');
            style.textContent = CSS;
            shadow.appendChild(style);

            // Inject HTML.
            var container = document.createElement('div');
            container.innerHTML = buildHTML(data);
            shadow.appendChild(container);

            // Init toggle behavior.
            initToggle(shadow);
        }
    }

    // Run.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }
})();
