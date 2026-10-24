# HWE Loyalty (módulo `loyalty`)

Programa de fidelización para el framework: un **monedero de puntos** que el cliente gana por
comprar, referir y participar, y que canjea en el checkout; más un programa de **referidos de dos
niveles** que paga sus comisiones en esos puntos. Es un módulo opcional del catálogo, como
`coupon-rules` o `shipping`.

> **Estado: completo (backend + contrato del frontend, sin UI).** El plugin es la fuente de
> verdad del monedero y de la red de referidos; el BFF expone `/api/loyalty/*` y el canje del
> carrito, y `lib/loyalty` reúne los tipos y helpers puros. El módulo no trae componentes ni
> vistas: la UI pública es responsabilidad de cada instancia (ver `docs/FRONTEND_CONNECT.md`).

## Qué aporta hoy

| Pieza | Detalle |
|---|---|
| `hwe-loyalty.php` | Clase `HWE_Loyalty`: comprueba que WooCommerce esté activo, carga `includes/` y engancha los ajustes, el libro, los eventos y la administración a `plugins_loaded`. |
| `includes/Settings.php` | Clase `HWE_Loyalty_Settings`: puebla el grupo reservado `loyalty` del Control Center y expone la lectura saneada. |
| `includes/Schema.php` | Tablas propias del módulo (`ledger`, `balances`, `consumptions`, `referrals`) con versión de esquema y migración perezosa. |
| `includes/Ledger.php` | Libro mayor transaccional: abonos, cargos FIFO, devoluciones con su caducidad, caducidad por lotes y saldo. |
| `includes/Api.php` | Punto de entrada estable para otros módulos: `hwe_loyalty_available()`, `hwe_loyalty_credit()`, `hwe_loyalty_reverse_credit()` y `hwe_loyalty_referrer_of()`. |
| `includes/Scheduler.php` | Evento diario `hwe_loyalty_daily` (caducidad y cumpleaños), programado al activar y retirado al desactivar. |
| `includes/Referrals.php` | Códigos, atribución pendiente → confirmada, red N1/N2 y primas de alta. |
| `includes/Earnings.php`, `ProductBonus.php` | Cashback, comisiones, bono por producto, reversión y estado de las ganancias del pedido. |
| `includes/Events.php` | Puntos por reseña (una por pedido, de producto o del sitio) y cumpleaños anual. |
| `includes/Redemption.php` | Extensión `hwe-loyalty` de la Store API, fee negativo, confirmación contra el libro y devolución. |
| `includes/Transfers.php` | Transferencias entre usuarios por código de referido, atómicas y con idempotencia. |
| `includes/Rest/` | Rutas internas `hwe/v1/loyalty/*` (lectura, referidos y transferencia), solo con el secreto del BFF. |
| `includes/PublicName.php` | Nombre visible de un usuario tal como lo ve otro (nunca su login ni su email). |
| `includes/Admin/` | Menú «Fidelización», pantallas, ajuste manual, lote de códigos, CSV, perfil, columnas de usuarios y caja del pedido. |
| `includes/Privacy.php` | Exportador y eliminador de datos personales de WordPress, y limpieza al eliminar una cuenta. |
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

Interruptores que consumen el libro, las rutas y el BFF:

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
  vive en `frontend/src/lib/config/{types,defaults}.ts` y `lib/loyalty/` reúne los tipos y los
  helpers puros.
- Con el módulo apagado, los lectores deben devolver vacío, las rutas del BFF responden 404 y la
  extensión de la Store API no se registra. El detalle para quien construya un frontend está en
  `docs/FRONTEND_CONNECT.md` (Parte A, «Fidelización»).

### Rutas del BFF (`/api/loyalty/*`)

Todas comprueban `requireModule(config, "loyalty")` y el interruptor de su función
(`requireLoyalty`); con el módulo o el interruptor apagados responden **404** como una ruta
inexistente. El `user_id` sale **siempre** de la sesión verificada, nunca de la query ni del
cuerpo, y WordPress se llama por las rutas internas con el secreto del BFF.

