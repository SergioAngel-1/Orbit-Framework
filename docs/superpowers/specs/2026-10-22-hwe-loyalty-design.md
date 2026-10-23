# Módulo `loyalty` (`hwe-loyalty`) — diseño

> Fecha: 2026-10-22 · Estado: aprobado en conversación, pendiente de plan.
> Origen: refactorización completa de un plugin de referidos y puntos hecho para una instancia
> anterior (`/home/sergi/Documentos/Proyectos/Flores Inc/backend/app/public/wp-content/plugins/floresinc-referrals-points`).
> Se replican sus **flujos**, no su código: el código se escribe con las prácticas del framework.
> **Nada** del nombre, slugs, textos ni supuestos de esa instancia entra en el repo (puerta
> anti-instancia; esta carpeta `docs/superpowers/` está exenta, el resto no).

## 1. Intención

Dar al framework un **programa de fidelización reutilizable**: un monedero de puntos que el cliente
gana por comprar, referir y participar, y que canjea en el checkout; más un programa de referidos de
dos niveles que paga sus comisiones en esos puntos. Es un **módulo opcional** más del catálogo,
como `coupon-rules` o `shipping`.

El framework es backend + arquitectura (`AGENTS.md §1.1`): este módulo **no diseña UI**. Entrega el
plugin de WordPress con su administración, el contrato público (`config.loyalty`), las rutas del BFF
y la lógica de `lib/`. Las vistas (saldo, historial, red, widget de canje, campo de código en el
registro) las construye cada instancia consumiendo ese contrato.

## 2. Decisiones del usuario

1. **Un solo módulo**, id `loyalty`, plugin `backend/wp-content/plugins/hwe-loyalty/`, con dos
   interruptores en su configuración: **Puntos** y **Referidos**. Referidos sin Puntos no funciona
   (las comisiones se pagan en puntos): con Puntos apagado, Referidos se comporta como apagado.
2. **Nombre e icono de la moneda** editables desde la configuración del módulo y expuestos al
   frontend. Ningún texto del módulo (notas de pedido, descripciones de movimientos, mensajes de
   error, correos) lleva el nombre de la moneda escrito a mano.
3. **Comisión de referido igual para todos los referidores**: un % sobre la primera compra del
   referido, otro % sobre las siguientes y otro % para el referidor de nivel 2. Sin niveles de
   membresía ni grupos.
4. **Cashback del comprador opcional**, con su propio %, apagado por defecto. (En el origen el
   comprador no ganaba nada por su propia compra.)
5. **Pagar el pedido completo con puntos opcional**, configurable, apagado por defecto. Con él
   apagado, el canje siempre deja al menos el envío por pagar.
6. **Transferencias entre usuarios opcionales** (por código de referido), configurables, apagadas
   por defecto.
7. **Comprar puntos con dinero queda fuera.**
8. **Aprobación manual de usuarios («login privado») es otro proyecto** con su propio spec. Este
   módulo solo se engancha a un contrato genérico (§6.6).
9. **Frontend: solo preparar para consumir** — contrato, BFF y `lib/`; ni componentes ni vistas.
10. **Con el pago completo apagado, los puntos nunca llevan el total a 0**: siempre queda por pagar
    en dinero un **mínimo configurable** en el panel (aunque el envío sea gratis o haya recogida).
11. **Los puntos por alta de un referido (y los de registro) esperan a que el usuario verifique su
    email**; si el futuro «login privado» está encendido, esperan también a la aprobación de la
    cuenta.

## 3. Objetivos, no-objetivos y anti-objetivos

**Objetivos**
- Paridad de flujos con el origen (§4), sin sus defectos (§10).
- Contabilidad de puntos **exacta e idempotente**: ningún evento acredita o descuenta dos veces,
  ningún saldo queda negativo, cada punto tiene trazabilidad (quién, por qué, sobre qué pedido).
- Seguridad del framework: el navegador nunca decide un importe; todo pasa por el BFF; WordPress
  solo atiende al BFF; seguridad falla cerrada.

**No-objetivos**
- UI de tienda (componentes o vistas en el núcleo o en el starter).
- Compra de puntos, membresías, newsletter, eventos/tickets, herramientas de recálculo del origen.
- Migración de datos desde el plugin de origen (no hay instancia que lo pida; ver §11).
- Más de dos niveles de referidos.

**Anti-objetivos** (lo haría un fracaso aunque «funcione»)
- Que el cliente pueda fijar el descuento, el número de puntos efectivamente descontados o el pago
  completo desde el navegador.
- Que un reintento, un webhook repetido o dos pestañas den puntos o descuentos dobles.
- Que el código de referido revele el login o el email del usuario (el framework bloquea la
  enumeración de usuarios en `security.php`).
