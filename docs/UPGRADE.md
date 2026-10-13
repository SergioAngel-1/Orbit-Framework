# Actualizar una instancia con los cambios del framework

> El framework y cada instancia comparten historia de git: una instancia ES un clon del
> framework que divergió. Este documento define **qué rutas siguen siendo del framework**
> (se actualizan) y **cómo traer sus cambios** sin pisar el código de la instancia.

## 1. Qué es núcleo (se actualiza) y qué es instancia (no se toca)

**Núcleo del framework** — en una actualización, la versión del framework gana:

- `backend/**` (mu-plugins, tema headless, scripts, config)
- `frontend/src/lib/**`
- `frontend/src/app/api/**`
- `frontend/src/components/ui/**`
- `frontend/src/proxy.ts`, `frontend/src/instrumentation.ts`
- `frontend/next.config.mjs`, `frontend/eslint.config.mjs`, `frontend/vitest.config.ts`
- `docker-compose.yml`, `docker-compose.prod.yml`, `Caddyfile`
- `.github/workflows/**`
- `docs/**` (salvo notas propias de la instancia)
- `frontend/tests/unit/**` (tests del núcleo)

**Instancia** — la actualización NUNCA debe tocarlo (si un merge lo toca, resuelve a favor
de lo local):

- `frontend/src/components/**` (salvo `ui/`)
- `frontend/src/app/[locale]/**`
- `frontend/src/i18n/messages/**`
- `backend/scripts/instance.config.json` (no versionado) y cualquier contenido/branding

**Zona gris (revisar a mano):** `frontend/package.json` (el framework puede subir versiones de
dependencias del núcleo; la instancia puede haber añadido las suyas) y
`frontend/src/i18n/routing.ts` (si la instancia añadió locales).

## 2. Preparación (una vez por instancia)

```bash
git remote add framework <URL-del-repo-del-framework>
git fetch framework --tags
```

## 3. Procedimiento de actualización

1. Lee `CHANGELOG.md` del framework entre tu versión y la nueva (los tags `vX.Y.Z` delimitan).
   Las entradas marcadas **BREAKING** requieren acción manual descrita en la propia entrada.
2. Crea una rama: `git checkout -b chore/framework-vX.Y.Z`.
3. Trae los cambios: `git merge vX.Y.Z` (merge del tag, no de `framework/main`: actualiza
   siempre a una release, nunca a trabajo en curso).
4. Resuelve conflictos con la regla de §1: rutas de núcleo → versión del framework; rutas de
   instancia → versión local; zona gris → a mano.
5. Verifica desde `frontend/`: `npm ci && npm run test && npm run lint && npx next build`.
6. Smoke test del contrato con la pila levantada: `node examples/minimal-consumer/index.mjs`
   y prueba de humo manual (login, carrito, checkout `noop`). Despliega como cualquier otro
   cambio (`docs/DEPLOYMENT.md`).

## 4. Reglas para el framework (para que esto siga funcionando)

- Todo cambio de núcleo se publica como release taggeada + entrada de CHANGELOG.
- Un cambio que exija tocar código de instancia se marca **BREAKING** en el CHANGELOG con su
  instrucción de migración.
- El núcleo no importa código de instancia (regla de lint en `eslint.config.mjs`) — esto es lo
  que hace que los merges de arriba sean limpios.

## 5. v1 → v2: registro de cambios incompatibles

> **Borrador en curso.** Se anota aquí, **una línea por cambio**, a medida que se hace. La
> guía definitiva —qué cambia, por qué y los pasos de migración de cada punto, más la tabla
> «Migrar Siu Tutuava»— se redacta al final de la v2.0.0. Una entrada marcada **(contrato)**
> afecta a un frontend que llame directamente al BFF o a WordPress sin usar el de este repo.

### Seguridad (F1)

- **Next 16.4.0 y React 19.3.0.** Tras actualizar, `npm install` en `frontend/`; las instancias
  que fijaban `next` por debajo de 16.3.3 estaban expuestas a una ejecución remota sin
  autenticar en `/_next/image`.
- **La config remota ahora se aplica de verdad.** El `deepMerge` de la v1.0 descartaba todo
  valor remoto escalar: una instancia clonada renderizaba siempre los valores por defecto. Tras
  actualizar, el sitio pasa a mostrar la marca, la paleta, la moneda y los flags **reales** del
  Control Center: revísalos antes de desplegar. Un campo remoto con la forma equivocada (objeto
  frente a lista o texto) se ignora y se registra `site_config.shape_mismatch`.
- **Los webhooks firmados dejan de recibir 403** con `ALLOWED_ORIGIN` configurado. La barrera
  de Origin exime una **lista cerrada** (`lib/security/signed-routes.ts`): `/api/revalidate`,
  `/api/webhooks/woocommerce/order-{created,updated}` y `/api/payments/webhook/<proveedor>`. Un
  webhook propio nuevo debe añadirse a esa lista y verificar su firma antes de actuar.
- **(contrato) `ephemeralToken` de `POST /api/auth/login` ya no es un JWT**, sino un id opaco
  de un solo uso: las credenciales se quedan en Redis hasta verificar el segundo factor. Sin
  Redis, el login de un usuario con 2FA responde 503 (falla cerrado). `verify-2fa` invalida el
  id tras 5 códigos incorrectos (429).
- **(contrato) El alta de 2FA ya no envía el secreto a ningún tercero.** `GET /api/auth/2fa/qr`
  lo genera el BFF por sesión (no acepta parámetros); `api.qrserver.com` sale de la CSP. Si tu
  frontend pintaba el QR por su cuenta, usa ese endpoint o muestra solo el código manual.
- **(contrato) `POST /api/auth/2fa/setup` y `activate` exigen el email verificado** (403).
- **(contrato) WordPress `PUT hwe/v1/auth/2fa-secret` exige prueba de posesión.** Activar pide
  `code` (TOTP del secreto que se guarda); desactivar, `code` del secreto activo o
  `recovery_code`. Con el 2FA ya activo, registrar otro secreto responde 409. El BFF ya lo
  reenvía; un cliente propio que llamara al endpoint sin código recibirá 403.
- **(contrato) WordPress `forgot-password` y `send-verification` solo aceptan `frontend_url` y
  `verification_url` de un origen de `HEADLESS_ALLOWED_ORIGINS`/`HEADLESS_FRONTEND_URL`** (400
  si no). Comprueba que coincide con `NEXT_PUBLIC_SITE_URL` en cada entorno.
- **(contrato) `send-verification` responde 502 si `wp_mail()` falla** (antes, 200).
- **Sin clave de cifrado de reserva.** Si no hay `HWE_2FA_KEY`, `AUTH_KEY` ni
  `GRAPHQL_JWT_AUTH_SECRET_KEY`, activar 2FA responde 500 y se registra `[hwe] auth:`; un
  secreto cifrado antes con la clave literal pública (`hwe-insecure-2fa-key`) deja de poder
  descifrarse: desactiva y vuelve a activar el 2FA con una clave real.
- **Los secretos del Control Center (pasarelas, SMTP) tampoco tienen clave de reserva.** Sin
  `HWE_SECRETS_KEY`, `AUTH_KEY` ni `SECURE_AUTH_KEY` se tratan como vacíos y se registra
  `[hwe] control-center:`; **sin clave no se guarda nada** (`SecretsStorage::setAll()` pasa a
  devolver `bool`, `false` = no se escribió), para no destruir los secretos ya guardados.
  Una instalación que cifró con la clave literal `hwe-fallback-insecure-key` debe definir una
  clave real y **volver a introducir** las credenciales.

