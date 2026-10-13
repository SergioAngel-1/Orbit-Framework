# Hallazgos para Siu Tutuava

Defectos y riesgos que aparecieron al portar el código de Siu (`d008bc15`) al framework HWE v2.
Es la lista para **corregir en el repositorio de Siu**: el framework ya lo arregló (commit
indicado, con su test en rojo contra el código de Siu) o lo evita.

Se mantiene viva: cada tarea de la ejecución del plan añade aquí lo que encuentra.

**Cómo leerlo**

- *Verificado en Siu*: comprobado leyendo el árbol de Siu en `d008bc15` (se cita el archivo).
  *Reproducido*: además se ejecutó el código de Siu con una entrada que lo demuestra.
- *Según el commit*: lo afirma el mensaje del commit del framework que lo corrige; no se ha vuelto
  a comprobar en el árbol de Siu.
- Gravedad: **Alta** = explotable por un tercero con impacto de seguridad. **Media** = explotable
  con condiciones, o con impacto operativo/de dinero. **Baja** = endurecimiento o defecto menor.

> No incluye lo que Siu ya había corregido y el framework v1 no tenía (webhook `noop`,
> `returnUrl` de pagos, identidad de las reseñas, idempotencia del checkout, `CSRF_SECRET` sin
> reservas, cierre de `wc/store` en Caddy, GraphQL por `?graphql`, prefijo de Redis…): eso son
> brechas del framework, no de Siu.

---

## Alta

### S-01 · El saneado de SVG deja pasar XSS almacenado (reproducido)
- **Dónde:** `backend/wp-content/mu-plugins/hwe-svg-uploads.php`, `hwe_svg_sanitize_string`.
- **Qué pasa:** se ejecutó la función de Siu con cuatro SVG y los cuatro salen **intactos**:
  1. `xlink:href="javascript:alert(1)"` — se detecta pero se borra con `removeAttribute($attr->name)`;
     `name` es el nombre **local** (`href`), así que el atributo con namespace `xlink:href` no se
     encuentra y sobrevive.
  2. `xlink:href="java&#x09;script:alert(1)"` — `trim()` solo mira los extremos; el tabulador
     interior que deja la entidad lo quitan los navegadores al interpretar la URL.
  3. `xlink:href="JaVa&#10;Script:…"` — igual con un salto de línea.
  4. `<a href="#x"><set attributeName="href" to="javascript:…"/>` — una animación SMIL reescribe el
     `href` tras el saneado; `set`/`animate` no están prohibidos.
  En la subida solo hay un rechazo previo por expresión regular para la forma literal
  `xlink:href="javascript:` (sin entidades): no cubre los casos 2–4.
- **Efecto:** cualquiera con `upload_files` (autor, editor, el propio cliente) sube un SVG que
  ejecuta JavaScript en el dominio del sitio al abrirse, p. ej. contra un administrador.
- **Arreglo en el framework:** lista de permitidos para `href` (fragmento, http(s), relativa,
  imagen raster en `data:`), esquemas normalizados quitando controles y espacios antes de
  comparar, animaciones que reescriben `href` fuera y borrado por nombre completo (`nodeName`).
  Commit `3237cdc`. Con el código nuevo los cuatro casos salen limpios.
- **Para Siu:** aplicar el mismo saneado y **revisar los SVG ya subidos** a la medioteca.
- **Sin verificar:** la subida real de un SVG por wp-admin con el filtro de WordPress (ver el
  commit: no se probó en vivo).

### S-02 · Redirección abierta en `?return=` (tabulador embebido, reproducido)
- **Dónde:** `frontend/src/lib/navigation/safe-return.ts` (`safeInternalPath`).
- **Qué pasa:** `trim()` no ve un tabulador interior y ninguna regla lo rechaza.
  `new URL("/\t/evil.example", "https://site.test").href` es `https://evil.example/`: el navegador
  quita tabuladores y saltos de línea al interpretar la URL y lo reconstruye como URL con
  protocolo relativo.
