import { describe, expect, it } from "vitest";

// ============================================================================
//  `public/brand/…` es el arte de marca que versiona cada instancia (logo,
//  logotipo para fondos oscuros, iconos). Next sirve `public/` con
//  `max-age=0`, así que cada visita lo revalidaba; una regla propia le da un
//  día de caché con `stale-while-revalidate`. Lo que NO puede pasar es que esa
//  regla (ni ninguna otra por extensión) toque `/_next/static`, que se sirve con
//  `immutable`: pisarlo invalidaría el hash con el que Next versiona el bundle.
// ============================================================================

type Header = { key: string; value: string };
type Rule = { source: string; headers: Header[] };

async function rules(): Promise<Rule[]> {
  const mod = (await import("../../next.config.mjs")) as {
    default: { headers: () => Promise<Rule[]> };
  };
  return mod.default.headers();
}

describe("next.config.mjs: caché de los estáticos de marca", () => {
  it("`/brand/*` lleva un día de caché con stale-while-revalidate", async () => {
    const rule = (await rules()).find((entry) => entry.source === "/brand/:path*");

    expect(rule).toBeDefined();
    expect(rule!.headers).toEqual([
      {
        key: "Cache-Control",
        value: "public, max-age=86400, stale-while-revalidate=86400",
      },
    ]);
  });

  it("la regla general NO manda Cache-Control (/_next/static conserva su `immutable`)", async () => {
    const catchAll = (await rules()).find((entry) => entry.source === "/:path*");

    expect(catchAll).toBeDefined();
    expect(catchAll!.headers.map((header) => header.key)).not.toContain(
      "Cache-Control",
    );
  });

  it("no hay reglas por extensión ni reglas que cubran `/_next`", async () => {
    const all = await rules();

    for (const rule of all) {
      expect(rule.source).not.toMatch(/_next/);
      // Una ruta concreta acaba en `/…`, no en `*.<extensión>`.
      expect(rule.source).not.toMatch(/\.[a-z0-9]{2,5}(\*)?$/i);
    }
  });
});
