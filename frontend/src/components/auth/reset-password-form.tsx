"use client";
import { HUBSPOT_DO_NOT_COLLECT } from "@/lib/analytics/hubspot";
import { useState } from "react";
import { useRouter } from "@/i18n/navigation";
import { useTranslations } from "next-intl";
import { csrfFetch } from "@/lib/client/csrf";
import { PasswordField } from "./password-field";

interface Props {
  /**
   * Clave del enlace del correo. NO se puede llamar `key`: React se reserva esa
   * prop y no la pasa al componente, así que el formulario enviaba `undefined` y
   * restablecer la contraseña fallaba siempre.
   */
  resetKey: string;
  login: string;
}

export function ResetPasswordForm({ resetKey, login }: Props) {
  const router = useRouter();
  const t = useTranslations("resetPassword");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(e.currentTarget);
    const password = String(form.get("password") ?? "");
    const confirm = String(form.get("confirm") ?? "");

    if (password !== confirm) {
      setError(t("mismatch"));
      setPending(false);
      return;
    }

    try {
      const res = await csrfFetch("/api/auth/reset-password", {
        method: "POST",
        body: { key: resetKey, login, password },
      });
      if (!res.ok) {
        const data = (await res.json().catch(() => ({}))) as { error?: string };
        throw new Error(data.error || t("error"));
      }
      router.push("/login");
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : t("error"));
    } finally {
      setPending(false);
    }
  }

  return (
    <form {...HUBSPOT_DO_NOT_COLLECT} onSubmit={onSubmit} className="space-y-4">
      {error && (
        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          {error}
        </p>
      )}
      <PasswordField confirmLabel={t("confirmPassword")} />
      <button
        type="submit"
        disabled={pending}
        className="w-full rounded-lg bg-brand py-2.5 font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50 dark:bg-accent dark:text-gray-950 dark:hover:bg-accent/85"
      >
        {pending ? t("submitting") : t("submit")}
      </button>
    </form>
  );
}
