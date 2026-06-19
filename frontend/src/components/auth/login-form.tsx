"use client";
import { useState } from "react";
import { useRouter } from "@/i18n/navigation";
import { Link } from "@/i18n/navigation";
import { useTranslations } from "next-intl";
import { csrfFetch } from "@/lib/client/csrf";

export function LoginForm() {
  const router = useRouter();
  const tLogin = useTranslations("login");
  const t2fa = useTranslations("twoFactor");
  const tForm = useTranslations("form");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const [requires2fa, setRequires2fa] = useState(false);
  const [ephemeralToken, setEphemeralToken] = useState<string | null>(null);
  const [code, setCode] = useState("");

  async function onSubmit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setPending(true);
    setError(null);
    const form = new FormData(e.currentTarget);
    try {
      const res = await csrfFetch("/api/auth/login", {
        method: "POST",
        body: {
          username: String(form.get("username") ?? ""),
          password: String(form.get("password") ?? ""),
        },
      });
      if (!res.ok) {
        const data = (await res.json().catch(() => ({}))) as { error?: string };
        throw new Error(data.error || tLogin("error"));
      }
      const data = (await res.json()) as {
        user?: { id: string };
        requires_2fa?: boolean;
        ephemeralToken?: string;
      };
      if (data.requires_2fa && data.ephemeralToken) {
        setRequires2fa(true);
        setEphemeralToken(data.ephemeralToken);
        return;
      }
      router.push("/account");
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : "Error.");
    } finally {
      setPending(false);
    }
  }

  async function verify2fa() {
    if (!ephemeralToken || code.length !== 6) return;
    setPending(true);
    setError(null);
    try {
      const res = await csrfFetch("/api/auth/verify-2fa", {
        method: "POST",
        body: { ephemeralToken, code },
      });
      if (!res.ok) {
        const data = (await res.json().catch(() => ({}))) as { error?: string };
        throw new Error(data.error || t2fa("verifyError"));
      }
      router.push("/account");
      router.refresh();
    } catch (err) {
      setError(err instanceof Error ? err.message : t2fa("verifyError"));
    } finally {
      setPending(false);
    }
  }

  if (requires2fa) {
    return (
      <div className="space-y-4">
        {error && (
          <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
            {error}
          </p>
        )}
        <p className="text-sm text-gray-500">{t2fa("code")}</p>
        <input
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, "").slice(0, 6))}
          placeholder="000000"
          maxLength={6}
          autoFocus
          className="w-full rounded-lg border border-gray-300 px-4 py-2 text-center text-lg tracking-widest dark:border-gray-700 dark:bg-gray-900"
        />
        <button
          type="button"
          onClick={verify2fa}
          disabled={pending || code.length !== 6}
          className="w-full rounded-lg bg-brand py-2.5 font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50"
        >
          {pending ? t2fa("verifying") : t2fa("verify")}
        </button>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4">
      {error && (
        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300">
          {error}
        </p>
      )}
      <div>
        <label className="mb-1 block text-sm font-medium">
          {tForm("usernameOrEmail")}
        </label>
        <input
          name="username"
          required
          autoComplete="username"
          className="w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900"
        />
      </div>
      <div>
        <label className="mb-1 block text-sm font-medium">{tForm("password")}</label>
        <input
          name="password"
          type="password"
          required
          autoComplete="current-password"
          className="w-full rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900"
        />
      </div>
      <button
        type="submit"
        disabled={pending}
        className="w-full rounded-lg bg-brand py-2.5 font-semibold text-white transition-colors hover:bg-brand-dark disabled:opacity-50"
      >
        {pending ? tLogin("submitting") : tLogin("submit")}
      </button>
      <p className="text-right text-sm">
        <Link href="/forgot-password" className="text-gray-400 hover:text-brand">
          {tLogin("forgotPassword")}
        </Link>
      </p>
      <p className="text-center text-sm text-gray-500">
        {tLogin("noAccount")}{" "}
        <Link href="/register" className="font-medium text-brand hover:underline">
          {tLogin("register")}
        </Link>
      </p>
    </form>
  );
}
