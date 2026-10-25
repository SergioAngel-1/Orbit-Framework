# RUNBOOK — Operación, recuperación e incidentes
### HWE Orbit

> Procedimientos operativos para producción: backups/restauración, rotación de
> secretos, cierre de sesiones, tareas programadas, salud del sistema, verificación
> de la pila y respuesta a incidentes. Pensado para ejecutarse desde el host del
> despliegue (con la pila Docker).

---

## 1. Backups

Los backups los genera `backend/scripts/backup.sh` (manual, desde el host) o el
contenedor `backup` de `docker-compose.prod.yml` (programado por cron, configurable
desde el panel).

### Desde el panel (lo normal)

*wp-admin → HWE Config → Backups* es el centro de mando:

- **Programación y retención:** activar el automático, frecuencia y hora (UTC), días
  de retención, «conservar solo el último», incluir o no los medios, excluir las
  miniaturas (WordPress las regenera) e incluir la exportación de la configuración.
  El panel escribe `hwe-backup-config.json`; **reinicia el contenedor `backup`
  tras cambiar la hora** para que el cron se actualice.
- **Estado y «Backup ahora»:** muestra el resultado de la última corrida y lanza una
  manual. El botón escribe un disparador que el contenedor `backup` consume en menos
  de un minuto (`backup-trigger-watch.sh`); funciona aunque el automático esté apagado
  y se bloquea mientras haya una corrida en curso.
- **Copias disponibles:** lista y descarga de los archivos (`db-*.sql.gz`,
  `uploads-*.tar.gz`, `config-*.json`).
- **Exportar / importar la configuración** del Control Center (sin secretos).
- **Correo de aviso** (`notify_email`): lo envía WordPress (WP-Cron) leyendo el estado
  de la última corrida; el script de backup no envía correo.

### Qué produce una corrida

En el destino (`./backups` por defecto, `HWE_BACKUPS_DIR` dentro del contenedor):

- `db-YYYYmmdd-HHMMSS.sql.gz` — volcado completo de la base de datos.
- `uploads-YYYYmmdd-HHMMSS.tar.gz` — medios de `wp-content/uploads`.
- `config-YYYYmmdd-HHMMSS.json` — exportación de la configuración (si está activada).

Garantías de los scripts: la contraseña de la base de datos **no** va en la línea de
comandos; cada paso se comprueba; si algo falla **no queda ninguna copia a medias** y
la corrida termina con error; y **nada se borra** si la corrida falla (un backup roto
no se lleva por delante las copias buenas). Un backup que muere (OOM, `docker kill`)
deja un *lock* que se retira solo cuando su dueño ya no existe o supera su edad máxima.

**Regla de oro:** copia estos archivos **fuera del servidor** (S3/almacenamiento
externo). Un backup que vive solo en la misma máquina no protege ante su pérdida.

**Cifrado y verificación (recomendado en producción).** Los archivos de `./backups` contienen
la base de datos completa **en claro** (clientes, pedidos, hashes): el robo del servidor o del
disco es también el robo de esos datos. Cifra en origen con una clave pública que **no** viva
en el servidor (`age -r "$(cat /etc/hwe/backup.pub)"` o `gpg --encrypt`) o sincroniza con un
remoto cifrado de `rclone`, y programa ese envío en el cron del host (el contenedor `backup`
no sale a Internet: solo está en la red `data`). Restringe además el directorio al usuario de
operación (`chmod 700 backups`) y **verifica** tanto la llegada (`rclone check`) como una
restauración de prueba (§2): una copia que nunca se restauró no es una copia.

### Desde la consola

```bash
# Backup inmediato en producción (con HWE_CONTAINER_PREFIX, sustituye `hwe_` por el tuyo)
docker exec hwe_backup /bin/sh /opt/hwe/scripts/backup-cron.sh manual

# Comprobar que las copias automáticas funcionan (resultado y fallos salen por logs)
docker logs --tail 50 hwe_backup
ls -lh backups/    # debe haber un db-*.sql.gz reciente

# Backup manual desde el host (desarrollo)
sh backend/scripts/backup.sh [directorio_destino]
```

---

## 2. Restauración (probada)

