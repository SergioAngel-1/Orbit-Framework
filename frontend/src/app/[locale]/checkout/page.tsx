import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { redirect } from "@/i18n/navigation";
import { loginHref } from "@/lib/navigation/safe-return";
import { CheckoutForm } from "@/components/checkout/checkout-form";
import { getSession } from "@/lib/auth/session";
import { getCheckoutCustomerSeed } from "@/lib/checkout/customer-seed-server";
import { CartCouponOffers } from "@/components/coupons/cart-coupon-offers";
import { isModuleEnabled } from "@/lib/config/modules";
import { getCartCoupons } from "@/lib/coupons/public-coupons";
import { getSiteConfig } from "@/lib/config";
import { getGeoPack } from "@/lib/geo";
import { identityPolicy } from "@/lib/customer/policy";
import { getShippingMethods } from "@/lib/shipping";
import "@/lib/payments/providers"; // efecto secundario: registra las pasarelas
import { enabledPaymentChoices } from "@/lib/payments/checkout-options";
import { isActiveGatewaySandbox } from "@/lib/payments/registry";
import { privateMetadata } from "@/lib/seo/page-metadata";

export const dynamic = "force-dynamic";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: "checkout" });
  return privateMetadata(t("title"));
}

export default async function CheckoutPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const [t, config, session, geoPack] = await Promise.all([
    getTranslations("checkout"),
    getSiteConfig(),
    getSession(),
    getGeoPack(),
  ]);
  // Sin sesión solo se compra si la tienda admite invitados; si no, al login.
  if (!session && config.ecommerce.checkout_login_required) {
    redirect({ href: loginHref("/checkout"), locale });
  }
  const customer = session
    ? await getCheckoutCustomerSeed(session.userId, config.ecommerce.country)
    : undefined;
  const paymentChoices = enabledPaymentChoices(config);
  // Módulo `customer-identity`: apagado, el formulario no pide documento ni términos.
  const identity = identityPolicy(config, geoPack);
  // Módulo `shipping`: apagado, ni extras ni aviso de envío gratis ni destino del carrito.
  const shippingOn = isModuleEnabled(config, "shipping");
  const shippingMethods = await getShippingMethods({ enabled: shippingOn });
  // El aviso de «modo de pruebas» es informativo: si no se puede leer la
  // pasarela, la página sigue sirviéndose sin él.
  const gatewaySandbox = paymentChoices.includes("online")
    ? await isActiveGatewaySandbox().catch(() => false)
    : false;
  // «Cupones disponibles»: del módulo `coupon-rules` y de `coupons_enabled`.
  const offers =
    config.ecommerce.coupons_enabled && isModuleEnabled(config, "coupon-rules")
      ? await getCartCoupons(session?.userId)
      : [];
  return (
    <div className="mx-auto max-w-2xl">
      <h1 className="mb-8 text-3xl font-extrabold tracking-tight">{t("title")}</h1>
      {offers.length > 0 && (
        <div className="mb-6">
          <CartCouponOffers coupons={offers} guest={!session} />
        </div>
      )}
      <CheckoutForm
        guest={!session}
        customer={customer}
        defaultCountry={config.ecommerce.country}
        geoPack={geoPack}
        identity={
          identity.enabled ? { documentTypes: identity.documentTypes } : undefined
        }
        shipping={shippingOn ? { methods: shippingMethods } : undefined}
        paymentChoices={paymentChoices}
        gatewaySandbox={gatewaySandbox}
        couponsEnabled={config.ecommerce.coupons_enabled}
      />
    </div>
  );
}
