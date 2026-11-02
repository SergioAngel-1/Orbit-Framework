// ============================================================================
//  Lector de la frontera núcleo/instancia (`tools/boundaries.json`).
//
//  Una sola fuente para tres consumidores que antes llevaban cada uno su lista:
//  la regla de ESLint del frontend, la comprobación de actualización
//  (`tools/upgrade-check.mjs`) y los tests que comparan la doc con el manifiesto.
//  Sin dependencias: lo importan tanto Node a pelo como ESLint y Vitest.
// ============================================================================

import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

export const OWNERS = /** @type {const} */ (["core", "instance", "gray"]);

const TOOLS_DIR = dirname(fileURLToPath(import.meta.url));

/**
 * Glob → RegExp. `**` cruza carpetas, `*` no; el resto es literal (los corchetes
 * de `[locale]` también). Un patrón casa la ruta entera, relativa a la raíz.
 * @param {string} glob
 */
export function globToRegExp(glob) {
  let source = "";
  for (let i = 0; i < glob.length; i++) {
    const char = glob[i];
    if (char === "*" && glob[i + 1] === "*") {
      source += ".*";
      i++;
    } else if (char === "*") {
      source += "[^/]*";
    } else {
      source += char.replace(/[.+?^${}()|[\]\\]/g, "\\$&");
    }
  }
  return new RegExp(`^${source}$`);
}

/**
 * Valida y compila una lista de reglas. Falla en voz alta: un manifiesto roto
 * no puede dejar pasar en silencio un conflicto en el núcleo.
 * @param {unknown} rules
 * @param {string} origin nombre del fichero, para el mensaje de error
 */
export function compileRules(rules, origin) {
  if (!Array.isArray(rules)) {
    throw new Error(`${origin}: "rules" debe ser una lista.`);
  }
  return rules.map((rule, index) => {
    if (
      !rule ||
      typeof rule.path !== "string" ||
      rule.path === "" ||
      !OWNERS.includes(rule.owner)
    ) {
      throw new Error(
        `${origin}: la regla ${index} necesita "path" y "owner" (${OWNERS.join(" | ")}).`,
      );
    }
    return {
      path: rule.path,
      owner: rule.owner,
      lint: rule.lint === true,
      regex: globToRegExp(rule.path),
    };
  });
}

/**
 * Carga el manifiesto del framework y, delante, el local de la instancia si existe.
 * @param {string} [root] raíz del repositorio (por defecto, la de este fichero)
 */
export function loadBoundaries(root = join(TOOLS_DIR, "..")) {
  const read = (file) => JSON.parse(readFileSync(join(root, file), "utf8"));
  const core = compileRules(
    read("tools/boundaries.json").rules,
    "tools/boundaries.json",
  );
  const localFile = "tools/boundaries.local.json";
  const local = existsSync(join(root, localFile))
    ? compileRules(read(localFile).rules, localFile)
    : [];
  return [...local, ...core];
}

/**
 * Dueño de una ruta: el de la primera regla que casa, o `unlisted`.
 * @param {string} path ruta relativa a la raíz, con `/`
 * @param {ReturnType<typeof compileRules>} rules
 * @returns {"core" | "instance" | "gray" | "unlisted"}
 */
export function classify(path, rules) {
  const rule = rules.find((candidate) => candidate.regex.test(path));
  return rule ? rule.owner : "unlisted";
}

const FRONTEND_PREFIX = "frontend/";

/** Glob del manifiesto → glob de ESLint (relativo a `frontend/`, solo TS). */
function toEslintGlob(path) {
  const relative = path.slice(FRONTEND_PREFIX.length);
  return relative.endsWith("/**") ? `${relative}/*.{ts,tsx}` : relative;
}

/**
 * Ficheros a los que se aplica la frontera de imports en ESLint: las reglas de
 * núcleo con `lint: true`; e ignorados, las rutas de instancia que viven dentro
 * de ellas (p. ej. `app/api/instance/**`, que sí puede importar `@/instance/**`).
 * @param {ReturnType<typeof compileRules>} rules
 */
export function eslintBoundary(rules) {
  const files = rules
    .filter((rule) => rule.owner === "core" && rule.lint)
    .map((rule) => rule.path);
  const prefixes = files
    .filter((path) => path.endsWith("/**"))
    .map((path) => path.slice(0, -2));
  const ignores = rules
    .filter(
      (rule) =>
        rule.owner === "instance" &&
        prefixes.some((prefix) => rule.path.startsWith(prefix)),
    )
    .map((rule) => rule.path);
  return { files: files.map(toEslintGlob), ignores: ignores.map(toEslintGlob) };
}
