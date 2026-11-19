# Imagen del servicio `wordpress`: la oficial con la extensión nativa PhpRedis.
#
# Por qué: la imagen oficial no trae la extensión `redis`, así que Redis Object Cache
# caía a Predis (PHP puro). Medido en producción el 2026-11-19: unas 42 llamadas a
# Redis por arranque de WordPress costaban ~40 ms por petición, y el BFF hace muchas.
# Con la extensión cargada, Redis Object Cache la detecta sola (`class_exists('Redis')`)
# y no hace falta `WP_REDIS_CLIENT`; si faltara, vuelve a Predis sin romper nada.
#
# `wpcli` y `wp-cron` cargan el mismo WordPress con las mismas `WP_REDIS_*`: usan
# `backend/docker/wp-cli/Dockerfile`, con la MISMA versión y suma de PhpRedis (lo
# comprueba frontend/tests/unit/wordpress-images.test.ts).
#
# Etiqueta base: la misma que antes en el compose. Sus parches llegan al reconstruir
# con `docker compose build --pull` (ver docs/RUNBOOK.md §10).
FROM wordpress:7.1-php8.4-apache

# PhpRedis 6.3.0: última versión estable al 2026-11-19, publicada el 2025-11-06 en PECL
# (https://pecl.php.net/package/redis, mantenedor mgrunder) y en el repo oficial
# (https://github.com/phpredis/phpredis/releases/tag/6.3.0). Sin avisos de seguridad
# publicados en phpredis/phpredis. La suma es la del archivo de PECL
# (https://pecl.php.net/get/redis-6.3.0.tgz) descargado ese día. Subir de versión =
# cambiar las dos líneas aquí y en backend/docker/wp-cli/Dockerfile.
ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_SHA256=0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5

# La base Debian ya trae las herramientas de compilación (`$PHPIZE_DEPS`) de la imagen
# de PHP, así que no hay nada que instalar ni purgar: solo se borran el archivo y la
# caché de PECL. Sin serializadores ni compresión extra (los valores por defecto):
# Redis Object Cache serializa con PHP y las claves siguen siendo las mismas.
RUN set -eux; \
    curl -fsSL -o /tmp/redis.tgz "https://pecl.php.net/get/redis-${PHPREDIS_VERSION}.tgz"; \
    echo "${PHPREDIS_SHA256}  /tmp/redis.tgz" | sha256sum -c -; \
    pecl install /tmp/redis.tgz; \
    docker-php-ext-enable redis; \
    rm -rf /tmp/redis.tgz /tmp/pear ~/.pearrc; \
    php -r 'exit(phpversion("redis") === getenv("PHPREDIS_VERSION") ? 0 : 1);'
