# Imagen de los servicios de WP-CLI (`wpcli`, `wp-cron`): la oficial con PhpRedis.
#
# Cargan el mismo WordPress que el servicio `wordpress`, con las mismas `WP_REDIS_*`
# (`x-wordpress-config-extra`): si este contenedor siguiera con Predis, `wp cache
# flush`, el cron y `wp hwe setup` hablarían con Redis por otro cliente que la web.
# Mismo motivo, misma versión y misma suma que backend/docker/wordpress/Dockerfile
# (lo comprueba frontend/tests/unit/wordpress-images.test.ts).
#
# Etiqueta base: la misma que antes en el compose. Sus parches llegan al reconstruir
# con `docker compose build --pull` (ver docs/RUNBOOK.md §10).
FROM wordpress:cli-php8.4

# PhpRedis 6.3.0: última versión estable al 2026-11-19, publicada el 2025-11-06 en PECL
# (https://pecl.php.net/package/redis, mantenedor mgrunder) y en el repo oficial
# (https://github.com/phpredis/phpredis/releases/tag/6.3.0). Sin avisos de seguridad
# publicados en phpredis/phpredis. La suma es la del archivo de PECL
# (https://pecl.php.net/get/redis-6.3.0.tgz) descargado ese día. Subir de versión =
# cambiar las dos líneas aquí y en backend/docker/wordpress/Dockerfile.
ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_SHA256=0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5

# La imagen corre como `www-data`; se instala como root y se vuelve a `www-data`.
# La base es Alpine sin compilador: `$PHPIZE_DEPS` entra como paquete virtual y sale
# en la MISMA capa, así que la imagen no arrastra gcc ni make.
USER root
RUN set -eux; \
    apk add --no-cache --virtual .phpredis-build-deps $PHPIZE_DEPS; \
    curl -fsSL -o /tmp/redis.tgz "https://pecl.php.net/get/redis-${PHPREDIS_VERSION}.tgz"; \
    echo "${PHPREDIS_SHA256}  /tmp/redis.tgz" | sha256sum -c -; \
    pecl install /tmp/redis.tgz; \
    docker-php-ext-enable redis; \
    apk del --no-network .phpredis-build-deps; \
    rm -rf /tmp/redis.tgz /tmp/pear ~/.pearrc; \
    php -r 'exit(phpversion("redis") === getenv("PHPREDIS_VERSION") ? 0 : 1);'
USER www-data
