import { instanceRegistry } from "@/instance/registry";
import type { InstanceRedirect } from "./types";

// ============================================================================
//  Redirecciones 301 de la instancia (migración desde una web anterior),
//  declaradas SOLO con datos en `InstanceRegistry.redirects`.
//
//  Una tienda que sustituye a otra hereda sus URLs indexadas y enlazadas: sin
//  un 301 a la nueva, cada una se convierte en un 404 y pierde lo que había
//  ganado. Las aplica el proxy ANTES de next-intl, así que `from` es la ruta
//  PÚBLICA tal como llega (con su prefijo de idioma si lo tenía) y `to` la URL
//  final, relativa al sitio o absoluta.
//
//  - Exacta: `{ from: "/tienda/vela-roja", to: "/products/vela-roja" }`.
//  - Prefijo: `{ from: "/producto/*", to: "/products/*" }` — el resto de la ruta
//    se copia en el `*` del destino (o se descarta si el destino no lo lleva).
//  - La consulta (`?…`) de la petición se conserva si el destino no trae la suya.
//
//  Módulo PURO y apto para edge (lo importa `proxy.ts`): ni `server-only` ni
//  logger. Una entrada inválida se descarta con un aviso en consola y el resto
//  sigue funcionando; `compileRedirects(map, { strict: true })` lanza en su
//  lugar, y es lo que usa el test de contrato para que CI falle con un dato malo.
// ============================================================================

/** Rutas que nunca se redirigen: internas de Next y el BFF. */
const RESERVED_PREFIXES = ["/api", "/_next"];

/** Tope de reglas: el proxy las recorre en cada petición de página. */
export const MAX_INSTANCE_REDIRECTS = 2000;

export interface CompiledRedirects {
  exact: Map<string, CompiledRule>;
  /** Prefijos, del más largo al más corto (el más específico manda). */
  prefixes: CompiledRule[];
}

interface CompiledRule {
  from: string;
  to: string;
  status: 301 | 302;
}

/** `/a/b/` → `/a/b`; la raíz se queda en `/`. */
function trimSlash(path: string): string {
  return path.length > 1 ? path.replace(/\/+$/, "") : path;
}

function isAbsoluteHttp(url: string): boolean {
  return /^https?:\/\/[^\s/]+/i.test(url);
}

function invalid(strict: boolean, reason: string, entry: unknown): void {
  if (strict) throw new Error(`redirects: ${reason} (${JSON.stringify(entry)})`);
  console.warn(JSON.stringify({ event: "instance.redirect_invalid", reason, entry }));
}

/** Valida y prepara las reglas. Sin mapa, ninguna. */
export function compileRedirects(
  map: readonly InstanceRedirect[] | undefined = instanceRegistry.redirects,
  options: { strict?: boolean } = {},
): CompiledRedirects {
  const strict = options.strict ?? false;
  const compiled: CompiledRedirects = { exact: new Map(), prefixes: [] };
  if (map === undefined || map === null) return compiled;
  if (!Array.isArray(map)) {
    invalid(strict, "el campo debe ser una lista", map);
    return compiled;
  }
  if (map.length > MAX_INSTANCE_REDIRECTS) {
    invalid(strict, `más de ${MAX_INSTANCE_REDIRECTS} reglas`, map.length);
  }

  for (const entry of map.slice(0, MAX_INSTANCE_REDIRECTS)) {
    const rawFrom = typeof entry?.from === "string" ? entry.from.trim() : "";
    const to = typeof entry?.to === "string" ? entry.to.trim() : "";
    if (!rawFrom.startsWith("/") || rawFrom.startsWith("//")) {
      invalid(strict, "`from` debe ser una ruta que empiece por /", entry);
      continue;
    }
    if (/[?#]/.test(rawFrom)) {
      invalid(strict, "`from` es solo la ruta, sin consulta ni ancla", entry);
      continue;
    }
    const isPrefix = rawFrom.endsWith("/*");
    const from = trimSlash(isPrefix ? rawFrom.slice(0, -2) : rawFrom) || "/";
    if (isPrefix && from === "/") {
      invalid(strict, "un prefijo no puede ser la raíz", entry);
      continue;
    }
    if (RESERVED_PREFIXES.some((p) => from === p || from.startsWith(`${p}/`))) {
      invalid(strict, "`from` no puede ser una ruta interna (/api, /_next)", entry);
      continue;
    }
    if (!(to.startsWith("/") && !to.startsWith("//")) && !isAbsoluteHttp(to)) {
      invalid(strict, "`to` debe ser una ruta del sitio o una URL http(s)", entry);
      continue;
    }
    // Bucle: el destino vuelve a caer en la propia regla.
    const toPath = trimSlash(to.split(/[?#]/)[0].replace(/\/\*$/, "")) || "/";
    const loops =
      !isAbsoluteHttp(to) &&
      (toPath === from || (isPrefix && toPath.startsWith(`${from}/`)));
    if (loops) {
      invalid(strict, "el destino vuelve a caer en `from` (bucle)", entry);
      continue;
    }
    const rule: CompiledRule = {
      from,
      to,
      status: entry.permanent === false ? 302 : 301,
    };
    if (isPrefix) {
      compiled.prefixes.push(rule);
    } else if (compiled.exact.has(from)) {
      invalid(strict, "`from` repetido (manda la primera)", entry);
    } else {
      compiled.exact.set(from, rule);
    }
  }
  compiled.prefixes.sort((a, b) => b.from.length - a.from.length);
  return compiled;
}

export interface RedirectMatch {
  /** Destino relativo al sitio o absoluto, con la consulta ya resuelta. */
  location: string;
  status: 301 | 302;
}

/**
 * Destino de una petición, o `null` si ninguna regla la cubre. `pathname` es la ruta
 * PÚBLICA tal como llega; `search` la consulta (con su `?`, o vacía).
 */
export function matchRedirect(
  pathname: string,
  search: string,
  rules: CompiledRedirects,
): RedirectMatch | null {
  if (rules.exact.size === 0 && rules.prefixes.length === 0) return null;
  let path = pathname;
  try {
    path = decodeURI(pathname);
  } catch {
    // Una ruta mal codificada se compara tal cual.
  }
  path = trimSlash(path);

  let target: string | null = null;
  let status: 301 | 302 = 301;
  const exact = rules.exact.get(path);
  if (exact) {
    target = exact.to;
    status = exact.status;
  } else {
    const rule = rules.prefixes.find(
      (r) => path === r.from || path.startsWith(`${r.from}/`),
    );
    if (!rule) return null;
    const rest = path.slice(rule.from.length).replace(/^\//, "");
    target = rule.to.includes("*")
      ? rule.to.replace("*", rest).replace(/\/+$/, "") || "/"
      : rule.to;
    status = rule.status;
  }

  const hasOwnQuery = target.includes("?");
  return {
    location: hasOwnQuery || !search ? target : `${target}${search}`,
    status,
  };
}