- Que se cuele vocabulario de la instancia de origen en el repo.

## 4. Flujos a replicar (paridad funcional)

| Flujo | Origen | Destino |
|---|---|---|
| Saldo y movimientos | Tablas propias de saldo y transacciones | Libro mayor con saldo derivado (§5.1) |
| Caducidad | Configurable en días; en la práctica nunca caducaba | Caducidad real por abono, consumo FIFO (§5.3) |
| Comisión al referidor N1 (1.ª compra / siguientes) | % por nivel de membresía | % único configurable (§6.2) |
| Comisión al referidor N2 | % por membresía, interruptor | % único + interruptor (§6.2) |
| Bono por producto (al referidor) | Meta de producto | Campo de producto del módulo (§6.2) |
| Cashback del comprador | No existía | Opcional (§6.2) |
| Puntos por registro, reseña, cumpleaños | Por evento activado | Igual, cada evento con su interruptor e importe (§6.3) |
| Puntos al referidor por alta (N1 y N2) | Al aprobar la cuenta | Al confirmarse la atribución (§6.6) |
| Canje en checkout | Cupón dinámico / fee negativo enviado por el cliente | Extensión de la Store API calculada en WP (§7) |
| Devolución del canje | Al cancelar/fallar/reembolsar | Igual, una sola vez (§7.4) |
| Pago completo con puntos | Medio de pago del tema | Camino nativo de total 0, opcional (§7.5) |
| Transferencias | Por código, mínimo 100, comisión opcional | Igual, opcional y configurable (§8) |
| Código y enlace de referido | Login + 4 dígitos, `?ref=` → cookie | Código aleatorio no derivado; `?ref=` → cookie httpOnly del BFF (§6.5) |
| Validar código en el registro | Endpoint público con datos del referidor | BFF público con rate-limit, sin datos personales (§9.2) |
| Red de referidos y estadísticas | N1/N2 con ganancias por referido | Igual, paginado (§9.2) |
| Administración | Dashboard, transacciones, red, ajuste manual, perfil, columnas de usuarios | Igual (§12) |

## 5. Modelo de datos (WordPress)

Tablas propias con `dbDelta` y versión de esquema en una opción, como `hwe-consent-log`
(`DB_VERSION_OPTION`, migración al detectar versión vieja, no solo en la activación). Prefijo
`{$wpdb->prefix}hwe_loyalty_`. Motor InnoDB (se usan transacciones y bloqueos de fila).

### 5.1 Libro mayor (`hwe_loyalty_ledger`)

Una fila por movimiento; **solo crece** (no se borran ni se reescriben filas salvo el campo de
remanente de los abonos). Campos mínimos:

- id, usuario, puntos con signo (abono > 0, cargo < 0), tipo de movimiento (§5.2),
- referencia tipada (tipo de referencia + id: pedido, usuario, comentario, movimiento de origen…),
- **clave de idempotencia** con índice único (ej. `earn:order:<id>:<usuario>`,
  `redeem:order:<id>`, `refund:order:<id>`, `expire:entry:<id>`, `transfer:<uuid>:out`),
- para abonos: remanente sin consumir y fecha de caducidad (nula = no caduca),
- actor (sistema / id del administrador / el propio usuario), nota legible, fecha UTC.

La descripción que ve el cliente **no** se guarda como texto libre con el nombre de la moneda: se
guarda el tipo y unos datos estructurados (ej. número de pedido, nombre visible del referido) y el
texto se compone al leer, con la moneda de la configuración vigente. La nota manual del
administrador sí es texto libre.

### 5.2 Tipos de movimiento

Abonos: `cashback`, `referral_commission_l1`, `referral_commission_l2`, `referral_product_bonus`,
`referral_signup_l1`, `referral_signup_l2`, `registration`, `review`, `birthday`, `transfer_in`,
`redeem_refund`, `admin_credit`.
Cargos: `redeem`, `transfer_out`, `transfer_fee` (si se modela aparte), `expire`, `admin_debit`,
`earn_reversal` (anulación de lo ganado por un pedido cancelado/reembolsado, §6.4).
El conjunto se declara en un solo sitio del plugin y se refleja como unión de literales en
`lib/loyalty/types.ts`.

### 5.3 Saldo y caducidad

- **Saldo** = suma de remanentes de abonos vivos. Se mantiene además una tabla de saldos por
  usuario (`hwe_loyalty_balances`: saldo, total ganado, total usado, última actualización) como
  caché transaccional; toda operación que mueve puntos **bloquea la fila del usuario**
  (`SELECT … FOR UPDATE`) dentro de una transacción, escribe el movimiento y actualiza saldo y
  remanentes en la misma transacción. El saldo nunca baja de 0 (la operación falla y no escribe).