- **Efecto:** `…/login?return=/%09/evil.example` envía a la víctima a un sitio ajeno tras
  autenticarse (phishing con el dominio legítimo en la barra).
- **Arreglo:** rechazar todo carácter de control (C0, DEL y C1). Commit `521b40c`, con test en
  rojo contra la versión de Siu.
- **Para Siu:** la misma regla; revisar cualquier otro sitio que use `safeInternalPath`.

### S-03 · 2FA: con el segundo factor activo, un token robado lo sustituye
- **Dónde:** `backend/wp-content/mu-plugins/hwe-auth.php`, `handleUpdate2faSecret` (verificado
  en Siu: la rama de activación no mira si ya hay un secreto activo).
- **Qué pasa:** activar exige un código TOTP válido **del secreto que se guarda**, no del que ya
  está activo. Con un access-token robado, el atacante envía su propio secreto y un código válido
  de él y deja el segundo factor de la víctima sustituido por el suyo.
- **Arreglo:** con el 2FA ya activo, registrar otro secreto responde 409; para cambiarlo hay que
  desactivar antes (lo que exige prueba del factor actual). Commit `939efa4`; el mensaje lo
  declara «divergencia respecto a Siu».
- **Para Siu:** la misma comprobación.

---

## Media

### S-04 · Aval Pay: la `reference` de la notificación no va firmada
- **Dónde:** `frontend/src/lib/payments/providers/avalpay.ts`, `verifyWebhook` (verificado en
  Siu).
- **Qué pasa:** la firma cubre `sessionId + status + date + secretKey`; la `reference` (el
  pedido) sale del cuerpo y no está firmada. El importe sí se confirma con la consulta
  autoritativa, pero se compara con el pedido que nombra la `reference`.
- **Efecto:** con una notificación firmada válida (de un pago propio barato), cambiar la
  `reference` por la de **otro pedido del mismo importe** lo marcaría pagado. Hace falta poder
  alterar o reenviar una notificación legítima, por eso es Media y no Alta; no se comprobó contra
  Aval Pay real.
- **Arreglo:** referencia, estado e importe salen de la consulta autoritativa de la sesión; si la
  notificación trae otra referencia, se rechaza. Commit `fa7cd8d`.

### S-05 · `GET /api/store/products?include=` devuelve productos sin publicar
- **Dónde:** `frontend/src/app/api/store/products/route.ts` (verificado en Siu: sin `status`).
- **Qué pasa:** consulta `wc/v3/products` con las credenciales del servidor y `include=<ids>`;
  WooCommerce devuelve por defecto productos de cualquier estado y el endpoint es público.
  Segundo defecto en la misma rama: `?include=abc` deja la lista vacía (`NaN` filtrado) y un
  `include` vacío no filtra nada, así que devolvía los primeros 100 productos de cualquier estado.
- **Efecto:** un anónimo lee nombre, precio y descripción de borradores, pendientes o privados.
- **Arreglo:** `status=publish`, solo enteros positivos sin duplicados (máx. 100), y sin ningún
  id válido responde lista vacía sin consultar. Commit `94608d8` (en el mensaje consta que Siu
  tampoco lo corrigió).

### S-06 · 2FA pendiente: sin tope de intentos, canje no atómico y fallos de Redis tragados
- **Dónde:** `frontend/src/lib/auth/pending-login.ts` y `api/auth/verify-2fa` (verificado en
  Siu: TTL de 300 s, `getPendingLogin` + `deletePendingLogin` por separado, tres `catch` mudos).
- **Qué pasa:** con la contraseña correcta, un TOTP de 6 dígitos se puede adivinar durante los 5
  minutos de vida del id, rotando de IP (el rate-limit por IP no lo frena). Además el canje es
  `get` y luego `del`: dos peticiones simultáneas con un código válido pueden abrir dos sesiones.
- **Arreglo:** tope de 5 códigos incorrectos por id (después hay que volver a iniciar sesión),
  reclamo con `GETDEL` atómico y los errores de Redis dejan `auth.pending_login.redis_error`.
  Commit `a99cfc7`.

