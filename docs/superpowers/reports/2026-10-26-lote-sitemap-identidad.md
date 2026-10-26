# Lote de huecos genéricos: sitemap, identidad por defecto y otros

Fecha: 2026-10-26 · Rama: `fix/lote-sitemap-identidad` (base `main` `92bdf70`) ·
Núcleo del framework (no instancia).

Batería de nueve tareas aprobadas por el revisor (T1, T4–T8, T11, T13, T14) más la
documentación opcional D1. Cada cambio va en su commit, con un test en rojo contra el código
anterior cuando cambia comportamiento. Todos son fixes o añadidos compatibles: no hay cambios
incompatibles y `FRAMEWORK_VERSION` no se toca (sigue en `2.2.0`, lo sube la release).

## T7 — `DELETE /api/store/cart` devuelve el carrito vacío (3027d38)

La Store API de WooCommerce contesta a `DELETE /cart/items` con una lista vacía. El BFF la
pasaba a `completeStoreCart` como si fuera un `StoreCart` y la petición acababa en 500, con el
cajón enseñando líneas viejas hasta recargar. Ahora el handler comprueba la forma de la respuesta
(`isStoreCart`): si ya es un carrito se usa tal cual; si no, relee `/cart` con la misma sesión
(token de carrito y credencial de usuario, con el token que haya devuelto el vaciado), valida la
forma y devuelve ese carrito completado. El `Cart-Token` se sigue persistiendo en su cookie, se
conservan `guardMutation` y el rate-limit, y un fallo de la relectura responde con el error
normalizado (nunca un 200 falso). Tests nuevos en `route.test.ts` para los tres caminos.
Docs: `CHANGELOG` y la fila de `/api/store/cart` en `docs/FRONTEND_CONNECT.md` (ahora
`GET/POST/DELETE`).

## T11 — `/icon` y `/apple-icon` no pasan por next-intl (11e1d17)

Reproducido antes de tocar nada: con `next build` + `next start` sobre el stub, las dos rutas de
metadatos dinámicas respondían HTML (404) porque el `matcher` solo excluía las rutas con punto y
next-intl las trataba como páginas. Se excluyen `icon$`, `icon/`, `apple-icon$` y `apple-icon/`
del `matcher`, manteniendo la forma literal que Next exige y sin afectar a `/icon-guide`,
`/en/icon/…` ni `/api/*` (que sigue entrando por la barrera de Origin). Test de unidad sobre el
patrón en `tests/unit/proxy-matcher.test.ts` y comprobación en el `next start` real (ver
«Verificación»).

## T5 — Defaults públicos sin identidad del framework (ff79ee1)

`social.twitter` (`@headlesswp`), `legal.email` (`hello@headlesswp.com`) y `legal.company`
(`HWE-Orbit`) venían con valores de la plantilla. Una tienda que no los rellenaba los publicaba
en `Organization.sameAs`, `contactPoint`/`legalName`, el `creator` de la tarjeta de Twitter y las
claves de `llms.txt`. Los tres defaults pasan a vacío en las dos capas (`CONFIG_DEFAULTS` y
`SchemaGroups/Contact.php`), con un test de paridad entre ambas. `brand.name` **no** cambia (es
el nombre de producto de la plantilla y lo fija un test existente). El resto del código ya
trataba el vacío (`if (email)`, `|| brand.name`, `legalName` solo si difiere). `grep` en el
repositorio versionado: el dominio y la cuenta del framework solo quedan en el `CHANGELOG`.

## T6 — JSON-LD de producto sin `image: []` ni `description: ""` (e5669fb)

`buildProductJsonLd` emitía `image: []` cuando la ficha no tenía foto y `description: ""` cuando
la descripción estaba en blanco; los buscadores lo leen como datos declarados y vacíos. Ahora las
dos claves se omiten si no hay URL de imagen o si la descripción queda vacía tras recortar. No se
inventa imagen de respaldo y el resto del nodo (oferta, marca, reseñas) no cambia; `SearchAction`
no se toca.

