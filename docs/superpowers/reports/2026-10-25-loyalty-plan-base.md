# Los planes de membresía dejan de generar puntos de fidelización

Fecha: 2026-10-25 · Rama: `fix/loyalty-plan-base` (base `v2.2.0`) · Núcleo del framework
(no instancia).

## Qué se cambió y por qué

`HWE_Loyalty_Earnings::orderBase()` sumaba **todas** las líneas del pedido. Con `memberships`
activo, comprar un plan generaba cashback, comisiones de referido y bono por producto sobre el
precio del plan, mientras que el propio módulo ya lo excluía del descuento de socio
(`HWE_Memberships_Pricing`) y el plan tiene su propio beneficio de puntos de activación.

La corrección no acopla `hwe-loyalty` a `hwe-memberships`: añade un punto de extensión en
`loyalty` y es `memberships` quien lo consume.

### `hwe-loyalty` (núcleo del módulo)

- Nuevo filtro público `HWE_Loyalty_Earnings::FILTER_LINE_EARNS` =
  `hwe_loyalty_line_earns`, con firma
  `( bool $earns, WC_Order_Item_Product $item, WC_Order $order ): bool`.
- Se aplica dentro de `orderBase()` **y** en el bucle del bono por producto de
  `calculate()`: una línea que no cuenta para la base tampoco paga bono. Así no queda una vía
  lateral para que un plan con `loyalty_referrer_bonus` configurado genere puntos.
- El valor por defecto es `true`. Un valor no booleano se registra con `self::log()` y se trata
  como `true`, con el mismo patrón que `hwe_loyalty_collected_on_completion`; un filtro mal
  escrito no puede dejar al comprador sin ganancias.
- `loyalty` no menciona membresías ni conoce sus clases.

### `hwe-memberships` (consumidor del filtro)

- `HWE_Memberships_LoyaltyBridge::register()` engancha `hwe_loyalty_line_earns` solo cuando
  `loyalty` está cargado (mismas guardas que ya usaba para `hwe_loyalty_entry_types`) y la
  función `points` o `referrals` está disponible.
- `HWE_Memberships_LoyaltyBridge::lineEarns()` devuelve `false` para un producto de membresía
  o una variación suya. Respeta un `false` de un filtro anterior y devuelve intacto un valor no
  booleano para que `loyalty` lo registre.
- La lógica «producto o padre es membresía» se subió a un método público compartido,
  `HWE_Memberships_Products::isMembershipLine()`. `Pricing` lo reutiliza (se eliminó su copia
  privada) y el puente con `loyalty` también: no hay dos implementaciones.
- Ajuste nuevo del Control Center `memberships.plans_earn_points` («Los planes generan puntos de
  fidelización»), booleano, **por defecto `false`**, no público (es cálculo de backend). Con
  `true` el filtro no excluye nada y el plan vuelve a puntuar como antes.
- El ajuste se lee con `HWE_Memberships_Settings::get()`; si falla, se falla cerrado (no puntúa).

### Interacción con el canje

`META_DISCOUNT` sigue restándose de la base total con suelo 0. Si un pedido mezcla un producto
normal y un plan y se pagó parte con puntos, el descuento global reduce la base de las líneas que
sí puntúan. No se puede repartir por línea sin persistir el reparto y el sentido del error es
conservador (nunca paga de más); se documenta como comportamiento conocido.

## Auditoría del punto 4, caso por caso

| Consumidor de la base / carrito | Decisión | Motivo |
|---|---|---|
| `Earnings::orderBase()` (base de todo) | **Cambiado** | Es el origen: aplica `hwe_loyalty_line_earns` por línea. |
| `Earnings::calculate()` → cashback | **Cubierto** | Usa `$base`; con la base sin planes no hay cashback del plan. |
| `Earnings::calculate()` → comisión N1/N2 | **Cubierto** | Ambas usan `$base`; el porcentaje se aplica a la base sin planes. |
| `Earnings::calculate()` → bono por producto | **Cambiado** | Iteraba `line_item` directamente; ahora aplica el mismo filtro. Sin esto, un plan con bono configurado seguiría pagando. |
| `HWE_Loyalty_Referrals` (puntos de alta, red y totales) | **Sin cambio** | Los puntos de alta son importes fijos por registro, no dependen de la base ni de líneas. Los totales de red suman movimientos del libro ya filtrados. |
| Mínimo de pedido dependiente de la base | **No existe** | `loyalty` no tiene un mínimo por base. `min_cash_due` es el mínimo que debe quedar por pagar en **canje**, no un umbral de ganancias; no se toca. |
| `Redemption::quote()` / `max_redeem_percent` | **Sin cambio, justificado** | El canje es un **medio de pago**, no una ganancia. Un cliente puede pagar (total o parte de) un plan con puntos: eso no crea puntos de la nada y es coherente con que el plan tenga precio. Excluirlo obligaría a que `loyalty` conociera planes o a inventar un segundo filtro de canje sin una necesidad de negocio. El tope por porcentaje sigue calculándose sobre «la base sin envío» (productos + fees), igual que antes. |
| Frontend: `lib/loyalty/cart.ts` `maxRedeemable` | **Sin cambio** | Replica `Redemption::quote()`, que no cambia. Si se hubiera tocado el canje habría que tocarlo; no es el caso. |
| Frontend: estimaciones de puntos ganados | **No existen** | `git grep -n -i "cashback|max_redeemable|earn" frontend/src/lib frontend/src/components` solo devuelve configuración, tipos y el espejo del canje. Los puntos los abona WordPress; el BFF no calcula cashback. No se duplica la regla en TS. |
| Config pública del frontend | **Sin cambio** | `plans_earn_points` es operativo y no se publica; `MembershipsConfig` no necesita el campo. |