| Ruta | Guarda | Qué hace |
|---|---|---|
| `GET /api/loyalty/summary` | Puntos | Saldo, total ganado/usado, valor del saldo en dinero, próximo a caducar y `participant`. |
| `GET /api/loyalty/transactions?page&per_page` | Puntos | Movimientos paginados (tope 100) con su texto ya compuesto en el idioma de la petición. |
| `GET /api/loyalty/referrals?level=1\|2&page&per_page` | Referidos | Red paginada sin ids ni contacto (nombre visible, fecha, estado y unidades generadas) y totales. |
| `GET /api/loyalty/referral-link` | Referidos | `{ code, url }`; la URL se compone con `config.brand.url`. |
| `GET /api/loyalty/validate-code?code=` | Referidos, **pública** | `{ valid }` y nada más; rate-limit por IP. |
| `POST /api/loyalty/transfer` | Transferencias | `guardMutation` estricto, `Idempotency-Key` obligatoria y respuesta discriminada (`ok` o `error` estable). |
| `POST /api/store/cart/points` | Puntos | Declara `{ points }` en la extensión del carrito y devuelve el carrito recalculado. |
| `DELETE /api/store/cart/points` | Puntos | Quita el canje (envía 0) y devuelve el carrito. |

Las rutas de lectura devuelven **5xx tipado** si WordPress falla y registran el evento; nunca
inventan un saldo 0. `validate-code` es la única pública y no revela a quién pertenece el código.

### Canje en la Store API

La extensión se llama `hwe-loyalty` y vive en `extensions.hwe-loyalty` del carrito:

`{ available, reason, balance, points_applied, discount, max_redeemable, min_redeem,
full_payment_available }`. `discount` va en **unidades menores** (string), como el resto de la
Store API. `reason` es `null` cuando el canje se puede aplicar y, si no,
`guest` \| `not_participant` \| `disabled` \| `below_minimum` \| `cart_below_cash_minimum`.

El navegador solo declara la **cantidad**; el descuento (fee negativo no imponible) lo calcula
WordPress en cada recálculo y lo recorta a saldo, `max_redeem_per_order` y el tope del carrito
(con pago completo apagado no cubre el envío ni deja el pedido por debajo de `min_cash_due`).
Al crear el pedido se vuelve a comprobar contra el libro y se descuenta con la clave
`redeem:order:<id>`; las metas `_hwe_loyalty_redeemed_points`, `_hwe_loyalty_discount` y
`_hwe_loyalty_paid_with_points` quedan escritas en ese momento.

- **Foco 1 (saldo que baja entre el carrito y el checkout):** la confirmación falla con el código
  de Store API `hwe_loyalty_insufficient_points` y **no crea el pedido**. El BFF lo traduce a
  **409** con `reason: "loyalty_insufficient_points"`; tras el error, la cantidad que el cliente
  vio aplicada se descarta, así que el reintento cobra lo que el carrito muestra entonces.
- **Canje confirmado:** la cantidad pedida se vacía de la sesión de Woo, para que la siguiente
  compra no gaste unidades que nadie pidió para ella.
- **Pago completo con puntos:** el pedido queda en total 0 y el BFF responde
  `paid_with_points: true`; no se crea pago ni se pasa a «En espera por link». La prueba es el
  libro (meta del pedido), nunca el navegador. Con extras de envío (`shipping_method_ids`) y pago
  completo a la vez, el checkout responde **422** (`loyalty_full_payment_with_shipping_extras`)
  antes de crear nada, porque los extras se suman después por wc/v3.
- Un pedido pagado del todo con unidades no genera ganancias (evita el bucle puntos → puntos).

### Registro y cookie `hwe_ref`

`proxy.ts` deja la cookie httpOnly `hwe_ref` (30 días, `SameSite=Lax`, `Secure` en producción)
cuando una página llega con `?ref=<código con formato válido>`; el último código reemplaza al
anterior y la URL no cambia. Es apta para edge: no consulta WordPress ni la config.

`POST /api/auth/register` acepta `referral_code` opcional: el cuerpo gana a la cookie. Tras crear
la cuenta llama a la atribución y **retira la cookie** salga como salga; un código inválido o un
fallo de WordPress se registra y no rompe el registro. Con el módulo o «Referidos» apagados no
lee ni el cuerpo ni la cookie. El formato del código (8 caracteres del alfabeto sin ambiguos) se
valida en `lib/loyalty/referral-code.ts`, en paridad con `HWE_Loyalty_Referrals::CODE_PATTERN`.

### Nombre visible de otra persona

La red, el recibo de una transferencia y los movimientos muestran el **nombre visible**, nunca el
login, el email ni el id. `HWE_Loyalty_PublicName::of()` usa el `display_name` solo si no es el
login ni un email; si no, el nombre de pila con la inicial del apellido; si tampoco hay, cadena
vacía. Las cuentas creadas por el registro del BFF tienen el login como `display_name`, así que sin
esta regla un cliente vería el usuario de otro.

### Enlace de referido y `brand.url`