### S-07 · Cambiar la contraseña deja fuera la sesión actual y oculta el fallo
- **Dónde:** `frontend/src/app/api/auth/change-password/route.ts` y `lib/auth/session.ts`
  (`revokeAllSessions`; verificado en Siu: devuelve `void`, el fallo es un `warn` y la ruta
  responde `{ ok: true }`).
- **Qué pasa:** `logout-all` rota el secreto JWT del usuario y eso invalida **todos** sus refresh
  tokens, también el del dispositivo que acaba de cambiar la contraseña: queda fuera en cuanto
  caduca su access token (~5 min). Y si la revocación falla, el usuario cree haber cerrado
  sesiones que siguen abiertas.
- **Arreglo:** tras rotar el secreto se reemite la sesión de este dispositivo con la contraseña
  nueva; la respuesta pasa a `{ ok, otherSessionsRevoked, sessionKept }`. Commit `9569815`.
- **Sin verificar:** el efecto sobre la sesión actual viene del mensaje del commit; la rotación
  real del secreto en WordPress necesita la pila (no se probó en vivo).

### S-08 · Desactivar el 2FA con un código de recuperación falla siempre
- **Dónde:** `frontend/src/app/api/auth/2fa/disable/route.ts` (verificado en Siu).
- **Qué pasa:** el BFF llama primero a `2fa-recovery/verify`, que **consume** el código; luego el
  `PUT 2fa-secret` de WordPress lo vuelve a verificar y consumir, lo encuentra gastado y responde
  403 (el BFF lo traduce a 502 «No se pudo desactivar 2FA»). El usuario pierde el código y sigue
  con el 2FA activo. Con el código TOTP sí funciona.
- **Arreglo:** el BFF reenvía el código sin verificarlo antes; WordPress lo verifica y consume una
  sola vez. Commit `939efa4`.

### S-09 · `SecretsStorage::setAll()` sin clave cifra todo a `''` y lo guarda
- **Dónde:** `backend/wp-content/mu-plugins/hwe-control-center/SecretsStorage.php` (verificado en
  Siu: `setAll()` → `update_option(…, encryptTree($data))` y `encrypt()` devuelve `''` sin clave).
- **Qué pasa:** con la clave mal configurada, un guardado cualquiera en el panel escribe `''` en
  todas las credenciales (pasarelas, SMTP): se destruyen cuando bastaba con restaurar la clave
  para leerlas.
- **Arreglo:** sin clave no se escribe nunca; `setAll()` devuelve `bool`. Commit `f0b430c`.

### S-10 · `/api/blog/posts` sin topes
- **Dónde:** `frontend/src/app/api/blog/posts/route.ts` (verificado en Siu).
- **Qué pasa:** `first` es `Number(…) || 9` sin máximo; `after`, `author`, `category`, `search`,
  `locale` y la lista de `tag` pasan tal cual a la consulta; la búsqueda no tiene rate-limit y
  cada pulsación es una consulta a WordPress.
- **Arreglo:** `first` ≤ 24, cursor solo base64, búsqueda ≤ 100 caracteres con 30/min por IP,
  etiquetas ≤ 10, slugs ≤ 200 y `locale` solo de los configurados. Commit `d113657`.

### S-11 · Copias de seguridad: `.env` leído con `IFS='='`, retención que traga errores
- **Dónde:** `backend/scripts/backup.sh` (verificado el `read` con `IFS='='`).
- **Qué pasa:** el parseo conserva las **comillas** y el `\r` de un `.env` con finales CRLF
  (`MYSQL_PASSWORD="abc"` se exporta con comillas → contraseña errónea y copia fallida sin causa
  evidente). La retención usa `2>/dev/null || true` y no valida `retain_days`.
- **Arreglo:** cargador propio con las reglas de Compose (`scripts/lib/load-env.sh`) y retención
  que no traga sus errores. Commit `a65a825`.

### S-12 · `hwe/v1/auth/*` y GraphQL deciden «es el BFF» por la red
- **Dónde:** `backend/wp-content/mu-plugins/hwe-bff-only.php` (verificado en Siu: sin
  `X-Forwarded-For` y `REMOTE_ADDR` privada; no hay credencial).
