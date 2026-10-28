# Imagen del contenedor `backup` de producción.
#
# Las dependencias se hornean aquí para que el contenedor no necesite salida a
# Internet al arrancar: en producción solo está conectado a la red interna `data`.
# `backup-entrypoint.sh` conserva un `apk add` de respaldo para una imagen vieja,
# pero el camino normal es encontrarlas ya instaladas.
FROM alpine:3.23

RUN apk add --no-cache mariadb-client jq
