/** Subconjunto de `SiteConfig["integrations"]` (Control Center → Integraciones). */
export interface AnalyticsIntegrationsConfig {
  analytics_provider: string;
  analytics_id: string;
  gtm_id: string;
  meta_pixel_id: string;
  tiktok_pixel_id: string;
  hubspot_portal_id: string;
}

/**
 * Qué servicios de terceros hay que cargar (con consentimiento) según lo
 * configurado en Control Center. Es la ÚNICA fuente de verdad: decide los
 * scripts, si se pide consentimiento y qué dice el banner. Sin ningún servicio
 * no hay nada que consentir: ni banner, ni «Preferencias de cookies».
 */
export interface TrackingServices {
  ga4: boolean;
  /** Plausible (`analytics_provider = "plausible"`, `analytics_id` = el dominio). */
  plausible: boolean;
  gtm: boolean;
  metaPixel: boolean;
  tiktokPixel: boolean;
  hubspot: boolean;
  /** Medición (GA4, Plausible, GTM o HubSpot). */
  analytics: boolean;
  /** Publicidad (píxeles de Meta o TikTok). */
  advertising: boolean;
  /** Hay al menos un servicio que requiere consentimiento. */
  any: boolean;
}

/**
 * Forma válida de cada ID. Todos se INCRUSTAN en scripts inline o en la URL
 * de uno (`fbq('init','…')`, gtm.js, `js.hs-scripts.com/<id>.js`…), así que
 * lo que no case se trata como vacío: una comilla o un espacio pegado desde
 * el panel rompía el script —o inyectaba el suyo— en vez de, simplemente, no
 * cargarlo.
 */
const TRACKING_ID_PATTERNS = {
  analytics_id: /^G-[A-Z0-9]+$/,
  /** El dominio con el que está dado de alta el sitio en Plausible (`tienda.com`). */
  plausible_domain: /^[A-Z0-9]([A-Z0-9-]*[A-Z0-9])?(\.[A-Z0-9]([A-Z0-9-]*[A-Z0-9])?)+$/,
  gtm_id: /^GTM-[A-Z0-9]+$/,
  meta_pixel_id: /^\d+$/,
  tiktok_pixel_id: /^[A-Z0-9]+$/,
  hubspot_portal_id: /^\d+$/,
} as const;

function cleanTrackingId(raw: string, pattern: RegExp): string {
  const id = raw.trim().toUpperCase();
  return pattern.test(id) ? id : "";
}

/**
 * IDs normalizados (sin espacios, en mayúsculas) y validados contra su forma.
 * Es lo único que debe llegar a los scripts: el ID crudo del CMS no.
 */
export function sanitizeTrackingIds(
  config: AnalyticsIntegrationsConfig,
): AnalyticsIntegrationsConfig {
  // `analytics_id` es un id de GA4 (`G-…`) o, con Plausible, un dominio: cada
  // proveedor se valida con su forma. El dominio se devuelve en minúsculas.
  const plausible = config.analytics_provider === "plausible";
  const analyticsId = plausible
    ? cleanTrackingId(config.analytics_id, TRACKING_ID_PATTERNS.plausible_domain)
    : cleanTrackingId(config.analytics_id, TRACKING_ID_PATTERNS.analytics_id);
  return {
    analytics_provider: config.analytics_provider,
    analytics_id: plausible ? analyticsId.toLowerCase() : analyticsId,
    gtm_id: cleanTrackingId(config.gtm_id, TRACKING_ID_PATTERNS.gtm_id),
    meta_pixel_id: cleanTrackingId(
      config.meta_pixel_id,
      TRACKING_ID_PATTERNS.meta_pixel_id,
    ),
    tiktok_pixel_id: cleanTrackingId(
      config.tiktok_pixel_id,
      TRACKING_ID_PATTERNS.tiktok_pixel_id,
    ),
    hubspot_portal_id: cleanTrackingId(
      config.hubspot_portal_id,
      TRACKING_ID_PATTERNS.hubspot_portal_id,
    ),
  };
}