- **`CSRF_SECRET` es obligatorio y sin reserva.** Ya no se degrada a `GRAPHQL_JWT_AUTH_SECRET_KEY`
  ni a la literal `insecure-dev-csrf-secret`: sin él, `/api/csrf` y toda escritura devuelven 500.
  Una instancia que no lo tuviera definido (y viviera de la reserva) debe añadirlo. Un token
  firmado con cualquiera de las reservas antiguas deja de ser válido: los usuarios obtienen uno
  nuevo al recargar.
- **`ALLOWED_ORIGIN` es obligatoria y el guard de arranque la comprueba** (en producción aborta;
  en desarrollo avisa): URL absoluta **https** (solo `localhost`/`127.0.0.1`/`[::1]` pueden ir
  por http, porque `next start` fija `NODE_ENV=production` y así arrancan el e2e y la pila local)
  y **exactamente** el `Origin` del navegador: sin barra final, ruta, credenciales, mayúsculas ni
  `:443`. La comparación es literal; con otra forma, toda escritura respondía 403 sin aviso.
- **(contrato) `POST /api/payments/create` solo acepta un `returnUrl` del propio sitio** (422
  si su origen no es `ALLOWED_ORIGIN`). Se entrega a la pasarela como destino del redirect
  post-pago, y sin esta barrera el endpoint era un redirect abierto vía un tercero. El frontend de
  este repo ya compone la URL con `window.location.origin`; si el tuyo enviaba otra, omite el
  campo (se usa `NEXT_PUBLIC_PAYMENT_RETURN_URL` o `/checkout/return`).
- **(contrato) `POST /api/store/reviews/[productId]` ya no acepta `name` ni `email`.** El autor
  (`reviewer` y `reviewer_email`) sale siempre del perfil WooCommerce de la sesión; si lo envías,
  se ignora. Antes cualquier petición directa podía publicar una reseña atribuida a un tercero.
  Si tu perfil no tiene nombre se publica como «Cliente». El `ReviewForm` del starter pierde esos
  dos campos (y las claves `products.reviewName`/`reviewEmail` de los mensajes, sustituidas por
  `reviewAuthorNote`); `reviewApi.create` ya no los admite.
- **(contrato) Wishlist, `2fa/disable` y `verify-email` validan el cuerpo con Zod y responden
  422 con `{ error, details }`.** Cambios de comportamiento: un `productId` de wishlist debe ser
  entero positivo (antes se aceptaba `-3` o `1.5`); `recoveryCode` en blanco ya no cae al TOTP
  (422); un `token` de `verify-email` de más de 200 caracteres se rechaza; y un cuerpo `null`
  pasa de 500 a 422.
- **(contrato) Rate-limit en tres lecturas/entradas que no lo tenían** (429 con `Retry-After`;
  fail-open si Redis no responde): `POST /api/revalidate` (120/min por IP, el mismo techo que los
  webhooks de pedido), la **búsqueda** de `GET /api/store/products?search=` (20/min por IP; el
  listado sin búsqueda no se limita) y `GET /api/store/cart` (30/min por IP y carrito). El cliente
  del carrito ya trata un error como «carrito no disponible».
- **(contrato) Los webhooks de pedido (`order-created`/`order-updated`) rechazan un `payload.id`
  que no sea un id de pedido** (entero positivo, número o texto numérico) con 400, en lugar de
  usarlo tal cual en la clave de Redis del estado.
- **Un único `verifyHmacSignature`** (`lib/security/webhook.ts`) verifica las firmas de
  WooCommerce (base64) y de HWE (hex con `sha256=`, prefijo obligatorio). Compara la firma
  codificada tal como llega, no los bytes decodificados, y falla cerrado con un secreto vacío. Un
  webhook propio nuevo debe usarlo en vez de reimplementar el HMAC.
- **Los tokens de diseño se validan por tipo antes de inyectarse en el `<style>`.** Antes, un
  valor como `red; } </style><script>…` en un color o una fuente del Control Center cerraba el
  `<style>` e inyectaba HTML/JS. Ahora un color solo puede ser `#rrggbb` (lo mismo que ya
  guardaba el panel) y un nombre de fuente solo letras, dígitos, espacios, guiones y apóstrofes
  (máx. 100); el que no cumple cae al valor por defecto de `CONFIG_DEFAULTS` y se registra
  `design.token_invalid` (una vez por valor y proceso). Si tu config tenía un color en otro
  formato (`rgb()`, `#fff`), pasará a mostrarse con el valor por defecto: corrígelo en el panel.
  Cada entrada de `TOKEN_MAP` declara su `kind` (`color`, `font` o `length`); una instancia que
  amplíe `lib/config/tokens.ts` debe indicarlo.
- **`sanitizeHtml` fuerza `rel="noopener noreferrer"` en todo enlace que abre otra ventana**
  (`target="_blank"` en cualquier grafía y nombres de ventana; solo `_self` queda exento). Se
  fusiona con el `rel` del CMS (p. ej. `nofollow`) y se elimina `opener`. El hook se registra una
  vez al importar el módulo.
- **(contrato) Cambiar la contraseña cierra las demás sesiones y conserva la actual.**
  `POST /api/auth/change-password` rota el secreto JWT del usuario en WordPress (`logout-all`),
  lo que invalida todos sus refresh tokens, también el de este dispositivo; por eso reemite la
  sesión actual iniciando sesión con la contraseña nueva, guarda las cookies nuevas y revoca el
  access token anterior. Los access tokens de otros dispositivos caducan solos (~5 min). La
  respuesta pasa de `{ ok }` a `{ ok, otherSessionsRevoked, sessionKept }`: si alguno es `false` la
  contraseña **sí** cambió, pero hay que avisar al usuario (`otherSessionsRevoked: false` = sus
  otras sesiones siguen abiertas; `sessionKept: false` = debe volver a iniciar sesión). El
  `ChangePasswordForm` del starter ya lo hace (claves `changePassword.successSessionsPending` y
  `successReloginNeeded`); `revokeAllSessions` devuelve ahora un booleano.
- **`change-password` verifica la contraseña actual con el email de la cuenta**, no con el id
  numérico de la sesión, que `wp_authenticate` no acepta (la verificación fallaba siempre). Un
  fallo de red o HTTP al verificar responde 502 en vez de «contraseña incorrecta».
- **(contrato) GraphQL, la Store API y `hwe/v1/auth|consent` solo atienden al BFF, con un secreto
  compartido.** El nuevo mu-plugin `hwe-bff-only` responde **404** a `/graphql`, `?graphql`,
  `wc/store/*`, `hwe/v1/auth/*` y `hwe/v1/consent/*` (por ruta y por `?rest_route=`) salvo que la
  petición traiga `X-HWE-Internal-Secret` con el valor de `HWE_REVALIDATION_SECRET`
  (comparación en tiempo constante) o venga de WP-CLI. El BFF lo envía en **todas** sus llamadas
  a WordPress (`internalSecretHeaders()` en los clientes de GraphQL, wc/v3 y Store API; `wpFetch`
  para el resto). Sin `HWE_REVALIDATION_SECRET` en `wp-config.php` falla cerrado y lo registra
  (`[hwe] bff-only: …`). Qué debes hacer:
  - **WordPress nativo (modo híbrido):** define `HWE_REVALIDATION_SECRET` en su `wp-config.php`
    con el mismo valor que `frontend/.env.local` (en Docker ya lo hace `docker-compose*.yml`).
  - **Consumidores propios del lado servidor** (un script, otro BFF, `examples/minimal-consumer`):
    envía la cabecera en cada llamada. Un navegador ya no puede llamar a estas rutas.
  - Dejan de funcionar el IDE GraphiQL de WPGraphQL y los bloques de carrito de WooCommerce en el
    editor, que llamaban a estas rutas desde el navegador.
  - La heurística de red de versiones anteriores (sin `X-Forwarded-For` + IP privada) queda
    detrás de `HWE_BFF_TRUST_PRIVATE_NETWORK` (constante PHP), **desactivada por defecto**.
