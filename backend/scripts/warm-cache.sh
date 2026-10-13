#!/bin/sh
# ============================================================================
#  warm-cache.sh — Calienta la caché ISR después de un despliegue.
#
#  Uso (en el servidor, desde la raíz del repo):
#     PUBLIC_DOMAIN=tienda.example.com sh backend/scripts/warm-cache.sh
#     sh backend/scripts/warm-cache.sh https://tienda.example.com    # o así
#
#  Variables:
#     WARM_MAX_URLS   cuántas URLs del sitemap recorrer (default 120; 0 = todas)
#     WARM_OFFSET     cuántas URLs del sitemap saltarse al principio (default 0)
#     WARM_KEY_ROUTES 0 = no pedir las rutas clave (default 1)
#     WARM_TIMEOUT    timeout por URL en segundos (default 90)
#     WARM_DELAY      pausa entre peticiones en segundos (default 1)
#     WARM_ROUTES     rutas clave (default: "/ /products /blog /about /contact")
#
#  Recomendado: una vez en primer plano, acotada, antes de dejar entrar al
#  público, y otra en SEGUNDO PLANO con el resto del sitemap
#  (`WARM_OFFSET=120 WARM_MAX_URLS=0 WARM_KEY_ROUTES=0`). Va secuencial: una
#  petición cada vez no compite con el público.
#
#  ── Por qué existe ─────────────────────────────────────────────────────────
#  Cada despliegue deja en frío las páginas ISR del build anterior. Con la caché
#  fría el sitio regenera las páginas bajo demanda, y si quien las pide primero
#  es el público y los rastreadores a la vez, la estampida satura WordPress, las
#  peticiones se estorban entre sí y ninguna termina. Como una petición abortada
#  NO entra en el Data Cache de Next, el sitio tampoco se calienta con los
#  intentos fallidos: se queda atascado.
#
#  Este script convierte esa estampida en una cola ORDENADA: una URL cada vez. Es
#  más lento en total y muchísimo más rápido en llegar a un sitio usable.
#
#  ── Lo que NO hace, a propósito ────────────────────────────────────────────
#  No borra ninguna caché. El Data Cache (respuestas de WordPress, por contenido)
#  sobrevive al despliegue queriendo: con él caliente, regenerar una página cuesta
#  una consulta; con él frío, decenas. Purgarlo deja el sitio regenerando todo
#  bajo demanda contra un WordPress que comparte CPU, que es justo lo que esto
#  evita.
# ============================================================================

set -eu

BASE="${1:-https://${PUBLIC_DOMAIN:-localhost}}"
BASE="${BASE%/}"
MAX_URLS="${WARM_MAX_URLS:-120}"
OFFSET="${WARM_OFFSET:-0}"
KEY_ROUTES="${WARM_KEY_ROUTES:-1}"
TIMEOUT="${WARM_TIMEOUT:-90}"
DELAY="${WARM_DELAY:-1}"

# Rutas que se calientan SIEMPRE y en primer lugar: son las de más tráfico y las
# que un visitante ve antes de que ningún rastreador llegue al sitemap.
RUTAS_CLAVE="${WARM_ROUTES:-/ /products /blog /about /contact}"

ok=0
fallo=0
lista_fallos=""

# Pide UNA url y contabiliza. Nunca aborta el script: que una ruta falle no
# invalida el calentamiento de las demás (el veredicto lo da el smoke check del
# despliegue, que es quien decide si vale).
calentar() {
    url="$1"
    ini=$(date +%s)
    cod=$(curl -s -o /dev/null -w '%{http_code}' -m "$TIMEOUT" "$url" 2>/dev/null || echo 000)
    fin=$(date +%s)
    seg=$((fin - ini))

    if [ "$cod" = "200" ] || [ "$cod" = "301" ] || [ "$cod" = "308" ]; then
        ok=$((ok + 1))
        printf '  ok   %3s  %3ss  %s\n' "$cod" "$seg" "$url"
    else
        fallo=$((fallo + 1))
        lista_fallos="${lista_fallos}
    ${cod}  ${url}"
        printf '  FALLO %3s %3ss  %s\n' "$cod" "$seg" "$url"
    fi

    [ "$DELAY" = "0" ] || sleep "$DELAY"
}

echo "== Calentando caché ISR de ${BASE} =="
echo "   (secuencial a propósito: en paralelo las peticiones se estorban)"
echo

if [ "$KEY_ROUTES" != "0" ]; then
    echo "-- Rutas clave --"
    for r in $RUTAS_CLAVE; do
        calentar "${BASE}${r}"
    done
fi

# El sitemap raíz es un ÍNDICE que apunta a sitemaps por segmento; hay que bajar
# los dos niveles. `grep -o` sobre <loc> basta y evita dependencias.
echo
echo "-- Sitemap (desde la ${OFFSET}, hasta ${MAX_URLS} URLs; 0 = todas) --"
indices=$(curl -s -m 30 "${BASE}/sitemap.xml" 2>/dev/null \
    | grep -o '<loc>[^<]*</loc>' | sed 's|<loc>||;s|</loc>||' || true)

if [ -z "$indices" ]; then
    echo "  (no se pudo leer ${BASE}/sitemap.xml; se omite este bloque)"
else
    urls=""
    for s in $indices; do
        case "$s" in
            */sitemap/*.xml|*/sitemap.xml)
                hijas=$(curl -s -m 30 "$s" 2>/dev/null \
                    | grep -o '<loc>[^<]*</loc>' | sed 's|<loc>||;s|</loc>||' || true)
                urls="${urls} ${hijas}"
                ;;
            *)
                urls="${urls} ${s}"
                ;;
        esac
    done

    i=0
    n=0
    for u in $urls; do
        i=$((i + 1))
        [ "$i" -le "$OFFSET" ] && continue
        [ "$MAX_URLS" -gt 0 ] && [ "$n" -ge "$MAX_URLS" ] && break
        n=$((n + 1))
        calentar "$u"
    done
fi

echo
echo "== Resumen: ${ok} ok, ${fallo} con error =="
if [ -n "$lista_fallos" ]; then
    echo "   Rutas con error:${lista_fallos}"
fi

# Salida 0 siempre: calentar es best-effort. Quien decide si el despliegue es
# bueno es el smoke check, que comprueba rutas concretas y sí falla.
exit 0
