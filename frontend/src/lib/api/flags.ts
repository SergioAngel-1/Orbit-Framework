import "server-only";
import { NextResponse } from "next/server";
import type { SiteConfig } from "@/lib/config/types";

/** Los interruptores de funcionalidad de la tienda (`config.ecommerce.*_enabled`). */
export type EcommerceFlag =
  "reviews_enabled" | "wishlist_enabled" | "coupons_enabled" | "search_enabled";

/**
 * Guarda de flag para rutas BFF: con la funcionalidad apagada en el Control
 * Center, la ruta no existe (404), igual que con un módulo apagado. Los flags se
 * respetan en el BFF y no solo en la UI: una petición directa no debe saltarse
 * lo que el administrador apagó.
 *
 *   const blocked = requireFlag(await getSiteConfig(), "wishlist_enabled");
 *   if (blocked) return blocked;
 */
export function requireFlag(
  config: Pick<SiteConfig, "ecommerce">,
  flag: EcommerceFlag,
): NextResponse | null {
  if (config.ecommerce[flag]) return null;
  return NextResponse.json({ error: "No encontrado." }, { status: 404 });
}