- **(contrato) Se elimina `NEXT_PUBLIC_WORDPRESS_API_URL`** y la rama de navegador de
  `lib/graphql-client.ts`, que ahora es `server-only`: el navegador no habla con WordPress. Quita la
  variable de tus `.env*`, `docker-compose*.yml`, CI y del build de la imagen; si un componente de
  cliente importaba `fetchGraphQL`, el build fallará y debe pasar por un Route Handler. Para
  autorizar las imágenes de WordPress (CSP y `next/image`) ahora se usa `WORDPRESS_PUBLIC_URL`
  (variable de servidor, presente en el **build**; por defecto `http://localhost:8080`). El CSP ya
  no incluye el CMS en `connect-src`. Los workflows de CI pasan a definir
  `WORDPRESS_INTERNAL_API_URL`.
- **Toda llamada a WordPress pasa por un cliente que añade el secreto.** Una rutina nueva no debe
  construir URLs de WordPress ni llamar a `fetch` por su cuenta: usa `wpFetch` (`lib/wordpress/
  internal-fetch`). El test `lib/security/wordpress-calls.test.ts` lo impone sobre todo el código.
- **`wp-login.php` tiene rate-limit: 5 intentos (POST) cada 5 minutos por IP.** El mu-plugin
  `rate-limit.php` solo cubría `/graphql` y `/wp-json/`, y `wp-login.php` es el único login humano
  permitido: sin límite se podían probar contraseñas sin fricción. Superarlo responde 429 con
  `Retry-After`; cargar la página (GET) no cuenta. También limita el envío de «he olvidado mi
  contraseña» de WordPress. Presupuesto propio: no compite con la API.
- **(contrato) `WP_ADMIN_PASSWORD` es obligatoria al instalar.** `setup.sh` ya no cae en `admin`
  (junto a `WP_ADMIN_USER=admin` por defecto dejaba credenciales públicas) y aborta con error si
  falta; `docker-compose.prod.yml` se niega a arrancar sin ella (`:?`). El `docker-compose.yml` de
  desarrollo sigue dando un valor local explícito. Si validas el compose de producción en tu CI,
  añade `WP_ADMIN_PASSWORD` a las variables ficticias.
- **La introspección de GraphQL falla cerrado.** Se apagaba solo si `WP_ENVIRONMENT_TYPE` estaba
  *definida* como `production`; sin la constante (WordPress asume `production`) quedaba activa. Ahora
  se pregunta a `wp_get_environment_type()`. Si usabas introspección en un entorno sin la constante
  (generación de tipos, herramientas de desarrollo), define `WP_ENVIRONMENT_TYPE` como `local` o
  `development`; `docker-compose.yml` ya lo hace (`local`).

- **`GET /api/store/products?include=` ya no expone productos sin publicar.** Esa rama consulta
  `wc/v3/products` con las credenciales del servidor, y WooCommerce devuelve por defecto productos de
  cualquier estado: un anónimo podía leer nombre, precio y descripción de un borrador, un pendiente o un
  privado pidiéndolo por id. Ahora pide `status=publish`. Además solo acepta ids enteros positivos (sin
  duplicados, máximo 100): antes `?include=abc` dejaba la lista vacía y un `include` vacío no filtra
  nada, así que devolvía los primeros 100 productos de cualquier estado. Sin ningún id válido responde una
  lista vacía sin consultar WooCommerce.

### Contrato de instancia (F2)

- **Nuevo: el código de tu instancia va en `backend/wp-content/mu-plugins/instance/`**, que el framework no toca nunca
  (solo versiona su README). El mu-plugin `hwe-instance-loader.php` carga sus `.php` de primer nivel en orden
  alfabético; un archivo roto se registra (`[hwe] instance-loader: …`) y no tumba el sitio. Si tu instancia ya tenía
  código mezclado con el del núcleo, muévelo ahí: así las próximas actualizaciones no generan conflictos. El `.gitignore`
  del framework ignora esa carpeta (salvo el README); en una instancia, borra esas dos líneas para versionar la tuya.
- **Nuevo grupo raíz `instance` en el schema del Control Center** (pestaña «Instancia», visible solo cuando tiene campos).
  Añade tus ajustes con el filtro `hwe_control_center_schema` **dentro de ese grupo**. Una clave raíz nueva se descarta y
  se registra, y un grupo del núcleo que un filtro elimine se restaura. Los campos `public` salen por
  `/wp-json/hwe/v1/config` en `instance.*`.
- **Nueva acción `hwe_control_center_saved($publicData, $rawPost)`**, que se dispara tras guardar y antes de revalidar.
  Sustituye a las acciones con nombre de instancia (`hwe_control_center_<cosa>_save`): `AdminPage` ya no lee ninguna clave
  del POST que no cuelgue del schema. Si tu código dependía de alguna, pasa a engancharse a esta (ver el README de
  `instance/`).
- **Nuevo filtro `hwe_revalidation_tags`** y `Revalidation::trigger($tag)`: WordPress solo envía tags registrados (formato
  `[a-z0-9._-]`, hasta 64 caracteres). La revalidación ya no se omite en silencio si falta `HWE_REVALIDATION_SECRET`: se
  registra.
- **Corregido: la config pública devolvía un marcador interno** (`"\u0000hwe_skip\u0000"`) en cualquier grupo raíz sin campos
  públicos; en la v1.0 era el caso de `backups`. Ahora esas claves se omiten. Si tu frontend leía `config.backups`, no tenía
  un valor útil; ya no existe.
- **Nuevo: módulos opcionales.** `instance.config.json` acepta `modules: [<id>…]` (`wp hwe setup` activa los listados y
  desactiva los demás del catálogo; sin la clave no toca ningún plugin). La config pública incluye `modules: { <id>:
  boolean }` calculado según si cada plugin está activo. En el frontend, `SiteConfig` gana `modules` (todo `false` por
  defecto) e `instance` (vacío en el núcleo): si construyes un `SiteConfig` literal en tus tests, añade ambas claves. El
  lector de banners devuelve vacío con el módulo apagado, aunque `banners.enabled` sea `true`: revisa que tu
  `instance.config.json` incluya `"banners"` si usas banners.

### Control Center (F3)

- **`wp hwe setup` ya no borra lo que el JSON no trae.** Antes, un JSON parcial saneaba las claves
  ausentes a vacío y `Storage::merge` las pisaba; ahora el setup trabaja en modo *sparse* y omite
  lo ausente. Si dependías del borrado (p. ej. para vaciar un campo), ponlo explícito en el JSON.
