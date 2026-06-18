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
- [ ] CSP revisada (connect-src e img-src incluyen el CMS)
- [ ] Backups programados y probados
- [ ] `npm run build` y `npm run test` verdes en CI
