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

## Rutas internas de lectura (`hwe/v1/loyalty/…`)

Las consume **solo el BFF**, con `X-HWE-Internal-Secret`; `hwe-bff-only` cierra todo
`/hwe/v1/loyalty/` y el `user_id` sale siempre de la sesión verificada, nunca del navegador.
Con el módulo o el interruptor de la función apagados responden `404 rest_no_route`.

| Ruta | Guarda | Devuelve |
|---|---|---|
| `GET loyalty/summary?user_id` | Puntos | `{ participant, balance, earned, used, expiring: { points, date } }`. El aviso de caducidad mira 30 días (spec §5.3). |
| `GET loyalty/transactions?user_id&page&per_page` | Puntos | `{ items: [{ id, type, points, data, created_at, expires_at }], total, pages }`, del más nuevo al más antiguo. `per_page` por defecto 20, mínimo 1 y tope 100; `pages = ceil(total / per_page)`. |
| `GET loyalty/referrals?user_id&level=1\|2&page&per_page` | Referidos | `{ items: [{ name, date, status, points_generated }], total, pages, totals: { level1, level2, pending, earned } }`. `level` por defecto 1; fuera de `1\|2`, 400. |

Decisiones de contrato:

- **Sin `user_id` válido (> 0), 400** `{ error: "invalid_request" }`. Un usuario inexistente o con
  un rol que no participa **no** es un error: `summary` responde `participant: false` con ceros y
  las dos listas van vacías. Así la ruta no sirve para enumerar cuentas.
- **`level=2` con `level2_enabled` apagado**: 200 con `items` vacío, no 404. La ruta sigue
  existiendo y `totals` conserva el histórico de la red (la guarda de la función es
  `referralsActive()`).
- **Red sin datos personales**: cada referido lleva solo `name` (nombre visible), `date`, `status`
  y `points_generated`; nunca `user_id`, email ni login. Una cuenta eliminada deja `name` vacío.
- **Fechas** en ISO 8601 UTC (`2026-10-22T10:00:00Z`); `date`/`expires_at` pueden ser `null`.
- **`totals.earned`** son los puntos que la red ha generado al referidor (abonos con referencia
  `user` a un miembro de su red), la misma definición que `points_generated` por referido.

### Dónde se compone el texto de un movimiento

**En el BFF** (Tarea 13, `lib/loyalty/describe.ts`), no en WordPress: así sigue el idioma de la
petición con `messages/` y su paridad de idiomas, y el backend no guarda descripciones con el
nombre de la unidad (spec §5.1). `transactions` devuelve `type` + `data` estructurado; este es el
contrato de `data` por tipo (lista blanca: un tipo desconocido, p. ej. añadido por
`hwe_loyalty_entry_types`, viaja con `data` vacía):

| `type` | Claves de `data` |
|---|---|
| `cashback`, `referral_commission_l1`, `referral_commission_l2`, `referral_product_bonus` | `order` (entero) |
| `redeem` | `order`, `discount` (importe como texto) |
| `redeem_refund`, `earn_reversal` | `order`; `earn_reversal` añade `original_type` |
| `referral_signup_l1`, `referral_signup_l2`, `registration`, `expire` | sin claves |
| `review` | `source` (`product` \| `site`) |
| `birthday` | `year` |
| `transfer_out`, `transfer_fee`, `transfer_in` | `counterpart_name` (nombre visible de la otra parte) y `fee`; **nunca** su id, email o login |
| `admin_credit`, `admin_debit` | `note` (la nota libre del ajuste, si existe) |

El texto de un ajuste manual puede mostrar su nota porque pertenece al propio usuario; las notas
privadas de pedido del libro no salen por esta ruta. `data` no lleva ids de terceros.

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
| `includes/Rest/ReadRoutes.php` | Rutas internas de lectura del monedero y la red (`hwe/v1/loyalty/{summary,transactions,referrals}`) para el BFF. |