## T1 — Sitemap sin fichas ocultas y sin truncar a 100 (36fcf20)

- `PRODUCT_SLUGS_QUERY` pide `catalogVisibility` y `pageInfo { hasNextPage endCursor }` y acepta
  `$after`.
- `ProductUrlEntry` gana `hidden` (verdadero solo con `HIDDEN`); el prerender de la ficha y de la
  landing no lo usa, así que las ocultas se siguen prerenderizando (siguen accesibles por URL).
- `getProductUrls({ all: true })` recorre el catálogo por cursor con el mismo tope de 20 tandas
  que `getPostsIndex`; sin la opción hace **una** petición de 100 (el presupuesto de prerender de
  `generateStaticParams` no cambia). Tolera respuestas sin `products` o sin `pageInfo` (el stub de
  CI responde `{"data":{}}`): lista vacía y el fallo queda con el evento existente.
- `buildSitemapEntries("catalogo")` omite la ficha oculta **y su landing de producto** y cuenta
  las omitidas con `sitemap.hidden_products_skipped`. Categorías y `llms.txt` no cambian.
- Docs: `CHANGELOG` y el párrafo «Sitemap segmentado» de `docs/FRONTEND_CONNECT.md`.

## T14 — Tests del núcleo aislados del registro (27b4af3)

`email-links.test.ts`, `forgot-password/route.test.ts` y `verify-email/route.test.ts` fijaban las
rutas internas sin aislar `@/instance/registry`: una instancia que remapeara `/reset-password` o
`/verify-email` con `publicPaths` veía fallar la suite del núcleo. Ahora simulan un registro vacío
(patrón de los tests de sitemap/`llms.txt`). El ejemplo vivo remapea además `/reset-password` a
`/example-access`, de modo que el job «contrato» caza en el futuro cualquier test no aislado.

Barrido con un registro temporal agresivo (públicas de `/login`, `/account`, `/products`,
`/forgot-password`, `/verify-email`, `/reset-password`, `timeZone` distinta, un campo de producto
y `sitemap`) sobre la suite completa: 345 ficheros / 3353 tests en verde, sin tocar el registro
del árbol (se restauró con `cp` desde una copia en `/tmp`).

## T8 — Compose de desarrollo coherente con `FRONTEND_PORT` (39980a0)

`NEXT_PUBLIC_SITE_URL`, `ALLOWED_ORIGIN`, `HEADLESS_FRONTEND_URL` y `HEADLESS_ALLOWED_ORIGINS`
fijaban `localhost:3000`. Ahora el compose de desarrollo las deriva de `FRONTEND_PORT` cuando no
se declaran (`http://localhost:${FRONTEND_PORT:-3000}`), con el valor efectivo de siempre cuando
el puerto es 3000. `.env.example` explica que en desarrollo basta `FRONTEND_PORT` y qué variables
hay que tocar si el origen público no es ese; `docs/CREATE_INSTANCE.md` §2 lo repite. Test en
`deploy-config.test.ts` sobre los compose (parseo YAML, sin Docker).

## T13 — País base de WooCommerce sigue a `ecommerce.country` (35ca72e)

Clase nueva `WooCountrySync` junto a `WooCurrencySync`: al guardar la config (misma acción
`hwe_control_center_saved`, mismo bloque que la moneda, también desde `wp hwe setup`), si el país
persistido es un ISO de dos letras mayúsculas y no coincide con la parte de país del ajuste
nativo (`CO:ANT` → `CO`), escribe el nuevo valor sin provincia; si coincide **no** toca nada (se
conserva la provincia); vacío o texto libre (`colombia`) no hace nada. Un fallo de
`update_option` se registra con `[hwe] control-center: …`. Test en `WooSyncTest.php` para los
cuatro caminos. La descripción del campo `country` en `SchemaGroups/Store.php` avisa de la
sincronización y `docs/CONFIGURATION.md` la lista.

