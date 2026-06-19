# RUNBOOK — Operación, recuperación e incidentes
### Headless Web Ecosystem

> Procedimientos operativos para producción: backups/restauración, rotación de
> secretos, cierre de sesiones, salud del sistema y respuesta a incidentes.
> Pensado para ejecutarse desde el host del despliegue (con la pila Docker).

---

## 1. Backups

Los backups los genera `backend/scripts/backup.sh` (manual) o el contenedor
`backup` de `docker-compose.prod.yml` (programado vía cron, configurable desde
el panel HWE Control Center → *Backups automáticos*).

Cada ejecución produce, en el destino (`./backups` por defecto):

- `db-YYYYmmdd-HHMMSS.sql.gz` — volcado completo de la base de datos.
- `uploads-YYYYmmdd-HHMMSS.tar.gz` — medios de `wp-content/uploads`.

**Regla de oro:** copia estos archivos **fuera del servidor** (S3/almacenamiento
externo). Un backup que vive solo en la misma máquina no protege ante su pérdida.

Backup inmediato (contenedor de producción):

```bash
docker exec hwe_backup /usr/local/bin/backup-cron.sh
```

---

## 2. Restauración (probada)

> La restauración **sobreescribe** datos. Practícala en *staging* antes de
> necesitarla en producción. El script pide confirmación explícita.

```bash
# Desarrollo (docker-compose.yml):
sh backend/scripts/restore.sh backups/db-XXXX.sql.gz backups/uploads-XXXX.tar.gz

# Producción (docker-compose.prod.yml):
COMPOSE_FILE=docker-compose.prod.yml \
  sh backend/scripts/restore.sh backups/db-XXXX.sql.gz backups/uploads-XXXX.tar.gz
```

El script: importa la DB al contenedor `db`, extrae los uploads y **vacía Redis**
(`FLUSHALL`) para no servir datos obsoletos.

**Verificación post-restore (obligatoria):**

1. `GET /api/health` → `status: "ok"` (o `degraded` solo por dependencias conocidas).
2. Login en `/wp-admin` correcto.
3. El catálogo carga en el frontend (puede requerir revalidación: ver §5).
4. Crear/ver un pedido de prueba (flujo login → carrito → checkout → pago `noop`).

**Simulacro recomendado (trimestral):** restaurar el último backup en un entorno
limpio y completar la verificación. Anota el tiempo total (RTO) y la antigüedad
del backup (RPO).

---

## 3. Rotación de secretos

Todos los secretos viven en `.env` (raíz) y nunca en el repo. Genera valores con
`openssl rand -base64 48`. El **guard de arranque** (`lib/security/secret-guard.ts`)
aborta el boot en producción si quedan valores por defecto o demasiado cortos.

| Secreto | Efecto al rotar | Acción adicional |
|---------|-----------------|------------------|
| `GRAPHQL_JWT_AUTH_SECRET_KEY` | Invalida **todas** las sesiones (firmas JWT) | Debe coincidir en WP y en el frontend. Reinicia ambos. |
| `CSRF_SECRET` | Invalida tokens CSRF en vuelo (el cliente re-pide token) | Reinicia el frontend. |
| `WC_WEBHOOK_SECRET` | Las firmas de webhooks de Woo dejan de validar | Actualiza el secreto del webhook en WooCommerce. |
| `HWE_REVALIDATION_SECRET` | Revalidación de config y `2fa-status` interno | Debe coincidir en WP y frontend. |
| `HWE_SECRETS_KEY` | **No** rompe secretos antiguos (lectura legacy), pero los nuevos usan la nueva clave | Re-guarda los secretos del Control Center para re-cifrarlos con la nueva clave. |
| `WC_CONSUMER_KEY/SECRET` | El BFF pierde acceso a wc/v3 | Regenera con `generate-woo-keys.sh` y actualiza `.env`. |

Tras rotar, reinicia los servicios afectados:

```bash
docker compose -f docker-compose.prod.yml up -d --force-recreate frontend wordpress
```

---

## 4. Sesiones y cuentas

- **Cerrar la sesión de un dispositivo:** `POST /api/auth/logout` (revoca el
  access token en la blocklist de Redis y borra cookies).
- **Cerrar TODAS las sesiones de un usuario** (p. ej. cuenta comprometida):
  `POST /api/auth/logout-all` — rota el secreto JWT del usuario en WordPress, lo
  que invalida sus refresh tokens. Los access tokens vivos caducan en minutos.
- **2FA:** el usuario recupera acceso con un **código de recuperación** si pierde
  el dispositivo. Si los agota, un administrador puede limpiar el meta
  `hwe_2fa_secret`/`hwe_2fa_recovery` del usuario en WordPress.

---

## 5. Salud y revalidación

- **Liveness:** `GET /api/health/live` → 200 si el proceso responde (sin tocar
  dependencias). Úsalo como *liveness probe*.
- **Readiness:** `GET /api/health` (detalle de dependencias). Para que falle
  cuando una dependencia está caída, usa `GET /api/health?ready=1` (devuelve 503
  si `degraded`). Úsalo como *readiness probe*.
- **Catálogo desactualizado:** WooCommerce revalida vía webhook a
  `/api/revalidate`. Si tras un cambio el catálogo no refresca, comprueba el
  webhook y su firma (`WC_WEBHOOK_SECRET`).

---

## 6. Respuesta a incidentes (guía rápida)

| Síntoma | Primer diagnóstico | Acción |
|---------|--------------------|--------|
| Picos de 401/403 en `/api/*` | Logs `*.idor`, `csrf`, `origin` | Revisar `ALLOWED_ORIGIN`/CSRF; posible ataque → revisar IPs. |
| `payments.webhook.mismatch` | Importe/moneda no casan | NO marcar pagado a mano; investigar pedido y evento de la pasarela. |
| Muchos 429 | Rate-limit disparado | ¿Ataque o cliente legítimo? Ajustar límites o bloquear IP en Caddy. |
| `checkout.link_owner_failed` | Pedido no ligado al cliente | Conciliar manualmente `customer_id` del pedido en wc/v3. |
| Boot del frontend falla | Guard de secretos | Revisar `.env`: secretos reales y ≥24 chars. |
| Redis caído | `health` → `redis: down` | Rate-limit/idempotencia degradan (fail-open). Restaurar Redis; revisar abuso mientras tanto. |

**Cuenta comprometida:** `logout-all` del usuario → forzar reset de contraseña →
revisar pedidos/direcciones recientes.

**Fuga de secreto:** rotar el secreto afectado (§3) → `logout-all` masivo si fue
el JWT → auditar accesos.

---

## 7. Contactos y escalado

> Completa con los datos de tu organización: responsable de infraestructura,
> proveedor de hosting, soporte de la pasarela de pago y del registrador DNS/TLS.