- **Consumo FIFO**: un cargo consume los remanentes de los abonos que caducan antes (los que no
  caducan, al final). El movimiento de cargo registra qué abonos consumió (tabla puente o campo
  estructurado; a criterio del implementador) para poder devolverlos al revertir.
- **Devolución de un canje** (`redeem_refund`): reabona los mismos puntos; caducidad del nuevo
  abono = la más lejana de los abonos consumidos, o «no caduca» si alguno no caducaba (decisión
  propia: el cliente no debe perder puntos porque la tienda canceló).
- **Caducidad**: tarea diaria (WP-Cron; en prod lo ejecuta el contenedor `wp-cron`) que, por lotes,
  genera un `expire` por cada abono con remanente > 0 y fecha vencida, idempotente por abono.
  `0` días = los puntos no caducan. Cambiar el ajuste solo afecta a abonos nuevos.
- **Aviso de caducidad**: el resumen expone «puntos que caducan en los próximos N días» y su fecha
  (N fijo de 30, decisión propia). No se envían correos (no-objetivo; una instancia puede hacerlo
  con la acción de §13).

### 5.4 Referidos (`hwe_loyalty_referrals`)

Una fila por usuario participante: usuario (único), código (único), referidor (nullable),
fecha de atribución, estado de la atribución (`pending` | `confirmed`), fecha de confirmación.
Solo se admite un referidor por usuario y **no se cambia** una vez confirmado salvo por un
administrador (con registro en el libro: ajuste de red, sin mover puntos ya pagados).
Se impiden ciclos (A refiere a B y B a A) y el autorreferido.

### 5.5 Metas

- Pedido: puntos canjeados, descuento aplicado (decimal en moneda de la tienda), estado del
  procesamiento de ganancias, marcas de devolución. Prefijo `_hwe_loyalty_`. Visibles en wp-admin
  (caja en el pedido) y en wc/v3 (para el BFF y para integraciones).
- Usuario: `hwe_birthday` (formato `MM-DD` o `AAAA-MM-DD`); el framework hoy no lo rellena — lo
  escribe la instancia (o un futuro campo de cuenta). Marca anual de cumpleaños abonado.
- Producto: el bono al referidor se declara como **campo de producto del módulo** registrado con
  `hwe_product_fields` (entero ≥ 0, no traducible), así el núcleo pinta la caja, sanea y lo expone
  por `Product.hweFields`. (Decisión propia: reutiliza la infraestructura en vez de un meta suelto.)

## 6. Ganar puntos

### 6.1 Cuándo un pedido «cuenta»

Un pedido genera ganancias **solo cuando está cobrado**: transición a `processing` o `completed`
con `is_paid()` verdadero (en el framework, tras el webhook verificado de la pasarela). **Excepción:
efectivo (`cod`)** — Woo lo pasa a `processing` al crearse sin haber cobrado, así que sus ganancias
esperan a `completed` (decisión propia: no se abonan puntos por algo no cobrado, y la reversión
limitada al saldo no podría recuperarlos si ya se gastaron). Lo mismo para cualquier medio offline
equivalente (el criterio vive en un solo sitio, filtrable). Se escucha en WordPress (fuente de verdad), no
en el BFF. Procesamiento **idempotente por pedido** (claves únicas del libro), seguro ante
reentradas del mismo hook y ante pedidos creados por wc/v3 o desde wp-admin.

No generan ganancias: pedidos de invitado (salvo el cashback, que exige cuenta), pedidos pagados
íntegramente con puntos (decisión propia: evita bucles de puntos que generan puntos), y la parte del
importe pagada con puntos.

**Base de cálculo** (para cashback y comisiones): total pagado en dinero del pedido, **sin envío**
(los extras de envío del módulo `shipping`, que llegan como `fee_lines`, cuentan como envío) y sin
la parte cubierta con puntos (decisión propia; el origen usaba el total con envío). Redondeo
de puntos siempre hacia abajo a entero.

### 6.2 Por compra

- **Cashback** (si activo): `% cashback × base` al comprador, con caducidad.
- **Comisión N1** (si Referidos activo y el comprador tiene referidor confirmado): `% primera
  compra` si es el primer pedido pagado del comprador (por cuenta, ignorando el actual), si no
  `% siguientes`. Solo dentro de la **duración de la comisión** (días desde la confirmación de la
  atribución; 0 = sin límite).
- **Comisión N2** (si nivel 2 activo): `% nivel 2 × base` al referidor del referidor, mismas reglas
  de duración (contadas desde la atribución del comprador).
- **Bono por producto**: `bono × cantidad` por línea al referidor N1, si el producto lo tiene.
- Cada abono deja una **nota privada** en el pedido (trazabilidad para el equipo) sin el nombre de
  la moneda escrito a mano.

### 6.3 Por eventos

