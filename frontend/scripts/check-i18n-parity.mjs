#!/usr/bin/env node
// Verifica que el catálogo de cada idioma secundario de `i18n/routing.ts` siga siendo la
// MISMA página que `es.json`, y que los mensajes con etiquetas tengan quien las pinte.
// Uso: `npm run i18n:check`. Con un solo idioma no hay paridad que comprobar y pasa.
//
// Cinco comprobaciones por idioma secundario, todas deterministas salvo la última:
//
//  1. CLAVES — las mismas hojas en los dos archivos.
//  2. FORMA — el mismo TIPO en cada hoja (texto / lista / objeto) y, en las
//     listas, el mismo NÚMERO de elementos. Tratar una lista como hoja sin mirar
//     dentro dejaba pasar una de 5 ítems en español y 4 en inglés como paridad
//     correcta, y en `/en` se perdía un ítem sin que nada fallara. Importa en las
//     listas cuya longitud ES el diseño (tarjetas, pasos, columnas del footer).
//  3. ARGUMENTOS — los mismos `{placeholders}` en los dos idiomas. Uno que
//     falte en la traducción no es un hueco: next-intl lanza al formatear y la
//     clave se pinta cruda con un ⚠️.
//  4. ETIQUETAS — las mismas `<tag>` en los dos idiomas, por la misma razón.
//  5. HANDLERS (heurística, ver abajo) — toda etiqueta de un mensaje tiene su
//     función en la llamada `t.rich(...)` que lo pinta. Es el fallo que motivó
//     esta comprobación: añadir un `<em>` a un mensaje cuyo `t.rich` solo definía
//     `strong` dejaba «⚠️ clave.del.mensaje» en pantalla, con la paridad de
//     claves en verde y el build sin una sola queja.
//
// La quinta cruza los mensajes con el código, así que no puede ser exacta: la
// llamada se localiza por el ÚLTIMO segmento de la clave. Si una etiqueta
// aparece en un mensaje y la llamada que lo pinta no la define, es ERROR (el
// cruce es firme). Si no se encuentra ninguna llamada `rich` para esa clave,
// es AVISO y no rompe: puede ser una clave construida en tiempo de ejecución.
//
// La lógica vive en `checkParity()`, pura y sin tocar disco (la prueba
// tests/unit/i18n-parity.test.ts la ejercita con fixtures); el CLI de abajo solo
// lee los archivos y llama a esa función.
import { readFileSync, readdirSync, statSync } from "node:fs";
import { fileURLToPath, pathToFileURL } from "node:url";
import { dirname, resolve, join } from "node:path";

/**
 * Prefijos de claves de CONTENIDO de la instancia que no se exige traducir al
 * inglés (p. ej. un namespace de rutas propias de un sitio monolingüe). El
 * framework no declara ninguno: una instancia que los necesite los añade aquí.
 *
 * @type {string[]}
 */
export const ES_ONLY_PREFIXES = [];

/** Hojas del árbol. Un array NO es hoja: se recorre por índice (`clave[0]`). */
function leaves(node, prefix = "", out = new Map()) {
  if (Array.isArray(node)) {
    node.forEach((v, i) => leaves(v, `${prefix}[${i}]`, out));
    return out;
  }
  if (node && typeof node === "object") {
    for (const [k, v] of Object.entries(node)) {
      leaves(v, prefix ? `${prefix}.${k}` : k, out);
    }
    return out;
  }
  out.set(prefix, node);
  return out;
}

/** Contenedores (objetos y listas) con su tipo y tamaño, para comparar forma. */
function shapes(node, prefix = "", out = new Map()) {
  if (Array.isArray(node)) {
    out.set(prefix, { kind: "lista", size: node.length });
    node.forEach((v, i) => shapes(v, `${prefix}[${i}]`, out));
    return out;
  }
  if (node && typeof node === "object") {
    if (prefix) out.set(prefix, { kind: "objeto", size: Object.keys(node).length });
    for (const [k, v] of Object.entries(node)) {
      shapes(v, prefix ? `${prefix}.${k}` : k, out);
    }
    return out;
  }
  out.set(prefix, { kind: "texto", size: null });
  return out;
}

