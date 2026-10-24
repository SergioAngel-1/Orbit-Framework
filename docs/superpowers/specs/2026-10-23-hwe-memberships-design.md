# Módulo `memberships` (`hwe-memberships`) — diseño

> Fecha: 2026-10-23 · Estado: pendiente de aprobación del usuario; después, plan.
> Origen: refactorización completa de un plugin de membresías hecho para una instancia anterior
> (`/home/sergi/Documentos/Proyectos/Flores Inc/backend/app/public/wp-content/plugins/floresinc-memberships`).
> Se replican sus **flujos**, no su código: el código se escribe con las prácticas del framework.
> **Nada** del nombre, niveles, slugs, iconos, textos ni supuestos de esa instancia entra en el repo
> (puerta anti-instancia; esta carpeta `docs/superpowers/` está exenta, el resto no).
> Referencia de forma: el módulo `loyalty` (`backend/wp-content/plugins/hwe-loyalty/` y su spec
> `2026-10-22-hwe-loyalty-design.md`). Donde este documento no diga otra cosa, se sigue su patrón.

## 1. Intención

Dar al framework un **programa de membresías reutilizable**: niveles que el cliente compra (o que
el equipo le asigna), que caducan, se renuevan, se congelan, y que dan **beneficios** en la tienda
y **acceso** a partes del catálogo reservadas a ciertos niveles. Es un **módulo opcional** más del
catálogo, como `loyalty` o `shipping`.

El framework es backend + arquitectura (`AGENTS.md §1.1`): este módulo **no diseña UI**. Entrega
el plugin de WordPress con su administración, el contrato público, las rutas del BFF y la lógica
de `lib/`. Las vistas (página de niveles, estado del socio, carné con QR, página de verificación,
avisos de bloqueo en el catálogo) las construye cada instancia consumiendo ese contrato.

## 2. Decisiones del usuario

1. **Catálogo exclusivo visible como «bloqueado»**: lo reservado a un nivel aparece en el catálogo
   público, pero **sin entregar datos que el visitante no puede ver** (§6). Nunca se oculta
   filtrando en el navegador ni se recorta solo en el frontend.
2. **Cinco beneficios de serie** pensados para cualquier tienda (§7): descuento de miembro,
   envíos gratis por periodo, puntos al activar, ventaja en referidos (comisión extra + membresía
   de regalo) y acceso anticipado. Más un tipo «ventaja informativa» sin lógica.
3. **Beneficios extensibles por instancia** de forma sencilla, sin tocar el núcleo (§7.7), con un
   ejemplo vivo en `examples/instance-minimal/`.
4. **Convivencia con `loyalty`**: cada módulo se enciende y apaga por separado; ninguno depende del
   otro para arrancar (§8).
5. **Carné digital verificable por QR**: sí (§9).
6. **Congelar membresías**: sí (§5.5). El reloj se detiene mientras está congelada (decisión
   propia aceptada por el usuario: «lo que recomiendes»).
7. **Asignar y quitar cualquier nivel en masa** desde wp-admin (§11.3).
8. **Sin nivel «por antigüedad»** por ahora (el origen lo tenía como nivel especial no comprable).
9. **Frontend: solo preparar para consumir** — contrato, BFF y `lib/`; ni componentes ni vistas.

## 3. Objetivos, no-objetivos y anti-objetivos

**Objetivos**
- Paridad de los flujos del origen que el usuario conserva (§4), sin sus defectos (§13).
- Estado de membresía **exacto e idempotente**: ningún pago, webhook repetido ni reintento activa
  dos veces, da puntos dos veces o consume dos envíos gratis.
- Seguridad del framework: el navegador nunca decide un descuento, un nivel ni un envío gratis;
  todo pasa por el BFF; WordPress solo atiende al BFF; seguridad falla cerrada.

**No-objetivos**
- UI de tienda (componentes o vistas en el núcleo o en el starter).
- Renovación automática con cobro recurrente (suscripciones). El origen tenía un «periodo de
  renovación» que solo era una etiqueta; aquí no existe.
- Correos de aviso de caducidad (se publica un hook para que la instancia o una integración los
  envíe; ver §12).
- Nivel por antigüedad, contenido editorial exclusivo, beneficios propios de la tienda de origen
  (muestras por peso, marcas aliadas con nombre, asesoría legal, eventos): si una instancia los
  quiere, los declara con §7.7.
- Migración de datos desde el plugin de origen (como en `loyalty`: sería un comando WP-CLI de la
  instancia, no del núcleo).

**Anti-objetivos** (lo haría un fracaso aunque «funcione»)
- Que una respuesta pública (GraphQL, REST de Woo, Store API, BFF) entregue la descripción, la
  galería, las variaciones u otro dato completo de un producto bloqueado a quien no tiene el nivel.
- Que el cliente pueda fijar el importe del descuento, su nivel o el uso de un envío gratis.
- Que un endpoint de verificación del carné permita enumerar socios o revele datos personales.
- Que apagar `loyalty` rompa membresías, o al revés.
- Que se cuele vocabulario de la instancia de origen en el repo.

## 4. Flujos a replicar (paridad funcional)

