import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import { notFound } from "next/navigation";
import { ResetPasswordForm } from "@/components/auth/reset-password-form";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("resetPassword");
  return { title: t("title") };
}

export const dynamic = "force-dynamic";

export default async function ResetPasswordPage(props: {
  searchParams: Promise<{ key?: string; login?: string }>;
}) {
  const searchParams = await props.searchParams;
  const t = await getTranslations("resetPassword");

  if (!searchParams.key || !searchParams.login) {
    notFound();
  }

  return (
    <div className="mx-auto max-w-sm">
      <h1 className="mb-8 text-2xl font-bold">{t("title")}</h1>
      <ResetPasswordForm key={searchParams.key} login={searchParams.login} />
    </div>
  );
}
