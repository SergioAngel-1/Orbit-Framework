import type { Metadata } from "next";
import { getTranslations, setRequestLocale } from "next-intl/server";
import { CartView } from "@/components/cart/cart-view";
import { CartCouponOffers } from "@/components/coupons/cart-coupon-offers";
import { getSession } from "@/lib/auth/session";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";
import { getCartCoupons } from "@/lib/coupons/public-coupons";

export const dynamic = "force-dynamic";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ locale: string }>;
}): Promise<Metadata> {
  const { locale } = await params;
  const t = await getTranslations({ locale, namespace: "cart" });
  return { title: t("title") };
}

export default async function CartPage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;
  setRequestLocale(locale);
  const [t, config, session] = await Promise.all([
    getTranslations("cart"),
    getSiteConfig(),
    getSession(),
  ]);
  // «Cupones disponibles»: del módulo `coupon-rules` y de `coupons_enabled`.
  const offers =
    config.ecommerce.coupons_enabled && isModuleEnabled(config, "coupon-rules")
      ? await getCartCoupons(session?.userId)
      : [];
  return (
    <div className="mx-auto max-w-2xl">
      <h1 className="mb-8 text-3xl font-extrabold tracking-tight">{t("title")}</h1>
      <CartView />
      {offers.length > 0 && (
        <div className="mt-6">
          <CartCouponOffers coupons={offers} guest={!session} />
        </div>
      )}
    </div>
  );
}