export function resolveTrackingServices(
  raw: AnalyticsIntegrationsConfig,
): TrackingServices {
  const config = sanitizeTrackingIds(raw);
  const gtm = Boolean(config.gtm_id);
  // Con GTM, el contenedor dispara GA4 por dentro: gtag.js en paralelo
  // duplicaría el envío.
  const ga4 =
    !gtm && config.analytics_provider === "ga4" && Boolean(config.analytics_id);
  // Plausible no usa cookies, pero se carga también tras el consentimiento: es
  // lo que ya hacía la v1 y lo más conservador (una instancia que no quiera
  // pedirlo para un servicio sin cookies puede dejar el resto sin configurar).
  const plausible =
    config.analytics_provider === "plausible" && Boolean(config.analytics_id);
  const metaPixel = Boolean(config.meta_pixel_id);
  const tiktokPixel = Boolean(config.tiktok_pixel_id);
  // HubSpot se declara MEDICIÓN, que es como clasifica HubSpot sus propias
  // cookies de seguimiento (`__hstc`, `hubspotutk`, `__hssc`: «Analytics»).
  const hubspot = Boolean(config.hubspot_portal_id);
  const analytics = ga4 || plausible || gtm || hubspot;
  const advertising = metaPixel || tiktokPixel;
  return {
    ga4,
    plausible,
    gtm,
    metaPixel,
    tiktokPixel,
    hubspot,
    analytics,
    advertising,
    any: analytics || advertising,
  };
}

/**
 * Nombre estable de cada servicio, en el orden en que el banner los nombra.
 * Es lo que se guarda con la decisión (en el navegador y en el registro de
 * consentimientos): qué servicios cubre un «Aceptar».
 */
export const TRACKING_SERVICE_NAMES = [
  "ga4",
  "plausible",
  "gtm",
  "meta",
  "tiktok",
  "hubspot",
] as const;
export type TrackingServiceName = (typeof TRACKING_SERVICE_NAMES)[number];

const SERVICE_FLAGS: Record<
  TrackingServiceName,
  "ga4" | "plausible" | "gtm" | "metaPixel" | "tiktokPixel" | "hubspot"
> = {
  ga4: "ga4",
  plausible: "plausible",
  gtm: "gtm",
  meta: "metaPixel",
  tiktok: "tiktokPixel",
  hubspot: "hubspot",
};

export function isTrackingServiceName(value: unknown): value is TrackingServiceName {
  return TRACKING_SERVICE_NAMES.includes(value as TrackingServiceName);
}

/** Servicios activos, por su nombre estable. */
export function trackingServiceNames(
  services: TrackingServices,
): TrackingServiceName[] {
  return TRACKING_SERVICE_NAMES.filter((name) => services[SERVICE_FLAGS[name]]);
}

/**
 * Lo inverso: los servicios que nombra una lista (la que enseñó un banner).
 * Los nombres desconocidos se ignoran y el orden no importa.
 */
export function servicesFromNames(names: readonly string[]): TrackingServices {
  const has = (name: TrackingServiceName) => names.includes(name);
  const ga4 = has("ga4");
  const plausible = has("plausible");
  const gtm = has("gtm");
  const metaPixel = has("meta");
  const tiktokPixel = has("tiktok");
  const hubspot = has("hubspot");
  const analytics = ga4 || plausible || gtm || hubspot;
  const advertising = metaPixel || tiktokPixel;
  return {
    ga4,
    plausible,
    gtm,
    metaPixel,
    tiktokPixel,
    hubspot,
    analytics,
    advertising,
    any: analytics || advertising,
  };
}

/** Clave del mensaje del banner: qué tipo de cookies se piden. */
export function consentKind(services: TrackingServices): "analytics" | "ads" | "both" {
  if (services.analytics && services.advertising) return "both";
  return services.advertising ? "ads" : "analytics";
}

/**
 * Terceros que el banner NOMBRA (`{providers}` de `consent.message`): el
 * consentimiento tiene que ser informado, y «cookies publicitarias» sin decir
 * de quién no lo es. Nombres de marca, iguales en los dos idiomas, y unidos
 * con coma y no con `Intl.ListFormat`: el servidor rehace este texto para el
 * registro de consentimientos, y su hash tiene que ser el del texto pintado.
 */
export function consentProviders(services: TrackingServices): string {
  const names: string[] = [];
  if (services.ga4) names.push("Google Analytics");
  if (services.plausible) names.push("Plausible");
  if (services.gtm) names.push("Google Tag Manager");
  if (services.metaPixel) names.push("Meta");
  if (services.tiktokPixel) names.push("TikTok");
  if (services.hubspot) names.push("HubSpot");
  return names.join(", ");
}

/** Argumentos de `consent.message`, los mismos en el banner y en el registro. */
export function consentMessageArgs(services: TrackingServices): {
  kind: "analytics" | "ads" | "both";
  providers: string;
} {
  return { kind: consentKind(services), providers: consentProviders(services) };
}
