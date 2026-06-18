import type { Metadata } from "next";
import { redirect } from "@/i18n/navigation";
import { getTranslations } from "next-intl/server";
import { getSession } from "@/lib/auth/session";
import { RegisterForm } from "@/components/auth/register-form";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("register");
  return { title: t("title") };
}

export const dynamic = "force-dynamic";

export default async function RegisterPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  const t = await getTranslations("register");
  const session = await getSession();
  if (session) redirect({ href: "/account", locale });

  return (
    <div className="mx-auto max-w-sm">
      <h1 className="mb-8 text-2xl font-bold">{t("title")}</h1>
      <RegisterForm />
    </div>
  );
}
