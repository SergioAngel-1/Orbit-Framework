"use client";
import { useState } from "react";
import { useTranslations } from "next-intl";
import { csrfFetch } from "@/lib/client/csrf";

export function EmailVerificationBanner() {
  const t = useTranslations("verifyEmail");
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);

  async function resend() {
    setSending(true);
    try {
      await csrfFetch("/api/auth/resend-verification", { method: "POST" });
      setSent(true);
    } catch {
      /* ignore */
    } finally {
      setSending(false);
    }
  }

  return (
    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
      <p className="font-medium">{t("bannerTitle")}</p>
      <p className="mt-1">{t("bannerMessage")}</p>
      {sent ? (
        <p className="mt-2 text-green-600 dark:text-green-400">{t("resent")}</p>
      ) : (
        <button
          onClick={resend}
          disabled={sending}
          className="mt-2 font-medium text-brand underline hover:no-underline disabled:opacity-50"
        >
          {sending ? t("sending") : t("resend")}
        </button>
      )}
    </div>
  );
}
