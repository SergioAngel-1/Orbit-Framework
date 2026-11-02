import { existsSync, readFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import ts from "typescript";

// ============================================================================
//  ¿Verifica la firma un route.ts antes de actuar? Análisis sobre el AST de
//  TypeScript (no sobre el texto), para `tests/unit/signed-routes.test.ts`.
//
//  Por qué no una expresión regular sobre el fuente: casaba llamadas citadas en
//  comentarios o cadenas (una ruta que NO verificaba pasaba si un comentario
//  decía «llamar a verifyHmacSignature(…)»), y solo reconocía el verificador por
//  su nombre: el envoltorio propio de una instancia (`verifyMetaSignature`, que
//  llama a `verifyHmacSignature`) no contaba.
//
//  Aquí cuenta una LLAMADA real a un verificador del núcleo o a una función que,
//  a su vez, llama a uno: local o importada (alias `@/` o ruta relativa), hasta
//  tres saltos. Las llamadas dentro del cuerpo de un envoltorio no se cuentan
//  como «la ruta verifica» ni como efecto: cuenta dónde lo INVOCA la ruta.
// ============================================================================

/** Verificadores de firma del núcleo. */
const CORE_VERIFIERS = new Set([
  "verifyWooWebhook",
  "verifyHweSignature",
  "verifyHmacSignature",
]);
/** Métodos verificadores (`provider.verifyWebhook(…)` de la capa de pagos). */
const VERIFIER_METHODS = new Set(["verifyWebhook"]);
/** Efectos que no pueden ocurrir antes de verificar. */
const SIDE_EFFECTS = new Set([
  "revalidateTag",
  "markEventOnce",
  "dispatchOrderEffects",
  "recordAndDiffStatus",
]);

/** Los manejadores del route.ts: son la ruta, no un envoltorio del verificador. */
const HTTP_METHODS = new Set([
  "GET",
  "HEAD",
  "POST",
  "PUT",
  "PATCH",
  "DELETE",
  "OPTIONS",
]);

const MAX_DEPTH = 3;
const SRC_DIR = resolve(dirname(new URL(import.meta.url).pathname), "../../src");

export interface SignatureAnalysis {
  /** Posición de la primera llamada de la ruta a un verificador; -1 si no hay. */
  verifierAt: number;
  /** Posición del primer efecto; -1 si no hay. */
  sideEffectAt: number;
}

type FunctionNode = ts.FunctionDeclaration | ts.ArrowFunction | ts.FunctionExpression;

function parse(file: string, source: string): ts.SourceFile {
  return ts.createSourceFile(
    file,
    source,
    ts.ScriptTarget.Latest,
    true,
    ts.ScriptKind.TS,
  );
}

/** Funciones de primer nivel del módulo: `function f` y `const f = () => …`. */
function topLevelFunctions(sf: ts.SourceFile): Map<string, FunctionNode> {
  const out = new Map<string, FunctionNode>();
  for (const stmt of sf.statements) {
    if (ts.isFunctionDeclaration(stmt) && stmt.name && stmt.body) {
      out.set(stmt.name.text, stmt);
    } else if (ts.isVariableStatement(stmt)) {
      for (const decl of stmt.declarationList.declarations) {
        const init = decl.initializer;
        if (
          ts.isIdentifier(decl.name) &&
          init &&
          (ts.isArrowFunction(init) || ts.isFunctionExpression(init))
        ) {
          out.set(decl.name.text, init);
        }
      }
    }
  }
  return out;
}

/** Nombre local → (módulo, nombre exportado) de los `import { a as b } from "…"`. */
function namedImports(
  sf: ts.SourceFile,
): Map<string, { module: string; imported: string }> {
  const out = new Map<string, { module: string; imported: string }>();
  for (const stmt of sf.statements) {
    if (!ts.isImportDeclaration(stmt) || !ts.isStringLiteral(stmt.moduleSpecifier))
      continue;
    const bindings = stmt.importClause?.namedBindings;
    if (!bindings || !ts.isNamedImports(bindings)) continue;
    for (const el of bindings.elements) {
      out.set(el.name.text, {
        module: stmt.moduleSpecifier.text,
        imported: (el.propertyName ?? el.name).text,
      });
    }
  }
  return out;
}

function resolveModule(fromFile: string, specifier: string): string | null {
  let base: string;
  if (specifier.startsWith("@/")) base = join(SRC_DIR, specifier.slice(2));
  else if (specifier.startsWith(".")) base = resolve(dirname(fromFile), specifier);
  else return null;
  for (const candidate of [`${base}.ts`, `${base}.tsx`, join(base, "index.ts")]) {
    if (existsSync(candidate)) return candidate;
  }
  return null;
}

function calleeOf(call: ts.CallExpression): { name: string; method: boolean } | null {
  const callee = call.expression;
  if (ts.isIdentifier(callee)) return { name: callee.text, method: false };
  if (ts.isPropertyAccessExpression(callee))
    return { name: callee.name.text, method: true };
  return null;
}

function forEachCall(
  node: ts.Node,
  visit: (call: ts.CallExpression) => void,
  skip?: Set<ts.Node>,
) {
  const walk = (n: ts.Node) => {
    if (skip?.has(n)) return;
    if (ts.isCallExpression(n)) visit(n);
    ts.forEachChild(n, walk);
  };
  walk(node);
}

interface ModuleInfo {
  sf: ts.SourceFile;
  /** Nombres (locales) que verifican: del núcleo, envoltorios locales e importados. */
  verifiers: Set<string>;
  /** Cuerpos de los envoltorios locales. */
  wrappers: Set<ts.Node>;
}

function isVerifierCall(call: ts.CallExpression, verifiers: Set<string>): boolean {
  const callee = calleeOf(call);
  if (!callee) return false;
  return callee.method ? VERIFIER_METHODS.has(callee.name) : verifiers.has(callee.name);
}

function analyzeModule(
  file: string,
  source: string,
  depth: number,
  seen: Set<string>,
): ModuleInfo {
  const sf = parse(file, source);
  const functions = topLevelFunctions(sf);
  // Una función local con el nombre de un verificador del núcleo solo cuenta si llama a uno.
  const verifiers = new Set([...CORE_VERIFIERS].filter((name) => !functions.has(name)));

  // Importados: cuentan si su definición en el otro módulo llama a un verificador.
  if (depth < MAX_DEPTH) {
    for (const [local, { module, imported }] of namedImports(sf)) {
      if (verifiers.has(local)) continue;
      const target = resolveModule(file, module);
      if (!target || seen.has(`${target}#${imported}`)) continue;
      seen.add(`${target}#${imported}`);
      const info = analyzeModule(target, readFileSync(target, "utf8"), depth + 1, seen);
      const fn = topLevelFunctions(info.sf).get(imported);
      if (!fn) continue;
      let calls = false;
      forEachCall(fn, (call) => {
        if (isVerifierCall(call, info.verifiers)) calls = true;
      });
      if (calls) verifiers.add(local);
    }
  }

  // Locales: punto fijo (un envoltorio puede llamar a otro).
  const wrappers = new Set<ts.Node>();
  let grew = true;
  while (grew) {
    grew = false;
    for (const [name, fn] of functions) {
      if (wrappers.has(fn) || HTTP_METHODS.has(name)) continue;
      let calls = false;
      forEachCall(fn, (call) => {
        if (isVerifierCall(call, verifiers)) calls = true;
      });
      if (calls) {
        wrappers.add(fn);
        if (!verifiers.has(name)) {
          verifiers.add(name);
          grew = true;
        }
      }
    }
  }

  return { sf, verifiers, wrappers };
}

/** Dónde verifica la firma la ruta y dónde actúa por primera vez. */
export function analyzeSignedRoute(file: string, source: string): SignatureAnalysis {
  const { sf, verifiers, wrappers } = analyzeModule(file, source, 0, new Set());
  let verifierAt = -1;
  let sideEffectAt = -1;
  forEachCall(
    sf,
    (call) => {
      const at = call.getStart(sf);
      if (isVerifierCall(call, verifiers)) {
        if (verifierAt < 0 || at < verifierAt) verifierAt = at;
        return;
      }
      const callee = calleeOf(call);
      if (
        callee &&
        SIDE_EFFECTS.has(callee.name) &&
        (sideEffectAt < 0 || at < sideEffectAt)
      ) {
        sideEffectAt = at;
      }
    },
    wrappers,
  );
  return { verifierAt, sideEffectAt };
}