| Flujo | Origen | Destino |
|---|---|---|
| Niveles | Derivados de productos de Woo marcados + lista fija de 5 niveles con nombres e iconos escritos a mano | Niveles como entidades del módulo, editables y traducibles; los productos son «formas de comprar» un nivel (§5.1) |
| Compra de membresía | Pedido `completed` → activa | Pedido **cobrado** → activa, idempotente por línea (§5.3) |
| Renovar / mejorar | Mismo nivel o superior extiende la fila | Línea de tiempo de concesiones: no se pierde tiempo pagado (§5.4) |
| Nivel inferior con uno superior activo | Expiraba el superior | El tiempo del inferior se aplaza tras el superior (§5.4) |
| Caducidad | Cron diario + «expirar al leer» escribiendo en BD | Se calcula al leer por fechas, sin escribir; el cron materializa el estado y dispara hooks (§5.6) |
| Congelar / descongelar | Estado `frozen`; el reloj seguía corriendo y no caducaba | Congelada = sin nivel efectivo; al descongelar recupera los días congelados (§5.5) |
| Ceder / quitar por admin | Perfil de usuario; quitar solo tocaba user_meta | Acciones explícitas en el perfil y en masa, con historial (§11) |
| Historial | Tabla de acciones | Igual, con actor y origen (§5.2) |
| Antigüedad mínima de cuenta para comprar | Meta del producto | Campo del nivel (§5.1) |
| Compra solo con sesión, una membresía por carrito | Validaciones de carrito | Igual, en el servidor (§5.3) |
| Cuenta pendiente de aprobación no compra | Meta propia de la instancia | Filtro del núcleo `hwe_account_is_pending` (§5.3) |
| Nivel mínimo por categoría con herencia | Copiado a cada subcategoría al guardar | Calculado al leer: el más restrictivo de la cadena (§6.1) |
| Bloqueo al añadir al carrito y en el checkout | Hooks de carrito clásicos | Store API + checkout del BFF (§6.3) |
| Beneficios por nivel | Registro de handlers; varios sin efecto | Registro de tipos con 5 de serie + extensión por instancia (§7) |
| Descuento por categoría | Calculado en el navegador, validado «a ojo» con tolerancia | Calculado en WordPress dentro del carrito (§7.2) |
| Entregas gratis | Contador por fila; el navegador marcaba el pedido | Cupo por concesión con reserva/consumo/devolución (§7.3) |
| Puntos al activar | Abono sin idempotencia en el plugin de puntos | Abono idempotente en `loyalty` (§7.4, §8) |
| Bonus de comisión y membresía de regalo al referido | Acoplado al plugin de puntos | Hooks de `loyalty`, módulos independientes (§7.5, §8) |
| Carné digital con QR | Token en user_meta en claro, 24 h | Token con hash, revocable, verificación mínima (§9) |
| Asignación masiva | Solo el nivel de antigüedad; ids procesados en opciones | Cualquier nivel, asignar y quitar, por lotes reanudables (§11.3) |
| Panel de estadísticas | Dashboard con conteos | Igual (§11) |

## 5. Modelo y ciclo de vida

Tablas propias con `dbDelta` y versión de esquema en una opción, con migración perezosa al detectar
versión vieja (patrón `HWE_Loyalty_Schema`). Prefijo `{$wpdb->prefix}hwe_memberships_`. InnoDB
(transacciones y bloqueo de fila por usuario en toda escritura).

### 5.1 Niveles y productos

- **Nivel**: entidad del módulo (recomendado: CPT no público con UI en el menú del módulo, para
  heredar traducciones con `hwe-content-i18n` y la medioteca; a criterio del implementador si
  justifica otra forma). Campos: nombre y descripción traducibles, slug por idioma, **rango**
  (entero único que ordena los niveles: mayor rango = más acceso), icono (medioteca o URL), color de
  marca opcional, «se puede comprar» (sí/no), antigüedad mínima de la cuenta en días (0 = sin
  requisito) y la configuración de sus beneficios (§7).
- **Sin nivel** es el estado base de todo usuario (rango 0 implícito); no es un nivel que se edite
  ni se compre. No hay niveles ni nombres predefinidos en el código.
- **Producto de membresía**: un producto simple y virtual de Woo con una pestaña del módulo que lo
  liga a un nivel y a una **duración en días** (0 = indefinida). Puede haber varios por nivel
  (p. ej. mensual y anual). El producto no tiene otra configuración de membresía (los beneficios
  viven en el nivel). Un nivel «no comprable» no admite productos ligados en venta.
- Borrar o despublicar un nivel con socios activos no se permite sin antes moverlos o quitarles el
  nivel (aviso en el panel); su historial se conserva.

### 5.2 Concesiones e historial

- **Concesión** (`grants`): cada trozo de tiempo de un nivel que tiene un usuario. Campos mínimos:
  usuario, nivel, origen (`order`, `admin`, `bulk`, `referral_gift`, o uno extra declarado por
  filtro), referencia tipada (pedido + línea, id de lote, id del referidor…), **clave de
  idempotencia** con índice único (p. ej. `order:<id>:item:<id>`, `bulk:<lote>:<usuario>`,
  `gift:<usuario>`), duración pagada en días (o indefinida), inicio y fin efectivos, estado
  (`scheduled`, `active`, `ended`, `revoked`), actor y fechas UTC.
- **Estado por usuario** (`members`): caché transaccional del nivel efectivo, fin de cobertura,
  si está congelado y desde cuándo, número de socio, «socio desde». Se actualiza en la misma
  transacción que cualquier cambio de concesiones, como la tabla de saldos de `loyalty`.
- **Historial** (`events`): una fila por cambio (activación, renovación, mejora, aplazamiento,
  caducidad, congelación, descongelación, concesión o retirada por admin, revocación por
  reembolso, regalo de referido), con nivel anterior y nuevo, actor y datos estructurados. Solo
  crece.
- El cupo de envíos gratis consumido vive con la concesión que lo aporta (§7.3).

### 5.3 Compra y activación

- **Reglas de compra** (las comprueba WordPress al añadir al carrito y otra vez al crear el
  pedido): exige sesión aunque la tienda permita compra de invitado; **una sola** línea de
  membresía por carrito y cantidad 1 (puede convivir con productos normales); el nivel debe ser
  comprable; la cuenta cumple la antigüedad mínima del nivel; la cuenta no está pendiente
  (`hwe_account_is_pending`); el usuario no tiene ya ese nivel (o uno superior) de forma
  indefinida (`membership_already_indefinite`). Cada rechazo lleva un motivo estable (`membership_login_required`,
  `membership_one_per_cart`, `membership_not_purchasable`, `membership_account_too_new` con los
  días que faltan, `membership_account_pending`).
