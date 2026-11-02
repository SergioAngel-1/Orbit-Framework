// ============================================================================
//  tools/doctor/run.mjs — Orquestación del doctor: hace las peticiones HTTP y
//  aplica los checks de tools/doctor/checks.mjs (la lógica pura está allí).
//
//  Lo separa de tools/doctor.mjs (la CLI) para que el test de integración pueda
//  arrancar el doctor contra servidores falsos sin ejecutar el proceso.
//
//  El secreto interno (HWE_REVALIDATION_SECRET) se lee del entorno o de .env /
//  frontend/.env.local, y NUNCA se imprime ni aparece en un mensaje.
// ============================================================================

import { existsSync, readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import {
  STATUS,
  analyzeJsonLd,
  checkSitemapLocOrigin,
  compareBrandOrigin,
  configVerdict,
  csrfVerdict,
  findCsrfCookie,
  findProductUrl,
  forgotPasswordVerdict,
  healthVerdict,
  isSuccess,
  originOf,
  parseEnvFile,
  parseSitemap,
  productFindings,
  respondsText,
  robotsVerdict,
  sitemapUrlVerdict,
  softNotFoundVerdict,
  verdict,
  withCheck,
} from "./checks.mjs";

const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..", "..");
const SECRET_HEADER = "X-HWE-Internal-Secret";
const DEFAULT_TIMEOUT_MS = 10_000;
const MAX_SITEMAP_CHILDREN = 20;

/**
 * Lee HWE_REVALIDATION_SECRET: primero del entorno, luego de .env y de
 * frontend/.env.local. Devuelve { value, source }; `source` es solo un nombre de fichero.
 *
 * @param {{ env?: Record<string, string | undefined>, rootDir?: string }} [options]
 * @returns {{ value: string, source: string | null }}
 */
export function readSecret({ env = process.env, rootDir = REPO_ROOT } = {}) {
  const fromEnv = (env.HWE_REVALIDATION_SECRET ?? "").trim();
  if (fromEnv) return { value: fromEnv, source: "entorno" };
  for (const rel of [".env", path.join("frontend", ".env.local")]) {
    const file = path.join(rootDir, rel);
    if (!existsSync(file)) continue;
    const value = (parseEnvFile(readFileSync(file, "utf8")).HWE_REVALIDATION_SECRET ?? "").trim();
    if (value) return { value, source: rel };
  }
  return { value: "", source: null };
}

function normalizeBase(url) {
  const origin = originOf(url);
  if (!origin) throw new Error(`URL no válida: «${url}»`);
  return String(url).trim().replace(/\/+$/, "");
}

function describeError(err) {
  const cause = err?.cause;
  return cause?.code ?? cause?.message ?? err?.message ?? "error de red";
}

/**
 * Una petición sin seguir redirecciones (redirect: "manual"). Nunca lanza: una
 * conexión rechazada o un timeout vuelven como status 0 con `error`.
 */
async function probe(url, { headers = {}, method = "GET", body, readBody = false, timeoutMs }) {
  try {
    const res = await fetch(url, {
      method,
      headers,
      body,
      redirect: "manual",
      signal: AbortSignal.timeout(timeoutMs),
    });
    let text = "";
    if (readBody) text = await res.text();
    else await res.body?.cancel().catch(() => {});
    return { status: res.status, headers: res.headers, text, error: "" };
  } catch (err) {
    return { status: 0, headers: new Headers(), text: "", error: describeError(err) };
  }
}

function secretHeaders(secret) {
  return secret ? { [SECRET_HEADER]: secret } : {};
}

/**
 * Ejecuta los siete checks contra una pila levantada y devuelve la lista de resultados
 * { check, status, message, hint? }. No lanza por un fallo del sistema probado: lo
 * convierte en un fail.
 */
export async function runDoctor({
  frontend,
  wordpress,
  secret = "",
  maxUrls = 50,
  timeoutMs = DEFAULT_TIMEOUT_MS,
}) {
  const front = normalizeBase(frontend);
  const wp = normalizeBase(wordpress);
  const frontOrigin = originOf(front);
  const results = [];
  const push = (check, v) => results.push(withCheck(check, v));
  const get = (url, readBody = false) => probe(url, { readBody, timeoutMs });

  // 1. Config pública de WordPress y origen de la marca --------------------
  if (!secret) {
    push(1, verdict(STATUS.WARN, "no hay HWE_REVALIDATION_SECRET en el entorno ni en .env: la petición sale sin la cabecera interna", "Genera el secreto con backend/scripts/generate-secrets.sh."));
  } else if (secret.startsWith("changeme-")) {
    push(1, verdict(STATUS.WARN, "HWE_REVALIDATION_SECRET tiene el valor de ejemplo de .env.example", "Genera uno propio con backend/scripts/generate-secrets.sh."));
  }
  const cfg = await probe(`${wp}/wp-json/hwe/v1/config`, {
    headers: secretHeaders(secret),
    readBody: true,
    timeoutMs,
  });
  let brandUrl = null;
  const cfgVerdict = configVerdict(cfg.status, cfg.text, cfg.error);
  if (cfgVerdict.verdict) {
    push(1, cfgVerdict.verdict);
  } else {
    brandUrl = cfgVerdict.brandUrl;
    push(1, compareBrandOrigin(brandUrl, front));
  }

  // 2. Salud del BFF --------------------------------------------------------
  const health = await probe(`${front}/api/health`, { readBody: true, timeoutMs });
  let healthBody = null;
  try {
    healthBody = JSON.parse(health.text);
  } catch {
    healthBody = null;
  }
  push(2, healthVerdict(health.status, healthBody, health.error));

  // 3. Token CSRF (cookie + cuerpo) ----------------------------------------
  const csrf = await probe(`${front}/api/csrf`, { readBody: true, timeoutMs });
  let csrfBody = null;
  try {
    csrfBody = JSON.parse(csrf.text);
  } catch {
    csrfBody = null;
  }
  const csrfCookie = findCsrfCookie(csrf.headers.getSetCookie?.() ?? []);
  const csrfResult = csrfVerdict(csrf.status, csrfBody, csrfCookie, csrf.error);
  push(3, csrfResult);

  // 4. Recuperación de contraseña con un correo inexistente ----------------
  if (csrfResult.status !== STATUS.OK) {
    push(4, verdict(STATUS.SKIP, "sin token CSRF válido (check 3): no se intenta la petición"));
  } else {
    const email = `doctor+${Date.now()}@example.invalid`;
    const forgot = await probe(`${front}/api/auth/forgot-password`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Origin: frontOrigin,
        "X-CSRF-Token": csrfBody.csrfToken,
        Cookie: `${csrfCookie.name}=${csrfCookie.value}`,
      },
      body: JSON.stringify({ email }),
      readBody: true,
      timeoutMs,
    });
    push(4, forgotPasswordVerdict(forgot.status, forgot.error));
  }

  // 5. robots.txt y sitemap (con sus URL comprobadas) ----------------------
  const robots = await get(`${front}/robots.txt`);
  push(5, robotsVerdict(robots.status, robots.error));

  const sitemapRoot = await probe(`${front}/sitemap.xml`, { readBody: true, timeoutMs });
  let urls = [];
  if (!isSuccess(sitemapRoot.status)) {
    push(5, verdict(STATUS.FAIL, `sitemap.xml ${respondsText(sitemapRoot.status, sitemapRoot.error)}`, "Sin sitemap no hay URL que comprobar: revisa /sitemap.xml en el frontend."));
  } else {
    const root = parseSitemap(sitemapRoot.text);
    if (root.kind === "index") {
      for (const child of root.locs.slice(0, MAX_SITEMAP_CHILDREN)) {
        const originWarn = checkSitemapLocOrigin(child, front);
        if (originWarn) {
          push(5, originWarn);
          continue;
        }
        const c = await probe(child, { readBody: true, timeoutMs });
        if (!isSuccess(c.status)) {
          push(5, verdict(STATUS.FAIL, `sitemap hijo ${child} responde ${c.status || c.error}`, "Un sitemap que no carga hace perder todas sus URL."));
        } else {
          urls.push(...parseSitemap(c.text).locs);
        }
      }
    } else {
      urls = root.locs;
    }
  }

  // Solo las URL del mismo origen se piden; el resto avisa (incluidas las relativas).
  const sameOrigin = [];
  for (const loc of urls) {
    const originWarn = checkSitemapLocOrigin(loc, front);
    if (originWarn) {
      push(5, originWarn);
      continue;
    }
    sameOrigin.push(new URL(loc).href);
  }
  const unique = [...new Set(sameOrigin)];
  if (isSuccess(sitemapRoot.status) && unique.length === 0) {
    push(5, verdict(STATUS.WARN, "el sitemap no lista ninguna URL del frontend", "Revisa los segmentos del sitemap y su contenido en WordPress."));
  }

  const toCheck = unique.slice(0, maxUrls);
  if (unique.length > toCheck.length) {
    push(5, verdict(STATUS.WARN, `solo se comprueban ${toCheck.length} de ${unique.length} URL (--max-urls)`));
  }
  let problems = 0;
  for (const url of toCheck) {
    const r = await probe(url, { timeoutMs });
    const v = sitemapUrlVerdict(url, r.status, r.headers.get("location") ?? "", r.error);
    if (v.status !== STATUS.OK) {
      problems += 1;
      push(5, v);
    }
  }
  if (isSuccess(sitemapRoot.status) && toCheck.length > 0 && problems === 0) {
    push(5, verdict(STATUS.OK, `${toCheck.length} URL del sitemap responden 2xx sin redirigir`));
  }

  // 6. JSON-LD de la primera ficha de producto -----------------------------
  const productUrl = findProductUrl(unique, front);
  if (!productUrl) {
    push(6, verdict(STATUS.SKIP, "ninguna URL de producto en el sitemap (se busca una ruta con /product o /producto)"));
  } else {
    const page = await probe(productUrl, { readBody: true, timeoutMs });
    if (!isSuccess(page.status)) {
      push(6, verdict(STATUS.WARN, `la ficha ${productUrl} responde ${page.status || page.error}; ver el check 5`));
    } else {
      const analysis = analyzeJsonLd(page.text);
      for (const err of analysis.parseErrors) {
        push(6, verdict(STATUS.FAIL, `JSON-LD inválido en ${productUrl}: ${err}`, "Un bloque JSON-LD que no parsea no lo lee ningún buscador."));
      }
      if (analysis.products.length === 0) {
        push(6, verdict(STATUS.WARN, `${productUrl} no trae ningún Product en JSON-LD`, "Las fichas de producto deben emitir su Product."));
      }
      let clean = true;
      for (const product of analysis.products) {
        for (const f of productFindings(product)) {
          clean = false;
          push(6, f);
        }
      }
      if (clean && analysis.products.length > 0) {
        push(6, verdict(STATUS.OK, `Product JSON-LD correcto en ${productUrl}`));
      }
    }
  }

  // 7. Una ruta inexistente debe responder 404 (no «soft 404») -------------
  const missing = await get(`${front}/doctor-404-${Date.now()}`);
  push(7, softNotFoundVerdict(missing.status, missing.headers.get("location") ?? ""));

  return results;
}