La URL es `<config.brand.url>/?ref=<código>`, construida en el BFF (nunca con `home_url()` de
WordPress, que en headless apunta al backend). Si `brand.url` está vacío, la URL sale **relativa**
(`/?ref=…`), como el resto de URLs absolutas del núcleo cuando falta la marca.

## Cómo se gana y se mueve el saldo

El libro (`HWE_Loyalty_Ledger`) es la fuente de verdad: una fila por movimiento, solo crece, con
clave de idempotencia única. Los abonos guardan remanente y caducidad; los cargos consumen
remanentes **FIFO** (primero lo que caduca antes; lo que no caduca, al final) y nunca dejan saldo
negativo. Toda operación bloquea la fila de saldo (`SELECT … FOR UPDATE`) dentro de una
transacción; si algo falla, no queda nada escrito.

- **Caducidad**: `expiry_days` fija la fecha al abonar; `0` = no caduca. El evento diario
  `hwe_loyalty_daily` genera un `expire` por abono vencido con remanente, por lotes e idempotente
  por abono. Cambiar el ajuste solo afecta a abonos nuevos. El resumen avisa de lo que caduca en
  los próximos 30 días.
- **Devolución de un canje**: `reverseDebit()` reabona exactamente lo consumido con la caducidad
  más lejana de los abonos consumidos, o sin caducidad si alguno no caducaba. También es
  idempotente.
- **Saldo**: `balance()` devuelve `balance`, `earned` y `used`; la tabla `balances` es la caché
  transaccional que se actualiza en la misma transacción que el movimiento.

### Ganancias por pedido

`HWE_Loyalty_Earnings::processOrder()` se dispara en `processing`, `completed` y
`payment_complete`, y solo abona si el pedido está **cobrado** (`is_paid()`); `cod` y los medios
equivalentes esperan a `completed` (filtro `hwe_loyalty_collected_on_completion`). Es idempotente
por pedido: un pedido ya procesado, revertido o saltado no vuelve a abonar.

- **Base de cálculo** (`orderBase()`): líneas de producto con sus impuestos, menos el descuento
  del canje (`_hwe_loyalty_discount`). **Sin envío** (los extras del módulo `shipping` llegan como
  `fee_lines` y cuentan como envío) y sin la parte cubierta con unidades. Redondeo a la precisión
  de la moneda de Woo; los puntos, hacia abajo.
- **Cashback** (si activo): `% cashback × base` al comprador.
- **Comisión N1**: `% primera compra` si es el primer pedido cobrado del comprador (por cuenta,
  ignorando el actual) y `% siguientes` después, dentro de `commission_duration_days` desde la
  confirmación de la atribución (`0` = sin límite).
- **Comisión N2** (si `level2_enabled`): `% nivel 2 × base` al referidor del referidor.
- **Bono por producto**: `bono × cantidad` por línea al N1, si el producto declara
  `loyalty_referrer_bonus` (campo de producto entero ≥ 0, no traducible).
- **Exclusiones**: pedidos de invitado, pedidos pagados del todo con unidades y la parte pagada
  con unidades. Un pedido de invitado no se marca como procesado, por si después se vincula a una
  cuenta.
- **Reversión**: `cancelled` y `refunded` **total** generan un `earn_reversal` por beneficiario,
  limitado a su saldo; lo no recuperado queda en la meta del pedido y en una nota privada. Un
  reembolso parcial no revierte.

### Ganancias por eventos

- **Registro**: al confirmarse la cuenta (email verificado o cuenta creada en wp-admin; si el
  futuro «login privado» la marca pendiente, espera a `hwe_account_approved`).
- **Reseña de producto**: una por **pedido** reseñado, aunque se publique en varios productos;
  si nace pendiente, al aprobarse. Reseña de invitado no abona.
- **Reseña del sitio** (módulo `site-reviews`): mismo importe y la misma clave por pedido, por la
  acción `hwe_site_review_published`.
- **Cumpleaños**: `hwe_birthday` (`MM-DD` o `AAAA-MM-DD`) en la zona del sitio, una vez por año
  natural. El 29 de febrero se abona el 28 en años no bisiestos.
- Un importe `0` apaga el evento sin abonar ni dejar rastro.

## Referidos

- **Código**: 8 caracteres de un alfabeto sin ambiguos, aleatorio (nunca derivado del login ni del
  email), con reintento ante colisión del índice único. Se crea al registrar la cuenta y de forma
  perezosa al primer uso en cuentas existentes; el lote de wp-admin asigna los que falten.