- **Activación al cobrar**: igual que las ganancias de `loyalty` — en `processing`, `completed` y
  `payment_complete`, solo si el pedido está cobrado (`is_paid()`); los medios que cobran al
  entregar esperan a `completed` (mismo criterio y filtro equivalente al de `loyalty`). Idempotente
  por línea de pedido (clave de la concesión): un pedido que vuelve a cambiar de estado no extiende
  dos veces.
- **Reversión**: `cancelled` o `refunded` **total** de un pedido que ya activó revoca su concesión
  (si estaba programada, desaparece; si estaba activa, termina ahora) y recalcula la línea de
  tiempo. Un reembolso parcial no revierte (decisión propia, alineada con `loyalty`). La
  revocación no recorre ni acorta otras concesiones.
- Pedidos de invitado: no activan nada (no deberían existir por la regla de sesión; si llega uno,
  se registra y se ignora).

### 5.4 Línea de tiempo (renovar, mejorar, bajar)

Regla de oro: **no se pierde tiempo pagado y el nivel efectivo es siempre el más alto cubierto**.

- **Mismo nivel**: la nueva concesión empieza cuando termina la cobertura actual de ese nivel
  (renovación encadenada).
- **Nivel superior**: empieza ahora; el tiempo restante de las concesiones de nivel inferior se
  **aplaza** al final de la superior (no se convierte en tiempo del nivel superior, como hacía el
  origen, ni se pierde).
- **Nivel inferior** con uno superior activo: queda programada para empezar al terminar la
  cobertura de los niveles superiores.
- **Indefinida**: una concesión sin fin cubre para siempre su nivel; las inferiores quedan
  programadas sin fecha (solo empezarán si la indefinida se revoca o se quita). Se documenta.
- El usuario y la API ven el nivel efectivo, su fin, y la cola de tiempo programado por nivel.

### 5.5 Congelar

- Lo hace un administrador (perfil, listado de socios o en masa por nivel). Mientras está
  congelado, el usuario **no tiene nivel efectivo**: sin beneficios, sin carné válido (los tokens se
  revocan) y sin acceso a lo bloqueado. Concesiones, cupos e historial se conservan.
- **El reloj se detiene**: mientras está congelado nada caduca; al descongelar, todas las fechas
  de fin de las concesiones activas y programadas se desplazan lo que duró la congelación.
- Congelar un usuario sin membresía, o descongelar uno no congelado, se rechaza con motivo.

### 5.6 Caducidad

- El nivel efectivo se **calcula al leer** a partir de las fechas: una lectura nunca escribe en la
  base de datos (el origen expiraba «en tiempo real» dentro de un GET).
- Una tarea diaria (`hwe_memberships_daily`, programada al activar el plugin y retirada al
  desactivarlo, como `HWE_Loyalty_Scheduler`; en producción la ejecuta el contenedor `wp-cron`)
  materializa transiciones por lotes: activa programadas cuyo inicio llegó, termina las vencidas,
  actualiza el estado por usuario, escribe el historial, revoca tokens del carné y dispara los
  hooks (§12). Es idempotente.
- La misma tarea dispara el hook de «a punto de caducar» una vez por concesión, a los días
  configurados (§10.1).

## 6. Catálogo exclusivo («bloqueado»)

### 6.1 Requisitos de acceso

- **Por categoría**: rango mínimo (o «público»). La herencia se calcula al leer: un producto o una
  subcategoría exige el **mayor** rango de toda su cadena de categorías. Cambiar el nivel de una
  categoría padre no reescribe las hijas.
- **Por producto** (opcional): rango mínimo propio; manda el mayor entre el del producto y el de
  sus categorías.
- **Acceso anticipado** (beneficio §7.6): un requisito temporal que se suma a los anteriores.
- Requisito efectivo de un producto = {rango mínimo, fecha de apertura si la hay}. Cálculo en un
  solo sitio del plugin, cacheado por petición e invalidado al guardar producto/categoría/nivel.

### 6.2 Qué ve quien no tiene acceso (ficha de escaparate)

- El producto o categoría **sí aparece** en listados, búsqueda, sitemap y GraphQL, pero solo con
  la **ficha de escaparate**: nombre, slug, imagen principal, precio público, categorías, el
  requisito (nivel mínimo con su nombre/slug y, si aplica, fecha de apertura) y la marca
  `locked`.
- **No sale** por ninguna vía pública: descripción y descripción corta, galería, variaciones y su
  stock, atributos, campos de producto del núcleo (`hweFields`), detalles de línea, contenido
  exclusivo, enlaces de descarga. WordPress los recorta **en origen** para toda petición sin
  usuario o sin el nivel: resolvers de WPGraphQL/WooGraphQL, REST `wc/store/v1/products` y
  cualquier REST pública de Woo o de módulos que exponga producto. El frontend nunca recorta.
- Como el catálogo del BFF se lee **sin usuario** (ISR, cache compartida), lo público siempre es la
  ficha de escaparate de lo bloqueado. Esto encaja con la caché: no hay variantes por usuario.
- **Detalle completo con sesión**: una ruta del BFF con sesión pide a una ruta interna de WordPress
  el detalle de un producto por slug/id para el `user_id` de la sesión; WordPress comprueba el
  requisito y devuelve el detalle completo o **la misma ficha de escaparate** (nunca un error que
  confirme qué hay detrás). No se cachea entre usuarios (sin ISR compartida).
- JSON-LD, sitemap y `llms.txt` usan solo la ficha de escaparate.
- **Categorías bloqueadas**: nombre, slug, imagen, descripción y requisito son públicos (la
  descripción es lo que invita a hacerse socio); los productos que contienen siguen §6.2.
- **Personal de la tienda**: wp-admin no se ve afectado (edición y vista previa completas). En la
  tienda, un administrador es un cliente más: no se salta requisitos ni reglas de compra.
