"use client";
import { HUBSPOT_DO_NOT_COLLECT } from "@/lib/analytics/hubspot";
import { useState } from "react";
import { useTranslations } from "next-intl";
import { csrfFetch } from "@/lib/client/csrf";
import { PasswordField } from "@/components/auth/password-field";

export function ChangePasswordForm() {
  const t = useTranslations("changePassword");
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setPending(true);
    setError(null);
    setMessage(null);
    const form = new FormData(e.currentTarget);
    const currentPassword = String(form.get("current_password") ?? "");
    const newPassword = String(form.get("new_password") ?? "");
    const confirm = String(form.get("confirm_password") ?? "");

    if (newPassword !== confirm) {
      setError(t("mismatch"));
      setPending(false);
      return;
    }

    try {
      const res = await csrfFetch("/api/auth/change-password", {
        method: "POST",
        body: { currentPassword, newPassword },
      });
      if (!res.ok) {
        const data = (await res.json().catch(() => ({}))) as { error?: string };
        throw new Error(data.error || t("error"));
      }
      // El servidor informa de qué pasó con las demás sesiones: no se da por cerradas
      // unas sesiones que siguen abiertas (`=== false`: una respuesta antigua sin esos
      // campos se trata como correcta).
      const result = (await res.json().catch(() => ({}))) as {
        otherSessionsRevoked?: boolean;
        sessionKept?: boolean;
      };
      setMessage(
        result.otherSessionsRevoked === false
          ? t("successSessionsPending")
          : result.sessionKept === false
            ? t("successReloginNeeded")
            : t("success"),
      );
      (e.target as HTMLFormElement).reset();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error.");
    } finally {
      setPending(false);
    }
  }

  return (
    <form
      {...HUBSPOT_DO_NOT_COLLECT}
      onSubmit={onSubmit}
      className="max-w-md space-y-4"
    >
      {message && (
        <p className="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-700 dark:border-green-900 dark:bg-green-950 dark:text-green-300">
          {message}
        </p>
      )}
      {error && (
        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          {error}
        </p>
      )}
      <div>
        <label htmlFor="current_password" className="mb-1 block text-sm font-medium">
          {t("currentPassword")}
        </label>
        <input
          id="current_password"
          name="current_password"
          type="password"
          required
          autoComplete="current-password"
          className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
        />
      </div>
      <PasswordField
        passwordName="new_password"
        confirmName="confirm_password"
        passwordLabel={t("newPassword")}
        confirmLabel={t("confirmPassword")}
      />
      <button
        type="submit"
        disabled={pending}
        className="rounded-lg bg-brand px-5 py-2 font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50 dark:bg-accent dark:text-gray-950 dark:hover:bg-accent/85"
      >
        {pending ? t("submitting") : t("submit")}
      </button>
    </form>
  );
}
