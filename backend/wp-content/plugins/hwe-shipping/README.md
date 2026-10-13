# HWE Shipping (módulo `shipping`)

Tarifas de envío por ciudad, métodos de envío extra, envío gratis por regla y una zona de reserva
de WooCommerce. Genérico: el país base es el de la tienda (`ecommerce.country`) y no contiene nada
de ninguna instancia.

## Qué hace

| Pieza | Detalle |
|---|---|
| CPT `hwe_shipping_rate` | Menú **Envío** de wp-admin. Una tarifa fija por ciudad: el título es el nombre de la ciudad. La coincidencia con la ciudad del cliente ignora mayúsculas y tildes. Cada tarifa tiene la casilla «Aplica envío gratis». |
| CPT `hwe_shipping_method` | Submenú **Métodos de envío**. Extras opcionales y **aditivos** (no alternativas de entrega): nombre, activo/inactivo y un valor agregado opcional. |
| Coste por ciudad (`ShippingCost`) | Filtro `woocommerce_package_rates` (prioridad 20): sustituye el coste del método `flat_rate` por la tarifa de la ciudad. Solo para destinos del **país base**: una ciudad homónima de otro país no hereda la tarifa. |
| Cupón con «Permitir envío gratuito» | Deja a 0 todas las tarifas (prioridad 30, después de la ciudad). Los extras no se ven afectados. |
| Envío gratis por regla (`FreeShipping`) | Prioridad 25. Ver abajo. |
| Zona de reserva (`ReserveZone`) | Zona de WooCommerce que cubre el país base, con un `flat_rate` cuyo coste es el envío de las ciudades **sin** tarifa propia. Se gestiona desde Control Center → Envío. |
| REST pública | `GET /wp-json/hwe-shipping/v1/{methods, rates, free-shipping/scope}`. `rates` solo expone **nombres** de ciudad, nunca importes. |
| Tag ISR `shipping` | Se registra en `hwe_revalidation_tags` y se revalida al guardar o borrar un método o una tarifa (y se sube la versión del transient de envíos de WooCommerce para que los carritos abiertos recalculen). |

El coste de las tarifas no viaja por REST: lo resuelve `ShippingCost` dentro de WooCommerce y la
Store API devuelve el importe correcto en `shipping_rates`.

## Zona de reserva

Las tarifas por ciudad solo **sobreescriben** el coste de un `flat_rate`. Si la tienda no tiene
ninguna zona que cubra el país, la Store API devuelve `shipping_rates: []` y el checkout cierra
pedidos con envío 0. `ReserveZone` evita ese hueco. Campos en Control Center → Envío:

| Campo | Qué hace |
|---|---|
| `shipping.reserve_enabled` | Crear y mantener la zona (por defecto, sí). Apagarlo no la borra. |
| `shipping.reserve_name` | Nombre de la zona y del método (lo que ve el cliente). |
| `shipping.reserve_cost` | Coste, en la moneda de la tienda. 0 = gratis para las ciudades sin tarifa propia. |

Se sincroniza al guardar el panel, al activar el plugin y con `wp hwe setup`. Solo toca la zona
que ella misma creó (su id va en la opción `hwe_shipping_reserve_zone_id`); el Control Center es
la fuente de verdad: un cambio a mano del coste de ese método se pisa al guardar el panel.

## Envío gratis por regla

El plugin no guarda la regla: la pide con

```php
apply_filters( 'hwe_shipping_free_rule', [] );
// [ 'enabled' => bool, 'min_amount' => float, 'categories' => slug[], 'products' => slug[] ]
```

(la entrega Control Center → Tienda → «Envío gratis»; sin este plugin esa regla no tiene efecto).
El envío es gratis cuando se cumplen las tres condiciones:

1. `enabled` y al menos una tarifa de ciudad con la casilla marcada.
2. La ciudad de destino es una de ellas. Una ciudad sin tarifa configurada, o un destino fuera del
   país base, nunca es gratis.
3. La suma de los artículos del alcance que necesitan envío —por su importe ya descontado— llega a
   `min_amount` (unidades mayores; 0 = sin mínimo). Una categoría cubre su descendencia;
   `categories` y `products` se unen; sin ninguno, cuenta todo el carrito. Si la suma llega, es
   gratis el envío de todo el pedido, no solo el de esos artículos.

Al conceder el envío gratis, cada tarifa lleva la meta visible **«Envío gratis: Compra mínima de
$X alcanzada»**; WooCommerce la copia a la línea de envío del pedido, así que en wp-admin se lee
por qué ese pedido no pagó envío.

El mismo cálculo (`FreeShipping::evaluate`) se publica en el carrito de la Store API como
`extensions.hwe_free_shipping`, con los importes en **unidades menores**: `enabled`, `threshold`,
`eligible_subtotal`, `remaining`, `qualifies`, `city` (`eligible` | `not_eligible` | `unknown`),
`all_cities` y `scoped`. El frontend lo pinta sin recalcular nada.

`GET /free-shipping/scope` devuelve a qué aplica la regla, con nombres: `categories` (cada una con
`children`, toda su descendencia con productos), `products` y `cities`. Cada categoría y producto
lleva `translations`: un mapa idioma → `{ name, slug }` con las traducciones de `hwe-content-i18n`
de los idiomas secundarios del sitio (solo los que tienen algo traducido). Es lo que el frontend
enseña en el «?» del aviso.

## Métodos como extras aditivos

Los métodos NO son alternativas de entrega (no reemplazan la tarifa): son extras que el cliente
marca en el checkout y que el BFF suma al pedido como una línea de cargo (`fee_line`) tras crearlo
(`POST /api/store/checkout`). El importe se lee fresco (`no-store`) en ese momento.

## Contrato con el frontend

- Módulo `shipping` en `config.modules` (activo = plugin activo). Con el módulo apagado, las rutas
  `/api/store/shipping/destination` y `/api/store/free-shipping/scope` responden 404, el checkout
  no ofrece métodos extra y el carrito no pinta el aviso de envío gratis.
- Lector en `frontend/src/lib/shipping/`; ver `docs/FRONTEND_CONNECT.md` («Envío»).

## Pruebas

`backend/tests/Unit/Shipping/ShippingTest.php` (PHPUnit + Brain Monkey): coste por ciudad y país
base, cupón, regla de envío gratis (mínimo, alcance, ciudad), alcance con traducciones, REST,
guardado, revalidación y zona de reserva.
