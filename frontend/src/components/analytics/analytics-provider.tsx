"use client";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  useSyncExternalStore,
  type ReactNode,
} from "react";
import Script from "next/script";
import { ConsentBanner } from "./consent-banner";
import { Ga4PageViews } from "./ga4-page-views";
import { HubSpotPageViews } from "./hubspot-page-views";
import { MetaPixelPageViews } from "./meta-pixel-page-views";
import { setTrackingReady } from "./track-event";
import { readStoredConsent, storeConsent } from "@/lib/analytics/consent-storage";
import { clearTrackingCookies } from "@/lib/analytics/tracking-cookies";
import {
  consentMessageArgs,
  resolveTrackingServices,
  sanitizeTrackingIds,
  type AnalyticsIntegrationsConfig,
} from "@/lib/analytics/services";

type ConsentStatus = "unknown" | "accepted" | "declined";

interface ConsentContextValue {
  consent: ConsentStatus;
  accept: () => void;
  decline: () => void;
  /** Vuelve a mostrar el banner para cambiar una decisión ya tomada. */
  openPreferences: () => void;
  /**
   * Hay algún servicio configurado en Control Center → Integraciones que
   * requiera consentimiento. Sin ninguno no hay nada que pedir ni que cambiar.
   */
  required: boolean;
}

const ConsentContext = createContext<ConsentContextValue | null>(null);

/**
 * Consentimiento de cookies. Retirarlo tiene que ser tan fácil como darlo, así
 * que el pie puede ofrecer «Preferencias de cookies» vía `openPreferences`.
 */
export function useConsent(): ConsentContextValue {
  const ctx = useContext(ConsentContext);
  if (!ctx) throw new Error("useConsent requiere AnalyticsProvider");
  return ctx;
}

export type { AnalyticsIntegrationsConfig };

// ── Scripts de cada servicio ────────────────────────────────────────────────
//
// Todos reciben IDs YA validados (`sanitizeTrackingIds`): se incrustan en
// scripts inline o en la URL de uno.

/**
 * `gtag` tiene que empujar el objeto `arguments` tal cual: gtag.js descarta los
 * arrays, y con un `(...args) => dataLayer.push(args)` el `config` se ignoraba
 * y GA4 no recibía ni el page_view. Se define ANTES de cargar la librería (la
 * cola se procesa al llegar) y queda en `window.gtag` para que `trackEvent`
 * envíe los eventos de e-commerce por la misma vía.
 */
function Ga4Scripts({ gaId }: { gaId: string }) {
  return (
    <>
      <Script
        id="hwe-ga4-init"
        strategy="afterInteractive"
        dangerouslySetInnerHTML={{
          __html: `window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}window.gtag=gtag;gtag('js',new Date());gtag('config',${JSON.stringify(gaId)});`,
        }}
      />
      <Script
        id="hwe-ga4"
        src={`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(gaId)}`}
        strategy="afterInteractive"
      />
    </>
  );
}

function PlausibleScript({ domain, host }: { domain: string; host: string }) {
  return (
    <Script
      id="hwe-plausible"
      src={`${host.replace(/\/$/, "")}/js/script.js`}
      data-domain={domain}
      strategy="afterInteractive"
      defer
    />
  );
}

function GtmScripts({ gtmId }: { gtmId: string }) {
  return (
    <>
      <Script
        id="hwe-gtm"
        strategy="afterInteractive"
        dangerouslySetInnerHTML={{
          __html: `(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','${gtmId}');`,
        }}
      />
      {/* Fallback sin JS — recomendado por Google justo tras <body>; aquí basta
          con que exista en el documento. */}
      <noscript>
        <iframe
          src={`https://www.googletagmanager.com/ns.html?id=${gtmId}`}
          height="0"
          width="0"
          style={{ display: "none", visibility: "hidden" }}
          title="gtm"
        />
      </noscript>
    </>
  );
}

/**
 * Código base oficial de Meta con UNA línea añadida, `fbq.disablePushState`,
 * que apaga el PageView automático del píxel en cada cambio de URL: los de la
 * navegación interna los manda `MetaPixelPageViews` (ver allí el porqué).
 */