- **Nuevo filtro `hwe_control_center_field_types`** para registrar tipos de campo propios (pintar,
  sanear, persistencia `config`/`secret`/`external`). El filtro puede añadir ids, pero no
  sobrescribir ni eliminar los del núcleo; un tipo desconocido se pinta deshabilitado en vez de
  romper el panel.
- **El guardado ya no reemplaza toda la config (`merge`, no `setAll`)** y rechaza el formulario
  incompleto: si falta alguna pestaña de nivel superior con contenido, no guarda nada y avisa
  (antes la completaba con vacíos y la persistía). Un secreto guardado se muestra como
  «guardado» sin revelar su valor.
- **Campos reorganizados (revisa tu `instance.config.json`):** `brand.og_image` se mueve a
  `seo.og_image`; `social` (linkedin/wikipedia/wikidata) pasa de URLs a usuarios/slugs; las
  credenciales de Wompi se reorganizan por pasarela (`integrity_secret`, `events_secret`) y
  llega el grupo `avalpay`; `logo` y `organization_logo` usan el selector de medioteca;
  `currency` es un desplegable con el catálogo de WooCommerce; `retain_days` es numérico.
  Llegan `tagline_en`/`description_en`, `social.whatsapp`, `legal.phone`/`mobile`/`locations`,
  `ecommerce.checkout_login_required`/`free_shipping`, medios de pago (`gateway_enabled`,
  `whatsapp_enabled`, `cash_enabled`), integraciones de medición (GTM, Meta + CAPI, TikTok,
  HubSpot), campos de schema SEO/GEO (devoluciones, envío, FAQ por bloques) y opciones de
  backup (`keep_only_latest`, `exclude_thumbnails`, `include_config_export`). En el frontend,
  `SiteConfig` gana `payments`, `modules` e `instance` (ver `lib/config/types.ts`).
- **(contrato) `formatPrice` y `formatStoreAmount` exigen moneda y locale.**
  `formatPrice(precio, moneda, locale, decimales?)` y
  `formatStoreAmount(importe, minorUnit, moneda, locale)`. En servidor, la moneda sale de
  `getSiteConfig().ecommerce.currency` (o del `currency_code` de la Store API) y el locale de
  la ruta; en cliente, usa `useFormatPrice()` / `useFormatStoreAmount()` de
  `components/ui/currency-context` (el layout raíz monta `CurrencyProvider`). El importe se
  formatea en el idioma de la página (antes siempre en `es`) y con los decimales de
  WooCommerce: la config pública gana `ecommerce.price_decimals` (calculado; `null` sin Woo)
  y los importes de la Store API usan su `currency_minor_unit`. Ya no hay ninguna moneda con
  formato fijado en el código: una tienda sin decimales lo configura en WooCommerce.
- **La página de retorno del checkout mostraba el total dividido entre 100** (formateaba el
  `total` de WC REST, en unidades mayores, como si fuera un importe de la Store API).

- **Importar config (Backups) ya no puede borrar los secretos.** Antes, si los secretos del
  export no descifraban con la clave local, se sobrescribían y se «revertía» a vacío: importar
  un export de otro servidor dejaba sin credenciales de pago. Ahora se verifican antes de
  escribir nada y, si no descifran, los guardados quedan intactos. Además la config importada
  pasa por el saneado del schema (lo que el panel no aceptaría se descarta), se fusiona (lo que
  el export no trae se conserva) y sincroniza Woo y revalida como un guardado del panel.
- **`wp hwe setup` dispara `hwe_control_center_saved`** (con el JSON como `$rawPost`): la
  moneda, el checkout de invitados y el efectivo sembrados llegan a WooCommerce, y los módulos
  que escuchan la acción actúan también al sembrar. Revalida una sola vez, tras aplicar los
  módulos.

- **Campos traducibles a N idiomas.** `brand.tagline`, `brand.description`, `geo.faq` y
  `geo.faq_payments` dejan de tener una hermana `_en` fija: el schema los marca como
  `translatable` y genera `<campo>_<idioma>` para cada idioma del nuevo grupo `i18n.locales`
  (por defecto `en`, así que las claves `_en` ya guardadas siguen valiendo). **(contrato)**
  `pickLocalized(es, en, locale)` pasa a `pickLocalized(entity, campo, locale)`, y
  `SiteConfig` pierde las propiedades `_en` explícitas (tipadas ahora como `Translations<…>`).

### Núcleo de WordPress (F4)

- **Se retira `woocommerce-shipping-config.php`.** La tarifa plana y el umbral de envío gratis
  del grupo `shipping` del Control Center (`flat_rate_cost`, `free_above`, `flat_label`,
  `free_label`) **dejan de aplicarse a WooCommerce** hasta que llegue el módulo `shipping`
  (que sustituye a este mu-plugin). Mientras tanto, configura las tarifas en WooCommerce →
  Ajustes → Envío. Un filtro o código propio que llamara a `hwe_shipping_config()` debe dejar
  de hacerlo.
- **Correo transaccional de marca a N idiomas.** `woocommerce-email-branding.php` se retira:
  lo sustituyen `hwe-email-chrome.php` (marco, colores y fuentes del Control Center, texto por
  idioma con el filtro `hwe_email_copy` y enlaces al frontend con prefijo de idioma) y las
  plantillas de `themes/hwe-headless-base/woocommerce/emails/`. Los asuntos y encabezados de
  WooCommerce salen del diccionario en el idioma de la tienda (`brand.locale`) salvo que el
  admin haya escrito los suyos; el «contenido adicional» por defecto se vacía. Los enlaces de los
  correos de pedido apuntan a `/account`, `/account/orders/<id>` y `/reset-password` del
  frontend (antes, a páginas de WordPress que en headless no existen). Si tu frontend usa otras
  rutas, cambia las plantillas en tu tema o filtra los textos.
- **(contrato) Correos de acceso en el idioma del visitante.** `hwe/v1/auth/forgot-password`
  acepta `locale` y `reset_url` (mismo allowlist de orígenes que `frontend_url`) y
  `send-verification` acepta `locale`; los correos son HTML de marca. En el BFF,
  `/api/auth/{register,forgot-password,resend-verification}` aceptan `locale` en el cuerpo.
- **El registro ya no manda al cliente el correo nativo «Login Details»** (texto plano con un
  enlace a wp-login.php); el aviso al admin se conserva.
- **El botón «Enviar email de prueba» del Control Center enviaba el formulario de
  configuración** (estaba anidado dentro de él). Ahora va en su propio formulario, deja elegir
  cualquier plantilla (pedido de ejemplo en memoria o el último real) y tiene vista previa.
- **Restablecer contraseña no funcionaba.** La página pasaba la clave del enlace en la prop
  `key` del formulario, que React se reserva: se enviaba `key: undefined`. Ahora es
  `resetKey`. Si tu frontend reutiliza `ResetPasswordForm`, cambia la prop.
- **(contrato) Verificar el email ya no exige sesión.** `POST /api/auth/verify-email` y
  `hwe/v1/auth/verify-email` identifican al usuario por el token (64 hex; otro formato es 422);
  reabrir un enlace ya usado responde 200 con `already_verified`. La página quita el token de la
  URL antes de que lo vean las analíticas y tiene `noindex`.
- **Login y registro por teléfono, apagados por defecto** (`auth.phone_login_enabled`, nuevo
  grupo `auth` del Control Center con `phone_default_dial_code`). Con el flag, el «usuario» del
  login puede ser el teléfono E.164 de la cuenta y el registro acepta `phone`; el teléfono es el
  `billing_phone` y es único. Endpoints internos nuevos (`hwe/v1/auth/{phone-available,
  resolve-login,attach-profile}`), solo con el secreto interno y el flag.