/** `{year}`, `{count, plural, …}` → nombres de argumento. */
const argsOf = (s) =>
  new Set([...String(s).matchAll(/\{\s*(\w+)\s*[,}]/g)].map((m) => m[1]));

/** `<em>`, `<br/>`, `</strong>` → nombres de etiqueta. */
const tagsOf = (s) =>
  new Set([...String(s).matchAll(/<\/?(\w+)\s*\/?>/g)].map((m) => m[1]));

const sorted = (set) => [...set].sort().join(", ") || "(ninguna)";

/** Nombres de propiedad en el NIVEL SUPERIOR de un cuerpo de objeto. */
function propertyNames(body) {
  const names = new Set();
  let level = 0;
  for (const token of body.matchAll(/[{}()[\]]|(\b\w+\b)\s*:/g)) {
    const ch = token[0];
    if (ch === "{" || ch === "(" || ch === "[") level++;
    else if (ch === "}" || ch === ")" || ch === "]") level--;
    else if (token[1] && level === 0) names.add(token[1]);
  }
  return names;
}

/** Índice del `}` que cierra la `{` de `open`, contando llaves anidadas. */
function closingBrace(code, open) {
  let depth = 0;
  let close = open;
  for (; close < code.length; close++) {
    if (code[close] === "{") depth++;
    else if (code[close] === "}" && --depth === 0) break;
  }
  return close;
}

/**
 * Claves que el código pinta como HTML (`t.raw(...)` + `dangerouslySetInnerHTML`
 * + `sanitizeHtml`). Ahí las etiquetas NO son etiquetas de next-intl y no
 * necesitan handler, así que no se avisa.
 */
