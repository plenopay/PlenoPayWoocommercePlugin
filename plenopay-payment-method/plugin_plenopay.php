<?php
/*
    Plugin Name: Plenopay Payment Method
    Plugin URI: https://plenopay.com/
    Description: Agrega Plenopay como método de pago en WooCommerce. Al confirmar el pedido se abre una ventana emergente de Plenopay (Unlimit).
    Author: Plenopay
    Author URI: https://www.plenopay.com/integracion-woocommerce
    License: MIT
    Version: 2.0.13
    Requires at least: 6.0
    Requires PHP: 7.4
    WC requires at least: 8.0
    WC tested up to: 11.2
    Text Domain: woocommerce-plenopay-gateway
    Update URI: https://github.com/plenopay/PlenoPayWoocommercePlugin
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Keep in sync with the Version header above (the release workflow checks both).
define( 'PLENOPAY_VERSION', '2.0.13' );

// ─── Automatic updates (GitHub Releases) ──────────────────────────────────────
// Registered before the WooCommerce check so the plugin keeps receiving updates
// even while WooCommerce is inactive.

require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

$wc_plenopay_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/plenopay/PlenoPayWoocommercePlugin/',
    __FILE__,
    'plenopay-payment-method'
);

// Install only the zip attached to a GitHub Release. Never fall back to GitHub's
// auto-generated source zip: it contains the whole repo, not the plugin folder.
$wc_plenopay_updater->getVcsApi()->enableReleaseAssets(
    '/^plenopay-payment-method\.zip$/',
    \YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
);

// Only published releases count as updates (no tags, no branch heads).
add_filter(
    $wc_plenopay_updater->getUniqueName( 'vcs_update_detection_strategies' ),
    function ( $strategies ) {
        return array_intersect_key( $strategies, [ 'latest_release' => true ] );
    }
);

// ─── Version change routine ───────────────────────────────────────────────────
// Updates (manual or automatic) don't fire activation hooks, so data migrations
// between versions run here, once, the first time a new version loads.

add_action( 'plugins_loaded', 'wc_plenopay_maybe_upgrade', 5 );

function wc_plenopay_maybe_upgrade() {
    $installed = get_option( 'plenopay_version' );
    if ( $installed === PLENOPAY_VERSION ) return;

    // Future migrations go here, e.g.:
    // if ( $installed && version_compare( $installed, '2.1.0', '<' ) ) { ... }

    update_option( 'plenopay_version', PLENOPAY_VERSION );
}

// ─── WooCommerce active check ─────────────────────────────────────────────────

function wc_plenopay_is_active() {
    if ( class_exists( 'WooCommerce' ) ) return true;
    $plugins = apply_filters( 'active_plugins', get_option( 'active_plugins', [] ) );
    if ( in_array( 'woocommerce/woocommerce.php', $plugins, true ) ) return true;
    if ( is_multisite() ) {
        return isset( get_site_option( 'active_sitewide_plugins', [] )['woocommerce/woocommerce.php'] );
    }
    return false;
}

if ( ! wc_plenopay_is_active() ) return;

// ─── Hooks ────────────────────────────────────────────────────────────────────

add_action( 'before_woocommerce_init',             'wc_plenopay_declare_hpos_compatibility' );
add_action( 'plugins_loaded',                      'wc_plenopay_gateway_init', 11 );
add_action( 'woocommerce_blocks_loaded',           'wc_plenopay_register_blocks_support' );
add_action( 'wp_enqueue_scripts',                  'wc_plenopay_enqueue_checkout_scripts' );
add_filter( 'woocommerce_payment_gateways',        'wc_plenopay_add_to_gateways' );
add_action( 'woocommerce_before_add_to_cart_form', 'wc_plenopay_product_banner' );
add_action( 'woocommerce_before_cart_totals',      'wc_plenopay_cart_banner' );
add_action( 'wp_ajax_plenopay_order_amounts',      'wc_plenopay_ajax_order_amounts' );
add_action( 'wp_ajax_nopriv_plenopay_order_amounts', 'wc_plenopay_ajax_order_amounts' );
add_action( 'woocommerce_create_refund',           'wc_plenopay_on_create_refund', 10, 2 );

// ─── Compatibility ────────────────────────────────────────────────────────────

function wc_plenopay_declare_hpos_compatibility() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables',  __FILE__, true );
    }
}

// ─── Gateway registration ─────────────────────────────────────────────────────

function wc_plenopay_add_to_gateways( $gateways ) {
    $gateways[] = 'WC_Gateway_Plenopay';
    return $gateways;
}

function wc_plenopay_gateway_init() {
    if ( class_exists( 'WC_Gateway_Plenopay' ) ) return;

    class WC_Gateway_Plenopay extends WC_Payment_Gateway {

        public function __construct() {
            $this->id                 = 'plenopay';
            $this->icon               = plugin_dir_url( __FILE__ ) . 'boton_logo_plenopay-02.png';
            $this->method_title       = __( 'Plenopay', 'woocommerce-plenopay-gateway' );
            $this->method_description = __( 'Paga en 4 cuotas con Plenopay. Se abre una ventana emergente al confirmar el pedido.', 'woocommerce-plenopay-gateway' );
            $this->has_fields         = false;
            $this->supports           = [ 'products' ];

            $this->init_form_fields();
            $this->init_settings();

            $this->enabled     = $this->get_option( 'enabled' );
            $this->title       = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
            add_action( 'woocommerce_api_plenopay', [ $this, 'webhook' ] );
        }

        public function is_available() {
            if ( 'yes' !== $this->enabled ) return false;
            if ( empty( $this->get_option( 'idCommerce' ) ) || empty( $this->get_option( 'keyCommerce' ) ) ) return false;
            return parent::is_available();
        }

        public function get_icon() {
            $icon_html = $this->icon ? '<img src="' . esc_url( $this->icon ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="height:20px;vertical-align:middle;" />' : '';
            return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
        }

        public function init_form_fields() {
            $this->form_fields = [
                'enabled' => [
                    'title'   => __( 'Habilitar/Deshabilitar', 'woocommerce-plenopay-gateway' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Plenopay habilitado', 'woocommerce-plenopay-gateway' ),
                    'default' => 'yes',
                ],
                'idCommerce' => [
                    'title'       => __( 'ID de Comercio', 'woocommerce-plenopay-gateway' ),
                    'type'        => 'number',
                    'description' => __( 'ID de comercio en Plenopay', 'woocommerce-plenopay-gateway' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ],
                'keyCommerce' => [
                    'title'       => __( 'Llave de Comercio (API Key)', 'woocommerce-plenopay-gateway' ),
                    'type'        => 'text',
                    'description' => __( 'Clave pública del comercio', 'woocommerce-plenopay-gateway' ),
                    'default'     => '',
                    'desc_tip'    => true,
                ],
                'title' => [
                    'title'   => __( 'Título', 'woocommerce-plenopay-gateway' ),
                    'type'    => 'text',
                    'default' => __( 'Plenopay - 4 pagos c/15 días con Tarjeta de Crédito y Débito.', 'woocommerce-plenopay-gateway' ),
                ],
                'description' => [
                    'title'   => __( 'Descripción', 'woocommerce-plenopay-gateway' ),
                    'type'    => 'textarea',
                    'default' => __( 'Paga hoy sólo el 25% de tu compra. Al confirmar, se abrirá una ventana de Plenopay para completar tu pago.', 'woocommerce-plenopay-gateway' ),
                ],
                'apiUrl' => [
                    'title'       => __( 'URL del Backend (API)', 'woocommerce-plenopay-gateway' ),
                    'type'        => 'text',
                    'description' => __( 'URL base de la API de Plenopay (sin barra al final)', 'woocommerce-plenopay-gateway' ),
                    'default'     => 'https://api.plenopay.com',
                    'desc_tip'    => true,
                ],
                'platformUrl' => [
                    'title'       => __( 'URL de Platform_Plenopay', 'woocommerce-plenopay-gateway' ),
                    'type'        => 'text',
                    'description' => __( 'URL base de la plataforma de pago (sin barra al final)', 'woocommerce-plenopay-gateway' ),
                    'default'     => 'https://pagos.plenopay.com',
                    'desc_tip'    => true,
                ],
                'sandbox' => [
                    'title'   => __( 'Sandbox / Producción', 'woocommerce-plenopay-gateway' ),
                    'type'    => 'select',
                    'options' => [
                        'true'  => __( 'Sandbox (Unlimit sandbox)', 'woocommerce-plenopay-gateway' ),
                        'false' => __( 'Producción', 'woocommerce-plenopay-gateway' ),
                    ],
                    'default' => 'true',
                ],
            ];
        }

        public function admin_options() {
            ?>
            <h3><?php _e( 'Plenopay Settings', 'woocommerce-plenopay-gateway' ); ?></h3>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
            <?php
        }

        /**
         * Called by WC after the checkout AJAX creates the order.
         * Sets order to pending-payment and returns the standard thankyou redirect.
         * The actual payment popup is opened by our checkout JS before the user
         * reaches the thankyou page.
         */
        public function process_payment( $order_id ) {
            $order = wc_get_order( $order_id );
            $order->update_status(
                'pending-payment',
                __( 'Esperando confirmación de pago vía Plenopay.', 'woocommerce-plenopay-gateway' )
            );
            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            ];
        }

        public function webhook() {
            // Refund notices from Plenopay are signed POST requests. The handler always ends the request.
            if ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
                wc_plenopay_handle_refund_notice( $this );
            }

            $order_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
            $order    = wc_get_order( $order_id );

            if ( ! $order || strtolower( $order->get_payment_method() ) !== 'plenopay' ) {
                wp_die( 'Invalid request', '', [ 'response' => 400 ] );
            }

            $status      = sanitize_text_field( $_GET['status']      ?? '' );
            $id_plenopay = sanitize_text_field( $_GET['idPlenopay']  ?? '' );

            if ( $status === 'success' ) {
                $order->update_status( 'processing', sprintf(
                    __( 'Plenopay confirmó la compra (ID: %s). Verifica en comercios.plenopay.com.', 'woocommerce-plenopay-gateway' ),
                    esc_html( $id_plenopay )
                ) );
                $order->reduce_order_stock();
            } elseif ( $status === 'expired' ) {
                $order->update_status( 'cancelled', sprintf(
                    __( 'Plenopay canceló la compra (ID: %s).', 'woocommerce-plenopay-gateway' ),
                    esc_html( $id_plenopay )
                ) );
                wc_restock_refunded_items( $order, $order->get_items() );
            } elseif ( in_array( $status, [ 'rejected', 'declined', 'failed' ], true ) ) {
                $order->update_status( 'failed', sprintf(
                    __( 'Plenopay rechazó el pago (ID: %s).', 'woocommerce-plenopay-gateway' ),
                    esc_html( $id_plenopay )
                ) );
                wc_restock_refunded_items( $order, $order->get_items() );
            }

            update_option( 'webhook_debug', $_GET );
        }
    }
}