- **Qué pasa:** es una suposición, no una credencial: cualquier servicio de la red interna que
  llegue a WordPress sin `X-Forwarded-For` pasa por «el BFF» y se salta el 2FA, el CSRF y el
  rate-limit del BFF.
- **Arreglo:** secreto compartido `X-HWE-Internal-Secret` comparado en tiempo constante; la
  heurística de red queda detrás de `HWE_BFF_TRUST_PRIVATE_NETWORK`, desactivada. Commit
  `dcb6bac`.
- **Sin verificar en vivo:** el rechazo y la aceptación contra WordPress real (lo cubre
  `verify-security.sh`).

### S-13 · Aval Pay: `payments.avalpay.secret_key` sin descifrar en producción
- **Dónde:** producción de Siu. Lo dice el propio commit `318e77b0` de Siu (2026-09-22): «eso
  silencia el síntoma, no la causa: `payments.avalpay.secret_key` sigue sin descifrar, así que
  los pagos con Aval Pay están rotos hasta que se vuelva a guardar la credencial en HWE Control
  Center».
- **Para Siu:** volver a guardar la credencial y comprobar que `HWE_SECRETS_KEY` (o `AUTH_KEY`)
  es la misma que cifró el valor y no cambia entre despliegues.

---

## Baja

### S-14 · `wpMediaInternalUrl` reescribe cualquier host con ruta de `uploads/`
- **Dónde:** `frontend/src/lib/wordpress/media.ts` (verificado: solo comprueba el `pathname`).
- **Qué pasa:** `https://otro-host/wp-content/uploads/x.png` se reescribe al WordPress interno.
  Es una confusión de origen, no un SSRF explotable (el destino es siempre el WordPress interno y
  el valor viene del CMS): se sirve la imagen equivocada.
- **Arreglo:** solo se reescribe un medio del WordPress propio (origen público). Commit `521b40c`.

### S-15 · Verificación HMAC permisiva y `payload.id` sin validar
- **Dónde:** `frontend/src/lib/security/webhook.ts` (verificado en Siu) y rutas de webhook de
  pedido.
- **Qué pasa:** la firma se decodifica con `Buffer.from(firma, "hex"|"base64")`, que descarta la
  basura final, el relleno que falta y los saltos de línea: varias cadenas distintas pasan por la
  misma firma; el prefijo `sha256=` es opcional (una firma sin él se acepta); `payload.id` solo se
  comprueba con `typeof` y se interpola en la clave de Redis `order:status:<id>`.
- **Arreglo:** se comparan las cadenas codificadas, prefijo obligatorio si se configura, secreto
  vacío falla cerrado, `parseWebhookOrderId` (entero positivo). Commit `74d849d`.

### S-16 · El proxy exime los webhooks por prefijo
- **Dónde:** `frontend/src/proxy.ts`, `SERVER_TO_SERVER_PATHS` (verificado en Siu:
  `"/api/webhooks/"`).
- **Qué pasa:** un prefijo abierto exime en silencio cualquier handler que se añada después,
  verifique o no la firma.
- **Arreglo:** lista cerrada en `lib/security/signed-routes.ts` y un test que recorre los
  `route.ts`. Commit `0869def`.

### S-17 · `ALLOWED_ORIGIN` con formato inexacto deja toda escritura en 403 sin aviso
- **Dónde:** `frontend/src/lib/security/secret-guard.ts` (según el commit).
- **Qué pasa:** la comparación es igualdad estricta con el origen que envía el navegador (sin
  barra final, ruta, mayúsculas ni `:443`); cualquier otra forma bloquea TODAS las escrituras y
  nada lo explica.