Cada evento tiene interruptor e importe fijo en la configuración:
- **Registro**: al confirmarse la cuenta (email verificado y, si aplica, aprobada; §6.6).
- **Reseña de producto**: en el framework las reseñas de producto nacen aprobadas, exigen un pedido
  completado y, con «misma reseña para todo el pedido», se publican en cada producto del pedido
  (`app/api/store/reviews/[productId]`). Por eso el abono es **uno por pedido reseñado**, no uno por
  producto (clave de idempotencia por pedido), al publicarse la primera reseña con autor de ese
  pedido. Si alguna vez una reseña nace pendiente de moderación, se abona al aprobarse. (Decisión
  propia; corrige además el origen, que abonaba reseñas sin moderar.)
- **Reseña del sitio** (módulo `site-reviews`, si está activo): mismo importe, una por pedido (es su
  regla nativa). Requiere que `hwe-site-reviews` dispare una acción estable al publicarse una reseña
  con autor (hoy no la tiene; añadirla es parte de este trabajo).
- **Cumpleaños**: tarea diaria; una vez por año natural y usuario con `hwe_birthday` = hoy (zona del
  sitio). Exige cuenta participante.

### 6.4 Reversión de ganancias

Si un pedido que ya generó ganancias pasa a `cancelled` o `refunded` (total), se genera un
`earn_reversal` por cada beneficiario, **limitado a su saldo** (el saldo nunca se vuelve negativo;
lo no recuperado se anota en el pedido). Reembolsos parciales: no revierten (decisión propia,
documentada como limitación). Comportamiento nuevo respecto al origen, que no revertía.

### 6.5 Código y enlace de referido

- Código **aleatorio**, no derivado del login ni del email: alfabeto sin caracteres ambiguos,
  longitud fija (8), generado con aleatoriedad criptográfica y reintento ante colisión con el
  índice único. Se asigna al crear la cuenta y, a los existentes, de forma perezosa al primer uso y
  con una acción de administración por lotes (sin el bucle sobre todos los usuarios en
  `plugins_loaded` del origen).
- El administrador puede cambiar el código de un usuario (único, formato validado).
- El enlace es `<config.brand.url>/?ref=<código>` (se construye en el BFF, nunca con `home_url()`
  de WordPress, que en headless apunta al backend).
- Participan los usuarios con algún **rol permitido** (ajuste; por defecto `customer` y
  `subscriber`: `setup.sh` fija `default_role = subscriber` y el registro por GraphQL crea
  suscriptores, así que solo con `customer` nadie participaría — decisión propia).

### 6.6 Atribución y alta de referidos

1. El BFF recibe el código en el registro (§9.3) y, tras crear la cuenta, lo envía a WordPress
   (ruta interna). WordPress valida: código existente, referidor participante, no autorreferido,
   sin ciclo, usuario sin referidor previo. Un código inválido **no** rompe el registro: se ignora y
   se registra.
2. La atribución nace siempre `pending`. Pasa a `confirmed` cuando la **cuenta está confirmada**:
   - email verificado (el mu-plugin `hwe-auth` ya guarda esa verificación en
     `hwe-auth/Account.php`; hoy no dispara una acción al verificarse — añadir una acción estable,
     p. ej. `hwe_email_verified( int $user_id )`, es parte de este trabajo), **y**
   - si el login privado está encendido, cuenta aprobada.
   Lo que llegue último dispara la confirmación. Una cuenta creada en wp-admin por un
   administrador se considera confirmada (no pasa por la verificación del sitio).
   La verificación de email del framework es **opcional** (no bloquea compra ni login): para este
   módulo es el requisito para cobrar los puntos de alta, no para participar en el resto.
3. Al confirmarse: abonos `referral_signup_l1` al referidor y, si nivel 2 activo,
   `referral_signup_l2` al referidor del referidor; y el abono de `registration` al propio usuario
   (diferido hasta ese momento aunque no tenga referidor). Idempotente por usuario. Las comisiones
   por compra (§6.2) **no** esperan: dependen de que el pedido esté cobrado, no de la confirmación
   de la cuenta, pero sí exigen una atribución existente (`pending` o `confirmed`).

**Contrato con el futuro módulo «login privado»** (provisional, lo fija ese spec): un filtro
`hwe_account_is_pending( bool $pending, int $user_id )` (por defecto `false`) y una acción
`hwe_account_approved( int $user_id )`. Este módulo solo los consume; no depende de ninguna
función concreta. Si ese spec elige otros nombres, se ajustan aquí.

## 7. Canje en el checkout

### 7.1 Principio

El navegador solo declara **cuántos puntos quiere usar**. WordPress calcula el descuento real, lo
limita y lo aplica dentro del carrito; el BFF nunca envía importes ni `fee_lines`.