// ─── Checkout page JS injection ───────────────────────────────────────────────

function wc_plenopay_enqueue_checkout_scripts() {
    if ( ! is_checkout() || is_order_received_page() ) return;
    if ( ! WC()->cart ) return;

    $gateways = WC()->payment_gateways->payment_gateways();
    $gateway  = $gateways['plenopay'] ?? null;
    if ( ! $gateway || ! $gateway->is_available() ) return;

    // Build product list from current cart
    $products = [];
    foreach ( WC()->cart->get_cart() as $item ) {
        $qty        = max( 1, (int) $item['quantity'] );
        $products[] = [
            'name'     => $item['data']->get_name(),
            'amount'   => round( (float) $item['line_subtotal'] / $qty, 2 ),
            'quantity' => $qty,
        ];
    }

    $config = [
        'idCommerce'  => $gateway->get_option( 'idCommerce' ),
        'keyCommerce' => $gateway->get_option( 'keyCommerce' ),
        'apiUrl'      => rtrim( $gateway->get_option( 'apiUrl',      'https://api.plenopay.com' ),   '/' ),
        'platformUrl' => rtrim( $gateway->get_option( 'platformUrl', 'https://pagos.plenopay.com' ), '/' ),
        'sandbox'     => $gateway->get_option( 'sandbox', 'true' ),
        'products'    => $products,
        'shipping'    => round( (float) WC()->cart->get_shipping_total(), 2 ),
        'tax'         => round( (float) WC()->cart->get_cart_tax(), 2 ),
        'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        'nonce'       => wp_create_nonce( 'plenopay_order_amounts' ),
    ];

    // Register a virtual script (no src) so we can attach inline scripts with a jQuery dependency
    wp_register_script( 'plenopay-checkout', false, [ 'jquery' ], null, true );
    wp_enqueue_script( 'plenopay-checkout' );

    // 1) Config object — uses PHP data, so we build it as JSON
    wp_add_inline_script(
        'plenopay-checkout',
        'var plenopayConfig = ' . wp_json_encode( $config ) . ';',
        'before'
    );

    // 2) Logic — nowdoc (no PHP interpolation); reads window.plenopayConfig
    wp_add_inline_script( 'plenopay-checkout', wc_plenopay_checkout_logic_js() );
}