- **El registro pide confirmar la contraseña** (componente `PasswordField`, compartido con
  restablecer y cambiar contraseña).
- **Seguridad crítica: el webhook de la pasarela de pruebas `noop` marcaba pedidos pagados en
  cualquier entorno.** Firmaba con un secreto público (`noop-sandbox-secret`) y se atendía
  siempre. Ahora solo existe si `noop` es la pasarela activa; en producción sin pasarela
  decidida no hay ninguna (antes, `noop` por defecto). Actualiza ya.
- **(contrato) Pasarela y credenciales desde el Control Center** (`hwe/v1/payments/credentials`,
  caché en memoria del proceso; `PAYMENT_PROVIDER` del entorno solo como anulación). Wompi y Aval
  Pay de punta a punta; `PaymentProvider` exige `isSandbox()`; el registro es asíncrono
  (`getActiveProvider()`). Aval Pay toma referencia, estado e importe de la consulta de la
  sesión (la referencia de la notificación no va firmada); Wompi exige que la firma cubra id,
  estado e importe. Un fallo a medias del webhook ya no deja el evento marcado como duplicado.
- **`POST /api/store/checkout` solo acepta los tres medios del checkout** (`hwe_offline`,
  `hwe_whatsapp_link`, `cod`), cada uno si está activo en Control Center → Pasarelas de pago;
  cualquier otro `payment_method` responde 422 (antes se reenviaba tal cual a la Store API: se
  podía crear un pedido con transferencia o con efectivo apagado). Un frontend propio debe mandar
  el gateway que corresponde a `enabledPaymentChoices()`. Con link por WhatsApp el pedido queda
  `on-hold`.
- **`POST /api/payments/create` responde 409** si el pedido no es de pago en línea
  (`hwe_offline`) o el pago en línea está apagado: ya no se puede iniciar un cobro en la pasarela
  para un pedido de efectivo o de WhatsApp.
- **`CheckoutForm` recibe `paymentChoices` y `gatewaySandbox`** (la página los calcula en el
  servidor). Si tu instancia sustituyó el formulario, mantén el mismo contrato: elegir medio,
  enviar su gateway y avisar del modo de pruebas. Los textos nuevos están en `checkout.*`.
- **`POST /api/store/checkout` respeta `ecommerce.checkout_login_required`** (por defecto,
  encendido): sin sesión responde 401. Antes aceptaba invitados sin mirar el flag, pero el
  cliente no podía pagar (el cobro exigía sesión). Con el flag apagado la compra de invitado
  funciona de punta a punta: cookie httpOnly `hwe_guest_order` con la `order_key`, que autoriza
  `/api/payments/{create,return}` y `/checkout/return`. Si tu instancia tenía un checkout de
  invitado «a medias», enciende o apaga el flag según lo que quieras.
- **El pedido de invitado se liga a la cuenta al abrir sesión** si el correo coincide
  (`login`, `verify-2fa`, `register`). Riesgo residual conocido: en un dispositivo compartido,
  quien registre una cuenta con el correo de un pedido de invitado reciente (cookie de 7 días)
  lo recibirá en «Mis pedidos»; el correo de la cuenta nueva no está verificado aún.
- **El carrito va ligado a la cuenta** (el JWT se reenvía a la Store API): al iniciar sesión
  prevalece el carrito de la cuenta y el del navegador se descarta; `logout-all` lo vacía.
  `GET /api/store/cart` quita o reduce las líneas sin existencias.
- **Checkout: guard de envío y medios frente a Woo.** Un carrito que necesita envío y no tiene
  tarifa responde 422 (antes se creaba con envío 0). Efectivo o WhatsApp que Woo no ofrece para
  el carrito: 422; el pago en línea cae a otro medio de cobro, nunca a efectivo. El rate-limit
  del checkout es estricto (cae a memoria si Redis no responde).
- **Errores de WooCommerce como texto plano** en `handleApiError` (entidades HTML decodificadas,
  sin etiquetas).
- **`/checkout/return`** acepta al invitado con su credencial, no se indexa, repinta cuando el
  webhook confirma el pago y ofrece «Pagar ahora»; el formulario del checkout ya no muestra el
  resultado en línea: siempre termina ahí (`CheckoutForm` recibe `customer`, `guest` y
  `defaultCountry`; ver `docs/FRONTEND_CONNECT.md`). Si tu instancia sustituyó esas vistas,
  conserva el contrato.
- **Los tests `E2E_FULL` mandan `payment_method: "hwe_offline"`** y leen `csrfToken` (antes
  `noop` y `token`: el spec estaba roto).
- **Nuevo módulo `site-reviews`** (plugin regular `hwe-site-reviews`, ver su `README.md` y
  `MANUAL.md`): el cliente califica su pedido completado (una vez, solo el dueño). Activa el
  módulo con `modules: ["site-reviews", …]` en `instance.config.json` o desde Plugins. Con el
  módulo apagado, `POST /api/store/orders/[id]/review` responde 404 y la cuenta no ofrece
  calificar. Su tag ISR `site-reviews` lo admite `/api/revalidate`.
- **Las reseñas de producto exigen una compra completada y salen publicadas.** Antes
  `POST /api/store/reviews/[productId]` aceptaba a cualquier cliente con sesión y las dejaba en
  moderación (`hold`). Ahora: 403 sin compra, 409 `not_received` con el pedido aún en
  preparación («Recibí mi producto»), 409 si ya reseñó, y se crean `approved` + `verified` (la
  compra es el filtro). Si tu instancia modera, cambia `status` a `hold` en la ruta y en
  `lib/reviews/publish-product-reviews.ts`. `GET` de la misma ruta devuelve la elegibilidad
  (caché por usuario y producto en Redis, 3 min, fail-open).
- **Cuenta:** historial con enlace a cada pedido y estado traducido; en el detalle, «Volver a
  pedir», «Recibí mi pedido», «Calificar pedido», «Pagar ahora» y ayuda por WhatsApp
  (`social.whatsapp`). Textos nuevos en `account.*` y `products.review*`. `getCustomerOrders`
  pagina (`page`, `perPage`) y ordena los más recientes primero.
- **Claves de i18n nuevas** en `account` (`orderActions`, `orderStatus`, `orderReview`,
  `orderSupport`) y `products` (`reviewLogin`, `reviewNotPurchased`…). `products.reviewSubmitted`
  cambia de texto (ya no dice «pendiente de moderación»).
- **`.gitignore` versiona los plugins de módulo** (`hwe-<id>/` de `Modules::CORE_IDS`); los de
  una instancia siguen siendo `hwe-instance-*`.
- **Accesibilidad:** el título «Mi cuenta» del layout de la cuenta pasa de `text-gray-400` a
  `text-gray-500` (contraste AA).
- **Nuevo módulo `coupon-rules`** (plugin regular `hwe-coupon-rules`, con `README.md` y
  `MANUAL.md`): fecha de inicio y caducidad **inclusiva**, primera compra, «requiere sesión»,
  alcance y cupones públicos. **La caducidad inclusiva afecta a todos los cupones del sitio**
  mientras el plugin esté activo (antes, «caduca el 27» dejaba de valer a las 00:00 del 27).
  Tag ISR `coupons` (admitido por `/api/revalidate`).
