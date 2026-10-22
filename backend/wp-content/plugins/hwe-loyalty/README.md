# HWE Loyalty (módulo `loyalty`)

Programa de fidelización para el framework: un **monedero de puntos** que el cliente gana por
comprar, referir y participar, y que canjea en el checkout; más un programa de **referidos de dos
niveles** que paga sus comisiones en esos puntos. Es un módulo opcional del catálogo, como
`coupon-rules` o `shipping`.

> **Estado: en construcción.** Esta entrega cubre el arranque del plugin y los ajustes del
> Control Center. El libro mayor, la caducidad, el canje en la Store API, los referidos, la
> administración y las rutas del BFF llegan en las siguientes tareas del módulo. Todavía no hay
> lógica de saldo, canje ni referidos.

## Qué aporta hoy

| Pieza | Detalle |
|---|---|
| `hwe-loyalty.php` | Clase `HWE_Loyalty`: comprueba que WooCommerce esté activo, carga `includes/` y engancha los ajustes a `plugins_loaded`. |
| `includes/Settings.php` | Clase `HWE_Loyalty_Settings`: puebla el grupo reservado `loyalty` del Control Center y expone la lectura saneada. |
| Control Center → Fidelización | Todos los ajustes del módulo, con sus defaults. El grupo lo declara el núcleo vacío (`SchemaGroups/Loyalty.php`) y este plugin lo rellena con `hwe_control_center_schema`. |

## Ajustes (`config.loyalty`)

Todos los campos son públicos excepto `allowed_roles`, que es un ajuste operativo del backend.

| Campo | Tipo | Default | Qué hace |
|---|---|---|---|
| `points_enabled` | booleano | `false` | Enciende el monedero. Sin él, referidos y transferencias se comportan como apagados. |
| `referrals_enabled` | booleano | `false` | Comisiones y puntos por alta de referidos. Exige puntos activos. |
| `currency_name` / `currency_name_plural` | texto | `punto` / `puntos` | Nombre de la unidad. Ningún texto del módulo lo lleva escrito a mano. |
| `currency_icon` | imagen | vacío | Icono de la unidad (medioteca o URL). |
| `point_value` | texto decimal | `0.01` | Valor de 1 unidad en la moneda de la tienda. Con 0 o menos, el canje queda desactivado. |
| `min_redeem` | entero | `100` | Mínimo de unidades para canjear. |
| `max_redeem_per_order` | entero | `0` | Tope por pedido. 0 = sin tope. |
| `full_payment_enabled` | booleano | `false` | Permite cubrir el pedido completo (envío incluido). |
| `min_cash_due` | texto decimal | `0.01` | Con el pago completo apagado, importe que debe quedar por pagar en dinero. |
| `expiry_days` | entero | `0` | Caducidad de los abonos. 0 = no caducan. |
| `cashback_enabled` / `cashback_percent` | booleano / entero | `false` / `0` | Devolución al comprador sobre la base de la compra. |
| `events.registration` / `events.review` / `events.birthday` | entero | `0` | Unidades por evento. 0 = apagado. |
| `referral_signup_points_l1` / `_l2` | entero | `0` | Unidades al referidor por el alta del referido (nivel 1 y 2). |
| `commission_first_percent` / `commission_next_percent` | entero | `0` | Comisión del referidor en la primera compra y en las siguientes. |
| `level2_enabled` / `commission_level2_percent` | booleano / entero | `false` / `0` | Comisión para el referidor del referidor. |
| `commission_duration_days` | entero | `0` | Días en los que se paga comisión desde la atribución. 0 = sin límite. |
| `transfers_enabled` | booleano | `false` | Transferencias entre usuarios por código de referido. |
| `transfer_min` / `transfer_fee_percent` / `transfer_daily_cap` | entero | `100` / `0` / `0` | Mínimo por transferencia, comisión de la tienda y tope diario. |
| `allowed_roles` | texto | `customer, subscriber` | Roles que participan. **No público.** Filtrable con `hwe_loyalty_participant_roles`. |

### Lectura saneada

`HWE_Loyalty_Settings::get()` devuelve los ajustes con tipos nativos y recorta los valores
absurdos al leer (foco de revisión 5): porcentajes a `[0, 100]`, enteros a `≥ 0`, `expiry_days`
negativo a `0` y `min_cash_due` ≤ 0 al mínimo positivo `0.01`. Con `point_value` ≤ 0 el canje
falla cerrado.

Interruptores que consumen las siguientes tareas:

- `pointsActive()`: ajuste encendido **y** módulo activo. El plugin cargado es la señal; si el
  Control Center está disponible se confirma con `Modules::isActive('loyalty')`. Incluir el
  archivo a mano no enciende el programa.
- `referralsActive()`: puntos activos **y** `referrals_enabled`.
- `transfersActive()`: puntos activos **y** `transfers_enabled`.
- `redeemActive()`: puntos activos **y** `point_value > 0`.
- `allowedRoles()`: roles participantes, con el filtro `hwe_loyalty_participant_roles`.
- `canParticipate(int $userId)`: usuario existente, con rol permitido y programa activo.

Los ajustes se leen con `\HWE\ControlCenter\Storage::get([...], $default)`. Sin Control Center
se usan los defaults de `HWE_Loyalty_Settings::DEFAULTS` y nada falla.

## Contrato con el frontend

- `config.modules.loyalty` es `true` solo si este plugin está activo (lo calcula el núcleo).
- `config.loyalty` lleva los campos públicos con los defaults de arriba. El espejo de TypeScript
  vive en `frontend/src/lib/config/{types,defaults}.ts`.
- Con el módulo apagado, los lectores deben devolver vacío y las rutas del BFF responder 404.
  Esta parte se implementa en las siguientes tareas.

## Pruebas

`backend/tests/Unit/Loyalty/SettingsTest.php` (PHPUnit + Brain Monkey): el esquema puebla el
grupo con todos los campos y sus defaults, `allowed_roles` no se publica, el saneado recorta los
valores absurdos, los interruptores dependen de puntos y módulo, y `canParticipate` respeta los
roles. La paridad de defaults en TypeScript la fija
`frontend/src/lib/config/defaults.test.ts`.

## Estructura

| Archivo | Responsabilidad |
|---|---|
| `hwe-loyalty.php` | Arranque, comprobación de WooCommerce y carga de `includes/`. |
| `includes/Settings.php` | Esquema del grupo `loyalty` y lectura saneada de los ajustes. |
