"use client";
import { createContext, useCallback, useContext, type ReactNode } from "react";
import { useLocale } from "next-intl";
import { formatPrice, formatStoreAmount } from "@/lib/format";

// ============================================================================
//  Moneda de la tienda (HWE Control Center → Tienda → Moneda ISO-4217) y sus
//  decimales (los de WooCommerce), disponibles en cliente sin que cada
//  componente haga su propio getSiteConfig(). Los fija el Server Component raíz
//  (`[locale]/layout.tsx`) a partir de `config.ecommerce`. El idioma sale de la
//  página (`useLocale`): el mismo importe se lee distinto en /es y en /en.
// ============================================================================

interface CurrencySettings {
  currency: string;
  decimals: number | null;
}

const CurrencyContext = createContext<CurrencySettings | null>(null);

function useCurrencySettings(): CurrencySettings {
  const settings = useContext(CurrencyContext);
  if (!settings) {
    throw new Error("useFormatPrice debe usarse dentro de <CurrencyProvider>.");
  }
  return settings;
}

/** Formatea un precio de catálogo con la moneda, los decimales y el idioma actuales. */
export function useFormatPrice(): (
  price: string | number | null | undefined,
) => string {
  const { currency, decimals } = useCurrencySettings();
  const locale = useLocale();
  return useCallback(
    (price) => formatPrice(price, currency, locale, decimals),
    [currency, decimals, locale],
  );
}

/** Formatea un importe de la Store API (unidades menores) en el idioma actual. */
export function useFormatStoreAmount(): (
  amount: string | null | undefined,
  minorUnit: number,
  currencyCode: string,
) => string {
  const locale = useLocale();
  return useCallback(
    (amount, minorUnit, currencyCode) =>
      formatStoreAmount(amount, minorUnit, currencyCode, locale),
    [locale],
  );
}

export function CurrencyProvider({
  currency,
  decimals = null,
  children,
}: {
  currency: string;
  decimals?: number | null;
  children: ReactNode;
}) {
  return (
    <CurrencyContext.Provider value={{ currency, decimals }}>
      {children}
    </CurrencyContext.Provider>
  );
}
