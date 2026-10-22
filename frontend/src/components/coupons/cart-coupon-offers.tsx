"use client";
import { useLocale, useTranslations } from "next-intl";
import { Link } from "@/i18n/navigation";
import { useCart } from "@/components/cart/cart-context";
import {
  couponAppliesToCart,
  couponBenefit,
  couponMessageType,
  formatCouponDiscount,
  type NoticeCoupon,
} from "@/lib/coupons/coupon-scope";

/**
 * «Cupones disponibles» del carrito y el checkout: los públicos vigentes que el
 * servidor ya filtró por cliente (`getCartCoupons`) y que aplican a algo del
 * carrito (`couponAppliesToCart`, con el gasto mínimo y máximo). «Usar» lo aplica
 * con el mismo `applyCoupon` del campo de cupón; quien decide si vale es
 * WordPress. Los que exigen sesión mandan al invitado a iniciarla.
 */
export function CartCouponOffers({
  coupons,
  guest,
}: {
  coupons: NoticeCoupon[];
  guest: boolean;
}) {
  const t = useTranslations("cart.offers");
  const locale = useLocale();
  const { cart, loading, applyCoupon } = useCart();

  if (!cart || coupons.length === 0) return null;

  const unit = 10 ** cart.totals.currency_minor_unit;
  const subtotal = {
    exclTax: Number(cart.totals.total_items) / unit,
    inclTax:
      (Number(cart.totals.total_items) + Number(cart.totals.total_items_tax)) / unit,
  };
  const applied = new Set(cart.coupons.map((c) => c.code.toLowerCase()));
  const offers = coupons.filter(
    (c) =>
      !applied.has(c.code.toLowerCase()) &&
      couponAppliesToCart(c, cart.items, subtotal),
  );
  if (offers.length === 0) return null;

  return (
    <section
      aria-labelledby="cart-offers-heading"
      className="rounded-xl border border-dashed border-brand/60 bg-brand/5 p-4 dark:border-accent/60 dark:bg-accent/5"
    >
      <h3 id="cart-offers-heading" className="mb-2 text-sm font-semibold">
        {t("heading")}
      </h3>
      <ul className="space-y-2">
        {offers.map((coupon) => {
          const title = t("title", {
            kind: couponBenefit(coupon),
            type: couponMessageType(coupon),
            discount: formatCouponDiscount(
              coupon,
              locale,
              cart.totals.currency_code,
              cart.totals.currency_minor_unit,
            ),
            first: coupon.firstOrderOnly ? "yes" : "no",
          });
          const needsLogin = coupon.requiresLogin && guest;
          return (
            <li
              key={coupon.code}
              className="flex items-center justify-between gap-3 text-sm"
            >
              <span>
                {title} ·{" "}
                <code className="font-mono font-bold">{coupon.code.toUpperCase()}</code>
                {coupon.requiresLogin && (
                  <span className="block text-xs text-gray-500">
                    {t("accountOnly")}
                  </span>
                )}
              </span>
              {needsLogin ? (
                <Link
                  href="/login"
                  aria-label={t("loginLabel", { code: coupon.code.toUpperCase() })}
                  className="shrink-0 rounded-lg border border-brand dark:border-accent px-3 py-1.5 text-xs font-semibold text-brand dark:text-accent hover:bg-brand/10 dark:hover:bg-accent/10"
                >
                  {t("login")}
                </Link>
              ) : (
                <button
                  type="button"
                  disabled={loading}
                  aria-label={t("useLabel", { code: coupon.code.toUpperCase() })}
                  onClick={() => void applyCoupon(coupon.code)}
                  className="shrink-0 rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-dark disabled:opacity-50 dark:bg-accent dark:text-gray-950 dark:hover:bg-accent/85"
                >
                  {t("use")}
                </button>
              )}
            </li>
          );
        })}
      </ul>
    </section>
  );
}