- Productos variables: la ficha de escaparate lleva el rango de precio público, sin variaciones.

### 6.3 Comprar

- Añadir al carrito (Store API) y crear el pedido rechazan una línea cuyo requisito no cumple el
  usuario, con motivo estable `membership_required` (+ rango y slug del nivel, o
  `membership_early_access` + fecha). Se comprueba también en el checkout porque el nivel puede
  caducar o congelarse con la línea ya en el carrito; el BFF lo traduce a **409** con el motivo.
- La extensión del carrito (§10.4) lista las líneas bloqueadas para que la UI avise antes de pagar.

## 7. Beneficios

### 7.1 Registro de tipos

- Un **tipo de beneficio** se declara en un solo registro del plugin: clave, nombre, descripción,
  campos que se configuran **por nivel** (con los mismos tipos de campo que el Control Center:
  número, porcentaje, booleano, selección, categorías, texto traducible…), si depende de otro
  módulo (y cuál) y qué expone públicamente.
- Cada nivel guarda, por tipo, `enabled` + los valores de sus campos. El guardado sanea según los
  campos declarados; un valor fuera de rango se recorta o se rechaza con aviso.
- Una **clase base** da el ciclo común: ¿lo tiene este usuario ahora? (nivel efectivo, no
  congelado, dependencia activa), su configuración, su estado para el usuario (p. ej. cupo
  restante), su representación pública y el registro de sus hooks. Un tipo cuyo módulo de
  dependencia está apagado **no existe para el cliente** (no se aplica ni sale en la API) y en
  wp-admin aparece en gris con el motivo; su configuración se conserva.
- Los textos que ve el cliente (nombre del beneficio, «10 % en Calzado», «2 envíos gratis») **se
  componen en el BFF** con `messages/` a partir de clave + datos estructurados, como las
  descripciones de movimientos de `loyalty`. Los campos de texto libre de la ventaja informativa
  son traducibles y llegan por idioma.

### 7.2 Descuento de miembro

- Campos por nivel: porcentaje (0–100) y alcance (todo el catálogo o categorías elegidas; la
  herencia de categorías se respeta).
- Lo calcula WordPress en cada recálculo del carrito de la Store API para el usuario de la sesión.
  Preferencia: rebajar el **precio de la línea** (el descuento queda por línea, los impuestos se
  calculan sobre lo rebajado y la base de ganancias de `loyalty` lo refleja sola); si el
  implementador elige otra mecánica debe conservar esas tres propiedades. Se aplica sobre el
  precio que el carrito cobraría (oferta incluida); un cupón se aplica después sobre el precio
  rebajado. Las líneas de membresía nunca se descuentan.
- El pedido guarda por línea el precio original y el descuento de miembro, y en total el nivel y el
  porcentaje (metas con prefijo `_hwe_membership_`, en constantes, nunca nombres repetidos).
- El catálogo público muestra el **precio público**. Un usuario con sesión obtiene su «precio para
  ti» por el BFF (§10.3), calculado por la misma función que el carrito.

### 7.3 Envíos gratis por periodo

- Campo por nivel: cantidad de envíos gratis. Cada **concesión** aporta ese cupo cuando está
  activa; renovar encadena una concesión nueva con su propio cupo cuando empiece. El cupo no
  usado de una concesión termina con ella (como en el origen: «muere con la membresía»).
- El cliente declara en el carrito si quiere usar un envío gratis (extensión §10.4); WordPress
  decide si puede (beneficio activo, cupo disponible descontando reservas) y, si puede, deja a 0 el
  coste de envío del carrito.
- **Reserva → consumo → devolución**: al crear el pedido se reserva una unidad del cupo (en la
  misma transacción que comprueba el disponible, para que dos pestañas no gasten la misma); al
  cobrarse el pedido se consume; si el pedido se cancela o falla antes de cobrarse, o se reembolsa
  del todo, se devuelve. Idempotente por pedido. El pedido guarda que usó un envío gratis de
  miembro y de qué concesión.
- **Integración con `shipping`**: con el módulo `shipping` activo, el envío gratis de miembro
  respeta sus reglas (solo las opciones marcadas como elegibles para envío gratis; la zona de
  reserva nunca es gratis). La forma recomendada es que `shipping` publique un filtro estable para
  que otro módulo marque el carrito como «envío gratis por motivo X» y `shipping` lo aplique con su
  propia lógica y su meta `_hwe_free_shipping_reason`; a criterio del implementador si encuentra
  una vía igual de desacoplada. Sin `shipping`, se aplica a las tarifas de envío de Woo que no sean
  recogida (por id de método, nunca por palabras de la etiqueta).
- Si el pedido termina con coste de envío 0 por otra razón (regla de compra mínima de `shipping`),
  no se reserva cupo aunque el cliente lo pidiera.

### 7.4 Puntos al activar o renovar (depende de `loyalty`)

- Campo por nivel: puntos por activación. Se abonan cada vez que se **cobra** una concesión de
  origen `order` (no por regalos ni asignaciones de admin, salvo que el lote lo pida
  explícitamente en una versión futura), con clave de idempotencia por línea de pedido y un tipo de
  movimiento propio de membresías declarado con `hwe_loyalty_entry_types`.
- Revertir la concesión por reembolso total revierte el abono (limitado al saldo, como las
  reversiones de `loyalty`).
- Requiere `loyalty` activo con «Puntos» encendido **en el momento del cobro**; si no, no se abona
  ni se intenta después (se registra en el historial y en el log).

### 7.5 Ventaja en referidos (depende de `loyalty` con «Referidos»)

- **Comisión extra**: campo por nivel con un porcentaje adicional para el referidor (N1) sobre la
  base de `loyalty`. Se añade por el filtro `hwe_loyalty_order_earnings` como un abono de **tipo
  propio** (p. ej. `membership_referral_bonus`, declarado con `hwe_loyalty_entry_types`), así
  `loyalty` lo revierte como cualquier otra ganancia. El nivel que cuenta es el del referidor **al
  cobrarse el pedido**.
