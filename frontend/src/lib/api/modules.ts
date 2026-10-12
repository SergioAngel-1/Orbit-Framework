import "server-only";
import { NextResponse } from "next/server";
import type { ModuleId, SiteConfig } from "@/lib/config/types";
import { isModuleEnabled } from "@/lib/config/modules";

/**
 * Guarda de módulo para rutas BFF: con el módulo apagado, la ruta responde 404
 * (el módulo no existe a efectos del frontend). Úsalo al inicio de cada ruta
 * propia de un módulo:
 *
 *   const blocked = requireModule(config, "site-reviews");
 *   if (blocked) return blocked;
 */
export function requireModule(
  config: Pick<SiteConfig, "modules">,
  id: ModuleId,
): NextResponse | null {
  if (isModuleEnabled(config, id)) return null;
  return NextResponse.json({ error: "No encontrado." }, { status: 404 });
}
