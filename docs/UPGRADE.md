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

### Tooling

- **CI:** nuevo paso `npm run audit:gate` (falla ante avisos `high`/`critical`, con una allowlist
  justificada en `frontend/scripts/audit-gate.mjs`), `npm run i18n:check` y el job `phpunit`.
  Las pruebas de PHP se ejecutan con `composer test` desde `backend/` (PHP 8.4).
- **ESLint:** se activa el preset `recommended` de `eslint-plugin-jsx-a11y`; el código de
  instancia con problemas de accesibilidad pasará a dar error de lint.
