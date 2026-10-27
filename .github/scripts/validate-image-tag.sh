#!/bin/sh
# Valida una etiqueta de imagen Docker ANTES de usarla en el CD.
#
# La etiqueta llega desde `workflow_dispatch.inputs.image_tag`, así que es DATO:
# nunca debe interpolarse en un `run:`/`script:` (GitHub la sustituiría como
# texto y el shell la ejecutaría). Este script la recibe como argumento, aplica
# la gramática de Docker y la devuelve por stdout:
#
#   · longitud entre 1 y 128 caracteres;
#   · primer carácter [A-Za-z0-9_];
#   · resto [A-Za-z0-9_.-].
#
# Sale 0 e imprime la etiqueta si es válida; sale 1 con el motivo por stderr si
# no lo es. Se invoca con `sh` (no depende del bit de ejecución).
set -eu

tag=${1-}

if [ -z "$tag" ]; then
  echo "image_tag vacío" >&2
  exit 1
fi

if [ "${#tag}" -gt 128 ]; then
  echo "image_tag supera los 128 caracteres de Docker" >&2
  exit 1
fi

case "$tag" in
  [A-Za-z0-9_]*) ;;
  *)
    echo "image_tag debe empezar por [A-Za-z0-9_]" >&2
    exit 1
    ;;
esac

case "$tag" in
  *[!A-Za-z0-9_.-]*)
    echo "image_tag solo admite [A-Za-z0-9_.-]" >&2
    exit 1
    ;;
esac

printf '%s\n' "$tag"
