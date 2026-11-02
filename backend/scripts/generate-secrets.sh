#!/bin/sh
# ============================================================================
#  generate-secrets.sh — Genera los secretos de una instancia nueva y los
#  escribe directamente en `.env` (raíz) y `frontend/.env.local`, en vez de
#  copiar/pegar a mano el resultado de varios `openssl rand ...` (ver
#  docs/INSTALL.md §2). Pensado para correr UNA VEZ en el host, antes del
#  primer `docker compose run --rm wpcli`:
#
#    cp .env.example .env
#    cp frontend/.env.example frontend/.env.local
#    sh backend/scripts/generate-secrets.sh
#
#  Idempotente: si un secreto YA fue generado (no tiene el valor placeholder
#  de .env.example), lo deja intacto — reejecutar el script no rota secretos
#  existentes por accidente. Para rotar uno a propósito, borra su línea (o
#  vuelve a poner el valor "changeme-...") y vuelve a ejecutar.
# ============================================================================

set -e

ROOT_ENV=".env"
FRONTEND_ENV="frontend/.env.local"

for f in "$ROOT_ENV" "$FRONTEND_ENV"; do
	if [ ! -f "$f" ]; then
		example="${f%.local}"
		example="${example%.env}.env.example"
		if [ -f "$example" ]; then
			echo "==> $f no existe, lo creo desde $example"
			cp "$example" "$f"
		else
			echo "ERROR: no existe $f ni $example. Copia primero .env.example/.env.local.example manualmente." >&2
			exit 1
		fi
	fi
done

# Genera un valor aleatorio. $1 = "base64"|"hex", $2 = bytes.
gen() {
	if [ "$1" = "hex" ]; then
		openssl rand -hex "$2"
	else
		openssl rand -base64 "$2" | tr -d '\n'
	fi
}

# Sustituye (o añade) KEY=valor en un archivo, solo si el valor actual está
# vacío o es uno de los placeholders conocidos de *.env.example.
set_if_placeholder() {
	_file="$1"; _key="$2"; _value="$3"
	_current=$(grep -m1 "^${_key}=" "$_file" 2>/dev/null | cut -d= -f2-)
	case "$_current" in
		""|changeme-*|noop-sandbox-secret)
			if grep -q "^${_key}=" "$_file"; then
				# BSD/GNU sed compat: usa un delimitador que no choque con base64 (/).
				sed -i.bak "s|^${_key}=.*|${_key}=${_value}|" "$_file" && rm -f "${_file}.bak"
			else
				printf '%s=%s\n' "$_key" "$_value" >> "$_file"
			fi
			echo "    ✓ ${_key} generado en ${_file}"
			;;
		*)
			echo "    (omitido) ${_key} ya tiene un valor propio en ${_file}"
			;;
	esac
}

echo "==> Generando secretos de la instancia..."

# Compartidos entre WordPress y el frontend: MISMO valor en ambos archivos.
JWT_SECRET=$(gen base64 64)
set_if_placeholder "$ROOT_ENV" "GRAPHQL_JWT_AUTH_SECRET_KEY" "$JWT_SECRET"
set_if_placeholder "$FRONTEND_ENV" "GRAPHQL_JWT_AUTH_SECRET_KEY" "$JWT_SECRET"

CSRF_SECRET=$(gen base64 32)
set_if_placeholder "$ROOT_ENV" "CSRF_SECRET" "$CSRF_SECRET"
set_if_placeholder "$FRONTEND_ENV" "CSRF_SECRET" "$CSRF_SECRET"

WEBHOOK_SECRET=$(gen hex 32)
set_if_placeholder "$ROOT_ENV" "WC_WEBHOOK_SECRET" "$WEBHOOK_SECRET"
set_if_placeholder "$FRONTEND_ENV" "WC_WEBHOOK_SECRET" "$WEBHOOK_SECRET"

NOOP_SECRET=$(gen base64 32)
set_if_placeholder "$ROOT_ENV" "NOOP_INTEGRITY_SECRET" "$NOOP_SECRET"
set_if_placeholder "$FRONTEND_ENV" "NOOP_INTEGRITY_SECRET" "$NOOP_SECRET"

# Secreto interno del BFF: WordPress (`hwe-bff-only`) solo atiende al frontend si cada
# llamada lo trae. En Docker el frontend lo recibe de la raíz vía docker-compose; en modo
# nativo/híbrido lo lee de frontend/.env.local, así que va en los dos con el MISMO valor.
# Si la raíz ya tenía uno propio, se reutiliza: así se arregla un .env.local que se quedó
# con el placeholder sin rotar el de WordPress.
set_if_placeholder "$ROOT_ENV" "HWE_REVALIDATION_SECRET" "$(gen hex 32)"
REVALIDATION_SECRET=$(grep -m1 "^HWE_REVALIDATION_SECRET=" "$ROOT_ENV" | cut -d= -f2-)
set_if_placeholder "$FRONTEND_ENV" "HWE_REVALIDATION_SECRET" "$REVALIDATION_SECRET"

echo ""
echo "✔ Secretos listos en ${ROOT_ENV} y ${FRONTEND_ENV}."
echo "  Siguiente paso: docker compose up -d db redis && docker compose run --rm wpcli"
