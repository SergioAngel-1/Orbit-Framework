"use client";
import { useEffect, useState } from "react";

// Los avisos de una misma página (varios cupones, o ficha + recomendados)
// comparten UNA petición, y la respuesta vale unos segundos: cambiar de página
// dentro del sitio no la repite. Corta a propósito: iniciar sesión o pagar un
// pedido cambia la respuesta.
const TTL_MS = 20_000;

/** Lo que el servidor sabe del visitante y la página ISR no. */
export interface ViewerCoupons {
  /** Códigos (en minúsculas) que el cliente ya no puede usar. */
  usedUp: ReadonlySet<string>;
  /** `true` si no hay sesión. */
  guest: boolean;
}

let cached: { at: number; promise: Promise<ViewerCoupons> } | null = null;

function loadViewer(): Promise<ViewerCoupons> {
  const now = Date.now();
  if (cached && now - cached.at < TTL_MS) return cached.promise;
  const promise = fetch("/api/coupons/used-up", {
    credentials: "same-origin",
    cache: "no-store",
  })
    .then(async (res): Promise<ViewerCoupons> => {
      // Fail-soft: si no se puede saber, el aviso se enseña y WordPress decide
      // al aplicarlo (con su mensaje junto al campo).
      if (!res.ok) return { usedUp: new Set(), guest: true };
      const body = (await res.json()) as { codes?: unknown; authenticated?: unknown };
      const codes = Array.isArray(body.codes) ? body.codes : [];
      return {
        usedUp: new Set(
          codes
            .filter((c): c is string => typeof c === "string")
            .map((c) => c.toLowerCase()),
        ),
        guest: body.authenticated !== true,
      };
    })
    .catch(() => ({ usedUp: new Set<string>(), guest: true }));
  cached = { at: now, promise };
  return promise;
}

/** Solo para tests. */
export function resetViewerCouponsCache(): void {
  cached = null;
}

/**
 * Lo que el servidor sabe de ESTE visitante respecto a los cupones, o `null`
 * mientras se consulta (o si `enabled` es falso y no hace falta preguntar). La
 * decisión de enseñar o no un cupón con ese dato es `shouldShowCoupon`.
 */
export function useViewerCoupons(enabled: boolean): ViewerCoupons | null {
  const [viewer, setViewer] = useState<ViewerCoupons | null>(null);

  useEffect(() => {
    if (!enabled) return;
    let active = true;
    void loadViewer().then((value) => {
      if (active) setViewer(value);
    });
    return () => {
      active = false;
    };
  }, [enabled]);

  return viewer;
}
