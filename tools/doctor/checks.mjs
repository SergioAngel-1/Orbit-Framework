// ============================================================================
//  tools/doctor/checks.mjs — Lógica PURA del doctor (sin red ni disco).
//
//  Parsea sitemaps y JSON-LD, compara orígenes, clasifica códigos HTTP, lee
//  ficheros .env ya cargados como texto y formatea los resultados. Todo se prueba
//  con entradas literales (frontend/tests/unit/doctor.test.ts). Quien hace las
//  peticiones es tools/doctor/run.mjs, que usa estas funciones.
//
//  Veredictos: ok | warn | fail | skip. Un check puede dar varios resultados (una
//  línea por URL del sitemap que responde 404, por ejemplo). El CLI sale con 1 si
//  hay algún fail. Ningún mensaje incluye el valor de un secreto.
// ============================================================================

export const STATUS = Object.freeze({ OK: "ok", WARN: "warn", FAIL: "fail", SKIP: "skip" });

export const CHECK_NAMES = Object.freeze({
  1: "config de marca",
  2: "salud del BFF",
  3: "token CSRF",
  4: "recuperación de contraseña",
  5: "robots y sitemap",
  6: "JSON-LD de producto",
  7: "404 real",
});

export const FORGOT_5XX_HINT =
  "NEXT_PUBLIC_SITE_URL / HEADLESS_ALLOWED_ORIGINS no coinciden con el origen del frontend";

export function verdict(status, message, hint) {
  return hint ? { status, message, hint } : { status, message };
}

export function withCheck(check, v) {
  return { check, ...v };
}

export function isSuccess(status) {
  return Number.isInteger(status) && status >= 200 && status < 300;
}

/** Origen («esquema://host[:puerto]») de una URL, o null si no es una URL absoluta válida. */
export function originOf(url) {
  try {
    const origin = new URL(String(url ?? "").trim()).origin;
    return origin === "null" ? null : origin;
  } catch {
    return null;
  }
}

/** 2xx → ok; 3xx → warn (el sitemap debe listar URL finales); resto (incluido 0 = sin respuesta) → fail. */
export function classifyHttpStatus(status) {
  if (!Number.isInteger(status) || status <= 0) return STATUS.FAIL;
  if (status >= 200 && status < 300) return STATUS.OK;
  if (status >= 300 && status < 400) return STATUS.WARN;
  return STATUS.FAIL;
}

// ---------------------------------------------------------------------------
//  Sitemap
// ---------------------------------------------------------------------------

const XML_ENTITIES = { amp: "&", lt: "<", gt: ">", quot: '"', apos: "'" };

function decodeXml(text) {
  return text.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (match, entity) => {
    if (entity[0] === "#") {
      const hex = entity[1] === "x" || entity[1] === "X";
      const code = parseInt(entity.slice(hex ? 2 : 1), hex ? 16 : 10);
      return Number.isInteger(code) && code >= 0 && code <= 0x10ffff
        ? String.fromCodePoint(code)
        : match;
    }
    return XML_ENTITIES[entity.toLowerCase()] ?? match;
  });
}

/**
 * Lee un sitemap. `kind` es "index" (sitemapindex: sus <loc> son otros sitemaps),
 * "urlset" (sus <loc> son páginas) o "unknown".
 */
export function parseSitemap(xml) {
  const text = String(xml ?? "");
  let kind = "unknown";
  if (/<sitemapindex[\s>]/i.test(text)) kind = "index";
  else if (/<urlset[\s>]/i.test(text)) kind = "urlset";
  const locs = [];
  for (const match of text.matchAll(/<loc\b[^>]*>([\s\S]*?)<\/loc\s*>/gi)) {
    locs.push(decodeXml(match[1].trim()));
  }
  return { kind, locs };
}

