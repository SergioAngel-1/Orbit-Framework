/**
 * Cookies que ponen los scripts de analítica/publicidad que carga
 * `AnalyticsProvider` solo con consentimiento: GA4/GTM (`_ga`, `_ga_<id>`,
 * `_gid`, `_gat*`, `_gcl_*`), Meta Pixel (`_fbp`, `_fbc`), TikTok Pixel
 * (`_ttp`, `_tt_*`, `ttcsid*`) y HubSpot (`__hstc`, `__hssc`, `__hssrc`,
 * `hubspotutk`, `messagesUtk` y los `__hs_*` de su banner). Retirar el
 * consentimiento tiene que llevárselas: el script ya no se carga, pero lo que
 * dejó en el navegador seguiría ahí.
 */
const TRACKING_COOKIE_RE =
  /^(_ga(_.*)?|_gid|_gat.*|_gcl_.*|_fbp|_fbc|_ttp|_tt_.*|ttcsid.*|__hs.*|hubspotutk|messagesUtk)$/;

export function isTrackingCookie(name: string): boolean {
  return TRACKING_COOKIE_RE.test(name);
}

/** Nombres de las cookies de seguimiento presentes en un `document.cookie`. */
export function trackingCookieNames(cookieHeader: string): string[] {
  return cookieHeader
    .split(";")
    .map((part) => part.split("=")[0]?.trim() ?? "")
    .filter((name) => name && isTrackingCookie(name));
}

/**
 * Dominios en los que pudo quedar cada cookie. GA las escribe en el dominio
 * registrable con punto (`.tienda.example`), no en el host exacto, y una
 * cookie solo se borra repitiendo su mismo `domain`: se prueba el host sin
 * dominio y cada sufijo con al menos dos etiquetas (`www.a.com` → `a.com`,
 * `www.a.com`). En `localhost` o una IP basta con el host.
 */
export function cookieDomainCandidates(hostname: string): (string | null)[] {
  const candidates: (string | null)[] = [null];
  if (!hostname.includes(".") || /^[\d.]+$/.test(hostname)) return candidates;
  const labels = hostname.split(".");
  for (let i = labels.length - 2; i >= 0; i -= 1) {
    candidates.push(labels.slice(i).join("."));
  }
  return candidates;
}

/** Borra del navegador las cookies de seguimiento (en el host y sus dominios padre). */
export function clearTrackingCookies(): void {
  if (typeof document === "undefined") return;
  const expired = "expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
  const domains = cookieDomainCandidates(window.location.hostname);
  for (const name of trackingCookieNames(document.cookie)) {
    for (const domain of domains) {
      document.cookie = domain
        ? `${name}=; ${expired}; domain=.${domain}`
        : `${name}=; ${expired}`;
    }
  }
}