> La restauración **sobreescribe** datos. Practícala en *staging* antes de
> necesitarla en producción. El script pide confirmación explícita y comprueba la
> integridad de los archivos **antes** de tocar nada.

```bash
# Desarrollo (docker-compose.yml):
sh backend/scripts/restore.sh backups/db-XXXX.sql.gz backups/uploads-XXXX.tar.gz

# Producción (docker-compose.prod.yml):
COMPOSE_FILE=docker-compose.prod.yml \
  sh backend/scripts/restore.sh backups/db-XXXX.sql.gz backups/uploads-XXXX.tar.gz
```

El script: verifica los archivos, importa la DB al contenedor `db`, extrae los
uploads y **vacía Redis** (`FLUSHALL`) para no servir datos obsoletos. Eso incluye la
lista de sesiones revocadas y los contadores de rate-limit: un token revocado antes de
la restauración vuelve a valer hasta que caduque (minutos) y los refresh tokens se rigen
solo por el secreto del usuario. Si restauras por una cuenta o un secreto comprometido,
haz `logout-all` y rota el secreto después.

**Verificación post-restore (obligatoria):**

1. `GET /api/health?ready=1` → 200 (`status: "ok"`).
2. Login en el panel de WordPress correcto.
3. El catálogo carga en el frontend (puede requerir revalidación: ver §5).
4. `sh backend/scripts/verify-security.sh` y `sh backend/scripts/verify-modules.sh` en verde.
5. Crear/ver un pedido de prueba (flujo login → carrito → checkout → pago `noop`).

**Simulacro recomendado (trimestral):** restaurar el último backup en un entorno
limpio y completar la verificación. Anota el tiempo total (RTO) y la antigüedad
del backup (RPO).

---

## 3. Rotación de secretos

Todos los secretos viven en `.env` (raíz) y nunca en el repo. Genera valores con
`openssl rand -base64 48` o `sh backend/scripts/generate-secrets.sh`. El **guard de
arranque** (`lib/security/secret-guard.ts`) aborta el boot en producción si quedan
valores por defecto o demasiado cortos.

| Secreto | Efecto al rotar | Acción adicional |
|---------|-----------------|------------------|
| `GRAPHQL_JWT_AUTH_SECRET_KEY` | Invalida **todas** las sesiones (firmas JWT) | Debe coincidir en WP y en el frontend. Reinicia ambos. |
| `CSRF_SECRET` | Invalida tokens CSRF en vuelo (el cliente re-pide token) | Reinicia el frontend. |
| `WC_WEBHOOK_SECRET` | Las firmas de webhooks de Woo dejan de validar | Actualiza el secreto del webhook en WooCommerce. |
| `HWE_REVALIDATION_SECRET` | Es el **secreto interno** BFF→WordPress y firma la revalidación: con un valor distinto en cada lado, GraphQL, la Store API y `hwe/v1/auth\|consent` responden 404 | Cambia **a la vez** el frontend y el `wp-config.php`/compose de WordPress y reinicia ambos. Comprueba con `verify-security.sh`. |
| `HWE_SECRETS_KEY` | **Lo ya cifrado deja de poder leerse** (no hay clave de reserva) y no se guarda nada hasta que haya clave | Vuelve a introducir las credenciales de pasarelas y SMTP en el panel. Cambiarla es una migración, no una rotación rutinaria. |
| `HWE_2FA_KEY` | Los secretos TOTP existentes dejan de descifrarse | Los usuarios con 2FA deberán reactivarlo (un administrador puede limpiar su `hwe_2fa_secret`). |
| `WC_CONSUMER_KEY/SECRET` | El BFF pierde acceso a wc/v3 | Regenera con `generate-woo-keys.sh` y actualiza `.env`. |
| Contraseña de aplicación del integrador | El integrador deja de entrar | Ver el `MANUAL.md` de `hwe-external-integration`: se revoca y se crea otra con WP-CLI. |

Tras rotar, reinicia los servicios afectados:

```bash
docker compose -f docker-compose.prod.yml up -d --force-recreate frontend wordpress wp-cron
```

> Con `COMPOSE_FILE=docker-compose.prod.yml` en `.env.prod`, un `docker compose …` a
> secas ya es el de producción. Cuidado: cada `docker compose run` recrea `wordpress`
> si el entorno de interpolación difiere del de la pila en marcha.

