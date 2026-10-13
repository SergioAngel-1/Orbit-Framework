"use client";
import { useState } from "react";
import { useRouter } from "@/i18n/navigation";
import { Link } from "@/i18n/navigation";
import { useLocale, useTranslations } from "next-intl";
import { csrfFetch } from "@/lib/client/csrf";
import { normalizePhoneE164 } from "@/lib/auth/phone";
import { PasswordField } from "./password-field";

interface RegisterFormProps {
  /** `auth.phone_login_enabled`: pide el teléfono (opcional) para poder entrar con él. */
  phoneEnabled?: boolean;
  /** `auth.phone_default_dial_code`: prefijo inicial del teléfono. */
  defaultDialCode?: string;
}

export function RegisterForm({
  phoneEnabled = false,
  defaultDialCode = "",
}: RegisterFormProps) {
  const router = useRouter();
  const tRegister = useTranslations("register");
  const locale = useLocale();
  const tForm = useTranslations("form");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(e.currentTarget);
    const password = String(form.get("password") ?? "");
    if (password !== String(form.get("confirm") ?? "")) {
      setError(tForm("passwordsDontMatch"));
      setPending(false);
      return;
    }
    const national = String(form.get("phone") ?? "").trim();
    const phone =
      phoneEnabled && national
        ? normalizePhoneE164(String(form.get("dial_code") ?? ""), national)
        : "";
    if (phone === null) {
      setError(tForm("phoneInvalid"));
      setPending(false);
      return;
    }
    try {
      const res = await csrfFetch("/api/auth/register", {
        method: "POST",
        body: {
          username: String(form.get("username") ?? ""),
          email: String(form.get("email") ?? ""),
          password,
          ...(phone ? { phone } : {}),
          locale,
        },
      });
      if (!res.ok) {
        const data = (await res.json().catch(() => ({}))) as { error?: string };
        throw new Error(data.error || tRegister("error"));
      }
      router.push("/account");
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error.");
    } finally {
      setPending(false);
    }
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4">
      {error && (
        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          {error}
        </p>
      )}
      <div>
        <label htmlFor="username" className="mb-1 block text-sm font-medium">
          {tForm("username")}
        </label>
        <input
          id="username"
          name="username"
          required
          minLength={3}
          autoComplete="username"
          className="w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900"
        />
      </div>
      <div>
        <label htmlFor="email" className="mb-1 block text-sm font-medium">
          {tForm("email")}
        </label>
        <input
          id="email"
          name="email"
          type="email"
          required
          autoComplete="email"
          className="w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900"
        />
      </div>
      {phoneEnabled && (
        <fieldset>
          <legend className="mb-1 block text-sm font-medium">
            {tForm("phoneOptional")}
          </legend>
          <div className="flex gap-2">
            <label htmlFor="dial_code" className="sr-only">
              {tForm("dialCode")}
            </label>
            <input
              id="dial_code"
              name="dial_code"
              defaultValue={defaultDialCode}
              placeholder="+00"
              inputMode="tel"
              autoComplete="tel-country-code"
              maxLength={5}
              className="w-20 rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900"
            />
            <label htmlFor="phone" className="sr-only">
              {tForm("phone")}
            </label>
            <input
              id="phone"
              name="phone"
              type="tel"
              inputMode="tel"
              autoComplete="tel-national"
              maxLength={20}
              aria-describedby="phone-help"
              className="w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900"
            />
          </div>
          <p id="phone-help" className="mt-1 text-xs text-gray-500">
            {tForm("phoneHelper")}
          </p>
        </fieldset>
      )}
      <PasswordField />
      <button
        type="submit"
        disabled={pending}
        className="w-full rounded-lg bg-brand py-2.5 font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50"
      >
        {pending ? tRegister("submitting") : tRegister("submit")}
      </button>
      <p className="text-center text-sm text-gray-500">
        {tRegister("hasAccount")}{" "}
        <Link href="/login" className="font-medium text-brand hover:underline">
          {tRegister("login")}
        </Link>
      </p>
      <p className="text-center text-xs text-gray-500">
        {tRegister("verificationNotice")}
      </p>
    </form>
  );
}
