# Módulo `memberships` (`hwe-memberships`) — plan de implementación

> **Para agentes:** SUB-SKILL REQUERIDA: usa superpowers:subagent-driven-development (recomendado) o
> superpowers:executing-plans para ejecutar este plan tarea por tarea. Los pasos usan casillas
> (`- [ ]`) para el seguimiento.
>
> **Estilo del plan (preferencia del usuario):** sin código literal. Cada paso dice qué lograr,
> dónde, con qué nombres y cómo se comprueba; el cómo concreto es criterio del agente, siempre
> dentro del spec, de este plan, de `AGENTS.md`/`CLAUDE.md` y de las mejores prácticas de
> WordPress/WooCommerce y Next.js. Ante una duda que el spec no resuelve, manda la convención del
> proyecto (y, en particular, cómo lo resolvió el módulo `loyalty`); si tampoco la resuelve, decide
> y anótalo en el mensaje del commit.

**Objetivo:** añadir el módulo opcional `memberships` (niveles con caducidad, beneficios
extensibles, catálogo exclusivo «bloqueado», carné verificable por QR y administración en masa) al
catálogo del framework, con su plugin de WordPress, su contrato público, sus rutas BFF y su lógica
de `lib/`, sin UI.

**Arquitectura:** WordPress es la fuente de verdad. El plugin `hwe-memberships` guarda niveles
(entidad del módulo), concesiones de tiempo por usuario en una línea de tiempo transaccional, el
estado por usuario y un historial que solo crece. Los requisitos de acceso se evalúan en un solo
sitio y recortan en origen los datos de lo bloqueado en toda vía pública; los beneficios son tipos
registrados (5 de serie + informativo + de instancia) que se aplican dentro del carrito de la Store
API. El BFF expone `/api/memberships/*` y `/api/store/cart/free-delivery` hablando con rutas
internas `hwe/v1/memberships/*` protegidas por el secreto interno. `loyalty` y `shipping` se tocan
solo para publicar un punto de enganche estable cada uno.

**Stack:** PHP 8 + WordPress/WooCommerce (Store API, WPGraphQL/WooGraphQL, Action Scheduler,
WP-Cron), PHPUnit 12 + Brain Monkey; Next.js 16 (Route Handlers), TypeScript, Zod, Vitest,
Playwright.

**Spec:** `docs/superpowers/specs/2026-10-23-hwe-memberships-design.md` (léelo entero antes de la
primera tarea; las referencias `§N` de abajo apuntan a él). Patrón de referencia: el plugin
`backend/wp-content/plugins/hwe-loyalty/` y su `README.md`.

## Restricciones globales

- Docs, comentarios y mensajes en **español**; código y nombres técnicos en **inglés**.
- Puerta anti-instancia: ningún nombre, nivel, slug, icono, texto ni supuesto de la instancia de
  origen en el repo (`bash .github/scripts/check-anti-instance.sh` en verde al cerrar cada tarea).
  No hay niveles, nombres ni colores predefinidos en el código: todo nivel lo crea el editor.
- Plugin: `backend/wp-content/plugins/hwe-memberships/`, cabecera `Version: 1.0.0`,
  `Requires PHP: 8.0`, `Requires Plugins: woocommerce`, `Text Domain: hwe`; `README.md` técnico +
  `MANUAL.md` editorial. Arranque en `plugins_loaded` (prioridad tardía) con comprobación de
  WooCommerce, como `HWE_Loyalty::boot()`.
- Prefijos: clases `HWE_Memberships_*`, tablas `{$wpdb->prefix}hwe_memberships_`, metas
  `_hwe_membership_`, hooks `hwe_memberships_` (registro/filtros) y `hwe_membership_` (eventos del
  ciclo), rutas internas `hwe/v1/memberships/…`, namespace de la Store API `hwe-memberships`,
  tag ISR `memberships`, capacidades `hwe_memberships_view` / `hwe_memberships_manage`.
- Ajustes leídos con `\HWE\ControlCenter\Storage::get` y fallback; nunca fatal sin el Control Center.
- Rutas internas de WP solo con `X-HWE-Internal-Secret` (cerradas por `hwe-bff-only`), fallan
  cerradas; `user_id` siempre de la sesión verificada del BFF, nunca del navegador.
- BFF: `requireModule(config, "memberships")` al inicio de cada ruta (404); `guardMutation` en toda
  escritura; WordPress siempre vía `wpFetch` o los clientes existentes; `import "server-only"`;
  importes de la Store API en unidades menores.
- Toda escritura del módulo en una transacción con bloqueo de la fila del usuario; toda operación
  disparada por pedidos, lotes o regalos es **idempotente por clave única**.
- Una lectura nunca escribe en la base de datos (la caducidad se calcula por fechas, §5.6).
- Seguridad falla cerrada: sin poder evaluar el requisito de un producto, se trata como bloqueado.
- Ningún fallo se traga en silencio: `error_log('[hwe] memberships: …')` en WP, eventos
  estructurados en el BFF; logs sin PII (solo ids).
- Textos que ve el cliente compuestos en el BFF con `messages/` (es/en en paridad), a partir de clave
  + datos estructurados; WordPress no devuelve frases.
- `loyalty` y `shipping` solo se modifican en las tareas que lo dicen (14 y 13), publicando un
  contrato estable documentado; membresías nunca llama a clases internas de otro módulo.
- Rama `feat/hwe-memberships` desde `main`. El árbol de trabajo de `main` tiene cambios ajenos sin
  commitear: **no** los incluyas ni los descartes; `git add` siempre con rutas explícitas.