// The 'shipping'/'tax' values above are captured once at initial checkout page load.
// WooCommerce recalculates them via its own AJAX (woocommerce_update_order_review)
// whenever the customer picks/changes a shipping method or address, without
// re-running this hook — so those values can go stale before the order is placed.
// This endpoint lets the checkout JS pull the real, final amounts straight from the
// order that WooCommerce just created, right before creating the Plenopay purchase.
function wc_plenopay_ajax_order_amounts() {
    check_ajax_referer( 'plenopay_order_amounts', 'nonce' );

    $order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0;
    $order    = $order_id ? wc_get_order( $order_id ) : false;

    if ( ! $order ) {
        wp_send_json_error( [ 'message' => 'Invalid order.' ], 404 );
    }

    wp_send_json_success( [
        'shipping' => round( (float) $order->get_shipping_total(), 2 ),
        'tax'      => round( (float) $order->get_total_tax(), 2 ),
    ] );
}

function wc_plenopay_checkout_logic_js() {
    // Nowdoc: no PHP variable interpolation — all $ are literal JS
    return <<<'JSEOF'
(function ($) {
    'use strict';

    var P               = window.plenopayConfig;
    var activePopup     = null;
    var pollInterval    = null;
    var thankyouUrl     = null;
    var paymentResolved = false;
    var messageHandler  = null;

    // WC 10.x fires checkout_place_order_{id} via $form.triggerHandler(), not document.body.
    // We must bind directly to the form. Returning false aborts WC's own AJAX so we
    // can run our own submission and open the popup instead of a full-page redirect.
    $(function () {
        $('form.checkout').on('checkout_place_order_plenopay', function () {
            handlePlenopayCheckout($(this));
            return false;
        });
    });

    // ── Step 0: submit WC checkout AJAX to create the order ──────────────────

    function handlePlenopayCheckout($form) {
        var $btn = $form.find('#place_order');
        $btn.prop('disabled', true).val('Procesando…');

        // wc_checkout_params.checkout_url is localised by WC itself
        var checkoutUrl = (typeof wc_checkout_params !== 'undefined')
            ? wc_checkout_params.checkout_url
            : '/?wc-ajax=checkout';

        $.ajax({
            type:     'POST',
            url:      checkoutUrl,
            data:     $form.serialize(),
            dataType: 'json',
            success: function (res) {
                if (res.result !== 'success') {
                    showWcError(res.messages || res.message || 'Error al procesar el pedido.');
                    resetBtn($btn);
                    return;
                }

                thankyouUrl = res.redirect;

                // Extract WC order ID from the thankyou URL
                var match   = (res.redirect || '').match(/order-received\/(\d+)/);
                var orderId = match ? parseInt(match[1], 10) : undefined;

                openPlenopayPopup(orderId);
            },
            error: function () {
                showWcError('Error de conexión. Intenta de nuevo.');
                resetBtn($btn);
            }
        });
    }

    // ── Steps 1-3: get tokens and open popup ──────────────────────────────────

    async function openPlenopayPopup(orderId) {
        try {
            // Refresh shipping/tax from the order WC just created — P.shipping/P.tax
            // were captured at initial page load and go stale if the customer changed
            // their shipping method/address afterwards (WC updates the review totals
            // via its own AJAX without re-running our PHP hook).
            if (orderId) {
                try {
                    var amountsRes = await jsonFetch(
                        P.ajaxUrl + '?action=plenopay_order_amounts'
                        + '&order_id=' + encodeURIComponent(orderId)
                        + '&nonce='    + encodeURIComponent(P.nonce)
                    );
                    if (amountsRes && amountsRes.data) {
                        if (typeof amountsRes.data.shipping === 'number') P.shipping = amountsRes.data.shipping;
                        if (typeof amountsRes.data.tax      === 'number') P.tax      = amountsRes.data.tax;
                    }
                } catch (e) { /* fall back to the page-load values if this fails */ }
            }

            // Step 1: commerce token
            var tokenUrl = P.apiUrl
                + '/pay-button/' + encodeURIComponent(P.idCommerce)
                + '?key='        + encodeURIComponent(P.keyCommerce)
                + '&sandbox='    + P.sandbox
                + '&justToken=true';

            var tokenRaw = await jsonFetch(tokenUrl);
            var commerceToken = (typeof tokenRaw === 'string')
                ? tokenRaw.replace(/^"|"$/g, '')
                : tokenRaw;

            if (typeof commerceToken !== 'string' || !/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(commerceToken)) {
                throw new Error('No se obtuvo el token del comercio. Verifica el ID y la llave.');
            }

            // Step 2: create purchase
            var purchaseBody = {
                products:    P.products,
                shippingFee: P.shipping,
                tax:         P.tax,
            };
            if (orderId) purchaseBody.orderId = orderId;

            var purchaseRes = await jsonFetch(P.apiUrl + '/purchases/create-order', {
                method:  'POST',
                headers: {
                    'Content-Type':  'application/json',
                    'Authorization': 'Bearer ' + commerceToken,
                },
                body: JSON.stringify(purchaseBody),
            });

            var purchaseToken = purchaseRes && (
                purchaseRes.token       ||
                (purchaseRes.data && purchaseRes.data.token)
            );

            if (!purchaseToken) {
                throw new Error('No se obtuvo el token de compra.');
            }

            // Step 3: open popup
            var popupUrl = P.platformUrl + '/' + purchaseToken
                + '?sandbox='    + P.sandbox
                + (orderId ? '&wcOrderId=' + encodeURIComponent(orderId) : '')
                + '&wcSiteUrl='  + encodeURIComponent(window.location.origin);
            var features = [
                'width=1030',
                'height=750',
                'left='  + Math.round((screen.width  - 1030) / 2),
                'top='   + Math.round((screen.height - 750) / 2),
                'resizable=yes',
                'scrollbars=yes',
                'toolbar=no',
                'menubar=no',
                'location=no',
                'status=no',
            ].join(',');

            activePopup = window.open(popupUrl, 'plenopay_payment', features);

            if (!activePopup || activePopup.closed) {
                throw new Error('El popup fue bloqueado por el navegador. Permite ventanas emergentes para este sitio e intenta de nuevo.');
            }

            // Derive expected origin from platformUrl (strip any path the admin may have added)
            var expectedOrigin;
            try { expectedOrigin = new URL(P.platformUrl).origin; } catch (e) { expectedOrigin = P.platformUrl; }

            // Listen for the outcome postMessage from Platform_Plenopay
            paymentResolved = false;
            if (messageHandler) window.removeEventListener('message', messageHandler);
            messageHandler = function (e) {
                if (e.origin !== expectedOrigin) return;
                if (!e.data || typeof e.data.plenopay === 'undefined') return;

                paymentResolved = true;
                clearInterval(pollInterval);
                window.removeEventListener('message', messageHandler);
                messageHandler = null;

                if (activePopup && !activePopup.closed) activePopup.close();
                activePopup = null;

                if (e.data.plenopay === 'success') {
                    window.location.href = thankyouUrl;
                } else {
                    showWcError('Tu pago fue rechazado por Plenopay. Intenta con otra tarjeta o método de pago.');
                    resetBtn($('form.checkout #place_order'));
                }
            };
            window.addEventListener('message', messageHandler);

            // Fallback: popup closed by the user (hit X) without sending a postMessage.
            // The WC order already exists as pending-payment — redirect to its status page
            // so the customer can see the order and retry later from their account.
            clearInterval(pollInterval);
            pollInterval = setInterval(function () {
                if (!activePopup || activePopup.closed) {
                    clearInterval(pollInterval);
                    activePopup = null;
                    if (!paymentResolved) {
                        window.removeEventListener('message', messageHandler);
                        messageHandler = null;
                        if (thankyouUrl) window.location.href = thankyouUrl;
                    }
                }
            }, 600);

        } catch (err) {
            showWcError('Plenopay: ' + (err.message || 'Error inesperado. Intenta de nuevo.'));
            resetBtn($('form.checkout #place_order'));
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    async function jsonFetch(url, opts) {
        var res  = await fetch(url, opts || {});
        var text = await res.text();
        var body;
        try { body = JSON.parse(text); } catch (e) { body = text; }
        if (!res.ok) {
            var msg = (body && Array.isArray(body.reasons) && body.reasons.length)
                ? body.reasons.map(function (r) { return r.message; }).join(', ')
                : ((body && body.message) ? body.message : ('HTTP ' + res.status));
            throw new Error(Array.isArray(msg) ? msg.join(', ') : msg);
        }
        return body;
    }

    function resetBtn($btn) {
        $btn.prop('disabled', false).val('Realizar el Pedido');
    }

    function showWcError(msg) {
        $('form.checkout .woocommerce-NoticeGroup-checkout, form.checkout .woocommerce-error').closest('.woocommerce-NoticeGroup').remove();
        var html = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">'
                 + '<ul class="woocommerce-error" role="alert"><li>' + msg + '</li></ul>'
                 + '</div>';
        $('form.checkout').prepend(html);
        $('html, body').animate({ scrollTop: $('form.checkout').offset().top - 80 }, 300);
    }

}(jQuery));
JSEOF;
}

// ─── Blocks checkout support ──────────────────────────────────────────────────

function wc_plenopay_register_blocks_support() {
    if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) return;
    if (   class_exists( 'WC_Gateway_Plenopay_Blocks' ) ) return;

    class WC_Gateway_Plenopay_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
        protected $name = 'plenopay';

        public function initialize() {
            $this->settings = get_option( 'woocommerce_plenopay_settings', [] );
        }

        public function is_active() {
            $gateways = WC()->payment_gateways->payment_gateways();
            $gateway  = $gateways['plenopay'] ?? null;
            return $gateway ? $gateway->is_available() : false;
        }

        public function get_payment_method_script_handles() {
            wp_register_script(
                'plenopay-blocks-integration',
                plugin_dir_url( __FILE__ ) . 'plenopay-blocks.js',
                [ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ],
                PLENOPAY_VERSION,
                true
            );
            return [ 'plenopay-blocks-integration' ];
        }

        public function get_payment_method_data() {
            $gateways = WC()->payment_gateways->payment_gateways();
            $gateway  = $gateways['plenopay'] ?? null;

            // Build current cart product list so the blocks JS can call create-order
            $products = [];
            if ( WC()->cart ) {
                foreach ( WC()->cart->get_cart() as $item ) {
                    $qty        = max( 1, (int) $item['quantity'] );
                    $products[] = [
                        'name'     => $item['data']->get_name(),
                        'amount'   => round( (float) $item['line_subtotal'] / $qty, 2 ),
                        'quantity' => $qty,
                    ];
                }
            }

            return [
                'title'       => $gateway ? $gateway->get_title()       : __( 'Plenopay', 'woocommerce-plenopay-gateway' ),
                'description' => $gateway ? $gateway->get_description() : '',
                'supports'    => [ 'products' ],
                'idCommerce'  => $gateway ? $gateway->get_option( 'idCommerce' )  : '',
                'keyCommerce' => $gateway ? $gateway->get_option( 'keyCommerce' ) : '',
                'apiUrl'      => $gateway ? rtrim( $gateway->get_option( 'apiUrl',      'https://api.plenopay.com' ),   '/' ) : 'https://api.plenopay.com',
                'platformUrl' => $gateway ? rtrim( $gateway->get_option( 'platformUrl', 'https://pagos.plenopay.com' ), '/' ) : 'https://pagos.plenopay.com',
                'sandbox'     => $gateway ? $gateway->get_option( 'sandbox', 'true' ) : 'true',
                'products'    => $products,
                'shipping'    => WC()->cart ? round( (float) WC()->cart->get_shipping_total(), 2 ) : 0,
                'tax'         => WC()->cart ? round( (float) WC()->cart->get_cart_tax(),        2 ) : 0,
                'iconUrl'     => plugin_dir_url( __FILE__ ) . 'boton_logo_plenopay-02.png',
            ];
        }
    }

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function ( $registry ) { $registry->register( new WC_Gateway_Plenopay_Blocks() ); }
    );
}

