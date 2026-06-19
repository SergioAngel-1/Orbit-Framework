"use client";
import {
  createContext,
  useContext,
  useState,
  useEffect,
  useCallback,
  type ReactNode,
} from "react";
import { ConsentBanner } from "./consent-banner";

type ConsentStatus = "unknown" | "accepted" | "declined";

interface ConsentContextValue {
  consent: ConsentStatus;
  accept: () => void;
  decline: () => void;
}

const ConsentContext = createContext<ConsentContextValue | null>(null);

export function useConsent(): ConsentContextValue {
  const ctx = useContext(ConsentContext);
  if (!ctx) {
    throw new Error("useConsent debe usarse dentro de <AnalyticsProvider>.");
  }
  return ctx;
}

declare global {
  interface Window {
    dataLayer?: unknown[];
    plausible?: (event: string, options?: { props?: Record<string, unknown>; revenue?: { currency: string; amount: number } }) => void;
  }
}

/**
 * Envía un evento de analítica al proveedor activo (Plausible o GA4), respetando
 * el consentimiento: si el script no se ha cargado (sin opt-in), es un no-op.
 *
 * Eventos e-commerce recomendados: `add_to_cart`, `begin_checkout`, `purchase`.
 */
export function trackEvent(
  name: string,
  props: Record<string, unknown> = {},
  revenue?: { currency: string; amount: number },
): void {
  if (typeof window === "undefined") return;
  const provider = process.env.NEXT_PUBLIC_ANALYTICS_PROVIDER;

  if (provider === "plausible" && typeof window.plausible === "function") {
    window.plausible(name, revenue ? { props, revenue } : { props });
    return;
  }
  if (provider === "ga4" && Array.isArray(window.dataLayer)) {
    window.dataLayer.push({ event: name, ...props, ...(revenue ?? {}) });
  }
}

/**
 * Inyecta el script de analytics una vez (idempotente).
 * Soporta GA4 (`G-XXXXXXXX`) y Plausible (según NEXT_PUBLIC_ANALYTICS_PROVIDER).
 */
function useAnalyticsScript(consent: ConsentStatus) {
  useEffect(() => {
    if (consent !== "accepted") return;

    const provider = process.env.NEXT_PUBLIC_ANALYTICS_PROVIDER;
    const gaId = process.env.NEXT_PUBLIC_GA_ID;
    const plausibleDomain = process.env.NEXT_PUBLIC_PLAUSIBLE_DOMAIN;

    if (provider === "ga4" && gaId && !document.querySelector("#hwe-ga4")) {
      const script = document.createElement("script");
      script.id = "hwe-ga4";
      script.async = true;
      script.src = `https://www.googletagmanager.com/gtag/js?id=${gaId}`;
      document.head.appendChild(script);

      window.dataLayer = window.dataLayer || [];
      function gtag(...args: unknown[]) { window.dataLayer!.push(args); }
      gtag("js", new Date());
      gtag("config", gaId, { anonymize_ip: true });
    }

    if (provider === "plausible" && plausibleDomain && !document.querySelector("#hwe-plausible")) {
      const script = document.createElement("script");
      script.id = "hwe-plausible";
      script.async = true;
      script.defer = true;
      script.src = "https://plausible.io/js/script.js";
      script.setAttribute("data-domain", plausibleDomain);
      document.head.appendChild(script);
    }
  }, [consent]);
}

export function AnalyticsProvider({ children }: { children: ReactNode }) {
  const [consent, setConsent] = useState<ConsentStatus>("unknown");
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
    const stored = localStorage.getItem("cookie-consent");
    if (stored === "accepted" || stored === "declined") {
      setConsent(stored);
    }
  }, []);

  const accept = useCallback(() => {
    localStorage.setItem("cookie-consent", "accepted");
    setConsent("accepted");
  }, []);

  const decline = useCallback(() => {
    localStorage.setItem("cookie-consent", "declined");
    setConsent("declined");
  }, []);

  useAnalyticsScript(consent);

  return (
    <ConsentContext.Provider value={{ consent, accept, decline }}>
      {children}
      {mounted && consent === "unknown" && (
        <ConsentBanner onAccept={accept} onDecline={decline} />
      )}
    </ConsentContext.Provider>
  );
}
