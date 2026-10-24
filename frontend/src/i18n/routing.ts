import { defineRouting } from "next-intl/routing";
import { instanceRegistry } from "@/instance/registry";
import { validatePublicPaths } from "@/lib/instance/public-paths";
import { CORE_ROOT_SEGMENTS } from "@/lib/landings/core-segments";

// Los idiomas y el idioma principal son de la instancia (zona gris: solo esta lista).
export const routing = defineRouting({
  locales: ["es", "en"],
  defaultLocale: "es",
  localePrefix: "as-needed",
});

// Las rutas públicas que declare la instancia (`InstanceRegistry.publicPaths`) se validan
// al cargar este módulo —lo importa el proxy, la navegación y el SEO—: un mapa ambiguo (dos
// rutas a la misma URL, un idioma o `/api` ocupados) falla al arrancar con un mensaje claro
// en vez de servir URLs rotas en silencio. Sin mapa no hace nada.
//
// No se declaran `pathnames` de next-intl a propósito: solo localizan un href que sea una
// clave EXACTA del mapa, así que una ficha concreta (`/products/vela-roja`) saldría sin
// traducir, y su middleware añadiría redirects 307 en paralelo al 308 del proxy. La
// traducción vive en `lib/instance/public-paths.ts` (SEO y navegación) y el rewrite/redirect
// en `proxy.ts`, que funcionan con rutas concretas.
validatePublicPaths(instanceRegistry.publicPaths, {
  locales: routing.locales,
  defaultLocale: routing.defaultLocale,
  internalRoots: CORE_ROOT_SEGMENTS,
});