// ─── Product page banner ──────────────────────────────────────────────────────

function wc_plenopay_product_banner() {
    $params = wc_plenopay_commerce_param();
    $apiUrl = wc_plenopay_banner_api_url();
    global $product;
    $price = (float) $product->get_price();
    ?>
    <style>.plenopay-mi{margin:0!important}.plenopay-mh{padding:0!important}.plenopay-mp{padding:0!important}</style>
    <div id="plenopay-product-banner" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;margin-bottom:15px;">
        o págalo en <span style="color:#000000;font-weight:bold;margin:0 4px;">4 cuotas</span>
        de <b style="display:flex;margin:0 5px;">$<span id="plenopay-cuota"></span></b>
        con <img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'boton_logo_plenopay-02.png' ); ?>" style="width:90px;margin:0 8px;" alt="Plenopay" />
        <span style="color:#1a4bcd;cursor:pointer;text-decoration:underline #1a4bcd" onclick="plenopayInfoAlert()">Conoce más.</span>
    </div>
    <script>
    function plenopayInfoAlert() {
        Swal.fire({ title:'', text:'', imageUrl:'<?php echo esc_js( plugin_dir_url( __FILE__ ) . 'CHECKOUTbox-plenopay.png' ); ?>', imageAlt:'Plenopay', showConfirmButton:false, showCloseButton:true, customClass:{image:'plenopay-mi',header:'plenopay-mh',popup:'plenopay-mp'} });
    }
    (async function() {
        try {
            var r = await fetch('<?php echo esc_js( $apiUrl ); ?>/purchases/amounts<?php echo esc_js( $params ); ?>');
            var d = await r.json();
            var amount = <?php echo esc_js( $price ); ?>;
            if (amount >= d.min && amount <= d.max) {
                document.getElementById('plenopay-cuota').textContent = (amount / 4).toFixed(2);
            } else {
                document.getElementById('plenopay-product-banner').style.display = 'none';
            }
        } catch(e) { document.getElementById('plenopay-product-banner').style.display = 'none'; }
    })();
    </script>
    <script src="//cdn.jsdelivr.net/npm/sweetalert2@10"></script>
    <?php
}