---

## 4. Sesiones y cuentas

- **Cerrar la sesión de un dispositivo:** `POST /api/auth/logout` (revoca el
  access token en la blocklist de Redis y borra cookies).
- **Cerrar TODAS las sesiones de un usuario** (p. ej. cuenta comprometida):
  `POST /api/auth/logout-all` — rota el secreto JWT del usuario en WordPress, lo
  que invalida sus refresh tokens. Cambiar la contraseña hace lo mismo y conserva
  solo la sesión actual. Los access tokens vivos caducan en minutos.
- **2FA:** el usuario recupera acceso con un **código de recuperación** si pierde
  el dispositivo. Si los agota, un administrador puede limpiar el meta
  `hwe_2fa_secret`/`hwe_2fa_recovery` del usuario en WordPress.
- **Integrador externo:** su usuario de servicio solo vive en WordPress (WP-CLI);
  retirar el acceso es vaciar `HWE_INTEGRATION_ALLOWED_IPS` y recrear `wordpress`
  (`/graphql` vuelve a cerrarse), o apagar el módulo `external-integration`.

---

## 5. Tareas programadas, salud y revalidación

**WP-Cron.** `DISABLE_WP_CRON` está activo: los eventos programados de WordPress y de
los plugins (aviso de backup, revalidaciones diferidas…) los ejecuta el contenedor
`wp-cron` por WP-CLI. Si «no pasa nada programado», mira `docker logs hwe_wp_cron` (o
`${HWE_CONTAINER_PREFIX:-hwe}_wp_cron` si la pila usa otro prefijo, `docs/CREATE_INSTANCE.md` §2).
Tras actualizar mu-plugins o plugins, reinicia ese contenedor.

**Salud.**

- **Liveness:** `GET /api/health/live` → 200 si el proceso responde (sin tocar
  dependencias). Úsalo como *liveness probe*.
- **Readiness:** `GET /api/health?ready=1` → 503 si alguna dependencia está caída.
  `GET /api/health` (sin flag) devuelve siempre 200 con el detalle.

**Presupuesto de memoria de WordPress.** El contenedor `wordpress` tiene 768 MB
(`docker-compose.prod.yml`) y tres números tienen que cuadrar entre sí:

| Archivo | Qué fija |
|---|---|
| `docker-compose.prod.yml` | `deploy.resources.limits.memory` (768 MB) |
| `backend/config/opcache.ini` | Segmento COMPARTIDO: `memory_consumption` + `interned_strings_buffer` + `jit_buffer_size` (96 + 8 + 0 = 104 MB) |
| `backend/config/apache-mpm.conf` | `MaxRequestWorkers` (5); cada hijo cuesta ~60 MB |
| `backend/config/uploads.ini` | `memory_limit` = techo de UNA petición (256 MB, lo que recomienda WooCommerce), nunca el del contenedor |

Presupuesto típico: `10 (padre) + 104 (OPcache) + 5 × 60 = 414 MB`; peor caso (una
petición al tope de `memory_limit` y 4 workers normales): `10 + 104 + 256 + 4 × 60 = 610 MB`,
con 158 MB de margen. Si
subes cualquiera de ellos, recalcula el resto en la misma proporción;
`npm run test` (`tests/unit/apache-mpm.test.ts`) falla si no cabe. Un import
puntual que necesite más memoria se lanza con `wp --memory-limit=512M …`, no
subiendo el `memory_limit` global.

**Catálogo desactualizado.** WooCommerce revalida vía webhook a `/api/revalidate`.
Si tras un cambio el catálogo no refresca, comprueba el webhook y su firma
(`WC_WEBHOOK_SECRET`). Los cambios de configuración y de módulos revalidan por
etiqueta (`site-config`, `banners`, `shipping`, `landings`, `geo`…) con
`HWE_REVALIDATION_SECRET`.

**Después de desplegar.** El volumen `next_cache` conserva el Data Cache entre
despliegues, pero las páginas ISR se regeneran. Calienta de forma ordenada (3 en
paralelo por defecto, contra los 5 workers de WordPress) en vez de dejar que público y
rastreadores regeneren a la vez:

