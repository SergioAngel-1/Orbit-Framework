// ============================================================================
//  Emisor (navegador) de la medición del banner de cookies. El servidor y el
//  porqué de cada decisión, en `consent-stats.ts`.
//
//  Una vez por EVENTO y SESIÓN del navegador (`sessionStorage`): el banner de
//  primera visita se repinta en cada página hasta que se responde, y contar
//  cada repintado inflaría «lo vieron» y con él «lo ignoraron». Sin
//  `sessionStorage` (Safari con almacenamiento bloqueado) no se envía NADA:
//  sin poder deduplicar, el recuento saldría sesgado, y mejor ausente.
//
//  Los robots no cuentan: un rastreador que ejecuta JS (Googlebot, la prueba
//  de PageSpeed/Lighthouse) ve el banner y no responde nunca.
// ============================================================================

import { CONSENT_GENERATION } from "./consent-storage";

export type ConsentReportEvent = "shown" | "accepted" | "declined";
export type ConsentReportVariant = "bar" | "modal";

// Por generación: tras un reinicio (`CONSENT_GENERATION`), una sesión abierta
// que ya había contado «lo vio» vuelve a contar en la generación nueva.
const SESSION_KEY = `hwe-consent-stats:g${CONSENT_GENERATION}`;
const ENDPOINT = "/api/consent-stats";
const BOT_UA =
  /bot|crawl|spider|slurp|lighthouse|pagespeed|headless|preview|facebookexternalhit/i;

export function isLikelyBot(nav: Pick<Navigator, "userAgent" | "webdriver">): boolean {
  return nav.webdriver === true || BOT_UA.test(nav.userAgent);
}

/** Marca el evento como enviado en la sesión; `false` si ya lo estaba o no hay almacenamiento. */
function claimOncePerSession(
  event: ConsentReportEvent,
  variant: ConsentReportVariant,
): boolean {
  const token = `${variant}:${event}`;
  try {
    const raw = sessionStorage.getItem(SESSION_KEY);
    const sent: unknown = raw ? JSON.parse(raw) : [];
    const list = Array.isArray(sent) ? sent.filter((v) => typeof v === "string") : [];
    if (list.includes(token)) return false;
    sessionStorage.setItem(SESSION_KEY, JSON.stringify([...list, token]));
    return true;
  } catch {
    return false;
  }
}

export function reportConsentEvent(
  event: ConsentReportEvent,
  variant: ConsentReportVariant,
): void {
  if (typeof window === "undefined") return;
  if (isLikelyBot(navigator)) return;
  if (!claimOncePerSession(event, variant)) return;
  // `keepalive`: la decisión puede ir seguida de una navegación o una recarga.
  void fetch(ENDPOINT, {
    method: "POST",
    keepalive: true,
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ event, variant }),
  }).catch(() => {
    /* medición best-effort: un fallo no se reintenta ni se muestra */
  });
}