// ─── Cart banner ──────────────────────────────────────────────────────────────

function wc_plenopay_cart_banner() {
    $params      = wc_plenopay_commerce_param();
    $apiUrl      = wc_plenopay_banner_api_url();
    global $woocommerce;
    $cart_total  = (float) $woocommerce->cart->total;
    ?>
    <style>.plenopay-mi{margin:0!important}.plenopay-mh{padding:0!important}.plenopay-mp{padding:0!important}</style>
    <div id="plenopay-cart-banner" style="display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;margin-bottom:15px;">
        o págalo en <span style="color:#000000;font-weight:bold;margin:0 4px;">4 cuotas</span>
        con <img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) . 'boton_logo_plenopay-02.png' ); ?>" style="width:90px;margin:0 8px;" alt="Plenopay" />
        <span style="color:#1a4bcd;cursor:pointer;text-decoration:underline #1a4bcd" onclick="plenopayInfoAlert()">Conoce más.</span>
    </div>
    <script>
    if (typeof plenopayInfoAlert === 'undefined') {
        function plenopayInfoAlert() {
            Swal.fire({ title:'', text:'', imageUrl:'<?php echo esc_js( plugin_dir_url( __FILE__ ) . 'CHECKOUTbox-plenopay.png' ); ?>', imageAlt:'Plenopay', showConfirmButton:false, showCloseButton:true, customClass:{image:'plenopay-mi',header:'plenopay-mh',popup:'plenopay-mp'} });
        }
    }
    (async function() {
        try {
            var r = await fetch('<?php echo esc_js( $apiUrl ); ?>/purchases/amounts<?php echo esc_js( $params ); ?>');
            var d = await r.json();
            var amount = <?php echo esc_js( $cart_total ); ?>;
            if (!(amount >= d.min && amount <= d.max)) {
                document.getElementById('plenopay-cart-banner').style.display = 'none';
            }
        } catch(e) { document.getElementById('plenopay-cart-banner').style.display = 'none'; }
    })();
    </script>
    <script src="//cdn.jsdelivr.net/npm/sweetalert2@10"></script>
    <?php
}

