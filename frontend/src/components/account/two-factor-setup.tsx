"use client";
import { useState, useEffect } from "react";
import { useTranslations } from "next-intl";
import { csrfFetch } from "@/lib/client/csrf";

export function TwoFactorSetup() {
  const t = useTranslations("twoFactor");
  const [enabled, setEnabled] = useState(false);
  const [loading, setLoading] = useState(true);
  const [secret, setSecret] = useState<string | null>(null);
  const [siteName, setSiteName] = useState("");
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [step, setStep] = useState<"idle" | "setup" | "verify" | "recovery">("idle");
  const [saving, setSaving] = useState(false);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);

  useEffect(() => {
    fetch("/api/auth/2fa/status", { credentials: "same-origin" })
      .then((r) => r.json())
      .then((s) => setEnabled(s.enabled ?? false))
      .catch(() => setEnabled(false))
      .finally(() => setLoading(false));
  }, []);

  async function startSetup() {
    setError(null);
    setMessage(null);
    try {
      const res = await csrfFetch("/api/auth/2fa/setup", { method: "POST" });
      if (!res.ok) throw new Error(t("setupError"));
      const data = await res.json() as { secret: string; site_name: string };
      setSecret(data.secret);
      setSiteName(data.site_name);
      setStep("setup");
    } catch (err) {
      setError(err instanceof Error ? err.message : t("setupError"));
    }
  }

  async function verifyAndActivate() {
    if (!secret || code.length !== 6) return;
    setSaving(true);
    setError(null);
    setMessage(null);
    try {
      const res = await csrfFetch("/api/auth/2fa/activate", {
        method: "POST",
        body: { secret, code },
      });
      if (!res.ok) {
        const data = (await res.json().catch(() => ({}))) as { error?: string };
        throw new Error(data.error || t("verifyError"));
      }
      const data = (await res.json().catch(() => ({}))) as { recovery_codes?: string[] };
      setEnabled(true);
      setSecret(null);
      setCode("");
      setMessage(t("activated"));
      // Mostrar los códigos de recuperación UNA sola vez.
      if (data.recovery_codes && data.recovery_codes.length > 0) {
        setRecoveryCodes(data.recovery_codes);
        setStep("recovery");
      } else {
        setStep("idle");
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : t("verifyError"));
    } finally {
      setSaving(false);
    }
  }

  async function disable() {
    // Re-verificación obligatoria: pedimos un código TOTP vigente.
    const entered = (prompt(t("disablePrompt")) || "").replace(/\D/g, "").slice(0, 6);
    if (entered.length !== 6) {
      if (entered.length > 0) setError(t("disableCodeRequired"));
      return;
    }
    setSaving(true);
    setError(null);
    setMessage(null);
    try {
      const res = await csrfFetch("/api/auth/2fa/disable", {
        method: "POST",
        body: { code: entered },
      });
      if (!res.ok) {
        const data = (await res.json().catch(() => ({}))) as { error?: string };
        throw new Error(data.error || t("disableError"));
      }
      setEnabled(false);
      setMessage(t("disabled"));
    } catch (err) {
      setError(err instanceof Error ? err.message : t("disableError"));
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <p className="text-sm text-gray-500">{t("loading")}</p>;
  }

  const username =
    typeof window !== "undefined"
      ? document.cookie.replace(/(?:(?:^|.*;\s*)hwe_at\s*=\s*([^;]*).*$)|^.*$/, "$1").slice(0, 20)
      : "";
  const otpauth = secret
    ? `otpauth://totp/${encodeURIComponent(siteName)}:${encodeURIComponent(username || "user")}?secret=${secret}&issuer=${encodeURIComponent(siteName)}`
    : "";

  return (
    <div className="space-y-4">
      {error && (
        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          {error}
        </p>
      )}
      {message && (
        <p className="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300">
          {message}
        </p>
      )}

      {enabled && step === "idle" ? (
        <div>
          <p className="mb-3 text-sm text-green-600 dark:text-green-400">{t("enabled")}</p>
          <button onClick={disable} disabled={saving}
            className="rounded-lg border border-red-300 px-4 py-2 text-sm text-red-600 hover:bg-red-50 disabled:opacity-50 dark:border-red-800 dark:hover:bg-red-950">
            {saving ? t("disabling") : t("disable")}
          </button>
        </div>
      ) : step === "idle" ? (
        <div>
          <p className="mb-3 text-sm text-gray-500">{t("notEnabled")}</p>
          <button onClick={startSetup}
            className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">
            {t("enable")}
          </button>
        </div>
      ) : null}

      {step === "recovery" && recoveryCodes.length > 0 && (
        <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950">
          <p className="mb-1 text-sm font-semibold text-amber-900 dark:text-amber-200">{t("recoveryTitle")}</p>
          <p className="mb-3 text-sm text-amber-800 dark:text-amber-300">{t("recoveryIntro")}</p>
          <ul className="grid grid-cols-2 gap-2 font-mono text-sm">
            {recoveryCodes.map((c) => (
              <li key={c} className="select-all rounded bg-white px-2 py-1 text-center dark:bg-gray-900">{c}</li>
            ))}
          </ul>
          <p className="mt-3 text-xs font-medium text-amber-700 dark:text-amber-400">{t("recoveryWarning")}</p>
          <button
            onClick={() => { setStep("idle"); setRecoveryCodes([]); }}
            className="mt-4 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark"
          >
            {t("recoveryDone")}
          </button>
        </div>
      )}

      {step === "setup" && secret && (
        <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
          <p className="mb-2 text-sm font-medium">{t("scanInstructions")}</p>
          <div className="mb-4 flex justify-center">
            <div className="inline-block rounded-lg border bg-white p-2">
              <img
                src={`https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(otpauth)}`}
                alt={t("qrcode")}
                className="h-48 w-48"
              />
            </div>
          </div>
          <p className="mb-1 text-xs text-gray-400">{t("orManual")}</p>
          <code className="block select-all rounded bg-gray-100 p-2 text-xs dark:bg-gray-800">
            {secret}
          </code>
          <div className="mt-4">
            <label className="mb-1 block text-sm font-medium">{t("code")}</label>
            <div className="flex gap-3">
              <input
                value={code}
                onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
                placeholder="000000"
                maxLength={6}
                className="flex-1 rounded-lg border border-gray-300 px-4 py-2 text-center text-lg tracking-widest dark:border-gray-700 dark:bg-gray-900"
              />
              <button onClick={verifyAndActivate} disabled={saving || code.length !== 6}
                className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark disabled:opacity-50">
                {saving ? t("verifying") : t("verify")}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
