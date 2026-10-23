#!/bin/sh
# ============================================================================
#  warm-cache.sh — Calienta la caché ISR después de un despliegue.
#
#  Uso (en el servidor, desde la raíz del repo):
#     PUBLIC_DOMAIN=tienda.example.com sh backend/scripts/warm-cache.sh
#     sh backend/scripts/warm-cache.sh https://tienda.example.com    # o así
#
#  Variables:
#     WARM_MAX_URLS    cuántas URLs del sitemap recorrer (default 120; 0 = todas)
#     WARM_OFFSET      cuántas URLs del sitemap saltarse al principio (default 0)
#     WARM_KEY_ROUTES  0 = no pedir las rutas clave (default 1)
#     WARM_TIMEOUT     timeout por URL en segundos (default 90)
#     WARM_CONCURRENCY peticiones en paralelo (default 3; 1 = secuencial)
#     WARM_DELAY       pausa entre peticiones de cada worker en segundos (default 0)
#     WARM_ROUTES      rutas clave (default: "/ /products /blog /about /contact")
#
#  Recomendado: una vez en primer plano, acotada, antes de dejar entrar al
#  público, y otra en SEGUNDO PLANO con el resto del sitemap
#  (`WARM_OFFSET=120 WARM_MAX_URLS=0 WARM_KEY_ROUTES=0`).
#
#  ── Por qué existe ─────────────────────────────────────────────────────────
#  Cada despliegue deja en frío las páginas ISR del build anterior (el volumen
#  `next_cache` conserva el Data Cache, pero las páginas se regeneran). Con la
#  caché fría el sitio regenera las páginas bajo demanda, y si quien las pide
#  primero es el público y los rastreadores a la vez, la estampida satura
#  WordPress, las peticiones se estorban entre sí y ninguna termina. Como una
#  petición abortada NO entra en el Data Cache de Next, el sitio tampoco se
#  calienta con los intentos fallidos: se queda atascado.
#
#  Este script convierte esa estampida en una cola ORDENADA. Va con 3 peticiones
#  en paralelo por defecto: WordPress tiene 5 workers (apache-mpm.conf) y el
#  resto de la capacidad queda para el público. Con `WARM_CONCURRENCY=1` vuelve
#  al modo estrictamente secuencial de antes.
#
#  ── Lo que NO hace, a propósito ────────────────────────────────────────────
#  No borra ninguna caché. El Data Cache (respuestas de WordPress, por contenido)
#  sobrevive al despliegue queriendo: con él caliente, regenerar una página cuesta
#  una consulta; con él frío, decenas. Purgarlo deja el sitio regenerando todo
#  bajo demanda contra un WordPress que comparte CPU, que es justo lo que esto
#  evita.
# ============================================================================

set -eu

# ── Modo trabajador ──────────────────────────────────────────────────────────
# `xargs` no puede llamar a una función del shell padre, así que cada worker es
# este mismo script invocado con `--una <url>`: imprime una línea TSV con el
# resultado y sale. Los parámetros llegan por entorno (más abajo se exportan).
una() {
    url="$1"
    ini=$(date +%s)
    cod=$(curl -s -o /dev/null -w '%{http_code}' -m "$WARM_TIMEOUT" "$url" 2>/dev/null || echo 000)
    fin=$(date +%s)
    printf '%s\t%s\t%s\n' "$cod" "$((fin - ini))" "$url"
    [ "$WARM_DELAY" = "0" ] || sleep "$WARM_DELAY"
}

if [ "${1:-}" = "--una" ]; then
    shift
    una "${1:?falta la URL}"
    exit 0
fi

BASE="${1:-https://${PUBLIC_DOMAIN:-localhost}}"
BASE="${BASE%/}"
MAX_URLS="${WARM_MAX_URLS:-120}"
OFFSET="${WARM_OFFSET:-0}"
KEY_ROUTES="${WARM_KEY_ROUTES:-1}"
WARM_TIMEOUT="${WARM_TIMEOUT:-90}"
WARM_CONCURRENCY="${WARM_CONCURRENCY:-3}"
WARM_DELAY="${WARM_DELAY:-0}"

# Un valor no numérico o 0 caería en un bucle raro: se normaliza a 1 (secuencial).
case "$WARM_CONCURRENCY" in
    ''|*[!0-9]*) WARM_CONCURRENCY=1 ;;
    0) WARM_CONCURRENCY=1 ;;
esac

export WARM_TIMEOUT WARM_DELAY

# Rutas que se calientan SIEMPRE y en primer lugar: son las de más tráfico y las
# que un visitante ve antes de que ningún rastreador llegue al sitemap.
RUTAS_CLAVE="${WARM_ROUTES:-/ /products /blog /about /contact}"

lista=$(mktemp)
resultados=$(mktemp)
trap 'rm -f "$lista" "$resultados"' EXIT INT TERM

echo "== Calentando caché ISR de ${BASE} =="
echo "   (${WARM_CONCURRENCY} en paralelo; WordPress tiene 5 workers)"
echo

if [ "$KEY_ROUTES" != "0" ]; then
    echo "-- Rutas clave --"
    for r in $RUTAS_CLAVE; do
        printf '%s\n' "${BASE}${r}" >> "$lista"
    done
fi

# El sitemap raíz es un ÍNDICE que apunta a sitemaps por segmento; hay que bajar
# los dos niveles. `grep -o` sobre <loc> basta y evita dependencias.
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
        printf '%s\n' "$u" >> "$lista"
    done
fi

echo
if [ ! -s "$lista" ]; then
    echo "== Nada que calentar =="
    exit 0
fi

# Se piden TODAS en paralelo acotado. Cada worker es este script (`--una`), que
# imprime una línea por URL; el recuento y el resumen se hacen después, en serie.
xargs -P "$WARM_CONCURRENCY" -n 1 sh "$0" --una < "$lista" > "$resultados" || true

ok=0
fallo=0
lista_fallos=""
TAB=$(printf '\t')
while IFS="$TAB" read -r cod seg url; do
    [ -n "${url:-}" ] || continue
    if [ "$cod" = "200" ] || [ "$cod" = "301" ] || [ "$cod" = "308" ]; then
        ok=$((ok + 1))
        printf '  ok   %3s  %3ss  %s\n' "$cod" "$seg" "$url"
    else
        fallo=$((fallo + 1))
        lista_fallos="${lista_fallos}
    ${cod}  ${url}"
        printf '  FALLO %3s %3ss  %s\n' "$cod" "$seg" "$url"
    fi
done < "$resultados"

echo
echo "== Resumen: ${ok} ok, ${fallo} con error =="
if [ -n "$lista_fallos" ]; then
    echo "   Rutas con error:${lista_fallos}"
fi

# Salida 0 siempre: calentar es best-effort. Quien decide si el despliegue es
# bueno es el smoke check, que comprueba rutas concretas y sí falla.
exit 0