// ─── Shared helper ────────────────────────────────────────────────────────────

function wc_plenopay_get_gateway() {
    $gateways = WC()->payment_gateways->get_available_payment_gateways();
    foreach ( $gateways as $method ) {
        if ( stripos( $method->title, 'plenopay' ) !== false ) {
            return WC()->payment_gateways->payment_gateways()[ $method->id ] ?? null;
        }
    }
    return null;
}

function wc_plenopay_commerce_param() {
    $gw = wc_plenopay_get_gateway();
    if ( $gw ) {
        $id = $gw->get_option( 'idCommerce' );
        return $id ? '?id=' . rawurlencode( $id ) : '';
    }
    return '';
}

function wc_plenopay_banner_api_url() {
    $gw = wc_plenopay_get_gateway();
    return $gw ? rtrim( $gw->get_option( 'apiUrl', 'https://api.plenopay.com' ), '/' ) : 'https://api.plenopay.com';
}

// ─── Refunds (WooCommerce ⇄ Plenopay) ─────────────────────────────────────────
//
// Money moves only in Plenopay. The WooCommerce refund amount is the value Plenopay
// returns, and the Plenopay refund references are stored in the WooCommerce refund meta.
// A refund started in Comercios comes back as a signed notice and is recorded here.

/**
 * Runs when WooCommerce is about to record a refund (admin refund on a Plenopay order).
 * Throwing an Exception aborts the WooCommerce refund and WooCommerce shows the message.
 */
