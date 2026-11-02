#!/usr/bin/env node
// ============================================================================
//  Comprueba, sin aplicarlo, cómo caería una release del framework sobre una
//  instancia (docs/UPGRADE.md §3).
//
//  Uso, en la raíz del clon y con el árbol sin cambios pendientes:
//    git fetch framework --tags && node tools/upgrade-check.mjs v2.5.0
//
//  Dos informes, con la frontera de `tools/boundaries.json` (+ el
//  `boundaries.local.json` de la instancia):
//    1. Deriva: archivos del núcleo que la instancia cambió desde la última
//       release fusionada. Es aviso: cada uno chocará tarde o temprano.
//    2. Conflictos: hace el merge en una rama temporal con --no-commit, lo
//       aborta y borra la rama; el árbol queda como estaba. Por conflicto:
//       instancia → gana lo local; zona gris → a mano; núcleo o sin listar →
//       error (la instancia pisó el núcleo, o falta la ruta en el manifiesto).
//
//  Sale con 0 si todo choca donde está previsto, 1 si hay conflictos fuera de
//  lista y 2 si no pudo comprobar (ref inexistente, árbol sucio, merge que no
//  empieza).
// ============================================================================

import { spawnSync } from "node:child_process";
import { existsSync } from "node:fs";
import { classify, loadBoundaries } from "./boundaries.mjs";

/** @param {string[]} args */
function git(args, { allowFail = false } = {}) {
  const result = spawnSync("git", args, { encoding: "utf8" });
  if (result.status !== 0 && !allowFail) {
    throw new Error(`git ${args.join(" ")}: ${result.stderr.trim()}`);
  }
  return result;
}

const lines = (text) =>
  text
    .split("\n")
    .map((line) => line.trim())
    .filter(Boolean);

function fail(message, code = 2) {
  console.error(`ERROR: ${message}`);
  process.exit(code);
}

const ref = process.argv[2];
if (!ref || ref.startsWith("-")) {
  console.error("Uso: node tools/upgrade-check.mjs <tag o ref del framework>");
  process.exit(2);
}

const root = git(["rev-parse", "--show-toplevel"]).stdout.trim();
process.chdir(root);

if (
  git(["rev-parse", "--verify", "--quiet", `${ref}^{commit}`], {
    allowFail: true,
  }).status !== 0
) {
  fail(`'${ref}' no existe (¿git fetch framework --tags?).`);
}
if (
  git(["status", "--porcelain", "--untracked-files=no"]).stdout.trim() !== ""
) {
  fail(
    "hay cambios sin commitear; commitéalos o apártalos antes de comprobar.",
  );
}

const rules = loadBoundaries(root);

// ── 1) Deriva respecto de la base común ──────────────────────────────────────
const base = git(["merge-base", "HEAD", ref], {
  allowFail: true,
}).stdout.trim();
if (base) {
  const changed = lines(git(["diff", "--name-only", base, "HEAD"]).stdout);
  const drift = changed.filter((path) => classify(path, rules) === "core");
  if (drift.length > 0) {
    console.log(
      `Aviso: ${drift.length} archivo(s) del núcleo cambiados en la instancia desde ${base.slice(0, 7)}:`,
    );
    for (const path of drift)
      console.log(`  núcleo editado:            ${path}`);
    console.log(
      "  (muévelos a una ruta de instancia o propón el cambio al framework)",
    );
  }
}

// ── 2) Merge de prueba ───────────────────────────────────────────────────────
const original =
  git(["symbolic-ref", "--short", "-q", "HEAD"], {
    allowFail: true,
  }).stdout.trim() || git(["rev-parse", "HEAD"]).stdout.trim();
const tempBranch = `upgrade-check-${process.pid}`;

function cleanup() {
  git(["merge", "--abort"], { allowFail: true });
  if (git(["switch", "-q", original], { allowFail: true }).status !== 0) {
    git(["checkout", "-q", original], { allowFail: true });
  }
  git(["branch", "-q", "-D", tempBranch], { allowFail: true });
}

let outside = 0;
let conflicts = [];
try {
  git(["switch", "-q", "-c", tempBranch]);
  const merge = git(["merge", "--no-commit", "--no-ff", ref], {
    allowFail: true,
  });
  // Un merge con conflictos deja MERGE_HEAD; si falla sin dejarlo, ni siquiera
  // empezó (p. ej. un archivo sin versionar que la release pisaría). Eso no es
  // «sin conflictos»: se falla cerrado.
  const mergeHead = git([
    "rev-parse",
    "--git-path",
    "MERGE_HEAD",
  ]).stdout.trim();
  if (merge.status !== 0 && !existsSync(mergeHead)) {
    cleanup();
    fail(`el merge de ${ref} no pudo empezar:\n${merge.stdout}${merge.stderr}`);
  }
  conflicts = lines(git(["diff", "--name-only", "--diff-filter=U"]).stdout);
} finally {
  cleanup();
}

const LABELS = {
  instance: "instancia (gana lo local): ",
  gray: "zona gris (a mano):        ",
  core: "NÚCLEO EDITADO:            ",
  unlisted: "FUERA DE LISTA:            ",
};
for (const path of conflicts) {
  const owner = classify(path, rules);
  console.log(`${LABELS[owner]}${path}`);
  if (owner === "core" || owner === "unlisted") outside++;
}

if (conflicts.length === 0) console.log(`Sin conflictos al traer ${ref}.`);
if (outside > 0) {
  fail(
    `${outside} conflicto(s) fuera de la zona gris y de las rutas de instancia. ` +
      "Si es una ruta propia de tu instancia, decláralo en tools/boundaries.local.json.",
    1,
  );
}
console.log(`OK: traer ${ref} solo choca donde está previsto.`);