```bash
PUBLIC_DOMAIN=tienda.example.com sh backend/scripts/warm-cache.sh
# y, en segundo plano, el resto del sitemap:
WARM_OFFSET=120 WARM_MAX_URLS=0 WARM_KEY_ROUTES=0 PUBLIC_DOMAIN=… sh backend/scripts/warm-cache.sh
# ¿WordPress va justo? Baja la concurrencia (WARM_CONCURRENCY=1 = secuencial).
```

No borra ninguna caché a propósito: el Data Cache sobrevive al despliegue.

**Purga de la caché de imágenes.** `next/image` no recolecta su caché en disco. El
servicio `cache-prune` borra una vez al día las variantes de `/app/.next/cache/images`
sin uso en `CACHE_IMAGES_MAX_AGE_DAYS` días (30 por defecto); se regeneran bajo demanda.
No toca el resto de `.next/cache`.

---

## 6. Verificar la pila

Los tests unitarios simulan `fetch` y `$_SERVER`; solo estos scripts ven la pila **real**.
Ejecútalos tras un despliegue, una restauración o un cambio de configuración de red.
Ninguno imprime secretos.

| Script | Qué comprueba |
|--------|---------------|
| `sh backend/scripts/verify-security.sh [--strict]` | WordPress solo atiende al BFF, webhooks firmados, 2FA, cabeceras. Ver `docs/SECURITY.md`. |
| `sh backend/scripts/verify-modules.sh [static\|toggle\|i18n\|country\|integration\|all]` | Cada módulo está completo y montado; encenderlo/apagarlo cambia `config.modules` y registra/retira sus rutas; `loyalty` añade sus tablas, las rutas internas con el secreto y la extensión de la Store API; `memberships` añade sus cinco tablas, sus rutas internas, la extensión de la Store API y el recorte de un producto bloqueado en GraphQL y la Store API (crea un nivel y un producto de prueba y los borra); traducción a un segundo idioma; paquete de país. **Enciende y apaga módulos de verdad** (y en `loyalty` toca los ajustes de forma temporal, restaurándolos) y los deja como estaban: no lo ejecutes contra producción con tráfico sin avisar. |
| `sh backend/scripts/verify-integration.sh [tracking\|gate\|access\|guard\|all]` | Puerta de red, rol y contraseña de aplicación, y política de GraphQL del módulo `external-integration`. Crea un usuario y un pedido desechables y los borra. |

Qué no puede verificarse en local: que Caddy vea la IP real del integrador ni que la
cabecera `Authorization` atraviese Caddy → Apache. Compruébalo en el despliegue.

---

## 7. Panel de WordPress en su propio host (opcional)

Con `ADMIN_DOMAIN` definido, `/wp-admin` y el login del dominio público redirigen a
`admin.<dominio>`; Caddy sirve ese host aparte. Procedimiento (DNS, `caddy/conf.d/admin.caddy`,
`siteurl`) en `caddy/README.md`. `HWE_LOGIN_SLUG` mueve además el login a `/<slug>` y hace
que `/wp-login.php` responda 404; **no publiques el slug**. `wp-login.php` limita a 5
intentos por IP cada 5 minutos.

---

## 8. Respuesta a incidentes (guía rápida)

| Síntoma | Primer diagnóstico | Acción |
|---------|--------------------|--------|
| Picos de 401/403 en `/api/*` | Logs `*.idor`, `csrf`, `origin` | Revisar `ALLOWED_ORIGIN`/CSRF; posible ataque → revisar IPs. |
| Todo responde 404 desde el BFF a WordPress | `[hwe] bff-only:` en el log de PHP | `HWE_REVALIDATION_SECRET` distinto o ausente en un lado (ver §3). |
| 500 intermitentes y el contenedor de WordPress se reinicia | `docker inspect hwe_wordpress` → `OOMKilled`, `docker stats` | Presupuesto de memoria (§5): revisar OPcache, `MaxRequestWorkers` y `memory_limit`. |
| `payments.webhook.mismatch` | Importe/moneda no casan | NO marcar pagado a mano; investigar pedido y evento de la pasarela. |
| Muchos 429 | Rate-limit disparado | ¿Ataque o cliente legítimo? Ajustar límites o bloquear IP en Caddy. |
| `checkout.link_owner_failed` | Pedido no ligado al cliente | Conciliar manualmente `customer_id` del pedido en wc/v3. |
| Boot del frontend falla | Guard de secretos | Revisar `.env`: secretos reales, ≥24 chars y `ALLOWED_ORIGIN` exacto. |
| Login con 2FA responde 503 | Redis caído | El 2FA falla cerrado sin Redis: restaurarlo. |
| Redis caído | `health` → `redis: down` | Rate-limit e idempotencia degradan (fail-open); la revocación de sesión también. Restaurar Redis; revisar abuso mientras tanto. |
| Backup «en curso» para siempre | Botón del panel bloqueado | El estado caduca solo a los 30 min; revisa `docker logs hwe_backup`. |
| Sitio lento tras desplegar | Caché ISR en frío | `warm-cache.sh` (§5). |