- **Membresía de regalo para el referido** (decisión del usuario): beneficio **exclusivo de los
  niveles que lo activen** (p. ej. solo el nivel más alto). Lo recibe **el referido** (la cuenta
  nueva que se registró con el código); el referidor no recibe membresía, solo los puntos que ya
  le da `loyalty` con su propia configuración.
  - Campos por nivel del referidor: nivel a regalar, días, y **máximo de regalos por periodo**
    (obligatorio, ≥ 1: el usuario pidió que siempre haya límite).
  - **Periodo**: cada concesión activa del referidor en ese nivel tiene su propio contador; al
    renovar (concesión nueva) el contador vuelve a empezar. Alcanzado el tope, el referido se
    registra con normalidad, `loyalty` funciona igual, no hay regalo y queda constancia en el
    historial del referidor. `GET /api/memberships/me` muestra al referidor los regalos que le
    quedan en el periodo.
  - **Cuándo se concede** (opción del módulo `referral_gift_requires_email_verification`, §10.1):
    - **Encendida** (por defecto): solo cuando el referido **verifica su correo**
      (`hwe_email_verified`) y existe su atribución en `loyalty`. Una cuenta que nunca verificó
      el correo —incluidas las creadas en wp-admin, que `loyalty` da por confirmadas— **no**
      recibe regalo.
    - **Apagada**: al confirmarse la atribución (`hwe_loyalty_attribution_confirmed`).
  - El nivel del referidor que cuenta (beneficio, días, tope) es el que tiene **en el momento de
    conceder**; congelado o sin nivel con el beneficio ⇒ no hay regalo.
  - Concesión de origen `referral_gift`, **una sola vez por referido** (clave idempotente
    `gift:<referido>`), sin puntos por activación. El consumo del tope y la concesión se escriben
    en la misma transacción (dos verificaciones simultáneas no pasan del tope). Si el referido ya
    tiene ese nivel o uno superior activo, el regalo se encola según §5.4 (decisión propia: no se
    pierde).
  - Si membresías estaba apagado en ese momento, no hay regalo retroactivo.

### 7.6 Acceso anticipado

- Campos por nivel: activado (sí/no). En el producto: fecha de apertura al público (opcional).
- Antes de esa fecha, solo los usuarios con un nivel que tenga el beneficio (y que además cumplan
  el requisito de nivel del producto, §6.1) lo ven completo y lo compran; los demás ven la ficha de
  escaparate con la fecha. Pasada la fecha, el producto vuelve a su requisito normal.
- La fecha vive como campo de producto del módulo y se expone en la ficha de escaparate. El ISR
  del catálogo debe refrescarse al cruzar la fecha (revalidación programada o `revalidate`
  acotado; a criterio del implementador, documentado).

### 7.7 Ventaja informativa y beneficios de instancia

- **Ventaja informativa**: tipo sin lógica (título + texto traducibles por nivel) para mostrar
  ventajas que la tienda gestiona fuera del sistema (atención prioritaria, aliados, eventos…).
- **Beneficios de instancia**: filtro estable `hwe_memberships_benefit_types` para que una
  instancia (en `mu-plugins/instance/` o un plugin `hwe-instance-*`) registre tipos propios que
  extienden la clase base. El panel por nivel, el guardado saneado, `wp hwe setup`, la API y el
  historial los tratan igual que a los de serie, sin tocar el núcleo. Una declaración mal formada
  se descarta y se registra; una clave que choca con una del núcleo se descarta.
- Hooks de ayuda para que un tipo de instancia reaccione al ciclo: activación, caducidad,
  congelación, pedido cobrado de un socio (§12).
- **Ejemplo vivo** en `examples/instance-minimal/`: un tipo de beneficio sencillo con un hook (p. ej.
  «regalo cada N pedidos cobrados» que deja una nota en el pedido), con su test PHP, cubierto por
  el job «contrato» de CI.

## 8. Convivencia con `loyalty`

- **Independencia**: `loyalty` no sabe nada de membresías. Membresías no llama a clases de
  `loyalty`: solo usa hooks y funciones publicados como contrato, y comprueba en cada uso que
  `loyalty` esté activo con la función que necesita (puntos o referidos).
- **Membresías apagado, `loyalty` encendido**: referidos y puntos funcionan exactamente igual que
  hoy; no queda ningún filtro de membresías registrado.
- **Membresías encendido, `loyalty` apagado** (o «Puntos»/«Referidos» apagados): los beneficios
  §7.4 y §7.5 no existen para el cliente; en wp-admin aparecen en gris con aviso; su configuración
  se conserva. El resto funciona sin cambios.
- **Único cambio en `loyalty`**: publicar un punto de entrada estable y documentado para abonar
  puntos desde otro módulo (usuario, puntos, tipo declarado, referencia, clave de idempotencia,
  nota estructurada) que responda «no disponible» si los puntos están apagados o el usuario no
  participa, y su contraparte para revertir un abono por clave. Se documenta en el README de
  `loyalty` y en `docs/PLUGIN_DEVELOPMENT.md` («Hooks estables de los módulos»), con sus tests.
- Cualquier fallo al hablar con `loyalty` se registra (`[hwe] memberships: …`) y **no** hace caer la
  activación de la membresía.

## 9. Carné digital verificable por QR

- **Token**: el socio pide su token por el BFF (sesión). WordPress emite un token aleatorio de
  alta entropía, ligado al usuario, con caducidad configurable (por defecto 24 h); guarda solo su
  **hash** (con índice), nunca el token en claro. Pedirlo de nuevo antes de caducar devuelve uno
  válido sin multiplicar tokens vivos (como mucho uno por usuario).
