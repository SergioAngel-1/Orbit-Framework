#!/bin/sh
# ============================================================================
#  lib/load-env.sh — Carga un archivo .env SIN ejecutarlo. Para `. lib/load-env.sh`.
#
#  Los scripts de backup hacían `. ./.env` (source). Eso trata el archivo como código de
#  shell: un valor sin comillas con espacios (`WP_TITLE=Mi Tienda`) rompe con «orden no
#  encontrada», y una línea con `$(…)` o con comillas invertidas se EJECUTARÍA. Aquí se
#  parsea línea a línea y los valores son datos, nunca código.
#
#  Reglas (las de Docker Compose, para que el .env signifique lo mismo en todas partes):
#    · se ignoran líneas vacías, comentarios (`# …`) y las que no son `CLAVE=valor`;
#    · se admite el prefijo `export` y espacios al principio;
#    · la clave es un identificador (`[A-Za-z_][A-Za-z0-9_]*`); otra cosa se ignora;
#    · el valor es todo lo que sigue al primer `=` (puede llevar `=`: relleno base64);
#    · se quitan las comillas simples o dobles que envuelven el valor, y en un valor SIN
#      comillas un comentario final (` # …`, con espacio delante: `abc#123` no lo es);
#    · se tolera CRLF (.env creado en Windows);
#    · una variable YA definida en el entorno gana: no se pisa (como `docker compose`).
#
#  Uso:  . "$(dirname "$0")/lib/load-env.sh"; hwe_load_env .env
# ============================================================================

hwe_load_env() {
	_hwe_file="${1:-.env}"
	[ -f "$_hwe_file" ] || return 0

	_hwe_cr="$(printf '\r')"
	# `|| [ -n "$_hwe_line" ]` lee también la última línea si no acaba en salto de línea.
	while IFS= read -r _hwe_line || [ -n "$_hwe_line" ]; do
		_hwe_line="${_hwe_line%"$_hwe_cr"}"
		# Espacios iniciales.
		_hwe_line="${_hwe_line#"${_hwe_line%%[![:space:]]*}"}"
		case "$_hwe_line" in
			'' | '#'*) continue ;;
		esac
		_hwe_line="${_hwe_line#export }"
		_hwe_line="${_hwe_line#"${_hwe_line%%[![:space:]]*}"}"
		case "$_hwe_line" in
			*=*) ;;
			*) continue ;;
		esac

		_hwe_key="${_hwe_line%%=*}"
		_hwe_value="${_hwe_line#*=}"
		case "$_hwe_key" in
			'' | [0-9]* | *[!A-Za-z0-9_]*) continue ;;
		esac

		case "$_hwe_value" in
			\"*\") _hwe_value="${_hwe_value#\"}"; _hwe_value="${_hwe_value%\"}" ;;
			\'*\') _hwe_value="${_hwe_value#\'}"; _hwe_value="${_hwe_value%\'}" ;;
			*)
				# Sin comillas: un comentario final lleva espacio delante.
				_hwe_value="${_hwe_value%% #*}"
				_hwe_value="${_hwe_value%"${_hwe_value##*[![:space:]]}"}"
				;;
		esac

		# La clave ya está validada como identificador: el `eval` solo lee su nombre.
		eval "_hwe_defined=\${$_hwe_key+set}"
		if [ -z "$_hwe_defined" ]; then
			export "$_hwe_key=$_hwe_value"
		fi
	done < "$_hwe_file"

	unset _hwe_file _hwe_cr _hwe_line _hwe_key _hwe_value _hwe_defined
}