**Cuenta comprometida:** `logout-all` del usuario → forzar reset de contraseña →
revisar pedidos/direcciones recientes.

**Fuga de secreto:** rotar el secreto afectado (§3) → `logout-all` masivo si fue
el JWT → auditar accesos.

---

## 9. Contactos y escalado

> Completa con los datos de tu organización: responsable de infraestructura,
> proveedor de hosting, soporte de la pasarela de pago y del registrador DNS/TLS.

---

## 10. Revisión de versiones y seguridad de dependencias

**Qué vigila Dependabot (solo seguridad).** `.github/dependabot.yml` tiene las
actualizaciones de versión apagadas (`open-pull-requests-limit: 0`): solo llegan PR por
avisos de seguridad, agrupados por ecosistema (npm del frontend, GitHub Actions y las
dependencias de pruebas de Composer). Requiere, en *Settings → Code security*,
**Dependabot alerts** y **Dependabot security updates** activados; sin ellos no llega nada.

**Lo que Dependabot NO vigila** y se revisa a mano (mensual, o ante un aviso):

| Qué | Cómo |
|---|---|
| Plugins de WordPress de `setup.sh` | `sh backend/scripts/check-versions.sh` (compara con wp.org y GitHub; `--strict` falla si alguno está por detrás) |
| Imágenes Docker (WordPress, MariaDB, Redis, Caddy, Alpine, Node) | `docs/COMPATIBILITY.md` (política de tags y digest) y las etiquetas de Docker Hub. Actualizar es explícito: `docker compose pull` — el compose de producción no baja imágenes nuevas a mitad de un despliegue (`pull_policy: if_not_present`). |
| Árbol npm | `npm audit` / `npm run audit:gate` (el gate de CI) y `npm outdated` en `frontend/` |

**Cómo actualizar el CORE de WordPress.** El core no se actualiza con `docker compose pull`: vive en
el volumen `wordpress_core` y el entrypoint de la imagen solo lo copia si falta
`/var/www/html/index.php`, así que el sitio sigue con la versión vieja (y los CVE del core no se
corrigen con el despliegue). Tras subir la etiqueta de la imagen:

```bash
# 1. Estado: versión instalada + checksums (¿hay archivos modificados a mano?)
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm wpcli /scripts/update-core.sh
# 2. Actualizar a la versión de la imagen nueva (con copia de seguridad previa, §1)
docker compose -f docker-compose.prod.yml --env-file .env.prod \
    run --rm wpcli /scripts/update-core.sh --apply 7.2
# 3. OPcache tiene validate_timestamps=0: sin reiniciar, sigue sirviendo el código viejo
docker compose -f docker-compose.prod.yml --env-file .env.prod restart wordpress
```

Después, `sh backend/scripts/verify-security.sh` y una compra de prueba.

**Cómo subir un plugin.** Pruébalo en staging y cambia a la vez la versión y su SHA-256
en `setup.sh` (los de GitHub verifican hash); actualiza `docs/COMPATIBILITY.md`. Un salto de
*major* (p. ej. WooCommerce 10 → 11) exige comprobar antes que WooGraphQL lo declara
probado (`WC tested up to` en su cabecera).

**Avisos de `npm audit` sin parche.** Se toleran solo en la allowlist de
`frontend/scripts/audit-gate.mjs`, con motivo y condición de retirada; hoy, `braces` (solo
herramientas de desarrollo; no hay versión corregida).