**Aplazado (no entra en este lote):** la zona de envío «España peninsular» la crea `setup.sh`
antes de leer la config y se duplica en cada reejecución; arreglarla exige reordenar el script o
moverla a PHP. La traducción de WooCommerce exige un concepto nuevo (idioma de WordPress) en
`instance.config.json` y red en el setup.

## T4 — Páginas propias y exclusiones del sitemap; contrato 2.3.0 (db99eb8 + 9fa7fbc)

Campo opcional `InstanceRegistry.sitemap` con dos listas:

- `pages`: rutas internas (formato de `pathnames`) que se añaden al segmento `paginas` en todos
  los idiomas con alternates y x-default. `changeFrequency` opcional (def. `monthly`) y
  `priority` opcional (def. 0.5, entre 0 y 1). La URL pública sale de `localizedPath`, así que
  `publicPaths` sigue mandando. Una ruta ya presente en el núcleo no se duplica (gana la del
  núcleo) salvo que la instancia la excluya y la redeclare.
- `exclude`: rutas internas exactas o un prefijo con `/*`, aplicadas a las entradas de los tres
  segmentos **antes** de expandir por idioma y comparando contra la ruta interna. En `llms.txt`
  se aplica a las páginas principales, a los documentos legales y a las guías; el copy no cambia.
- Validación fail-soft en el módulo puro `lib/instance/sitemap.ts` (mapa inyectable, como
  `public-paths.ts`): sin barra inicial, con `//`, consulta, ancla, comodín fuera del sufijo,
  frecuencia desconocida o prioridad fuera de rango → se descarta con el evento
  `instance.sitemap.invalid_entry` y el resto se genera. `publicPaths` se valida, pero solo se
  usa al construir la URL.

Tests colocados: `lib/instance/sitemap.test.ts` (validación y comparación), `_sitemap/entries.test.ts`
(página propia en todos los idiomas con `publicPaths`, exclusiones exacta y de prefijo sin tocar
`/products-extra`, sin duplicar, y salida idéntica sin el campo), `_llms/build.test.ts` (legal,
prefijo y guías) y `example-registry.test.ts`. El ejemplo vivo declara `/example` y excluye
`/legal/cookies`.

Docs: `CHANGELOG` (Added, movido a `[Sin publicar]`), `docs/FRONTEND_CONNECT.md` §A.4 (subsección
nueva y paso B.3.6 corregido: la vía es el registro, no editar `entries.ts`),
`docs/CREATE_INSTANCE.md` (SEO/rutas), `docs/UPGRADE.md` §3.3 «v2.2 → v2.3» y `AGENTS.md` §1.1 y
§6.7.

**Commit de contrato (9fa7fbc):** `HWE_CORE_CONTRACT_VERSION` pasa a `2.3.0`
(`hwe-instance-loader.php`, `InstanceLoaderTest.php`, `instance/README.md`,
`docs/PLUGIN_DEVELOPMENT.md`, `docs/COMPATIBILITY.md`). No se toca `FRAMEWORK_VERSION` y no se
crean tags; `framework-version.test.ts` sigue en verde con el último `## [x.y.z]` del CHANGELOG
(`2.2.0`).

## D1 — Plugins de instancia activados por `wp hwe setup` (dd5a65b)

Receta del filtro `hwe_modules_catalog` (id neutro `example` → `hwe-instance-example`) y su
`modules: ["example", …]` en `instance.config.json`, en `docs/PLUGIN_DEVELOPMENT.md` §0 (amplía
la fila del hook) y enlazada desde `docs/CREATE_INSTANCE.md` §7 y desde
`backend/wp-content/mu-plugins/instance/README.md`. La advertencia queda explícita: **con
`modules` presente, el setup activa los listados y desactiva el resto del catálogo**, así que hay
que enumerar también los módulos del framework que se quieran conservar; un id desconocido se
ignora con su evento. Se añade la frase de la zona horaria (X2): `timeZone` del registro solo
gobierna el frontend y WordPress tiene la suya en Ajustes → General, que el setup no toca.

## Verificación ejecutada (salida real)

Toda la batería se corrió en este worktree, con el árbol limpio salvo este informe y el
`INFORME-lote.md` (sin commitear).