function wc_plenopay_on_create_refund( $refund, $args ) {
    if ( ! empty( $GLOBALS['wc_plenopay_skip_refund_hook'] ) ) return;
    if ( ! $refund instanceof WC_Order_Refund ) return;

    $order = wc_get_order( $refund->get_parent_id() );
    if ( ! $order || strtolower( $order->get_payment_method() ) !== 'plenopay' ) return;
    if ( $refund->get_meta( '_plenopay_refund_synced' ) ) return;

    $gateway = wc_plenopay_gateway_instance();
    if ( ! $gateway ) throw new Exception( 'Plenopay no está configurado en esta tienda.' );

    $requested = round( (float) ( $args['amount'] ?? $refund->get_amount() ), 2 );
    $others    = (float) $order->get_total_refunded() - ( $refund->get_id() ? (float) $refund->get_amount() : 0 );
    $is_total  = round( $others + $requested, 2 ) >= round( (float) $order->get_total(), 2 ) - 0.01;

    $body = [
        'orderId' => (int) $order->get_id(),
        'type'    => $is_total ? 'TOTAL' : 'PARTIAL',
    ];
    if ( ! $is_total ) {
        $body['products'] = wc_plenopay_refund_products( $order, $args['line_items'] ?? [] );
        if ( empty( $body['products'] ) ) {
            throw new Exception( 'Selecciona los productos a devolver. Plenopay solo acepta devoluciones parciales por producto.' );
        }
    }

    $lock = wc_plenopay_refund_lock_key( $order->get_id() );
    set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

    try {
        $result = wc_plenopay_request_refund( $gateway, $body );
        $refs   = array_map( 'sanitize_text_field', (array) ( $result['refundRefs'] ?? [] ) );

        $refund->set_amount( round( (float) $result['amount'], 2 ) );
        $refund->update_meta_data( '_plenopay_refund_synced', 'yes' );
        $refund->update_meta_data( '_plenopay_refund_refs', $refs );
        $refund->save();

        if ( $is_total ) {
            $order->update_status( 'refunded', __( 'Devolución total confirmada por Plenopay.', 'woocommerce-plenopay-gateway' ) );
        } else {
            $order->add_order_note( sprintf(
                /* translators: %s: Plenopay refund references */
                __( 'Devolución parcial confirmada por Plenopay (referencias: %s).', 'woocommerce-plenopay-gateway' ),
                implode( ', ', $refs )
            ) );
        }
    } finally {
        delete_transient( $lock );
    }
}

/**
 * Signed notice from Plenopay (POST, X-Plenopay-* headers) for a refund started in Comercios.
 * Always ends the request with wp_die().
 */
