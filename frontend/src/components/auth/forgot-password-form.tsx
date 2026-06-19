"use client";
import { useState } from "react";
import { useTranslations } from "next-intl";
import { csrfFetch } from "@/lib/client/csrf";

interface Props {
  email?: string;
}

export function ForgotPasswordForm({ email: initialEmail }: Props) {
  const t = useTranslations("forgotPassword");
  const tForm = useTranslations("form");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [sent, setSent] = useState(false);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(e.currentTarget);
    try {
      const res = await csrfFetch("/api/auth/forgot-password", {
        method: "POST",
        body: { email: String(form.get("email") ?? "") },
      });
      if (!res.ok) {
        const data = (await res.json().catch(() => ({}))) as { error?: string };
        throw new Error(data.error || t("error"));
      }
      setSent(true);
    } catch (err) {
      setError(err instanceof Error ? err.message : t("error"));
    } finally {
      setPending(false);
    }
  }

  if (sent) {
    return (
      <div className="rounded-xl border border-green-200 bg-green-50 p-6 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
        <p className="font-semibold">{t("sentTitle")}</p>
        <p className="mt-2 text-sm">{t("sentMessage")}</p>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4">
      <p className="text-sm text-gray-500 dark:text-gray-400">{t("instructions")}</p>
      {error && (
        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          {error}
        </p>
      )}
      <div>
        <label className="mb-1 block text-sm font-medium">{tForm("email")}</label>
        <input
          name="email"
          type="email"
          required
          autoComplete="email"
          defaultValue={initialEmail}
          className="w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900"
        />
      </div>
      <button
        type="submit"
        disabled={pending}
        className="w-full rounded-lg bg-brand py-2.5 font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50"
      >
        {pending ? t("submitting") : t("submit")}
      </button>
    </form>
  );
}
