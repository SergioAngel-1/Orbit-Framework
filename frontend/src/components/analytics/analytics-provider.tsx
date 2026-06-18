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

  return (
    <ConsentContext.Provider value={{ consent, accept, decline }}>
      {children}
      {mounted && consent === "unknown" && (
        <ConsentBanner onAccept={accept} onDecline={decline} />
      )}
    </ConsentContext.Provider>
  );
}