### 7.2 Mecánica (Store API)

- `hwe-loyalty` registra una **extensión de la Store API** (namespace `hwe-loyalty`):
  - un *update callback* del carrito (`cart/extensions`) que guarda en la sesión de Woo los puntos
    solicitados (0 = quitar);
  - datos del carrito en `extensions.hwe-loyalty`: saldo disponible, puntos aplicados, descuento
    (en unidades menores, como el resto de la Store API), máximo canjeable para este carrito,
    mínimo, si el pago completo está disponible y el motivo si el canje no es posible.
- El descuento es un **fee negativo no imponible** calculado en `woocommerce_cart_calculate_fees`
  en cada recálculo, con los puntos **recortados** a: saldo vivo, máximo por pedido, y el tope del
  carrito. Tope del carrito:
  - pago completo **apagado**: el descuento no puede cubrir el envío **ni** dejar el total por
    debajo del **mínimo a pagar en dinero** (ajuste `min_cash_due`, decimal > 0 en moneda de la
    tienda). Si el carrito ya está por debajo de ese mínimo, no hay canje.
  - pago completo **encendido**: total con envío (puede llegar a 0).
  Por debajo del mínimo de puntos para canjear, no se aplica.
- Exige sesión (participante). Invitado o rol no permitido → la extensión informa «no disponible».
- La conversión es `puntos × valor del punto` (ajuste «valor de 1 punto en la moneda de la
  tienda», decimal). Redondeo del descuento a la precisión de la moneda de Woo.

### 7.3 Descuento definitivo al crear el pedido

En la creación del pedido de la Store API (hook de checkout de la Store API, dentro de la misma
petición), WordPress **vuelve a calcular** y descuenta los puntos con el libro (transacción + bloqueo
de fila, clave `redeem:order:<id>`). Si el saldo ya no alcanza (otra pestaña, otra compra), el
checkout **falla con un error claro** y no se crea el pedido con un descuento sin respaldo. Un único
camino de descuento (el origen tenía cuatro). Metas del pedido escritas en ese momento.

### 7.4 Devolución

Pedido con canje que pasa a `cancelled`, `failed` o `refunded` → `redeem_refund` una sola vez
(§5.3). Un pedido `pending` que caduca por el *hold stock* de Woo pasa a `cancelled` y también
devuelve. Reembolso parcial: no devuelve puntos (limitación documentada).

### 7.5 Pago completo con puntos (opcional)

- **Sin pasarela propia** (decisión propia, corregida tras revisar el código): cuando el pedido no
  necesita pago, la Store API vacía el medio de pago y lo marca pagado por su cuenta, así que una
  pasarela nunca llegaría a ejecutarse. El camino es el **nativo de total 0**: el descuento ya quedó
  confirmado contra el libro al crear el pedido (§7.3) — **la prueba de pago es el propio libro**,
  no el navegador — y Woo marca el pedido pagado.
- Un pedido llega a total 0 por puntos **solo** con el ajuste encendido (§7.2 lo impide si no).
  WordPress marca el pedido como pagado con puntos (meta) para que el resto del sistema lo
  distinga de un total 0 por otra causa.
- En el BFF (`app/api/store/checkout`, `lib/payments/checkout-options.ts`): un pedido en total 0 por
  puntos se trata como «sin pago» — no se valida ni sustituye el medio de pago contra los de Woo, no
  se llama a `/api/payments/create`, no pasa a «En espera por link» y la respuesta lo indica para
  que el frontend vaya directo a la confirmación. `enabledPaymentChoices` necesita conocer los
  módulos además de `config.payments`.
- **Incompatibilidad con los extras de envío** del módulo `shipping`: hoy se añaden por wc/v3
  **después** de crear el pedido, fuera del cálculo del carrito, y dejarían importe pendiente en un
  pedido ya pagado. Mientras sigan así, pedir pago completo con puntos y extras a la vez responde
  **422** con motivo claro, y con pago completo apagado el mínimo en dinero (§7.2) se calcula sin
  esos extras (que se suman después). Moverlos al carrito queda como mejora futura del módulo
  `shipping`, fuera de este trabajo.
- Un pedido pagado del todo con puntos no genera ganancias (§6.1).

## 8. Transferencias (opcional)

- Del usuario con sesión a otro identificado por **código de referido**. Ajustes: mínimo por
  transferencia (por defecto 100), % de comisión que se queda la tienda (por defecto 0; redondeo
  hacia arriba de la comisión), y tope diario por usuario (decisión propia; 0 = sin tope).
- Prohibido a uno mismo y a usuarios no participantes. Débito y abono **en una sola transacción**
  del libro; los puntos recibidos caducan según el ajuste general.
- La respuesta solo devuelve el nombre visible del receptor si la operación tuvo éxito (para el
  recibo); nunca su email ni su login.
