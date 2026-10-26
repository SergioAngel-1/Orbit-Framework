# El rate-limit de WordPress no cuenta las llamadas del BFF como si fueran de un solo cliente

Fecha: 2026-10-26 · Rama: `fix/rate-limit-bff` (base `main` v2.2.0 + el fix de planes sin puntos) ·
Núcleo del framework (no instancia).

## Qué se cambió y por qué

`backend/wp-content/mu-plugins/rate-limit.php` limita `/graphql` (60/min) y `/wp-json/` (120/min)
por IP (`hwe_get_client_ip()`), sin excepción para el BFF. En producción el BFF llama a WordPress
por la red interna **sin `X-Forwarded-For`**, así que `REMOTE_ADDR` es la IP del contenedor del
frontend: todos los compradores compartían un único presupuesto. La Store API
(`/wp-json/wc/store/…`, carrito y checkout) y las lecturas por usuario (fidelización, pedidos)
cuentan ahí, de modo que unas pocas decenas de usuarios simultáneos dejaban la tienda entera en
429 (en una instancia real: `loyalty.summary_failed … HTTP 429`, `cart.get.error`,
`measurement.purchase.error`; una sola sesión de e2e lo disparaba).

Ahora las peticiones que llegan con un `X-HWE-Internal-Secret` **válido** no consumen el
presupuesto por IP de `/graphql` ni de `/wp-json/`. La comprobación **no se duplica**: se movió a
un mu-plugin compartido, `hwe-bff-secret.php`, y la usan tanto `hwe-bff-only.php` (la puerta que
responde 404) como `rate-limit.php`:

```php
// rate-limit.php
if ( str_contains( $uri, '/graphql' ) ) {
    if ( ! hwe_rate_limit_is_bff_request() ) {
        hwe_check_rate_limit( HWE_RL_GRAPHQL );
    }
} elseif ( str_contains( $uri, '/wp-json/' ) ) {
    if ( ! hwe_rate_limit_is_bff_request() ) {
        hwe_check_rate_limit( HWE_RL_REST );
    }
}
// wp-login.php: sin cambios, se cuenta siempre.
```

`hwe_rate_limit_is_bff_request()` devuelve `false` (se cuenta como cualquier petición) si:

- no está definida `HWE_REVALIDATION_SECRET` (falla cerrado);
- no viene la cabecera;
- el valor no coincide (comparación en tiempo constante sobre SHA-256, también respecto a la
  longitud, la misma de `hwe-bff-only`);
- la comprobación lanza una excepción (`\Throwable` → `false`).

Un secreto **incorrecto** no da más margen que ninguno: cae por la misma rama que «sin cabecera».
El límite de `wp-login.php` no cambia (5 POST / 5 min) y se aplica aunque la petición traiga el
secreto válido: es tráfico humano directo, el BFF no inicia sesión ahí.

### Por qué un mu-plugin nuevo (`hwe-bff-secret.php`)

La regla que decide «¿esto es el BFF?» tiene que ser **exactamente la misma** en las dos puertas.
Si divergieran, una petición podría pasar `hwe-bff-only` y a la vez gastar el presupuesto de todos
los compradores (o quedar exenta sin ser el BFF). Duplicar la comparación haría que la próxima
rotación de secreto arreglase solo una copia. El archivo nuevo contiene la constante, la
comparación y el log con throttling, sin registrar hooks; `hwe-bff-only.php` y `rate-limit.php` lo
incluyen con `require_once`. Orden de carga: `hwe-bff-only.php` va antes alfabéticamente y es quien
lo incluye primero; el `include_once` del cargador de mu-plugins lo salta después. El prefijo del
log sigue siendo `[hwe] bff-only:` (lo citan `README.md`, `docs/INSTALL.md`, `docs/RUNBOOK.md` y
`docs/UPGRADE.md`, y lo fija un test).

## Punto 2: ¿reenviar la IP real del cliente para limitar por cliente en WordPress?

**Decisión: no en este cambio.** Justificación:

1. **El BFF ya limita por usuario/IP real en su propia frontera** (`lib/security/rate-limit.ts`,
   `lib/api/guard.ts` con `strict` en auth). La exención no deja el tráfico del BFF sin límite
   global: lo acota el BFF, que es quien conoce la identidad y la IP real
   (`lib/http/request-ip.ts`, anti-spoofing con `TRUSTED_PROXY_COUNT`). Lo que se corrige aquí es
   un presupuesto **compartido por accidente** (la IP del contenedor), no una defensa que se
   elimine.
2. **Cubrirlo en WordPress exigiría una cabecera de confianza nueva** (`X-HWE-Client-IP`), aceptada
   solo junto al secreto, y **propagar la IP por todos los clientes** del BFF: `wpFetch`,
   `graphql-client`, `store-client` y el cliente `wc/v3`. Las lecturas de GraphQL son ISR/caché y
   no tienen una petición de cliente en el ámbito (ni la necesitan: solo se sirven desde caché).
   La cobertura quedaría parcial y, peor, **aparentaría** un límite por cliente que no existe en la
   mitad del tráfico.
3. El riesgo nuevo es nulo: quien tiene el secreto ya puede llamar a GraphQL y a la Store API sin
   pasar por la puerta (`hwe-bff-only` se lo permitiría). La exención no amplía la superficie de
   nadie que no fuera ya «el BFF».

