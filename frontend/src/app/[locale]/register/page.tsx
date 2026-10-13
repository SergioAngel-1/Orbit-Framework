import type { Metadata } from "next";
import { redirect } from "@/i18n/navigation";
import { getTranslations } from "next-intl/server";
import { getSession } from "@/lib/auth/session";
import { RegisterForm } from "@/components/auth/register-form";
import { getSiteConfig } from "@/lib/config";
import { getGeoPack } from "@/lib/geo";
import { identityPolicy } from "@/lib/customer/policy";
import { privateMetadata } from "@/lib/seo/page-metadata";
import { safeInternalPath } from "@/lib/navigation/safe-return";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("register");
  return privateMetadata(t("title"));
}

export const dynamic = "force-dynamic";

export default async function RegisterPage({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{ return?: string | string[] }>;
}) {
  const { locale } = await params;
  const { return: rawReturn } = await searchParams;
  const returnTo = safeInternalPath(
    Array.isArray(rawReturn) ? rawReturn[0] : rawReturn,
  );
  const t = await getTranslations("register");
  const [session, config, geoPack] = await Promise.all([
    getSession(),
    getSiteConfig(),
    getGeoPack(),
  ]);
  if (session) redirect({ href: returnTo, locale });
  // Módulo `customer-identity`: apagado, el registro no pide documento ni términos.
  const identity = identityPolicy(config, geoPack);

  return (
    <div className="mx-auto max-w-sm">
      <h1 className="mb-8 text-2xl font-bold">{t("title")}</h1>
      <RegisterForm
        phoneEnabled={config.auth.phone_login_enabled}
        defaultDialCode={config.auth.phone_default_dial_code}
        returnTo={returnTo}
        identity={
          identity.enabled ? { documentTypes: identity.documentTypes } : undefined
        }
      />
    </div>
  );
}