- Solo se emite con nivel efectivo (activo y no congelado). Se **revoca** al caducar, congelar,
  quitar o revocar la membresía.
- **QR**: apunta a una URL del frontend compuesta con `config.brand.url` (ruta configurable,
  p. ej. `/verify/<token>`); la página la construye la instancia. Nunca la URL de WordPress.
- **Verificación pública** por el BFF con rate-limit por IP: responde solo si está vigente, nombre
  del nivel, «socio desde», número de socio (prefijo configurable + número propio del módulo,
  **nunca** el id de usuario), nombre enmascarado (regla de nombre visible de `loyalty`
  `PublicName` reducida a nombre de pila + inicial) y, **solo si `customer-identity` está activo**,
  el documento enmascarado. Token inexistente, caducado o revocado dan **la misma** respuesta
  («no válido»), sin distinguir.
- Interruptor en la configuración del módulo (§10.1): con el carné apagado, las rutas responden 404.

## 10. Contrato con el frontend

### 10.1 Configuración pública (`config.memberships`)

Grupo reservado `memberships` del Control Center, declarado vacío por el núcleo (como
`SchemaGroups/Loyalty.php`) y poblado por el plugin. Campos (públicos salvo indicación):
- `card_enabled` (booleano, `false`), `card_verify_path` (texto, ruta del frontend para el QR),
  `member_number_prefix` (texto corto), `card_token_ttl_hours` (entero, 24; **no público**).
- `expiring_notice_days` (entero, 7; **no público**): cuándo se dispara el hook de «a punto de
  caducar».
- `referral_gift_requires_email_verification` (booleano, `true`; **no público**): la membresía de
  regalo al referido solo se concede tras verificar su correo (§7.5).
- Los niveles y sus beneficios **no** van en `config` (son contenido editable con traducciones):
  van por su propia ruta con ISR (§10.3).
- `config.modules.memberships` lo calcula el núcleo según el plugin activo. Espejo de TypeScript en
  `lib/config/{types,defaults}.ts`.

### 10.2 GraphQL (catálogo)

- `Product` y `ProductCategory` ganan un campo del módulo con el requisito (rango y slug del nivel,
  fecha de apertura) y la marca `locked` evaluada **para la petición** (en el catálogo ISR, siempre
  sin usuario). Con el módulo apagado el campo no existe o es nulo; los lectores toleran ambos.
- Los resolvers recortan los campos de §6.2 para productos bloqueados.

### 10.3 BFF `/api/memberships/*`

Todas comprueban `requireModule(config, "memberships")`; el `user_id` sale **siempre** de la sesión;
WordPress se llama por rutas internas `hwe/v1/memberships/*` cerradas por `hwe-bff-only` con el
secreto. Lecturas: 5xx tipado y evento registrado si WordPress falla, nunca un estado inventado.

| Ruta | Acceso | Qué hace |
|---|---|---|
| `GET /api/memberships/levels` | Pública, ISR tag `memberships` | Niveles comprables y no comprables visibles, con nombre/descr. por idioma, rango, slug, icono, beneficios (clave + datos, texto compuesto en el BFF) y productos de compra (id, precio, duración). |
| `GET /api/memberships/me` | Sesión | Nivel efectivo, estado (`none`/`active`/`frozen`), fin, cola programada por nivel, beneficios activos con su estado (cupo restante, etc.) y elegibilidad de compra por nivel (antigüedad, pendiente). |
| `GET /api/memberships/history` | Sesión | Historial paginado del propio usuario (tope), texto compuesto en el BFF. |
| `GET /api/memberships/prices?ids=` | Sesión | «Precio para ti» de una lista acotada de productos (misma función que el carrito). Sin sesión o sin descuento: los precios públicos. |
| `GET /api/memberships/products/[slug]` | Sesión | Detalle completo si cumple el requisito; si no, la ficha de escaparate (§6.2). |
| `GET /api/memberships/card` | Sesión + `card_enabled` | `{ token, expires_at, verify_url }`; 403 tipado sin nivel efectivo. |
| `GET /api/memberships/verify/[token]` | Pública + `card_enabled`, rate-limit por IP | Respuesta mínima de §9. |
| `POST /api/store/cart/free-delivery` / `DELETE` | Sesión, `guardMutation` | Declara o quita el uso de un envío gratis en la extensión del carrito; devuelve el carrito recalculado. |

El checkout del BFF traduce los errores de la Store API del módulo a **409** con `reason` estable
(`membership_required`, `membership_early_access`, `membership_free_delivery_unavailable`, y los de
compra de §5.3).

### 10.4 Extensión de la Store API (`hwe-memberships`)

En `extensions.hwe-memberships` del carrito: nivel efectivo (o nulo), descuento de miembro total
(unidades menores, string) y por línea, estado del envío gratis (`available`, `remaining`,
`requested`, `applied`, `reason`), líneas bloqueadas con su motivo, y avisos de compra de membresía
(renovación / mejora / programada, para que la UI explique qué pasará). El navegador solo declara
«quiero usar un envío gratis»; todo importe y decisión los calcula WordPress.

### 10.5 `lib/memberships/`

Tipos del contrato, helpers puros (composición de textos de beneficios e historial, formato de
duración, lectura de requisitos de la ficha de escaparate), lectores de servidor y la guarda
equivalente a `requireLoyalty` para el carné. Con tests colocados.

### 10.6 Revalidación

Tag ISR `memberships` (niveles) registrado en `hwe_revalidation_tags` y en `registry`/núcleo según
corresponda; guardar un nivel o sus beneficios lo invalida. Cambiar el requisito de una categoría
o producto, o cruzar una fecha de acceso anticipado, invalida `products`.

## 11. Administración (wp-admin)

### 11.1 Menú del módulo (título neutro: «Membresías»)

- **Resumen**: socios activos por nivel, congelados, que caducan en 7/30 días, caducados en el
  periodo, altas y renovaciones en el periodo.
