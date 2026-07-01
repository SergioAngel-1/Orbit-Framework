import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { CheckoutForm } from "@/components/checkout/checkout-form";
import { getSiteConfig } from "@/lib/config";

export const dynamic = "force-dynamic";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: "checkout" });
  return { title: t("title") };
}

export default async function CheckoutPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const [t, config] = await Promise.all([getTranslations("checkout"), getSiteConfig()]);
  return (
    <div className="mx-auto max-w-2xl">
      <h1 className="mb-8 text-3xl font-extrabold tracking-tight">{t("title")}</h1>
      <CheckoutForm couponsEnabled={config.ecommerce.coupons_enabled} />
    </div>
  );
}