- **`POST /api/store/coupons` usaba `cart/coupons`**, cuya respuesta es el cupón y no el carrito:
  nada más aplicar uno, el carrito quedaba roto en pantalla. Ahora usa `cart/apply-coupon` y
  `cart/remove-coupon`. Con `ecommerce.coupons_enabled` apagado, aplicar responde 403 (quitar
  sigue permitido). Un frontend propio que llamara directo a la Store API no cambia.
- **`completeStoreCart` añade `couponRef`** a las líneas (una consulta de catálogo, en caché de
  5 min) solo con el módulo y los cupones activos.
- **Textos nuevos** `coupons.*` y `cart.offers.*` (i18n). La ficha, la categoría, el carrito y
  el checkout muestran los avisos y «Cupones disponibles» solo con el módulo y `coupons_enabled`.
- **Pruebas:** `backend/patchwork.json` hace redefinible `time()`; el stub del CI sirve un cupón
  público y los pedidos del cliente de prueba.
- **Analítica por `config.integrations`.** Los servicios (GA4, GTM, Plausible, Meta Pixel y
  Conversions API, TikTok, HubSpot) se configuran en Control Center → Integraciones y cargan tras
  «Aceptar». **Se retiran `NEXT_PUBLIC_ANALYTICS_PROVIDER`, `NEXT_PUBLIC_GA_ID`,
  `NEXT_PUBLIC_PLAUSIBLE_DOMAIN` y `NEXT_PUBLIC_PLAUSIBLE_HOST`**: pasa `analytics_provider` y
  `analytics_id` (el `G-…` o el dominio de Plausible) al panel. Quedan `PLAUSIBLE_HOST` (script
  autoalojado) y `CSP_ANALYTICS_PROVIDERS` (estrecha la CSP; por defecto autoriza todos los
  proveedores).
- **`trackEvent` cambia de módulo y de firma:** ahora es
  `import { trackEvent } from "@/components/analytics/track-event"` y recibe un evento de
  e-commerce con la forma de GA4 (`trackEvent("purchase", purchaseParams(...))`); antes
  `trackEvent(nombre, props, revenue)` desde `analytics-provider`. `PurchaseTracker` recibe
  `params` en vez de `orderId`/`amount`/`currency`. `AnalyticsProvider` requiere la prop `config`.
- **La compra solo se mide si el pedido está pagado EN LÍNEA** y sale también desde el servidor
  (Conversions API de Meta) tras el webhook verificado. El checkout guarda el contexto de
  medición en el pedido (`tracking` en `POST /api/store/checkout`; metas `_hwe_tracking_*`).
- **Todo `<form>` lleva `data-hs-do-not-collect`** (HubSpot recoge formularios que no son suyos
  si el portal lo tiene activo); el de contacto no. Un test lo exige a los formularios nuevos.
- **El banner nombra a los terceros configurados** (`consent.message` pasa a ICU con `kind`,
  `providers` y un enlace a `/legal/cookies`). Sin servicios configurados ya no se pide nada.
- **No se porta `content-category`** (la línea de negocio del producto es de cada instancia):
  `item_category` es opcional en `AnalyticsItem` y la rellena quien arma los parámetros.
- **SEO por entidad unificado (`hwe-seo/fields.php`).** Entradas, páginas, productos y
  categorías comparten caja, límites (título 60, descripción 160), contador y columna
  «SEO». Los campos del formulario de la entrada pasan de `hwe_seo_title`/`hwe_seo_description`
  a `hwe_post_seo_title`/`hwe_post_seo_description` (solo afecta a quien enviara ese
  formulario a mano). Las metas (`_hwe_seo_title`, `_hwe_seo_description`) no cambian.
- **`graphql-protection.php` ya no declara el filtro `graphql_cache_active`** (era código
  muerto: lo lee wp-graphql-smart-cache, que no se instala, y el BFF consulta por POST) y
  `setup.sh` deja de escribir la opción `graphql_cache_section`. Las consultas rechazadas se
  registran con el prefijo `[hwe] graphql:` (antes `[HWE GraphQL]`) y una sola vez por resultado.
- **Las pasarelas `hwe_offline` y `hwe_whatsapp_link` siempre están disponibles en la Store
  API**: son con las que el BFF crea el pedido. En producción, wp-admin avisa si COD o BACS
  siguen activos (salvo el efectivo activado desde el Control Center).
- **Panel en su propio host (`siteurl` ≠ `home`):** la API REST de wp-admin y las
  redirecciones de las acciones del panel se quedan en el host del panel. La prueba de SMTP
  vuelve a `admin.php?page=hwe-config`. Los fallos de envío se registran como `[hwe] smtp:`.

- **Nuevo módulo `consent-log`** (plugin regular `hwe-consent-log`, con `README.md` y
  `MANUAL.md`): registro de cada decisión del banner como prueba (tabla `wp_hwe_consent_log`,
  IP truncada, texto enseñado por hash, versión de la política), medición anónima de la respuesta
  al banner (Redis del BFF), historial en el perfil del usuario, exportador y eliminador de datos
  personales de WordPress y purga por años (Control Center → Integraciones). Pantalla: Ajustes →
  «Consentimientos de cookies». Rutas del BFF: `POST /api/consent`, `/api/consent-stats` (ver
  `FRONTEND_CONNECT.md`); apagado, ambas responden 404.
- **El valor de `localStorage["cookie-consent"]` pasa de cadena a JSON** y sube la generación a 2:
  **todos los visitantes verán el banner otra vez** al actualizar (la cadena suelta de la v1 se
  descarta, no se migra: no había comprobante ni alcance que conservar). Una instancia con su
  propio banner debe leer/escribir con `consent-storage.ts`, no con la cadena.
- **«Aceptar» cubre los servicios que el banner nombraba.** Añadir un servicio en Control Center
  repregunta sin subir la generación; quitar uno no.
- **`hasValidInternalSecret`** (`lib/security/internal-secret.ts`): comparación en tiempo
  constante del `X-HWE-Internal-Secret` para las rutas del BFF que solo atiende WordPress
  (`/api/consent-stats` GET). Quien comparaba el secreto a mano debe usarla.
- **Nueva cookie `hwe_consent`** (httpOnly, 400 días): el comprobante del dispositivo. Solo con el
  módulo; entra en la política de cookies de la instancia como cookie técnica de prueba.
- **Riesgo residual documentado:** el comprobante se vincula a la cuenta al iniciar sesión; en un
  dispositivo compartido, un comprobante ya ligado a otra cuenta **no** se reasigna (queda en la
  primera), de modo que las decisiones de una persona no se atribuyen a otra.

- **SEO/GEO completo.** `lib/seo/` gana `title`, `meta-description`, `opengraph`, `page-metadata`,
  `breadcrumbs`, `listing`, `safe-json-ld` y `og-static`; `jsonld` suma `Article`/`BlogPosting`,
  `CollectionPage`, `LocalBusiness` por sede, `VideoObject`, `Service` y el fundador como entidad
  del `@graph`. **Rompe:** `buildSiteGraph(config, locale, options?)` exige el idioma;
  `buildPersonJsonLd(config)` desaparece (usa `buildFounderPageJsonLd(config, pageUrl)`);
  `renderOgCard(config, locale)` exige el idioma; `alternatesFor`/`hreflangLanguages` aceptan
  `{ locales }` (idiomas con traducción real); `localizedPath("/", "en")` es `/en`, no `/en/`.