- Un commit por tarea terminada, en verde; mensaje en español estilo Conventional Commits,
  terminado en `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Verificación por tarea: backend `cd backend && composer test`; frontend `cd frontend && npm run
  lint && npm run type-check && npm run format:check && npm run i18n:check && npm run test`; y
  **`npx next build`** antes de cerrar cualquier tarea que toque `frontend/`.
- Sé honesto sobre lo no verificado en vivo (Store API real, GraphQL real, cobro por webhook, cron,
  Action Scheduler): dilo en el resumen de la tarea si no levantaste la pila (trampas del entorno:
  memoria `docker-desktop-bind-mount-lag`).

## Foco de revisión

1. **El mismo pedido notificado varias veces** (`processing` → `completed`, `payment_complete`,
   webhook repetido, cambio manual de estado de ida y vuelta): una sola concesión, un solo abono de
   puntos, un solo consumo de envío gratis → tests en Tareas 6, 13 y 15.
2. **Dos pestañas o dos pedidos simultáneos** gastando el último envío gratis, o dos referidos
   verificando el correo a la vez con el tope del referidor a punto de agotarse: nunca se pasa del
   cupo ni del tope → tests en Tareas 13 y 15.
3. **El nivel cambia con el carrito ya lleno** (caduca, se congela, se quita, se apaga el módulo):
   el checkout rechaza la línea bloqueada con 409 y el descuento/envío gratis desaparece en el
   recálculo, nunca se conserva el viejo → tests en Tareas 11, 12 y 13.
4. **Fugas de datos de lo bloqueado por vías laterales**: búsqueda GraphQL, productos relacionados
   y de venta cruzada, `wc/store/v1/products` (listado y ficha), variaciones por id, el carrito, la
   REST de otros módulos que exponen producto → test por vía en Tarea 10.
5. **Módulos vecinos que se encienden/apagan a mitad de vida** (`loyalty` sin «Puntos» o sin
   «Referidos», `shipping` apagado, `customer-identity` apagado): nada se rompe, los beneficios
   dependientes desaparecen para el cliente y su configuración se conserva → tests en Tareas 13, 15
   y 16.

---

### Tarea 1: Registrar el módulo en el núcleo

**Archivos:**
- Modificar: `backend/wp-content/mu-plugins/hwe-control-center/Modules.php` (`CORE_IDS` + comentario
  «diez» → «once»), `.../hwe-control-center/Schema.php` (usar el trait nuevo en `core()`),
  `backend/wp-content/mu-plugins/hwe-bff-only.php` (cerrar `/hwe/v1/memberships/`)
- Crear: `.../hwe-control-center/SchemaGroups/Memberships.php` (grupo reservado vacío, calcado de
  `SchemaGroups/Loyalty.php`, etiqueta «Membresías»)
- Modificar: `frontend/src/lib/config/types.ts` (`ModuleId` + `"memberships"`),
  `frontend/src/lib/config/defaults.ts` (`modules.memberships: false`),
  `frontend/src/lib/config/modules.ts` y `backend/scripts/verify-modules.sh` si enumeran ids,
  `.github/scripts/site-config.ci.json` si enumera módulos
- Tests: los existentes de `backend/tests/Unit/ControlCenter/ModulesTest.php`,
  `SchemaContractTest.php`, `backend/tests/Unit/HweBffOnly/` y `frontend/src/lib/config/*.test.ts`

**Interfaces:**
- Produce: id `memberships` ↔ slug `hwe-memberships`; grupo raíz `memberships` vacío en el núcleo y
  poblable con `hwe_control_center_schema`; `/hwe/v1/memberships/*` responde 404 sin el secreto.

- [ ] Ajustar los tests para que esperen 11 módulos con `memberships`, el grupo raíz vacío que no se
      descarta al poblarlo, y el cierre de la ruta en `hwe-bff-only`; verlos fallar.
- [ ] Implementar (PHP y TS en paridad).
- [ ] Verificación backend y frontend + `npx next build` en verde.
- [ ] Commit `feat(core): registra el módulo memberships y su grupo reservado`.

### Tarea 2: Plugin base y ajustes

**Archivos:**
- Crear: `backend/wp-content/plugins/hwe-memberships/hwe-memberships.php` (`HWE_Memberships::boot()`),
  `includes/Settings.php` (`HWE_Memberships_Settings`), `README.md` y `MANUAL.md` iniciales
  (se completan en la Tarea 24)
- Modificar: `backend/scripts/instance.config.example.json` (grupo `memberships`),
  `frontend/src/lib/config/types.ts` (`MembershipsConfig` en `SiteConfig.memberships`),
  `frontend/src/lib/config/defaults.ts` (`CONFIG_DEFAULTS.memberships`)
- Test: `backend/tests/Unit/Memberships/SettingsTest.php`; paridad de defaults en
  `frontend/src/lib/config/defaults.test.ts`

**Interfaces:**
- Produce (PHP): `HWE_Memberships_Settings::get(): array` (saneado);
  `::moduleActive(): bool` (plugin cargado y, si hay Control Center, `Modules::isActive`);
  `::cardActive(): bool`; `::participantRoles(): string[]` (filtro
  `hwe_memberships_participant_roles`, defaults `customer`, `subscriber`).
- Produce (TS): `MembershipsConfig` con los campos **públicos** de §10.1 (`card_enabled`,
  `card_verify_path`, `member_number_prefix`).
- Campos (§10.1): `card_enabled` (false, público), `card_verify_path` (público, default `/verify`),
  `member_number_prefix` (público, default vacío), `card_token_ttl_hours` (24, no público, 1–720),
  `expiring_notice_days` (7, no público, ≥ 0), `referral_gift_requires_email_verification`
  (true, no público).

- [ ] Tests: el filtro puebla el grupo con esos campos y defaults; los no públicos no salen en la
      config pública; saneado de rangos (TTL fuera de rango se recorta, días negativos a 0, ruta que
      no empieza por `/` se normaliza o se rechaza); sin Control Center devuelve defaults; sin
      WooCommerce el plugin no arranca y lo registra. Verlos fallar.
- [ ] Implementar; reflejar el grupo en `instance.config.example.json`, `types.ts`, `defaults.ts`.
- [ ] Verificación backend y frontend + `npx next build`.
- [ ] Commit `feat(memberships): plugin base y ajustes en el Control Center`.

### Tarea 3: Esquema de datos y niveles

**Archivos:**
- Crear: `includes/Schema.php` (`HWE_Memberships_Schema`: tablas `grants`, `members`, `events`,
  `card_tokens` y lo que el implementador necesite para cupos y lotes; `dbDelta`, opción
  `hwe_memberships_db_version`, migración perezosa como `HWE_Loyalty_Schema`),
  `includes/Levels.php` (`HWE_Memberships_Levels`)
- Test: `backend/tests/Unit/Memberships/SchemaTest.php`, `LevelsTest.php`

**Interfaces:**
- Produce: `HWE_Memberships_Levels::all(bool $includeUnpublished = false): array` (ordenados por
  rango), `::get(int $levelId): ?array`, `::byRank(int $rank): ?array`, `::rankOf(int $levelId): int`
  (0 si no existe), `::isPurchasable(int $levelId): bool`, `::minAccountAgeDays(int $levelId): int`,
  `::benefitsConfig(int $levelId): array` (por clave de tipo: `enabled` + campos, sin sanear aquí;
  la Tarea 8 sanea al guardar). Cada nivel expone: id, nombre y descripción (traducibles), slug por
  idioma, rango, icono, color, comprable, antigüedad mínima.
- Niveles como CPT no público con UI (recomendado, §5.1) registrando sus campos traducibles en
  `hwe_translatable_fields`; si el agente elige otra forma, la justifica en el commit y conserva
  las traducciones y la medioteca.

- [ ] Tests: creación de tablas idempotente y migración al detectar versión vieja; el rango es
      entero ≥ 1 y único entre niveles publicados (guardar un duplicado se rechaza con aviso); orden
      por rango; nivel inexistente ⇒ rango 0; un nivel con socios activos no se puede borrar ni
      despublicar (aviso) y su historial se conserva; los campos traducibles se declaran. Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(memberships): esquema de datos y niveles`.

### Tarea 4: Línea de tiempo (lógica pura)

**Archivos:**
- Crear: `includes/Timeline.php` (`HWE_Memberships_Timeline`, **sin** acceso a BD ni a WordPress)
- Test: `backend/tests/Unit/Memberships/TimelineTest.php`

**Interfaces:**
- Produce: un modelo de concesión en memoria (nivel, rango, duración en días o indefinida, inicio,
  fin, estado, clave) y funciones puras:
  `::place(array $grants, array $new, DateTimeImmutable $now): array` (concesiones resultantes con
  inicios/fines recalculados según §5.4); `::effective(array $grants, DateTimeImmutable $at): ?array`
  (concesión que da el nivel efectivo: la de mayor rango que cubre `$at`);
  `::revoke(array $grants, string $key, DateTimeImmutable $now): array` (§5.3: programada
  desaparece, activa termina ahora; no recorre las demás); `::shift(array $grants, int $seconds):
  array` (desplaza fines/inicios no terminados, para descongelar); `::queue(array $grants,
  DateTimeImmutable $now): array` (tiempo programado por nivel).

- [ ] Tests (tabla de casos, fechas fijas, UTC): primera compra; renovar mismo nivel encadena tras
      su fin; mejorar empieza ahora y aplaza el resto inferior exacto al final de la superior;
      bajar queda programado tras la cobertura superior; dos mejoras y una bajada encadenadas;
      indefinida bloquea la cola inferior (programada sin fecha) y su revocación la libera;
      revocar una activa no mueve las demás; `shift` desplaza solo lo no terminado; `effective`
      en los bordes exactos (inicio incluido, fin excluido). Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(memberships): línea de tiempo de concesiones`.

### Tarea 5: Servicio de concesiones, congelación e historial

**Archivos:**
- Crear: `includes/Grants.php` (`HWE_Memberships_Grants`), `includes/Events.php`
  (`HWE_Memberships_Events`: historial y disparo de hooks tras el COMMIT), `includes/Members.php`
  (`HWE_Memberships_Members`: lectura del estado por usuario)
- Test: `backend/tests/Unit/Memberships/GrantsTest.php`, `MembersTest.php`

**Interfaces:**
- Consume: `HWE_Memberships_Timeline`, `HWE_Memberships_Levels`.
- Produce: `HWE_Memberships_Grants::grant(int $userId, int $levelId, ?int $days, string $origin,
  string $key, array $ref = [], int $actorId = 0, array $data = []): ?int` (null si la clave ya
  existía; orígenes `order`, `admin`, `bulk`, `referral_gift` + filtro
  `hwe_memberships_grant_origins`); `::revokeByKey(string $key, int $actorId = 0, string $reason =
  ''): bool`; `::removeAll(int $userId, int $actorId, string $reason): bool` (quitar);
  `::freeze(int $userId, int $actorId): bool`; `::unfreeze(int $userId, int $actorId): bool`
  (desplaza con `Timeline::shift`).
  `HWE_Memberships_Members::state(int $userId, ?DateTimeImmutable $at = null): array`
  (`level_id`, `rank`, `status` `none|active|frozen`, `ends_at` o nulo si indefinida,
  `queue`, `member_since`, `member_number`, `grant_key` de la concesión efectiva) — **calculado por
  fechas, sin escribir**; `::effectiveRank(int $userId): int` (0 si congelado o sin nivel).
- Acciones (§12, tras el COMMIT, solo ids): `hwe_membership_activated`, `_renewed`, `_upgraded`,
  `_deferred`, `_expired`, `_expiring`, `_frozen`, `_unfrozen`, `_granted_by_admin`,
  `_removed_by_admin`, `_revoked`, `_referral_gift_granted` y la general
  `hwe_membership_level_changed( int $userId, int $oldLevelId, int $newLevelId )`.

- [ ] Tests: `grant` en transacción con `FOR UPDATE` de la fila del usuario y `ROLLBACK` ante fallo;
      misma clave dos veces = una concesión; cada operación escribe su evento con actor y nivel
      anterior/nuevo; congelar sin membresía o descongelar no congelado se rechaza con motivo;
      congelado ⇒ `effectiveRank` 0 y nada caduca mientras dura; descongelar tras N días desplaza N
      días; `state` no ejecuta ninguna escritura (aserción sobre `$wpdb`); los hooks se disparan
      después del COMMIT y nunca si hubo ROLLBACK; número de socio único y estable por usuario
      (no es el id). Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(memberships): concesiones, congelación e historial`.

### Tarea 6: Productos de membresía, reglas de compra y activación al cobrar

**Archivos:**
- Crear: `includes/Products.php` (`HWE_Memberships_Products`: pestaña «Membresía» del producto,
  columna del listado), `includes/Purchase.php` (`HWE_Memberships_Purchase`)
- Test: `backend/tests/Unit/Memberships/ProductsTest.php`, `PurchaseTest.php` (dobles de
  `tests/Stubs/`)

**Interfaces:**
- Consume: `HWE_Memberships_Grants`, `HWE_Memberships_Members`, `HWE_Memberships_Levels`.
- Produce: `HWE_Memberships_Products::membershipOf(int $productId): ?array` (`level_id`, `days`
  o nulo = indefinida); `HWE_Memberships_Purchase::canPurchase(int $userId, int $levelId): array`
  (`ok` + `reason` estable de §5.3 + datos, p. ej. `days_left`);
  `::processOrder(int $orderId): void` (idempotente por línea, clave `order:<id>:item:<id>`);
  `::reverseOrder(int $orderId): void`; `::isCollected(WC_Order $order): bool` (filtro
  `hwe_memberships_collected_on_completion`, default `cod`). Metas del pedido en constantes.
- Validación de carrito en la Store API (añadir y al crear pedido) con los motivos
  `membership_login_required`, `membership_one_per_cart`, `membership_not_purchasable`,
  `membership_account_too_new`, `membership_account_pending`, `membership_already_indefinite`.

- [ ] Tests: el producto de membresía es simple y virtual (se fuerza al guardar), ligado a un nivel
      existente con días ≥ 0; cada regla de compra rechaza con su motivo (invitado, segunda línea o
      cantidad 2, nivel no comprable, antigüedad, `hwe_account_is_pending` verdadero, indefinida ya
      cubierta) y se comprueba también al crear el pedido; `processOrder` solo con `is_paid()`;
      `cod` espera a `completed`; `processing` + `completed` + `payment_complete` = una concesión
      (Foco 1); `cancelled`/`refunded` total revoca y uno parcial no; pedido de invitado se registra
      y se ignora. Verlos fallar.
- [ ] Implementar (hooks de estado de pedido de Woo, compatibles con HPOS).
- [ ] `composer test` en verde. Commit `feat(memberships): compra y activación al cobrar`.

### Tarea 7: Tarea diaria

**Archivos:**
- Crear: `includes/Scheduler.php` (`HWE_Memberships_Scheduler`: evento `hwe_memberships_daily`)
- Modificar: `hwe-memberships.php` (programar al activar, retirar al desactivar)
- Test: `backend/tests/Unit/Memberships/SchedulerTest.php`

**Interfaces:**
- Consume: `HWE_Memberships_Grants`, `HWE_Memberships_Events`.
- Produce: `HWE_Memberships_Scheduler::run(int $batch = 200): int` (transiciones materializadas).
  La Tarea 16 se engancha a `hwe_membership_expired` para revocar tokens.

- [ ] Tests: activar programa un único evento y desactivar lo retira; materializa
      `scheduled → active` y `active → ended` por lotes con tope por ejecución; dispara
      `_activated`/`_expired`/`_level_changed` una vez por transición (dos ejecuciones = mismas
      transiciones); `_expiring` una vez por concesión a `expiring_notice_days` (0 = nunca);
      congelados no caducan; módulo apagado no hace nada. Verlos fallar.
- [ ] Implementar. Nada de bucles sobre todos los usuarios en peticiones normales.
- [ ] `composer test` en verde. Commit `feat(memberships): tarea diaria de transiciones`.

### Tarea 8: Registro de tipos de beneficio y ventaja informativa

**Archivos:**
- Crear: `includes/Benefits/Registry.php` (`HWE_Memberships_Benefits`),
  `includes/Benefits/Benefit.php` (clase base abstracta `HWE_Memberships_Benefit`),
  `includes/Benefits/InfoPerk.php`
- Test: `backend/tests/Unit/Memberships/BenefitsRegistryTest.php`

**Interfaces:**
- Produce: `HWE_Memberships_Benefits::all(): array<string,HWE_Memberships_Benefit>` (núcleo +
  filtro `hwe_memberships_benefit_types`; mal formados o con clave del núcleo se descartan y se
  registran); `::get(string $key): ?HWE_Memberships_Benefit`;
  `::sanitizeLevelConfig(array $raw): array` (por tipo, según sus campos);
  `::activeFor(int $userId): array` (beneficios con su estado público).
  Clase base: `key(): string`, `fields(): array` (tipos de campo del Control Center: número,
  porcentaje, booleano, selección, nivel, categorías, texto traducible), `dependsOn(): ?string`
  (`loyalty`, `loyalty:points`, `loyalty:referrals`, `shipping`… o nulo), `available(): bool`
  (dependencia activa), `configFor(int $levelId): ?array`, `isActiveFor(int $userId): bool`
  (nivel efectivo, no congelado, `enabled`, `available()`), `stateFor(int $userId): array`,
  `publicData(int $levelId): array` (clave + datos estructurados, nunca frases),
  `register(): void` (sus hooks; solo si el módulo está activo).
- Clave del tipo informativo: `info_perk` (título y texto traducibles).

- [ ] Tests: el registro carga los de serie y uno de prueba vía filtro; descarta uno sin clase
      base, sin clave o que pisa una del núcleo, y lo registra; el saneado recorta o rechaza
      valores fuera de rango según los campos; un tipo con dependencia apagada no es
      `isActiveFor` ni sale en `activeFor` pero su config se conserva; congelado ⇒ ningún
      beneficio activo; `info_perk` devuelve sus textos por idioma. Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(memberships): registro de tipos de beneficio`.

### Tarea 9: Requisitos de acceso y acceso anticipado

**Archivos:**
- Crear: `includes/Access.php` (`HWE_Memberships_Access`), `includes/Benefits/EarlyAccess.php`,
  `includes/Admin/TermFields.php` (rango mínimo de categoría), campos de requisito en la pestaña
  del producto (rango mínimo, fecha de apertura) junto a `Products.php`
- Test: `backend/tests/Unit/Memberships/AccessTest.php`

**Interfaces:**
- Consume: `HWE_Memberships_Levels`, `HWE_Memberships_Members`, `HWE_Memberships_Benefits`.
- Produce: `HWE_Memberships_Access::requirementFor(int $productId): array` (`min_rank`,
  `level_id` y slug del nivel mínimo, `opens_at` o nulo); `::categoryRequirement(int $termId):
  array`; `::canView(int $userId, int $productId): bool` (0 = sin usuario);
  `::lockReason(int $userId, int $productId): ?array` (`membership_required` +
  nivel, o `membership_early_access` + fecha; nulo si accesible). Filtro
  `hwe_memberships_product_requirement` que **solo puede endurecer** (un valor más laxo se ignora y
  se registra). Caché por petición, invalidada al guardar producto, categoría o nivel. Beneficio
  `early_access` (§7.6): antes de `opens_at` solo niveles con el beneficio y el rango requerido.

- [ ] Tests: herencia por cadena de categorías (gana el mayor; cambiar el padre no reescribe hijas);
      requisito del producto vs categorías (gana el mayor); acceso anticipado antes/después de la
      fecha con y sin beneficio; el filtro no puede abrir; error al evaluar ⇒ bloqueado (falla
      cerrado, registrado); módulo apagado ⇒ todo accesible sin requisitos; el personal de la
      tienda no se salta nada; cambiar el requisito de una categoría o producto revalida el tag
      `products` (por el mecanismo de revalidación existente del núcleo); guardar una fecha de
      apertura programa un único evento que revalida `products` al llegar la fecha (reprogramado
      si cambia, retirado si se borra). Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(memberships): requisitos de acceso y acceso anticipado`.

### Tarea 10: Ficha de escaparate en toda vía pública

**Archivos:**
- Crear: `includes/Teaser.php` (`HWE_Memberships_Teaser`), `includes/Graphql.php` (campo del módulo
  en `Product` y `ProductCategory`, recorte en resolvers)
- Test: `backend/tests/Unit/Memberships/TeaserTest.php`, `GraphqlTest.php`

**Interfaces:**
- Consume: `HWE_Memberships_Access`.
- Produce: `HWE_Memberships_Teaser::isTeaser(int $productId, int $userId): bool`;
  `::fields(): string[]` (los que sí salen, §6.2); campo GraphQL `hweMembership` en `Product`
  (`requiredLevel { slug, rank, name }`, `opensAt`, `locked`) y en `ProductCategory`
  (`requiredLevel`). Nombre exacto del campo a criterio del agente, documentado en la Tarea 24.

- [ ] Tests por vía (Foco 4), sin usuario y con usuario sin nivel: ficha GraphQL, listado, búsqueda,
      relacionados/venta cruzada, variaciones por id, `wc/store/v1/products` (listado y ficha) y
      cualquier REST pública del repo que exponga producto (buscar con grep y cubrir cada una) —
      solo salen los campos de escaparate y la marca `locked`; con el nivel, ficha completa; la
      categoría bloqueada mantiene nombre, imagen y descripción; variable ⇒ rango de precio sin
      variaciones; módulo apagado ⇒ campo nulo o ausente y nada recortado. Verlos fallar.
- [ ] Implementar el recorte **en origen** (WordPress), no en el frontend.
- [ ] `composer test` en verde. Commit `feat(memberships): ficha de escaparate en las vías públicas`.

### Tarea 11: Extensión de la Store API y bloqueo de compra

**Archivos:**
- Crear: `includes/StoreApi.php` (`HWE_Memberships_StoreApi`: extensión `hwe-memberships` del
  carrito, validación de líneas al añadir y al crear el pedido)
- Test: `backend/tests/Unit/Memberships/StoreApiTest.php`

**Interfaces:**
- Consume: `HWE_Memberships_Access`, `HWE_Memberships_Purchase`, `HWE_Memberships_Members`.
- Produce: esquema de la extensión (§10.4): `level` (o nulo), `member_discount` total y por línea
  (unidades menores, string; lo rellena la Tarea 12), `free_delivery` (`available`, `remaining`,
  `requested`, `applied`, `reason`; la Tarea 13), `blocked_items` (clave de línea + motivo + datos),
  `purchase_notice` (`renewal` | `upgrade` | `scheduled` | nulo). Callback de actualización de la
  extensión para que la Tarea 13 declare el uso del envío gratis. Errores de la Store API con
  códigos estables `hwe_membership_required`, `hwe_membership_early_access` y los de §5.3.

- [ ] Tests: añadir una línea bloqueada falla con el código; una línea ya en el carrito que pasa a
      bloqueada (caducidad, congelación, quitar) aparece en `blocked_items` y el pedido no se crea
      (Foco 3); `purchase_notice` correcto para renovar/mejorar/bajar; con el módulo apagado la
      extensión no se registra. Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(memberships): extensión de la Store API y bloqueo de compra`.

### Tarea 12: Descuento de miembro

**Archivos:**
- Crear: `includes/Benefits/MemberDiscount.php`, `includes/Pricing.php` (`HWE_Memberships_Pricing`)
- Test: `backend/tests/Unit/Memberships/MemberDiscountTest.php`

**Interfaces:**
- Consume: `HWE_Memberships_Benefits`, `HWE_Memberships_StoreApi`.
- Produce: beneficio `member_discount` (campos: porcentaje 0–100, alcance todo/categorías);
  `HWE_Memberships_Pricing::priceFor(int $userId, int $productId, ?int $variationId = null):
  array` (`regular`, `price`, `member_price`, `percent`), la **misma** función que usa el carrito.
  Metas de línea y de pedido en constantes (`_hwe_membership_line_original`,
  `_hwe_membership_line_discount`, `_hwe_membership_discount_level`, `_hwe_membership_discount_percent`
  o equivalentes documentados).

- [ ] Tests: rebaja del precio de línea solo en el carrito del usuario con el beneficio; alcance
      por categorías con herencia; sobre precio de oferta; el cupón se aplica después; impuestos
      sobre lo rebajado; líneas de membresía nunca se descuentan; el catálogo (sin usuario) no
      cambia; pierde el nivel con el carrito lleno ⇒ el recálculo quita el descuento (Foco 3);
      metas escritas al crear el pedido; `priceFor` coincide con el carrito; porcentaje fuera de
      rango saneado. Verlos fallar.
- [ ] Implementar la mecánica que conserve las tres propiedades de §7.2 (por línea, impuestos,
      base de `loyalty`).
- [ ] `composer test` en verde. Commit `feat(memberships): descuento de miembro en el carrito`.

### Tarea 13: Envíos gratis por periodo (y enganche en `shipping`)

**Archivos:**
- Crear: `includes/Benefits/FreeDeliveries.php`
- Modificar (contrato nuevo de `shipping`): `backend/wp-content/plugins/hwe-shipping/includes/FreeShipping.php`
  (o donde el agente juzgue) para publicar un filtro estable con el que otro módulo marque el
  carrito como «envío gratis por motivo X»; `hwe-shipping/README.md` («Hooks estables») y
  `docs/PLUGIN_DEVELOPMENT.md`
- Test: `backend/tests/Unit/Memberships/FreeDeliveriesTest.php` y un caso en
  `backend/tests/Unit/Shipping/` para el filtro

**Interfaces:**
- Consume: `HWE_Memberships_Benefits`, `HWE_Memberships_StoreApi`, `HWE_Memberships_Grants`.
- Produce: beneficio `free_deliveries` (campo: cantidad ≥ 0); cupo por concesión activa;
  `reserve(int $orderId)`, `consume(int $orderId)`, `release(int $orderId)` idempotentes por pedido;
  ruta de declaración vía la extensión (`requested`). Filtro de `shipping` con nombre
  `hwe_shipping_free_reason` (o el que el agente documente) que recibe el contexto del paquete y
  devuelve un motivo o nada; `shipping` lo aplica con su propia lógica (solo opciones elegibles,
  nunca la reserva) y su meta `_hwe_free_shipping_reason`.

- [ ] Tests: cupo por concesión (renovar encadena cupo nuevo cuando empieza; el no usado muere con
      la concesión); la reserva comprueba disponible menos reservas en la misma transacción; dos
      pedidos simultáneos con 1 restante ⇒ uno reserva y el otro falla con
      `membership_free_delivery_unavailable` (Foco 2); consumo al cobrar, devolución al cancelar,
      fallar o reembolso total; repeticiones de estado no consumen dos veces (Foco 1); si el envío
      ya salía 0 por otra regla no se reserva; con `shipping` activo respeta opciones elegibles y
      reserva; sin `shipping` aplica a tarifas no de recogida por id de método; `shipping` apagado o
      sin el filtro no rompe nada (Foco 5). Verlos fallar.
- [ ] Implementar ambos lados.
- [ ] `composer test` en verde. Commit `feat(memberships): envíos gratis por periodo con reserva`.

### Tarea 14: Punto de entrada estable en `loyalty`

**Archivos:**
- Modificar: `backend/wp-content/plugins/hwe-loyalty/includes/` (archivo nuevo o existente a
  criterio, p. ej. `Api.php`), `hwe-loyalty/README.md`, `docs/PLUGIN_DEVELOPMENT.md` («Hooks
  estables de los módulos»)
- Test: `backend/tests/Unit/Loyalty/ApiTest.php`

**Interfaces:**
- Produce (contrato público de `loyalty`, sin que membresías conozca sus clases): funciones globales
  `hwe_loyalty_available(string $feature): bool` (`points` | `referrals`),
  `hwe_loyalty_credit(int $userId, int $points, string $type, string $key, array $ref = [], array
  $data = []): ?int` (null si no disponible, usuario no participante, tipo no declarado como abono o
  clave repetida) y `hwe_loyalty_reverse_credit(string $key, string $reversalKey): ?int` (revierte
  un abono limitado al saldo, idempotente, como `earn_reversal`). Nombres exactos a criterio, pero
  estables y documentados.

- [ ] Tests: abono por la API usa el libro (idempotente por clave); tipo no declarado se rechaza;
      con «Puntos» apagado devuelve null sin escribir; reversión limitada al saldo e idempotente;
      `available` refleja los interruptores. Verlos fallar.
- [ ] Implementar sin cambiar el comportamiento existente de `loyalty` (sus tests previos en verde).
- [ ] `composer test` en verde. Commit `feat(loyalty): punto de entrada estable para otros módulos`.

### Tarea 15: Beneficios que dependen de `loyalty`

**Archivos:**
- Crear: `includes/Benefits/ActivationPoints.php`, `includes/Benefits/ReferralBonus.php`,
  `includes/Benefits/ReferralGift.php`, `includes/LoyaltyBridge.php`
  (`HWE_Memberships_LoyaltyBridge`: comprobaciones de disponibilidad y tipos declarados con
  `hwe_loyalty_entry_types`)
- Test: `backend/tests/Unit/Memberships/LoyaltyBenefitsTest.php`

**Interfaces:**
- Consume: Tarea 14, `hwe_loyalty_order_earnings`, `hwe_loyalty_attribution_confirmed`,
  `hwe_email_verified`, `HWE_Memberships_Grants`, `HWE_Memberships_Purchase`.
- Produce: beneficios `activation_points` (campo: puntos ≥ 0; depende de `loyalty:points`),
  `referral_bonus` (campo: % adicional N1; depende de `loyalty:referrals`; tipo de movimiento
  `membership_referral_bonus`), `referral_gift` (campos: nivel a regalar, días ≥ 1, máximo por
  periodo ≥ 1; depende de `loyalty:referrals`). Tipo de movimiento `membership_activation` para los
  puntos. Clave del regalo `gift:<referido>`; contador del tope por concesión del referidor.

- [ ] Tests de puntos: abono al cobrar una concesión de origen `order`, una vez por línea (Foco 1);
      no por regalos ni admin; reversión con el reembolso total; `loyalty` sin «Puntos» en el cobro
      ⇒ no hay abono ni reintento y queda en historial/log.
- [ ] Tests de comisión extra: entrada añadida al filtro con el tipo propio y el nivel del
      referidor **al cobrar**; `loyalty` la revierte con el pedido; sin el beneficio no añade nada.
- [ ] Tests de regalo (§7.5): lo recibe el **referido**; opción de verificación encendida ⇒ solo
      con `hwe_email_verified` y atribución existente, nunca a cuentas sin verificar (incluidas las
      de wp-admin); apagada ⇒ con `hwe_loyalty_attribution_confirmed`; una vez por referido; tope
      por concesión del referidor que se reinicia al renovar; dos verificaciones simultáneas con el
      tope a 1 ⇒ un solo regalo (Foco 2); referidor congelado o sin el beneficio ⇒ no hay regalo;
      referido con nivel igual o superior ⇒ regalo encolado; sin puntos por activación; tope
      alcanzado queda en el historial del referidor.
- [ ] Tests de convivencia (Foco 5): `loyalty` inactivo ⇒ ningún filtro de membresías registrado
      en sus hooks y los tres beneficios ausentes para el cliente; membresías apagado ⇒ `loyalty`
      intacto.
- [ ] Verlos fallar; implementar; `composer test` en verde.
- [ ] Commit `feat(memberships): beneficios conectados con loyalty`.

### Tarea 16: Carné digital (WordPress)

**Archivos:**
- Crear: `includes/Card.php` (`HWE_Memberships_Card`), `includes/Rest/CardRoutes.php`
- Test: `backend/tests/Unit/Memberships/CardTest.php`

**Interfaces:**
- Produce: `HWE_Memberships_Card::issue(int $userId): ?array` (`token`, `expires_at`; reutiliza el
  vivo; null sin nivel efectivo o con el carné apagado); `::revoke(int $userId): void` (enganchado a
  caducar, congelar, quitar, revocar); `::verify(string $token): ?array` (datos mínimos de §9).
  Rutas internas `GET memberships/card?user_id` y `GET memberships/verify?token`.

- [ ] Tests: el token tiene alta entropía y solo se guarda su hash (aserción sobre lo escrito); como
      mucho uno vivo por usuario; caduca según `card_token_ttl_hours`; revocación en cada evento;
      `verify` de inexistente, caducado o revocado da la misma respuesta; la respuesta no lleva id,
      email ni login; nombre enmascarado; documento enmascarado solo con `customer-identity` activo
      (Foco 5); número de socio con prefijo; rutas exigen el secreto; carné apagado ⇒ 404. Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(memberships): carné digital verificable`.

### Tarea 17: Rutas internas de lectura

**Archivos:**
- Crear: `includes/Rest/ReadRoutes.php` (`levels`, `me`, `history`, `prices`, `product`)
- Test: `backend/tests/Unit/Memberships/ReadRoutesTest.php`

**Interfaces:**
- Produce: `GET hwe/v1/memberships/levels?locale` (niveles visibles, beneficios por `publicData`,
  productos de compra con id, precio y días); `GET memberships/me?user_id` (`Members::state`,
  beneficios activos con `stateFor`, elegibilidad por nivel, regalos restantes del referidor);
  `GET memberships/history?user_id&page&per_page` (tope 100); `GET memberships/prices?user_id&ids`
  (lista acotada, tope 50, `Pricing::priceFor`); `GET memberships/product?user_id&slug|id`
  (completo o escaparate según `Access::canView`, nunca un error que confirme el contenido).
  Fechas ISO 8601 UTC. `user_id` inválido ⇒ 400; usuario inexistente ⇒ respuesta vacía (no sirve
  para enumerar).

- [ ] Tests de forma de cada respuesta, secreto obligatorio, módulo apagado ⇒ `rest_no_route`,
      topes, y que `product` sin acceso devuelve exactamente la ficha de escaparate. Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(memberships): rutas internas de lectura`.

### Tarea 18: `lib/memberships` y rutas BFF de lectura y carné

**Archivos:**
- Crear: `frontend/src/lib/memberships/` (`types.ts`, `server.ts`, `describe.ts` para componer
  textos de beneficios e historial, `guard.ts` para el carné, helpers puros de requisitos y
  duraciones, con tests colocados)
- Crear: `frontend/src/app/api/memberships/{levels,me,history,prices,card}/route.ts`,
  `frontend/src/app/api/memberships/products/[slug]/route.ts`,
  `frontend/src/app/api/memberships/verify/[token]/route.ts` (con tests colocados)
- Modificar: `frontend/src/i18n/messages/es.json` y `en.json` (namespace del módulo); registrar el
  tag `memberships` donde el núcleo declara sus tags
- Modificar: los lectores del catálogo (`frontend/src/lib/catalog/` y sus queries en
  `lib/woocommerce/queries.ts`) para pedir el campo del módulo y tolerar que no exista

**Interfaces:**
- Consume: Tareas 10, 16 y 17.
- Produce: tipos `MembershipLevel`, `MembershipState`, `MembershipBenefit`, `MembershipRequirement`,
  `MembershipCard`, `MembershipVerification`; lectores `getMembershipLevels(locale)` (ISR, tag
  `memberships`), `getMembershipState(userId)`, etc. Rutas de §10.3 con sus accesos.

- [ ] Tests: cada ruta 404 con el módulo apagado; las de sesión toman `user_id` de la sesión y
      responden 401 sin ella; `card` exige `card_enabled` y nivel (403 tipado sin él); `verify` es
      pública con rate-limit por IP y la misma respuesta para cualquier token inválido; `prices`
      sin sesión devuelve precios públicos; `products/[slug]` sin cache compartida; fallo de WP ⇒
      5xx tipado y evento registrado; `describe` compone en es/en desde clave + datos; el lector
      de catálogo funciona con y sin el campo del módulo. Verlos fallar.
- [ ] Implementar; `npm run i18n:check` en verde.
- [ ] Verificación frontend + `npx next build`.
- [ ] Commit `feat(memberships): lib y rutas BFF de lectura y carné`.

### Tarea 19: BFF del carrito y del checkout

**Archivos:**
- Crear: `frontend/src/app/api/store/cart/free-delivery/route.ts` (POST/DELETE, con test)
- Modificar: `frontend/src/app/api/store/checkout/route.ts` y su test (traducción de errores del
  módulo a 409), `frontend/src/lib/memberships/cart.ts` (tipos y lectura de
  `extensions.hwe-memberships`, con test)

**Interfaces:**
- Consume: Tareas 11–13.
- Produce: `POST/DELETE /api/store/cart/free-delivery` (sesión, `guardMutation`, devuelve el carrito
  recalculado); 409 con `reason` `membership_required`, `membership_early_access`,
  `membership_free_delivery_unavailable` y los de compra de §5.3.

- [ ] Tests: 404 con módulo apagado; sin sesión 401; sin CSRF rechazado; el cuerpo solo declara
      «usar/no usar», nunca importes; cada error de la Store API del módulo sale como 409 con su
      motivo; un error ajeno sigue su camino actual. Verlos fallar.
- [ ] Implementar.
- [ ] Verificación frontend + `npx next build`.
- [ ] Commit `feat(memberships): carrito y checkout en el BFF`.

### Tarea 20: Administración — menú, capacidades, niveles y beneficios por nivel

**Archivos:**
- Crear: `includes/Admin/Admin.php`, `Capabilities.php`, `Menu.php`, `LevelsScreen.php` (o la UI del
  CPT ampliada), `BenefitsScreen.php`
- Test: `backend/tests/Unit/Memberships/AdminLevelsTest.php`, `CapabilitiesTest.php`

**Interfaces:**
- Consume: `HWE_Memberships_Levels`, `HWE_Memberships_Benefits`.
- Produce: menú «Membresías»; capacidades concedidas a `administrator` y `shop_manager` en
  `activated_plugin` y autorreparadas en `admin_menu`/`admin_init` (calco de
  `HWE_Loyalty_Admin_Capabilities`); pantalla de beneficios por nivel que pinta los campos de cada
  tipo registrado (incluidos los de instancia), en gris con motivo los de dependencia apagada,
  y vista comparativa niveles × beneficios. Guardar un nivel o sus beneficios invalida el tag
  `memberships` (registrado en `hwe_revalidation_tags`).

- [ ] Tests: capacidades concedidas e idempotentes, nunca a roles de instancia; toda escritura
      exige capacidad y nonce; el guardado pasa por `sanitizeLevelConfig`; un tipo con dependencia
      apagada se muestra deshabilitado y conserva su valor al guardar; guardar revalida
      `memberships`; módulo inactivo ⇒ sin menú. Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(memberships): administración de niveles y beneficios`.

### Tarea 21: Administración — socios, historial, resumen, perfil, columnas y pedido

**Archivos:**
- Crear: `includes/Admin/MembersScreen.php`, `HistoryScreen.php`, `Stats.php`, `Csv.php`,
  `UserProfile.php`, `UserColumns.php`, `OrderBox.php`
- Test: `backend/tests/Unit/Memberships/AdminMembersTest.php`

**Interfaces:**
- Consume: `HWE_Memberships_Grants`, `HWE_Memberships_Members`, `HWE_Memberships_Events`.
- Produce: pantallas de §11.1–11.2. El perfil solo ofrece acciones explícitas (conceder, extender,
  quitar, congelar/descongelar, con nota); no hay un campo de nivel que se sobrescriba.

- [ ] Tests: filtros y ordenación del listado de socios y de la columna de usuarios resueltos en
      SQL (sin cargar todos los usuarios); CSV sin email ni login, celdas protegidas contra fórmulas
      y por lotes; cada acción del perfil delega en `Grants` y deja historial con el admin; la caja
      del pedido usa las constantes de metas; HPOS (`shop_order` y la pantalla de
      `wc_get_page_screen_id('shop-order')`). Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(memberships): administración de socios y perfil`.

### Tarea 22: Asignar, quitar y congelar en masa

**Archivos:**
- Crear: `includes/Admin/Bulk.php` (pantalla, vista previa) e `includes/Bulk.php`
  (`HWE_Memberships_Bulk`: lotes con Action Scheduler)
- Test: `backend/tests/Unit/Memberships/BulkTest.php`

**Interfaces:**
- Consume: `HWE_Memberships_Grants`, `HWE_Memberships_Settings::participantRoles`.
- Produce: `HWE_Memberships_Bulk::preview(array $selection, array $operation): array` (afectados por
  caso, no encontrados de la lista); `::start(...)` (id de lote) y `::progress(string $batchId):
  array`. Selecciones de §11.3 (participantes, sin membresía, de un nivel, lista de emails/ids);
  operaciones: asignar (días o indefinida; modos `only_new`, `extend_same`, `replace`), quitar,
  congelar, descongelar. Clave por usuario `bulk:<lote>:<usuario>`.

- [ ] Tests: la vista previa cuenta bien cada caso y lista lo no encontrado; los lotes son
      reanudables (reprocesar un lote no duplica), no guardan listas de ids en opciones y escriben
      historial con lote y admin; cada modo hace lo de §11.3; sin Action Scheduler disponible el
      lote no arranca y lo dice; capacidad + nonce. Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(memberships): operaciones en masa por lotes`.

### Tarea 23: Privacidad

**Archivos:**
- Crear: `includes/Privacy.php` (`HWE_Memberships_Privacy`)
- Test: `backend/tests/Unit/Memberships/PrivacyTest.php`

**Interfaces:**
- Produce: exportador y eliminador de WordPress (grupo «Membresías») y limpieza en `delete_user`,
  con el criterio de `HWE_Loyalty_Privacy`: se exportan estado, concesiones e historial; se borran
  tokens del carné; concesiones e historial ligados a pedidos se conservan anonimizados (notas y
  datos libres vaciados); idempotente y por lotes.

- [ ] Tests de exportación paginada, borrado idempotente, tokens eliminados, contabilidad de
      pedidos conservada, `delete_user` sin herencia por `reassign`. Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(memberships): exportador y eliminador de datos personales`.

### Tarea 24: Ejemplo vivo de beneficio de instancia

**Archivos:**
- Crear: `examples/instance-minimal/backend/wp-content/mu-plugins/instance/70-example-membership-benefit.php`,
  `examples/instance-minimal/backend/tests/Unit/Instance/ExampleMembershipBenefitTest.php`
- Modificar: `examples/instance-minimal/README.md`

**Interfaces:**
- Consume: filtro `hwe_memberships_benefit_types` y clase base de la Tarea 8.
- Produce: un tipo sencillo de ejemplo (p. ej. «regalo cada N pedidos cobrados» que deja una nota
  privada en el pedido), sin vocabulario de ninguna instancia real.

- [ ] Test del ejemplo (se registra, se configura por nivel, actúa solo con el beneficio activo y
      el módulo encendido). Verlo fallar.
- [ ] Implementar; reproducir localmente el job «contrato» de CI (superponer el ejemplo y correr
      `composer test`, `npm run test`, `type-check` y `build`).
- [ ] Commit `docs(examples): beneficio de membresía de instancia`.

### Tarea 25: Módulo apagado, stub de CI, verificación en vivo y documentación

**Archivos:**
- Modificar: `backend/scripts/verify-modules.sh` (toggle de `memberships`: tablas, rutas internas,
  extensión de la Store API, recorte de un producto bloqueado en GraphQL y Store API sin usuario),
  `.github/scripts/site-config.ci.json` / `stub-graphql.mjs` (`config.memberships`, módulo y
  campo de catálogo), `frontend/tests/e2e/modules-off.spec.ts`
- Modificar docs (§16 del spec): `hwe-memberships/README.md` y `MANUAL.md` completos,
  `docs/CONFIGURATION.md`, `docs/FRONTEND_CONNECT.md` Parte A (config, GraphQL, ficha de
  escaparate, rutas BFF, extensión, carné), `docs/PLUGIN_DEVELOPMENT.md` (grupo reservado, hooks,
  tipos de beneficio de instancia, contratos nuevos de `loyalty` y `shipping`), `AGENTS.md` (11
  módulos, §6.9, tabla de estado), `CLAUDE.md` (lista de módulos), `CHANGELOG.md` (`[Sin publicar]`)

- [ ] Ampliar el stub y la pasada e2e sin módulos; borrar `frontend/.next/cache` tras cambiar el stub.
- [ ] Escribir la documentación; revisar que «diez módulos»/«10 módulos» no quede en ningún sitio.
- [ ] Verificación completa: `composer test`; `npm run lint`, `type-check`, `format:check`,
      `i18n:check`, `test`, `test:coverage` (umbrales sin bajar), `audit:gate`; `npx next build`;
      `bash .github/scripts/check-anti-instance.sh`.
- [ ] Si la pila está disponible: `sh backend/scripts/verify-modules.sh all` y una prueba manual de
      compra de membresía cobrada → activación, producto bloqueado sin y con nivel, envío gratis
      consumido; si no, declararlo como no verificado en vivo.
- [ ] Commit `docs(memberships): documentación, stub de CI y verificación del módulo`.

### Tarea 26: Revisión de extremo a extremo

Revisión de solo lectura una vez terminada la Tarea 25, hecha por un subagente de Claude (Opus,
esfuerzo medio) lanzado por el coordinador.

**Alcance:** `hwe-memberships` completo; sus enganches en el núcleo (`Modules.php`, grupo reservado,
`hwe-bff-only`), en `loyalty` (Tarea 14) y en `shipping` (Tarea 13); el BFF y `lib/memberships`.

**Flujos:** comprar → cobrar → activar → renovar/mejorar/bajar → congelar → caducar; reembolso;
catálogo bloqueado por cada vía pública; descuento y envío gratis en carrito y checkout; puntos,
comisión extra y regalo de referido con `loyalty` encendido y apagado; carné; masivo;
administración; privacidad; módulo apagado.

**Qué reporta:** solo hallazgos de severidad media, alta o grave (bugs, incoherencias con el spec,
huecos de seguridad o concurrencia, consultas a optimizar, refactors con beneficio claro), cada uno
con `archivo:línea`, escenario de fallo, impacto y arreglo propuesto.

- [ ] El coordinador valida cada hallazgo, lo reproduce con un test en rojo cuando proceda, corrige
      y commitea por hallazgo; lo que no se arregle queda anotado con su motivo.
