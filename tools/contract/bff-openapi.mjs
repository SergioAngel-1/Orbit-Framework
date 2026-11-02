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

/** Texto entre el paréntesis o la llave en `open` y su pareja (sin contar cadenas). */
function balanced(source, open) {
  const pairs = { "(": ")", "{": "}", "[": "]" };
  let depth = 0;
  let quote = null;
  for (let i = open; i < source.length; i += 1) {
    const ch = source[i];
    if (quote) {
      if (ch === "\\") i += 1;
      else if (ch === quote) quote = null;
      continue;
    }
    if (ch === '"' || ch === "'" || ch === "`") quote = ch;
    else if (ch in pairs) depth += 1;
    else if (ch === ")" || ch === "}" || ch === "]") {
      depth -= 1;
      if (depth === 0) return source.slice(open + 1, i);
    }
  }
  return null;
}

/** Trozos de `text` separados por comas de primer nivel. */
function splitTopLevel(text) {
  const parts = [];
  let depth = 0;
  let quote = null;
  let from = 0;
  for (let i = 0; i < text.length; i += 1) {
    const ch = text[i];
    if (quote) {
      if (ch === "\\") i += 1;
      else if (ch === quote) quote = null;
      continue;
    }
    if (ch === '"' || ch === "'" || ch === "`") quote = ch;
    else if ("({[".includes(ch)) depth += 1;
    else if (")}]".includes(ch)) depth -= 1;
    else if (ch === "," && depth === 0) {
      parts.push(text.slice(from, i));
      from = i + 1;
    }
  }
  parts.push(text.slice(from));
  return parts.map((p) => p.trim()).filter(Boolean);
}

/** Inicializador de `const NOMBRE = …` del módulo (sin `as const` ni `satisfies`), o null. */
function constInit(name, shared) {
  const match = new RegExp(`\\bconst\\s+${name}\\b[^=]*=\\s*`).exec(shared);
  if (!match) return null;
  const at = match.index + match[0].length;
  const init = "({[".includes(shared[at])
    ? shared[at] + balanced(shared, at) + { "(": ")", "{": "}", "[": "]" }[shared[at]]
    : shared.slice(at, shared.indexOf(";", at) === -1 ? undefined : shared.indexOf(";", at));
  return init.replace(/\s+(?:as\s+const|satisfies\s+[\w.<>]+)\s*$/, "").trim();
}

/** Campos `clave: valor` de primer nivel de un literal de objeto `{ … }` (con `...CONST`). */
function objectFields(literal, shared, depth) {
  const body = literal.trim().startsWith("{") ? balanced(literal.trim(), 0) : null;
  if (body === null) return null;
  const fields = {};
  for (const part of splitTopLevel(body)) {
    if (part.startsWith("...")) {
      const spread = evaluate(part.slice(3), shared, depth + 1);
      if (spread && typeof spread === "object") Object.assign(fields, spread);
      continue;
    }
    const match = /^([A-Za-z_$][\w$]*)\s*:\s*([\s\S]+)$/.exec(part);
    if (match) fields[match[1]] = match[2].trim();
    else if (/^[A-Za-z_$][\w$]*$/.test(part)) fields[part] = part; // abreviado `{ limit }`
  }
  return fields;
}

/**
 * Valor de una expresión con lo que se sabe sin ejecutar el módulo: literales, `as const`,
 * aritmética entera (`15 * 60`), constantes del módulo y campos de un objeto constante
 * (`RATE_LIMIT.limit`). Lo que no resuelve es undefined.
 */
function evaluate(expr, shared, depth = 0) {
  if (depth > 5 || expr === undefined || expr === null) return undefined;
  let value = String(expr).trim().replace(/\s+as\s+const$/, "").trim();
  if (/^"[^"]*"$|^'[^']*'$/.test(value)) return value.slice(1, -1);
  if (value === "true" || value === "false") return value === "true";
  if (value.startsWith("{")) return objectFields(value, shared, depth) ?? undefined;
  if (/^[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)?$/.test(value)) {
    return evaluate(resolveRef(value, shared, depth), shared, depth + 1);
  }
  // Sustituye identificadores y `OBJ.campo` por su número y evalúa la aritmética.
  const substituted = value.replace(/[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)?/g, (ref) => {
    const resolved = evaluate(resolveRef(ref, shared, depth), shared, depth + 1);
    return typeof resolved === "number" ? String(resolved) : "NaN";
  });
  if (!/^[\d_\s+\-*/().]+$/.test(substituted)) return undefined;
  const number = Function(`"use strict"; return (${substituted.replace(/_/g, "")});`)();
  return Number.isFinite(number) ? number : undefined;
}

/** `NOMBRE` → su inicializador; `OBJ.campo` → el valor del campo en el objeto constante. */
function resolveRef(ref, shared, depth) {
  const [name, field] = ref.split(".");
  const init = constInit(name, shared);
  if (init === null) return undefined;
  if (!field) return init;
  const fields = evaluate(init, shared, depth + 1);
  return fields && typeof fields === "object" ? fields[field] : undefined;
}

/** `{ name, limit, windowSeconds, strict }` a partir de los campos (o valores) declarados. */
function limitEntry({ name, limit, windowSeconds, strict }, shared) {
  const entry = {};
  const n = evaluate(name, shared);
  if (typeof n === "string") entry.name = n;
  for (const [key, raw] of [["limit", limit], ["windowSeconds", windowSeconds]]) {
    const value = evaluate(raw, shared);
    if (typeof value === "number") entry[key] = value;
  }
  if (evaluate(strict, shared) === true) entry.strict = true;
  return entry;
}

/**
 * Límites por IP de un método: las opciones `rateLimit: { … }` (o una constante con ese
 * objeto) de `guardMutation` / `instanceRoute`, y las llamadas directas a
 * `rateLimit(clave, límite, ventana, { strict })`. El nombre de una llamada directa es el
 * prefijo literal de su clave (`mcp:${ip}` → `mcp`).
 */
function rateLimits(text, shared) {
  const found = [];
  for (const match of text.matchAll(/\brateLimit\s*:\s*/g)) {
    const at = match.index + match[0].length;
    const raw = text[at] === "{" ? `{${balanced(text, at)}}` : /^[\w$.]+/.exec(text.slice(at))?.[0];
    const fields = evaluate(raw, shared);
    if (fields && typeof fields === "object") {
      const entry = limitEntry(fields, shared);
      if (Object.keys(entry).length) found.push(entry);
    }
  }
  for (const match of text.matchAll(/(?<![.\w])rateLimit\(/g)) {
    const args = splitTopLevel(balanced(text, match.index + match[0].length - 1) ?? "");
    if (args.length < 3) continue;
    const key = /^["'`]([\w.-]+)/.exec(args[0]);
    const options = evaluate(args[3], shared);
    const entry = limitEntry(
      {
        name: key ? JSON.stringify(key[1]) : undefined,
        limit: args[1],
        windowSeconds: args[2],
        strict: options && typeof options === "object" ? options.strict : undefined,
      },
      shared,
    );
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