## Tests (rojo primero)

Nuevo `backend/tests/Unit/Memberships/LoyaltyPlanEarningsTest.php` (integración real del puente
con `HWE_Loyalty_Earnings::orderBase()` y un registro de hooks en memoria):

- pedido con producto normal + plan: base 100 (solo el normal);
- pedido solo con plan: base 0;
- variación de un producto de membresía (vínculo resuelto por el padre): no cuenta;
- `plans_earn_points = true`: base 150 (el plan vuelve a contar);
- un filtro anterior que excluye el producto normal no lo reabre el puente.

Ampliaciones en `backend/tests/Unit/Loyalty/EarningsTest.php`:

- sin ningún módulo enganchado, todas las líneas cuentan (comportamiento anterior);
- el filtro puede excluir una línea de la base;
- un valor no booleano se registra con su tipo y cuenta como `true`;
- el filtro también excluye el bono por producto.

**Evidencia de rojo**: con `orderBase()` temporalmente devuelto a su forma anterior (sin llamar a
`lineEarns()`), los 4 tests de integración que dependen de la exclusión fallan con los valores
antiguos (`150.0` en vez de `100.0`, `50.0` en vez de `0.0`, `130.0` en vez de `100.0`, `100.0` en
vez de `0.0`). Restaurado el cambio, pasan. El test del ajuste en `true` y el de «respeta un
filtro anterior» son los dos que no dependen de esa llamada, como corresponde.

`SettingsTest` se actualizó para el campo nuevo (orden, tipo, default `false`, lectura saneada y
que no sale por la config pública).

## Verificación ejecutada (salidas reales)

### Backend

```
$ COMPOSER_PROCESS_TIMEOUT=0 php /tmp/opencode/tools/composer.phar test
...
OK (2160 tests, 15470 assertions)
Time: 02:47.109, Memory: 88.00 MB
```

### Frontend (`cd frontend`)

```
$ npm run lint
> eslint .
(sin errores)

$ npm run type-check
> tsc --noEmit
(sin errores)

$ npm run format:check
All matched files use Prettier code style!

$ npm run i18n:check
i18n OK — 605 hojas, misma forma, mismos argumentos y etiquetas, y toda etiqueta localizada tiene handler.

$ npm run test
Test Files  339 passed (339)
      Tests  3282 passed (3282)

$ npm run test:coverage
Statements   : 89.08% ( 5199/5836 )
Branches     : 82.06% ( 4110/5008 )
Functions    : 91.25% ( 1116/1223 )
Lines        : 90.43% ( 4565/5048 )
(sin fallo de umbrales)

$ STUB_GRAPHQL_PORT=18160 node ../.github/scripts/stub-graphql.mjs &
stub GraphQL escuchando en http://127.0.0.1:18160
$ WORDPRESS_INTERNAL_API_URL=http://127.0.0.1:18160/graphql npx next build
BUILD_EXIT=0
(compilación y prerender completos; el stub se mató por su PID 303781)
```

### Raíz

```
$ bash .github/scripts/check-anti-instance.sh
Puerta anti-instancia: 0 coincidencias en el repositorio.
```

## Docs y contrato

- `backend/wp-content/plugins/hwe-loyalty/README.md`: base de cálculo y fila del filtro nuevo.
- `backend/wp-content/plugins/hwe-memberships/README.md`: fila del ajuste, sección «Los planes no
  generan puntos» y `hwe_loyalty_line_earns` en los hooks que consume.
- `backend/wp-content/plugins/hwe-memberships/MANUAL.md` y `docs/CONFIGURATION.md`: el ajuste en
  la lista de opciones del equipo técnico.
- `docs/FRONTEND_CONNECT.md`: nota de que el ajuste es operativo y el frontend no calcula puntos.
- `CHANGELOG.md`: entrada en `[Sin publicar]` (la sección «Unreleased» de este repo está en
  español). Es un fix compatible de parche: **no** se tocó `FRAMEWORK_VERSION` ni la versión del
  contrato (`HWE_CORE_CONTRACT_VERSION`).

## Pendiente / no verificado en vivo

No se levantó la pila Docker con WordPress real: la compra de un plan, el cobro por webhook y el
canje se han probado con dobles de PHPUnit, no contra WooCommerce en ejecución. El punto de
extensión se apoya en `apply_filters` en el momento del cobro (cuando ambos plugins ya están
cargados), así que el orden de arranque de los plugins no afecta. Queda para el coordinador la
revisión del diff y el commit; no se ejecutó ningún comando de git de escritura.
