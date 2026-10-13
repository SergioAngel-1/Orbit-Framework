import { describe, expect, it } from "vitest";
import { readdirSync } from "node:fs";
import { resolve } from "node:path";
import { instanceRegistry } from "@/instance/registry";
import { reservedRootSegments } from "@/lib/landings/reserved-segments";

// ============================================================================
//  Cada ruta de UN solo tramo bajo `app/[locale]/` debe estar reservada.
//
//  Next da precedencia al segmento estático sobre `[landingPath]`: una landing de
//  raíz sobre una ruta existente sería inalcanzable, en silencio. La lista de
//  `reserved-segments.ts` se escribe a mano, así que este test la contrasta con
//  el árbol real: añadir una ruta (en el framework o en una instancia) sin
//  reservarla rompe aquí, no en producción.
// ============================================================================

const APP_DIR = resolve(process.cwd(), "src/app/[locale]");

/** Carpetas que son una ruta pública de un tramo: ni dinámicas, ni privadas, ni de archivo. */
function staticRouteSegments(): string[] {
  return readdirSync(APP_DIR, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => entry.name)
    .filter(
      (name) => !name.startsWith("[") && !name.startsWith("_") && !name.startsWith("("),
    );
}

describe("segmentos reservados de la raíz", () => {
  it("el recorrido de app/[locale] encuentra las rutas (guarda contra un falso verde)", () => {
    const segments = staticRouteSegments();

    expect(segments).toEqual(
      expect.arrayContaining(["products", "blog", "cart", "learn"]),
    );
  });

  it("toda ruta estática de un tramo está reservada", () => {
    const reserved = reservedRootSegments(
      instanceRegistry.landings?.reservedRootSegments ?? [],
    );
    const missing = staticRouteSegments().filter((segment) => !reserved.has(segment));

    expect(
      missing,
      `Añade estos tramos a CORE_ROOT_SEGMENTS (o a instanceRegistry.landings.reservedRootSegments): ${missing.join(", ")}`,
    ).toEqual([]);
  });
});
