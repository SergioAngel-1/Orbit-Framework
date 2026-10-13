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
> de migración de una instancia derivada— se redacta al final de la v2.0.0. Una entrada marcada **(contrato)**
> afecta a un frontend que llame directamente al BFF o a WordPress sin usar el de este repo.

### Seguridad (F1)

- **El BFF vuelve a poder leer y escribir en wc/v3.** WooCommerce solo acepta Basic con consumer
  key/secret cuando `is_ssl()` es true, y el BFF le habla por HTTP en la red interna
  (`http://wordpress:80`): sin `X-Forwarded-Proto: https`, **toda** llamada a wc/v3 (pedidos,
  cuenta, direcciones, reseñas y marcar pagado un pedido desde el webhook de la pasarela)
  respondía 401 `woocommerce_rest_cannot_view`, mientras GraphQL y la Store API (sin ck/cs)
  seguían funcionando. `wcFetch` manda la cabecera cuando `WC_API_URL` es `http://`. Un frontend
  propio que llame a wc/v3 por HTTP interno necesita lo mismo.
- **«Cerrar sesión en todos los dispositivos» y el cambio de contraseña cierran de verdad las
  demás sesiones.** El plugin WPGraphQL JWT Authentication (0.7.0) rotaba el secreto del
  usuario pero **nunca lo comparaba** con el del refresh token: tras `logout-all`, el refresh
  token anterior seguía renovando sesión (duran 365 días). `hwe-auth.php` filtra ahora
  `graphql_jwt_auth_validate_token` y exige que el `user_secret` sea el vigente. Efecto al
  actualizar: los refresh tokens emitidos hasta hoy siguen valiendo (llevan el secreto actual);
  solo dejan de valer los que se rotan a partir de ahora.
- **Cambiar la contraseña funciona para los clientes.** El BFF la cambiaba con
  `POST wp/v2/users/<id>`, ruta que `security.php` retira (anti-enumeración) a quien no puede
  listar usuarios: un cliente recibía 404 y «No se pudo cambiar la contraseña». Ahora usa
  `POST hwe/v1/auth/change-password` (solo BFF y con sesión): WordPress verifica la contraseña
  actual y, tras 5 intentos fallidos, bloquea 15 minutos. **(contrato)** Un frontend propio que
  llame a `wp/v2/users/<id>` para cambiar la contraseña debe pasar a este endpoint.
- **Desactivar el 2FA funciona.** La ruta `PUT hwe/v1/auth/2fa-secret` declaraba `secret` como
  `string` y el BFF manda `null` para desactivar: WordPress respondía 400 siempre, con TOTP y con
  código de recuperación. Ahora acepta `string|null`.
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