- **El layout raíz ya no declara `alternates`.** Antes toda página sin canónica propia (las privadas,
  los legales, la 404) salía con la de la home. La home la declara ahora ella misma. Una página
  propia de la instancia que dependiera de la heredada debe usar `pageMetadata`/`alternatesFor`.
- **Sitemap segmentado:** `app/sitemap.ts` desaparece. `/sitemap.xml` es un índice y los ficheros
  están en `/sitemap/{paginas,catalogo,blog}.xml`. Sin `lastmod` inventado (solo con fecha real) y
  vacío con la config degradada. Vuelve a dar de alta `/sitemap.xml` en Search Console si lo
  habías dado de alta por segmentos propios.
- **`/robots.txt`:** el `Content-Signal` usa `ai-input` (la señal `ai-retrieval` que emitía la v1 no
  existe en el borrador y un parser puede descartar la línea). Permite `/api/og` y `/api/icon`.
- **`/llms.txt` por idioma** (`/en/llms.txt`); 404 con la config degradada. Namespace `llms.*`
  nuevo en i18n.
- **Config:** campo nuevo `seo.business_type` (tipo schema.org de las sedes; `LocalBusiness` por
  defecto). `fetchRemoteConfig` pasa a 3 intentos con plazo (`CONFIG_TIMEOUT_MS`, 5 s) y devuelve
  una config marcada (`isFallbackSiteConfig`) si WordPress no responde.
- **Seguridad:** el JSON-LD de la FAQ (texto del CMS) se serializaba con `JSON.stringify` y un
  `</script>` en una respuesta cerraba el bloque; ahora todo JSON-LD pasa por `safeJsonLd` y un
  test lo impone.
- **Vistas privadas** declaran `noindex` por sí mismas (cuenta, carrito, checkout, login,
  registro, recuperación y verificación), no solo por `Disallow`. Las vistas derivadas de un listado
  (`?search=`, `?category=`, `?after=`…) van `noindex, follow` y sin `alternates`.
- **Tarjetas sociales estáticas (opcional):** `npm run og:generate` escribe `public/og/*.png` y
  `src/instance/og-cards.json`; `src/instance/registry.ts` añade `ogCards`. Sin ellas, la tarjeta
  dinámica de marca.

- **Blog:** `getPosts` acepta `tag`, `author`, `category` y `search`; `getPostSlugs` recorre todas
  las páginas (antes solo las 100 primeras); `getPostBySlug(slug, locale)`; nuevos
  `GET /api/blog/posts` y las vistas `/blog/archive` y `/blog/category/[slug]`. Las consultas de
  entradas piden ahora `tags`, `categories`, `author.slug`, `dateGmt` y `modifiedGmt`. La
  paginación por «Cargar más» (`?after=`) del listado pasa a scroll infinito; `?after=` sigue
  funcionando (`noindex`). `PostCard` ya no es un `<a>` que envuelve la tarjeta: el título es el
  enlace estirado y la categoría, el autor y las etiquetas son enlaces propios.
- **Marcador de recomendación en el cuerpo:** `<!-- hwe-rec type="product|post" slug="…" -->`.
- **La caja SEO del editor (`seoTitle`, `seoDescription`) llega por fin al `<title>` y a la meta
  description** de fichas, categorías de producto y entradas: la query ya la pedía pero la
  normalización la descartaba. Si tu instancia tenía escritos títulos en esa caja, cambian en la
  web al actualizar.
- **`i18n/navigation` exporta `permanentRedirect`.**

- **El cliente GraphQL tiene plazo y reintentos.** Cada intento corta a los 10 s
  (`GRAPHQL_TIMEOUT_MS`) y se reintenta hasta 3 veces un fallo de red, un plazo vencido o un 5xx;
  una mutation no se reintenta. Nuevo `kind: "timeout"` en `GraphQLClientError`. Un código que
  distinguía `network` de otros fallos debe contemplar `timeout`.
- **`brand.icon_url`** (calculado en el backend, no almacenado): el icono del sitio de WordPress.
  El favicon, el `apple-touch-icon` y los iconos de la PWA lo usan si se puede servir (mismo
  origen de WordPress, imagen raster de menos de 1 MB) y, si no, el generado desde la marca.
- **`CmsImage`** (`components/ui/cms-image.tsx`) es la vía de las imágenes del CMS: reescribe la
  URL de medios al origen interno (`WORDPRESS_INTERNAL_API_URL`) para que el optimizador de
  `next/image` la alcance dentro de Docker, y sirve una variante móvil como UN `<picture>`. El
  origen interno es de servidor: en un Client Component la URL se deja tal cual. La reescritura
  solo toca medios del WordPress propio (en Siu, cualquier host con una ruta de uploads se
  desviaba a la red interna). `images.qualities` pasa a `[75, 90]` y se autoriza el origen
  interno en `remotePatterns`.
- **`PRERENDER_LONG_TAIL=none`** deja fichas y entradas para ISR bajo demanda. El defecto
  (`all`) conserva el comportamiento anterior.
- **`?return=` en login y registro** vuelve a la ruta de origen (solo rutas internas; se rechazan
  URLs absolutas, `//`, `\\`, esquemas, `/api` y cualquier carácter de control: un tabulador
  dentro de la ruta se reconstruye como protocolo relativo en el navegador). El checkout sin
  sesión y la invitación de «gracias» lo usan. `next-intl`: `permanentRedirect` se exporta de
  `@/i18n/navigation`.
- **Mensajes de i18n:** el cliente recibe solo los namespaces que usan los Client Components
  (`lib/i18n/client-messages.ts`, con un test que lo impone). Un Client Component nuevo que lea
  un namespace nuevo debe añadirlo a `CLIENT_NAMESPACES`; los fallos de next-intl se registran
  (`i18n.message_error`).
- **Nuevo:** `lib/http/fetch-retry` (lecturas del CMS con reintentos; lo usan los banners),
  `lib/youtube`, `lib/validation/{email,form}`, `lib/prerender-budget`,
  `scripts/static-generation-options.mjs`, `lib/wordpress/media`.

### Infraestructura y CD (F4)

- **El `Dockerfile` del frontend exige `--build-arg WORDPRESS_PUBLIC_URL`** y falla pronto sin
  ella (antes construía con el valor por defecto y la imagen servía una CSP que autorizaba
  `http://localhost:8080`). El workflow de CD y `docker compose … build frontend` ya la pasan
  (CD: variable de repositorio `WORDPRESS_PUBLIC_URL`). Otros args que el build congela:
  `NEXT_PUBLIC_SITE_URL`, `CSP_ANALYTICS_PROVIDERS`, `SENTRY_DSN`.
- **`npm run build` ya no type-chequea las pruebas** (`tsconfig.build.json`): algunas importan
  archivos de fuera de `frontend/` y el contexto de Docker no los trae. `npm run type-check`
  (CI) sigue cubriéndolas. `frontend/public/` existe (la imagen lo copia) y `.dockerignore` deja
  de excluir `tests/` por el mismo motivo.
- **`postinstall` parchea Next** (`scripts/patch-next-isr-headers.mjs`): en un MISS, una redirección
  dentro de una página ISR salía con `Location` duplicado (reproducido en Next 16.4.0). El script
  comprueba la versión EXACTA y **falla la instalación** si `next` cambia: hay que volver a
  reproducir el problema y, si ya no ocurre, retirar el script y su test.