Queda como mejora futura documentada: si se quiere un límite por cliente también en WordPress,
añadir `X-HWE-Client-IP` (solo con secreto válido) y usarla como clave de bucket **únicamente** en
las rutas por usuario (Store API y `hwe/v1/auth|loyalty|memberships`), no en el catálogo cacheado.

## Tests (rojo primero)

Nuevo `backend/tests/Unit/RateLimit/BffExemptionTest.php` (cada método en su proceso: la constante
`HWE_REVALIDATION_SECRET` no se puede deshacer). `RateLimitTestCase` gana `withSecret()` y un
`error_log` silenciado.

Casos:

| Caso | Esperado |
|---|---|
| 121 GET a `/wp-json/wp/v2/posts` con secreto válido | las 121 pasan; la Store API también |
| 61 POST a `/graphql` con secreto válido | las 61 pasan |
| Sin secreto, 200 REST | la 121.ª es 429 |
| Sin secreto, 100 GraphQL | el 61.º es 429 |
| Secreto **incorrecto** | se cuenta igual que sin secreto (120) |
| `HWE_REVALIDATION_SECRET` **sin definir** (aunque venga cabecera) | se cuenta (120) |
| `wp-login.php` con secreto válido | sigue limitado a 5 |

**Evidencia de rojo** (antes de tocar `rate-limit.php`; el resto de casos ya pasaban, que es lo
correcto):

```
$ COMPOSER_PROCESS_TIMEOUT=0 php /tmp/opencode/tools/composer.phar test -- --filter BffExemptionTest
1) testRestWithTheValidSecretIsNeverRejected
   Failed asserting that 120 is identical to 121.
2) testGraphqlWithTheValidSecretIsNeverRejected
   Failed asserting that 60 is identical to 61.
FAILURES! Tests: 7, Assertions: 14, Failures: 2.
```

Tras implementar: `OK (7 tests, 15 assertions)` para el archivo y `OK (84 tests, 366 assertions)`
para `BffExemptionTest|ApiLimitsTest|LoginBruteForceTest|HweBffOnly`.

## Documentación

- `docs/SECURITY.md`: fila nueva en el modelo de amenazas («Abuso de la API de WordPress») con los
  límites, la exención del BFF y las condiciones en que no aplica.
- `AGENTS.md`: mapa de `hwe-bff-secret.php` y `rate-limit.php`, y viñeta en §6.3 explicando la
  exención y de dónde sale el límite del BFF.
- `backend/wp-content/mu-plugins/README.md`: filas de `hwe-bff-secret.php` y `rate-limit.php`.
- `docs/RUNBOOK.md`: el diagnóstico de «Muchos 429» aclara que solo cuenta el tráfico que no es
  del BFF.
- `CHANGELOG.md` `[Sin publicar]` → `Fixed`.

## Verificación ejecutada

### Backend (`backend/`)

```
$ COMPOSER_PROCESS_TIMEOUT=0 php /tmp/opencode/tools/composer.phar test
OK (2173 tests, 15500 assertions)
Time: 02:21.074, Memory: 90.00 MB
```

`php -l` sin errores en `rate-limit.php`, `hwe-bff-secret.php` y `hwe-bff-only.php`.

### Frontend (`frontend/`, sin cambios de código: se corre igual porque el CI lo exige)

```
$ npm run lint                 → eslint . (sin errores)
$ npm run type-check           → tsc --noEmit (sin errores)
$ npm run format:check         → All matched files use Prettier code style!
$ npm run i18n:check           → i18n OK — 605 hojas, misma forma, mismos argumentos y etiquetas.
$ npm run test:coverage        → Test Files 343 passed (343) · Tests 3299 passed (3299)
                                 Statements 88.99 % (5328/5987) · Branches 82.03 % (4205/5126)
                                 Functions 91.35 % (1141/1249) · Lines 90.47 % (4682/5175)
                                 (sin fallo de umbrales)
```

Build con el stub (PID propio, puerto libre 18164; apagado por PID al terminar):

```
$ STUB_GRAPHQL_PORT=18164 node .github/scripts/stub-graphql.mjs &
$ WORDPRESS_INTERNAL_API_URL=http://127.0.0.1:18164/graphql npm run build
… ƒ Proxy (Middleware) · rutas estáticas y SSG generadas · build completado
```

### Repositorio

```
$ bash .github/scripts/check-anti-instance.sh
Puerta anti-instancia: 0 coincidencias en el repositorio.
```

## Lo que NO se verificó en vivo (honestidad)

- No se levantó la pila (prohibido `docker` en este worktree ni se disponía de WordPress real):
  `backend/scripts/verify-security.sh`, `verify-modules.sh` y `verify-integration.sh` **no** se
  ejecutaron. Los tests PHPUnit simulan `$_SERVER` y los transients, así que confirman la rama de
  decisión, no el 429 real contra Apache+PHP.
- No se ejecutó el e2e de compra (`purchase.spec.ts`, opt-in) ni una sesión real de fidelización
  contra WordPress: el síntoma que motivó el fix (429 con decenas de usuarios) queda sin reproducir
  en vivo aquí. La comprobación que lo cerraría es un `verify-*` contra la pila con carga
  concurrente.
- `hwe-bff-only` no cambia de comportamiento (sus tests siguen verdes), pero su comprobación ahora
  vive en otro archivo: conviene revisar en el commit que `hwe-bff-secret.php` es un mu-plugin
  nuevo (no una ruta de instancia) y que el despliegue lo copia con el resto de `mu-plugins/`.
