"use client";
// Sin setRequestLocale/force-dynamic: la página es 100% de cliente (la
// verificación ocurre en un efecto vía csrfFetch); no hay datos por usuario en
// el render inicial.
import { Suspense, useEffect, useState, type ReactNode } from "react";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { Spinner } from "@/components/ui/spinner";
import { csrfFetch } from "@/lib/client/csrf";
import { useScrubQueryParams } from "@/hooks/use-scrub-query-params";

type Status = "verifying" | "ok" | "error" | "no-token";
type Tone = "success" | "danger" | "neutral";

const PRIMARY =
  "inline-flex items-center justify-center rounded-lg bg-brand px-5 py-2.5 font-semibold text-white hover:bg-brand-dark focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2";
const SECONDARY =
  "inline-flex items-center justify-center rounded-lg border border-gray-300 px-5 py-2.5 font-semibold hover:border-brand hover:text-brand focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 dark:border-gray-700";

const TONE_DISC: Record<Tone, string> = {
  success: "bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300",
  danger: "bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300",
  neutral: "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200",
};

function StatusIcon({ tone }: { tone: Tone }) {
  return (
    <svg
      width="30"
      height="30"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      {tone === "success" ? (
        <path d="m5 12.5 4.5 4.5L19 7.5" />
      ) : tone === "danger" ? (
        <path d="M12 7.5v6M12 17h.01" />
      ) : (
        <>
          <rect x="3" y="5" width="18" height="14" rx="2.5" />
          <path d="m4 7.5 8 6 8-6" />
        </>
      )}
    </svg>
  );
}

/** Tarjeta centrada con disco de estado, texto y acciones. */
function StatusCard({
  tone,
  title,
  message,
  actions,
}: {
  tone: Tone;
  title: string;
  message: string;
  actions: ReactNode;
}) {
  return (
    <section className="mx-auto flex min-h-[50vh] max-w-md items-center justify-center">
      <div className="w-full rounded-2xl border border-gray-200 bg-surface px-6 py-10 text-center dark:border-gray-800 dark:bg-gray-900">
        <span
          className={`mx-auto flex h-16 w-16 items-center justify-center rounded-full ${TONE_DISC[tone]}`}
        >
          <StatusIcon tone={tone} />
        </span>
        <h1 className="mt-6 text-2xl font-bold">{title}</h1>
        <p className="mx-auto mt-3 max-w-[38ch] text-gray-600 dark:text-gray-300">
          {message}
        </p>
        <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
          {actions}
        </div>
      </div>
    </section>
  );
}

function VerifyingCard({ label }: { label: string }) {
  return (
    <section className="mx-auto flex min-h-[50vh] max-w-md items-center justify-center">
      <div className="flex flex-col items-center gap-4">
        <Spinner size="lg" label={label} />
        <p className="text-gray-600 dark:text-gray-300">{label}</p>
      </div>
    </section>
  );
}

function VerifyEmailContent() {
  const t = useTranslations("verifyEmail");
  const searchParams = useSearchParams();
  // En estado y no leído en cada render: `useScrubQueryParams` lo quita de la
  // URL (no debe llegar a las analíticas) y con él `useSearchParams` dejaría de
  // devolverlo a media verificación.
  const [token] = useState(() => searchParams.get("token"));
  useScrubQueryParams(["token"]);
  const [status, setStatus] = useState<Status>(token ? "verifying" : "no-token");

  useEffect(() => {
    if (!token) return;
    let cancelled = false;
    csrfFetch("/api/auth/verify-email", { method: "POST", body: { token } })
      .then((res) => {
        if (!cancelled) setStatus(res.ok ? "ok" : "error");
      })
      .catch(() => {
        if (!cancelled) setStatus("error");
      });
    return () => {
      cancelled = true;
    };
  }, [token]);

  if (status === "verifying") return <VerifyingCard label={t("verifying")} />;

  if (status === "ok") {
    return (
      <StatusCard
        tone="success"
        title={t("success")}
        message={t("successMessage")}
        actions={
          <>
            <Link href="/products" className={PRIMARY}>
              {t("startShopping")}
            </Link>
            <Link href="/account" className={SECONDARY}>
              {t("goToAccount")}
            </Link>
          </>
        }
      />
    );
  }

  if (status === "error") {
    return (
      <StatusCard
        tone="danger"
        title={t("error")}
        message={t("errorMessage")}
        actions={
          <Link href="/login" className={PRIMARY}>
            {t("goToLogin")}
          </Link>
        }
      />
    );
  }

  return (
    <StatusCard
      tone="neutral"
      title={t("noToken")}
      message={t("noTokenMessage")}
      actions={
        <Link href="/products" className={SECONDARY}>
          {t("goToStore")}
        </Link>
      }
    />
  );
}

function VerifyingFallback() {
  const t = useTranslations("verifyEmail");
  return <VerifyingCard label={t("verifying")} />;
}

// useSearchParams exige un límite de Suspense o toda la ruta se degrada a CSR.
export default function VerifyEmailPage() {
  return (
    <Suspense fallback={<VerifyingFallback />}>
      <VerifyEmailContent />
    </Suspense>
  );
}
