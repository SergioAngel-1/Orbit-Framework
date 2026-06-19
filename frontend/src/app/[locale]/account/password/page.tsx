import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import { ChangePasswordForm } from "@/components/account/change-password-form";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("changePassword");
  return { title: t("title") };
}

export const dynamic = "force-dynamic";

export default async function PasswordPage() {
  const t = await getTranslations("changePassword");
  return (
    <div>
      <h2 className="mb-6 text-xl font-bold">{t("title")}</h2>
      <ChangePasswordForm />
    </div>
  );
}
