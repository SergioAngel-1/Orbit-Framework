"use client";
import { useEffect } from "react";
import { usePathname } from "next/navigation";

// ============================================================================
//  Vista de página de HubSpot en la navegación interna: una por ruta.
//
//  El código de seguimiento de HubSpot registra la vista de la página en la
//  que se carga y NADA MÁS: a diferencia del píxel de Meta, no engancha
//  `pushState` (comprobado en su analytics.js el 2026-09-28), así que las
//  navegaciones del router de Next —sin recarga— no le llegan. Su API para
//  aplicaciones de una sola página es `setPath` + `trackPageView` sobre la
//  cola `_hsq`, que existe antes de que cargue el script y la procesa al
//  llegar (developers.hubspot.com → Tracking code v1 → «Set the current page
//  path»).
//
//  Mismo criterio que `MetaPixelPageViews`: la vista es el `pathname` de
//  `next/navigation` (la URL real, con el prefijo de idioma), no la query —
//  ordenar o filtrar un listado no es una página nueva—; la última ruta
//  enviada vive a nivel de MÓDULO porque el cambio de idioma remonta el
//  layout; y el primer montaje solo la marca, porque esa vista ya la cuenta
//  el propio script al cargar.
// ============================================================================

let lastTrackedPath: string | null = null;

export function HubSpotPageViews() {
  const pathname = usePathname();

  useEffect(() => {
    if (lastTrackedPath === null) {
      lastTrackedPath = pathname;
      return;
    }
    if (pathname === lastTrackedPath) return;
    lastTrackedPath = pathname;
    const hsq = (window._hsq = window._hsq || []);
    hsq.push(["setPath", pathname]);
    hsq.push(["trackPageView"]);
  }, [pathname]);

  return null;
}