- **Niveles**: crear/editar niveles (§5.1) y, por nivel, sus **beneficios** agrupados, con los
  tipos de instancia incluidos y los dependientes de otro módulo en gris si falta. Vista
  comparativa de niveles × beneficios.
- **Socios**: listado paginado con filtros (nivel, activo, congelado, por caducar, caducado,
  origen), acciones por fila (ver, congelar/descongelar, quitar) y exportación CSV (ids y nombre
  visible, sin email; celdas protegidas contra fórmulas, como el CSV de `loyalty`).
- **En masa** (§11.3).
- **Historial**: filtrable por usuario, acción, nivel y fecha.

### 11.2 Perfil, listados, producto, categoría y pedido

- **Perfil de usuario**: nivel efectivo, estado, fin, cola programada, cupo de envíos, últimos
  eventos, y acciones explícitas con nota opcional: conceder nivel (días o indefinida), extender,
  quitar, congelar/descongelar. No hay un campo de «nivel» que se sobrescriba.
- **Listado de usuarios**: columna de membresía (nivel, fin, congelado) ordenable y filtro por nivel
  y estado, resueltos en SQL (sin cargar todos los usuarios).
- **Producto**: pestaña «Membresía» (si es producto de membresía: nivel + duración) y campos de
  requisito (rango mínimo, fecha de apertura). Columna en el listado de productos.
- **Categoría**: rango mínimo (o público), indicando si una subcategoría lo hereda de su cadena.
- **Pedido**: caja con concesión creada (o revocada), descuento de miembro, envío gratis usado y
  puntos por activación, con estados. HPOS compatible.

### 11.3 Asignar y quitar en masa

- **Selección**: todos los clientes participantes (roles `customer` y `subscriber` por defecto,
  ampliables con un filtro como en `loyalty`), los que no tienen membresía, los de un nivel, o
  una lista pegada de emails o ids (los no encontrados se listan al previsualizar).
- **Asignar** cualquier nivel con duración en días o indefinida, y modo: solo a quien no tiene
  membresía · extender a quien ya tiene ese nivel · reemplazar (quita lo que tenga y concede).
- **Quitar** por nivel o por lista. **Congelar/descongelar** por nivel o por lista.
- **Vista previa** con el número de afectados por cada caso antes de ejecutar; confirmación
  explícita.
- **Ejecución por lotes** con Action Scheduler (incluido en WooCommerce), reanudable e idempotente
  por lote+usuario (clave de la concesión `bulk:<lote>:<usuario>`), con progreso visible y sin
  guardar listas de ids en opciones. Cada cambio deja historial con el lote y el admin.

### 11.4 Capacidades

`hwe_memberships_view` (lectura) y `hwe_memberships_manage` (niveles, beneficios, acciones sobre
socios, masivo), concedidas a `administrator` y `shop_manager` al activar, idempotentes y
autorreparadas como en `loyalty`; nunca a roles de la instancia. Toda escritura comprueba
capacidad y nonce.

## 12. Extensibilidad (hooks estables)

Acciones (tras el COMMIT; solo ids numéricos): membresía activada, renovada, mejorada, aplazada,
caducada, a punto de caducar (con días), congelada, descongelada, concedida/quitada por admin,
revocada por reembolso, regalo de referido concedido; y «nivel efectivo cambiado» (usuario, nivel
anterior, nivel nuevo) como acción general. Filtros: tipos de beneficio
(`hwe_memberships_benefit_types`), orígenes de concesión extra, medios cobrados al completar,
requisito efectivo de un producto (para reglas de instancia, solo puede **endurecer**, nunca abrir
un producto a quien no cumple). Prefijo `hwe_memberships_` / `hwe_membership_`. Se documentan en
el README del plugin y en `docs/PLUGIN_DEVELOPMENT.md`.

## 13. Defectos del origen que no se replican

- Descuento calculado en el navegador y validado contra un máximo con tolerancia del 5 % sobre el
  subtotal (permitía inflar el descuento y no miraba categorías).
- Envío gratis marcado por el navegador con una meta del pedido; carrera entre pedidos pendientes.
- Activación y abono de puntos **no idempotentes**: un pedido que volvía a `completed` extendía y
  abonaba otra vez.
- Comprar un nivel inferior con uno superior activo expiraba el superior; mejorar convertía el
  tiempo inferior restante en tiempo superior.
- Dos fuentes de verdad (tabla + user_meta) sincronizadas al leer; lecturas que escribían.
- Quitar la membresía desde el perfil solo tocaba user_meta: la tabla seguía «activa».
- Congelar no detenía el reloj y una congelada nunca caducaba.
- Niveles 0–5, nombres, iconos, rutas de imágenes y validaciones `<= 5` escritos a mano.
- Endpoint de depuración abierto a cualquier usuario con sesión; token del carné en claro en
  user_meta y búsquedas por `meta_value` sin índice.
- Detección de «recogida» por palabras de la etiqueta del método de envío.
- Beneficios registrados que enganchaban filtros que nadie dispara (eventos, contenido exclusivo,
  acceso anticipado) y estado global para formatear textos.
- Textos de la API en un solo idioma escritos en PHP; descuento aplicado a precios del catálogo
  por filtros globales de precio.
- Procesos masivos que guardaban todos los ids procesados en una opción.

## 14. Restricciones del framework (obligatorias)

- Estructura de módulo como `hwe-loyalty`: `README.md` técnico + `MANUAL.md` para editores,
  arranque en `plugins_loaded` con comprobación de WooCommerce, cabecera `Version: 1.0.0`,
  `Requires Plugins: woocommerce`, `Text Domain: hwe`.
- Catálogo de módulos: `memberships` entra en `Modules::CORE_IDS` (pasan a ser 11); grupo
  reservado `memberships` en `SchemaGroups/`; `hwe-bff-only` cierra `/hwe/v1/memberships/`.
