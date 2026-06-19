import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import { ForgotPasswordForm } from "@/components/auth/forgot-password-form";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("forgotPassword");
  return { title: t("title") };
}

export const dynamic = "force-dynamic";

export default function ForgotPasswordPage() {
  return (
    <div className="mx-auto max-w-sm">
      <h1 className="mb-8 text-2xl font-bold">Recuperar contraseña</h1>
      <ForgotPasswordForm />
    </div>
  );
}