- **Atribución**: `HWE_Loyalty_Referrals::attribute()` rechaza código inexistente, propio, de un
  usuario que no participa, con ciclo o a quien ya tiene referidor (`ignored:<motivo>`) sin
  escribir. Nace `pending` y pasa a `confirmed` cuando la cuenta está confirmada y, si el login
  privado lo pide, aprobada: lo que llegue último dispara la confirmación
  (`hwe_email_verified` / `hwe_account_approved`). Una cuenta creada en wp-admin se considera
  confirmada.
- **Primas de alta**: al confirmar se abonan `referral_signup_l1`, `referral_signup_l2` (si nivel 2)
  y `registration` (si su importe > 0), una sola vez por usuario. Las comisiones por compra no
  esperan a la confirmación: dependen de que el pedido esté cobrado y de que exista atribución
  (`pending` o `confirmed`).
- **Red**: `network()` devuelve N1/N2 paginados y totales, sin ids, email ni login; solo el nombre
  visible. Un referidor eliminado no rompe la red ni recibe comisiones futuras.

## Transferencias

Del usuario de la sesión al dueño de un **código de referido** (nunca por id). Reglas:
`transfers_enabled` y puntos activos; emisor y receptor participantes; no a uno mismo; mínimo
`transfer_min`; comisión `ceil(points × %/100)` que se queda la tienda (el receptor recibe el
resto); tope diario por emisor (`0` = sin tope) contado desde el inicio del día en la zona del
sitio. El cargo, la comisión y el abono se escriben en **una sola transacción** del libro y se
bloquean las dos filas de saldo en orden de id ascendente para no interbloquear.

La clave de idempotencia la manda el BFF (`Idempotency-Key`); el libro la cualifica por emisor.
Repetir la misma clave con los mismos parámetros devuelve la misma respuesta sin mover unidades;
con otros parámetros responde `conflict`. La respuesta solo lleva el nombre visible del receptor
si el envío salió bien; nunca su email, login ni id. Los motivos estables son `disabled`,
`invalid_request`, `conflict`, `below_minimum`, `insufficient`, `invalid_recipient`, `self`,
`daily_cap` y `failed`.

## Rutas internas (`hwe/v1/loyalty/…`)

Las consume **solo el BFF**, con `X-HWE-Internal-Secret`; `hwe-bff-only` cierra todo
`/hwe/v1/loyalty/` (sin el secreto responde 404, como una ruta inexistente) y el `user_id` sale
siempre de la sesión verificada, nunca del navegador. Con el módulo o el interruptor de la función
apagados responden `404 rest_no_route`.

| Ruta | Guarda | Devuelve / hace |
|---|---|---|
| `GET loyalty/summary?user_id` | Puntos | `{ participant, balance, earned, used, expiring: { points, date } }`. El aviso de caducidad mira 30 días (spec §5.3). |
| `GET loyalty/transactions?user_id&page&per_page` | Puntos | `{ items: [{ id, type, points, data, created_at, expires_at }], total, pages }`, del más nuevo al más antiguo. `per_page` por defecto 20, mínimo 1 y tope 100; `pages = ceil(total / per_page)`. |
| `GET loyalty/referrals?user_id&level=1\|2&page&per_page` | Referidos | `{ items: [{ name, date, status, points_generated }], total, pages, totals: { level1, level2, pending, earned } }`. `level` por defecto 1; fuera de `1\|2`, 400. |
| `GET loyalty/referral-code?user_id` | Referidos | `{ code }` (lo crea si falta). Sin `user_id` válido, 400. |
| `GET loyalty/validate-code?code` | Referidos | `{ valid }` y nada más: no revela el dueño ni sus datos. |
| `POST loyalty/attribute` | Referidos | `{ result: "attributed" \| "ignored:<motivo>" }` para una cuenta recién creada. |
| `POST loyalty/transfer` | Transferencias | `{ ok, sent, received, fee, recipient_name }` o `{ error }` con el motivo estable. El BFF pone `user_id`, `code`, `points`, `idempotency_key` y `note`. |

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

**En el BFF** (`lib/loyalty/describe.ts`), no en WordPress: así sigue el idioma de la
petición con `messages/` y su paridad de idiomas, y el backend no guarda descripciones con el
nombre de la unidad (spec §5.1). `transactions` devuelve `type` + `data` estructurado; este es el
contrato de `data` por tipo (lista blanca: un tipo desconocido, p. ej. añadido por
`hwe_loyalty_entry_types`, viaja con `data` vacía):

