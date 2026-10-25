#!/bin/sh
# ============================================================================
#  compose-containers.sh — resuelve los contenedores de la pila Docker de ESTE repo.
#
#  Una instancia puede convivir con otras pilas de Orbite en la misma máquina
#  (`docs/CREATE_INSTANCE.md` §2). Un script que hace `docker exec hwe_wordpress` desde
#  el clon de otra instancia actuaría sobre la pila equivocada —`verify-modules.sh`
#  activa y desactiva módulos—, así que los contenedores se resuelven por DATOS, no por
#  un nombre escrito a mano:
#
#    1. la anulación explícita (`HWE_WP_CONTAINER`, `HWE_CLI_CONTAINER`), si viene;
#    2. el servicio de Compose del proyecto actual: `docker compose ps -q <servicio>`
#       ejecutado desde la raíz del repo;
#    3. el nombre por defecto del framework: `${HWE_CONTAINER_PREFIX:-hwe}_<servicio>`
#       (con `-` → `_`, igual que `docker-compose.yml`).
#
#  Cualquier contenedor resuelto se comprueba contra la etiqueta
#  `com.docker.compose.project`: si es de otra pila se ABORTA (código 2) con un mensaje
#  claro, también cuando viene de una variable de anulación. La comprobación falla
#  cerrada: un contenedor sin etiqueta de Compose tampoco se toca.
#
#  Se carga con `.`; no ejecuta nada por sí solo y no depende de `bash`.
# ============================================================================

# Prefijo de nombres de contenedor. Por defecto, el de siempre: `hwe`.
hwe_container_prefix() {
	printf '%s' "${HWE_CONTAINER_PREFIX:-hwe}"
}

# Proyecto de Compose de este repo: `COMPOSE_PROJECT_NAME` o el nombre de la carpeta
# (el valor por defecto de Compose, que es el nombre del proyecto en `docker compose ps`).
hwe_compose_project() {
	if [ -n "${COMPOSE_PROJECT_NAME:-}" ]; then
		printf '%s' "$COMPOSE_PROJECT_NAME"
		return 0
	fi
	basename "$(pwd)"
}

# Nombre por defecto de un servicio: `wp-cron` → `hwe_wp_cron`.
hwe_default_container_name() {
	printf '%s_%s' "$(hwe_container_prefix)" "$(printf '%s' "$1" | tr '-' '_')"
}

# Etiqueta de proyecto de Compose de un contenedor (vacía si no existe o no la tiene).
hwe_container_project() {
	docker inspect -f '{{ index .Config.Labels "com.docker.compose.project" }}' "$1" 2>/dev/null \
		| head -n1
}

# hwe_resolve_container <servicio> [anulación] [nombre-de-la-variable]
#
# Imprime la referencia del contenedor (id de Compose o nombre) y devuelve:
#   0 = existe y es de ESTE proyecto
#   1 = no hay contenedor para ese servicio
#   2 = el contenedor es de otro proyecto (o no tiene proyecto): NO se debe tocar
hwe_resolve_container() {
	_hwe_service="$1"
	_hwe_override="${2:-}"
	_hwe_override_name="${3:-}"
	_hwe_project="$(hwe_compose_project)"

	if [ -n "$_hwe_override" ]; then
		if ! docker inspect "$_hwe_override" >/dev/null 2>&1; then
			echo "hwe: ${_hwe_override_name:-la variable de anulación} apunta a «$_hwe_override», que no existe." >&2
			return 1
		fi
		_hwe_label="$(hwe_container_project "$_hwe_override")"
		if [ "$_hwe_label" != "$_hwe_project" ]; then
			echo "hwe: ${_hwe_override_name:-la variable de anulación} apunta a «$_hwe_override», del proyecto de Compose «${_hwe_label:-sin proyecto}», y no del de este repo («$_hwe_project»). Abortado: no se toca otra pila; exporta COMPOSE_PROJECT_NAME o cambia la variable." >&2
			return 2
		fi
		printf '%s' "$_hwe_override"
		return 0
	fi

	_hwe_id="$(docker compose ps -q "$_hwe_service" 2>/dev/null | head -n1)"
	if [ -n "$_hwe_id" ]; then
		# Compose ya filtra por proyecto, pero se comprueba igual: la etiqueta es la prueba
		# (y cubre un `COMPOSE_PROJECT_NAME` que no coincida con el del contenedor).
		_hwe_label="$(hwe_container_project "$_hwe_id")"
		if [ "$_hwe_label" != "$_hwe_project" ]; then
			echo "hwe: el contenedor $_hwe_id (servicio $_hwe_service) pertenece al proyecto de Compose «${_hwe_label:-sin proyecto}», no al de este repo («$_hwe_project»). Abortado: no se toca otra pila. Exporta COMPOSE_PROJECT_NAME o HWE_CONTAINER_PREFIX (docs/CREATE_INSTANCE.md §2)." >&2
			return 2
		fi
		printf '%s' "$_hwe_id"
		return 0
	fi

	_hwe_default="$(hwe_default_container_name "$_hwe_service")"
	if docker inspect "$_hwe_default" >/dev/null 2>&1; then
		_hwe_label="$(hwe_container_project "$_hwe_default")"
		if [ "$_hwe_label" != "$_hwe_project" ]; then
			echo "hwe: el contenedor $_hwe_default (servicio $_hwe_service) pertenece al proyecto de Compose «${_hwe_label:-sin proyecto}», no al de este repo («$_hwe_project»). Abortado: no se toca otra pila. Exporta COMPOSE_PROJECT_NAME o HWE_CONTAINER_PREFIX (docs/CREATE_INSTANCE.md §2)." >&2
			return 2
		fi
		printf '%s' "$_hwe_default"
		return 0
	fi

	return 1
}