function rawKeys(sources) {
  const keys = new Set();
  for (const { code } of sources) {
    for (const m of code.matchAll(/\braw\(\s*["'`]([^"'`]+)["'`]\s*\)/g))
      keys.add(m[1]);
  }
  return keys;
}

/**
 * Handlers declarados en cada `…rich("clave", { … })` del código, indexados
 * por el nombre de la clave tal y como se escribe en la llamada. El objeto se
 * recorta contando llaves desde la primera `{` que sigue a la coma, así que
 * un handler que contenga JSX con llaves no lo descuadra.
 *
 * Los handlers compartidos llegan por SPREAD (`{ tag: …, ...mark }`, un patrón
 * habitual cuando varias llamadas reusan el mismo `mark`): un `...ident` se
 * resuelve buscando `const ident = { … }` en el mismo archivo. Sin esto, esas
 * claves saldrían como etiqueta sin handler — un falso positivo que dejaría el
 * chequeo en rojo permanente y, con él, inservible.
 */
function richHandlers(sources) {
  const byKey = new Map();
  for (const { path, code } of sources) {
    const spreads = new Map();
    for (const c of code.matchAll(/\bconst\s+(\w+)\s*=\s*\{/g)) {
      const open = code.indexOf("{", c.index);
      spreads.set(c[1], propertyNames(code.slice(open + 1, closingBrace(code, open))));
    }

    for (const m of code.matchAll(/\brich\(\s*["'`]([^"'`]+)["'`]\s*,/g)) {
      const start = code.indexOf("{", m.index + m[0].length - 1);
      if (start === -1) continue;
      const body = code.slice(start + 1, closingBrace(code, start));
      const names = propertyNames(body);
      for (const sp of body.matchAll(/\.\.\.(\w+)/g)) {
        for (const n of spreads.get(sp[1]) ?? []) names.add(n);
      }
      const entry = byKey.get(m[1]) ?? { names: new Set(), files: new Set() };
      for (const n of names) entry.names.add(n);
      entry.files.add(path);
      byKey.set(m[1], entry);
    }
  }
  return byKey;
}

/**
 * Ejecuta las cinco comprobaciones.
 *
 * @param {object} input
 * @param {unknown} input.es Mensajes en español (ya parseados).
 * @param {unknown} input.en Mensajes en inglés (ya parseados).
 * @param {{ path: string, code: string }[]} input.sources Código del frontend
 *   que pinta mensajes (para la comprobación 5). Los `*.test.*` se ignoran.
 * @param {string[]} [input.esOnlyPrefixes] Prefijos exentos de traducción.
 * @param {string} [input.locale] Código del idioma comparado (solo para los mensajes).
 * @returns {{ errors: string[], warnings: string[], leafCount: number }}
 */
export function checkParity({
  es,
  en,
  sources,
  esOnlyPrefixes = ES_ONLY_PREFIXES,
  locale = "en",
}) {
  const isEsOnly = (k) => esOnlyPrefixes.some((p) => k.startsWith(p));
  const esLeaves = leaves(es);
  const enLeaves = leaves(en);
  const esShapes = shapes(es);
  const enShapes = shapes(en);

  const errors = [];
  const push = (titulo, lineas) => {
    if (lineas.length)
      errors.push(`${titulo} (${lineas.length}):\n  ${lineas.join("\n  ")}`);
  };

  // ── 1. Claves ─────────────────────────────────────────────────────────────
  push(
    `Faltan en ${locale}.json`,
    [...esLeaves.keys()].filter((k) => !enLeaves.has(k) && !isEsOnly(k)),
  );
  push(
    "Faltan en es.json",
    [...enLeaves.keys()].filter((k) => !esLeaves.has(k)),
  );

  // ── 2. Forma ──────────────────────────────────────────────────────────────
  const shapeIssues = [];
  for (const [k, a] of esShapes) {
    if (isEsOnly(k)) continue;
    const b = enShapes.get(k);
    if (!b) continue; // ya lo reporta la comprobación de claves
    if (a.kind !== b.kind)
      shapeIssues.push(`${k}: es es ${a.kind} y ${locale} es ${b.kind}`);
    else if (a.kind === "lista" && a.size !== b.size)
      shapeIssues.push(
        `${k}: la lista tiene ${a.size} en es y ${b.size} en ${locale}`,
      );
  }
  push("Forma distinta entre idiomas", shapeIssues);

  // ── 3 y 4. Argumentos y etiquetas ─────────────────────────────────────────
  const argIssues = [];
  const tagIssues = [];
  for (const [k, valueEs] of esLeaves) {
    if (isEsOnly(k) || typeof valueEs !== "string") continue;
    const valueEn = enLeaves.get(k);
    if (typeof valueEn !== "string") continue;
    const [ae, an] = [argsOf(valueEs), argsOf(valueEn)];
    if (sorted(ae) !== sorted(an))
      argIssues.push(
        `${k}: es usa {${sorted(ae)}} y ${locale} usa {${sorted(an)}}`,
      );
    const [te, tn] = [tagsOf(valueEs), tagsOf(valueEn)];
    if (sorted(te) !== sorted(tn))
      tagIssues.push(
        `${k}: es usa <${sorted(te)}> y ${locale} usa <${sorted(tn)}>`,
      );
  }
  push("Argumentos distintos entre idiomas", argIssues);
  push("Etiquetas distintas entre idiomas", tagIssues);

  // ── 5. Etiquetas ↔ handlers de `t.rich` en el código ──────────────────────
  const code = sources.filter(({ path }) => !/\.test\./.test(path));
  const handlers = richHandlers(code);
  const raws = rawKeys(code);
  const handlerIssues = [];
  const warnings = [];
  for (const [key, value] of esLeaves) {
    if (typeof value !== "string") continue;
    const tags = tagsOf(value);
    if (tags.size === 0) continue;
    const plain = key.replace(/\[\d+\]/g, "");
    const last = plain.split(".").pop();
    const entry = handlers.get(plain) ?? handlers.get(last);
    if (!entry) {
      if (raws.has(plain) || raws.has(last)) continue; // se pinta como HTML saneado
      warnings.push(
        `${key}: lleva <${sorted(tags)}> y no se ve ninguna llamada rich()`,
      );
      continue;
    }
    const missing = [...tags].filter((t) => !entry.names.has(t));
    if (missing.length)
      handlerIssues.push(
        `${key}: <${missing.join(", ")}> sin handler en rich() — ${[...entry.files].join(", ")}`,
      );
  }
  push("Etiquetas sin handler en t.rich()", handlerIssues);

  return { errors, warnings, leafCount: esLeaves.size };
}

// ── CLI ─────────────────────────────────────────────────────────────────────

function sourceFiles(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) sourceFiles(full, out);
    else if (/\.(tsx?|jsx?)$/.test(name)) out.push(full);
  }
  return out;
}

/**
 * Idiomas declarados en `src/i18n/routing.ts` (y su idioma principal). El script es Node
 * puro y no puede importar TS, así que lee la configuración con una expresión regular
 * sobre el objeto `defineRouting({ … })`; si no la encuentra, falla en vez de comparar
 * idiomas inventados.
 *
 * @returns {{ locales: string[], defaultLocale: string }}
 */
export function readRoutingConfig(filePath) {
  const source = readFileSync(filePath, "utf8");
  const localesMatch = source.match(/locales\s*:\s*\[([^\]]*)\]/);
  if (!localesMatch) {
    throw new Error(
      `No se encontró "locales: [ … ]" en ${filePath}: revisa i18n/routing.ts.`,
    );
  }
  const locales = [...localesMatch[1].matchAll(/["']([^"']+)["']/g)].map((m) => m[1]);
  if (locales.length === 0) {
    throw new Error(`"locales" está vacío en ${filePath}.`);
  }
  const defaultMatch = source.match(/defaultLocale\s*:\s*["']([^"']+)["']/);
  const defaultLocale = defaultMatch?.[1] ?? locales[0];
  if (!locales.includes(defaultLocale)) {
    throw new Error(
      `defaultLocale "${defaultLocale}" no está en locales (${locales.join(", ")}).`,
    );
  }
  return { locales, defaultLocale };
}

function main() {
  const here = dirname(fileURLToPath(import.meta.url));
  const messagesDir = resolve(here, "../src/i18n/messages");
  const srcDir = resolve(here, "../src");
  const load = (name) => JSON.parse(readFileSync(resolve(messagesDir, name), "utf8"));

  const { locales, defaultLocale } = readRoutingConfig(
    resolve(here, "../src/i18n/routing.ts"),
  );
  const secondary = locales.filter((locale) => locale !== defaultLocale);

  const sources = sourceFiles(srcDir).map((file) => ({
    path: file.replace(`${srcDir}/`, "src/"),
    code: readFileSync(file, "utf8"),
  }));

  if (secondary.length === 0) {
    console.log(
      `i18n OK — un solo idioma (${defaultLocale}): no hay paridad entre idiomas que comprobar.`,
    );
    return;
  }

  const es = load(`${defaultLocale}.json`);
  const errors = [];
  const warnings = [];
  let leafCount = 0;

  for (const locale of secondary) {
    const { errors: localeErrors, warnings: localeWarnings, leafCount: leaves } =
      checkParity({
        es,
        en: load(`${locale}.json`),
        sources,
        locale,
      });
    errors.push(...localeErrors);
    warnings.push(...localeWarnings);
    leafCount = Math.max(leafCount, leaves);
  }

  if (warnings.length) {
    console.warn(
      `AVISO — mensajes con etiquetas cuya llamada rich() no se localizó (${warnings.length}):\n  ${warnings.join("\n  ")}`,
    );
  }
  if (errors.length) {
    console.error(errors.join("\n\n"));
    process.exitCode = 1;
    return;
  }
  console.log(
    `i18n OK — ${leafCount} hojas, misma forma, mismos argumentos y etiquetas, y toda etiqueta localizada tiene handler.`,
  );
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main();
}
