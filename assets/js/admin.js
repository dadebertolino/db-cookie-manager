/**
 * DB Cookie Manager — Admin JS
 *
 * Funzionalità:
 *  - Conferma su form con [data-confirm-delete]
 *  - Copy-to-clipboard su [data-dbcm-copy="<selector>"]
 *  - Scanner: avvio scansione a 3 fasi (prepare → scan_url ×N → finalize)
 *    con progress bar e status a ogni step. Continua se una singola URL
 *    fallisce (timeout, 404).
 *  - Scanner: override categoria cookie via select inline
 *  - Scanner: delete cookie con conferma
 */

(function () {
    'use strict';

    var C = window.dbcmAdmin || {};
    var i18n = C.i18n || {};

    function t(key) {
        return i18n[key] || key;
    }

    /* =========================================================================
     * Conferma su form di delete
     * ========================================================================= */
    document.addEventListener('submit', function (ev) {
        var form = ev.target;
        if (!form.matches || !form.matches('[data-confirm-delete]')) return;
        // eslint-disable-next-line no-alert
        if (!window.confirm(t('confirmDelete'))) {
            ev.preventDefault();
        }
    }, true);

    /* =========================================================================
     * Copy-to-clipboard
     *
     * Markup atteso:
     *   <button data-dbcm-copy="#mio-textarea">Copia</button>
     *   <textarea id="mio-textarea">...</textarea>
     * ========================================================================= */
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest && ev.target.closest('[data-dbcm-copy]');
        if (!btn) return;
        ev.preventDefault();

        var sel = btn.getAttribute('data-dbcm-copy');
        var src = sel ? document.querySelector(sel) : null;
        if (!src) return;

        var text = ('value' in src) ? src.value : src.textContent;
        if (!text) return;

        // navigator.clipboard è preferibile ma richiede HTTPS o localhost.
        // Fallback: textarea + execCommand (deprecato ma funzionante ovunque).
        var doFallback = function () {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) { /* noop */ }
            document.body.removeChild(ta);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(doFallback);
        } else {
            doFallback();
        }

        // Feedback minimo: cambia label per 1.5s.
        var prev = btn.textContent;
        btn.textContent = t('copied');
        setTimeout(function () { btn.textContent = prev; }, 1500);
    });

    /* =========================================================================
     * Scanner: avvio scansione a 3 fasi (prepare → scan_url × N → finalize)
     *
     * Pattern: prepare ritorna l'array di URL, poi chiamiamo scan_url per
     * ognuna in sequenza aggiornando la progress bar, infine finalize.
     *
     * Errori AJAX → mostra db-ui-alert-danger e ferma il flusso.
     * ========================================================================= */

    function ajaxPost(action, data) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce',  C.scannerNonce || '');
        Object.keys(data || {}).forEach(function (k) {
            body.append(k, data[k]);
        });
        return fetch(C.ajaxUrl, {
            method:      'POST',
            body:        body,
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    var startBtn = document.getElementById('dbcm-scan-start');
    if (startBtn) {
        startBtn.addEventListener('click', function () {
            startBtn.disabled = true;

            var wrap   = document.getElementById('dbcm-scan-progress-wrap');
            var bar    = document.getElementById('dbcm-scan-progress-bar');
            var status = document.getElementById('dbcm-scan-status');
            var errBox = document.getElementById('dbcm-scan-error');
            var errMsg = document.getElementById('dbcm-scan-error-msg');

            if (errBox) errBox.style.display = 'none';
            if (wrap)   wrap.style.display = 'block';
            if (bar)    bar.style.width = '0%';
            if (status) status.textContent = t('scanInProgress');

            function fail(msg) {
                if (errBox && errMsg) {
                    errMsg.textContent = msg || t('scanError');
                    errBox.style.display = 'flex';
                }
                if (wrap)     wrap.style.display = 'none';
                startBtn.disabled = false;
            }

            ajaxPost('dbcm_scan_prepare', {}).then(function (res) {
                if (!res || !res.success) return fail();
                var urls  = res.data.urls || [];
                var total = urls.length || 1;
                var done  = 0;

                // Scansiona le URL in sequenza per non saturare il server.
                function next() {
                    if (done >= urls.length) {
                        return ajaxPost('dbcm_scan_finalize', {}).then(function (r2) {
                            if (!r2 || !r2.success) return fail();
                            if (status) status.textContent = t('scanComplete');
                            if (bar)    bar.style.width = '100%';
                            // Reload per mostrare i risultati nella stessa pagina.
                            setTimeout(function () {
                                window.location.reload();
                            }, 600);
                        }).catch(function () { fail(); });
                    }
                    var url = urls[done];
                    ajaxPost('dbcm_scan_url', { url: url }).then(function (r3) {
                        // Anche se fallisce su una URL singola continuiamo:
                        // un 404 o un timeout su una pagina non deve fermare
                        // tutta la scansione.
                        done++;
                        var pct = Math.round((done / total) * 100);
                        if (bar) bar.style.width = pct + '%';
                        if (status) status.textContent = 'Scansionando ' + done + '/' + total + ' (' + url + ')';
                        next();
                    }).catch(function () {
                        done++;
                        next();
                    });
                }
                next();
            }).catch(function () { fail(); });
        });
    }

    /* =========================================================================
     * Scanner: override categoria cookie
     * ========================================================================= */
    document.addEventListener('change', function (ev) {
        var sel = ev.target;
        if (!sel.matches || !sel.matches('.dbcm-cookie-cat')) return;

        var id = sel.getAttribute('data-cookie-id');
        var cat = sel.value;
        if (!id) return;

        sel.disabled = true;
        ajaxPost('dbcm_cookie_override', { id: id, category: cat }).then(function (res) {
            sel.disabled = false;
            if (!res || !res.success) {
                // eslint-disable-next-line no-alert
                window.alert('Errore nell\'aggiornamento della categoria.');
            }
        }).catch(function () {
            sel.disabled = false;
        });
    });

    /* =========================================================================
     * Scanner: delete cookie
     * ========================================================================= */
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest && ev.target.closest('.dbcm-cookie-delete');
        if (!btn) return;
        ev.preventDefault();
        // eslint-disable-next-line no-alert
        if (!window.confirm(t('confirmDelete'))) return;

        var id = btn.getAttribute('data-cookie-id');
        if (!id) return;

        btn.disabled = true;
        ajaxPost('dbcm_cookie_delete', { id: id }).then(function (res) {
            if (res && res.success) {
                var row = btn.closest('tr');
                if (row && row.parentNode) row.parentNode.removeChild(row);
            } else {
                btn.disabled = false;
            }
        }).catch(function () {
            btn.disabled = false;
        });
    });

})();
