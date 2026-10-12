# Seguridad — Modelo de amenazas y guía de hardening

## Modelo de amenazas

El sistema asume un **atacante con capacidad de red** (MITM, CSRF, replay) y un
**atacante con acceso parcial al cliente** (XSS en otros sitios, pero no inyección
directa en el dominio de la tienda). No se protege contra un atacante con acceso al
servidor o que controle la red interna de la plataforma de despliegue.

| Amenaza | Mitigación | Dónde |
|---------|-----------|-------|
| Robo de credenciales WooCommerce | Proxy BFF, `server-only`, env sin `NEXT_PUBLIC_` | Fase 3 |
| XSS → robo de sesión | JWT en cookie `httpOnly`, CSP estricta | Fases 1,2 |
| CSRF en escrituras | Token firmado (double-submit) + verificación Origin | Fase 4 |
| Fuerza bruta en login | Rate-limit por IP + umbral por endpoint | Fase 4 |
| Duplicación de pedidos | Clave de idempotencia (Redis) | Fase 4 |
| Pago falsificado | Confirmación solo por webhook firmado + conciliación | Fase 7 |
| Webhook falso | HMAC-SHA256 con secreto compartido | Fases 5,7 |
| IDOR (otro usuario ve mis datos) | Autorización por propietario en handlers | Fase 3 |
| Enumeración de usuarios WP | `/wp-json/wp/v2/users` bloqueado + `?author=N` | Fase 1 |
| Fuga de secretos en el repo | Solo `.env.example`, secret-scanner en CI | Fase 8 |

## Responsabilidades del cliente

El Licenciatario es responsable de:

1. **Generar secretos propios** — No usar los del `.env.example`.
2. **HTTPS en producción** — Terminación TLS antes del proxy inverso.
3. **Copias de seguridad** — Programar `backup.sh` con rotación externa.
4. **Protección de datos (RGPD)** — Configurar el banner de consentimiento, la
   política de cookies y las páginas legales antes de lanzar.
5. **Pasarela de pago** — Verificar que la integración sigue las guías de seguridad
   del proveedor y que el webhook está firmado.
6. **Actualizaciones** — Mantener al día WordPress, plugins y dependencias npm.
7. **Monitorización** — Cablear Sentry (u observabilidad equivalente) y revisar
   logs periódicamente.

## Checklist de hardening

- [ ] Secretos generados con `openssl rand` y puestos en `.env`
- [ ] `ALLOWED_ORIGIN` ajustado al dominio real
- [ ] HTTPS forzado (HSTS + redirect 80→443)
- [ ] Cabeceras verificadas (`securityheaders.com` ≥ A)
- [ ] Webhooks configurados con secreto y prueba de firma
- [ ] Rate-limit con Redis activo y umbrales ajustados
- [ ] Cookies `Secure` + `SameSite` en producción
- [ ] CSP revisada (`img-src` incluye `WORDPRESS_PUBLIC_URL`; `connect-src` solo `'self'`: el navegador no habla con WordPress)
- [ ] `HWE_REVALIDATION_SECRET` idéntico en WordPress y frontend (secreto interno de `hwe-bff-only`)
- [ ] `sh backend/scripts/verify-security.sh --strict` en verde contra la pila real
- [ ] Backups programados y probados
- [ ] `npm run build` y `npm run test` verdes en CI

## Verificar la pila con `verify-security.sh`

Los tests unitarios simulan `fetch` y `$_SERVER`: no pueden decir si la pila **real** está cerrada.
`backend/scripts/verify-security.sh` lo comprueba con tráfico HTTP de verdad contra la pila en marcha
(dev o prod). Es idempotente (no cambia nada) y no imprime ningún secreto.

```bash
WP_URL=http://localhost:8080 BFF_URL=http://localhost:3000 \
HWE_REVALIDATION_SECRET=… WC_WEBHOOK_SECRET=… \
VERIFY_2FA_USERNAME=… VERIFY_2FA_PASSWORD=… \
PUBLIC_URL=https://tu-dominio.com \    # solo en producción, tras Caddy
  sh backend/scripts/verify-security.sh --strict
```

Qué comprueba:

1. **WordPress solo atiende al BFF** (`hwe-bff-only`): GraphQL, la Store API y `hwe/v1/auth` responden
   **404** sin el secreto interno, por ruta y por parámetro (`/?graphql`, `?rest_route=`) y con un
   secreto incorrecto. Incluye un **control positivo**: con el secreto correcto GraphQL sí responde; sin
   él, los 404 de una pila rota parecerían «cerrada».
2. **Webhooks y barrera de Origin**: uno firmado (HWE y WooCommerce) atraviesa el proxy sin `Origin`; uno
   sin firma o con firma falsa se rechaza; una escritura normal sin `Origin` ni CSRF se rechaza.
3. **Login con 2FA**: la respuesta pide el segundo factor y no trae tokens ni cookies de sesión. Necesita un
   usuario con 2FA activo (se activa desde la cuenta; no se puede automatizar sin TOTP).
4. **Cabeceras**: CSP (sin `api.qrserver.com`), `nosniff`, `X-Frame-Options`, `Referrer-Policy`,
   `Permissions-Policy`, sin `X-Powered-By`; con `PUBLIC_URL`, las de las rutas que Caddy sirve directo desde
   WordPress.

Lo que falta un secreto o un usuario se **omite y se dice** (`(omitida)`); con `--strict` omitir cuenta como
fallo (úsalo en la aceptación de una release). Salida: `0` todo bien · `1` algún fallo · `2` la pila no
responde. El propio script se prueba contra una pila de pega con cada fuga posible
(`backend/tests/Unit/Scripts/VerifySecurityTest.php`): un verificador que nunca falla no verifica nada.
