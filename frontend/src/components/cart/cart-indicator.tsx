"use client";
import { Link } from "@/i18n/navigation";
import { useTranslations } from "next-intl";
import { useCart } from "./cart-context";

export function CartIndicator() {
  const { count } = useCart();
  const t = useTranslations("nav");
  return (
    <Link
      href="/cart"
      className="relative inline-flex items-center gap-1 text-sm font-medium text-gray-600 transition-colors hover:text-brand dark:text-gray-300"
    >
      {t("cart")}
      {count > 0 && (
        <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand px-1.5 text-xs font-bold text-white">
          {count}
        </span>
      )}
    </Link>
  );
}