/** Un <loc> relativo o de otro origen se avisa y no se pide (no sabemos ni debemos tocar ese host). */
export function checkSitemapLocOrigin(loc, frontendUrl) {
  const absolute = originOf(loc);
  if (absolute === null) {
    return verdict(
      STATUS.WARN,
      `<loc> relativa o no válida: «${loc}»`,
      "El sitemap debe dar URL absolutas (con el origen público del frontend).",
    );
  }
  const front = originOf(frontendUrl);
  if (absolute !== front) {
    return verdict(
      STATUS.WARN,
      `<loc> de otro origen (${absolute}); el frontend es ${front}. No se pide.`,
      "Revisa brand.url: el sitemap se genera con su origen.",
    );
  }
  return null;
}

/** Veredicto de una URL del sitemap según su respuesta sin seguir redirecciones. */
export function sitemapUrlVerdict(url, status, location = "", error = "") {
  const state = classifyHttpStatus(status);
  if (state === STATUS.OK) return verdict(STATUS.OK, `${url} → ${status}`);
  if (state === STATUS.WARN) {
    const target = location ? ` → ${location}` : "";
    return verdict(
      STATUS.WARN,
      `${url} redirige (${status}${target}); el sitemap debe listar la URL final`,
      "Sustituye esa entrada del sitemap por su destino.",
    );
  }
  if (status === 0) {
    return verdict(STATUS.FAIL, `${url} no responde (${error || "sin respuesta"})`, "Comprueba que el frontend está levantado.");
  }
  return verdict(
    STATUS.FAIL,
    `${url} responde ${status} y el sitemap la anuncia`,
    "Quita la URL del sitemap o arregla la ruta; un 404 o 5xx en el sitemap penaliza el rastreo.",
  );
}

/** Primera URL de ficha de producto (ruta con un segmento que empiece por «product») del mismo origen. */
export function findProductUrl(urls, frontendUrl) {
  const front = originOf(frontendUrl);
  for (const loc of urls) {
    const abs = originOf(loc);
    if (abs !== front) continue;
    const { pathname } = new URL(loc);
    if (isProductPath(pathname)) return new URL(loc).href;
  }
  return null;
}

/** «/products/vela», «/es/productos/vela»… sí; «/products/archive» (índice) y «/about» no. */
export function isProductPath(pathname) {
  const segments = String(pathname ?? "").split("/").filter(Boolean);
  const i = segments.findIndex((s) => /^product/i.test(s));
  return i >= 0 && i + 1 < segments.length && segments[i + 1] !== "archive";
}

// ---------------------------------------------------------------------------
//  JSON-LD de producto
// ---------------------------------------------------------------------------

