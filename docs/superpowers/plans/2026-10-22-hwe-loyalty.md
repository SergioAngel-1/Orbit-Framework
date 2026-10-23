# Módulo `loyalty` (`hwe-loyalty`) — plan de implementación

> **Para agentes:** SUB-SKILL REQUERIDA: usa superpowers:subagent-driven-development (recomendado) o
> superpowers:executing-plans para ejecutar este plan tarea por tarea. Los pasos usan casillas
> (`- [ ]`) para el seguimiento.
>
> **Estilo del plan (preferencia del usuario):** sin código literal. Cada paso dice qué lograr,
> dónde, con qué nombres y cómo se comprueba; el cómo concreto es criterio del agente, siempre
> dentro del spec, de este plan y, sobre todo, de `AGENTS.md`/`CLAUDE.md`. Ante una duda que el
> spec no resuelve, manda la convención del proyecto; si tampoco la resuelve, decide y anótalo en
> el mensaje del commit.

**Objetivo:** añadir el módulo opcional `loyalty` (monedero de puntos + referidos de dos niveles)
al catálogo del framework, con su plugin de WordPress, su contrato público, sus rutas BFF y su
lógica de `lib/`, sin UI.

**Arquitectura:** WordPress es la fuente de verdad: un libro mayor transaccional en tablas propias
del plugin `hwe-loyalty` calcula, abona y descuenta; el canje viaja como extensión de la Store API y
el descuento definitivo se confirma contra el libro al crear el pedido. Los ajustes viven en el
grupo reservado `loyalty` del Control Center. El BFF expone `/api/loyalty/*` y `/api/store/cart/points`
hablando con rutas internas `hwe/v1/loyalty/*` protegidas por el secreto interno.