function MetaPixelScript({ pixelId }: { pixelId: string }) {
  return (
    <>
      <Script
        id="hwe-meta-pixel"
        strategy="afterInteractive"
        dangerouslySetInnerHTML={{
          __html: `!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq.disablePushState=true;fbq('init','${pixelId}');fbq('track','PageView');`,
        }}
      />
      <noscript>
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          height="1"
          width="1"
          style={{ display: "none" }}
          alt=""
          src={`https://www.facebook.com/tr?id=${pixelId}&ev=PageView&noscript=1`}
        />
      </noscript>
    </>
  );
}

function TikTokPixelScript({ pixelId }: { pixelId: string }) {
  return (
    <Script
      id="hwe-tiktok-pixel"
      strategy="afterInteractive"
      dangerouslySetInnerHTML={{
        __html: `!function (w, d, t) {w.TiktokAnalyticsObject=t;var ttq=w[t]=w[t]||[];ttq.methods=["page","track","identify","instances","debug","on","off","once","ready","alias","group","enableCookie","disableCookie","holdConsent","revokeConsent","grantConsent"],ttq.setAndDefer=function(t,e){t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}};for(var i=0;i<ttq.methods.length;i++)ttq.setAndDefer(ttq,ttq.methods[i]);ttq.instance=function(t){for(var e=ttq._i[t]||[],n=0;n<ttq.methods.length;n++)ttq.setAndDefer(e,ttq.methods[n]);return e},ttq.load=function(e,n){var r="https://analytics.tiktok.com/i18n/pixel/events.js",o=n&&n.partner;ttq._i=ttq._i||{},ttq._i[e]=[],ttq._i[e]._u=r,ttq._t=ttq._t||{},ttq._t[e]=+new Date,ttq._o=ttq._o||{},ttq._o[e]=n||{};n=document.createElement("script"),n.type="text/javascript",n.async=!0,n.src=r+"?sdkid="+e+"&lib="+t;var a=document.getElementsByTagName("script")[0];a.parentNode.insertBefore(n,a)};ttq.load('${pixelId}');ttq.page();}(window, document, 'ttq');`,
      }}
    />
  );
}

/**
 * Código de seguimiento de HubSpot, el oficial («HubSpot Embed Code»): un
 * cargador por portal que a su vez inyecta analytics, formularios recogidos,
 * CTA y su banner según lo que el portal tenga activo (de ahí los hosts de la
 * CSP). Las vistas de la navegación interna las manda `HubSpotPageViews`.
 */
function HubSpotScript({ portalId }: { portalId: string }) {
  return (
    <Script
      id="hs-script-loader"
      src={`https://js.hs-scripts.com/${portalId}.js`}
      strategy="afterInteractive"
    />
  );
}

// ── Consentimiento: almacén externo (localStorage) ──────────────────────────

/** Aviso in-tab: `storage` solo lo reciben las OTRAS pestañas, no la que escribe. */
const CONSENT_EVENT = "hwe:cookie-consent";

function readConsent(): ConsentStatus {
  try {
    return readStoredConsent(localStorage)?.status ?? "unknown";
  } catch {
    return "unknown";
  }
}

function notifyConsentChange(): void {
  window.dispatchEvent(new Event(CONSENT_EVENT));
}

function subscribeConsent(onStoreChange: () => void): () => void {
  window.addEventListener(CONSENT_EVENT, onStoreChange);
  window.addEventListener("storage", onStoreChange);
  return () => {
    window.removeEventListener(CONSENT_EVENT, onStoreChange);
    window.removeEventListener("storage", onStoreChange);
  };
}

function writeConsent(value: Exclude<ConsentStatus, "unknown">): void {
  try {
    storeConsent(localStorage, value);
  } catch {
    /* sin almacenamiento: la decisión vale para esta vista y se repreguntará */
  }
  notifyConsentChange();
}

