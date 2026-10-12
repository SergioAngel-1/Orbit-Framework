#!/bin/sh
# ============================================================================
#  lib/verified-download.sh — Descargas con verificación de SHA-256. Para `. lib/...`.
#
#  Cadena de suministro: lo que `setup.sh` baja de GitHub (plugins sin release en
#  wordpress.org) está fijado a una versión concreta Y a un hash. Una descarga cuyo hash
#  no coincide NO se instala: ni el archivo descargado ni, MENOS AÚN, un «reintento
#  directo desde la URL» (que traería el mismo artefacto manipulado y dejaría la
#  verificación en puro teatro).
# ============================================================================

# hwe_download_verified <url> <sha256> <destino>
#
#  Devuelve 0 solo si la descarga termina y su SHA-256 coincide. En cualquier otro caso
#  (curl ausente, descarga fallida, hash distinto) borra <destino>, lo explica por la
#  salida de error y devuelve distinto de 0; quien llama decide si es fatal.
hwe_download_verified() {
	_hwe_url="$1"
	_hwe_expected="$2"
	_hwe_dest="$3"

	if ! command -v curl >/dev/null 2>&1 || ! command -v sha256sum >/dev/null 2>&1; then
		echo "    ✗ Faltan curl o sha256sum: no se puede verificar la descarga de $_hwe_url. No se instala." >&2
		return 2
	fi

	if ! curl -fsSL -o "$_hwe_dest" "$_hwe_url"; then
		rm -f "$_hwe_dest"
		echo "    ✗ No se pudo descargar $_hwe_url. No se instala." >&2
		return 1
	fi

	_hwe_actual="$(sha256sum "$_hwe_dest" | cut -d' ' -f1)"
	if [ "$_hwe_actual" != "$_hwe_expected" ]; then
		rm -f "$_hwe_dest"
		echo "    ✗ El SHA256 de $_hwe_url NO coincide (esperado $_hwe_expected, obtenido $_hwe_actual). No se instala." >&2
		return 1
	fi
	return 0
}