**Stack:** PHP 8 + WordPress/WooCommerce (Store API, wc/v3, WP-Cron), PHPUnit 12 + Brain Monkey;
Next.js 16 (Route Handlers, `proxy.ts`), TypeScript, Zod, Vitest, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-22-hwe-loyalty-design.md` (léelo entero antes de la
primera tarea; las referencias `§N` de abajo apuntan a él).

## Restricciones globales

- Docs, comentarios y mensajes en **español**; código y nombres técnicos en **inglés**.
- Puerta anti-instancia: ningún nombre, slug, texto ni supuesto de la instancia de origen en el repo
  (`bash .github/scripts/check-anti-instance.sh` en verde al cerrar cada tarea).
- Ningún texto del módulo lleva el nombre de la moneda escrito a mano: sale de
  `loyalty.currency_name` / `currency_name_plural` / `currency_icon`.
- Plugin: `backend/wp-content/plugins/hwe-loyalty/`, cabecera `Version: 1.0.0`, `Requires PHP: 8.0`,
  `Requires Plugins: woocommerce`, `Text Domain: hwe`; `README.md` técnico + `MANUAL.md` editorial.
- Prefijos: tablas `{$wpdb->prefix}hwe_loyalty_`, metas `_hwe_loyalty_`, hooks `hwe_loyalty_`,
  rutas internas `hwe/v1/loyalty/…`, namespace Store API `hwe-loyalty`, cookie `hwe_ref`.
- Ajustes leídos con `\HWE\ControlCenter\Storage::get` y fallback; nunca fatal sin el Control Center.
- Rutas internas de WP solo con `X-HWE-Internal-Secret` (`hwe_has_internal_secret`), fallan cerradas.
- BFF: `requireModule(config, "loyalty")` + guarda del interruptor al inicio de cada ruta (404);
  `guardMutation` en toda escritura; `user_id` siempre de la sesión; WordPress siempre vía `wpFetch`
  o los clientes existentes; `import "server-only"` en módulos de servidor.
- Puntos = enteros, redondeo hacia abajo; descuentos con la precisión de la moneda de Woo; importes
  de la Store API en unidades menores.
- Ningún fallo se traga en silencio: `error_log('[hwe] loyalty: …')` en WP, eventos estructurados
  (`logReadFailure`, `logger.*`) en el BFF; logs sin PII (solo ids).
- Seguridad falla cerrada (saldo, canje, transferencia, secreto); contenido falla suave.
- Un commit por tarea terminada, en verde, con `git add` de rutas explícitas; mensaje en español
  estilo Conventional Commits, terminado en
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Rama: `feat/hwe-loyalty`.
- Verificación por tarea: backend `cd backend && composer test`; frontend `cd frontend && npm run
  lint && npm run type-check && npm run format:check && npm run test`; y **`npx next build`** antes
  de cerrar cualquier tarea que toque `frontend/`.
- Sé honesto sobre lo no verificado en vivo (Store API real, webhooks, cron): dilo en el resumen de
  la tarea si no levantaste la pila.

## Foco de revisión

1. **Saldo que cambia entre el carrito y la creación del pedido** (dos pestañas, una transferencia
   o una caducidad entremedias): el checkout falla con motivo claro y no nace un pedido con
   descuento sin respaldo → test en Tarea 8.
2. **El mismo pedido notificado varias veces** (`processing` → `completed`, webhook repetido,
   `payment_complete` + cambio de estado): un solo abono por beneficiario → test en Tarea 6.
3. **Ajustes que cambian con un canje ya aplicado al carrito** (otro `point_value`, módulo o
   «Puntos» apagado, `max_redeem_per_order` reducido): el recálculo recorta o quita el descuento,
   nunca conserva el viejo → test en Tarea 8.
4. **Referidor eliminado o usuario suprimido** (WP «eliminar usuario», eliminador de privacidad):
   lecturas de red, abonos pendientes y comisiones futuras no rompen ni abonan a un id inexistente
   → tests en Tareas 5 y 12.
5. **Valores de configuración absurdos** (`point_value` 0 o negativo, porcentajes > 100,
   `min_cash_due` 0, `expiry_days` negativo): se sanean al leer; con `point_value` ≤ 0 el canje
   queda deshabilitado (falla cerrado) → test en Tarea 2.

---

### Tarea 1: Registrar el módulo en el núcleo

**Archivos:**
- Modificar: `backend/wp-content/mu-plugins/hwe-control-center/Modules.php` (`CORE_IDS`),
  `backend/wp-content/mu-plugins/hwe-control-center/Schema.php` (usar el trait nuevo en `core()`)
- Crear: `backend/wp-content/mu-plugins/hwe-control-center/SchemaGroups/Loyalty.php` (grupo
  reservado vacío, calcado de `SchemaGroups/Shipping.php`, etiqueta «Fidelización»)
- Modificar: `frontend/src/lib/config/types.ts` (`ModuleId` + `"loyalty"`),
  `frontend/src/lib/config/defaults.ts` (`modules.loyalty: false`), `frontend/src/lib/config/modules.ts`
  si enumera ids, `backend/scripts/verify-modules.sh` si enumera ids
- Tests: los existentes de `backend/tests/Unit/ControlCenter/` y `frontend/src/lib/config/*.test.ts`
  que fijan el catálogo

**Interfaces:**
- Produce: id de módulo `loyalty` ↔ slug `hwe-loyalty`; grupo raíz `loyalty` del Schema, vacío en
  el núcleo y poblable por el plugin con `hwe_control_center_schema`.

- [ ] Ajustar los tests de catálogo para que esperen 10 módulos con `loyalty` y que el grupo raíz
      `loyalty` exista vacío y no se descarte al poblarlo desde un filtro; ejecutarlos y verlos fallar.
- [ ] Implementar el registro (PHP y TS en paridad; el comentario «nueve plugins» pasa a diez).
- [ ] `composer test` y `npm run test` en verde; `npx next build`.
- [ ] Commit `feat(core): registra el módulo loyalty y su grupo reservado`.

### Tarea 2: Plugin base y ajustes del módulo

**Archivos:**
- Crear: `backend/wp-content/plugins/hwe-loyalty/hwe-loyalty.php` (clase principal `HWE_Loyalty`:
  arranque, comprobación de WooCommerce, carga de `includes/`), `includes/Settings.php`
  (`HWE_Loyalty_Settings`), `README.md` y `MANUAL.md` iniciales (se completan en la Tarea 17)
- Modificar: `backend/scripts/instance.config.example.json` (grupo `loyalty` con los defaults),
  `frontend/src/lib/config/types.ts` (`LoyaltyConfig` en `SiteConfig.loyalty`),
  `frontend/src/lib/config/defaults.ts` (`CONFIG_DEFAULTS.loyalty`)
- Test: `backend/tests/Unit/Loyalty/SettingsTest.php`, test de paridad de defaults existente o
  colocado en `frontend/src/lib/config/`

**Interfaces:**
- Produce (PHP): `HWE_Loyalty_Settings::get(): array` (ajustes saneados), `::pointsActive(): bool`,
  `::referralsActive(): bool` (= puntos activos ∧ referidos), `::transfersActive(): bool`,
  `::redeemActive(): bool` (puntos activos ∧ `point_value` > 0), `::allowedRoles(): string[]`
  (filtrable con `hwe_loyalty_participant_roles`),
  `::canParticipate(int $userId): bool`.
- Produce (TS): `LoyaltyConfig` con los campos de spec §9.1 (nombres exactos de ahí).

- [ ] Tests PHP: el filtro `hwe_control_center_schema` puebla `loyalty` con todos los campos de §9.1
      y sus defaults; `allowed_roles` no es público y por defecto vale `customer` + `subscriber`;
      todo lo opcional nace apagado (`cashback_enabled`, `full_payment_enabled`, `transfers_enabled`,
      `level2_enabled`); el saneado recorta porcentajes a [0, 100], fuerza enteros ≥ 0, `min_cash_due`
      > 0 y `point_value` ≤ 0 deja `redeemActive()` en falso (Foco 5); `referralsActive()` es falso
      con puntos apagados; `canParticipate` según roles. Verlos fallar.
- [ ] Implementar el plugin base y los ajustes. Elegir el tipo de campo del icono entre los que
      ofrece el Control Center (o registrar uno con `hwe_control_center_field_types` si hace falta).
- [ ] Reflejar el grupo en `instance.config.example.json`, `types.ts` y `defaults.ts` con los mismos
      defaults; test de paridad en verde.
- [ ] `composer test`, verificación frontend y `npx next build` en verde.
- [ ] Commit `feat(loyalty): plugin base y ajustes en el Control Center`.

### Tarea 3: Libro mayor

**Archivos:**
- Crear: `includes/Schema.php` (tablas `hwe_loyalty_ledger`, `hwe_loyalty_balances`,
  `hwe_loyalty_referrals` y lo que haga falta para el consumo FIFO, con `dbDelta`, versión en la
  opción `hwe_loyalty_db_version` y migración al detectar versión vieja, patrón `hwe-consent-log`),
  `includes/Ledger.php` (`HWE_Loyalty_Ledger`), `includes/EntryTypes.php` (tipos de §5.2 en un solo
  sitio, ampliable por filtro `hwe_loyalty_entry_types`)
- Test: `backend/tests/Unit/Loyalty/LedgerTest.php` (`$wpdb` simulado como en `ConsentLogTest`)

**Interfaces:**
- Produce: `HWE_Loyalty_Ledger::credit(int $userId, int $points, string $type, string $key, array $ref = [], ?int $expiryDays = null, array $data = []): ?int`
  (id del movimiento; `null` si la clave ya existía — idempotente);
  `::debit(int $userId, int $points, string $type, string $key, array $ref = [], array $data = []): ?int`
  (`null` si saldo insuficiente o clave repetida; nunca deja saldo negativo; consumo FIFO);
  `::reverseDebit(string $debitKey, string $refundKey): ?int` (reabona lo consumido con la
  caducidad de §5.3); `::expireDue(int $batch = 200): int`; `::balance(int $userId): array`
  (`balance`, `earned`, `used`); `::expiringSoon(int $userId, int $days = 30): array`
  (`points`, `date`); `::history(int $userId, int $page, int $perPage): array` (`items`, `total`).
  Acciones `hwe_loyalty_credited`, `hwe_loyalty_debited`, `hwe_loyalty_expired`.

- [ ] Tests: abono crea fila y suma saldo; misma clave dos veces = un solo abono; cargo mayor que el
      saldo devuelve `null` y no escribe; el cargo consume primero el abono que caduca antes y deja
      al final los que no caducan; `reverseDebit` devuelve exactamente lo consumido con la caducidad
      más lejana (o sin caducidad si alguno no caducaba) y es idempotente; `expireDue` genera un
      `expire` por abono vencido con remanente y no repite; `expiry_days = 0` ⇒ sin caducidad; las
      operaciones se hacen en transacción con bloqueo de la fila de saldo (el test comprueba el
      `FOR UPDATE` y el `ROLLBACK` ante fallo de escritura). Verlos fallar.
- [ ] Implementar esquema y libro. Las descripciones no se guardan como texto con la moneda: tipo +
      `data` estructurada (§5.1).
- [ ] `composer test` en verde.
- [ ] Commit `feat(loyalty): libro mayor transaccional con consumo FIFO y caducidad`.

### Tarea 4: Tareas programadas

**Archivos:**
- Crear: `includes/Scheduler.php` (evento diario `hwe_loyalty_daily`: caducidad por lotes y
  cumpleaños; se des-programa al desactivar el plugin)
- Test: `backend/tests/Unit/Loyalty/SchedulerTest.php`

**Interfaces:**
- Consume: `HWE_Loyalty_Ledger::expireDue`.
- Produce: acción `hwe_loyalty_daily` (la Tarea 7 cuelga de ella el cumpleaños).

- [ ] Tests: activar programa un único evento diario; desactivar lo quita; el evento procesa la
      caducidad en lotes hasta agotar o hasta un tope por ejecución; con «Puntos» apagado no hace
      nada. Verlos fallar.
- [ ] Implementar. Nada de bucles sobre todos los usuarios en `plugins_loaded`.
- [ ] `composer test` en verde. Commit `feat(loyalty): tarea diaria de caducidad`.

### Tarea 5: Referidos — códigos, atribución y confirmación

**Archivos:**
- Crear: `includes/Referrals.php` (`HWE_Loyalty_Referrals`), `includes/Rest/ReferralRoutes.php`
  (rutas internas `POST hwe/v1/loyalty/attribute`, `GET hwe/v1/loyalty/validate-code`,
  `GET hwe/v1/loyalty/referral-code`)
- Modificar: `backend/wp-content/mu-plugins/hwe-auth/Account.php` (disparar
  `do_action('hwe_email_verified', $userId)` justo al marcar `hwe_email_verified`, una sola vez)
- Test: `backend/tests/Unit/Loyalty/ReferralsTest.php`, y un caso nuevo en
  `backend/tests/Unit/HweAuth/` para la acción

**Interfaces:**
- Produce: `HWE_Loyalty_Referrals::codeFor(int $userId): string` (crea si falta; 8 caracteres de un
  alfabeto sin ambiguos, aleatoriedad criptográfica, reintento ante colisión);
  `::userByCode(string $code): ?int`; `::attribute(int $userId, string $code): string`
  (`attributed` | `ignored:<motivo>`); `::confirmIfReady(int $userId): bool`;
  `::referrerOf(int $userId, bool $confirmedOnly = false): ?int`;
  `::network(int $userId, int $level, int $page, int $perPage): array`;
  `::setCode(int $userId, string $code): bool`; `::setReferrer(int $userId, ?int $referrerId): bool`
  (administrador). Acción `hwe_loyalty_attribution_confirmed`. Consume el contrato provisional
  `hwe_account_is_pending` / `hwe_account_approved` y `hwe_email_verified`.

- [ ] Tests: el código no contiene login ni email y es único; atribuir con código inexistente,
      propio, de no participante, con ciclo o a quien ya tiene referidor devuelve `ignored:*` sin
      escribir; la atribución nace `pending`; pasa a `confirmed` solo con email verificado y, si
      `hwe_account_is_pending` dice verdadero, tras `hwe_account_approved` (cualquier orden de
      llegada); una cuenta creada en wp-admin queda confirmada; al confirmar se abonan
      `referral_signup_l1`, `referral_signup_l2` (si nivel 2) y `registration` (si su importe > 0)
      una sola vez; referidor eliminado ⇒ no se abona a un id inexistente y la red no rompe (Foco 4);
      las rutas internas exigen el secreto y `validate-code` solo devuelve `{ valid }`. Verlos fallar.
- [ ] Implementar (y la acción en `hwe-auth`). Códigos de usuarios existentes: perezosos al primer
      uso; el lote masivo es la acción de administración de la Tarea 11.
- [ ] `composer test` en verde. Commit `feat(loyalty): códigos, atribución y confirmación de referidos`.

### Tarea 6: Ganancias por pedido y su reversión

**Archivos:**
- Crear: `includes/Earnings.php` (`HWE_Loyalty_Earnings`), `includes/ProductBonus.php` (campo de
  producto `loyalty_referrer_bonus` vía `hwe_product_fields`, entero ≥ 0, no traducible)
- Test: `backend/tests/Unit/Loyalty/EarningsTest.php` (dobles de `tests/Stubs/wc-order.php`)

**Interfaces:**
- Consume: `HWE_Loyalty_Ledger::credit/debit`, `HWE_Loyalty_Referrals::referrerOf`,
  `HWE_Loyalty_Settings`.
- Produce: `HWE_Loyalty_Earnings::processOrder(int $orderId): void` (idempotente),
  `::reverseOrder(int $orderId): void`, `::orderBase(WC_Order $order): float`,
  `::isCollected(WC_Order $order): bool`; filtros `hwe_loyalty_order_earnings` (puntos calculados
  antes de abonar) y `hwe_loyalty_collected_on_completion` (medios que esperan a `completed`, por
  defecto `cod`). Meta `_hwe_loyalty_earnings` con el estado.

- [ ] Tests: base = pagado en dinero sin envío, sin `fee_lines` de extras de envío y sin la parte
      en puntos; cashback solo si está activo y el comprador tiene cuenta; comisión N1 con % primera
      compra solo en el primer pedido cobrado del comprador y % siguientes después; fuera de
      `commission_duration_days` no hay comisión; N2 solo con nivel 2 activo; bono por producto ×
      cantidad al referidor N1; `cod` no abona en `processing` y sí en `completed`; pedido pagado
      con puntos no genera nada; `processing` + `completed` + `payment_complete` sobre el mismo
      pedido = un abono por beneficiario (Foco 2); `cancelled`/`refunded` total revierte limitado al
      saldo y anota lo no recuperado; reembolso parcial no revierte; nota privada en el pedido sin
      el nombre de la moneda escrito a mano. Verlos fallar.
- [ ] Implementar con los hooks de estado de Woo en WordPress (no en el BFF).
- [ ] `composer test` en verde. Commit `feat(loyalty): ganancias por pedido y reversión`.

### Tarea 7: Ganancias por eventos (reseñas y cumpleaños)

**Archivos:**
- Crear: `includes/Events.php` (`HWE_Loyalty_Events`)
- Modificar: `backend/wp-content/plugins/hwe-site-reviews/hwe-site-reviews.php` (acción estable
  `hwe_site_review_published( int $reviewId, int $userId, int $orderId )` al publicarse una reseña
  con autor; documentarla en su README)
- Test: `backend/tests/Unit/Loyalty/EventsTest.php` y un caso en `backend/tests/Unit/SiteReviews/`

**Interfaces:**
- Consume: `HWE_Loyalty_Ledger::credit`, acción `hwe_loyalty_daily`, meta de usuario `hwe_birthday`.

- [ ] Tests: una reseña de producto aprobada con autor abona `events.review` una vez **por pedido**
      aunque se publique en varios productos; una pendiente abona al aprobarse; reseña de invitado
      no abona; la reseña del sitio abona con la misma clave por pedido; cumpleaños abona una vez por
      año natural a quien tiene `hwe_birthday` = hoy en la zona del sitio (acepta `MM-DD` y
      `AAAA-MM-DD`); importe 0 = evento apagado. Verlos fallar.
- [ ] Implementar.
- [ ] `composer test` en verde. Commit `feat(loyalty): puntos por reseñas y cumpleaños`.

### Tarea 8: Canje en el carrito y en el pedido (Store API)

**Archivos:**
- Crear: `includes/Redemption.php` (`HWE_Loyalty_Redemption`): registro de la extensión `hwe-loyalty`
  (update callback + datos del carrito, patrón `hwe-customer-identity` / `hwe-shipping`), fee
  negativo en `woocommerce_cart_calculate_fees`, confirmación en la creación del pedido de la Store
  API, devolución en `cancelled`/`failed`/`refunded`, marca de pago completo
- Test: `backend/tests/Unit/Loyalty/RedemptionTest.php` (dobles `tests/Stubs/wc-cart.php`)

**Interfaces:**
- Consume: `HWE_Loyalty_Ledger::balance/debit/reverseDebit`, `HWE_Loyalty_Settings`.
- Produce: update callback del namespace `hwe-loyalty` con datos `{ points: int }` (0 = quitar);
  `extensions.hwe-loyalty` del carrito con `available` (bool), `reason`
  (`guest` | `not_participant` | `disabled` | `below_minimum` | `cart_below_cash_minimum` | `null`),
  `balance`, `points_applied`, `discount` (unidades menores, string), `max_redeemable`, `min_redeem`,
  `full_payment_available`; metas de pedido `_hwe_loyalty_redeemed_points`, `_hwe_loyalty_discount`,
  `_hwe_loyalty_paid_with_points` (`yes` si el total quedó en 0 por puntos).

- [ ] Tests: los puntos pedidos se recortan a saldo, `max_redeem_per_order` y tope del carrito; con
      pago completo apagado el descuento no cubre envío ni deja el total por debajo de
      `min_cash_due` (también con envío 0) y un carrito por debajo de ese mínimo no admite canje;
      con pago completo encendido puede llegar a 0; por debajo de `min_redeem` no aplica; invitado y
      no participante ⇒ `available: false` con su `reason`; cambiar `point_value`, apagar «Puntos» o
      bajar el máximo con un canje aplicado ⇒ el recálculo recorta o quita el fee (Foco 3); al crear
      el pedido se recalcula y se descuenta con clave `redeem:order:<id>`; si el saldo ya no alcanza,
      el checkout lanza un error de la Store API con motivo claro y no crea el pedido (Foco 1);
      `cancelled`/`failed`/`refunded` devuelven una sola vez; total 0 por puntos marca
      `_hwe_loyalty_paid_with_points`. Verlos fallar.
- [ ] Implementar. Un único camino de descuento; el navegador nunca envía importes.
- [ ] `composer test` en verde. Commit `feat(loyalty): canje de puntos en la Store API`.

### Tarea 9: Transferencias

**Archivos:**
- Crear: `includes/Transfers.php` (`HWE_Loyalty_Transfers`), ruta interna
  `POST hwe/v1/loyalty/transfer` (en `includes/Rest/` junto a las demás)
- Test: `backend/tests/Unit/Loyalty/TransfersTest.php`

**Interfaces:**
- Consume: `HWE_Loyalty_Ledger`, `HWE_Loyalty_Referrals::userByCode`.
- Produce: `HWE_Loyalty_Transfers::transfer(int $fromUserId, string $recipientCode, int $points, string $idempotencyKey, string $note = ''): array`
  (`ok`, `sent`, `received`, `fee`, `recipient_name` | `error` con código estable:
  `disabled`, `below_minimum`, `insufficient`, `invalid_recipient`, `self`, `daily_cap`);
  acción `hwe_loyalty_transferred`.

- [ ] Tests: apagado ⇒ `disabled`; por debajo de `transfer_min`; a uno mismo; código inexistente o
      de no participante; saldo insuficiente; tope diario; comisión redondeada hacia arriba; débito y
      abono en la misma transacción (fallo del abono ⇒ nada escrito); misma clave ⇒ misma respuesta
      sin mover puntos; la respuesta nunca incluye email ni login. Verlos fallar.
- [ ] Implementar. `composer test` en verde. Commit `feat(loyalty): transferencias entre usuarios`.

### Tarea 10: Rutas internas de lectura

**Archivos:**
- Crear: `includes/Rest/ReadRoutes.php` (`GET hwe/v1/loyalty/summary`, `/transactions`,
  `/referrals`; todas con `user_id` del BFF y el secreto interno)
- Test: `backend/tests/Unit/Loyalty/ReadRoutesTest.php`

**Interfaces:**
- Produce: `summary` → `{ participant, balance, earned, used, expiring: { points, date } }`;
  `transactions?page&per_page` (tope 100) → `{ items: [{ id, type, points, data, created_at,
  expires_at }], total, pages }`; `referrals?level=1|2&page&per_page` → `{ items: [{ name, date,
  status, points_generated }], totals: { level1, level2, pending, earned } }` (nombre visible, nunca
  email ni login).

- [ ] Tests: sin secreto ⇒ cerrado; módulo o interruptor apagado ⇒ 404; `per_page` recortado; red
      sin emails; usuario sin participación ⇒ `participant: false` y ceros. Verlos fallar.
- [ ] Implementar. Decidir aquí dónde se compone el texto de un movimiento (spec §9.4) y anotarlo en
      el README; si se compone en el BFF, `data` debe bastar.
- [ ] `composer test` en verde. Commit `feat(loyalty): rutas internas de lectura`.

### Tarea 11: Administración en wp-admin

**Archivos:**
- Crear: `includes/Admin/*` (menú «Fidelización»: resumen, movimientos con filtros y CSV, red,
  ajuste manual, acción por lotes de códigos; campos del perfil; columnas ordenables del listado de
  usuarios; caja en el pedido); capacidades `hwe_loyalty_view` / `hwe_loyalty_manage` concedidas a
  `administrator` y `shop_manager` al activar; assets en `assets/` si hacen falta
- Test: `backend/tests/Unit/Loyalty/AdminTest.php`

**Interfaces:**
- Consume: Ledger, Referrals, Settings.

- [ ] Tests: sin `hwe_loyalty_manage` no se puede ajustar saldo, código ni referidor; el ajuste
      manual exige nota y nonce, y el cargo se limita al saldo; editar el perfil nunca sobrescribe el
      saldo (abono/cargo explícito); cambiar el referidor respeta ciclos y autorreferido; la acción
      por lotes asigna códigos solo a quien no tiene y es reanudable; el CSV escapa celdas que
      empiezan por `=`, `+`, `-`, `@`. Verlos fallar.
- [ ] Implementar con escapado de salida en todas las vistas.
- [ ] `composer test` en verde. Commit `feat(loyalty): administración en wp-admin`.

### Tarea 12: Privacidad

**Archivos:**
- Crear: `includes/Privacy.php` (exportador y eliminador de datos personales, patrón
  `hwe-consent-log/includes/Privacy.php`); manejar también `delete_user`
- Test: `backend/tests/Unit/Loyalty/PrivacyTest.php`

- [ ] Tests: el exportador devuelve saldo, código, referidor y movimientos; el eliminador anonimiza
      movimientos (conserva importes) y desvincula la red; tras eliminar un referidor, sus referidos
      quedan sin referidor y futuras compras no generan comisión para ese id (Foco 4). Verlos fallar.
- [ ] Implementar. `composer test` en verde. Commit `feat(loyalty): exportación y supresión de datos`.

### Tarea 13: `lib/loyalty` (contrato y helpers)

**Archivos:**
- Crear: `frontend/src/lib/loyalty/types.ts`, `format.ts`, `cart.ts`, `describe.ts` (si la Tarea 10
  decidió componer en el BFF), `server.ts` (server-only: lectores vía `wpFetch`), `guard.ts`
  (server-only), y sus tests colocados; `frontend/src/lib/client/loyalty.ts` (cliente del navegador
  con CSRF de `lib/client/csrf`)

**Interfaces:**
- Produce: tipos `LoyaltyEntryType`, `LoyaltySummary`, `LoyaltyTransactionPage`,
  `LoyaltyReferralPage`, `LoyaltyCartExtension`, `LoyaltyTransferResult`; puros
  `pointsToMoney(points, config): number`, `formatPoints(points, config): { text, icon }` (singular/
  plural), `maxRedeemable(input, config): number` (espejo de §7.2, solo para mostrar),
  `parseLoyaltyCartExtension(cart): LoyaltyCartExtension | null`; servidor
  `getLoyaltySummary(userId)`, `getLoyaltyTransactions(userId, { page, perPage })`,
  `getLoyaltyReferrals(userId, { level, page, perPage })`, `getReferralLink(userId, config)`
  (URL con `config.brand.url`), `validateReferralCode(code)`, `attributeReferral(userId, code)`,
  `transferPoints(userId, input, idempotencyKey)`; guarda
  `requireLoyalty(config, feature: "points" | "referrals" | "transfers"): NextResponse | null`.

- [ ] Tests colocados: conversión y formato (singular/plural, sin icono); `maxRedeemable` con los
      mismos casos de tope que la Tarea 8; `parseLoyaltyCartExtension` tolera la extensión ausente
      o malformada; `requireLoyalty` responde 404 con módulo o interruptor apagado; un fallo de
      WordPress en un lector registra `logReadFailure` y lanza un error tipado (no un saldo 0).
      Verlos fallar.
- [ ] Implementar. Ningún import de `@/components/**` ni `@/instance/**` (regla de lint del núcleo).
- [ ] Verificación frontend + `npx next build`. Commit `feat(loyalty): contrato y helpers en lib/loyalty`.

### Tarea 14: Rutas BFF `/api/loyalty/*`

**Archivos:**
- Crear: `frontend/src/app/api/loyalty/{summary,transactions,referrals,referral-link,validate-code,transfer}/route.ts`
  con tests colocados

**Interfaces:**
- Consume: todo lo de la Tarea 13. Contrato de la tabla de spec §9.2.

- [ ] Tests: cada ruta 404 con el módulo o su interruptor apagado; sin sesión 401 salvo
      `validate-code`; `user_id` sale de la sesión aunque el body/query traiga otro; `validate-code`
      con rate-limit por IP y respuesta `{ valid }`; `transfer` pasa por `guardMutation` estricto y
      exige `Idempotency-Key` válida (repetir devuelve el mismo resultado); `transactions` recorta
      `per_page`; `referral-link` usa `config.brand.url`. Verlos fallar.
- [ ] Implementar con Zod. El test que impide `fetch` directo a WordPress
      (`lib/security/wordpress-calls.test.ts`) debe seguir en verde.
- [ ] Verificación frontend + `npx next build`. Commit `feat(loyalty): rutas BFF del monedero y referidos`.

### Tarea 15: Canje desde el BFF y checkout en total 0

**Archivos:**
- Crear: `frontend/src/app/api/store/cart/points/route.ts` (POST/DELETE) con test
- Modificar: `frontend/src/app/api/store/checkout/route.ts`, `frontend/src/lib/payments/checkout-options.ts`
  (`enabledPaymentChoices` recibe también los módulos), `frontend/src/lib/validation/store.ts` si hace
  falta, y sus tests

**Interfaces:**
- Consume: `parseLoyaltyCartExtension`, `requireLoyalty`, extensión `hwe-loyalty` (Tarea 8).
- Produce: respuesta del checkout con `paid_with_points: true` cuando el pedido quedó pagado con
  puntos.

- [ ] Tests: `POST /api/store/cart/points` con `{ points }` llama al update callback de la extensión
      con el `Cart-Token` y el JWT y devuelve el carrito; `DELETE` envía 0; ambos con
      `guardMutation` y 404 si «Puntos» apagado; el checkout con un pedido en total 0 por puntos no
      valida ni sustituye el medio de pago, no crea pago ni pasa a «En espera por link» y responde
      `paid_with_points`; pedir pago completo con puntos y `shipping_method_ids` con extras responde
      422 con motivo; con el módulo apagado el checkout se comporta como hoy. Verlos fallar.
- [ ] Implementar sin romper el flujo de pagos existente (sus tests siguen en verde).
- [ ] Verificación frontend + `npx next build`. Commit `feat(loyalty): canje desde el BFF y checkout pagado con puntos`.

### Tarea 16: Captura de `?ref=` y registro con código

**Archivos:**
- Modificar: `frontend/src/proxy.ts` (cookie `hwe_ref`), `frontend/src/app/api/auth/register/route.ts`,
  `frontend/src/lib/validation/auth.ts` (`referral_code` opcional); módulo apto para edge con las
  opciones de la cookie sin duplicar la política de `lib/security/cookies.ts` (que es server-only);
  tests correspondientes

**Interfaces:**
- Consume: `attributeReferral(userId, code)` (Tarea 13).

- [ ] Tests: una página con `?ref=<código con formato válido>` recibe `hwe_ref` httpOnly, 30 días,
      `SameSite=Lax`, `Secure` en producción; un formato inválido no deja cookie; el último código
      reemplaza al anterior; la URL no cambia; el registro usa `referral_code` del body o, si falta,
      la cookie, llama a la atribución tras crear la cuenta, borra la cookie, y un fallo de la
      atribución se registra sin romper el registro; con el módulo o «Referidos» apagado ignora
      ambos. Verlos fallar.
- [ ] Implementar.
- [ ] Verificación frontend + `npx next build`. Commit `feat(loyalty): captura de ?ref= y registro con código`.

### Tarea 17: CI, verificación en vivo y documentación

**Archivos:**
- Modificar: `.github/scripts/stub-graphql.mjs` y/o el `STUB_SITE_CONFIG_EXTRA` del workflow
  (`config.loyalty` + módulo), `frontend/tests/e2e/modules-off.spec.ts` (rutas `/api/loyalty/*` y
  `/api/store/cart/points` responden 404 con el módulo apagado), `backend/scripts/verify-modules.sh`
  (toggle de `loyalty`: tablas, rutas internas, extensión de la Store API)
- Docs: `backend/wp-content/plugins/hwe-loyalty/README.md` y `MANUAL.md` completos;
  `docs/CONFIGURATION.md`, `docs/FRONTEND_CONNECT.md` Parte A, `docs/PLUGIN_DEVELOPMENT.md` (grupo
  reservado `loyalty`, hooks estables nuevos: `hwe_email_verified`, `hwe_site_review_published`,
  contrato provisional de login privado, hooks `hwe_loyalty_*`), `AGENTS.md` (10 módulos, §6.9,
  tabla de estado), `CLAUDE.md` (lista de módulos), `README` de `hwe-site-reviews`, `CHANGELOG.md`
  (`[Sin publicar]`)

- [ ] Ampliar stub, e2e sin módulos y `verify-modules.sh`; ejecutar `npm run e2e` contra el build con
      el stub y la pasada sin módulos.
- [ ] Escribir la documentación. Revisar que «nueve módulos» no quede en ningún sitio.
- [ ] Verificación completa: `composer test`; `npm run lint`, `type-check`, `format:check`,
      `i18n:check`, `test`, `test:coverage` (umbrales sin bajar), `audit:gate`; `npx next build`;
      `bash .github/scripts/check-anti-instance.sh`.
- [ ] Si la pila está disponible: `sh backend/scripts/verify-modules.sh all` y una prueba manual de
      canje + pedido cobrado → abono; si no, declararlo como no verificado en vivo.
- [ ] Commit `docs(loyalty): documentación, stub de CI y verificación del módulo`.

### Tarea 18: Revisión de extremo a extremo del módulo

Revisión de solo lectura del plugin y de todos sus flujos, una vez terminada la Tarea 17. **No la
ejecuta un worker de opencode**: la hace un subagente de Claude (Opus, esfuerzo medio) lanzado por
el coordinador.

**Alcance:**
- `hwe-loyalty` completo.
- Sus enganches en el núcleo (`Modules.php` y el grupo reservado `loyalty`) y en
  `hwe-site-reviews` (`hwe_site_review_published`).
- El BFF y `lib/loyalty` (Tareas 13–16).

**Flujos:** ganar (pedido, eventos, referidos), revertir, canjear (carrito → checkout → devolución),
transferir, caducar, administración, privacidad y contrato con el frontend.

**Qué busca:**
- Bugs.
- Incoherencias entre capas o con el spec.
- Algoritmos o consultas por optimizar.
- Refactorizaciones con beneficio claro.
- Huecos de seguridad o de concurrencia.

**Qué reporta:** solo hallazgos de severidad **media, alta o grave**, cada uno con:
- `archivo:línea`;
- escenario concreto de fallo;
- impacto;
- arreglo propuesto.

Nada cosmético ni de estilo.

- [ ] El coordinador valida cada hallazgo, lo reproduce con un test en rojo cuando proceda,
      corrige y commitea por hallazgo; lo que no se arregle queda anotado con su motivo.