- **Se retira `woocommerce-shipping-config.php`, y con él los campos del grupo `shipping` del
  Control Center** (`zone_name`, `flat_rate_cost`, `free_above`, `flat_label`, `free_label`):
  dejaron de aplicarse a WooCommerce y un campo que no hace nada engaña al editor. El grupo
  queda **reservado al módulo `shipping`**, que añadirá los suyos (sin ellos, la pestaña «Envío»
  no se pinta). Mientras tanto, configura las tarifas en WooCommerce → Ajustes → Envío; la regla
  de envío gratis sigue en `ecommerce.free_shipping`. Quita la clave `shipping` de tu
  `instance.config.json` (se ignora) y no llames a `hwe_shipping_config()`.
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
  solo toca medios del WordPress propio (antes, cualquier host con una ruta de uploads se
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

### Traducción de contenido (F5)

- **Nuevo mu-plugin `hwe-content-i18n`** sustituye a `hwe-content-translation`,
  `hwe-slug-translation`, `hwe-category-translation`, `hwe-tag-translation` y
  `hwe-blog-category-translation` de las instancias derivadas del framework v1: **borra esos cinco archivos**
  de `mu-plugins/` al actualizar. Las metas (`_hwe_title_en`, `_hwe_slug_en`, `_hwe_name_en`…) son
  las mismas: no hay migración de datos.
- **(contrato) Nombres de GraphQL.** Los campos por idioma (`titleEn`, `excerptEn`, `contentEn`,
  `seoTitleEn`, `seoDescriptionEn`, `nameEn`, `shortDescriptionEn`, `descriptionEn`, `slugEn`)
  desaparecen. Pasan a `translation(locale: "en") { title excerpt content seoTitle seoDescription
  name shortDescription description slug }` en cada tipo, con los mismos valores y null cuando no hay
  traducción. `RootQuery.hweSlugFromEn(postType, slugEn)` pasa a
  `hweSlugFrom(postType, locale, slug)`, que además acepta una taxonomía (`product_cat`). Las queries
  del frontend llevan marcadores `# @translation` (`lib/i18n/translation.ts`).
- **(contrato) Firmas del frontend.** `getProducts`, `getProductBySlug`, `getCategory`,
  `getCategories`, `getProductsByIds`/`BySlugs`, `getBlogCategories` y `getPostsIndex` reciben el
  idioma; `getProductSlugs()` se sustituye por `getProductUrls()` (slug por idioma y en cuáles
  existe) y `getPostSlugs()` devuelve además `slugs` y `locales`. Los enlaces a fichas pasan por
  `productHref`/`categoryHref`/`blogPostHref`. El navegador manda `X-HWE-Locale` al BFF.
- **El idioma sale de `i18n.locales`** (Control Center, sembrado por `wp hwe setup` desde
  `instance.config.json`) y debe coincidir con `frontend/src/i18n/routing.ts`; con un solo idioma el
  plugin no registra cajas ni campos de GraphQL.
- **El slug traducido es único entre idiomas**: no puede coincidir con el slug nativo de otra ficha
  del mismo tipo ni con el slug traducido (en cualquier idioma) de otra. Los datos que ya lo
  incumplan no se tocan; solo se rechaza el siguiente guardado.
- **Slug de categorías y etiquetas del blog: no se traduce** (como antes). El de las categorías de
  producto sí.
- **Se guarda con `wp_slash`.** El SEO por entidad (`_hwe_seo_title`, `_hwe_seo_description`) y las
  traducciones de términos perdían una `\` escrita a mano en cada guardado, porque `update_*_meta`
  hace `wp_unslash` del valor.
- **El tag `products` se acepta en `POST /api/revalidate` con la firma HWE**: guardar la traducción de
  una categoría de producto no dispara el webhook de WooCommerce, así que WordPress lo pide por esta
  vía. El plugin lo registra en `hwe_revalidation_tags`.

### Paquetes por país (F5)

- **Nuevo mu-plugin `hwe-geo`** (`GET /wp-json/hwe/v1/geo`, tag ISR `geo`, filtro `hwe_geo_packs`)
  con los paquetes `CO` y `generic`. Las instancias derivadas que traían tablas de un país en
  `lib/geo/*` las pasan a un paquete (`hwe_geo_packs`) y borran esos archivos.
- **(contrato) `checkout` y `customer` validan la dirección.** Con un paquete de lista cerrada
  (`CO`), `POST /api/store/checkout` y `PUT /api/store/customer` responden 422 si falta el
  departamento y no lo fija la ciudad, si no existe o si no corresponde a la ciudad; antes pasaba
  cualquier texto y WooCommerce lo rechazaba después. El `state` se envía como código (`CO-ANT`).
  El formulario del checkout ahora pinta el campo `state` y manda `billing_address.state`: un
  frontend propio debe hacer lo mismo. Con el paquete genérico nada cambia (texto libre).
- **(contrato) Libreta de direcciones.** `addressSchema` y `addressUpdateSchema` desaparecen de
  `lib/validation/address`: se usan `buildAddressSchema(pack)` y `buildAddressUpdateSchema(pack)`. El
  país por defecto ya no es `ES` fijo sino el de la tienda (con el paquete genérico, el país es
  obligatorio).
- **(contrato) Firmas.** `CheckoutCustomerSeed` lleva `state`; `CheckoutForm` y `AddressManager`
  reciben `geoPack` (opcional: sin él, texto libre). El teléfono de la dirección se normaliza a E.164
  con el prefijo del paquete.
- **Decisión que diverge de la instancia de origen:** allí un departamento irreconocible caía en
  silencio a `CO-CUN` (Cundinamarca) y el de la tabla de ciudades pisaba el escrito. Aquí no se
  inventa nada: un valor inválido o incoherente es un error del campo.

### Módulo `customer-identity` (F5)

- **Nuevo plugin `hwe-customer-identity`** (módulo `customer-identity`, desde el mu-plugin de las
  instancias derivadas del framework v1): si tu instancia tenía `hwe-customer-identity.php` en
  `mu-plugins/`, **bórralo** y activa el plugin (`wp hwe setup` con `modules`). Las metas
  (`billing_document_type`, `billing_document`, `hwe_terms_accepted_at`, `_hwe_document_*`) son las
  mismas: no hay migración de datos.
- **(contrato) Los tipos de documento salen del paquete de país** (`documentTypes` de `hwe-geo`),
  no de una lista fija `CC/CE/PP/NIT`: `DOCUMENT_TYPES`, `isDocumentType` y el tipo `DocumentType`
  de `lib/validation/identity` desaparecen (el tipo es ahora un `string`, el código del paquete).
  Un país sin tipos de documento no pide documento. Para otro país, añade un paquete con
  `hwe_geo_packs`.
- **(contrato) Firmas.** `CheckoutCustomerSeed` lleva `identity`; `CheckoutForm` y `RegisterForm`
  reciben `identity?: { documentTypes }` y `ProfileForm` `identity?: { documentTypes, stored }`
  (omitido = el módulo está apagado). `resolveCheckoutIdentity` recibe la política
  (`identityPolicy`).
- **(contrato) Cuerpos.** `POST /api/store/checkout` y `POST /api/auth/register` aceptan `identity`
  (`{ documentType, documentNumber }`) y `terms_accepted`; `PUT /api/store/customer`, `identity`.
  Con el módulo encendido y un paquete con documentos, el checkout y el registro **los exigen**
  (422). `tracking`, `identity` y `terms_accepted` dejan de reenviarse a la Store API.
- **`/api/auth/register` consulta ahora `getSiteConfig()` siempre** (antes solo con teléfono), para
  saber si el módulo está encendido; el paquete de país solo se lee con el módulo encendido.

### Módulo `shipping` (F5)

- **Nuevo plugin `hwe-shipping`** (módulo `shipping`) sustituye al mu-plugin de tarifa plana y umbral
  global de las instancias derivadas del framework v1 (`woocommerce-shipping-config.php`: **bórralo** y
  activa el plugin con `modules`). Los CPT (`hwe_shipping_rate`, `hwe_shipping_method`) y sus metas son
  los mismos: no hay migración de datos.
- **(contrato) El país base es `ecommerce.country`** (Control Center), no el de WooCommerce → Ajustes →
  General: las tarifas por ciudad y el envío gratis solo valen para destinos de ese país.
- **(contrato) El alcance del envío gratis** (`GET /free-shipping/scope`) ya no trae `name_en` / `slug_en`:
  trae `translations: { <idioma>: { name, slug } }` para todos los idiomas secundarios del sitio.
  `normalizeFreeShippingScope` acepta cualquier idioma y las categorías enlazan con `categoryHref`
  (`/categories/<slug>`), no con una ruta fija.
- **Zona de reserva nueva:** el módulo crea y mantiene una zona de WooCommerce que cubre el país con un
  `flat_rate` (Control Center → Envío: `reserve_enabled`, `reserve_name`, `reserve_cost`). Sustituye al
  script de la instancia que sembraba esa zona. Si ya tienes una zona que cubre el país, apaga
  `reserve_enabled` o tendrás dos.
- **(contrato) Firmas.** `CheckoutForm` recibe `shipping?: { methods }` (omitido = módulo apagado);
  `syncAuthenticatedCart(authToken, userId?)`; `getCartRequestAuth` ya devolvía `userId`;
  `useCart().setShippingDestination`; `StoreCart` gana `shipping_address` y `extensions`.
- **(contrato) Cuerpos.** `POST /api/store/checkout` acepta `shipping_method_ids`. Nuevas rutas
  `PUT /api/store/shipping/destination` y `GET /api/store/free-shipping/scope` (404 con el módulo
  apagado). Una división que el paquete no reconoce en el destino es un 422; antes la instancia de
  origen caía en silencio a una por defecto.
- **Sin `getShippingCities`:** las ciudades salen del paquete de país.

### Módulo `banners` (F5)

- **Se adopta el modelo tipo × locación** de las instancias derivadas del framework v1 y se retira `Placements`:
  las posiciones `hero` / `middle` / `bottom` desaparecen. Un banner ya no tiene `_hwe_banner_placement`:
  tiene **tipo** (`carousel`, `band`, `allies`, `page_hero`, `landing`) y **locación** (`home` por
  defecto). Si tenías banners del plugin v1 (`hero` → `carousel`, `middle` → `band`), vuelve a crearlos
  eligiendo el tipo; el plugin de v1 no llegó a publicarse, así que no hay migración automática.
- **(contrato) Filtros.** `hwe_banners_placements` desaparece. Nuevos: `hwe_banners_banner_types`,
  `hwe_banners_locations`, `hwe_banners_slide_types`, `hwe_banners_size_guide`,
  `hwe_banners_field_hints`. `hwe_banners_secondary_locales` desaparece: los idiomas de las
  traducciones son los secundarios del sitio (`i18n.locales`, vía `hwe-content-i18n`), N idiomas en
  vez de uno.
- **(breaking) `mandala_presets` → `decor_presets`.** El filtro `hwe_banners_mandala_presets` pasa a
  `hwe_banners_decor_presets`, y en el frontend los presets se declaran en
  `InstanceRegistry.decorPresets` (clave → `{ src, invert? }`) en lugar de una tabla en el núcleo. Las
  **claves** deben coincidir en los dos lados (test de paridad). El JSON del slide pasa de `mandalaMode`
  / `mandalaSvg` / `mandalaPreset` a `decorMode` / `decorSvg` / `decorPreset`, y las etiquetas del admin
  a «visual decorativo».
- **Sin migración de datos:** los slides guardados con `mandala_mode` / `mandala_preset` /
  `mandala_svg` se leen con las claves nuevas (`LegacySlide`) y se reescriben al guardar el banner.
- **(contrato) Lector.** `getBannerPlacement(placement, locale)` desaparece: `getBanner(type, location,
  locale, { enabled })`, `getBanners(locale, location)`, `getPageHeroSlide` y `getLandingHeroSlide`. La
  API REST cambia a `GET /banners[/{type}]?location=&lang=` (un tipo inexistente es 404). `BannerSlide`
  gana `imageAlt`, `cta2`, `cta2Href`, `bannerType`, `location`, `type`, `decor*`, `showCopy`; pierde
  `placement`. La home usa `getBanner("carousel", "home", …)`.
- **Un slide se descarta solo si no tiene nada que pintar** (sin imagen, sin visual decorativo y sin copy),
  en el plugin y en el normalizador; un slide con titular y botones se sirve aunque aún no tenga foto
  (el carrusel del starter lo filtra: pinta fotos).
- **Revalidación por el núcleo** (`Revalidation::trigger('banners')`): ya no hay un POST firmado propio
  del plugin.
- Nuevo: página **Banners → Ayuda** con las medidas, `MANUAL.md` para editores y vistas «Landings» en el
  listado.

### Módulo `product-landing` (F5)

- **El plugin `hwe-product-landing` es ahora un módulo** (`product-landing`): se activa con `modules` en
  `instance.config.json` y el frontend lo sabe por `config.modules['product-landing']`. Apagado, el
  plugin no registra `Product.landing` y el frontend no lo consulta ni pinta nada.
- **(breaking) Los campos `*En` desaparecen del GraphQL.** `heroSubtitleEn`, `nameEn`, `titleEn`… se
  sustituyen por `ProductLanding.translation(locale: String!)` y, en cada fila de las listas
  (`components`, `qualities`, `adviceItems`), `translation(locale: String!)`: devuelven los campos
  traducibles en ese idioma, o nulo si no hay traducción. El frontend los pide con el marcador
  `# @translation ProductLanding: …` (`lib/landings/queries.ts`) y los lee con `pickTranslated`. Una
  consulta propia de la instancia que pida `*En` debe migrar a `translation`.
- **Sin migración de datos:** las traducciones de los campos escalares siguen en
  `_hwe_landing_<campo>_<idioma>` (el inglés de v1 es `_en`) y las de las listas en `i18n.<idioma>`
  dentro de la fila. Los idiomas son los secundarios del sitio (`i18n.locales`), N en vez de solo inglés:
  una traducción de un idioma que el sitio no declara no se guarda.
- **(contrato) Íconos por filtro.** El set neutro (12 claves) vive en `Fields::DEFAULT_ICONS`; una
  instancia lo amplía con `hwe_product_landing_icons` (clave en minúsculas → etiqueta) y declara los
  mismos dibujos en su frontend. `Fields::ICONS` desaparece (era una constante; usa `Fields::icons()`).
- **(contrato) Ruta y lectores.** La landing vive en `/products/[slug]/learn` (antes la ruta la ponía cada
  instancia). Lectores nuevos en `lib/landings/`: `getProductLanding(slug, locale)`,
  `getPublishedProductLandingSlugs()`, `getPublishedProductLandingLocales()`, `hasProductLanding()` y
  `getRelatedProductLandings()`. La landing se lee en su propia consulta (no dentro de la ficha), así que
  un WordPress sin el plugin no rompe la ficha.
- **Sin traducción no se anuncia el idioma:** una landing solo aparece en el hreflang y el sitemap de un
  idioma si tiene traducción de alguno de sus campos de peso (título SEO, subtítulo del hero, cuerpos
  educativo y «sobre el producto», texto de cierre). Sin ella la dirección existe y sirve el idioma por
  defecto con `lang` correcto.
- **(contrato) Revalidación.** El plugin pide el tag `products` por sí mismo (y lo declara en
  `hwe_revalidation_tags`); ya no depende solo del webhook `product.updated` de WooCommerce.
- **Arreglado:** `video_poster` no estaba en el formulario, así que cada guardado borraba la miniatura del
  video. El formulario ya la ofrece.
- **No se porta el atajo de publicación en lote** del Control Center de la instancia de origen: la
  publicación se hace con la casilla «Publicar landing» de cada ficha. El vídeo se enlaza a YouTube (la
  CSP del framework tiene `frame-src 'none'`): incrustarlo es una decisión de la instancia.

### Módulo `landings` (F5)

- **Nuevo plugin `hwe-landings`** (módulo `landings`): el motor de landings editoriales de las
  instancias derivadas, **sin** sus landings estáticas. Activa el módulo con `modules` en
  `instance.config.json`. El núcleo reserva el grupo raíz `landings` (vacío) en el Control Center, como
  `shipping`.
- **(breaking) `landing_pages` → `landings`.** El nodo raíz `landing_pages` (con `editorial`,
  `editorial_registry` y `product`) de una instancia derivada pasa a `landings.registry` (el texto
  `identificador | Nombre | tipo`) y `landings.editorial.<id>`. El identificador y su contenido se
  conservan: hay que mover la configuración guardada (o sembrarla de nuevo con `wp hwe setup`).
- **(breaking) Los campos `*_en` pasan a variantes por idioma.** `title_en`, `body_en`, `cta_label_en`…
  se sustituyen por `<campo>_<idioma>` para cada idioma de `i18n.locales` (`title_en`, `title_pt_br`…).
  La URL por idioma pasa de `url.path_en` a `url.path_<idioma>` (el de `en` se llama igual). Desaparece
  `image_en` (una imagen por idioma): las imágenes no se traducen.
- **(breaking) `url.base`: `learn` → `area`.** El valor `root` no cambia. El área sigue siendo
  `/learn/<tramo>`; antes el nombre público del área lo fijaba la instancia.
- **(contrato) Landings con diseño propio.** Las declara la instancia con `hwe_landings_static_entries`
  en el backend (`id => ['label', 'jsonld', 'base']`) y con `instanceRegistry.landings.staticEntries` en
  el frontend: `STATIC_EDITORIAL_ENTRIES` y los mapas de tu `registry.ts` pasan a ser DATOS del registro
  (`uniqueSections`, `consumesBase`, `faqAside`, `hints`). Un identificador del panel que choque con una
  estática se ignora.
- **(breaking) Frontend.** `getEditorialLanding` / `buildEditorialRegistry` leen `config.landings` (antes
  `config.landing_pages`); `EditorialUrlBase` es `"area" | "root"` (antes `"learn" | "root"`) y el href
  de la raíz es un `string` (`/<tramo>`), no un objeto tipado de `next-intl`. `pathEn` desaparece:
  `entry.paths[locale]` y `pathFor(entry, locale)` sirven N idiomas. `FAQ_ASIDE_SECTION`,
  `UNIQUE_SECTIONS` por slug y `LEARN_PRODUCT_GROUP_ORDER` pasan a `entry.faqAside`, al mapa
  `unique-sections.tsx` de tu instancia y a `instanceRegistry.landings.productGroupOrder`.
- **(contrato) Tramos reservados.** La lista sale de `reserved-segments.ts` (antes de `routing.pathnames`,
  que el framework no usa). Si tu instancia añade una ruta de UN solo tramo bajo `app/[locale]/`,
  decláralo en `instanceRegistry.landings.reservedRootSegments`: el test
  `landings-reserved-segments.test.ts` falla si no.
- **Landing asociada a un producto.** Se sirve en `/products/[slug]/learn` (antes en la ruta de la
  instancia): precedencia landing de producto > editorial asociada > 404. `getProductBoundLandingSlugs`
  es la unión de los dos mecanismos y alimenta el índice y el sitemap.
- **(contrato) Tipos de campo.** `landing_registry` y `registry_type` salen del núcleo del Control Center
  y los registra el módulo (`hwe_control_center_field_types`). El tipo de una fila guardada se edita en
  el bloque de su landing y escribe en la misma fila del registro.
- **No se porta:** las secciones y datos propios de las landings de la instancia de origen (documentos
  descargables, tarifas, canales del hero, vídeo de la FAQ, grupos de productos de línea, categoría
  asociada y su botón); si tu instancia los necesita, añádelos con `hwe_control_center_schema` dentro de
  `landings` o en `instance`.
- **Locaciones de banner:** el módulo añade `landing-<id>` a `hwe-banners` por cada landing registrada
  (estáticas y del panel).
- **Revalidación:** tag `landings` (el frontend lo acepta desde esta versión).

### Módulo `external-integration` (F5)

- **Nuevo plugin `hwe-external-integration`** (módulo `external-integration`): sustituye a los mu-plugins
  de acceso y de guarda de GraphQL de un integrador concreto en las instancias derivadas (`hwe-*-access.php` y
  `hwe-*-graphql-guard.php`: **bórralos** y activa el módulo). El rol es `hwe_integration` (el mismo).
- **(breaking) Constantes y entorno.** `<INTEGRADOR>_ALLOWED_IPS` pasa a `HWE_INTEGRATION_ALLOWED_IPS`
  (compose, `.env` y `.env.prod`); la IP la sigue decidiendo `REMOTE_ADDR`. Los códigos de error de
  la cuenta de servicio pasan a `hwe_integration_ip`, `hwe_integration_graphql_only` y
  `hwe_integration_no_login`.
- **(contrato) La puerta de red exige el módulo.** `hwe_bff_only_is_integration_call()` (en
  `hwe-bff-only.php`) solo devuelve `true` si el módulo la enciende (`hwe_integration_gate_enabled`): sin
  él, una IP de la lista no abre GraphQL. La Store API y `hwe/v1/auth|consent` siguen cerradas también
  para ella. `hwe_bff_only_is_internal_call()` desaparece (el BFF se identifica con el secreto).
- **(contrato) Application Passwords.** `security.php` las deja apagadas salvo que un módulo las
  encienda con `hwe_application_passwords_enabled`; el módulo las limita a su usuario de servicio.
- **(contrato) Política por filtro.** Lo que antes eran constantes del archivo de la instancia
  (operaciones raíz, campos vetados, claves de `metaData`, estados sin pagar) es ahora el filtro
  `hwe_integration_policy`. Por defecto: leer pedidos (`order`, `orders`), `updateOrder` solo con el
  estado y solo las claves de seguimiento de `hwe-order-tracking`. **Suelos** que ningún filtro rebaja:
  `login`, `registerUser`, `users`, `customers`… nunca se abren; los campos vetados del defecto no se
  quitan; un pedido sin pagar solo se cancela.
- **(breaking) La lista por defecto es más estrecha:** `product` y `products` ya no se pueden leer
  (los productos de las líneas llegan por `lineItems`). Si tu integrador los lee, ábrelos con
  `$policy['queries'][] = 'products'`.
- **Script:** `backend/scripts/verify-integration.sh [tracking|gate|access|guard|all]` sustituye al
  específico del integrador.

### Campos de producto declarativos (F5)

- **Nuevo mu-plugin `hwe-product-fields`** (siempre activo; sin campos propios): sustituye a los mu-plugins
  de una instancia derivada que añadían una caja y un campo de WPGraphQL por dato de producto (modo de uso,
  composición, textos de la línea…). **Bórralos** y declara los campos con el filtro `hwe_product_fields`.
- **(breaking) GraphQL.** En lugar de un campo por dato (`Product.dosage`, `dosageEn`…), una lista
  `Product.hweFields(locale)` de pares `{ key, value }` ya resueltos al idioma. Las consultas de la instancia
  que pedían `dosage`/`dosageEn` migran a `hweFields(locale: $locale) { key value }` y a
  `product.fields.dosage` (tipado por *augmentation* de `ProductFieldValues`). Un `*En` desaparece: el
  idioma lo resuelve el campo.
- **Sin migración de datos:** la meta `_hwe_<clave>` y `_hwe_<clave>_en` es la misma que ya guardaban esos
  mu-plugins. Si la instancia usaba otra clave de meta, el campo declarado debe llamarse igual que el sufijo
  tras `_hwe_`.
- **(contrato) Claves reservadas:** `name`, `short_description`, `description`, `seo_title`,
  `seo_description`, `slug` y el prefijo `landing_`.
- **(contrato) Frontend:** `ProductDetail.fields` (mapa clave → texto, vacío si no hay campos) y
  `InstanceRegistry.productFields` (clave → `{ type, label? }`). La consulta `ProductBySlug` gana la variable
  `$locale`.

### Detalles de línea de pedido (F5)

- **Nuevo mu-plugin `hwe-order-item-details`** (siempre activo; sin detalles propios): sustituye a los
  mu-plugins de una instancia derivada que cableaban a mano la Store API para guardar un dato por línea
  (filtros `woocommerce_store_api_add_to_cart_data`, `woocommerce_get_item_data`,
  `woocommerce_checkout_create_order_line_item`…). **Bórralos** y declara el dato con
  `hwe_order_item_details`.
- **(contrato) Alta:** el dato viaja en `extensions.<clave>` de `cart/add-item`; el BFF acota claves y
  tamaños y WordPress valida con el validador declarado. Un valor inválido o ausente (si es obligatorio) es
  un 400 y no entra al carrito.
- **(contrato) Meta del pedido:** `meta_key` (visible, llega a los correos) y `_hwe_item_<clave>` (dato
  máquina). Si la instancia ya guardaba su dato con otra clave visible, decláralo con `meta_key` para no
  romper pedidos anteriores ni sus plantillas de correo.
- **(contrato) Frontend:** `cartApi.addItem(id, quantity, extensions?)`, `useCart().addItem` con el mismo
  tercer argumento y `StoreCartItem.item_data`.
- **No se porta** lo específico de una instancia (catálogos de una marca concreta): era una declaración, no núcleo.

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

- **Un backup que muere ya no deja el sitio sin copias.** `backup-cron.sh` guardaba su lock sin
  dueño: si el proceso moría (OOM, un manual interrumpido), el lock quedaba puesto y **todas** las
  corridas siguientes se omitían con «ya hay un backup en curso» —en la instancia donde se vio, 18
  días sin copia automática y sin ningún aviso—. Ahora el lock guarda el PID y se retira si el
  dueño no existe o si lleva más de 6 h (`HWE_BACKUP_LOCK_MAX_AGE_MIN`).
- **El contenedor `backup` monta la carpeta `backend/scripts`, no tres archivos sueltos.** Un bind
  mount de un archivo fija su inodo: `git reset --hard` lo sustituye y el contenedor seguía con la
  versión vieja (y sin la configuración del panel) hasta recrearse. Los scripts viven ahora en
  `/opt/hwe/scripts/` (antes `/usr/local/bin/`): actualiza cualquier comando propio. Tras
  actualizar, `docker compose -f docker-compose.prod.yml up -d backup` recrea el contenedor.
- **(contrato) La base de datos de producción es un volumen EXTERNO.** `db_data` era un volumen
  normal: `docker compose down -v` se llevaba la base de datos, y su nombre salía del nombre del
  proyecto, así que un `up -d` desde otro directorio (o con otro `-p`) creaba un volumen **nuevo
  y vacío** y arrancaba la tienda en blanco, sin error. Ahora es `external: true`, con el nombre
  `HWE_DB_VOLUME` (por defecto `hwe_db_data`): `down -v` no lo toca y, si no existe, `up` falla.
  **Antes de actualizar una instalación existente** pon en `.env.prod` `HWE_DB_VOLUME=<tu
  volumen actual>` (`docker volume ls | grep db_data`, suele ser `<directorio>_db_data`); sin
  eso, `up` falla con «external volume … not found» (no arranca vacío). En una instalación
  nueva: `docker volume create hwe_db_data` una vez. El compose de desarrollo no cambia.
- **`COMPOSE_FILE=docker-compose.prod.yml` en `.env.prod`.** Sin `-f`, `docker compose
  --env-file .env.prod …` usaba el compose de **desarrollo** sobre producción (db y wordpress
  recreados con otra configuración, puertos publicados). Añade la línea a tu `.env.prod`
  (no está en el repositorio); un `-f` explícito, como el del CD, sigue mandando.
- **El CD de producción hace una copia de la base de datos antes de desplegar** y se detiene si no
  hay un volcado de los últimos 15 minutos.
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
