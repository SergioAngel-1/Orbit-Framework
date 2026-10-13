# Caddy — sitios opcionales

El `Caddyfile` de la raíz sirve el dominio público (frontend + WordPress). Los
archivos `*.caddy` de `caddy/conf.d/` se importan además (el contenedor monta esa
carpeta en `/etc/caddy/conf.d`). Sin archivos, no cambia nada.

## Panel de WordPress en `admin.<dominio>` (opcional)

Deja el dominio público íntegramente al frontend y mueve `/wp-admin` y el login a
su propio host. Los **medios** no se mudan: se siguen sirviendo desde el dominio
público (`WP_CONTENT_URL`), así que el frontend no se reconstruye.

1. **DNS**: un registro `A`/`AAAA` para `admin.<dominio>` hacia el servidor.
2. `.env.prod`: `ADMIN_DOMAIN=admin.tienda.com`.
3. `cp caddy/admin.caddy.example caddy/conf.d/admin.caddy`.
4. `siteurl` de WordPress al subdominio (y `home` NO se toca):
   `docker compose -f docker-compose.prod.yml run --rm --entrypoint wp wpcli --path=/var/www/html option update siteurl https://admin.tienda.com`
5. `docker compose -f docker-compose.prod.yml --env-file .env.prod up -d caddy`
   (recarga el `Caddyfile`).

> ⚠ **El paso 4 y el redirect del `Caddyfile` van emparejados.** Con `siteurl`
> todavía en el dominio público, WordPress devolvería al visitante a un dominio que
> redirige al subdominio: bucle. Para **revertir**, quita `ADMIN_DOMAIN` y vuelve a
> poner `siteurl` al dominio público, los dos a la vez.

Añade el host a `HEADLESS_ALLOWED_ORIGINS` solo si algo del panel hace peticiones
cross-origin al BFF (no es lo habitual).
