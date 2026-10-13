import type { Metadata } from "next";
import { redirect } from "@/i18n/navigation";
import { getTranslations } from "next-intl/server";
import { getSession } from "@/lib/auth/session";
import { LoginForm } from "@/components/auth/login-form";
import { getSiteConfig } from "@/lib/config";
import { privateMetadata } from "@/lib/seo/page-metadata";
import { safeInternalPath } from "@/lib/navigation/safe-return";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("login");
  return privateMetadata(t("title"));
}

export const dynamic = "force-dynamic";

export default async function LoginPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ return?: string | string[] }>;
}) {
  const { locale } = await params;
  const { return: rawReturn } = await searchParams;
  // `?return=` solo admite una ruta interna (anti open-redirect).
  const returnTo = safeInternalPath(
    Array.isArray(rawReturn) ? rawReturn[0] : rawReturn,
  );
  const t = await getTranslations("login");
  const [session, config] = await Promise.all([getSession(), getSiteConfig()]);
  if (session) redirect({ href: returnTo, locale });

  return (
    <div className="mx-auto max-w-sm">
      <h1 className="mb-8 text-2xl font-bold">{t("title")}</h1>
      <LoginForm phoneEnabled={config.auth.phone_login_enabled} returnTo={returnTo} />
    </div>
  );
}
