=== Plenopay Payment Method ===
Contributors: plenopay
Tags: woocommerce, payments, installments, bnpl, plenopay
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 10.2
Stable tag: 2.0.13
License: MIT
License URI: https://opensource.org/licenses/MIT

Agrega Plenopay como método de pago en WooCommerce: paga en 4 cuotas con tarjeta de crédito o débito.

== Description ==

Plenopay permite a tus clientes pagar sus compras en 4 cuotas cada 15 días con tarjeta de crédito o débito.
Al confirmar el pedido se abre una ventana emergente de Plenopay (Unlimit) para completar el pago.

* Compatible con el checkout clásico y el checkout por bloques de WooCommerce.
* Compatible con HPOS (High-Performance Order Storage).
* Devoluciones totales y parciales sincronizadas con Plenopay Comercios.
* Actualizaciones desde el panel de WordPress (Plugins → Actualizaciones).

== Installation ==

1. Sube `plenopay-payment-method.zip` en Plugins → Añadir nuevo → Subir plugin y actívalo.
2. Ve a WooCommerce → Ajustes → Pagos → Plenopay.
3. Captura tu ID de Comercio y tu Llave de Comercio (API Key) y guarda los cambios.

== Frequently Asked Questions ==

= ¿Cómo recibo nuevas versiones? =

Desde la versión 2.0.13 el plugin avisa de nuevas versiones en Plugins → Actualizaciones.
Para instalarlas sin intervención, activa "Habilitar actualizaciones automáticas" en la fila de Plenopay de la página de Plugins.

== Changelog ==

= 2.0.13 =
* Nuevo: actualizaciones del plugin desde el panel de WordPress (manuales o automáticas).
* Corrección: el script del checkout por bloques se versiona con la versión del plugin para evitar que el navegador use una copia antigua tras actualizar.

= 2.0.12 =
* Última versión distribuida manualmente. Para actualizar a 2.0.13 se debe subir el zip una única vez.

== Upgrade Notice ==

= 2.0.13 =
Instala esta versión manualmente una vez; a partir de ella las nuevas versiones llegan desde el panel de WordPress.
