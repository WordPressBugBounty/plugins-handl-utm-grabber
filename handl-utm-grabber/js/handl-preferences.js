/**
 * HandL Consent Banner. Self-contained, no deps, Shadow DOM.
 *
 * Standalone: fires `handl_consent_change` to start/stop capture.
 * Manager mode (cfg.wp_consent_api = 1): pushes through wp_set_consent() so
 * every consent-aware plugin reacts.
 * Always writes the legacy `gdprConsent` cookie (the server gate in lite/gdpr.php).
 *
 * API: window.HandLConsentBanner = { open, close, getConsent }.
 * Re-open via any [data-handl-consent-open] / .handl-consent-open element.
 */
(function () {
    'use strict';

    var cfg = window.handlConsentBannerCfg;
    if (!cfg || !parseInt(cfg.enabled, 10)) {
        return;
    }

    // Never render inside page-builder/preview iframes.
    try {
        if (window.top !== window.self) {
            return;
        }
    } catch (e) { /* cross-origin frame: definitely an iframe */
        return;
    }

    var CONSENT_COOKIE = 'handl_consent_status';
    var LEGACY_COOKIE = 'gdprConsent';
    var COOKIE_DAYS = parseInt(cfg.cookie_days, 10) || 365;
    // 1 only when manager mode is on AND the WP Consent API plugin is active.
    var MANAGER_MODE = parseInt(cfg.wp_consent_api, 10) === 1;

    /* ---------------------------------- cookies ---------------------------------- */

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : undefined;
    }

    function setCookie(name, value, days) {
        var expires = new Date(Date.now() + days * 864e5).toUTCString();
        var cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax';
        if (location.protocol === 'https:') {
            cookie += '; Secure';
        }
        document.cookie = cookie;
    }

    /* ------------------------------- consent state -------------------------------- */

    function getConsent() {
        var raw = getCookie(CONSENT_COOKIE);
        if (raw) {
            try {
                return JSON.parse(raw);
            } catch (e) { /* fall through */ }
        }
        var legacy = getCookie(LEGACY_COOKIE);
        if (legacy !== undefined) {
            var allowed = legacy === '1' ? 'allow' : 'deny';
            return { functional: 'allow', statistics: allowed, marketing: allowed };
        }
        return null;
    }

    function hasDecision() {
        return getConsent() !== null;
    }

    // Manager mode: replay a legacy gdprConsent decision (no wp_consent_* yet)
    // into the API so wp_has_consent() agrees.
    function syncWpConsent() {
        if (!MANAGER_MODE || typeof window.wp_set_consent !== 'function') {
            return;
        }
        var consent = getConsent();
        if (!consent || getCookie('wp_consent_marketing') !== undefined) {
            return;
        }
        Object.keys(consent).forEach(function (category) {
            window.wp_set_consent(category, consent[category]);
        });
    }

    function saveConsent(statuses) {
        statuses.functional = 'allow';

        setCookie(CONSENT_COOKIE, JSON.stringify(statuses), COOKIE_DAYS);
        setCookie(LEGACY_COOKIE, statuses.marketing === 'allow' ? '1' : '0', COOKIE_DAYS);

        var bridge = MANAGER_MODE && typeof window.wp_set_consent === 'function';
        if (bridge) {
            // Push to the API; it writes wp_consent_* cookies + fires its own event.
            Object.keys(statuses).forEach(function (category) {
                window.wp_set_consent(category, statuses[category]);
            });
        } else {
            // Standalone: our own event drives capture start/stop.
            document.dispatchEvent(new CustomEvent('handl_consent_change', { detail: statuses }));
        }

        if (statuses.marketing !== 'allow' && typeof window.RemoveHandLCookies === 'function') {
            window.RemoveHandLCookies();
        }
    }

    /* ---------------------------------- theming ----------------------------------- */

    var THEMES = {
        light: {
            bg: '#ffffff', text: '#1f2937', border: '#e5e7eb',
            btn_bg: '#f3f4f6', btn_text: '#1f2937',
            accent_bg: '#16a34a', accent_text: '#ffffff'
        },
        dark: {
            bg: '#1f2937', text: '#f9fafb', border: '#374151',
            btn_bg: '#374151', btn_text: '#f9fafb',
            accent_bg: '#22c55e', accent_text: '#052e16'
        }
    };

    function resolveColors() {
        var base = THEMES[cfg.theme] || THEMES.light;
        var out = {};
        Object.keys(base).forEach(function (key) {
            out[key] = (cfg.colors && cfg.colors[key]) ? cfg.colors[key] : base[key];
        });
        return out;
    }

    /* ---------------------------------- rendering ---------------------------------- */

    var host = null;
    var shadow = null;

    function buildStyles(colors) {
        var radius = parseInt(cfg.radius, 10);
        if (isNaN(radius)) { radius = 8; }

        return [
            ':host { all: initial; }',
            '* { box-sizing: border-box; margin: 0; padding: 0; }',
            '.hcb { position: relative; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;',
            '  font-size: 14px; line-height: 1.5; color: ' + colors.text + ';',
            '  background: ' + colors.bg + '; border: 1px solid ' + colors.border + ';',
            '  border-radius: ' + radius + 'px; box-shadow: 0 8px 30px rgba(0,0,0,.18);',
            '  padding: 20px; width: 100%; pointer-events: auto; }',
            '.hcb--bar { border-radius: 0; border-left: 0; border-right: 0; border-bottom: 0; box-shadow: 0 -6px 24px rgba(0,0,0,.12); }',
            '.hcb__heading { font-size: 16px; font-weight: 600; margin-bottom: 6px; }',
            '.hcb__message { margin-bottom: 14px; opacity: .92; }',
            '.hcb__message a { color: inherit; text-decoration: underline; }',
            '.hcb__actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }',
            '.hcb__btn { font: inherit; font-weight: 600; cursor: pointer; border: 1px solid ' + colors.border + ';',
            '  background: ' + colors.btn_bg + '; color: ' + colors.btn_text + ';',
            '  padding: 9px 16px; border-radius: ' + Math.min(radius, 10) + 'px; transition: filter .15s ease; }',
            '.hcb__btn:hover { filter: brightness(.94); }',
            '.hcb__btn:focus-visible { outline: 2px solid ' + colors.accent_bg + '; outline-offset: 2px; }',
            '.hcb__btn--accept { background: ' + colors.accent_bg + '; color: ' + colors.accent_text + '; border-color: transparent; }',
            '.hcb__btn--link { background: transparent; border-color: transparent; font-weight: 500; text-decoration: underline; }',
            '.hcb__prefs { margin: 4px 0 14px; display: none; }',
            '.hcb__prefs.is-open { display: block; }',
            '.hcb__cat { display: flex; justify-content: space-between; align-items: center; gap: 12px;',
            '  padding: 10px 0; border-top: 1px solid ' + colors.border + '; }',
            '.hcb__cat:last-child { border-bottom: 1px solid ' + colors.border + '; }',
            '.hcb__cat-label { font-weight: 600; }',
            '.hcb__cat-desc { font-size: 12px; opacity: .75; }',
            '.hcb__toggle { position: relative; width: 40px; height: 22px; flex: 0 0 auto; border: 0; cursor: pointer;',
            '  border-radius: 11px; background: ' + colors.btn_bg + '; transition: background .15s ease; }',
            '.hcb__toggle::after { content: ""; position: absolute; top: 3px; left: 3px; width: 16px; height: 16px;',
            '  border-radius: 50%; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.3); transition: transform .15s ease; }',
            '.hcb__toggle[aria-checked="true"] { background: ' + colors.accent_bg + '; }',
            '.hcb__toggle[aria-checked="true"]::after { transform: translateX(18px); }',
            '.hcb__toggle[disabled] { opacity: .6; cursor: not-allowed; }',
            '.hcb__powered { margin-top: 10px; font-size: 11px; opacity: .7; }',
            '.hcb__powered a { color: inherit; text-decoration: underline; }',
            '.hcb-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); pointer-events: auto; }',
            '.hcb-wrap { position: fixed; z-index: 2147483000; pointer-events: none; }',
            '.hcb-wrap--bar { left: 0; right: 0; bottom: 0; }',
            '.hcb-wrap--corner-left { left: 16px; bottom: 16px; max-width: 380px; width: calc(100vw - 32px); }',
            '.hcb-wrap--corner-right { right: 16px; bottom: 16px; max-width: 380px; width: calc(100vw - 32px); }',
            '.hcb-wrap--modal { inset: 0; display: flex; align-items: center; justify-content: center; padding: 16px; }',
            '.hcb-wrap--modal .hcb { max-width: 480px; }',
            '.hcb-wrap--bar .hcb__inner { max-width: 1080px; margin: 0 auto; display: flex; flex-wrap: wrap; gap: 4px 24px; align-items: center; justify-content: space-between; }',
            '.hcb-wrap--bar .hcb__content { flex: 1 1 420px; }',
            '.hcb-wrap--bar .hcb__message { margin-bottom: 0; }',
            '.hcb-wrap--bar .hcb__prefs, .hcb-wrap--bar .hcb__actions { flex: 0 0 auto; }',
            '.hcb-wrap--bar .hcb__prefs { flex-basis: 100%; }',
            '.hcb-wrap--bar .hcb__powered { flex-basis: 100%; margin-top: 2px; text-align: right; }',
            '@media (max-width: 600px) { .hcb__actions { width: 100%; } .hcb__btn { flex: 1 1 auto; } }',
            '@keyframes hcb-in { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }',
            '.hcb { animation: hcb-in .25s ease; }'
        ].join('\n');
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text) { node.textContent = text; }
        return node;
    }

    function buildCategoryRow(category, state) {
        var row = el('div', 'hcb__cat');
        var info = el('div', '');
        info.appendChild(el('div', 'hcb__cat-label', category.label));
        if (category.description) {
            info.appendChild(el('div', 'hcb__cat-desc', category.description));
        }

        var toggle = el('button', 'hcb__toggle');
        toggle.type = 'button';
        toggle.setAttribute('role', 'switch');
        toggle.setAttribute('aria-label', category.label);
        toggle.setAttribute('aria-checked', state[category.id] === 'allow' ? 'true' : 'false');
        if (category.locked) {
            toggle.disabled = true;
        } else {
            toggle.addEventListener('click', function () {
                var next = state[category.id] === 'allow' ? 'deny' : 'allow';
                state[category.id] = next;
                toggle.setAttribute('aria-checked', next === 'allow' ? 'true' : 'false');
            });
        }

        row.appendChild(info);
        row.appendChild(toggle);
        return row;
    }

    // Free only: "Powered by UTM Grabber" under the buttons; cfg.powered_by is 0 when hidden.
    function buildPoweredBy() {
        var p = cfg.powered_by;
        if (!p || !p.url) {
            return null;
        }
        var wrap = el('div', 'hcb__powered');
        wrap.appendChild(document.createTextNode((p.label || 'Powered by') + ' '));
        var link = el('a', '', p.brand || 'UTM Grabber');
        link.href = p.url;
        link.target = '_blank';
        link.rel = 'nofollow noopener noreferrer';
        wrap.appendChild(link);
        return wrap;
    }

    function render() {
        if (host) {
            return;
        }

        var colors = resolveColors();
        var position = ['bar', 'corner-left', 'corner-right', 'modal'].indexOf(cfg.position) > -1 ? cfg.position : 'bar';

        host = document.createElement('div');
        host.id = 'handl-preferences-host';
        shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

        var style = document.createElement('style');
        style.textContent = buildStyles(colors);
        shadow.appendChild(style);

        var wrap = el('div', 'hcb-wrap hcb-wrap--' + position);

        if (position === 'modal') {
            wrap.appendChild(el('div', 'hcb-overlay'));
        }

        var banner = el('div', 'hcb' + (position === 'bar' ? ' hcb--bar' : ''));
        banner.setAttribute('role', 'dialog');
        banner.setAttribute('aria-modal', position === 'modal' ? 'true' : 'false');
        banner.setAttribute('aria-label', cfg.heading);

        var inner = el('div', 'hcb__inner');
        var content = el('div', 'hcb__content');
        if (cfg.heading) {
            content.appendChild(el('div', 'hcb__heading', cfg.heading));
        }
        var message = el('div', 'hcb__message', cfg.message);
        if (cfg.policy_url) {
            message.appendChild(document.createTextNode(' '));
            var link = el('a', '', cfg.policy_label || 'Privacy Policy');
            link.href = cfg.policy_url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            message.appendChild(link);
        }
        content.appendChild(message);
        inner.appendChild(content);

        // Preferences panel
        var state = { functional: 'allow', statistics: 'allow', marketing: 'allow' };
        var existing = getConsent();
        if (existing) {
            Object.keys(state).forEach(function (key) {
                if (existing[key]) { state[key] = existing[key]; }
            });
        }

        var prefs = el('div', 'hcb__prefs');
        (cfg.categories || []).forEach(function (category) {
            prefs.appendChild(buildCategoryRow(category, state));
        });
        inner.appendChild(prefs);

        // Actions
        var actions = el('div', 'hcb__actions');

        if (parseInt(cfg.show_prefs, 10)) {
            var prefsBtn = el('button', 'hcb__btn hcb__btn--link', cfg.prefs_label);
            prefsBtn.type = 'button';
            prefsBtn.setAttribute('aria-expanded', 'false');
            // First click opens the panel; once open, the same button saves the toggles.
            prefsBtn.addEventListener('click', function () {
                if (prefs.classList.contains('is-open')) {
                    decide(state);
                    return;
                }
                prefs.classList.add('is-open');
                prefsBtn.setAttribute('aria-expanded', 'true');
                prefsBtn.textContent = cfg.save_label;
                prefsBtn.className = 'hcb__btn';
            });
            actions.appendChild(prefsBtn);
        }

        if (parseInt(cfg.show_deny, 10)) {
            var denyBtn = el('button', 'hcb__btn', cfg.deny_label);
            denyBtn.type = 'button';
            denyBtn.addEventListener('click', function () {
                decide({ functional: 'allow', statistics: 'deny', marketing: 'deny' });
            });
            actions.appendChild(denyBtn);
        }

        var acceptBtn = el('button', 'hcb__btn hcb__btn--accept', cfg.accept_label);
        acceptBtn.type = 'button';
        acceptBtn.addEventListener('click', function () {
            decide({ functional: 'allow', statistics: 'allow', marketing: 'allow' });
        });
        actions.appendChild(acceptBtn);

        inner.appendChild(actions);

        var powered = buildPoweredBy();
        if (powered) {
            inner.appendChild(powered);
        }

        banner.appendChild(inner);
        wrap.appendChild(banner);
        shadow.appendChild(wrap);
        document.body.appendChild(host);

        if (position === 'modal') {
            acceptBtn.focus();
        }
    }

    function decide(statuses) {
        saveConsent(statuses);
        close();
    }

    function close() {
        if (host && host.parentNode) {
            host.parentNode.removeChild(host);
        }
        host = null;
        shadow = null;
    }

    function open() {
        close();
        render();
    }

    /* ------------------------------------ boot ------------------------------------ */

    function boot() {
        syncWpConsent();

        if (!hasDecision()) {
            render();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // Manager mode: mirror API consent changes from any source into our cookies
    // so the server-side gate stays in agreement.
    document.addEventListener('wp_listen_for_consent_change', function (event) {
        if (!MANAGER_MODE) {
            return;
        }
        var detail = event.detail || {};
        var consent = getConsent() || { functional: 'allow', statistics: 'deny', marketing: 'deny' };
        var changed = false;
        Object.keys(detail).forEach(function (category) {
            if (consent.hasOwnProperty(category) && consent[category] !== detail[category]) {
                consent[category] = detail[category];
                changed = true;
            }
        });
        if (changed) {
            consent.functional = 'allow';
            setCookie(CONSENT_COOKIE, JSON.stringify(consent), COOKIE_DAYS);
            setCookie(LEGACY_COOKIE, consent.marketing === 'allow' ? '1' : '0', COOKIE_DAYS);
        }
    });

    // Let site owners add a "Cookie settings" link anywhere.
    document.addEventListener('click', function (event) {
        var target = event.target && event.target.closest
            ? event.target.closest('[data-handl-consent-open], .handl-consent-open')
            : null;
        if (target) {
            event.preventDefault();
            open();
        }
    });

    window.HandLConsentBanner = {
        open: open,
        close: close,
        getConsent: getConsent
    };
})();
