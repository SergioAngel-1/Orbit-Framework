"use client";
import { useEffect } from "react";

/**
 * Quita de la URL (sin navegar) los parámetros sensibles, p. ej. el token de
 * verificación: que no queden en el historial ni lleguen a las analíticas
 * (que leen `location.href`). Quien los necesite debe leerlos ANTES, en estado.
 */
export function useScrubQueryParams(names: readonly string[]): void {
  const key = names.join(",");
  useEffect(() => {
    const url = new URL(window.location.href);
    let changed = false;
    for (const name of key.split(",")) {
      if (url.searchParams.has(name)) {
        url.searchParams.delete(name);
        changed = true;
      }
    }
    if (changed) window.history.replaceState(window.history.state, "", url.toString());
  }, [key]);
}
