# El cashback y las comisiones por porcentaje respetan el valor de la unidad

Fecha: 2026-10-26 · Rama: `fix/loyalty-point-value` (base `v2.2.0`) · Núcleo del framework
(no instancia).

## Qué se cambió y por qué

`HWE_Loyalty_Earnings::calculate()` convertía un porcentaje de la base (dinero) directamente en
unidades: `floor( $base * $percent / 100 )`. El resto del módulo trata las unidades como algo que
vale `point_value` en la moneda de la tienda (canje en `Redemption.php`, valor del saldo en
`frontend/src/lib/loyalty/format.ts`). Por tanto, el porcentaje devuelto **en valor** dependía de
`point_value`: con el `0.01` por defecto, un cashback del 3 % pagaba el 0,03 % del valor de la
compra; con `point_value = 10`, el 30 %. La etiqueta del Control Center ya prometía «porcentaje de
la base de compra» — valor, no unidades.

La corrección aplica el porcentaje al valor y convierte el importe a unidades con una única
función:

```php
HWE_Loyalty_Earnings::toUnits( float $amount, array $settings ): int
// point_value > 0: (int) floor( round( $amount / $point_value, 6 ) )
// point_value <= 0: 1 unidad = 1 de la moneda (comportamiento anterior) + aviso una sola vez
```

El `round(…, 6)` antes del `floor` evita que la aritmética binaria reste una unidad en un cociente
exacto (`59 900 × 3 % / 10 = 179.7 → 179`; `0.1 / 0.01` no es `10` exacto en coma flotante). La
rama `point_value <= 0` conserva el cálculo previo y registra con `self::log()` una única vez por
proceso (`point_value ≤ 0: se convierte 1 unidad = 1 de la moneda…`).

### Dónde se aplicó

| Punto | Antes | Ahora |
|---|---|---|
| Cashback (`calculate()`) | `floor( $base * $percent / 100 )` | `self::toUnits( $base * $percent / 100, $settings )` |
| Comisión N1 (`calculate()`) | `floor( $base * $percent / 100 )` | `self::toUnits( $base * $percent / 100, $settings )` |
| Comisión N2 (`calculate()`) | `floor( $base * $percent / 100 )` | `self::toUnits( $base * $percent / 100, $settings )` |
| Bono por producto | `bonusFor( $productId ) * $quantity` | **sin cambio**: son unidades fijas |
| Eventos (`Events.php`) | importe configurado en unidades | **sin cambio**: no lee `point_value` |
| Primas de alta (`Referrals.php`) | importe configurado en unidades | **sin cambio**: no lee `point_value` |

### Datos existentes

No se migra el libro: los abonos ya hechos se quedan como están (una migración retroactiva
cambiaría saldos ya gastados y no hay forma de reconstruir el valor con el que se abonaron). La
corrección se aplica a los pedidos procesados a partir de la actualización. Documentado en el
CHANGELOG como cambio de importes para las instancias con `point_value != 1`.

## Auditoría: todas las conversiones dinero → unidades

`git grep -n -i "percent" backend/wp-content/plugins/hwe-loyalty frontend/src/lib/loyalty` y
`git grep -n "point_value\|/ \$value"` sobre las mismas rutas.

| Sitio | Conversión | Decisión |
|---|---|---|
| `Earnings::calculate()` (cashback, N1, N2) | porcentaje de la base → unidades | **Corregido** con `toUnits()`. Era el bug. |
| `Redemption::quote()` — `maxByCash` | `floor( $total / $value + 1e-9 )` | **Sin cambio, ya alineado**: divide por `point_value`; el canje es un tope de pago, no una ganancia. Su épsilon `1e-9` solo absorbe el error binario del mismo modo. |
| `Redemption::quote()` — `maxByPercent` | `floor( $percentBase * $percent / 100 / $value + 1e-9 )` | **Sin cambio, ya alineado**: primero calcula el valor del porcentaje y después divide por `point_value`, exactamente la regla nueva. |
| `Redemption::pointsToCover()` / `pointsForDiscount()` / `quote()` (`pointsByDiscount`) | `floor( $amount / $value + 1e-9 )` | **Sin cambio, ya alineado**: mismo contrato. No se unifican con `toUnits()` para no tocar la ruta de dinero (fee del carrito y confirmación del pedido) en un fix de ganancias; su variante con épsilon es equivalente para los decimales de la moneda. |
| `Transfers::feeFor()` | porcentaje de **unidades** transferidas (no de dinero) | **No aplica**: la comisión se cobra en unidades sobre un importe que ya está en unidades; no hay dinero que convertir. |
| Frontend `lib/loyalty/cart.ts` — `maxRedeemable()` | `Math.floor( total / value + EPSILON )` y `(percentBase * percent) / 100 / value` | **Sin cambio, ya alineado**: réplica de `Redemption::quote()` para pintar el control; nunca decide un importe. |
| Frontend `lib/loyalty/format.ts` — `pointsToMoney()` | unidades → dinero (`points * point_value`) | **No aplica**: es la conversión inversa, de presentación. |
| Frontend (BFF) | — | **No existe cálculo de ganancias en TS**: los puntos los abona WordPress en el cobro; el frontend solo lee el saldo y el valor. No hay regla duplicada que corregir. |
| `max_redeem_percent` (Control Center / README / frontend) | porcentaje de valor convertido a unidades | **Ya estaba alineado**: se calcula en valor y se divide por `point_value`. |

