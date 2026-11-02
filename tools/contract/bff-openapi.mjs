#!/usr/bin/env node
// ============================================================================
//  Inventario OpenAPI 3.1 del BFF, leído de los `route.ts` reales.
//
//  Uso:
//    node tools/contract/bff-openapi.mjs                     # núcleo → stdout
//    node tools/contract/bff-openapi.mjs --include-instance  # + app/api/instance/**
//
//  El framework versiona la salida del núcleo en `contract/bff.openapi.json` y
//  `frontend/tests/unit/contract-bff.test.ts` falla si una ruta cambia y el
//  fichero no (se regenera con `HWE_UPDATE_CONTRACT=1` en ese test).
//
//  Es análisis estático, a propósito sin cargar los módulos de Next: por cada
//  método exportado se anotan las guardas que su cuerpo llama (módulo, flag,
//  `guardMutation`, sesión, firma). Los cuerpos y respuestas no se describen:
//  viven en los schemas zod de cada ruta, enlazada en `x-hwe-source`, y en
//  `docs/FRONTEND_CONNECT.md` §A.2.
// ============================================================================

import { readdirSync, readFileSync, statSync } from "node:fs";
import { dirname, join, relative, sep } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "../..");
const API_DIR = join(ROOT, "frontend/src/app/api");
const METHODS = ["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"];
const EXPORT = new RegExp(
  `export\\s+(?:async\\s+function|const)\\s+(${METHODS.join("|")})\\b`,
  "g",
);

/** @param {string} dir */
function routeFiles(dir) {
  return readdirSync(dir)
    .sort()
    .flatMap((name) => {
      const full = join(dir, name);
      if (statSync(full).isDirectory()) return routeFiles(full);
      return /^route\.tsx?$/.test(name) ? [full] : [];
    });
}

/** `store/orders/[id]` → `/api/store/orders/{id}` y sus parámetros. */
function pathOf(file, apiDir) {
  const segments = relative(apiDir, dirname(file)).split(sep);
  const params = [];
  const url = segments
    .map((segment) => {
      const match = /^\[(?:\.\.\.)?(.+)\]$/.exec(segment);
      if (!match) return segment;
      params.push(match[1]);
      return `{${match[1]}}`;
    })
    .join("/");
  return { url: `/api/${url}`, params };
}

/** Cuerpo de cada método exportado: del `export` al siguiente `export` de método. */
function methodBodies(source) {
  const marks = [...source.matchAll(EXPORT)].map((m) => ({
    method: m[1],
    at: m.index,
  }));
  return marks.map((mark, i) => ({
    method: mark.method,
    body: source.slice(mark.at, marks[i + 1]?.at ?? source.length),
  }));
}

/** Último literal de cadena dentro de cada llamada a `fn(...)` (hasta el `;`). */
function literalArgs(body, fn) {
  const found = [];
  for (const match of body.matchAll(new RegExp(`\\b${fn}\\(([^;]*)`, "g"))) {
    const literals = [...match[1].matchAll(/"([a-z0-9_-]+)"/g)].map(
      (m) => m[1],
    );
    if (literals.length) found.push(literals.at(-1));
  }
  return [...new Set(found)];
}

/**
 * Código que ejecuta un método: su cuerpo más los ayudantes locales del módulo
 * a los que llama (muchas rutas comparten un `handle()` o `guard()` local).
 */
function reachable(body, shared) {
  const helpers = [
    ...shared.matchAll(/(?:async\s+)?function\s+([A-Za-z0-9_]+)\s*\(/g),
  ];
  let text = body;
  helpers.forEach((match, i) => {
    if (!new RegExp(`\\b${match[1]}\\(`).test(body)) return;
    text +=
      "\n" + shared.slice(match.index, helpers[i + 1]?.index ?? shared.length);
  });
  return text;
}

/** Valor numérico de un literal o de una `const NOMBRE = 123` del módulo; si no, undefined. */
function numberOf(raw, shared) {
  const value = raw.trim();
  if (/^\d[\d_]*$/.test(value)) return Number(value.replace(/_/g, ""));
  const constant = new RegExp(`\\bconst\\s+${value}\\s*=\\s*([\\d_]+)\\s*;`).exec(shared);
  return constant ? Number(constant[1].replace(/_/g, "")) : undefined;
}

/**
 * Límites por IP de las opciones `rateLimit: { name, limit, windowSeconds, strict }` que
 * reciben `guardMutation` / `instanceRoute`. Lo que no es literal ni constante del módulo se omite.
 */
function rateLimits(text, shared) {
  const found = [];
  for (const match of text.matchAll(/\brateLimit:\s*\{([^}]*)\}/g)) {
    const fields = match[1];
    const entry = {};
    const name = /\bname:\s*"([^"]+)"/.exec(fields);
    if (name) entry.name = name[1];
    for (const key of ["limit", "windowSeconds"]) {
      const raw = new RegExp(`\\b${key}:\\s*([A-Za-z0-9_]+)`).exec(fields);
      const value = raw ? numberOf(raw[1], shared) : undefined;
      if (value !== undefined) entry[key] = value;
    }
    if (/\bstrict:\s*true\b/.test(fields)) entry.strict = true;
    if (Object.keys(entry).length) found.push(entry);
  }
  return found;
}