- **`docker-compose.prod.yml`:** servicio `wp-cron` (con `DISABLE_WP_CRON`; sin él los eventos
  programados solo corrían con visitas al panel), volumen `wordpress_core` compartido con
  `wpcli`/`wp-cron` y, en solo lectura, `caddy`; MariaDB con buffer pool configurable;
  `frontend.build` con los args; `GRAPHQL_TIMEOUT_MS`/`CONFIG_TIMEOUT_MS` de runtime
  (25 s / 15 s); volumen `next_image_cache`; `--save 900 1` en Redis. **Tras actualizar:**
  `docker compose … up -d` recrea los servicios cambiados y `docker compose … run --rm wpcli`
  no hace falta de nuevo. Define `WORDPRESS_PUBLIC_URL` en `.env.prod` si difiere de
  `https://<PUBLIC_DOMAIN>`.
- **Seguridad — `wp cache flush` ya no borra el Redis del frontend.** WordPress y el BFF comparten
  Redis (revocación de tokens, rate-limit, idempotencia, 2FA); «Flush cache» de Redis Object Cache
  hacía `FLUSHDB` y se llevaba todo, incluidas las listas de revocación (un token revocado volvía a
  valer). WordPress usa ahora `WP_REDIS_PREFIX='hwe-wp:'` y `WP_REDIS_SELECTIVE_FLUSH` (dev y prod).
  **Al actualizar, la caché de objetos de WordPress se rehace** (cambia el prefijo).
- **Caddyfile:** `/wp-content/*` lo sirve Caddy desde disco (los `.php` siguen yendo a Apache);
  el frontend reintenta la conexión 30 s durante el despliegue (antes 502); redirect opcional
  `/wp-admin` → `ADMIN_DOMAIN` y sitios opcionales en `caddy/conf.d/*.caddy`
  (`caddy/README.md`: el panel en `admin.<dominio>`).
- **Desarrollo:** `docker-compose.yml` monta los plugins propios (`hwe-banners`,
  `hwe-site-reviews`, `hwe-coupon-rules`, `hwe-consent-log`) y tiene `wp-cron`; antes `setup.sh`
  avisaba de que `hwe-banners` no existía.
- **CD:** exige la CI en verde para el commit, construye con los build-args, despliega con
  `up -d` (toda la pila), recarga Caddy, calienta la caché y comprueba rutas reales; `image_tag`
  en el disparo manual permite volver a una imagen ya publicada sin construir.
- **Nuevo:** `backend/scripts/warm-cache.sh`.

### Operación (F1)

- **Las copias automáticas de producción no funcionaban en la v1.0; ahora sí. Recrea el contenedor
  `backup`:** `docker compose -f docker-compose.prod.yml up -d --force-recreate backup`. Eran dos
  fallos encadenados, reproducidos con Docker: (1) el entrypoint hacía `chmod +x` sobre un script
  montado `:ro`, que falla siempre (EROFS) y, con `set -e`, dejaba el contenedor en **bucle de
  reinicios**; (2) con `cap_drop: ALL`, `crond` no puede lanzar ninguna tarea y la descarta en
  silencio. Ahora el job se lanza con `/bin/sh`, el servicio recupera solo `SETUID` y `SETGID`, y
  la salida de cada copia (resultado y fallos) va a `docker logs hwe_backup` en vez de a un archivo
  interno. Comprueba tras desplegar: `docker logs hwe_backup` y `ls backups/`.
- **Los scripts de copia y restauración ya no ponen la contraseña de MySQL en la línea de
  comandos** (`-p…` se veía en `ps aux` y `/proc/<pid>/cmdline`): va por `MYSQL_PWD`. Además
  vuelcan a un archivo intermedio y comprueban cada paso (en `/bin/sh` no hay `pipefail`, así que
  `mariadb-dump | gzip` daba por bueno un volcado que fallaba a medias), no dejan ninguna copia
  parcial si algo falla, y `restore.sh` verifica la integridad del volcado **antes** de pedir la
  confirmación destructiva. La retención ya no traga sus errores.
- **`backup.sh` y `restore.sh` leen el `.env` como datos, no con `source`**
  (`scripts/lib/load-env.sh`): un valor con espacios sin comillas ya no los rompe y una línea con
  `$(…)` ya no se ejecuta. Como en Docker Compose, una variable ya definida en el entorno gana
  sobre el `.env`.
- **Apache tiene techo de procesos en producción** (`backend/config/apache-mpm.conf`, montado en
  `conf-enabled/`): la imagen permitía 150 workers y los hijos no se reciclaban, así que bajo carga
  el contenedor moría por OOM. Va calibrado al límite de 512 MB de la plantilla (6 workers); si
  subes `deploy.resources.limits.memory` del servicio `wordpress`, sube también
  `MaxRequestWorkers` (el archivo explica la fórmula). Reinicia el contenedor `wordpress`.
- **Caddy manda cabeceras de seguridad en las rutas que sirve WordPress** (`/wp-admin`,
  `/wp-login.php`, `/wp-json`, `/wp-content`), que no pasan por Next: HSTS, `X-Content-Type-Options`
  y `Referrer-Policy` con el mismo valor que `next.config.mjs`, y `X-Frame-Options: SAMEORIGIN`
  (el frontend sigue con `DENY`).
- **`setup.sh` ya no instala un plugin cuya descarga no coincide con su SHA-256.** Antes, con un
  hash distinto, instalaba igualmente desde la URL directa (JWT Auth y WooGraphQL), y
  wp-graphql-cors no se verificaba. Ahora: JWT Auth (imprescindible) **aborta**; CORS y WooGraphQL
  desde GitHub (opcionales) se omiten con aviso. Los hashes se pueden sobrescribir por entorno
  (`JWT_AUTH_SHA256`, `WOOGRAPHQL_SHA256`, `WPGRAPHQL_CORS_SHA256`) para subir de versión; cambia
  siempre versión y hash a la vez.
- **`generate-woo-keys.sh` no imprime las claves completas si ya las escribió** en
  `.env`/`frontend/.env.local` (solo una confirmación truncada); con `--print-only`, o si la
  escritura falla, sí las muestra.
- **Se elimina `BackupConfig::triggerNow()`**: un `exec()` sin llamadores y sin comprobación de
  capacidad. Un test impide que el código PHP propio ejecute procesos del sistema.
- **`.gitignore` cubre `.env.prod`, `*.tar.gz` y `/backups/`.** Si tu instancia versionó alguno,
  retíralo del índice (`git rm --cached`) y rota los secretos que contuviera.
- **Nuevo `backend/scripts/verify-security.sh`**: comprueba con tráfico HTTP real que la pila está cerrada
  (GraphQL/Store API/`hwe/v1/auth` → 404 sin el secreto, webhooks, login con 2FA sin tokens, cabeceras).
  Ejecútalo tras desplegar la v2 (ver `docs/SECURITY.md`); con `--strict` no omite nada en silencio.

### Tooling

- **CI:** nuevo paso `npm run audit:gate` (falla ante avisos `high`/`critical`, con una allowlist
  justificada en `frontend/scripts/audit-gate.mjs`), `npm run i18n:check` y el job `phpunit`.
  Las pruebas de PHP se ejecutan con `composer test` desde `backend/` (PHP 8.4).
- **ESLint:** se activa el preset `recommended` de `eslint-plugin-jsx-a11y`; el código de
  instancia con problemas de accesibilidad pasará a dar error de lint.
