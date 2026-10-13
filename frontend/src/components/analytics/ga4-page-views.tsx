"use client";
import { useEffect } from "react";
import { usePathname } from "next/navigation";

// ============================================================================
//  page_view de GA4 en la navegación interna: uno por RUTA, como Meta y
//  HubSpot (`MetaPixelPageViews`, `HubSpotPageViews`).
//
//  El `gtag('config', …)` de `Ga4Scripts` manda el de la vista en la que se
//  carga. Las siguientes son del router de Next, sin recarga. GA4 las contaba
//  con su medición mejorada («Cambios de página según los eventos del
//  historial del navegador»), que dispara un page_view por CADA cambio de
//  URL, query incluida: ordenar la tienda o cada búsqueda del blog
//  (`router.push` con `?q=`) eran vistas nuevas, y las cifras de GA4 no
//  cuadraban con las de Meta ni HubSpot, que cuentan una por ruta.
//
//  gtag.js no tiene parámetro para apagar esa detección: se apaga en GA4 →
//  Administrar → Flujos de datos → el flujo web → Medición mejorada → Vistas
//  de página → Configuración avanzada → «Cambios de página según los eventos
//  del historial del navegador». CON ESE AJUSTE ENCENDIDO, ESTE COMPONENTE
//  DUPLICA cada vista de la navegación interna.
//
//  Mismo criterio que los otros dos: el `pathname` de `next/navigation` (la
//  URL real, con el prefijo de idioma), la última ruta enviada a nivel de
//  MÓDULO (el cambio de idioma remonta el layout) y el primer montaje solo la
//  marca, porque esa vista ya la manda el `config`.
// ============================================================================

let lastTrackedPath: string | null = null;

export function Ga4PageViews() {
  const pathname = usePathname();

  useEffect(() => {
    if (lastTrackedPath === null) {
      lastTrackedPath = pathname;
      return;
    }
    if (pathname === lastTrackedPath) return;
    lastTrackedPath = pathname;
    window.gtag?.("event", "page_view", {
      page_location: window.location.href,
      page_title: document.title,
    });
  }, [pathname]);

  return null;
}