- Idempotente por clave enviada por el BFF (cabecera `Idempotency-Key`, `lib/security/idempotency`).

## 9. Contrato con el frontend

### 9.1 Configuración pública (`config.loyalty`)

Grupo raíz `loyalty` del Control Center: el núcleo lo **declara vacío** (como `shipping` y
`landings`, ver `SchemaGroups/`) y `hwe-loyalty` lo puebla con `hwe_control_center_schema`. Así la
«UI del plugin» para ajustes es su pestaña en HWE Config y hereda `wp hwe setup`, la API pública y
la revalidación de `site-config`. Campos (todos con su `'default'`, públicos salvo que se diga):

- `points_enabled`, `referrals_enabled`;
- `currency_name` (singular), `currency_name_plural`, `currency_icon` (imagen de la medioteca o
  URL; vacío = sin icono — tipo de campo según los disponibles, a criterio del implementador);
- `point_value` (valor de 1 punto en moneda de la tienda), `min_redeem`, `max_redeem_per_order`
  (0 = sin tope), `full_payment_enabled`, `min_cash_due` (mínimo a pagar en dinero con el pago
  completo apagado, > 0), `expiry_days`;
- `cashback_enabled`, `cashback_percent`;
- `events`: importes de `registration`, `review`, `birthday` (0 = apagado);
- `referral_signup_points_l1`, `referral_signup_points_l2`, `commission_first_percent`,
  `commission_next_percent`, `level2_enabled`, `commission_level2_percent`,
  `commission_duration_days`;
- `transfers_enabled`, `transfer_min`, `transfer_fee_percent`, `transfer_daily_cap`;
- **no públicos**: `allowed_roles`.

Reflejo obligatorio en `Schema` ↔ `instance.config.example.json` ↔ `lib/config/types.ts` ↔
`lib/config/defaults.ts` (los defaults del frontend coinciden con los del Schema).
`ModuleId` gana `"loyalty"`; `Modules.php` lo añade al catálogo.

### 9.2 BFF `/api/loyalty/*`

Todas: `requireModule(config, "loyalty")` y la guarda del interruptor correspondiente (404 si
apagado, mismo patrón que `requireFlag`); sesión obligatoria salvo donde se indica; `user_id` sale
**siempre** de la sesión verificada; WordPress se llama con el secreto interno (`wpFetch`), nunca
con `fetch` directo. Respuestas tipadas en `lib/loyalty/types.ts`; validación de entrada con Zod.

| Ruta | Guarda | Devuelve / hace |
|---|---|---|
| `GET /api/loyalty/summary` | Puntos | Saldo, valor en dinero, ganado, usado, próximo a caducar (puntos + fecha), participante sí/no |
| `GET /api/loyalty/transactions?page&per_page` | Puntos | Movimientos paginados (tope de `per_page`), con texto compuesto y tipo |
| `GET /api/loyalty/referrals?level&page` | Referidos | Red N1/N2 paginada: nombre visible, fecha, estado, puntos generados; totales |
| `GET /api/loyalty/referral-link` | Referidos | Código y URL (con `config.brand.url`) |
| `GET /api/loyalty/validate-code?code=` | Referidos, **pública** | `{ valid }` y nada más; rate-limit por IP |
| `POST /api/loyalty/transfer` | Transferencias | `guardMutation` (Origin → CSRF → rate-limit estricto) + idempotencia |
| `POST /api/store/cart/points` | Puntos | `guardMutation`; `{ points }` → update callback de la extensión; devuelve el carrito |
| `DELETE /api/store/cart/points` | Puntos | Quita el canje; devuelve el carrito |

Rutas internas de WordPress que consumen (namespace `hwe/v1/loyalty/…`, solo con
`X-HWE-Internal-Secret`, cerradas por `hwe-bff-only`; tomar `hwe-coupon-rules` y
`hwe-customer-identity` como patrón): resumen, movimientos, red, enlace, validar código, transferir,
atribuir referido. El canje va por la Store API con el `Cart-Token` (no por estas rutas).

Fallos: lecturas que degradan se registran (`logReadFailure`); el monedero no se inventa un saldo
0 si WordPress falla (error 5xx tipado, no un saldo falso).

### 9.3 Registro y captura de `?ref=`

- `proxy.ts`: si una página llega con `?ref=<código con formato válido>`, deja la cookie httpOnly
  `hwe_ref` (30 días, `SameSite=Lax`, `Secure` en prod, opciones de `lib/security/cookies`). El
  último código que llega reemplaza al anterior. Sin consultar WordPress ni la config (edge): la
  cookie se deja **siempre**, también con el módulo apagado, porque es inofensiva — quien decide es
  el registro, que la ignora si el módulo o Referidos están apagados (decisión propia). Ojo:
  `lib/security/cookies.ts` importa `server-only`; las opciones de la cookie deben salir de un
  módulo apto para edge sin duplicar la política de cookies del framework. No toca la URL.