export function AnalyticsProvider({
  children,
  config,
  plausibleHost = "https://plausible.io",
}: {
  children: ReactNode;
  config: AnalyticsIntegrationsConfig;
  /** Origen del script de Plausible (por defecto el servicio alojado). */
  plausibleHost?: string;
}) {
  // Solo los IDs validados llegan a los scripts inline (ver `sanitizeTrackingIds`).
  const ids = useMemo(() => sanitizeTrackingIds(config), [config]);
  const services = useMemo(() => resolveTrackingServices(ids), [ids]);

  // El consentimiento NO es estado de React: vive en `localStorage`, que es un
  // sistema externo. `useSyncExternalStore` lo lee donde está en vez de copiarlo
  // a un `useState` desde un efecto de montaje; escuchar `storage` sincroniza la
  // decisión entre pestañas.
  const consent = useSyncExternalStore(
    subscribeConsent,
    readConsent,
    () => "unknown" as ConsentStatus,
  );
  const mounted = useSyncExternalStore(
    () => () => {},
    () => true,
    () => false,
  );
  const [preferencesOpen, setPreferencesOpen] = useState(false);

  const accept = useCallback(() => {
    setPreferencesOpen(false);
    writeConsent("accepted");
  }, []);
  const decline = useCallback(() => {
    setPreferencesOpen(false);
    writeConsent("declined");
  }, []);
  const openPreferences = useCallback(() => setPreferencesOpen(true), []);
  const closePreferences = useCallback(() => setPreferencesOpen(false), []);

  // Retirar un consentimiento dado: se borran las cookies que dejaron los
  // scripts y se recarga, porque un script ya ejecutado no se descarga (seguiría
  // midiendo la sesión en curso aunque su <Script> se desmonte). Es un efecto
  // sobre la TRANSICIÓN para cubrir también la retirada hecha en OTRA pestaña.
  // Toda llegada a «Solo necesarias» borra además las cookies, recargue o no.
  const previousConsent = useRef(consent);
  useEffect(() => {
    const was = previousConsent.current;
    previousConsent.current = consent;
    if (consent === "declined" && was !== "declined") clearTrackingCookies();
    if (was === "accepted" && consent === "declined") window.location.reload();
  }, [consent]);

  const consentValue = useMemo(
    () => ({ consent, accept, decline, openPreferences, required: services.any }),
    [consent, accept, decline, openPreferences, services.any],
  );

  // Abre (o cierra) la cola de `trackEvent` cuando el consentimiento ya está
  // resuelto. En el render de hidratación vale «sin respuesta» aunque haya un
  // «Aceptar» guardado: ahí no se toca, o se vaciaría lo que la página disparó
  // al hidratar. Con aceptación, este efecto corre DESPUÉS de los de los
  // `<Script>` de abajo, que inyectan los inline en su propio efecto.
  const trackersReady = consent === "accepted" && services.any;
  useEffect(() => {
    if (!mounted) return;
    setTrackingReady(trackersReady);
  }, [mounted, trackersReady]);

  const accepted = consent === "accepted";
  const askingFirstTime = mounted && services.any && consent === "unknown";
  const showPreferences = mounted && services.any && preferencesOpen;

  return (
    <ConsentContext.Provider value={consentValue}>
      {children}
      {accepted && services.ga4 && (
        <>
          <Ga4Scripts gaId={ids.analytics_id} />
          <Ga4PageViews />
        </>
      )}
      {accepted && services.plausible && (
        <PlausibleScript domain={ids.analytics_id} host={plausibleHost} />
      )}
      {accepted && services.gtm && <GtmScripts gtmId={ids.gtm_id} />}
      {accepted && services.metaPixel && (
        <>
          <MetaPixelScript pixelId={ids.meta_pixel_id} />
          <MetaPixelPageViews pixelId={ids.meta_pixel_id} />
        </>
      )}
      {accepted && services.tiktokPixel && (
        <TikTokPixelScript pixelId={ids.tiktok_pixel_id} />
      )}
      {accepted && services.hubspot && (
        <>
          <HubSpotScript portalId={ids.hubspot_portal_id} />
          <HubSpotPageViews />
        </>
      )}
      {/* Sin servicios configurados no hay nada que consentir: sin banner. */}
      {(askingFirstTime || showPreferences) && (
        <ConsentBanner
          message={consentMessageArgs(services)}
          onAccept={accept}
          onDecline={decline}
          onClose={askingFirstTime ? undefined : closePreferences}
        />
      )}
    </ConsentContext.Provider>
  );
}
