# HWE External Integration

Módulo `external-integration` del framework: acceso de un **integrador externo** (ERP, logística,
facturación) a los pedidos de WooCommerce por GraphQL, con la superficie mínima. Es un plugin regular
(activable) y no contiene nada de ningún integrador concreto. Guía de operación: [`MANUAL.md`](MANUAL.md).

## Qué hace

Una petición de la integración atraviesa tres capas, cada una con un dueño:

| Capa | Dónde vive | Qué decide |
|---|---|---|
| **Puerta de red** | `mu-plugins/hwe-bff-only.php` | Qué IP llega a GraphQL (`HWE_INTEGRATION_ALLOWED_IPS`, sobre `REMOTE_ADDR`). Solo GraphQL: la Store API y `hwe/v1/auth\|consent` siguen cerradas también para esa IP. Este plugin la **enciende** (`hwe_integration_gate_enabled`): sin él, una IP de la lista no abre nada. |
| **Identidad** | `Role`, `Access` | El rol `hwe_integration` (solo pedidos), la Application Password del usuario de servicio (válida solo desde la IP y solo en GraphQL) y la ausencia de inicio de sesión interactivo. |
| **Política** | `Policy`, `GraphqlGuard` | Qué puede pedir y cambiar: operaciones raíz, campos vetados, `metaData` y el cambio de estado. |

El BFF (secreto interno) y WP-CLI **no** quedan afectados: para ellos la petición no es de la integración.

## Política por defecto

- **Leer** pedidos: `order` y `orders`. El cliente llega por `order.customer`, `billing` y `shipping`.
- **Escribir**: `updateOrder` y solo el **estado** (`id`/`orderId`, `status`). Cualquier otro campo se
  rechaza. No ejecuta la mutación de WooGraphQL (que recalcula totales y pisa `created_via`): cambia el
  estado con `update_status()` y nada más.
- **Un pedido sin pagar** (`pending`, `failed`) solo se puede cancelar: pasarlo a procesando o
  completado le pone fecha de pago, descuenta inventario y envía al comprador el correo de un pedido
  cobrado, y eso solo lo afirma el webhook de la pasarela. Un `checkout-draft` no se toca. Es un
  **suelo**: ningún filtro lo rebaja.
- **`metaData`**: en el pedido y sus reembolsos, solo las claves de seguimiento de `hwe-order-tracking`
  (`_carrier`, `_tracking_number`, `_tracking_url`); en las líneas, el meta visible (sin `_`).
- **Campos vetados** (aunque su tipo se pueda leer): `Order.orderKey`, `cartHash`, `customerIpAddress`,
  `customerUserAgent`, `orderNotes` y, en `Customer`, las credenciales, la sesión y `metaData`.
- **Nunca** (suelo): `login`, `registerUser`, `users`, `customers`, `viewer`… Emitirían un token sin el 2FA
  del BFF o listarían cuentas.

## Extensibilidad

`hwe_integration_policy` (filtro) recibe y devuelve la política. Solo puede **abrir** lo que su integrador
necesita; el suelo de arriba no se puede rebajar.

```php
add_filter( 'hwe_integration_policy', static function ( array $policy ): array {
    $policy['queries'][]         = 'products';          // leer también productos
    $policy['order_meta_keys'][] = '_hwe_document_number'; // y un meta del pedido
    return $policy;
} );
```

| Clave | Contenido | Suelo |
|---|---|---|
| `queries` | operaciones raíz de lectura | `DENIED_ROOT` se descarta siempre |
| `mutations` | mutaciones raíz (solo `updateOrder` está implementada) | `DENIED_ROOT` se descarta siempre |
| `update_order_fields` | campos de la entrada de `updateOrder` | — |
| `blocked_fields` | `Tipo → [campos]` que no se pueden leer | los del defecto no se pueden quitar |
| `order_meta_keys` | claves visibles en `Order.metaData` | — |
| `order_meta_types` | tipos cuyo `metaData` va por esa lista | — |
| `unpaid_statuses` | estados desde los que solo se cancela | `pending` y `failed` |

Una política mal formada se descarta y queda en el log (`[hwe] external-integration: …`).

## Estructura

```
hwe-external-integration/
  hwe-external-integration.php — entrada y autoloader PSR-4 (HWE\ExternalIntegration\…)
  includes/
    Plugin.php        — cableado; enciende la puerta de red y las Application Passwords
    Role.php          — rol `hwe_integration` y la traducción de capacidades con HPOS
    Access.php        — Application Password acotada, sin login, ¿petición de la integración?
    Policy.php        — la política y su suelo
    GraphqlGuard.php  — la política hecha filtros de WPGraphQL
  README.md  MANUAL.md
```

## Notas

- **Con HPOS**, WooGraphQL autoriza `updateOrder` sobre el tipo `shop_order_placehold`, que tiene las
  capacidades genéricas de «post». El rol no recibe `edit_others_posts` (abriría el blog): `Role::mapMetaCap`
  traduce `edit_post` y `read_post` a las de pedidos solo para ese tipo y ese rol.
- **`order.customer`**: WooGraphQL protege `Customer` con `list_users`. Para la integración esa restricción
  se rebaja a `edit_shop_orders`; no abre más superficie que el pedido ya abierto.
- **Sin tag ISR**: el módulo no tiene datos que pinte el frontend. El frontend solo sabe que está activo
  (`config.modules['external-integration']`).
- **Una llamada del BFF no cuenta nunca** como integración, aunque venga de una IP de la lista.

## Verificación

`backend/tests/Unit/ExternalIntegration/` (PHPUnit) y, contra la pila Docker,
`sh backend/scripts/verify-integration.sh all`. Lo que **no** se puede verificar en local: que el proxy
vea la IP real del integrador y que la cabecera `Authorization` llegue a PHP a través del proxy.
