# HWE Loyalty (módulo `loyalty`)

Programa de fidelización para el framework: un **monedero de puntos** que el cliente gana por
comprar, referir y participar, y que canjea en el checkout; más un programa de **referidos de dos
niveles** que paga sus comisiones en esos puntos. Es un módulo opcional del catálogo, como
`coupon-rules` o `shipping`.

> **Estado: en construcción (backend).** Están el arranque, los ajustes, el libro mayor con su
> caducidad, los referidos, las ganancias por pedido y por eventos, el canje en la Store API, las
> transferencias, las rutas internas de lectura y la administración en wp-admin. Las rutas del
> BFF y el contrato del frontend llegan en las siguientes tareas del módulo.

## Qué aporta hoy

| Pieza | Detalle |
|---|---|
| `hwe-loyalty.php` | Clase `HWE_Loyalty`: comprueba que WooCommerce esté activo, carga `includes/` y engancha los ajustes y la administración a `plugins_loaded`. |
| `includes/Settings.php` | Clase `HWE_Loyalty_Settings`: puebla el grupo reservado `loyalty` del Control Center y expone la lectura saneada. |
| `includes/Admin/` | Menú «Fidelización», pantallas, ajuste manual, lote de códigos, CSV, perfil, columnas de usuarios y caja del pedido. |
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
(`activated_plugin`) y de forma idempotente. `admin_init` las autorrepara si faltan en alguno de
esos dos roles —por una actualización o una edición manual de roles— y lo registra; un rol creado
por una instancia nunca se toca. Toda escritura comprueba capacidad **y** nonce, y los manejadores
de `admin-post.php` vuelven a comprobar la capacidad aunque la pantalla ya la haya comprobado.

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

`backend/tests/Unit/Loyalty/AdminTest.php` (PHPUnit + Brain Monkey) fija las reglas del panel:
capacidades solo en `administrator`/`shop_manager`, escritura bloqueada sin `hwe_loyalty_manage`,
nota y nonce obligatorios, cargo limitado al saldo, doble envío sin duplicado, perfil que no
sobrescribe el saldo, referidor con autorreferido y ciclos rechazados, lote reanudable sin
duplicados, celdas CSV inertes, ordenación de usuarios por SQL y escapado de un nombre con
`<script>`. `SettingsTest.php` cubre el esquema y la lectura saneada; la paridad de defaults en
TypeScript la fija `frontend/src/lib/config/defaults.test.ts`.

## Estructura

| Archivo | Responsabilidad |
|---|---|
| `hwe-loyalty.php` | Arranque, comprobación de WooCommerce y carga de `includes/`. |
| `includes/Settings.php` | Esquema del grupo `loyalty` y lectura saneada de los ajustes. |
| `includes/Admin/Admin.php` | Arranque del panel, puerta del módulo y avisos de una sola vez. |
| `includes/Admin/Capabilities.php` | `hwe_loyalty_view`/`hwe_loyalty_manage`, concesión y autorreparación. |
| `includes/Admin/Menu.php` | Menú «Fidelización» y URLs de sus pantallas. |
| `includes/Admin/Pages.php` | Resumen, movimientos, red, ajuste manual y lote de códigos. |
| `includes/Admin/Movements.php`, `Stats.php`, `Csv.php` | Consultas filtradas, cifras y exportación por lotes. |
| `includes/Admin/Adjustments.php`, `Codes.php` | Ajuste manual idempotente y asignación de códigos reanudable. |
| `includes/Admin/UserProfile.php`, `UserColumns.php`, `OrderBox.php`, `Users.php` | Perfil, columnas ordenables, caja del pedido y resolución de usuarios. |
| `includes/Rest/ReadRoutes.php` | Rutas internas de lectura del monedero y la red (`hwe/v1/loyalty/{summary,transactions,referrals}`) para el BFF. |
