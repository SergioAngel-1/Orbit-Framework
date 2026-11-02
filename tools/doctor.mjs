#!/usr/bin/env node
// ============================================================================
//  tools/doctor.mjs — Comprobación de integración contra una pila levantada.
//
//  Encuentra los fallos que hasta ahora solo detectaba una auditoría manual:
//  origen de la marca distinto del frontend (los enlaces de correo saldrían
//  mal), recuperación de contraseña que da 5xx por un origen mal configurado,
//  URL del sitemap que responden 404 o redirigen, JSON-LD de producto con
//  imagen vacía, «soft 404» e imágenes del BFF que next/image rechaza. Lógica en tools/doctor/checks.mjs (pura) y
//  tools/doctor/run.mjs (peticiones). Esta CLI solo lee argumentos y formatea.
//
//  Uso:  node tools/doctor.mjs [--frontend URL] [--wordpress URL] [--json] [--max-urls N]
//  Sin dependencias: Node ≥ 20. Sale con 1 si hay algún fallo (fail).
// ============================================================================

import { parseArgs } from "node:util";
import { originOf, exitCodeFor, formatJson, formatTable } from "./doctor/checks.mjs";
import { readSecret, runDoctor } from "./doctor/run.mjs";

const USAGE = `Uso: node tools/doctor.mjs [opciones]

  --frontend URL     Frontend (web y BFF). Por defecto http://localhost:3000
  --wordpress URL    WordPress. Por defecto http://localhost:8080
  --json             Salida en JSON en vez de tabla
  --max-urls N       Máximo de URL del sitemap que se piden (por defecto 50)
  -h, --help         Esta ayuda

Lee HWE_REVALIDATION_SECRET del entorno o de .env / frontend/.env.local. Nunca lo imprime.
Sale con 1 si hay algún fallo.`;

function fail(message, code = 2) {
	console.error(`doctor: ${message}`);
	console.error("");
	console.error(USAGE);
	process.exit(code);
}

let values;
try {
	({ values } = parseArgs({
		options: {
			frontend: { type: "string" },
			wordpress: { type: "string" },
			json: { type: "boolean" },
			"max-urls": { type: "string" },
			help: { type: "boolean", short: "h" },
		},
		strict: true,
	}));
} catch (err) {
	fail(err.message);
}

if (values.help) {
	console.log(USAGE);
	process.exit(0);
}

const frontend = values.frontend ?? "http://localhost:3000";
const wordpress = values.wordpress ?? "http://localhost:8080";
for (const [name, url] of [["--frontend", frontend], ["--wordpress", wordpress]]) {
	if (!originOf(url) || !/^https?:/.test(url)) fail(`${name} debe ser una URL http(s) absoluta (recibido «${url}»)`);
}

const maxUrls = values["max-urls"] === undefined ? 50 : Number(values["max-urls"]);
if (!Number.isInteger(maxUrls) || maxUrls < 1 || maxUrls > 10_000) {
	fail(`--max-urls debe ser un entero entre 1 y 10000 (recibido «${values["max-urls"]}»)`);
}

try {
	const { value: secret } = readSecret();
	const results = await runDoctor({ frontend, wordpress, secret, maxUrls });
	process.stdout.write(`${values.json ? formatJson(results) : formatTable(results)}\n`);
	process.exitCode = exitCodeFor(results);
} catch (err) {
	console.error(`doctor: error inesperado: ${err instanceof Error ? err.message : err}`);
	process.exitCode = 2;
}
