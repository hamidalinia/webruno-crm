/**
 * Admin JS for Webruno CRM:
 * 1) Live connect/disconnect (AJAX, no blind full-page redirects)
 * 2) Customer table actions -- VIP toggle, notes, compose/send message
 *
 * Everything lives in one file/IIFE so there's nothing else to load or
 * keep in sync. Customer-table actions use event delegation on
 * document.body, so they work regardless of load order or if rows are
 * re-rendered later -- no per-row listener wiring that can silently miss
 * elements added after DOMContentLoaded fired.
 */
( function () {
    'use strict';

    function qs( sel, ctx ) {
        return ( ctx || document ).querySelector( sel );
    }

    function setStatus( el, type, message ) {
        if ( ! el ) return;
        el.className = 'webruno-crm-status webruno-crm-status-' + type;
        el.textContent = message;
        el.hidden = false;
    }

    function setBusy( button, busy, busyLabel ) {
        if ( ! button ) return;
        button.disabled = busy;
        if ( busy ) {
            button.dataset.originalLabel = button.dataset.originalLabel || button.textContent;
            button.textContent = busyLabel || button.dataset.originalLabel;
            button.classList.add( 'webruno-crm-busy' );
        } else {
            button.textContent = button.dataset.originalLabel || button.textContent;
            button.classList.remove( 'webruno-crm-busy' );
        }
    }

    function ajaxPost( action, data ) {
        if ( typeof window.webrunoCrmAjax === 'undefined' ) {
            console.error( 'Webruno CRM: webrunoCrmAjax is not defined -- admin.js was not localized correctly.' );
            return Promise.reject( new Error( 'webrunoCrmAjax missing' ) );
        }

        var body = new URLSearchParams( Object.assign( {}, data, {
            action: action,
            nonce: window.webrunoCrmAjax.nonce,
        } ) );

        return fetch( window.webrunoCrmAjax.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        } ).then( function ( resp ) {
            return resp.json().then( function ( json ) {
                return { ok: resp.ok, json: json };
            } );
        } );
    }

    function debounce( fn, wait ) {
        var t;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout( t );
            t = setTimeout( function () { fn.apply( ctx, args ); }, wait );
        };
    }

    /* ==================== Connect / Disconnect ==================== */

    var pollTimer = null;
    var pollDeadline = 0;

    function stopPolling() {
        if ( pollTimer ) {
            clearTimeout( pollTimer );
            pollTimer = null;
        }
    }

    /** Clears the "waiting" UI (link + cancel button) and re-enables the connect buttons. */
    function resetConnectUi() {
        stopPolling();
        var wait = qs( '#webruno-crm-waiting' );
        if ( wait ) wait.remove();
        document.querySelectorAll( '.webruno-crm-connect-btn' ).forEach( function ( b ) {
            setBusy( b, false );
        } );
    }

    function showWaiting( status, connectUrl ) {
        var strings = window.webrunoCrmAjax.strings;
        var wait = qs( '#webruno-crm-waiting' );
        if ( wait ) wait.remove();

        wait = document.createElement( 'p' );
        wait.id = 'webruno-crm-waiting';

        var link = document.createElement( 'a' );
        link.href = connectUrl;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = strings.openLink;
        wait.appendChild( link );

        var cancel = document.createElement( 'button' );
        cancel.type = 'button';
        cancel.className = 'button-link';
        cancel.style.marginLeft = '12px';
        cancel.textContent = strings.cancel;
        cancel.addEventListener( 'click', function () {
            resetConnectUi();
            ajaxPost( 'webruno_crm_connect_cancel', {} ).catch( function () {} );
            setStatus( status, 'error', strings.cancelled );
        } );
        wait.appendChild( cancel );

        status.insertAdjacentElement( 'afterend', wait );
    }

    function pollConnect( status ) {
        var cfg = window.webrunoCrmAjax;

        if ( Date.now() > pollDeadline ) {
            resetConnectUi();
            ajaxPost( 'webruno_crm_connect_cancel', {} ).catch( function () {} );
            setStatus( status, 'error', cfg.strings.timedOut );
            return;
        }

        ajaxPost( 'webruno_crm_connect_poll', {} )
            .then( function ( result ) {
                var data = ( result.json && result.json.data ) || {};
                var state = data.status || 'pending';

                if ( state === 'approved' ) {
                    stopPolling();
                    setStatus( status, 'success', data.message || '' );
                    window.location.reload();
                } else if ( state === 'declined' || state === 'expired' ) {
                    resetConnectUi();
                    setStatus( status, 'error', data.message || ( state === 'declined' ? cfg.strings.declined : cfg.strings.timedOut ) );
                } else {
                    pollTimer = setTimeout( function () { pollConnect( status ); }, cfg.pollIntervalMs || 3000 );
                }
            } )
            .catch( function () {
                // transient network error: keep waiting until the deadline
                pollTimer = setTimeout( function () { pollConnect( status ); }, cfg.pollIntervalMs || 3000 );
            } );
    }

    function handleConnectClick( e ) {
        var button = e.currentTarget;
        var domain = button.dataset.domain || 'webruno.com';
        var status = qs( '#webruno-crm-status' );

        e.preventDefault();
        resetConnectUi();
        setBusy( button, true, window.webrunoCrmAjax.strings.connecting );
        setStatus( status, 'pending', window.webrunoCrmAjax.strings.connecting );

        ajaxPost( 'webruno_crm_connect', { domain: domain } )
            .then( function ( result ) {
                if ( result.json && result.json.success && result.json.data.connect_url ) {
                    var url = result.json.data.connect_url;
                    setStatus( status, 'pending', window.webrunoCrmAjax.strings.waiting );
                    showWaiting( status, url );
                    // may be blocked by the browser; the visible link above is the fallback
                    window.open( url, '_blank', 'noopener,noreferrer' );
                    pollDeadline = Date.now() + ( window.webrunoCrmAjax.pollTimeoutMs || 600000 );
                    pollTimer = setTimeout( function () { pollConnect( status ); }, window.webrunoCrmAjax.pollIntervalMs || 3000 );
                } else {
                    var msg = ( result.json && result.json.data && result.json.data.message )
                        || window.webrunoCrmAjax.strings.genericError;
                    setStatus( status, 'error', msg );
                    setBusy( button, false );
                }
            } )
            .catch( function () {
                setStatus( status, 'error', window.webrunoCrmAjax.strings.networkError );
                setBusy( button, false );
            } );
    }

    /* ==================== Region (which Webruno server) ==================== */

    function renderRegion( data ) {
        var main = qs( '#webruno-crm-connect-main' );
        var host = qs( '#webruno-crm-region-host' );
        var reasons = qs( '#webruno-crm-region-reasons' );
        var message = qs( '#webruno-crm-region-message' );
        if ( main && data.domain ) { main.dataset.domain = data.domain; }
        if ( host ) { host.textContent = data.host || ''; }
        if ( reasons ) { reasons.textContent = ( data.reasons || [] ).join( ' ' ); }
        if ( message ) { message.textContent = data.message || ''; }
    }

    function regionRequest( action, params ) {
        var reasons = qs( '#webruno-crm-region-reasons' );
        if ( reasons ) { reasons.textContent = window.webrunoCrmAjax.strings.regionChecking; }
        return ajaxPost( action, params || {} ).then( function ( result ) {
            if ( result.json && result.json.success && result.json.data ) {
                renderRegion( result.json.data );
            }
        } ).catch( function () { /* keep what is shown; connecting still reports unreachable servers */ } );
    }

    function handleManualConnectSubmit( e ) {
        var form = e.currentTarget;
        var button = qs( 'button[type="submit"]', form );
        var status = qs( '#webruno-crm-status' );

        e.preventDefault();
        setBusy( button, true, window.webrunoCrmAjax.strings.connecting );
        setStatus( status, 'pending', window.webrunoCrmAjax.strings.connecting );

        var data = {
            domain: qs( '#webruno_crm_domain', form ).value,
            custom_domain: qs( '#webruno_crm_custom_domain', form ) ? qs( '#webruno_crm_custom_domain', form ).value : '',
            token: qs( '#webruno_crm_manual_token', form ).value,
        };

        ajaxPost( 'webruno_crm_manual_connect', data )
            .then( function ( result ) {
                if ( result.json && result.json.success ) {
                    setStatus( status, 'success', result.json.data.message );
                    window.location.reload();
                } else {
                    var msg = ( result.json && result.json.data && result.json.data.message )
                        || window.webrunoCrmAjax.strings.genericError;
                    setStatus( status, 'error', msg );
                    setBusy( button, false );
                }
            } )
            .catch( function () {
                setStatus( status, 'error', window.webrunoCrmAjax.strings.networkError );
                setBusy( button, false );
            } );
    }

    function handleDisconnectClick( e ) {
        var button = e.currentTarget;
        if ( ! window.confirm( window.webrunoCrmAjax.strings.confirmDisconnect ) ) return;

        var status = qs( '#webruno-crm-status' );
        setBusy( button, true, window.webrunoCrmAjax.strings.disconnecting );

        ajaxPost( 'webruno_crm_disconnect', {} )
            .then( function ( result ) {
                if ( result.json && result.json.success ) {
                    window.location.reload();
                } else {
                    var msg = ( result.json && result.json.data && result.json.data.message )
                        || window.webrunoCrmAjax.strings.genericError;
                    setStatus( status, 'error', msg );
                    setBusy( button, false );
                }
            } )
            .catch( function () {
                setStatus( status, 'error', window.webrunoCrmAjax.strings.networkError );
                setBusy( button, false );
            } );
    }

    /* ==================== Customer table: VIP + notes ==================== */

    function saveCustomerMeta( row ) {
        var checkbox = qs( '.webruno-dash-vip-checkbox', row );
        var noteInput = qs( '.webruno-dash-note-input', row );
        if ( ! checkbox || ! noteInput ) return;

        ajaxPost( 'webruno_crm_save_customer_meta', {
            email: row.getAttribute( 'data-email' ),
            vip: checkbox.checked ? '1' : '0',
            note: noteInput.value,
        } ).catch( function () {
            // Best-effort -- a failed save here isn't destructive, just retry on next edit.
        } );
    }

    var debouncedSaveCustomerMeta = debounce( saveCustomerMeta, 500 );

    /* ==================== Customer table: compose + send ==================== */

    function toggleCompose( btn ) {
        var target = document.getElementById( btn.getAttribute( 'data-target' ) );
        if ( ! target ) return;

        var isOpen = ! target.hidden;
        target.hidden = isOpen;
        btn.setAttribute( 'aria-expanded', String( ! isOpen ) );
        btn.textContent = isOpen ? btn.dataset.labelClosed : btn.dataset.labelOpen;
    }

    function sendComposeMessage( btn ) {
        var box = btn.closest( '.webruno-dash-compose-box' );
        if ( ! box ) return;

        var statusEl = qs( '.webruno-dash-send-status', box );
        var subjectInput = qs( '.webruno-dash-compose-subject', box );
        var bodyInput = qs( '.webruno-dash-compose-body', box );
        var email = btn.getAttribute( 'data-email' );

        var subject = subjectInput ? subjectInput.value.trim() : '';
        var message = bodyInput ? bodyInput.value.trim() : '';

        if ( ! subject || ! message ) {
            if ( statusEl ) {
                statusEl.hidden = false;
                statusEl.className = 'webruno-dash-send-status webruno-dash-send-status-error';
                statusEl.textContent = btn.dataset.errRequired || 'Subject and message are required.';
            }
            return;
        }

        btn.disabled = true;
        if ( statusEl ) {
            statusEl.hidden = false;
            statusEl.className = 'webruno-dash-send-status';
            statusEl.textContent = btn.dataset.sending || 'Sending…';
        }

        ajaxPost( 'webruno_crm_send_message', { email: email, subject: subject, message: message } )
            .then( function ( result ) {
                btn.disabled = false;
                if ( ! statusEl ) return;

                if ( result.json && result.json.success ) {
                    statusEl.className = 'webruno-dash-send-status webruno-dash-send-status-success';
                    statusEl.textContent = btn.dataset.sent || 'Sent!';
                } else {
                    var msg = ( result.json && result.json.data && result.json.data.message )
                        || btn.dataset.errGeneric || 'Could not send.';
                    statusEl.className = 'webruno-dash-send-status webruno-dash-send-status-error';
                    statusEl.textContent = msg;
                }
            } )
            .catch( function () {
                btn.disabled = false;
                if ( statusEl ) {
                    statusEl.className = 'webruno-dash-send-status webruno-dash-send-status-error';
                    statusEl.textContent = btn.dataset.errNetwork || 'Network error.';
                }
            } );
    }

    /* ==================== Wiring ==================== */

    document.addEventListener( 'DOMContentLoaded', function () {
        // Connect buttons
        document.querySelectorAll( '.webruno-crm-connect-btn' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', handleConnectClick );
        } );

        // Region box (connect screen only): refresh the detection once, and handle manual changes
        if ( qs( '#webruno-crm-region' ) ) {
            regionRequest( 'webruno_crm_region_detect' );
            document.querySelectorAll( '.webruno-crm-region-set' ).forEach( function ( btn ) {
                btn.addEventListener( 'click', function ( ev ) {
                    ev.preventDefault();
                    regionRequest( 'webruno_crm_region_set', { region: btn.dataset.region } );
                } );
            } );
        }

        var manualForm = qs( '#webruno-crm-manual-connect-form' );
        if ( manualForm ) {
            manualForm.addEventListener( 'submit', handleManualConnectSubmit );
        }

        var disconnectBtn = qs( '.webruno-crm-disconnect-btn' );
        if ( disconnectBtn ) {
            disconnectBtn.addEventListener( 'click', handleDisconnectClick );
        }

        // Customer table -- delegated listeners on document.body so this
        // works regardless of whether the table exists at DOMContentLoaded
        // time, and regardless of markup changes between renders.
        document.body.addEventListener( 'change', function ( e ) {
            if ( e.target.classList.contains( 'webruno-dash-vip-checkbox' ) ) {
                var row = e.target.closest( '.webruno-dash-customer-row' );
                if ( row ) saveCustomerMeta( row );
            }
        } );

        document.body.addEventListener( 'input', function ( e ) {
            if ( e.target.classList.contains( 'webruno-dash-note-input' ) ) {
                var row = e.target.closest( '.webruno-dash-customer-row' );
                if ( row ) debouncedSaveCustomerMeta( row );
            }
        } );

        document.body.addEventListener( 'click', function ( e ) {
            var composeBtn = e.target.closest( '.webruno-dash-compose-toggle' );
            if ( composeBtn ) {
                toggleCompose( composeBtn );
                return;
            }

            var sendBtn = e.target.closest( '.webruno-dash-send-btn' );
            if ( sendBtn ) {
                sendComposeMessage( sendBtn );
            }
        } );
    } );
} )();