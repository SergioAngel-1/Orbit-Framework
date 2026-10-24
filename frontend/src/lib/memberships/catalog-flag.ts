import "server-only";
import { cache } from "react";
import { getSiteConfig } from "@/lib/config";
import { isModuleEnabled } from "@/lib/config/modules";

// ============================================================================
//  ¿El catálogo debe pedir el campo del módulo de membresías?
//
//  Con el módulo apagado, `Product.hweMembership` NO existe en el esquema de
//  WPGraphQL: pedirlo haría fallar toda la consulta del catálogo. Por eso hay dos
//  variantes de cada documento y esta bandera elige la correcta. Se memoiza por
//  request con `cache()` de React (la config ya lo está).
// ============================================================================

export const membershipsCatalogEnabled = cache(async (): Promise<boolean> => {
  const config = await getSiteConfig();
  return isModuleEnabled(config, "memberships");
});