/** Lo que un método hace antes de su negocio. */
function describe(method, body, shared) {
  const text = reachable(body, shared);
  const operation = {};
  const modules = literalArgs(text, "requireModule");
  const flags = literalArgs(text, "requireFlag");
  const loyalty = literalArgs(text, "requireLoyalty");
  if (modules.length) operation["x-hwe-module"] = modules;
  if (flags.length) operation["x-hwe-flag"] = flags;
  if (loyalty.length) operation["x-hwe-loyalty-feature"] = loyalty;

  const signed =
    /\b(?:verifyWooWebhook|verifyHweSignature|verifyHmacSignature)\(|\.verifyWebhook\(/.test(
      text,
    );
  const guarded = /\bguardMutation\(|\binstanceRoute\(/.test(text);
  // `instanceRoute({ public: true })`: ruta de máquina sin Origin ni CSRF (solo tope de cuerpo
  // y límite por IP), bajo `/api/instance/public/`.
  const isPublic = /\binstanceRoute\(/.test(text) && /\bpublic:\s*true\b/.test(text);
  if (signed) {
    operation["x-hwe-auth"] = "signature";
  } else if (method !== "GET" && method !== "OPTIONS") {
    operation["x-hwe-csrf"] = guarded && !isPublic && !/csrf:\s*false/.test(text);
  }
  if (isPublic) operation["x-hwe-public"] = true;
  const limits = rateLimits(text, shared);
  if (limits.length) operation["x-hwe-rate-limit"] = limits;
  // `required`: sin sesión → 401 garantizado. `read`: la ruta lee la sesión y decide
  // (puede responder 401 o comportarse distinto con y sin cuenta).
  if (/\brequireSession\(|session:\s*"required"/.test(text)) {
    operation["x-hwe-session"] = "required";
  } else if (/\bgetSession\(|session:\s*"optional"/.test(text)) {
    operation["x-hwe-session"] = "read";
  }
  if (/Idempotency-Key/i.test(text)) operation["x-hwe-idempotency-key"] = true;
  return operation;
}

/**
 * @param {{ includeInstance?: boolean, apiDir?: string }} [options] `apiDir`: carpeta `app/api`
 *   que se recorre (por defecto la del frontend; los tests pasan una de ejemplo).
 * @returns {Record<string, unknown>} documento OpenAPI 3.1
 */
export function buildBffOpenApi({ includeInstance = false, apiDir = API_DIR } = {}) {
  const paths = {};
  for (const file of routeFiles(apiDir)) {
    const source = readFileSync(file, "utf8");
    const { url, params } = pathOf(file, apiDir);
    if (!includeInstance && url.startsWith("/api/instance/")) continue;
    const bodies = methodBodies(source);
    const firstExport = bodies.length ? source.search(EXPORT) : source.length;
    const shared = source.slice(0, firstExport);
    const item = {};
    if (params.length) {
      item.parameters = params.map((name) => ({
        name,
        in: "path",
        required: true,
        schema: { type: "string" },
      }));
    }
    for (const { method, body } of bodies) {
      item[method.toLowerCase()] = {
        "x-hwe-source": relative(ROOT, file).split(sep).join("/"),
        ...describe(method, body, shared),
        responses: {
          default: {
            description: "Ver x-hwe-source y docs/FRONTEND_CONNECT.md §A.2.",
          },
        },
      };
    }
    if (bodies.length) paths[url] = item;
  }
  return {
    openapi: "3.1.0",
    info: {
      title: "Orbit BFF",
      version: readFileSync(join(ROOT, "FRAMEWORK_VERSION"), "utf8").trim(),
      description:
        "Inventario de las rutas del BFF (Next.js Route Handlers) generado desde el código por tools/contract/bff-openapi.mjs; no se edita a mano. Sesión en cookies httpOnly; toda escritura con x-hwe-csrf exige el token de GET /api/csrf en X-CSRF-Token y un Origin permitido. Las x-hwe-* describen lo que cada método consulta antes de su negocio: x-hwe-module (apagado → 404), x-hwe-flag (flags que lee; casi siempre apagado → 404, ver la fuente), x-hwe-session (required → 401 sin sesión; read → la lee y decide), x-hwe-auth: signature (webhook firmado, sin CSRF), x-hwe-public (ruta de máquina de la instancia, sin Origin ni CSRF) y x-hwe-rate-limit (límites por IP declarados en las opciones de la guarda; su ausencia no significa que no haya límite: ver la fuente).",
    },
    paths,
  };
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const doc = buildBffOpenApi({
    includeInstance: process.argv.includes("--include-instance"),
  });
  process.stdout.write(JSON.stringify(doc, null, 2) + "\n");
}
