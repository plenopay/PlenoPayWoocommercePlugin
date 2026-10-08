( function () {
    'use strict';

    var wcSettings       = window.wc.wcSettings;
    var wcBlocksRegistry = window.wc.wcBlocksRegistry;
    var createElement    = window.wp.element.createElement;
    var useEffect        = window.wp.element.useEffect;
    var decodeEntities   = window.wp.htmlEntities.decodeEntities;

    var settings    = wcSettings.getSetting( 'plenopay_data', {} );
    var title       = decodeEntities( settings.title || 'Plenopay' );
    var description = settings.description || '';

    // ── Label (title + logo shown in the payment method list) ────────────────

    var PlenopayLabel = function () {
        return createElement(
            'span',
            { style: { display: 'inline-flex', alignItems: 'center', gap: '8px' } },
            title,
            createElement( 'img', {
                src:   settings.iconUrl || '',
                alt:   'Plenopay',
                style: { height: '20px', verticalAlign: 'middle' },
            } )
        );
    };

    // ── Content (shown when payment method is selected; mounts popup hook) ───

    var PlenopayContent = function ( props ) {
        var eventRegistration = props.eventRegistration;
        var emitResponse      = props.emitResponse;

        useEffect( function () {
            if ( ! eventRegistration || ! eventRegistration.onPaymentSetup ) return;

            // onPaymentSetup fires when the user clicks "Place Order".
            // Returning a Promise makes WC Blocks wait for it before proceeding.
            var unsubscribe = eventRegistration.onPaymentSetup( function () {
                return runPlenopayFlow( emitResponse );
            } );

            return unsubscribe;
        }, [] );

        return createElement( 'div', {
            dangerouslySetInnerHTML: { __html: description },
        } );
    };

    // ── Main popup flow ───────────────────────────────────────────────────────

    function runPlenopayFlow( emitResponse ) {
        var SUCCESS     = emitResponse.responseTypes.SUCCESS;
        var ERROR       = emitResponse.responseTypes.ERROR;
        var apiUrl      = ( settings.apiUrl      || 'https://api.plenopay.com' ).replace( /\/$/, '' );
        var platformUrl = ( settings.platformUrl  || 'http://localhost:3001' ).replace( /\/$/, '' );
        var sandbox     = settings.sandbox        || 'true';

        // Open the popup SYNCHRONOUSLY — must happen before any async call so the
        // browser treats it as part of the "Place Order" user gesture.
        var features = [
            'width=1030', 'height=750',
            'left='  + Math.round( ( screen.width  - 1030 ) / 2 ),
            'top='   + Math.round( ( screen.height - 750 ) / 2 ),
            'resizable=yes', 'scrollbars=yes',
            'toolbar=no', 'menubar=no', 'location=no', 'status=no',
        ].join( ',' );

        var popup = window.open( 'about:blank', 'plenopay_payment', features );

        if ( ! popup || popup.closed ) {
            return Promise.resolve( {
                type:    ERROR,
                message: 'El popup fue bloqueado por el navegador. Permite ventanas emergentes e intenta de nuevo.',
            } );
        }

        // Show a loading screen while async token fetching happens
        try {
            popup.document.write(
                '<html><head><title>Plenopay</title>'
                + '<style>body{font-family:sans-serif;display:flex;align-items:center;'
                + 'justify-content:center;height:100vh;margin:0;background:#f5f5f5;}'
                + 'p{color:#6c3baa;font-size:18px;font-weight:600;}</style></head>'
                + '<body><p>Cargando Plenopay…</p></body></html>'
            );
        } catch ( e ) { /* ignore: cross-origin guard fires on page refresh */ }

        // Step 1: commerce token
        var tokenUrl = apiUrl
            + '/pay-button/' + encodeURIComponent( settings.idCommerce  || '' )
            + '?key='        + encodeURIComponent( settings.keyCommerce || '' )
            + '&sandbox='    + sandbox
            + '&justToken=true';

        // settings.shipping/settings.tax were read once when this script module
        // first loaded and never change afterwards, so they go stale if the
        // customer picks/changes a shipping method later in the same checkout
        // session. Pull the live totals from the Store API cart right before
        // creating the purchase, falling back to `settings` if that fails.
        return getLiveCartAmounts()
            .then( function ( live ) {
                return jsonFetch( tokenUrl ).then( function ( tokenRaw ) {
                    return { tokenRaw: tokenRaw, live: live };
                } );
            } )
            .then( function ( result ) {
                var tokenRaw = result.tokenRaw;
                var live     = result.live;
                var commerceToken = ( typeof tokenRaw === 'string' )
                    ? tokenRaw.replace( /^"|"$/g, '' )
                    : tokenRaw;

                if ( typeof commerceToken !== 'string' || ! /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test( commerceToken ) ) {
                    throw new Error( 'No se obtuvo el token del comercio. Verifica el ID y la llave de comercio.' );
                }

                // Step 2: create purchase (cart data comes from PHP via get_payment_method_data)
                return jsonFetch( apiUrl + '/purchases/create-order', {
                    method:  'POST',
                    headers: {
                        'Content-Type':  'application/json',
                        'Authorization': 'Bearer ' + commerceToken,
                    },
                    body: JSON.stringify( {
                        products:    settings.products || [],
                        shippingFee: live ? live.shipping : ( settings.shipping || 0 ),
                        tax:         live ? live.tax      : ( settings.tax      || 0 ),
                    } ),
                } );
            } )
            .then( function ( purchaseRes ) {
                var purchaseToken = purchaseRes && (
                    purchaseRes.token || ( purchaseRes.data && purchaseRes.data.token )
                );

                if ( ! purchaseToken ) {
                    throw new Error( 'No se obtuvo el token de compra.' );
                }

                if ( popup.closed ) {
                    throw new Error( 'El popup fue cerrado antes de completar. Intenta de nuevo.' );
                }

                // Step 3: navigate the already-open popup to Platform_Plenopay
                popup.location.href = platformUrl + '/' + purchaseToken
                    + '?sandbox='   + sandbox
                    + '&wcSiteUrl=' + encodeURIComponent( window.location.origin );

                return waitForPlenopayResult( popup, platformUrl, SUCCESS, ERROR );
            } )
            .catch( function ( err ) {
                if ( popup && ! popup.closed ) popup.close();
                return {
                    type:    ERROR,
                    message: 'Plenopay: ' + ( err.message || 'Error inesperado. Intenta de nuevo.' ),
                };
            } );
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    function waitForPlenopayResult( popup, platformUrl, SUCCESS, ERROR ) {
        return new Promise( function ( resolve ) {
            var resolved = false;
            var interval;

            // Derive expected origin (strip any path the admin may have added)
            var expectedOrigin;
            try { expectedOrigin = new URL( platformUrl ).origin; } catch ( e ) { expectedOrigin = platformUrl; }

            var handler = function ( e ) {
                if ( e.origin !== expectedOrigin ) return;
                if ( ! e.data || typeof e.data.plenopay === 'undefined' ) return;

                resolved = true;
                clearInterval( interval );
                window.removeEventListener( 'message', handler );
                if ( popup && ! popup.closed ) popup.close();

                if ( e.data.plenopay === 'success' ) {
                    resolve( { type: SUCCESS } );
                } else {
                    resolve( {
                        type:    ERROR,
                        message: 'Tu pago fue rechazado por Plenopay. Intenta con otra tarjeta o método de pago.',
                    } );
                }
            };

            window.addEventListener( 'message', handler );

            // Fallback: popup closed without a postMessage (user hit X).
            // No WC order has been created yet in the blocks flow, so returning ERROR
            // keeps the customer on the checkout page where they can try again.
            interval = setInterval( function () {
                if ( ! popup || popup.closed ) {
                    clearInterval( interval );
                    if ( ! resolved ) {
                        window.removeEventListener( 'message', handler );
                        resolve( {
                            type:    ERROR,
                            message: 'Cerraste la ventana de Plenopay sin completar el pago. Tu pedido no fue creado. Puedes intentarlo de nuevo.',
                        } );
                    }
                }
            }, 600 );
        } );
    }

    function getLiveCartAmounts() {
        return fetch( '/wp-json/wc/store/v1/cart', { credentials: 'same-origin' } )
            .then( function ( res ) { return res.ok ? res.json() : null; } )
            .then( function ( cart ) {
                if ( ! cart || ! cart.totals ) return null;
                var minorUnit = Math.pow( 10, cart.totals.currency_minor_unit || 2 );
                return {
                    shipping: parseInt( cart.totals.total_shipping, 10 ) / minorUnit,
                    tax:      parseInt( cart.totals.total_tax,      10 ) / minorUnit,
                };
            } )
            .catch( function () { return null; } );
    }

    function jsonFetch( url, opts ) {
        return fetch( url, opts || {} )
            .then( function ( res ) {
                return res.text().then( function ( text ) {
                    var body;
                    try { body = JSON.parse( text ); } catch ( e ) { body = text; }
                    if ( ! res.ok ) {
                        var msg = ( body && Array.isArray( body.reasons ) && body.reasons.length )
                            ? body.reasons.map( function ( r ) { return r.message; } ).join( ', ' )
                            : ( ( body && body.message ) ? body.message : ( 'HTTP ' + res.status ) );
                        throw new Error( Array.isArray( msg ) ? msg.join( ', ' ) : msg );
                    }
                    return body;
                } );
            } );
    }

    // ── Register with WC Blocks ───────────────────────────────────────────────

    wcBlocksRegistry.registerPaymentMethod( {
        name:           'plenopay',
        label:          createElement( PlenopayLabel,   null ),
        content:        createElement( PlenopayContent, null ),
        edit:           createElement( 'div', { dangerouslySetInnerHTML: { __html: description } } ),
        canMakePayment: function () { return true; },
        ariaLabel:      title,
        supports: {
            features: settings.supports || [ 'products' ],
        },
    } );

}() );