| `type` | Claves de `data` |
|---|---|
| `cashback`, `referral_commission_l1`, `referral_commission_l2`, `referral_product_bonus` | `order` (entero) |
| `redeem` | `order`, `discount` (importe como texto) |
| `redeem_refund`, `earn_reversal` | `order` (si la referencia original es un pedido); `earn_reversal` añade `original_type` y, si la reversión viene de `hwe_loyalty_reverse_credit()`, `credit` (id del abono revertido) |
| `referral_signup_l1`, `referral_signup_l2`, `registration`, `expire` | sin claves |
| `review` | `source` (`product` \| `site`) |
| `birthday` | `year` |
| `transfer_out`, `transfer_fee`, `transfer_in` | `counterpart_name` (nombre visible de la otra parte) y `fee`; **nunca** su id, email o login |
| `admin_credit`, `admin_debit` | `note` (la nota libre del ajuste, si existe) |

El texto de un ajuste manual puede mostrar su nota porque pertenece al propio usuario; las notas
privadas de pedido del libro no salen por esta ruta. `data` no lleva ids de terceros.

## Hooks estables

Acciones (después del COMMIT; solo ids numéricos, nunca emails, logins ni nombres):

| Acción | Firma | Cuándo |
|---|---|---|
| `hwe_loyalty_credited` | `( int $userId, int $points, string $type, array $ref )` | Tras un abono. `$ref` es `['type' => string\|null, 'id' => string\|null]` y también lo disparan las devoluciones (`redeem_refund`). |
| `hwe_loyalty_debited` | `( int $userId, int $points, string $type, array $ref )` | Tras un cargo. |
| `hwe_loyalty_expired` | `( int $userId, int $points, string $type, array $ref )` | Tras caducar un abono; `$type` es `expire` y `$ref` es `['type' => 'entry', 'id' => '<id del abono>']`. |
| `hwe_loyalty_transferred` | `( int $fromUserId, int $toUserId, int $points, int $received, int $fee )` | Tras una transferencia confirmada. |
| `hwe_loyalty_attribution_confirmed` | `( int $userId, ?int $referrerId )` | La primera vez que una atribución pasa a `confirmed`. |

Filtros:

| Filtro | Firma | Para qué |
|---|---|---|
| `hwe_loyalty_order_earnings` | `( array $earnings, WC_Order $order, float $base, array $settings ): array` | Reglas propias de puntos por compra antes de abonar. Cada entrada es `user_id`, `type`, `points`, `ref` y `data`; las incompletas o de un tipo que no es abono se descartan y se registran. |
| `hwe_loyalty_collected_on_completion` | `( string[] $methods ): string[]` | Medios que esperan a `completed` para contar como cobrados. Por defecto `['cod']`. |
| `hwe_loyalty_entry_types` | `( array<string, "credit"\|"debit"> $types ): array` | Tipos de movimiento extra. Se descarta y se registra una entrada mal formada. |
| `hwe_loyalty_participant_roles` | `( string[] $roles ): string[]` | Roles que participan. Los defaults son `customer` y `subscriber`. |

### Funciones públicas (contrato para otros módulos)

`includes/Api.php` publica el punto de entrada que usan otros módulos (por ejemplo, membresías)
para abonar o revertir puntos sin conocer las clases internas. Las cuatro funciones existen **solo
con el plugin cargado**: quien las consume comprueba `function_exists()` y, además, la función
concreta con `hwe_loyalty_available()`.

| Función | Firma | Qué hace |
|---|---|---|
| `hwe_loyalty_available` | `( string $feature ): bool` | `points` exige el módulo activo y «Puntos» encendido; `referrals`, además, «Referidos». Cualquier otro valor devuelve `false` (y se registra). |
| `hwe_loyalty_credit` | `( int $userId, int $points, string $type, string $key, array $ref = [], array $data = [] ): ?int` | Abona por el libro, con la caducidad configurada y la idempotencia por `$key`. Devuelve el id del movimiento o `null` sin escribir si los puntos están apagados, el usuario no participa, el tipo no está declarado como abono o la clave ya existía. `$ref` es la referencia tipada habitual; `$data`, la nota estructurada (sin textos de cliente). |
| `hwe_loyalty_reverse_credit` | `( string $key, string $reversalKey ): ?int` | Revierte el abono `$key` con un cargo `earn_reversal` limitado al saldo actual (nunca deja el saldo negativo) e idempotente por `$reversalKey` (el cargo se guarda como `reversal:<reversalKey>`). Devuelve el id del cargo o `null` si la clave original no existe o no es un abono, si no queda saldo o si esa reversión ya se aplicó. |
| `hwe_loyalty_referrer_of` | `( int $userId ): ?int` | Referidor N1 **confirmado** del usuario. Devuelve `null` si no hay atribución, si sigue `pending`, si el referidor ya no existe o si el plugin no está cargado. Es la lectura que deben usar los módulos que pagan por la red: una atribución pendiente no genera comisión. Solo viaja el id numérico, nunca nombre, email ni login. No exige que «Puntos» o «Referidos» estén encendidos; el consumidor decide su propia disponibilidad con `hwe_loyalty_available()`. |

