import { createNavigation } from "next-intl/navigation";
import { routing } from "./routing";

// ============================================================================
//  Navegación consciente del locale.
//  Usa SIEMPRE estos `Link`, `redirect`, `useRouter`, `usePathname` en lugar de
//  los de `next/*` para que el prefijo de idioma (/en/...) se conserve al navegar.
// ============================================================================

export const { Link, redirect, usePathname, useRouter, getPathname } =
  createNavigation(routing);
