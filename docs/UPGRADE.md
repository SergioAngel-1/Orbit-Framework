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

### Tooling

- **CI:** nuevo paso `npm run audit:gate` (falla ante avisos `high`/`critical`, con una allowlist
  justificada en `frontend/scripts/audit-gate.mjs`), `npm run i18n:check` y el job `phpunit`.
  Las pruebas de PHP se ejecutan con `composer test` desde `backend/` (PHP 8.4).
- **ESLint:** se activa el preset `recommended` de `eslint-plugin-jsx-a11y`; el código de
  instancia con problemas de accesibilidad pasará a dar error de lint.
