"use client";
import { useTranslations } from "next-intl";
import { useCart } from "./cart-context";

// Abre el CartDrawer al hacer click (en lugar de navegar a /cart directamente).
// El enlace sigue existiendo como fallback accesible vía aria-label.
export function CartIndicator() {
  const { count, toggleDrawer } = useCart();
  const t = useTranslations("nav");

  return (
    <button
      type="button"
      onClick={toggleDrawer}
      aria-label={`${t("cart")}${count > 0 ? ` (${count} artículo${count !== 1 ? "s" : ""})` : ""}`}
      className="relative inline-flex items-center gap-1 text-sm font-medium text-gray-600 transition-colors hover:text-brand dark:text-gray-300"
    >
      <svg
        xmlns="http://www.w3.org/2000/svg"
        width="20"
        height="20"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden
      >
        <circle cx="8" cy="21" r="1" />
        <circle cx="19" cy="21" r="1" />
        <path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12" />
      </svg>
      {count > 0 && (
        <span
          aria-hidden
          className="absolute -right-1.5 -top-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-brand px-1 text-xs font-bold text-white"
        >
          {count > 99 ? "99+" : count}
        </span>
      )}
    </button>
  );
}