function wc_plenopay_handle_refund_notice( $gateway ) {
    $raw_body  = file_get_contents( 'php://input' );
    $signature = isset( $_SERVER['HTTP_X_PLENOPAY_SIGNATURE'] ) ? wp_unslash( $_SERVER['HTTP_X_PLENOPAY_SIGNATURE'] ) : '';
    $timestamp = isset( $_SERVER['HTTP_X_PLENOPAY_TIMESTAMP'] ) ? wp_unslash( $_SERVER['HTTP_X_PLENOPAY_TIMESTAMP'] ) : '';
    $event_id  = isset( $_SERVER['HTTP_X_PLENOPAY_EVENT_ID'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PLENOPAY_EVENT_ID'] ) ) : '';
    $secret    = $gateway->get_option( 'keyCommerce' );

    if ( ! $secret || ! wc_plenopay_signature_is_valid( (string) $raw_body, (string) $timestamp, (string) $signature, $secret ) ) {
        wp_die( 'Invalid signature', '', [ 'response' => 401 ] );
    }

    $data = json_decode( (string) $raw_body, true );
    if ( ! is_array( $data ) ) {
        wp_die( 'Invalid payload', '', [ 'response' => 400 ] );
    }

    $order_id = absint( $data['orderId'] ?? 0 );
    $order    = $order_id ? wc_get_order( $order_id ) : false;
    $status   = sanitize_text_field( $data['status'] ?? '' );
    $amount   = round( (float) ( $data['amount'] ?? 0 ), 2 );
    $refs     = array_map( 'sanitize_text_field', (array) ( $data['refundRefs'] ?? [] ) );

    if ( ! $order || strtolower( $order->get_payment_method() ) !== 'plenopay' ) {
        wp_die( 'Invalid request', '', [ 'response' => 400 ] );
    }
    if ( ! in_array( $status, [ 'refunded_total', 'refunded_partial' ], true ) || $amount <= 0 ) {
        wp_die( 'Invalid refund notice', '', [ 'response' => 400 ] );
    }

    // Already recorded (a repeated notice, or a refund the plugin started itself).
    if ( wc_plenopay_refund_is_recorded( $order, $refs, $event_id ) ) {
        wp_die( 'Already recorded', '', [ 'response' => 200 ] );
    }

    // The plugin is still recording a refund it started on this order. The backend retries the notice.
    if ( get_transient( wc_plenopay_refund_lock_key( $order_id ) ) ) {
        wp_die( 'Refund in progress', '', [ 'response' => 503 ] );
    }

    $GLOBALS['wc_plenopay_skip_refund_hook'] = true;
    try {
        $refund = wc_create_refund( [
            'order_id'       => $order_id,
            'amount'         => $amount,
            'reason'         => __( 'Devolución iniciada desde Comercios Plenopay', 'woocommerce-plenopay-gateway' ),
            'refund_payment' => false,
        ] );
    } finally {
        unset( $GLOBALS['wc_plenopay_skip_refund_hook'] );
    }

    if ( is_wp_error( $refund ) ) {
        wp_die( esc_html( $refund->get_error_message() ), '', [ 'response' => 500 ] );
    }

    $refund->update_meta_data( '_plenopay_refund_synced', 'yes' );
    $refund->update_meta_data( '_plenopay_refund_refs', $refs );
    $refund->update_meta_data( '_plenopay_event_id', $event_id );
    $refund->save();

    if ( 'refunded_total' === $status ) {
        $order->update_status( 'refunded', __( 'Devolución total iniciada desde Comercios Plenopay.', 'woocommerce-plenopay-gateway' ) );
    } else {
        $order->add_order_note( sprintf(
            /* translators: 1: amount, 2: Plenopay refund references */
            __( 'Devolución parcial iniciada desde Comercios Plenopay por %1$s (referencias: %2$s).', 'woocommerce-plenopay-gateway' ),
            $amount,
            implode( ', ', $refs )
        ) );
    }

    wp_die( 'ok', '', [ 'response' => 200 ] );
}

function wc_plenopay_signature_is_valid( $raw_body, $timestamp, $signature, $secret ) {
    // Same scheme as the backend: HMAC-SHA256 over "{timestamp}.{rawBody}" with the commerce key.
    if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 ) return false;

    $expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
    return hash_equals( $expected, $signature );
}

function wc_plenopay_refund_is_recorded( WC_Order $order, array $refs, $event_id ) {
    foreach ( $order->get_refunds() as $existing ) {
        $existing_refs = (array) $existing->get_meta( '_plenopay_refund_refs', true );
        if ( $refs && array_intersect( $refs, $existing_refs ) ) return true;
        if ( $event_id && $event_id === $existing->get_meta( '_plenopay_event_id', true ) ) return true;
    }
    return false;
}

function wc_plenopay_refund_products( WC_Order $order, array $line_items ) {
    $products = [];
    foreach ( $line_items as $item_id => $line ) {
        $qty  = (int) ( $line['qty'] ?? 0 );
        $item = $order->get_item( $item_id );
        if ( $qty > 0 && $item ) {
            $products[] = [ 'name' => $item->get_name(), 'quantity' => $qty ];
        }
    }
    return $products;
}

function wc_plenopay_request_refund( $gateway, array $body ) {
    $api_url         = rtrim( $gateway->get_option( 'apiUrl', 'https://api.plenopay.com' ), '/' );
    $idempotency_key = wp_generate_uuid4();
    $response        = null;

    // A fresh token for each refund action. On 401, request a new token and retry once.
    for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
        $token    = wc_plenopay_get_refund_token( $gateway );
        $response = wc_plenopay_api_request( 'POST', $api_url . '/purchases/refund-order', [
            'headers' => [
                'Authorization'   => 'Bearer ' . $token,
                'Content-Type'    => 'application/json',
                'Idempotency-Key' => $idempotency_key,
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ] );
        if ( 401 !== $response['status'] ) break;
    }

    if ( 200 !== $response['status'] ) {
        throw new Exception( wc_plenopay_error_message( $response['data'] ) );
    }
    return $response['data'];
}

function wc_plenopay_get_refund_token( $gateway ) {
    $api_url = rtrim( $gateway->get_option( 'apiUrl', 'https://api.plenopay.com' ), '/' );
    $url     = $api_url . '/pay-button/' . rawurlencode( $gateway->get_option( 'idCommerce' ) )
             . '?sandbox=' . rawurlencode( $gateway->get_option( 'sandbox', 'true' ) ) . '&refundToken=true';

    // The commerce key goes in the header only, never in the query string (nginx logs query strings).
    $response = wc_plenopay_api_request( 'GET', $url, [
        'headers' => [ 'x-api-key' => $gateway->get_option( 'keyCommerce' ) ],
        'timeout' => 30,
    ] );

    $token = $response['data']['refundToken'] ?? '';
    if ( 200 !== $response['status'] || ! is_string( $token ) || '' === $token ) {
        throw new Exception( wc_plenopay_error_message( $response['data'] ) );
    }
    return $token;
}

function wc_plenopay_api_request( $method, $url, array $args ) {
    $response = 'GET' === $method ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args );

    if ( is_wp_error( $response ) ) {
        return [ 'status' => 0, 'data' => [ 'message' => 'No se pudo conectar con Plenopay. Intenta de nuevo.' ] ];
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    return [
        'status' => (int) wp_remote_retrieve_response_code( $response ),
        'data'   => is_array( $data ) ? $data : [],
    ];
}

function wc_plenopay_error_message( array $data ) {
    if ( ! empty( $data['reasons'][0]['message'] ) ) return $data['reasons'][0]['message'];
    if ( ! empty( $data['message'] ) ) return is_array( $data['message'] ) ? implode( ', ', $data['message'] ) : $data['message'];
    return 'Plenopay no pudo procesar la devolución. Intenta de nuevo.';
}

function wc_plenopay_gateway_instance() {
    return WC()->payment_gateways->payment_gateways()['plenopay'] ?? null;
}

function wc_plenopay_refund_lock_key( $order_id ) {
    return 'plenopay_refund_inflight_' . (int) $order_id;
}
