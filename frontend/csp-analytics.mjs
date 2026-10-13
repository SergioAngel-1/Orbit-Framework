// ============================================================================
//  Hosts de analítica que la CSP autoriza (`script-src`, `connect-src`,
//  `img-src`). Se evalúa al construir/arrancar (`next.config.mjs`), así que NO
//  puede leer lo configurado en Control Center (runtime, por petición): la CSP
//  fija el MÁXIMO permitido y lo que realmente se carga lo decide
//  `AnalyticsProvider` según la config y el consentimiento.
//
//  Por defecto se autorizan todos los proveedores que el framework sabe cargar.
//  Un despliegue que solo usa algunos lo estrecha con
//  `CSP_ANALYTICS_PROVIDERS=ga4,meta` (o `none`): los demás hosts desaparecen de
//  la cabecera, y un script de un proveedor no listado, aunque alguien lo
//  configurara en el panel, el navegador lo bloquearía.
//
//  Los de Google son los de la configuración «solo analítica» de su guía
//  oficial de CSP: gtag.js envía por fetch, por beacon o por imagen según el
//  navegador. Activar Google Signals o vincular Google Ads exige la
//  configuración «con Ads» de esa guía. Los de HubSpot salen de leer el propio
//  `hs-scripts.com/<portal>.js`: el loader inyecta analítica, interactivos web,
//  recogida de formularios y su banner.
// ============================================================================

const GOOGLE = {
  script: ["https://www.googletagmanager.com"],
  connect: [
    "https://www.google-analytics.com",
    "https://*.google-analytics.com",
    "https://www.googletagmanager.com",
    "https://*.google.com",
  ],
  img: ["https://www.googletagmanager.com", "https://*.google-analytics.com"],
};

/** Hosts por servicio (los mismos nombres estables de `lib/analytics/services.ts`). */
const HOSTS = {
  ga4: GOOGLE,
  gtm: GOOGLE,
  meta: {
    script: ["https://connect.facebook.net"],
    connect: ["https://www.facebook.com"],
    img: ["https://www.facebook.com"],
  },
  tiktok: {
    script: ["https://analytics.tiktok.com"],
    connect: ["https://analytics.tiktok.com"],
    img: [],
  },
  hubspot: {
    script: [
      "https://*.hs-scripts.com",
      "https://*.hs-analytics.net",
      "https://*.hubspot.com",
      "https://*.hscollectedforms.net",
      "https://*.hs-banner.com",
    ],
    connect: [
      "https://*.hubspot.com",
      "https://*.hubapi.com",
      "https://*.hscollectedforms.net",
      "https://*.hs-banner.com",
    ],
    img: ["https://*.hubspot.com", "https://*.hsappstatic.net"],
  },
};

export const ANALYTICS_PROVIDERS = ["ga4", "plausible", "gtm", "meta", "tiktok", "hubspot"];

const DEFAULT_PLAUSIBLE_HOST = "https://plausible.io";

function uniq(list) {
  return [...new Set(list)];
}

/** Origen (sin ruta) de una URL, o `null` si no lo es. */
function originOf(value) {
  try {
    return new URL(value).origin;
  } catch {
    return null;
  }
}

/**
 * @param {Record<string, string | undefined>} env
 * @returns {{ script: string[]; connect: string[]; img: string[] }}
 */
export function analyticsCspHosts(env = process.env) {
  const raw = (env.CSP_ANALYTICS_PROVIDERS ?? "").trim().toLowerCase();
  const enabled =
    raw === ""
      ? ANALYTICS_PROVIDERS
      : raw
          .split(",")
          .map((name) => name.trim())
          .filter((name) => ANALYTICS_PROVIDERS.includes(name));

  const out = { script: [], connect: [], img: [] };
  for (const name of enabled) {
    if (name === "plausible") {
      const host = originOf(env.PLAUSIBLE_HOST || DEFAULT_PLAUSIBLE_HOST);
      if (host) {
        out.script.push(host);
        out.connect.push(host);
      }
      continue;
    }
    const hosts = HOSTS[name];
    out.script.push(...hosts.script);
    out.connect.push(...hosts.connect);
    out.img.push(...hosts.img);
  }
  return {
    script: uniq(out.script),
    connect: uniq(out.connect),
    img: uniq(out.img),
  };
}
