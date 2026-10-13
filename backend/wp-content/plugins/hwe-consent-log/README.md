# HWE Consent Log (módulo `consent-log`)

Registro de consentimientos de cookies: la **prueba** de qué eligió cada persona en el banner,
cuándo y con qué texto. Va junto a la **medición anónima** de la respuesta al banner
(cuántos aceptan, cuántos solo necesarias, cuántos no responden).

## Qué hace

| Pieza | Detalle |
|---|---|
| Tabla `wp_hwe_consent_log` | Una fila por decisión: comprobante, cuenta (si había sesión), acción, decisión, categorías, servicios pedidos, hash del texto, versión de la política, generación, IP truncada, user-agent, ruta, idioma y variante. Solo crece. |
| Tabla `wp_hwe_consent_texts` | El texto que enseñó el banner, una vez por hash. |
| `POST /wp-json/hwe/v1/consent/record` | Anota una decisión. Solo con el secreto interno. El BFF la llama desde `POST /api/consent`. |
| `GET /wp-json/hwe/v1/consent/latest?receipt=` | La decisión vigente de un comprobante. El BFF la consulta antes de mandar una compra a la Conversions API de Meta. |
| `POST /wp-json/hwe/v1/consent/link` | Vincula el comprobante a la cuenta al iniciar sesión o registrarse. |
| Ajustes → «Consentimientos de cookies» | Medición anónima arriba, registro debajo (búsqueda por comprobante, email o id, y CSV). |
| Perfil de usuario | Historial de sus decisiones, incluidas las que tomó antes de iniciar sesión en ese dispositivo. |
| Privacidad de WordPress | Exportador y eliminador de datos personales: la supresión **desvincula** (no borra) para conservar la prueba. |
| Purga diaria | Años desde la última decisión de cada comprobante: Control Center → Integraciones → «Conservar el registro de consentimientos» (5 por defecto; 0 = no borrar). |

## Reglas

- **Solo escribe el BFF**, con `X-HWE-Internal-Secret` (`hwe_has_internal_secret`). Si el mu-plugin que
  lo valida no está, las rutas fallan cerradas.
- La IP **la trunca el BFF** (/24 en IPv4, /48 en IPv6) antes de llegar aquí; la ruta llega sin query.
- Un comprobante ligado a **otra** cuenta no se vincula (dispositivo compartido).
- Un `change` que pasa de aceptar a rechazar se guarda como `revoke`: lo decide este plugin con su
  propio registro, no el navegador.
- Una decisión de una **generación** anterior a la más alta del registro ya no vale: el banner
  volvió a preguntar (`CONSENT_GENERATION` en `lib/analytics/consent-storage.ts`).
- La medición anónima **no** vive en WordPress: la cuenta el BFF en Redis y esta pantalla la lee de
  `GET /api/consent-stats` (secreto interno, zona horaria del sitio).

## Contrato con el frontend

- Módulo `consent-log` en `config.modules` (activo = plugin activo). Con el módulo apagado:
  `/api/consent` y `/api/consent-stats` responden 404, el banner no pide ni guarda comprobante ni
  encola decisiones; el consentimiento sigue funcionando en el navegador.
- Contrato completo en `docs/FRONTEND_CONNECT.md` (§ Consentimiento).

## Pruebas

`backend/tests/Unit/ConsentLog/ConsentLogTest.php` (PHPUnit + Brain Monkey, `$wpdb` simulado).