| Comprobación | Comando | Resultado |
|---|---|---|
| Lint | `npm run lint` | exit 0 |
| Tipos | `npm run type-check` | exit 0 |
| Formato | `npm run format:check` | exit 0, «All matched files use Prettier code style!» |
| i18n | `npm run i18n:check` | exit 0, «605 hojas …» |
| Tests | `npm run test` | 345 ficheros, 3353 tests en verde |
| Cobertura | `npm run test:coverage` | exit 0; 88,98 % sentencias, 82,26 % ramas, 91,38 % funciones, 90,45 % líneas (sin bajar umbrales) |
| Build | `STUB_GRAPHQL_PORT=18163 node .github/scripts/stub-graphql.mjs` + `npx next build` con `WORDPRESS_INTERNAL_API_URL`/`WORDPRESS_PUBLIC_URL` al stub | build completo; `/icon` y `/apple-icon` prerenderizados |
| T11 en `next start` | `next start --port 28431` con el entorno dummy del CI y el stub | `/icon` → `200 image/png` (32×32), `/apple-icon` → `200 image/png` (180×180), ambos también con `Accept-Language: en`; `/` → `200 text/html` con `x-request-id`; `/api/csrf` → `200 application/json`; `/api/health/live` → `200`; `/icon-guide` → `404 text/html` (pasa por el proxy); `/en/products` → `200 text/html` |
| PHP | `php vendor/bin/phpunit` | 2177 tests, 15 509 aserciones, OK (6:24) |
| Puerta anti-instancia | `bash .github/scripts/check-anti-instance.sh` | «0 coincidencias en el repositorio», exit 0 |
| Job «contrato» | worktree temporal sobre `HEAD`: superponer `examples/instance-minimal/`, `composer test`, `npm run test`, `npm run type-check`, stub + `npm run build` | PHP 2189 tests OK; frontend 348 ficheros / 3362 tests OK; tipos OK; build con el ejemplo OK |

El job «contrato» se reprodujo **en un worktree temporal** (`/tmp/opencode/contrato`, eliminado
al terminar); el árbol principal nunca se superpuso.

Notas de ejecución:

- `composer test` tiene un `process-timeout` de 300 s y aquí la suite tarda 6:24; se usó
  `php vendor/bin/phpunit` (mismos tests) y, en el job de contrato, `COMPOSER_PROCESS_TIMEOUT=900`.
  No es un fallo del código.
- En la primera pasada de `test:coverage` falló una vez `tests/unit/proxy-referral.test.ts` por
  `Test timed out in 5000ms` (el fichero, aislado, tarda 5,09 s bajo carga de cobertura); al
  repetirlo pasó solo y la pasada completa siguiente quedó en verde. Es un test sensible al
  tiempo de la máquina, ajeno a este lote (no se tocó `proxy-referral`).

## Lo que no se verificó en vivo

Sin pila real de WordPress/WooCommerce (prohibido Docker en este lote), quedan sin comprobar:

- **T7** contra la Store API real (forma de la respuesta de `DELETE /cart/items` y relectura).
  Comando para el dueño: `sh backend/scripts/verify-integration.sh`.
- **T13** contra WordPress real (que `woocommerce_default_country` quede alineado al guardar).
  Mismo `verify-integration.sh`, o guardar la config en un WordPress de staging.
- **T8** con `docker compose config` (los tests solo parsean el YAML): `docker compose config`.

Todo lo demás se verificó aquí con las órdenes de la tabla.

## Hallazgos que no se tocan en este lote

- **X1** (fichas ocultas en listados, búsqueda y ficha): aplazado; esta tarea solo cambia el
  sitemap.
- **6b** (`SearchAction` del JSON-LD): rechazado por el revisor.
- El recorrido por cursor del sitemap tiene el mismo tope que el índice del blog (20 tandas de
  100 = 2000 fichas). Un catálogo mayor seguiría truncándose; subirlo es una decisión aparte
  (presupuesto de build/ISR).

## Ronda 1 de la revisión 2 (cambios menores)

