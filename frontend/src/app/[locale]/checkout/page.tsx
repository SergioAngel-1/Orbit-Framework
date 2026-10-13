import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { redirect } from "@/i18n/navigation";
import { CheckoutForm } from "@/components/checkout/checkout-form";
import { getSession } from "@/lib/auth/session";
import { getCheckoutCustomerSeed } from "@/lib/checkout/customer-seed-server";
import { getSiteConfig } from "@/lib/config";
import "@/lib/payments/providers"; // efecto secundario: registra las pasarelas
import { enabledPaymentChoices } from "@/lib/payments/checkout-options";
import { isActiveGatewaySandbox } from "@/lib/payments/registry";

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
  const [t, config, session] = await Promise.all([
    getTranslations("checkout"),
    getSiteConfig(),
    getSession(),
  ]);
  // Sin sesión solo se compra si la tienda admite invitados; si no, al login.
  if (!session && config.ecommerce.checkout_login_required) {
    redirect({ href: "/login", locale });
  }
  const customer = session
    ? await getCheckoutCustomerSeed(session.userId, config.ecommerce.country)
    : undefined;
  const paymentChoices = enabledPaymentChoices(config);
  // El aviso de «modo de pruebas» es informativo: si no se puede leer la
  // pasarela, la página sigue sirviéndose sin él.
  const gatewaySandbox = paymentChoices.includes("online")
    ? await isActiveGatewaySandbox().catch(() => false)
    : false;
  return (
    <div className="mx-auto max-w-2xl">
      <h1 className="mb-8 text-3xl font-extrabold tracking-tight">{t("title")}</h1>
      <CheckoutForm
        guest={!session}
        customer={customer}
        defaultCountry={config.ecommerce.country}
        paymentChoices={paymentChoices}
        gatewaySandbox={gatewaySandbox}
        couponsEnabled={config.ecommerce.coupons_enabled}
      />
    </div>
  );
}
