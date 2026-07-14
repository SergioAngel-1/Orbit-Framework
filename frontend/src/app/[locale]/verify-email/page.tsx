"use client";
import { useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { csrfFetch } from "@/lib/client/csrf";

export default function VerifyEmailPage() {
  const t = useTranslations("verifyEmail");
  const searchParams = useSearchParams();
  const token = searchParams.get("token");
  const [status, setStatus] = useState<"verifying" | "ok" | "error" | "no-token">(
    "verifying",
  );

  useEffect(() => {
    if (!token) {
      setStatus("no-token");
      return;
    }
    csrfFetch("/api/auth/verify-email", {
      method: "POST",
      body: { token },
    })
      .then((res) => {
        setStatus(res.ok ? "ok" : "error");
      })
      .catch(() => setStatus("error"));
  }, [token]);

  return (
    <div className="mx-auto max-w-md text-center">
      {status === "verifying" && <p className="text-gray-500">{t("verifying")}</p>}
      {status === "ok" && (
        <div>
          <h1 className="mb-4 text-2xl font-bold text-green-600">{t("success")}</h1>
          <p className="mb-6 text-gray-600">{t("successMessage")}</p>
          <Link href="/account" className="font-medium text-brand hover:underline">
            {t("goToAccount")}
          </Link>
        </div>
      )}
      {status === "error" && (
        <div>
          <h1 className="mb-4 text-2xl font-bold text-red-600">{t("error")}</h1>
          <p className="mb-6 text-gray-600">{t("errorMessage")}</p>
          <Link href="/account" className="font-medium text-brand hover:underline">
            {t("goToAccount")}
          </Link>
        </div>
      )}
      {status === "no-token" && (
        <div>
          <h1 className="mb-4 text-2xl font-bold">{t("noToken")}</h1>
          <p className="text-gray-600">{t("noTokenMessage")}</p>
        </div>
      )}
    </div>
  );
}