La revisión 2 aprobó la fusión con cambios menores. Se aplicaron todos encima de la rama, sin
rehacer ni reescribir ningún commit anterior, cada uno con su test cuando cambia comportamiento
(en rojo primero):

| Commit | Cambio |
|---|---|
| `784c29e` | **Sitemap fail-soft de verdad.** `resolveInstanceSitemap` iteraba `map.pages`/`map.exclude` sin comprobar que fueran listas: un valor no iterable lanzaba fuera de cualquier `try` y tumbaba el sitemap y `llms.txt`. Ahora cada campo se protege con `Array.isArray`; si no es lista se ignora entero con `instance.sitemap.invalid_entry`. Tests nuevos (rojo antes: `TypeError: number 5 is not iterable`). |
| `9296b97` | **`UPGRADE.md` §3.3** deja de decir «sin cambios incompatibles … conserva el comportamiento y las URL» (no era exacto) y pasa a «sin cambios incompatibles de contrato; revisa estos puntos de comportamiento», con las fichas ocultas y el catálogo completo (T1), la sincronización del país base de WooCommerce (T13) y la frase de `HWE_CORE_CONTRACT_VERSION` 2.3.0 que ya traían §3.1 y §3.2. |
| `5adf7ec` | **`FRONTEND_CONNECT.md` A.4:** se documenta que las `pages` declaradas no se filtran por `exclude` (gana la declaración explícita), la regla que ya aplica `entries.ts`. |
| `4e60b23` | **Comentario del matcher del proxy:** deja de decir «EXACTA» y explica que `icon/` y `apple-icon/` cubren `/icon/<id>` y `/apple-icon/<id>` de `generateImageMetadata`. El test de patrón fija las dos variantes y que `/en/icon/…` sí entra. Sin cambio de comportamiento. |
| `1977737` | **Ejemplo vivo:** el registro declara como página propia `/example` (la vista que existe), no `/example-page`; su test lo comprueba y se revierte el reformateo ajeno de `toMatchObject({ "/about": "/nosotros" })`. |
| `4b3d41d` | **Tope del sitemap visible:** `getProductUrls({ all: true })` emite `logger.warn` con `catalog.product_urls_truncated` si tras las 20 tandas (2000 fichas) aún hay `hasNextPage`. Test nuevo (rojo antes) y un catálogo que termina antes no avisa. |

Housekeeping pendiente del revisor: `INFORME-lote.md` sigue **sin commitear** y se elimina antes de
fusionar (el informe versionado es este archivo).

### Verificación de la ronda 1 (salida real)

| Comprobación | Comando | Resultado |
|---|---|---|
| Lint | `npm run lint` | exit 0 |
| Tipos | `npm run type-check` | exit 0 |
| Formato | `npm run format:check` | exit 0, «All matched files use Prettier code style!» |
| i18n | `npm run i18n:check` | exit 0, «605 hojas …» |
| Tests | `npm run test` | 345 ficheros, **3356** tests en verde (antes 3353) |
| Cobertura | `npm run test:coverage` | exit 0; 89,00 % sentencias, 82,29 % ramas, 91,38 % funciones, 90,46 % líneas |
| Build | stub `STUB_GRAPHQL_PORT=18163` + `npx next build` | exit 0; 64 páginas generadas |
| PHP | `php vendor/bin/phpunit` | 2177 tests, 15 509 aserciones, OK (7:06) |
| Puerta anti-instancia | `bash .github/scripts/check-anti-instance.sh` | «0 coincidencias», exit 0 |
| Job «contrato» | worktree temporal sobre `HEAD`, ejemplo superpuesto, `node_modules`/`vendor` copiados | PHP 2189 tests / 15 633 aserciones OK; frontend 348 ficheros / **3365** tests OK; `tsc` exit 0; `next build` (Turbopack, como el CI) exit 0 |

Sigue sin verificarse en vivo (sin Docker): T7 contra la Store API real, T13 contra WordPress y
T8 con `docker compose config`; los comandos para el dueño son los de la sección anterior.
