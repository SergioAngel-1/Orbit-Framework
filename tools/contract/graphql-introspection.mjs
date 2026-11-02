#!/usr/bin/env node
// ============================================================================
//  Vuelca el esquema GraphQL de una pila levantada (introspección).
//
//  Uso:
//    node tools/contract/graphql-introspection.mjs [--wordpress http://localhost:8080] > schema.json
//
//  El esquema depende de las versiones de WPGraphQL/WooGraphQL instaladas y de
//  los plugins activos (también los de la instancia), así que el framework no
//  versiona una copia: cada pila genera la suya. Útil para dárselo a un agente
//  o a un generador de tipos. Lee `HWE_REVALIDATION_SECRET` del entorno o de
//  `.env` (WordPress solo atiende GraphQL con `X-HWE-Internal-Secret`); el
//  secreto nunca se imprime.
// ============================================================================

import { existsSync, readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "../..");

const QUERY = `query IntrospectionQuery {
  __schema {
    queryType { name } mutationType { name } subscriptionType { name }
    types { ...FullType }
    directives { name description locations args { ...InputValue } }
  }
}
fragment FullType on __Type {
  kind name description
  fields(includeDeprecated: true) { name description args { ...InputValue } type { ...TypeRef } isDeprecated deprecationReason }
  inputFields { ...InputValue }
  interfaces { ...TypeRef }
  enumValues(includeDeprecated: true) { name description isDeprecated deprecationReason }
  possibleTypes { ...TypeRef }
}
fragment InputValue on __InputValue { name description type { ...TypeRef } defaultValue }
fragment TypeRef on __Type { kind name ofType { kind name ofType { kind name ofType { kind name ofType { kind name ofType { kind name ofType { kind name } } } } } } }`;

function arg(name, fallback) {
  const i = process.argv.indexOf(name);
  return i > 0 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

function secret() {
  if (process.env.HWE_REVALIDATION_SECRET)
    return process.env.HWE_REVALIDATION_SECRET;
  for (const file of [".env", "frontend/.env.local"]) {
    const full = join(ROOT, file);
    if (!existsSync(full)) continue;
    const line = readFileSync(full, "utf8")
      .split("\n")
      .find((l) => l.startsWith("HWE_REVALIDATION_SECRET="));
    if (line)
      return line
        .slice(line.indexOf("=") + 1)
        .trim()
        .replace(/^["']|["']$/g, "");
  }
  return "";
}

const wordpress = arg(
  "--wordpress",
  process.env.WORDPRESS_URL ?? "http://localhost:8080",
);
let res;
try {
  res = await fetch(`${wordpress.replace(/\/$/, "")}/graphql`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-HWE-Internal-Secret": secret(),
    },
    body: JSON.stringify({ query: QUERY }),
  });
} catch (error) {
  console.error(
    `ERROR: no se pudo conectar con ${wordpress}: ${error.cause?.code ?? error.message}`,
  );
  process.exit(1);
}
const json = await res.json().catch(() => null);
if (!res.ok || !json?.data?.__schema) {
  console.error(
    `ERROR: la introspección falló (HTTP ${res.status}). ¿Pila levantada y secreto correcto?`,
  );
  if (json?.errors) console.error(JSON.stringify(json.errors).slice(0, 500));
  process.exit(1);
}
process.stdout.write(JSON.stringify(json.data, null, 2) + "\n");