- **Arreglo:** el guard lo explica («el navegador envía https://…») y el arranque lo rechaza.
  Commit `6c9d28c`.

### S-18 · Tokens de diseño: la fuente admite `\s`
- **Dónde:** `frontend/src/lib/config/tokens.ts` (`44bb5f5f` en Siu, según el commit).
- **Qué pasa:** la validación de la fuente admite `\s`; un salto de línea rompe la cadena CSS
  entre comillas del `<style>` inyectado.
- **Arreglo:** espacio literal y tope de 100 caracteres. Commit `a609b36`.

### S-19 · `deepMerge` de la config remota destruye un objeto por defecto con un `[]`
- **Dónde:** `frontend/src/lib/config/remote.ts`, `deepMerge` (verificado en Siu: un override
  array reemplaza al base sin mirar la forma).
- **Qué pasa:** PHP serializa un mapa vacío como `[]`, no como `{}`; con un campo tipo mapa vacío
  (p. ej. `"social": []`) el objeto por defecto desaparece y el frontend lee `undefined`.
- **Arreglo:** si la forma no coincide (objeto frente a array o escalar) manda el default y se
  registra `site_config.shape_mismatch` con la ruta. Commit `4a82a04`.

### S-20 · Las traducciones de términos y el SEO por entidad pierden las barras invertidas
- **Dónde:** `hwe-category-translation.php`, `hwe-tag-translation.php`,
  `hwe-blog-category-translation.php` y `hwe-{post,page,product,category}-seo.php` (verificado
  en Siu: ninguno llama a `wp_slash` antes de `update_term_meta` / `update_post_meta`;
  `hwe-content-translation.php` sí, con un comentario que explica por qué).
- **Qué pasa:** `update_*_meta` hace `wp_unslash` del valor. Un nombre, una descripción o un
  título SEO con una `\` escrita a mano (una ruta, un `\n` literal, una expresión) la pierde en
  cada guardado.
- **Arreglo:** `wp_slash` al escribir, en las traducciones nuevas y en `hwe-seo/fields.php`. Se
  reprodujo con un test en rojo contra el código anterior (`ContentSeoTest`).

---

## Avisos y riesgos residuales

### R-01 · Reclamo del pedido de invitado con correo sin verificar
`claimGuestOrder` liga el pedido a la cuenta cuando el correo del pedido coincide con el de la
cuenta, aunque esta no tenga el correo verificado. En un dispositivo compartido la cookie
`hwe_guest_order` dura 7 días. Está documentado como riesgo residual en `docs/UPGRADE.md`
(checkout). Mitigaría exigir `email_verified` o un enlace por correo. El reclamo viene de Siu;
no se ha comprobado aquí si Siu ya lo mitiga.

### R-02 · WordPress y el BFF comparten Redis
Con la misma base, `wp cache flush` hace `FLUSHDB` y borra la revocación de tokens, el
rate-limit y la idempotencia del BFF. El framework v1 lo tenía; Siu ya usaba prefijo y flush
selectivo (se confirmó al portar la tarea 49). Se anota por si hay otra instalación derivada de
la v1 sin el prefijo.

### R-03 · `GET /wp-json/hwe/v1/auth/2fa-secret` devuelve el secreto TOTP en claro
Verificado en Siu y en el framework (`handleGet2faSecret`). Lo necesita el BFF para verificar el
código (`verify-2fa`, `2fa/disable`, `2fa/activate`). Detrás de `hwe-bff-only` solo atiende con
el secreto interno **y** un token válido; si esa puerta se debilita (S-12), un access-token robado
entrega el secreto y con él se generan códigos. Valorar verificar el TOTP en WordPress y retirar
el endpoint.

---

## Revisado y no se considera defecto

- `dangerouslyAllowLocalIP: true` incondicional en `next.config.mjs`: es deliberado y está
  documentado (el optimizador de imágenes pide al origen interno, que resuelve a IP privada);
  `remotePatterns` sigue siendo la allowlist. El framework lo limita a «origen interno
  configurado o desarrollo», que en la pila Docker es lo mismo.
- La idempotencia del checkout, la validación Zod de wishlist/verify-email/2fa-disable y la
  identidad de las reseñas ya estaban corregidas en Siu; las brechas eran del framework v1.

---

*Última actualización: tarea 50 (F5).*