El tipo de movimiento propio se declara antes con `hwe_loyalty_entry_types`; sin declararlo,
`hwe_loyalty_credit()` falla cerrado y no escribe. Revertir no exige que «Puntos» siga
encendido: es una corrección contable y debe funcionar aunque el programa se apagara después
del abono. Todos los rechazos se registran con `error_log('[hwe] loyalty: …')` y solo con ids y
claves técnicas.

Hooks del núcleo y de otros módulos que consume:

- `hwe_email_verified( int $userId )` — lo dispara `hwe-auth` una sola vez al verificar el email.
- `hwe_account_approved( int $userId )` y el filtro `hwe_account_is_pending( bool $pending, int $userId )` — contrato **provisional** con el futuro «login privado». Este módulo solo los consume.
- `hwe_site_review_published( int $reviewId, int $userId, int $orderId )` — de `hwe-site-reviews`; abona la reseña del sitio una vez por pedido.

Todo se documenta también en `docs/PLUGIN_DEVELOPMENT.md` como contrato del framework.

## Privacidad

El módulo se engancha a las herramientas de privacidad de WordPress
(**Herramientas → Exportar datos personales** y **Borrar datos personales**) con el grupo
«Fidelización», más la acción `delete_user` para cuando se elimina una cuenta desde Usuarios.
La lógica vive en `includes/Privacy.php` (`HWE_Loyalty_Privacy`); los logs de todo el ciclo
llevan solo ids numéricos, nunca emails, logins ni nombres.

### Exportador

Paginado por lotes de 100 movimientos. La primera página lleva un elemento de resumen con
saldo, total ganado, total usado, código de referido, nombre visible del referidor, estado de la
atribución y sus fechas; las páginas siguientes solo movimientos. Cada movimiento exporta fecha
(UTC), tipo técnico, puntos, caducidad (si la tiene), pedido (si lo tiene) y nota libre (si la
tiene). En una transferencia se añade el **nombre visible de la otra parte**, nunca su id, email
o login. Un usuario sin fila de referidos o sin movimientos devuelve `done: true`.

### Eliminador

La cuenta puede seguir existiendo; por eso **conserva la fila de saldo** y `user_id` en el libro
(sin él, el saldo y la caducidad dejarían de cuadrar). Decisiones:

- **Se conservan** los importes (`points`, `remaining_points`), el tipo, las fechas, el actor y
  la referencia tipada (pedido, usuario…): son la contabilidad del programa y la base de la red.
- **Se vacían** `note` y `data` de los movimientos del usuario: `data` puede llevar ids de la
  otra parte de una transferencia, nombres o notas; el detalle que hace falta para cuadrar vive
  en columnas propias. Las notas manuales del administrador (texto libre) desaparecen.
- El **código de referido** se sustituye por uno con prefijo `ERASED-` que no pasa la validación
  de formato (`NOT NULL UNIQUE` impide dejarlo vacío): ya no sirve para atribuir ni transferir y
  el exportador lo omite.
- Su **fila de referidos** queda sin referidor y en `pending`; sus **referidos quedan sin
  referidor**. No se mueven puntos ya pagados.
- Los movimientos se procesan por lotes de 100 sin cargar el libro entero en memoria; la
  operación es **idempotente** (repetirla no cambia nada) y `done` es `false` mientras queden
  movimientos con nota o datos por limpiar.
- `items_retained` es `true` cuando hay movimientos, y el mensaje explica que se conservan
  anonimizados por obligación contable. Los abonos de **otros** usuarios con referencia al
  usuario eliminado no se tocan.

### Cuenta eliminada (`delete_user`)

Antes de que WordPress borre la cuenta: sus referidos quedan sin referidor, su propia fila de
referidos se **elimina**, su fila de saldo se **elimina** (sale del circuito) y sus movimientos
se conservan anonimizados con el mismo criterio que el eliminador, por lotes. El `reassign` de
WordPress se **ignora a propósito**: ni la red ni los puntos se heredan. Como el libro y las
referencias se conservan, las lecturas de red toleran referencias huérfanas, y una compra futura
del antiguo referido ya no genera comisión para el id eliminado (Foco de revisión 4).