- `/api/auth/register`: acepta `referral_code` opcional en el body (Zod); si no viene, usa la cookie.
  Tras crear la cuenta, llama a la ruta interna de atribución (best-effort: un fallo se registra y
  no rompe el registro) y borra la cookie. Con el módulo o Referidos apagado, ignora ambos.

### 9.4 Lógica de `lib/`

- `lib/loyalty/` (server-only donde lea WordPress): lectores del resumen, movimientos, red y enlace;
  tipos del contrato.
- Helpers **puros** con tests colocados: conversión puntos ↔ dinero, cálculo del máximo canjeable
  (espejo del de WordPress, solo para mostrar), formato de cantidad con nombre singular/plural e
  icono, interpretación de `extensions.hwe-loyalty` del carrito, composición del texto de un
  movimiento a partir de su tipo y datos.
- `lib/client/`: funciones del navegador para las rutas anteriores (con CSRF de `lib/client/csrf`).
- Sin componentes, sin vistas, sin mensajes i18n de UI (no hay UI). Los textos de movimientos que
  compone el BFF siguen el idioma de la petición (el implementador decide si viven en WordPress o en
  `lib/`; deben respetar la paridad de idiomas si van a `messages/`).

### 9.5 Revalidación

Los datos son por usuario y dinámicos (sin ISR). La config pública ya revalida con `site-config`.
El módulo no declara tag ISR propio (como `customer-identity`).

## 10. Defectos del origen que no se replican

1. Caducidad que nunca se aplicaba (solo caducaba un tipo que nadie creaba) y que no era FIFO.
2. Cuatro caminos de descuento del canje y descuento enviado por el cliente (fees/cupones fabricados
   en el navegador).
3. Transferencia «atómica» con `START TRANSACTION` alrededor de funciones que invalidan cachés y
   disparan acciones por su cuenta; sin bloqueo de fila.
4. Claves de ajustes inconsistentes (dos nombres para la caducidad, dos metas para el bono de
   producto, dos claves para los puntos por alta) y lecturas de claves inexistentes.
5. Código de referido derivado del login (enumeración de usuarios) y colisiones con un solo
   reintento.
6. Tres caminos de atribución (cookie en `user_register`, cambio de rol, aprobación) que se pisaban.
7. Abono por reseña sin moderar; `$_COOKIE`/`$_POST` leídos dentro de WordPress (en headless, el
   navegador no habla con WordPress).
8. Valor monetario invertido en una vista y variables sin definir; `error_log` solo con `WP_DEBUG`
   (el framework exige registrar lo que degrada: `error_log('[hwe] loyalty: …')`).
9. Regeneración de códigos para todos los usuarios dentro de `plugins_loaded`.

## 11. Restricciones del framework (obligatorias)

- Estructura y documentación de módulo: `README.md` técnico + `MANUAL.md` para editores; clase
  principal + traits por responsabilidad en `includes/` (patrón `hwe-consent-log`); cabecera
  `Version: 1.0.0`, `Requires Plugins: woocommerce`, `Text Domain: hwe`.
- Lectura de ajustes con `\HWE\ControlCenter\Storage::get` y fallback (no fatal si falta el Control
  Center).
- Ningún secreto en `config` pública ni `NEXT_PUBLIC_`; rutas WP internas detrás del secreto.
- Módulo apagado ⇒ lectores vacíos, rutas BFF 404, la extensión de la Store API no se registra, la
  checkout no acepta un total 0 por puntos y el registro ignora el código y la cookie `hwe_ref`. Cubrirlo en
  `verify-modules.sh` y en la pasada e2e sin módulos (`tests/e2e/modules-off.spec.ts`); ampliar el
  stub de CI (`STUB_SITE_CONFIG_EXTRA`) con `config.loyalty` y el módulo.
- Privacidad: exportador y eliminador de datos personales de WordPress (como `hwe-consent-log`).
  La supresión anonimiza los movimientos (conserva la contabilidad) y desvincula la red.
- Logs sin PII: ids numéricos, nunca emails ni nombres.
- Puerta anti-instancia limpia en todo lo nuevo (código, tests, docs).
- Sin migración desde el plugin de origen. Si una instancia lo necesitara, sería un comando WP-CLI
  de la instancia, no del núcleo.

## 12. Administración (wp-admin)