- Ajustes con `\HWE\ControlCenter\Storage::get` y fallback; nada secreto en `config`.
- **Módulo apagado** ⇒ el catálogo no tiene requisitos (todo público, sin recortes), lectores
  vacíos, rutas BFF 404, extensión de la Store API no registrada, ningún filtro sobre `loyalty` ni
  `shipping`. Datos de requisitos guardados se conservan para cuando vuelva. Cubrirlo en
  `verify-modules.sh`, en `tests/e2e/modules-off.spec.ts` y en el stub de CI
  (`STUB_SITE_CONFIG_EXTRA`).
- Privacidad: exportador y eliminador de WordPress (estado, concesiones, historial, tokens) y
  limpieza en `delete_user`; el eliminador conserva concesiones e historial anonimizados si son
  contabilidad de pedidos (criterio de `loyalty`).
- Logs sin PII; ningún fallo tragado en silencio; seguridad falla cerrada (sin poder comprobar el
  requisito de un producto, se trata como bloqueado).
- Puerta anti-instancia limpia en todo lo nuevo (código, tests, docs, ejemplos fuera de
  `examples/` incluidos los textos de prueba).

## 15. Pruebas y verificación

- **PHPUnit + Brain Monkey** (`backend/tests/Unit/Memberships/`): línea de tiempo (renovar,
  mejorar, bajar, indefinida, aplazamiento, revocación), congelar con desplazamiento de fechas,
  caducidad calculada al leer y tarea diaria idempotente, activación por pedido cobrado
  (idempotencia, `cod`, reembolso total vs parcial), reglas de compra, requisitos con herencia y
  acceso anticipado, recorte de la ficha de escaparate en cada vía pública, descuento (alcance,
  ofertas, cupón, impuestos), envío gratis (reserva/consumo/devolución, concurrencia, regla de
  `shipping`), integración con `loyalty` encendido/apagado (abono, reversión, comisión extra,
  regalo único por referido, tope por periodo del referidor con concurrencia, opción de verificación
  de correo encendida/apagada y cuentas de wp-admin sin regalo), carné (hash, revocación, respuesta indistinguible), registro de tipos de
  instancia (válidos y mal formados), masivo (vista previa, reanudación, idempotencia),
  capacidades, privacidad. Los tests del nuevo punto de entrada de `loyalty` van con `loyalty`.
- **Vitest** colocados: `lib/memberships/`, rutas BFF (404 con módulo apagado, sesión, `user_id`
  de la sesión, CSRF en escrituras, rate-limit de verificación, 409 del checkout), lectores del
  catálogo con y sin el campo del módulo.
- **En vivo**: ampliar `verify-modules.sh` (tablas, rutas internas, extensión de la Store API,
  recorte de un producto bloqueado en GraphQL y Store API sin usuario). Lo que solo prueba el stub
  (compra real de una membresía → activación por webhook; envío gratis consumido) se declara no
  verificado en vivo hasta correrlo contra la pila.
- `npx next build`, `composer test`, lint/tipos/formato/i18n, job «contrato» y anti-instancia en
  verde.

## 16. Documentación a actualizar

`docs/CONFIGURATION.md` (módulo y campos), `docs/FRONTEND_CONNECT.md` Parte A (config, GraphQL,
rutas BFF, extensión de la Store API, ficha de escaparate, carné), `docs/PLUGIN_DEVELOPMENT.md`
(grupo reservado, hooks, tipos de beneficio de instancia, punto de entrada nuevo de `loyalty`),
`AGENTS.md` (11 módulos, §6.9, tabla de estado), `CLAUDE.md` (lista de módulos),
`backend/scripts/instance.config.example.json`, `CHANGELOG.md` (`[Sin publicar]`), README y MANUAL
del plugin (y README de `loyalty` y de `shipping` por sus puntos de enganche nuevos).

## 17. Decisiones propias (revisables)

Reloj detenido al congelar (usuario: «lo que recomiendes») · niveles como entidad del módulo con
rango, productos como formas de compra con duración · activación al cobrar (no al completar) ·
renovar encadena; mejorar aplaza el tiempo inferior; bajar programa · indefinida bloquea la cola
inferior · reembolso parcial no revierte · revocar no recorre otras concesiones · herencia de
categoría calculada al leer y ganando el más restrictivo · requisito opcional por producto · ficha
de escaparate con nombre, imagen principal, precio público y categorías · detalle con sesión sin
caché compartida · descuento sobre el precio de línea (oferta incluida, cupón después, líneas de
membresía excluidas) · cupo de envíos por concesión que muere con ella · reserva de envío gratis
al crear el pedido · el envío gratis no se reserva si el envío ya salía a 0 por otra regla ·
puntos solo por concesiones compradas · comisión extra con tipo de movimiento propio · regalo de
referido encolado si ya tiene nivel, una vez por usuario, no retroactivo, tope contado por
concesión del referidor, nivel del referidor evaluado al conceder, verificación de correo
encendida por defecto · acceso anticipado como
beneficio por nivel + fecha en el producto · carné de 24 h con hash, un token vivo por usuario,
número de socio propio · categoría bloqueada con descripción pública · el personal no se salta
requisitos en la tienda · no se compra un nivel ya cubierto de forma indefinida · documento en el carné solo con `customer-identity` · aviso de caducidad
como hook, sin correo · capacidades para `administrator` y `shop_manager` · sin migración desde el
origen.

## 18. Lo que queda al implementador

Forma exacta de tablas e índices, CPT vs otra forma para los niveles (justificada), mecánica
concreta del descuento por línea en la Store API, la vía de enganche con `shipping` (respetando
§7.3), cómo refrescar el ISR al cruzar una fecha de acceso anticipado, organización de clases,
nombres exactos de hooks y metas (con los prefijos dados), paginaciones y topes, textos y mensajes,
forma del número de socio, y el orden de las tareas del plan.