## Administración en wp-admin

El menú **Fidelización** (título neutro, sin el nombre de la unidad) solo se registra con el
plugin activo. Si el interruptor «Puntos» está apagado, las pantallas siguen disponibles en modo
de auditoría (leer el histórico y la red) con un aviso; las acciones manuales siguen siendo una
herramienta del equipo y no dependen de que la tienda reparta puntos automáticamente.

| Pantalla | Qué hace |
|---|---|
| **Resumen** | Puntos en circulación, emitidos, canjeados y caducados en el periodo (30/90/365 días), referidos confirmados y pendientes, y los mejores referidores. |
| **Movimientos** | Listado paginado con filtros por usuario (id, email o login), tipo, rango de fechas y pedido. El botón **Exportar CSV** descarga con el mismo filtro. |
| **Red** | Busca un usuario y muestra su referidor, los totales de la red y sus referidos N1/N2 paginados. Nunca muestra email ni login de los referidos. |
| **Ajuste manual** | Abona o carga unidades con **nota obligatoria**. Un cargo mayor que el saldo se rechaza y nunca deja saldo negativo. |
| **Códigos** | Asigna código de referido solo a participantes que no tienen, por lotes acotados. Si la petición se corta, volver a pulsar continúa donde quedó: las filas ya creadas no se repiten. |

### Capacidades

- `hwe_loyalty_view`: leer el menú, el perfil, las columnas y la caja del pedido.
- `hwe_loyalty_manage`: ajustar saldo, cambiar código o referidor y lanzar el lote.

Se conceden **solo** a `administrator` y `shop_manager`, al activar el plugin
(`activated_plugin`) y de forma idempotente. `admin_menu` (antes de registrar el menú, porque
`wp-admin/menu.php` comprueba el acceso antes de `admin_init`) y `admin_init` las autorreparan si
faltan en alguno de esos dos roles —por una actualización o una edición manual de roles— y lo
registran; un rol creado por una instancia nunca se toca. Toda escritura comprueba capacidad **y**
nonce, y los manejadores de `admin-post.php` vuelven a comprobar la capacidad aunque la pantalla ya
la haya comprobado.

### Ajuste manual e idempotencia

La pantalla genera una clave de idempotencia por render del formulario. El libro la guarda en su
índice único: un doble clic o un reenvío del navegador devuelve «ya aplicado» y no mueve puntos.
El movimiento queda con tipo `admin_credit`/`admin_debit`, actor `admin`, el id del administrador y
la nota en su columna. El cargo comprueba el saldo y, si otra petición lo gastó entre la
comprobación y la escritura, el libro vuelve a rechazarlo.

### Perfil de usuario

Muestra saldo, total ganado/usado, código editable, referidor editable y los últimos diez
movimientos. **No existe un campo de saldo**: el saldo se cambia con un abono o un cargo
explícito (misma clase y mismas garantías que la pantalla de ajuste). Los cambios de código y
referidor delegan en `HWE_Loyalty_Referrals`, que valida formato, unicidad, autorreferido y
ciclos; un valor rechazado deja aviso y no escribe. Leer o guardar el perfil no crea códigos: la
lectura consulta la fila existente y el lote es la vía para generarlos.

### Listado de usuarios

Dos columnas, **Saldo** y **Referidor**, ordenables. La ordenación se resuelve en SQL con un
`LEFT JOIN` a `hwe_loyalty_balances` o `hwe_loyalty_referrals` y su `ORDER BY`; no se cargan todos
los usuarios para ordenarlos en PHP.

### Pedido

Caja «Fidelización» con puntos canjeados, descuento aplicado, marca de pago completo con puntos y
las ganancias generadas con su estado (`processed`/`skipped`/`reversed`) y sus beneficiarios. Usa
las constantes de `HWE_Loyalty_Redemption` y `HWE_Loyalty_Earnings`, nunca nombres de meta
repetidos. Se registra en `shop_order` y en la pantalla de
`wc_get_page_screen_id( 'shop-order' )` (HPOS).

### CSV de movimientos

Columnas: `id`, `fecha_utc`, `usuario_id`, `usuario` (nombre visible), `tipo`, `cantidad`,
`remanente`, `referencia`, `actor`, `nota`, `caduca_utc`. **No** incluye email ni login: el
listado de usuarios ya los muestra al equipo y un archivo descargado no necesita la identidad
completa. El volcado va por lotes de 500 filas directamente a la salida (streaming) y cada celda
pasa por `csvCell`, que antepone `'` a lo que empiece por `=`, `+`, `-`, `@`, tabulador o retorno
de carro para que Excel no lo ejecute como fórmula.

