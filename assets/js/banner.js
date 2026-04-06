/**
 * DB Cookie Manager - Banner Frontend
 *
 * @package db-cookie-manager
 */

(function () {
    'use strict';

    var C = window.dbcmBanner || {};
    var COOKIE_NAME = 'dbcm_consent';

    // =========================================================================
    // LANGUAGE DETECTION
    // =========================================================================

    function detectLanguage() {
        var activeLangs = C.activeLangs || ['it'];
        var defaultLang = C.defaultLang || 'it';

        // Get browser language (e.g. "it-IT" -> "it", "en-US" -> "en")
        var browserLang = ( navigator.language || navigator.userLanguage || '' ).substring( 0, 2 ).toLowerCase();

        // Check if browser language is in active languages
        if ( activeLangs.indexOf( browserLang ) !== -1 ) {
            return browserLang;
        }

        return defaultLang;
    }

    var currentLang = detectLanguage();
    var translations = ( C.translations && C.translations[ currentLang ] ) || {};
    var fallback = ( C.translations && C.translations[ C.defaultLang || 'it' ] ) || {};

    /**
     * Get translated text by key
     */
    function T( key ) {
        return translations[ key ] || fallback[ key ] || '';
    }

    // =========================================================================
    // COOKIE HELPERS
    // =========================================================================

    function getConsent() {
        var match = document.cookie.match( '(^|;)\\s*' + COOKIE_NAME + '=([^;]*)' );
        if ( ! match ) return null;
        try {
            return JSON.parse( decodeURIComponent( match[2] ) );
        } catch ( e ) {
            return null;
        }
    }

    function setConsent( categories ) {
        var data = {
            necessary: true, // always true
            performance: !! categories.performance,
            analytics: !! categories.analytics,
            marketing: !! categories.marketing,
            timestamp: Date.now(),
            version: '2.0.1'
        };
        var expires = new Date();
        expires.setDate( expires.getDate() + parseInt( C.consentDuration || 365, 10 ) );
        document.cookie = COOKIE_NAME + '=' + encodeURIComponent( JSON.stringify( data ) )
            + ';expires=' + expires.toUTCString()
            + ';path=/;SameSite=Lax';
        return data;
    }

    function hasConsent() {
        return getConsent() !== null;
    }

    // =========================================================================
    // DOM HELPERS
    // =========================================================================

    function el( tag, attrs, children ) {
        var node = document.createElement( tag );
        if ( attrs ) {
            Object.keys( attrs ).forEach( function ( key ) {
                if ( key === 'className' ) {
                    node.className = attrs[key];
                } else if ( key === 'style' && typeof attrs[key] === 'object' ) {
                    Object.keys( attrs[key] ).forEach( function ( prop ) {
                        node.style[prop] = attrs[key][prop];
                    } );
                } else if ( key.indexOf( 'on' ) === 0 ) {
                    node.addEventListener( key.substring(2).toLowerCase(), attrs[key] );
                } else {
                    node.setAttribute( key, attrs[key] );
                }
            } );
        }
        if ( children ) {
            if ( typeof children === 'string' ) {
                node.textContent = children;
            } else if ( Array.isArray( children ) ) {
                children.forEach( function ( child ) {
                    if ( child ) node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
                } );
            } else {
                node.appendChild( children );
            }
        }
        return node;
    }

    // =========================================================================
    // BANNER RENDERING
    // =========================================================================

    function buildBanner() {
        var wrap = document.getElementById( 'dbcm-banner-wrap' );
        if ( ! wrap ) return;

        // Inject custom CSS if provided
        if ( C.customCss ) {
            var styleEl = document.createElement( 'style' );
            styleEl.textContent = C.customCss;
            document.head.appendChild( styleEl );
        }

        // Overlay
        var overlay = el( 'div', { className: 'dbcm-overlay', onClick: function () {} } );
        if ( ! C.overlay ) overlay.style.display = 'none';

        // Layout classes
        var layoutClass = 'dbcm-banner dbcm-banner--' + ( C.layout || 'bar' ) + ' dbcm-banner--' + ( C.position || 'bottom' );

        // Policy link
        var policyLink = null;
        if ( C.policyUrl ) {
            policyLink = el( 'a', {
                href: C.policyUrl,
                className: 'dbcm-banner__policy-link',
                target: '_blank',
                rel: 'noopener'
            }, T('textPolicyLink') || 'Cookie Policy' );
        }

        // Description with optional policy link
        var descContent = el( 'div', { className: 'dbcm-banner__desc' } );
        descContent.innerHTML = escHtml( T('textDescription') || '' );
        if ( policyLink ) {
            descContent.appendChild( document.createTextNode( ' ' ) );
            descContent.appendChild( policyLink );
        }

        // Buttons
        var btnAccept = el( 'button', {
            className: 'dbcm-btn dbcm-btn--accept',
            onClick: function () { acceptAll(); }
        }, T('textAcceptAll') || 'Accetta tutto' );

        var btnReject = el( 'button', {
            className: 'dbcm-btn dbcm-btn--reject',
            onClick: function () { rejectAll(); }
        }, T('textRejectAll') || 'Solo necessari' );

        var btnCustomize = el( 'button', {
            className: 'dbcm-btn dbcm-btn--customize',
            onClick: function () { showDetails(); }
        }, T('textCustomize') || 'Personalizza' );

        // Banner structure
        var banner = el( 'div', {
            className: layoutClass,
            role: 'dialog',
            'aria-label': T('textTitle') || 'Cookie consent',
            'aria-modal': 'false'
        }, [
            el( 'div', { className: 'dbcm-banner__inner' }, [
                el( 'div', { className: 'dbcm-banner__content' }, [
                    el( 'div', { className: 'dbcm-banner__title' }, T('textTitle') || '' ),
                    descContent
                ] ),
                el( 'div', { className: 'dbcm-banner__actions' }, [
                    btnAccept,
                    btnReject,
                    btnCustomize
                ] ),
                C.showCredits
                    ? el( 'div', { className: 'dbcm-banner__credits' }, [
                        el( 'a', {
                            href: 'https://github.com/dadebertolino/db-cookie-manager',
                            target: '_blank',
                            rel: 'noopener noreferrer',
                            className: 'dbcm-banner__credits-link'
                        }, 'Powered by DB Cookie Manager' )
                    ] )
                    : null
            ] )
        ] );

        // Details panel (hidden by default)
        var detailsPanel = buildDetailsPanel();

        // Apply colors as CSS custom properties
        wrap.style.setProperty( '--dbcm-bg', C.colorBg || '#1e293b' );
        wrap.style.setProperty( '--dbcm-text', C.colorText || '#f8fafc' );
        wrap.style.setProperty( '--dbcm-btn', C.colorBtn || '#2563eb' );
        wrap.style.setProperty( '--dbcm-btn-text', C.colorBtnText || '#ffffff' );

        // Apply theme class
        if ( C.theme === 'light' ) {
            wrap.classList.add( 'dbcm-theme-light' );
        }

        // Apply same theme to reopen wrap
        var reopenWrap = document.getElementById( 'dbcm-reopen-wrap' );
        if ( reopenWrap ) {
            reopenWrap.style.setProperty( '--dbcm-bg', C.colorBg || '#1e293b' );
            reopenWrap.style.setProperty( '--dbcm-text', C.colorText || '#f8fafc' );
            reopenWrap.style.setProperty( '--dbcm-btn', C.colorBtn || '#2563eb' );
            if ( C.theme === 'light' ) {
                reopenWrap.classList.add( 'dbcm-theme-light' );
            }
        }

        wrap.appendChild( overlay );
        wrap.appendChild( banner );
        wrap.appendChild( detailsPanel );

        return wrap;
    }

    // =========================================================================
    // DETAILS PANEL (Personalizza)
    // =========================================================================

    function buildDetailsPanel() {
        var cats = [
            {
                key: 'necessary',
                label: T('textCatNecessary') || 'Necessari',
                desc: T('textCatNecessaryDesc') || '',
                locked: true,
                checked: true
            },
            {
                key: 'performance',
                label: T('textCatPerformance') || 'Prestazioni',
                desc: T('textCatPerformanceDesc') || '',
                locked: false,
                checked: !! C.defaultPerformance
            },
            {
                key: 'analytics',
                label: T('textCatAnalytics') || 'Analitici',
                desc: T('textCatAnalyticsDesc') || '',
                locked: false,
                checked: !! C.defaultAnalytics
            },
            {
                key: 'marketing',
                label: T('textCatMarketing') || 'Marketing',
                desc: T('textCatMarketingDesc') || '',
                locked: false,
                checked: !! C.defaultMarketing
            }
        ];

        var catElements = cats.map( function ( cat ) {
            // Toggle
            var toggleId = 'dbcm-toggle-' + cat.key;
            var checkbox = el( 'input', {
                type: 'checkbox',
                id: toggleId,
                className: 'dbcm-toggle__input',
                'data-category': cat.key
            } );
            checkbox.checked = cat.checked;
            if ( cat.locked ) {
                checkbox.disabled = true;
                checkbox.checked = true;
            }

            var toggle = el( 'label', { className: 'dbcm-toggle', 'for': toggleId }, [
                checkbox,
                el( 'span', { className: 'dbcm-toggle__slider' } )
            ] );

            // Cookie list for this category
            var cookieItems = ( C.cookieList && C.cookieList[ cat.key ] ) || [];
            var cookieTable = null;
            if ( cookieItems.length > 0 ) {
                var rows = cookieItems.map( function ( ck ) {
                    return el( 'tr', {}, [
                        el( 'td', { className: 'dbcm-details__cookie-name' }, ck.name ),
                        el( 'td', {}, ck.provider || '' ),
                        el( 'td', {}, ck.duration || '' )
                    ] );
                } );

                cookieTable = el( 'div', { className: 'dbcm-details__cookies' }, [
                    el( 'table', { className: 'dbcm-details__table' }, [
                        el( 'thead', {}, [
                            el( 'tr', {}, [
                                el( 'th', {}, 'Cookie' ),
                                el( 'th', {}, 'Fornitore' ),
                                el( 'th', {}, 'Durata' )
                            ] )
                        ] ),
                        el( 'tbody', {}, rows )
                    ] )
                ] );
            }

            // Expandable category row
            var headerRow = el( 'div', { className: 'dbcm-details__cat-header' }, [
                el( 'div', { className: 'dbcm-details__cat-info' }, [
                    el( 'div', { className: 'dbcm-details__cat-title' }, [
                        el( 'span', {}, cat.label ),
                        cat.locked
                            ? el( 'span', { className: 'dbcm-details__always-on' }, T('textAlwaysOn') || 'Sempre attivi' )
                            : null,
                        cookieItems.length > 0
                            ? el( 'span', {
                                className: 'dbcm-details__expand',
                                onClick: function () { toggleCookieList( this ); }
                              }, '▸ ' + cookieItems.length + ' cookie' )
                            : null
                    ] ),
                    el( 'p', { className: 'dbcm-details__cat-desc' }, cat.desc )
                ] ),
                toggle
            ] );

            return el( 'div', { className: 'dbcm-details__cat' }, [
                headerRow,
                cookieTable
            ] );
        } );

        // Save button
        var btnSave = el( 'button', {
            className: 'dbcm-btn dbcm-btn--accept',
            onClick: function () { saveCustom(); }
        }, T('textSave') || 'Salva preferenze' );

        // Close button
        var btnClose = el( 'button', {
            className: 'dbcm-btn dbcm-btn--reject',
            onClick: function () { hideDetails(); }
        }, T('textClose') || 'Chiudi' );

        var panel = el( 'div', {
            className: 'dbcm-details',
            id: 'dbcm-details',
            role: 'dialog',
            'aria-modal': 'true',
            'aria-label': T('textCustomize') || 'Personalizza'
        }, [
            el( 'div', { className: 'dbcm-details__inner' }, [
                el( 'div', { className: 'dbcm-details__header' }, [
                    el( 'h3', { className: 'dbcm-details__title' }, T('textCustomize') || 'Personalizza' ),
                    el( 'button', {
                        className: 'dbcm-details__close',
                        onClick: function () { hideDetails(); },
                        'aria-label': 'Chiudi'
                    }, '✕' )
                ] ),
                el( 'div', { className: 'dbcm-details__body' }, catElements ),
                el( 'div', { className: 'dbcm-details__footer' }, [ btnSave, btnClose ] )
            ] )
        ] );

        return panel;
    }

    function toggleCookieList( trigger ) {
        var cat = trigger.closest( '.dbcm-details__cat' );
        var list = cat.querySelector( '.dbcm-details__cookies' );
        if ( ! list ) return;

        var isOpen = list.classList.contains( 'is-open' );
        list.classList.toggle( 'is-open' );
        trigger.textContent = ( isOpen ? '▸ ' : '▾ ' ) + ( C.cookieList ? '' : '' );

        // Update text with count
        var table = list.querySelector( 'tbody' );
        var count = table ? table.children.length : 0;
        trigger.textContent = ( isOpen ? '▸ ' : '▾ ' ) + count + ' cookie';
    }

    // =========================================================================
    // REOPEN BUTTON
    // =========================================================================

    function buildReopenButton() {
        if ( ! C.showReopen ) return;

        var reopenWrap = document.getElementById( 'dbcm-reopen-wrap' );
        if ( ! reopenWrap ) return;

        var posClass = 'dbcm-reopen dbcm-reopen--' + ( C.reopenPosition || 'bottom-left' );

        var btn = el( 'button', {
            className: posClass,
            onClick: function () { showBanner(); },
            'aria-label': 'Gestisci preferenze cookie',
            title: 'Gestisci preferenze cookie'
        }, '🍪' );

        reopenWrap.appendChild( btn );
        reopenWrap.style.display = 'block';
    }

    // =========================================================================
    // SHOW / HIDE LOGIC
    // =========================================================================

    function showBanner() {
        var wrap = document.getElementById( 'dbcm-banner-wrap' );
        wrap.style.display = 'block';
        document.body.classList.add( 'dbcm-no-scroll' );

        // If returning user, pre-set toggles from saved consent
        var consent = getConsent();
        if ( consent ) {
            var toggles = wrap.querySelectorAll( '.dbcm-toggle__input' );
            toggles.forEach( function ( t ) {
                var cat = t.getAttribute( 'data-category' );
                if ( cat && consent[ cat ] !== undefined && ! t.disabled ) {
                    t.checked = consent[ cat ];
                }
            } );
        }

        // Focus first action button
        var firstBtn = wrap.querySelector( '.dbcm-btn' );
        if ( firstBtn ) firstBtn.focus();

        // Hide reopen button
        var reopen = document.getElementById( 'dbcm-reopen-wrap' );
        if ( reopen ) reopen.style.display = 'none';

        // Escape key closes banner (reject)
        document.addEventListener( 'keydown', bannerEscHandler );
    }

    function hideBanner() {
        var wrap = document.getElementById( 'dbcm-banner-wrap' );
        wrap.style.display = 'none';
        document.body.classList.remove( 'dbcm-no-scroll' );
        document.removeEventListener( 'keydown', bannerEscHandler );

        // Show reopen button
        if ( C.showReopen ) {
            var reopen = document.getElementById( 'dbcm-reopen-wrap' );
            if ( reopen ) reopen.style.display = 'block';
        }
    }

    function bannerEscHandler( e ) {
        if ( e.key === 'Escape' ) {
            // If details panel is open, close that first
            var details = document.getElementById( 'dbcm-details' );
            if ( details && details.classList.contains( 'is-open' ) ) {
                return; // trapFocus handles this
            }
            rejectAll();
        }
    }

    function showDetails() {
        var details = document.getElementById( 'dbcm-details' );
        details.classList.add( 'is-open' );

        // Focus the close button
        var closeBtn = details.querySelector( '.dbcm-details__close' );
        if ( closeBtn ) closeBtn.focus();

        // Trap focus inside the panel
        details.addEventListener( 'keydown', trapFocus );
    }

    function hideDetails() {
        var details = document.getElementById( 'dbcm-details' );
        details.classList.remove( 'is-open' );
        details.removeEventListener( 'keydown', trapFocus );

        // Return focus to the customize button
        var customizeBtn = document.querySelector( '.dbcm-btn--customize' );
        if ( customizeBtn ) customizeBtn.focus();
    }

    function trapFocus( e ) {
        if ( e.key === 'Escape' ) {
            hideDetails();
            return;
        }

        if ( e.key !== 'Tab' ) return;

        var details = document.getElementById( 'dbcm-details' );
        var focusable = details.querySelectorAll( 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])' );
        if ( ! focusable.length ) return;

        var first = focusable[0];
        var last = focusable[ focusable.length - 1 ];

        if ( e.shiftKey ) {
            if ( document.activeElement === first ) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if ( document.activeElement === last ) {
                e.preventDefault();
                first.focus();
            }
        }
    }

    // =========================================================================
    // CONSENT ACTIONS
    // =========================================================================

    function acceptAll() {
        setConsent( { performance: true, analytics: true, marketing: true } );
        hideBanner();
        fireConsentEvent( 'all' );
    }

    function rejectAll() {
        setConsent( { performance: false, analytics: false, marketing: false } );
        hideBanner();
        fireConsentEvent( 'necessary' );
    }

    function saveCustom() {
        var wrap = document.getElementById( 'dbcm-banner-wrap' );
        var toggles = wrap.querySelectorAll( '.dbcm-toggle__input' );
        var categories = {};

        toggles.forEach( function ( t ) {
            var cat = t.getAttribute( 'data-category' );
            if ( cat ) {
                categories[ cat ] = t.checked;
            }
        } );

        setConsent( categories );
        hideDetails();
        hideBanner();
        fireConsentEvent( 'custom' );
    }

    function fireConsentEvent( type ) {
        var consent = getConsent();
        var event = new CustomEvent( 'dbcm:consent', {
            detail: { type: type, consent: consent }
        } );
        document.dispatchEvent( event );

        // Activate blocked scripts for consented categories
        activateBlockedScripts( consent );

        // Restore blocked iframes
        restoreBlockedIframes( consent );

        // Log consent to server
        logConsent( type, consent );
    }

    function logConsent( type, consent ) {
        if ( ! C.ajaxurl ) return;

        var formData = new FormData();
        formData.append( 'action', 'dbcm_log_consent' );
        formData.append( 'type', type );
        formData.append( 'consent', JSON.stringify( consent ) );

        fetch( C.ajaxurl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        } ).catch( function () {
            // Silent fail — logging is not critical
        } );
    }

    // =========================================================================
    // SCRIPT ACTIVATION (unblock after consent)
    // =========================================================================

    function activateBlockedScripts( consent ) {
        if ( ! consent ) return;

        var blocked = document.querySelectorAll( 'script[data-dbcm-blocked="true"]' );
        blocked.forEach( function ( script ) {
            var category = script.getAttribute( 'data-dbcm-category' );
            if ( ! category || ! consent[ category ] ) return;

            // Create a new script element (changing type on existing doesn't re-execute)
            var newScript = document.createElement( 'script' );

            // Copy attributes except blocked ones
            Array.from( script.attributes ).forEach( function ( attr ) {
                if ( attr.name === 'type' || attr.name === 'data-dbcm-blocked' || attr.name === 'data-dbcm-category' ) {
                    return;
                }
                newScript.setAttribute( attr.name, attr.value );
            } );

            // Set correct type
            newScript.type = 'text/javascript';

            // Copy inline content if any
            if ( script.textContent ) {
                newScript.textContent = script.textContent;
            }

            // Replace old with new
            script.parentNode.replaceChild( newScript, script );
        } );
    }

    function restoreBlockedIframes( consent ) {
        if ( ! consent ) return;

        var placeholders = document.querySelectorAll( '.dbcm-iframe-placeholder' );
        placeholders.forEach( function ( ph ) {
            var category = ph.getAttribute( 'data-dbcm-category' );
            if ( ! category || ! consent[ category ] ) return;

            var src = ph.getAttribute( 'data-dbcm-src' );
            if ( ! src ) return;

            // Create iframe
            var iframe = document.createElement( 'iframe' );
            iframe.src = src;
            iframe.style.width = ph.style.width || '100%';
            iframe.style.height = ph.style.height || '400px';
            iframe.style.maxWidth = '100%';
            iframe.style.border = 'none';
            iframe.setAttribute( 'allowfullscreen', '' );

            // Replace placeholder with iframe
            ph.parentNode.replaceChild( iframe, ph );
        } );
    }

    // Listen for consent requests from iframe placeholders
    document.addEventListener( 'dbcm:requestConsent', function ( e ) {
        var category = e.detail && e.detail.category;
        if ( ! category ) return;

        // Accept the requested category
        var consent = getConsent() || {};
        consent[ category ] = true;
        setConsent( consent );

        // Restore iframes for that category
        restoreBlockedIframes( getConsent() );

        // Also activate any scripts of that category
        activateBlockedScripts( getConsent() );
    } );

    // =========================================================================
    // HTML ESCAPE
    // =========================================================================

    function escHtml( str ) {
        var div = document.createElement( 'div' );
        div.textContent = str;
        return div.innerHTML;
    }

    // =========================================================================
    // INIT
    // =========================================================================

    function init() {
        try {
            buildBanner();

            if ( hasConsent() ) {
                var consent = getConsent();
                activateBlockedScripts( consent );
                restoreBlockedIframes( consent );
                buildReopenButton();
            } else {
                showBanner();
                buildReopenButton();
            }
        } catch ( err ) {
            console.error( 'DB Cookie Manager error:', err );
        }
    }

    // Run on DOM ready
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

})();
