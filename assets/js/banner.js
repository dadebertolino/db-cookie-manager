/**
 * DB Cookie Manager — Banner frontend
 *
 * Versione 3.0.0 — riscrittura completa:
 *  - Cookie scrive SOLO chiavi WP Consent API standard
 *      (functional, preferences, statistics, statistics-anonymous, marketing)
 *  - Schema versionato: {v:3, ...}. Cookie con altri schemi vengono ignorati.
 *  - API pubblica window.DBCM esposta a sviluppatori esterni.
 *  - Evento 'dbcm:consent' emesso su document a ogni cambio scelta.
 *  - Sincronizzazione AJAX con il server (DBCM_Consent_API → wp_set_consent).
 *
 * Markup: niente template HTML lato PHP. Il banner viene costruito in JS
 * dentro #dbcm-banner-root, così è facile da personalizzare via filtri JS
 * e l'invio del CSS resta indipendente dalla logica di rendering.
 *
 * @package db-cookie-manager
 */

(function () {
    'use strict';

    var C = window.dbcmBanner || {};
    if (!C.cookieName) {
        // Config non disponibile: il PHP non ha eseguito wp_localize_script.
        // Esci silenzioso per non spammare la console.
        return;
    }

    var COOKIE_NAME    = C.cookieName;
    var COOKIE_SCHEMA  = C.cookieSchema || 3;
    var ALL_CATEGORIES = C.categories || ['functional', 'preferences', 'statistics', 'statistics-anonymous', 'marketing'];
    var OPT_CATEGORIES = C.categoriesOptional || ['preferences', 'statistics', 'statistics-anonymous', 'marketing'];
    var ROOT_ID        = 'dbcm-banner-root';

    /* =========================================================================
     * I18N
     * ========================================================================= */

    var currentLang = detectLanguage();
    var translations = (C.translations && C.translations[currentLang]) || {};
    var fallback     = (C.translations && C.translations[C.defaultLang || 'it']) || {};

    function detectLanguage() {
        var active = C.activeLangs || ['it'];
        var def    = C.defaultLang || 'it';
        var browser = (navigator.language || navigator.userLanguage || '').substring(0, 2).toLowerCase();
        return active.indexOf(browser) !== -1 ? browser : def;
    }

    function T(key) {
        return translations[key] || fallback[key] || '';
    }

    /* =========================================================================
     * COOKIE I/O
     * ========================================================================= */

    /**
     * Legge il cookie del banner.
     * Restituisce null se assente, malformato, o con schema diverso da quello
     * corrente. Tornare null fa ri-mostrare il banner all'utente.
     */
    function readCookie() {
        var match = document.cookie.match('(^|;)\\s*' + COOKIE_NAME + '=([^;]*)');
        if (!match) return null;
        try {
            var data = JSON.parse(decodeURIComponent(match[2]));
            if (!data || data.v !== COOKIE_SCHEMA) return null;
            return normalize(data);
        } catch (e) {
            return null;
        }
    }

    /**
     * Forza la presenza di tutte le 5 chiavi (default false) e di functional=true.
     */
    function normalize(data) {
        var out = { v: COOKIE_SCHEMA, ts: data.ts || Date.now(), type: data.type || 'custom' };
        ALL_CATEGORIES.forEach(function (cat) {
            out[cat] = !!data[cat];
        });
        out.functional = true;
        return out;
    }

    /**
     * Scrive il cookie e lo restituisce normalizzato.
     */
    function writeCookie(consent, type) {
        var data = normalize({
            v:    COOKIE_SCHEMA,
            ts:   Date.now(),
            type: type || 'custom'
        });
        ALL_CATEGORIES.forEach(function (cat) {
            data[cat] = !!consent[cat];
        });
        data.functional = true;

        var expires = new Date();
        expires.setDate(expires.getDate() + parseInt(C.consentDuration || 365, 10));

        document.cookie = COOKIE_NAME + '=' + encodeURIComponent(JSON.stringify(data))
            + ';expires=' + expires.toUTCString()
            + ';path=/;SameSite=Lax'
            + (location.protocol === 'https:' ? ';Secure' : '');

        return data;
    }

    /* =========================================================================
     * SERVER SYNC + EVENT DISPATCH
     * ========================================================================= */

    /**
     * Notifica il server (DBCM_Consent_API::ajax_set_consent) della scelta
     * dell'utente. Lato server vengono invocate wp_set_consent() per ogni
     * categoria → tutti gli altri plugin con WP Consent API ricevono i valori
     * aggiornati al prossimo page-load (e in alcuni casi anche subito).
     */
    function syncWithServer(consent, type) {
        if (!C.ajaxUrl || !C.nonce) return Promise.resolve();

        var body = new FormData();
        body.append('action',  'dbcm_set_consent');
        body.append('nonce',   C.nonce);
        body.append('type',    type || 'custom');
        body.append('consent', JSON.stringify(stripMeta(consent)));

        return fetch(C.ajaxUrl, {
            method:      'POST',
            body:        body,
            credentials: 'same-origin'
        }).catch(function () { /* silent */ });
    }

    /**
     * Rimuove le chiavi meta (v, ts, type) dal payload prima di inviarlo
     * al server: lì interessano solo i boolean delle categorie.
     */
    function stripMeta(consent) {
        var out = {};
        ALL_CATEGORIES.forEach(function (cat) {
            out[cat] = !!consent[cat];
        });
        return out;
    }

    /**
     * Emette il custom event 'dbcm:consent' su document.
     * Permette ad altri script (snippet inline, integrazioni custom) di
     * reagire immediatamente al cambio di consenso senza dover fare
     * polling sul cookie.
     */
    function dispatchConsentEvent(consent, type) {
        try {
            var ev = new CustomEvent('dbcm:consent', {
                detail: { type: type, consent: stripMeta(consent) }
            });
            document.dispatchEvent(ev);
        } catch (e) { /* old browser */ }
    }

    /* =========================================================================
     * RENDERING
     * ========================================================================= */

    function getRoot() {
        var root = document.getElementById(ROOT_ID);
        if (!root) {
            // Se il PHP non ha stampato il root, lo creiamo al volo.
            root = document.createElement('div');
            root.id = ROOT_ID;
            document.body.appendChild(root);
        }
        return root;
    }

    function clearRoot() {
        var root = getRoot();
        while (root.firstChild) root.removeChild(root.firstChild);
    }

    function el(tag, attrs, children) {
        var n = document.createElement(tag);
        if (attrs) {
            Object.keys(attrs).forEach(function (k) {
                if (k === 'className')      n.className = attrs[k];
                else if (k === 'text')      n.textContent = attrs[k];
                else if (k.indexOf('on') === 0) n.addEventListener(k.substring(2).toLowerCase(), attrs[k]);
                else                        n.setAttribute(k, attrs[k]);
            });
        }
        if (children) {
            (Array.isArray(children) ? children : [children]).forEach(function (c) {
                if (c == null) return;
                n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
            });
        }
        return n;
    }

    /**
     * Banner principale (vista compatta).
     */
    function renderBanner() {
        clearRoot();

        var box = el('div', {
            className: 'dbcm-banner dbcm-banner--' + (C.layout || 'box') + ' dbcm-banner--' + (C.position || 'bottom-right'),
            role: 'dialog',
            'aria-modal': 'false',
            'aria-labelledby': 'dbcm-banner-title',
            'aria-describedby': 'dbcm-banner-msg'
        }, [
            el('h2', { id: 'dbcm-banner-title', className: 'dbcm-banner__title', text: T('title') }),
            el('p',  { id: 'dbcm-banner-msg', className: 'dbcm-banner__msg', text: T('message') }),
            renderPolicyLink(),
            el('div', { className: 'dbcm-banner__actions' }, [
                el('button', {
                    type: 'button',
                    className: 'dbcm-btn dbcm-btn--ghost',
                    text: T('customize'),
                    onClick: openPreferences
                }),
                el('button', {
                    type: 'button',
                    className: 'dbcm-btn dbcm-btn--secondary',
                    text: T('reject_all'),
                    onClick: handleRejectAll
                }),
                el('button', {
                    type: 'button',
                    className: 'dbcm-btn dbcm-btn--primary',
                    text: T('accept_all'),
                    onClick: handleAcceptAll
                })
            ])
        ]);

        if (C.overlay) {
            getRoot().appendChild(el('div', { className: 'dbcm-overlay', onClick: function () {} }));
        }
        getRoot().appendChild(box);
    }

    function renderPolicyLink() {
        if (!C.policyUrl) return null;
        return el('p', { className: 'dbcm-banner__policy' }, [
            el('a', { href: C.policyUrl, target: '_blank', rel: 'noopener', text: T('policy_link') })
        ]);
    }

    /**
     * Modal preferenze (vista espansa con i toggle delle 4 categorie opzionali).
     */
    function renderPreferences(currentChoice) {
        clearRoot();

        var saved = currentChoice || readCookie() || (C.defaults || {});

        var rows = OPT_CATEGORIES.map(function (cat) {
            var checked = !!saved[cat];
            return el('div', { className: 'dbcm-pref__row', 'data-category': cat }, [
                el('div', { className: 'dbcm-pref__info' }, [
                    el('div', { className: 'dbcm-pref__label', text: T('cat_' + cat) }),
                    el('div', { className: 'dbcm-pref__desc',  text: T('cat_' + cat + '_desc') })
                ]),
                el('label', { className: 'dbcm-toggle' }, [
                    el('input', {
                        type: 'checkbox',
                        className: 'dbcm-toggle__input',
                        'data-category': cat,
                        checked: checked ? 'checked' : null
                    }),
                    el('span', { className: 'dbcm-toggle__slider' })
                ])
            ]);
        });

        // Riga 'functional' fissa, sempre attiva, non interattiva.
        var functionalRow = el('div', { className: 'dbcm-pref__row dbcm-pref__row--locked' }, [
            el('div', { className: 'dbcm-pref__info' }, [
                el('div', { className: 'dbcm-pref__label', text: T('cat_functional') }),
                el('div', { className: 'dbcm-pref__desc',  text: T('cat_functional_desc') })
            ]),
            el('span', { className: 'dbcm-toggle dbcm-toggle--locked', 'aria-disabled': 'true' }, [
                el('span', { className: 'dbcm-toggle__slider dbcm-toggle__slider--on' })
            ])
        ]);

        var box = el('div', {
            className: 'dbcm-banner dbcm-banner--preferences',
            role: 'dialog',
            'aria-modal': 'true',
            'aria-labelledby': 'dbcm-pref-title'
        }, [
            el('h2', { id: 'dbcm-pref-title', className: 'dbcm-banner__title', text: T('customize') }),
            el('div', { className: 'dbcm-pref__list' }, [functionalRow].concat(rows)),
            el('div', { className: 'dbcm-banner__actions' }, [
                el('button', {
                    type: 'button',
                    className: 'dbcm-btn dbcm-btn--secondary',
                    text: T('reject_all'),
                    onClick: handleRejectAll
                }),
                el('button', {
                    type: 'button',
                    className: 'dbcm-btn dbcm-btn--primary',
                    text: T('save'),
                    onClick: handleSaveCustom
                })
            ])
        ]);

        getRoot().appendChild(el('div', { className: 'dbcm-overlay' }));
        getRoot().appendChild(box);
    }

    function close() {
        clearRoot();
        if (C.showReopenBtn) renderReopenButton();
    }

    function renderReopenButton() {
        var btn = el('button', {
            type: 'button',
            className: 'dbcm-reopen dbcm-reopen--' + (C.reopenPosition || 'bottom-left'),
            'aria-label': T('reopen'),
            title: T('reopen'),
            text: '🍪',
            onClick: openPreferences
        });
        getRoot().appendChild(btn);
    }

    /* =========================================================================
     * ACTIONS
     * ========================================================================= */

    function handleAcceptAll() {
        var consent = {};
        ALL_CATEGORIES.forEach(function (cat) { consent[cat] = true; });
        commit(consent, 'accept_all');
    }

    function handleRejectAll() {
        var consent = { functional: true };
        OPT_CATEGORIES.forEach(function (cat) { consent[cat] = false; });
        commit(consent, 'reject_all');
    }

    function handleSaveCustom() {
        var consent = { functional: true };
        var inputs = document.querySelectorAll('.dbcm-toggle__input[data-category]');
        OPT_CATEGORIES.forEach(function (cat) { consent[cat] = false; });
        Array.prototype.forEach.call(inputs, function (input) {
            var cat = input.getAttribute('data-category');
            if (OPT_CATEGORIES.indexOf(cat) !== -1) {
                consent[cat] = !!input.checked;
            }
        });
        commit(consent, 'custom');
    }

    /**
     * Pipeline completa: scrive il cookie, sincronizza col server, riattiva
     * gli script bloccati e ripristina gli iframe per le categorie concesse,
     * emette l'evento, chiude il banner.
     */
    function commit(consent, type) {
        var written = writeCookie(consent, type);
        syncWithServer(written, type);
        activateBlockedScripts(written);
        restoreBlockedIframes(written);
        dispatchConsentEvent(written, type);
        close();
    }

    function openPreferences() {
        renderPreferences(readCookie());
    }

    /* =========================================================================
     * RIATTIVAZIONE — script bloccati & iframe placeholder
     *
     * Il blocker PHP (DBCM_Blocker) neutralizza preventivamente gli script
     * di tracking impostando type="text/plain" + data-dbcm-blocked="true".
     * Questo non li fa eseguire al browser. Quando l'utente concede una
     * categoria, dobbiamo:
     *   1. Clonare ogni <script data-dbcm-blocked> con type rimosso →
     *      il browser lo esegue.
     *   2. Sostituire ogni .dbcm-iframe-placeholder con il vero <iframe>
     *      ricostruito dai data-* salvati.
     * ========================================================================= */

    /**
     * Riattiva tutti gli script bloccati per le categorie concesse.
     *
     * Cambiare type="text/plain" → "text/javascript" su un tag già nel
     * DOM NON causa la ri-esecuzione: serve creare un nuovo elemento.
     * Copia tutti gli attributi originali tranne type e data-dbcm-*.
     */
    function activateBlockedScripts(consent) {
        if (!consent) return;
        var blocked = document.querySelectorAll('script[data-dbcm-blocked="true"]');
        Array.prototype.forEach.call(blocked, function (oldScript) {
            var category = oldScript.getAttribute('data-dbcm-category');
            if (!category || !consent[category]) return;

            var newScript = document.createElement('script');
            // Copia attributi (esclusi gli interni del blocker e il type).
            Array.prototype.forEach.call(oldScript.attributes, function (attr) {
                if (attr.name === 'type'
                    || attr.name === 'data-dbcm-blocked'
                    || attr.name === 'data-dbcm-category') {
                    return;
                }
                newScript.setAttribute(attr.name, attr.value);
            });
            // Forza il type corretto (lo lasciamo esplicito per chiarezza).
            newScript.type = 'text/javascript';
            // Inline content (se presente): copialo prima dell'append così
            // il browser lo esegue al momento dell'inserimento nel DOM.
            if (oldScript.textContent) {
                newScript.textContent = oldScript.textContent;
            }
            if (oldScript.parentNode) {
                oldScript.parentNode.replaceChild(newScript, oldScript);
            }
        });
    }

    /**
     * Sostituisce ogni placeholder iframe con il vero <iframe> per le
     * categorie concesse.
     *
     * I data attribute sul placeholder contengono src e (in futuro) gli
     * altri attributi dell'iframe originale. Per lo step 3 ricostruiamo
     * un iframe pulito con src + width/height inferiti dallo style.
     */
    function restoreBlockedIframes(consent) {
        if (!consent) return;
        var placeholders = document.querySelectorAll('.dbcm-iframe-placeholder');
        Array.prototype.forEach.call(placeholders, function (ph) {
            var category = ph.getAttribute('data-dbcm-category');
            var src      = ph.getAttribute('data-dbcm-src');
            if (!category || !src || !consent[category]) return;

            var iframe = document.createElement('iframe');
            iframe.setAttribute('src', src);
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute('allowfullscreen', '');
            iframe.setAttribute('loading', 'lazy');

            // Eredita le dimensioni del placeholder per evitare layout shift.
            if (ph.style.width)  iframe.style.width  = ph.style.width;
            if (ph.style.height) iframe.style.height = ph.style.height;
            iframe.style.maxWidth = '100%';
            iframe.style.border = '0';

            if (ph.parentNode) {
                ph.parentNode.replaceChild(iframe, ph);
            }
        });
    }

    /* =========================================================================
     * PUBLIC API — window.DBCM
     * ========================================================================= */

    /**
     * API pubblica esposta a sviluppatori che vogliono integrare con il
     * banner senza dover riparsare il cookie a mano.
     *
     * Esempi d'uso:
     *   if (window.DBCM.hasConsent('statistics')) { ga('send', ...); }
     *   document.querySelector('#privacy-link').onclick = window.DBCM.openPreferences;
     *   window.DBCM.onConsent(function (e) { console.log(e.detail.consent); });
     */
    window.DBCM = {
        /**
         * @param {string} category — categoria WP Consent API standard.
         * @returns {boolean}
         */
        hasConsent: function (category) {
            if (category === 'functional') return true;
            var c = readCookie();
            return c ? !!c[category] : false;
        },

        /**
         * @returns {object|null} Snapshot del consenso (con meta) o null.
         */
        getConsent: function () {
            return readCookie();
        },

        /**
         * Imposta programmaticamente il consenso.
         * @param {object} consent — mappa categoria → bool.
         * @param {string} [type='custom']
         */
        setConsent: function (consent, type) {
            commit(consent || {}, type || 'custom');
        },

        /**
         * Apre il modal preferenze.
         */
        openPreferences: openPreferences,

        /**
         * Sottoscrive un listener al change del consenso.
         * @param {Function} cb — riceve il CustomEvent.
         * @returns {Function} unsubscribe
         */
        onConsent: function (cb) {
            document.addEventListener('dbcm:consent', cb);
            return function () { document.removeEventListener('dbcm:consent', cb); };
        },

        /**
         * Lista delle categorie standard supportate.
         */
        categories: ALL_CATEGORIES.slice()
    };

    /* =========================================================================
     * BOOT
     * ========================================================================= */

    /**
     * Determina se il browser sta inviando un segnale di opt-out
     * (Do Not Track o Global Privacy Control).
     *
     * GPC (Global Privacy Control) è il successore moderno di DNT,
     * supportato da Firefox/Brave/DuckDuckGo. La spec dice che 'sec-gpc:1'
     * deve essere trattato come "rifiuto del trattamento per finalità
     * di vendita o condivisione" — concretamente, opt-out delle categorie
     * opzionali è la lettura più conservativa.
     *
     * DNT è oggi poco rispettato (Apple Safari l'ha rimosso, Chrome non
     * l'ha mai mandato di default) ma alcune configurazioni privacy lo
     * abilitano comunque.
     *
     * @returns {string|null} 'gpc' | 'dnt' | null
     */
    function detectOptOutSignal() {
        if (C.respectGpc && navigator.globalPrivacyControl === true) {
            return 'gpc';
        }
        if (C.respectDnt) {
            // navigator.doNotTrack è '1' (opt-out), '0' (opt-in), null (no signal).
            // window.doNotTrack su IE legacy. msDoNotTrack su IE10+.
            var dnt = navigator.doNotTrack
                || window.doNotTrack
                || navigator.msDoNotTrack;
            if (dnt === '1' || dnt === 'yes') {
                return 'dnt';
            }
        }
        return null;
    }

    /**
     * Costruisce un payload di consenso "rifiuta tutto" in cui solo
     * 'functional' è true. Stessa forma del payload prodotto da reject_all
     * nel modal preferenze.
     */
    function buildRejectAllConsent() {
        var consent = { functional: true };
        var optionals = (C.categoriesOptional || []).slice();
        for (var i = 0; i < optionals.length; i++) {
            consent[optionals[i]] = false;
        }
        return consent;
    }

    function boot() {
        // Se esiste già un consenso salvato, riattiva subito gli script
        // bloccati e ripristina gli iframe per le categorie concesse.
        // Senza questo, dopo un page reload gli script tracking resterebbero
        // con type="text/plain" per sempre.
        var existing = readCookie();
        if (existing) {
            activateBlockedScripts(existing);
            restoreBlockedIframes(existing);
            // Già ha un cookie → non mostriamo il banner; il signal DNT/GPC
            // non override le scelte dell'utente già espresse.
            if (C.showReopenBtn) {
                renderReopenButton();
            }
            return;
        }

        // Check DNT/GPC: se attivo e nessun cookie esistente, scrivi
        // automaticamente "rifiuta tutto", non mostrare il banner, ma
        // mostra comunque il pulsante "Riapri preferenze" così l'utente
        // può cambiare idea esplicitamente in seguito.
        var signal = detectOptOutSignal();
        if (signal) {
            commit(buildRejectAllConsent(), 'reject_all');
            if (C.showReopenBtn) {
                renderReopenButton();
            }
            return;
        }

        if (C.autoOpen) {
            renderBanner();
        } else if (C.showReopenBtn) {
            renderReopenButton();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

})();
