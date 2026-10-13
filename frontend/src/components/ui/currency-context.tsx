"use client";
import { createContext, useContext, type ReactNode } from "react";

// ============================================================================
//  Moneda de la tienda (HWE Control Center → Tienda → Moneda ISO-4217),
//  disponible en cliente sin que cada componente haga su propio getSiteConfig().
//  El valor lo fija el Server Component raíz (`[locale]/layout.tsx`) a partir
//  de `config.ecommerce.currency`.
// ============================================================================

const CurrencyContext = createContext<string | null>(null);

export function useCurrency(): string {
  const currency = useContext(CurrencyContext);
  if (!currency) {
    throw new Error("useCurrency debe usarse dentro de <CurrencyProvider>.");
  }
  return currency;
}

export function CurrencyProvider({
  currency,
  children,
}: {
  currency: string;
  children: ReactNode;
}) {
  return (
    <CurrencyContext.Provider value={currency}>{children}</CurrencyContext.Provider>
  );
}