### Módulo apagado

Con el plugin inactivo no se registra el menú ni ninguna caja, columna o manejador: la puerta está
en `HWE_Loyalty_Admin::register()` y el plugin ni siquiera llega a arrancar. No se mantiene lectura
alguna para auditar sin el módulo activo.

## Pruebas

`backend/tests/Unit/Loyalty/` (PHPUnit + Brain Monkey) cubre el esquema y los ajustes, el libro
(saldo no negativo, FIFO, caducidad, devoluciones e idempotencia), la programación diaria, los
referidos (códigos, ciclos, confirmación y primas), las ganancias por pedido y eventos, el canje
(recortes, mínimos, pago completo y Foco 1), las transferencias, las rutas internas (secreto y
guardas), la administración, la privacidad y el contrato público de `Api.php` (disponibilidad,
abono idempotente y reversión limitada al saldo). `PublicNameTest.php` fija la regla del nombre
visible.

En el frontend, los tests colocados de `frontend/src/lib/loyalty/` cubren los helpers puros y los
lectores de servidor; los de `app/api/loyalty/*`, `app/api/store/cart/points` y
`app/api/store/checkout` cubren las guardas (404), la sesión, el CSRF, la idempotencia y el
`paid_with_points`; `tests/unit/proxy-referral.test.ts` fija la cookie `hwe_ref`. La verificación
en vivo es `sh backend/scripts/verify-modules.sh` (el toggle de `loyalty` comprueba tablas, rutas
internas y la extensión de la Store API) y la pasada e2e sin módulos
(`frontend/tests/e2e/modules-off.spec.ts`).

## Estructura

| Archivo | Responsabilidad |
|---|---|
| `hwe-loyalty.php` | Arranque, comprobación de WooCommerce y carga de `includes/`. |
| `includes/Settings.php` | Esquema del grupo `loyalty` y lectura saneada de los ajustes. |
| `includes/EntryTypes.php` | Tipos de movimiento (`credit`/`debit`) en un solo sitio, ampliables por filtro. |
| `includes/Schema.php` | Tablas, versión de esquema y migración perezosa. |
| `includes/Ledger.php` | Libro transaccional, FIFO, caducidad, devoluciones, saldo e historial. |
| `includes/Api.php` | Punto de entrada estable para otros módulos (disponibilidad, abono y reversión). |
| `includes/Scheduler.php` | Evento diario de caducidad y cumpleaños. |
| `includes/Referrals.php` | Códigos, atribución, confirmación, red y primas de alta. |
| `includes/Earnings.php` | Ganancias por pedido, estado en la meta y reversión. |
| `includes/ProductBonus.php` | Campo de producto `loyalty_referrer_bonus`. |
| `includes/Events.php` | Puntos por reseña (producto o sitio) y cumpleaños. |
| `includes/Redemption.php` | Extensión de la Store API, fee, confirmación, metas y devolución. |
| `includes/Transfers.php` | Transferencias por código, comisión, tope e idempotencia. |
| `includes/PublicName.php` | Nombre visible de un usuario para otro. |
| `includes/Admin/Admin.php` | Arranque del panel, puerta del módulo y avisos de una sola vez. |
| `includes/Admin/Capabilities.php` | `hwe_loyalty_view`/`hwe_loyalty_manage`, concesión y autorreparación. |
| `includes/Admin/Menu.php` | Menú «Fidelización» y URLs de sus pantallas. |
| `includes/Admin/Pages.php` | Resumen, movimientos, red, ajuste manual y lote de códigos. |
| `includes/Admin/Movements.php`, `Stats.php`, `Csv.php` | Consultas filtradas, cifras y exportación por lotes. |
| `includes/Admin/Adjustments.php`, `Codes.php` | Ajuste manual idempotente y asignación de códigos reanudable. |
| `includes/Admin/UserProfile.php`, `UserColumns.php`, `OrderBox.php`, `Users.php` | Perfil, columnas ordenables, caja del pedido y resolución de usuarios. |
| `includes/Rest/ReadRoutes.php` | Rutas internas de lectura (`summary`, `transactions`, `referrals`). |
| `includes/Rest/ReferralRoutes.php` | Rutas internas de referidos (`attribute`, `validate-code`, `referral-code`). |
| `includes/Rest/TransferRoutes.php` | Ruta interna de transferencias (`transfer`). |
| `includes/Privacy.php` | Exportador y eliminador de datos personales; limpieza al eliminar una cuenta. |
