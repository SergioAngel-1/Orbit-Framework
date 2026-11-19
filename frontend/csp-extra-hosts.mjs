// ============================================================================
//  Orígenes EXTRA que un despliegue añade a la CSP (`script-src`, `connect-src`,
//  `img-src`), sin editar el núcleo.
//
//  La CSP la arma `next.config.mjs` al construir: en la imagen `standalone` Next
//  la hornea en el artefacto y no se vuelve a evaluar al arrancar. Por eso son
//  variables de BUILD (ARG del Dockerfile + `build.args` del compose), igual que
//  `CSP_ANALYTICS_PROVIDERS` (que solo ESTRECHA lo que ya está).
//
//  Sirven para sumar hosts que el framework no conoce: los de audiencias de
//  Google Ads, los de una pasarela de Conversions API, una CDN de tipografías…
//    CSP_EXTRA_SCRIPT_SRC=https://googleads.g.doubleclick.net,https://cdn.ejemplo.com
//    CSP_EXTRA_CONNECT_SRC=https://api.pasarela.example
//    CSP_EXTRA_IMG_SRC=https://*.cdn.ejemplo.com
//
//  Validación estricta: SOLO orígenes `https://`, con comodín de subdominio
//  (`https://*.ejemplo.com`) y puerto opcional. Se descarta cualquier otra cosa
//  —`*`, `http:`, palabras clave entre comillas (`'unsafe-inline'`), espacios,
//  `;`, rutas, consultas…—, porque los valores se concatenan en una cabecera:
//  un `;` colaría directivas nuevas enteras. Un valor inválido se ignora con un
//  aviso en el build y el resto sí entra (mismo criterio que
//  `scripts/image-formats.mjs`). Sin variables, el resultado es vacío y la CSP
//  queda idéntica byte a byte.
//
//  El comodín exige al menos tres etiquetas (`*.ejemplo.com`) y no vale justo
//  sobre un sufijo público de dos etiquetas (`*.com.co`, `*.co.uk`): si no, se
//  autorizaría a TODOS los registros de un ccTLD entero. La lista de segundas
//  etiquetas es corta y no es la Public Suffix List: un sufijo público que no
//  esté en ella seguiría pasando (ver docs/SECURITY.md).
// ============================================================================

/** Directiva de la CSP → variable de entorno que la amplía. */
export const EXTRA_HOSTS_ENV = {
  script: "CSP_EXTRA_SCRIPT_SRC",
  connect: "CSP_EXTRA_CONNECT_SRC",
  img: "CSP_EXTRA_IMG_SRC",
};

/** Una etiqueta DNS válida (sin el comodín). */
const LABEL = /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

/**
 * `https://host[:puerto]`, y nada más: sin ruta, sin usuario, sin consulta y sin
 * fragmento. La clase de caracteres deja fuera espacios, `;`, comillas y `,`
 * (el separador), así que una inyección de directivas no puede pasar de aquí.
 */
const ORIGIN = /^https:\/\/([a-z0-9.*-]+)(?::(\d{1,5}))?$/i;

/**
 * Segundas etiquetas que, bajo un ccTLD de dos letras, forman un sufijo público
 * de dos etiquetas (`co.uk`, `com.co`, `com.br`…). Lista corta y deliberada: no
 * es la Public Suffix List, solo los casos frecuentes.
 */
const PUBLIC_SECOND_LEVEL_LABELS = new Set([
  "ac",
  "co",
  "com",
  "edu",
  "gob",
  "gov",
  "mil",
  "net",
  "nom",
  "org",
  "sch",
]);

/**
 * Host con al menos un punto (dominio + TLD). El comodín solo vale como primera
 * etiqueta y con un dominio detrás (`*.ejemplo.com`, no `*` ni `*.com`), nunca
 * justo sobre un sufijo público de dos etiquetas (`*.com.co`, `*.co.uk`).
 */
function isValidHost(host) {
  if (host.length > 253) return false;
  const labels = host.split(".");
  const wildcard = labels[0] === "*";
  if (wildcard) labels.shift();
  if (labels.length < 2 || !labels.every((label) => LABEL.test(label))) return false;
  if (!wildcard) return true;
  // Tres etiquetas como mínimo con el comodín; y si solo hay dos detrás
  // (`*.x.y`), `x` no puede ser la segunda etiqueta de un sufijo público bajo un
  // ccTLD de dos letras.
  if (labels.length === 2 && labels[1].length === 2) {
    if (PUBLIC_SECOND_LEVEL_LABELS.has(labels[0])) return false;
  }
  return true;
}

/**
 * Origen canónico (`https://host` en minúsculas, con el puerto si lo trae), o
 * `null` si el valor no es un origen https admisible.
 */
function parseOrigin(value) {
  const match = ORIGIN.exec(value);
  if (!match) return null;
  const host = match[1].toLowerCase();
  if (!isValidHost(host)) return null;
  const port = match[2];
  if (port !== undefined) {
    const number = Number(port);
    if (number < 1 || number > 65535) return null;
    return `https://${host}:${number}`;
  }
  return `https://${host}`;
}

/**
 * Orígenes extra por directiva, listos para concatenar en la CSP.
 *
 * @param {Record<string, string | undefined>} env
 * @param {(message: string) => void} warn
 * @returns {{ script: string[]; connect: string[]; img: string[] }}
 */
export function cspExtraHosts(env = {}, warn = console.warn) {
  const out = { script: [], connect: [], img: [] };

  for (const [directive, variable] of Object.entries(EXTRA_HOSTS_ENV)) {
    const raw = env[variable];
    if (raw === undefined || raw === null || String(raw).trim() === "") continue;

    const ignored = [];
    for (const entry of String(raw).split(",")) {
      const value = entry.trim();
      if (value === "") continue;
      const origin = parseOrigin(value);
      if (origin === null) {
        ignored.push(value);
      } else if (!out[directive].includes(origin)) {
        out[directive].push(origin);
      }
    }

    if (ignored.length > 0) {
      warn(
        `${variable}: se ignoran los valores no válidos (${ignored.join(", ")}); ` +
          "solo se admiten orígenes https, con comodín de subdominio " +
          "(https://*.ejemplo.com, nunca sobre un sufijo público como *.com.co) " +
          "y sin ruta, consulta ni palabra clave.",
      );
    }
  }

  return out;
}
