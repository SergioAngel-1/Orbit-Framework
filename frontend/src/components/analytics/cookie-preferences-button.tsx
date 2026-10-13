"use client";
import { useTranslations } from "next-intl";
import { useConsent } from "./analytics-provider";

/** Reabre el banner de cookies para cambiar (o retirar) el consentimiento. */
export function CookiePreferencesButton({ className }: { className?: string }) {
  const t = useTranslations("consent");
  const { openPreferences, required } = useConsent();
  // Sin servicios que consentir (Control Center → Integraciones vacío), no
  // hay preferencia que cambiar.
  if (!required) return null;
  return (
    <button type="button" onClick={openPreferences} className={className}>
      {t("preferences")}
    </button>
  );
}