## Tests (rojo primero)

`backend/tests/Unit/Loyalty/EarningsTest.php`:

- El fixture pasa a `point_value = '1'` (1 unidad = 1 de la moneda) para que los casos de
  porcentajes, ventanas y reversión sigan midiendo la decisión sin el factor de conversión; ese
  valor es, además, el caso «igual que antes».
- Nuevos:
  - `point_value = 10`, base 59 900, 3 % → **179** unidades;
  - `point_value = 0.01` (defecto), base 100, 3 % → **300** unidades (valor 3);
  - `point_value = 1`, base 100, 10 % → **10** unidades (comportamiento anterior);
  - `point_value = 0`, base 100, 3 % → **3** unidades por pedido (el anterior) y **un solo**
    aviso que menciona `point_value` y «1 unidad = 1 de la moneda»;
  - comisión N1 y N2 con `point_value = 10`, base 59 900: **599** y **179** unidades;
  - bono por producto con `point_value = 10`: **10** unidades (5 × 2), sin conversión.

**Evidencia de rojo** (antes de tocar `Earnings.php`, con los tests nuevos y el fixture en `'1'`):

```
$ COMPOSER_PROCESS_TIMEOUT=0 php /tmp/opencode/tools/composer.phar test -- --filter 'EarningsTest'
41 / 41 (100%)
There were 4 failures:
1) test_cashback_percent_is_a_value_converted_to_units_with_the_point_value
   Failed asserting that 1797 is identical to 179.
2) test_the_default_point_value_turns_the_percentage_into_its_value_in_units
   Failed asserting that 3 is identical to 300.
3) test_zero_point_value_keeps_one_unit_per_currency_and_logs_it_once
   Failed asserting that actual size 0 matches expected size 1.
4) test_level_one_and_two_percentage_commissions_are_converted_with_the_point_value
   Failed asserting that 5990 is identical to 599.
Tests: 41, Assertions: 182, Failures: 4.
```

Tras implementar `toUnits()` y aplicarla: `OK (41 tests, 184 assertions)`.

## Verificación ejecutada

### Backend

```
$ COMPOSER_PROCESS_TIMEOUT=0 php /tmp/opencode/tools/composer.phar test
OK (2166 tests, 15485 assertions)
Time: 02:20.369, Memory: 88.00 MB
```

Solo el módulo: `php … test -- tests/Unit/Loyalty` → `OK (304 tests, 1994 assertions)`.

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

$ STUB_GRAPHQL_PORT=18161 node ../.github/scripts/stub-graphql.mjs &
stub GraphQL escuchando en http://127.0.0.1:18161
$ WORDPRESS_INTERNAL_API_URL=http://127.0.0.1:18161/graphql npx next build
BUILD_EXIT=0
(compilación y prerender completos; el stub se mató por su PID)
```

### Raíz

```
$ bash .github/scripts/check-anti-instance.sh
Puerta anti-instancia: 0 coincidencias en el repositorio.
```

## Docs

- `backend/wp-content/plugins/hwe-loyalty/README.md`: fila de `point_value`, filas de cashback y
  comisiones, y viñeta «Conversión dinero → unidades» con la fórmula y el ejemplo 59 900 · 3 % ·
  10 = 179.
- `Settings.php`: descripciones de `point_value`, `cashback_percent`,
  `commission_first_percent`, `commission_next_percent` y `commission_level2_percent` («en valor:
  se convierte a unidades con el valor de la unidad»).
- `docs/FRONTEND_CONNECT.md`: el contrato de `loyalty.*` aclara que los porcentajes son valor y
  que el frontend no calcula ganancias.
- `CHANGELOG.md` `[Sin publicar]` → «Fixed», con la nota de importes para `point_value != 1` y la
  ausencia de migración del libro.

## Pendiente / no verificado en vivo

No se levantó la pila Docker con WordPress real: el cálculo se ha probado con los dobles de
PHPUnit, no contra WooCommerce en ejecución. El efecto en una instancia con `point_value != 1`
(por ejemplo `10`) es un cambio de importes en los abonos futuros; conviene avisar en las notas de
actualización de la instancia. Queda para el coordinador la revisión del diff y el commit; no se
ejecutó ningún comando de git de escritura.
