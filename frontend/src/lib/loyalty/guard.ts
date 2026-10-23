import "server-only";
import { NextResponse } from "next/server";
import { isModuleEnabled } from "@/lib/config/modules";
import type { SiteConfig } from "@/lib/config/types";

/** Funciones del módulo que tienen su propia guarda. */
export type LoyaltyFeature = "points" | "referrals" | "transfers";

function notFound(): NextResponse {
  // Mismo cuerpo que `requireModule` / `requireFlag`: el navegador no distingue
  // una función que no existe de una apagada por el administrador.
  return NextResponse.json({ error: "No encontrado." }, { status: 404 });
}

/**
 * Guarda de una función del módulo `loyalty` para las rutas BFF.
 *
 *  - Sin el módulo activo, 404.
 *  - Puntos = `points_enabled`.
 *  - Referidos = puntos + `referrals_enabled` (sin monedero no se pagan comisiones).
 *  - Transferencias = puntos + `transfers_enabled`.
 *
 * Devuelve `null` si la ruta puede seguir, o la respuesta 404 que debe
 * devolverse tal cual. Se comprueba en el BFF, no solo en la UI: una petición
 * directa no puede saltarse un interruptor apagado.
 */
export function requireLoyalty(
  config: Pick<SiteConfig, "modules" | "loyalty">,
  feature: LoyaltyFeature,
): NextResponse | null {
  if (!isModuleEnabled(config, "loyalty")) return notFound();

  const points = config.loyalty?.points_enabled === true;
  const enabled =
    feature === "points"
      ? points
      : feature === "referrals"
        ? points && config.loyalty.referrals_enabled === true
        : points && config.loyalty.transfers_enabled === true;

  return enabled ? null : notFound();
}
