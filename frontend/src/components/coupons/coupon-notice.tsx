import { getTranslations } from "next-intl/server";
import { Link } from "@/i18n/navigation";
import { formatMoney } from "@/lib/format";
import {
  couponBenefit,
  couponMessageType,
  formatCouponDiscount,
} from "@/lib/coupons/coupon-scope";
import type { NoticeCoupon } from "@/lib/coupons/public-coupons";
import { CouponGate, GuestOnly } from "./coupon-window";

// ============================================================================
//  Aviso de cupón PÚBLICO (Marketing → Cupones → «Cupón público»).
//
//  Solo AVISA: no toca ningún precio. Dice cuánto descuenta, en qué y con qué
//  código se aplica al finalizar la compra; quien decide si vale es WordPress al
//  aplicarlo. Dos contextos: la ficha de un producto y la página de una
//  categoría de su alcance. Si el cupón exige sesión, invita a iniciarla (solo
//  al invitado). Lo gatea quien lo monta (`coupons_enabled` + módulo).
// ============================================================================

export async function CouponNotices({
  coupons,
  context,
  locale,
  currency,
  decimals,
  className,
}: {
  coupons: NoticeCoupon[];
  context: "product" | "category";
  locale: string;
  currency: string;
  /** Decimales de la tienda (`ecommerce.price_decimals`), o `null` para los de Intl. */
  decimals?: number | null;
  className?: string;
}) {
  if (coupons.length === 0) return null;
  const t = await getTranslations({ locale, namespace: "coupons" });

  return (
    <div className={className ?? "flex flex-col gap-2"}>
      {coupons.map((coupon) => {
        const title = t(`title.${context}`, {
          kind: couponBenefit(coupon),
          type: couponMessageType(coupon),
          discount: formatCouponDiscount(coupon, locale, currency, decimals),
          first: coupon.firstOrderOnly ? "yes" : "no",
        });
        return (
          <CouponGate
            key={coupon.code}
            coupon={{
              code: coupon.code,
              perUserLimited: coupon.perUserLimited,
              startsAt: coupon.startsAt,
              endsAt: coupon.endsAt,
              activeAtRender: coupon.activeAtRender,
            }}
          >
            <aside
              aria-label={t("label")}
              className="flex flex-col gap-1 rounded-xl border border-dashed border-brand/60 bg-brand/5 px-4 py-3 text-sm leading-snug"
            >
              <p className="font-bold">{title}</p>
              <p className="text-gray-600 dark:text-gray-300">
                {t.rich("useCode", {
                  code: (chunks) => (
                    <code className="rounded-md border border-gray-300 bg-white px-1.5 py-0.5 font-mono text-[0.8125rem] font-bold tracking-wide dark:border-gray-700 dark:bg-gray-900">
                      {chunks}
                    </code>
                  ),
                  value: coupon.code.toUpperCase(),
                })}
              </p>
              {coupon.minimumAmount !== null && (
                <p className="text-gray-600 dark:text-gray-300">
                  {t("minSpend", {
                    amount: formatMoney(
                      coupon.minimumAmount,
                      currency,
                      locale,
                      decimals,
                    ),
                  })}
                </p>
              )}
              {coupon.maximumAmount !== null && (
                <p className="text-gray-600 dark:text-gray-300">
                  {t("maxSpend", {
                    amount: formatMoney(
                      coupon.maximumAmount,
                      currency,
                      locale,
                      decimals,
                    ),
                  })}
                </p>
              )}
              {coupon.requiresLogin && (
                <p className="text-gray-600 dark:text-gray-300">
                  {t("accountOnly")}{" "}
                  <GuestOnly>
                    {t.rich("guestHint", {
                      login: (chunks) => (
                        <Link
                          href="/login"
                          className="font-bold text-brand underline underline-offset-2"
                        >
                          {chunks}
                        </Link>
                      ),
                      register: (chunks) => (
                        <Link
                          href="/register"
                          className="font-bold text-brand underline underline-offset-2"
                        >
                          {chunks}
                        </Link>
                      ),
                    })}
                  </GuestOnly>
                </p>
              )}
            </aside>
          </CouponGate>
        );
      })}
    </div>
  );
}
