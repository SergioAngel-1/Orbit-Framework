"use client";
import { useEffect } from "react";
import { usePathname } from "next/navigation";

// ============================================================================
//  PageView de Meta en la navegación interna: exactamente uno por vista.
//
//  El snippet base (`MetaPixelScript`) envía el PageView de la vista en la que
//  se carga el píxel. Las navegaciones posteriores son del router de Next, sin
//  recarga, así que el snippet no vuelve a correr y el PageView lo manda este
//  componente al cambiar la RUTA.
//
//  Con `trackSingle`, NO con `track`. fbevents.js guarda qué píxeles ya
//  enviaron un PageView en esta carga y DESCARTA en silencio cualquier
//  `fbq('track', 'PageView')` posterior: solo deja pasar el suyo automático de
//  cambio de historial, que aquí está apagado (párrafo siguiente). Con `track`
//  llegaba a Meta el PageView de la entrada y ninguno más —ni en «Probar
//  eventos» ni en Pixel Helper—, y el componente parecía funcionar porque la
//  llamada se hacía. `trackSingle(id, …)` es la API pública para un píxel
//  concreto y no pasa por esa deduplicación (verificado en fbevents.js el
//  2026-09-25).
//
//  El píxel tiene su propio rastreo de SPA —engancha `pushState`/`replaceState`
//  y dispara un PageView por cada cambio de URL, query y hash incluidos—, y el
//  snippet lo apaga con `fbq.disablePushState = true`: si no, cambiar el orden
//  de la tienda o cada pausa al escribir en el buscador del blog contaba como
//  una vista, y con este componente además se duplicaba el de cada ruta.
//
//  La vista es el `pathname`, no la query: ordenar, buscar o filtrar un
//  listado no es una página nueva. Cambiar de idioma sí (`/tienda` →
//  `/en/store`). Es el de `next/navigation` (URL real), no el de
//  `@/i18n/navigation`, que devuelve la plantilla (`/legal/[slug]`) y no
//  cambiaría entre dos fichas de la misma ruta dinámica.
//
//  La última ruta enviada vive a nivel de MÓDULO, no en un ref: el cambio de
//  idioma remonta el layout de `[locale]` y con él este componente, y un ref
//  recién creado tomaría la ruta nueva por "ya enviada". El primer montaje la
//  marca sin enviar nada, porque ese PageView ya lo mandó el snippet (y así el
//  doble efecto de StrictMode tampoco duplica).
// ============================================================================

let lastTrackedPath: string | null = null;

export function MetaPixelPageViews({ pixelId }: { pixelId: string }) {
  const pathname = usePathname();

  useEffect(() => {
    if (lastTrackedPath === null) {
      lastTrackedPath = pathname;
      return;
    }
    if (pathname === lastTrackedPath) return;
    lastTrackedPath = pathname;
    window.fbq?.("trackSingle", pixelId, "PageView");
  }, [pathname, pixelId]);

  return null;
}