/** Extrae y parsea los <script type="application/ld+json"> de un HTML. */
export function extractJsonLd(html) {
  const blocks = [];
  const re = /<script\b[^>]*type\s*=\s*["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script\s*>/gi;
  for (const match of String(html ?? "").matchAll(re)) {
    try {
      blocks.push({ data: JSON.parse(match[1]) });
    } catch (err) {
      blocks.push({ error: err instanceof Error ? err.message : String(err) });
    }
  }
  return blocks;
}

function typesOf(node) {
  const t = node["@type"];
  return Array.isArray(t) ? t : [t];
}

/** Nodos de nivel superior de un JSON-LD, aplanando arrays y @graph. */
function topLevelNodes(data) {
  const out = [];
  const visit = (node) => {
    if (Array.isArray(node)) {
      node.forEach(visit);
      return;
    }
    if (node && typeof node === "object") {
      out.push(node);
      if (Array.isArray(node["@graph"])) node["@graph"].forEach(visit);
    }
  };
  visit(data);
  return out;
}

/** Analiza el HTML de una ficha: bloques JSON-LD inválidos y nodos Product. */
export function analyzeJsonLd(html) {
  const blocks = extractJsonLd(html);
  const parseErrors = blocks.filter((b) => "error" in b).map((b) => b.error);
  const products = [];
  for (const block of blocks) {
    if (!("data" in block)) continue;
    for (const node of topLevelNodes(block.data)) {
      if (typesOf(node).includes("Product")) products.push(node);
    }
  }
  return { blocks: blocks.length, parseErrors, products };
}

/** Veredictos de un Product: imagen vacía y descripción vacía fallan; falta de offers o de imagen avisa. */
export function productFindings(product) {
  const findings = [];
  const name = typeof product.name === "string" && product.name.trim() ? product.name.trim() : "(sin nombre)";
  const image = product.image;
  if (Array.isArray(image) && image.length === 0) {
    findings.push(
      verdict(
        STATUS.FAIL,
        `Product «${name}»: image es un array vacío`,
        "Sin imagen, Google no muestra la ficha con foto: el producto necesita una imagen destacada.",
      ),
    );
  } else if (image === "") {
    findings.push(verdict(STATUS.FAIL, `Product «${name}»: image es una cadena vacía`, "Revisa la imagen destacada del producto."));
  } else if (image === undefined || image === null) {
    findings.push(verdict(STATUS.WARN, `Product «${name}»: no trae image`, "Añade la imagen destacada del producto."));
  }
  if (typeof product.description === "string" && product.description.trim() === "") {
    findings.push(
      verdict(
        STATUS.FAIL,
        `Product «${name}»: description es una cadena vacía`,
        "Rellena la descripción corta del producto en WooCommerce.",
      ),
    );
  } else if (product.description === undefined || product.description === null) {
    findings.push(verdict(STATUS.WARN, `Product «${name}»: no trae description`, "Rellena la descripción del producto."));
  }
  if (product.offers === undefined || product.offers === null) {
    findings.push(
      verdict(
        STATUS.WARN,
        `Product «${name}»: no trae offers`,
        "Sin offers no hay precio ni disponibilidad en el JSON-LD: revisa el precio del producto.",
      ),
    );
  }
  return findings;
}

// ---------------------------------------------------------------------------
//  Checks 1–4 y 7 (respuestas ya leídas)
// ---------------------------------------------------------------------------

export function compareBrandOrigin(brandUrl, frontendUrl) {
  const raw = String(brandUrl ?? "").trim();
  if (!raw) {
    return verdict(
      STATUS.WARN,
      "brand.url está vacío: el sitemap y los enlaces de correo no tendrán dominio",
      "Pon el origen público del frontend en Control Center → Marca (o en instance.config.json).",
    );
  }
  const brand = originOf(raw);
  if (brand === null) {
    return verdict(STATUS.FAIL, `brand.url no es una URL válida: «${raw}»`, "Usa una URL absoluta, p. ej. https://tienda.ejemplo.com");
  }
  const front = originOf(frontendUrl);
  if (brand !== front) {
    return verdict(
      STATUS.FAIL,
      `brand.url (${brand}) no es el origen del frontend (${front})`,
      "Los enlaces de correo saldrían con otro dominio. Iguala brand.url al origen público del frontend.",
    );
  }
  return verdict(STATUS.OK, `brand.url coincide con el frontend (${front})`);
}

export function healthVerdict(status, body, error = "") {
  if (status === 0) return verdict(STATUS.FAIL, `/api/health no responde (${error || "sin respuesta"})`, "Comprueba que el frontend está levantado.");
  if (!isSuccess(status)) {
    return verdict(STATUS.FAIL, `/api/health responde ${status}`, "El BFF no está sano: revisa el log del frontend.");
  }
  if (body?.status === "ok") return verdict(STATUS.OK, "/api/health responde ok");
  if (body?.status) {
    const deps = body.dependencies ? ` (${JSON.stringify(body.dependencies)})` : "";
    return verdict(
      STATUS.WARN,
      `/api/health responde «${body.status}»${deps}`,
      "Una dependencia no responde (Redis o WordPress): el frontend degrada, pero revísala.",
    );
  }
  return verdict(STATUS.WARN, "/api/health responde 2xx sin campo status");
}

/** «responde 200» o, si no hubo respuesta, «no responde (ECONNREFUSED)». */
export function respondsText(status, error = "") {
  return status === 0 ? `no responde (${error || "sin respuesta"})` : `responde ${status}`;
}

export function csrfVerdict(status, body, cookie, error = "") {
  if (status === 0) {
    return verdict(STATUS.FAIL, `/api/csrf ${respondsText(status, error)}`, "Comprueba que el frontend está levantado.");
  }
  if (!isSuccess(status)) {
    return verdict(STATUS.FAIL, `/api/csrf ${respondsText(status)}`, "Revisa CSRF_SECRET (obligatorio) en .env y frontend/.env.local.");
  }
  if (typeof body?.csrfToken !== "string" || body.csrfToken === "") {
    return verdict(STATUS.FAIL, "/api/csrf no devuelve csrfToken en el cuerpo", "El BFF no emite tokens: revisa CSRF_SECRET.");
  }
  if (!cookie) {
    return verdict(
      STATUS.FAIL,
      "/api/csrf no fija la cookie CSRF",
      "Sin cookie no hay doble envío: las escrituras responderán 403.",
    );
  }
  return verdict(STATUS.OK, `token CSRF emitido (cookie ${cookie.name} + cuerpo)`);
}

/** Cookie CSRF de una lista de cabeceras Set-Cookie: prefiere `hwe_csrf`, si no cualquiera con «csrf». */
export function findCsrfCookie(setCookies, preferred = "hwe_csrf") {
  const parsed = [];
  for (const raw of setCookies ?? []) {
    const pair = String(raw).split(";")[0];
    const eq = pair.indexOf("=");
    if (eq <= 0) continue;
    parsed.push({ name: pair.slice(0, eq).trim(), value: pair.slice(eq + 1).trim() });
  }
  return parsed.find((c) => c.name === preferred) ?? parsed.find((c) => /csrf/i.test(c.name)) ?? null;
}

export function forgotPasswordVerdict(status, error = "") {
  if (status === 0) {
    return verdict(STATUS.FAIL, `POST /api/auth/forgot-password no responde (${error || "sin respuesta"})`, "Comprueba que el frontend está levantado.");
  }
  if (isSuccess(status)) {
    return verdict(STATUS.OK, `POST /api/auth/forgot-password → ${status} con un correo inexistente`);
  }
  if (status === 429) {
    return verdict(
      STATUS.WARN,
      "POST /api/auth/forgot-password → 429 (límite de 3 por 5 minutos y por IP)",
      "Vuelve a ejecutar el doctor dentro de cinco minutos.",
    );
  }
  if (status === 403) {
    return verdict(
      STATUS.FAIL,
      "POST /api/auth/forgot-password → 403 (Origin o CSRF rechazados)",
      "ALLOWED_ORIGIN / HEADLESS_ALLOWED_ORIGINS deben ser exactamente el origen del frontend.",
    );
  }
  if (status >= 500) {
    return verdict(STATUS.FAIL, `POST /api/auth/forgot-password → ${status}`, FORGOT_5XX_HINT);
  }
  return verdict(STATUS.FAIL, `POST /api/auth/forgot-password → ${status}`, "Revisa el log del frontend para ver la causa.");
}

export function configVerdict(status, text, error = "") {
  if (status === 0) {
    return { verdict: verdict(STATUS.FAIL, `WordPress no responde (${error || "sin respuesta"})`, "Levanta la pila o corrige --wordpress.") };
  }
  if (!isSuccess(status)) {
    return {
      verdict: verdict(
        STATUS.FAIL,
        `/wp-json/hwe/v1/config responde ${status}`,
        "Con 404, WordPress no acepta la petición: HWE_REVALIDATION_SECRET debe coincidir con el de wp-config.php, y --wordpress debe apuntar a WordPress.",
      ),
    };
  }
  let parsed;
  try {
    parsed = JSON.parse(text);
  } catch {
    return { verdict: verdict(STATUS.FAIL, "/wp-json/hwe/v1/config no devuelve JSON", "¿Responde WordPress o una página de error?") };
  }
  const brandUrl = typeof parsed?.brand?.url === "string" ? parsed.brand.url : "";
  return { verdict: null, brandUrl, config: parsed };
}

export function robotsVerdict(status, error = "") {
  if (isSuccess(status)) return verdict(STATUS.OK, "robots.txt responde 2xx");
  return verdict(
    STATUS.WARN,
    `robots.txt ${respondsText(status, error)}`,
    "Sin robots.txt los rastreadores no reciben reglas ni la ruta del sitemap.",
  );
}

/** Check 7: una ruta inexistente debe responder 404; un 200 es un «soft 404» (Google lo indexa como página). */
export function softNotFoundVerdict(status, location = "") {
  if (status === 404) return verdict(STATUS.OK, "una ruta inexistente responde 404");
  if (status === 0) return verdict(STATUS.FAIL, "la ruta inexistente no responde", "Comprueba que el frontend está levantado.");
  if (isSuccess(status)) {
    return verdict(
      STATUS.FAIL,
      "soft 404: una ruta inexistente responde 2xx en vez de 404",
      "La página de error debe devolver 404 real (status 404), no 200.",
    );
  }
  if (status >= 300 && status < 400) {
    return verdict(
      STATUS.WARN,
      `una ruta inexistente redirige (${status}${location ? ` → ${location}` : ""}); debería responder 404`,
      "Revisa el middleware de idiomas y las redirecciones del registro de la instancia.",
    );
  }
  if (status >= 500) return verdict(STATUS.FAIL, `una ruta inexistente responde ${status}`, "El 404 de la app falla: revisa el log del frontend.");
  return verdict(STATUS.WARN, `una ruta inexistente responde ${status} (se esperaba 404)`);
}

// ---------------------------------------------------------------------------
//  Entorno y resumen
// ---------------------------------------------------------------------------

/** Lee un .env como texto: ignora comentarios y líneas vacías, y quita comillas envolventes. */
export function parseEnvFile(text) {
  const out = {};
  for (const raw of String(text ?? "").split(/\r?\n/)) {
    const line = raw.trim();
    if (!line || line.startsWith("#")) continue;
    const eq = line.indexOf("=");
    if (eq <= 0) continue;
    const key = line.slice(0, eq).trim();
    let value = line.slice(eq + 1).trim();
    const quote = value[0];
    if (value.length >= 2 && (quote === '"' || quote === "'") && value.endsWith(quote)) {
      value = value.slice(1, -1);
    }
    out[key] = value;
  }
  return out;
}

export function summarize(results) {
  const counts = { ok: 0, warn: 0, fail: 0, skip: 0 };
  for (const r of results) counts[r.status] = (counts[r.status] ?? 0) + 1;
  return counts;
}

export function exitCodeFor(results) {
  return results.some((r) => r.status === STATUS.FAIL) ? 1 : 0;
}

const LABEL = { ok: "OK  ", warn: "WARN", fail: "FAIL", skip: "SKIP" };

export function formatTable(results) {
  const lines = [];
  for (const r of results) {
    const name = CHECK_NAMES[r.check] ?? `check ${r.check}`;
    lines.push(`${LABEL[r.status] ?? r.status}  ${r.check}. ${name}: ${r.message}`);
    if (r.hint) lines.push(`        → ${r.hint}`);
  }
  const s = summarize(results);
  lines.push("", `Resumen: ${s.ok} ok, ${s.warn} warn, ${s.fail} fail, ${s.skip} skip.`);
  lines.push(exitCodeFor(results) === 0 ? "Sin fallos." : "Hay fallos: corrígelos y vuelve a ejecutar el doctor.");
  return lines.join("\n");
}

export function formatJson(results) {
  return JSON.stringify({ summary: summarize(results), results }, null, 2);
}
