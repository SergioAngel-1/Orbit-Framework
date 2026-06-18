import "server-only";
import { cache } from "react";
import { fetchRemoteConfig } from "./remote";

export type { SiteConfig } from "./types";
export { CONFIG_DEFAULTS } from "./defaults";
export { buildCssVars } from "./tokens";

/**
 * Devuelve la configuración efectiva del sitio.
 *
 * Memoizada con `cache()` de React: dentro de un mismo request/render cycle
 * no se realizan múltiples fetch. Los sucesivos requests usan ISR (Next.js).
 *
 * Uso en Server Components o Route Handlers:
 *   const config = await getSiteConfig();
 */
export const getSiteConfig = cache(fetchRemoteConfig);