- **Ajustes**: pestaña `loyalty` de HWE Config (§9.1).
- **Páginas propias** bajo un menú del módulo (título neutro, p. ej. «Fidelización»):
  - Resumen: puntos en circulación, emitidos y canjeados en el periodo, caducados, referidos
    confirmados/pendientes, mejores referidores.
  - Movimientos: listado filtrable (usuario, tipo, fecha, pedido) y exportable a CSV.
  - Red: buscar un usuario y ver su referidor y sus referidos N1/N2.
  - Ajuste manual: abonar o cargar puntos con nota obligatoria (cargo limitado al saldo).
  - Acción por lotes: asignar código a los usuarios sin código.
- **Perfil de usuario**: saldo, código (editable), referidor (editable por administrador, con las
  reglas de §5.4) y últimos movimientos. El ajuste de saldo se hace con abono/cargo explícito, no
  sobrescribiendo el número.
- **Listado de usuarios**: columnas saldo y referidor (ordenables).
- **Pedido**: caja con puntos canjeados, descuento, ganancias generadas y su estado.
- **Capacidades**: `hwe_loyalty_view` (lectura) y `hwe_loyalty_manage` (ajustes, ajuste manual,
  editar red), concedidas a `administrator` y `shop_manager` al activar; nunca roles de la instancia.

## 13. Extensibilidad (hooks estables)

Acciones: punto abonado, punto cargado, puntos caducados, atribución confirmada, transferencia
hecha (con usuario, puntos, tipo, referencia). Filtros: puntos calculados por compra antes de
abonar (para que una instancia aplique reglas propias), tipos de movimiento extra, roles
participantes. Nombres con prefijo `hwe_loyalty_`; se documentan en el README y en
`docs/PLUGIN_DEVELOPMENT.md` si pasan a ser contrato.

## 14. Pruebas y verificación

- **PHPUnit + Brain Monkey** (`backend/tests/Unit/Loyalty/`, `$wpdb` simulado como en
  ConsentLog): libro (saldo no negativo, FIFO, caducidad, devolución con caducidad correcta,
  idempotencia por clave), cálculo de ganancias (base sin envío, primera compra vs siguientes,
  duración, N2, bono por producto, cashback, exclusiones), atribución (ciclos, autorreferido,
  pendiente → confirmada), canje (recortes, mínimo, tope con y sin pago completo, fallo por saldo
  insuficiente al crear el pedido), reversión, transferencias, guardas de módulo y del secreto
  interno, exportador/eliminador.
- **Vitest** colocados: helpers puros de `lib/loyalty/`, rutas BFF (guardas 404, sesión, CSRF,
  `user_id` de la sesión, idempotencia de transferencia), proxy (`?ref=` → cookie), registro con
  código, opciones de pago con el medio de puntos.
- Verificación en vivo: ampliar `verify-modules.sh` (y `verify-integration.sh` si aplica) con el
  módulo encendido/apagado. Lo que solo prueba el stub (canje real en Store API, webhook → abono)
  se declara como no verificado en vivo hasta correrlo contra la pila.
- `npx next build`, `composer test`, lint/tipos/formato/i18n y la puerta anti-instancia en verde.

## 15. Documentación a actualizar

`docs/CONFIGURATION.md` (módulo y campos), `docs/FRONTEND_CONNECT.md` Parte A (config, rutas BFF,
extensión de la Store API, medio de pago, cookie `hwe_ref`), `docs/PLUGIN_DEVELOPMENT.md` (grupo
reservado `loyalty`, hooks si son contrato), `AGENTS.md` (catálogo de módulos pasa a 10, §6.9, tabla
de estado), `CLAUDE.md` (lista de módulos), `backend/scripts/instance.config.example.json`,
`CHANGELOG.md` (`[Sin publicar]`), `README.md`/`MANUAL.md` del plugin.

## 16. Decisiones propias (revisables)

Base de cálculo sin envío (extras de envío incluidos como envío) y sin la parte pagada con puntos ·
pedidos pagados con puntos no generan ganancias · efectivo genera ganancias al completarse ·
reversión de ganancias en cancelación/reembolso total y no en parciales · devolución del canje con
la caducidad más lejana · aviso de caducidad a 30 días · código aleatorio de 8 caracteres · reseña:
un abono por pedido reseñado · bono por producto como campo de `hwe_product_fields` · tope diario de
transferencias · roles participantes por defecto `customer` + `subscriber` · cookie `hwe_ref`
siempre, decide el registro · sin pasarela propia (camino nativo de total 0) · pago completo con
puntos incompatible con extras de envío (422) · cuenta creada en wp-admin = confirmada ·
capacidades para `administrator` y `shop_manager` · contrato provisional con el módulo de login
privado (§6.6).

## 17. Lo que queda al implementador

Forma concreta de las tablas e índices, cómo registrar el consumo FIFO, tipo de campo del icono,
dónde se componen los textos de movimientos, organización de traits/clases, paginación por defecto
y topes, mensajes exactos de error, y el orden de las tareas del plan.
