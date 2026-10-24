import "server-only";
import { NextResponse } from "next/server";
import { isModuleEnabled } from "@/lib/config/modules";
import type { SiteConfig } from "@/lib/config/types";

/** Cuerpo 404 común del módulo (igual que `requireModule`). */
function notFound(): NextResponse {
  return NextResponse.json({ error: "No encontrado." }, { status: 404 });
}

/**
 * Guarda del carné digital para las rutas BFF (`/api/memberships/card` y
 * `/api/memberships/verify/[token]`).
 *
 *  - Sin el módulo activo, 404.
 *  - Con el módulo activo pero el carné apagado (`card_enabled`), también 404.
 *
 * Devuelve `null` si la ruta puede seguir, o la respuesta 404 que debe
 * devolverse tal cual. Se comprueba en el BFF, no solo en la UI: una petición
 * directa no puede saltarse un interruptor apagado.
 */
export function requireMembershipCard(
  config: Pick<SiteConfig, "modules" | "memberships">,
): NextResponse | null {
  if (!isModuleEnabled(config, "memberships")) return notFound();
  return config.memberships?.card_enabled === true ? null : notFound();
}
