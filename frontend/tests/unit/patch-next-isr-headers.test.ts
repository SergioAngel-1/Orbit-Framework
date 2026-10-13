import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";
import path from "node:path";
import {
  patchSource,
  MARKER,
  SUPPORTED_NEXT,
  TARGETS,
  PATCHED_LOOP,
} from "../../scripts/patch-next-isr-headers.mjs";

// En un MISS, Next renderiza la página (que fija `Location` con `res.setHeader`
// al redirigir) y DESPUÉS vuelca `cachedData.headers` con `res.appendHeader`,
// así que la redirección sale con dos cabeceras `Location` idénticas. Con HIT o
// STALE no hay render previo y sale una. Un cliente que fusiona cabeceras lee
// `/blog/x, /blog/x` y obtiene un 404. `scripts/patch-next-isr-headers.mjs` salta
// los valores que `res` ya lleva. Estas pruebas fijan que el Next INSTALADO
// quedó parcheado (una subida de versión que rompa el parche falla aquí y no en
// producción), que el parche es idempotente y qué hace el bucle.

const frontendRoot = path.resolve(__dirname, "../..");

/** Un `res` mínimo con la semántica de Node para `getHeader` / `appendHeader`. */
function fakeRes(initial: Record<string, string | string[]> = {}) {
  const store = new Map<string, string[]>(
    Object.entries(initial).map(([k, v]) => [k.toLowerCase(), [v].flat()]),
  );
  return {
    getHeader: (name: string) => {
      const values = store.get(name.toLowerCase());
      if (!values) return undefined;
      return values.length === 1 ? values[0] : values;
    },
    appendHeader: (name: string, value: string) => {
      const key = name.toLowerCase();
      store.set(key, [...(store.get(key) ?? []), value]);
    },
    values: (name: string) => store.get(name.toLowerCase()) ?? [],
  };
}

/** Ejecuta el bucle parcheado tal cual lo deja el script. */
function runLoop(res: ReturnType<typeof fakeRes>, headers: Record<string, unknown>) {
  new Function("res", "headers", PATCHED_LOOP)(res, headers);
}

describe("patch-next-isr-headers", () => {
  it("el parche corresponde a la versión de Next instalada", () => {
    const installed = JSON.parse(
      readFileSync(path.join(frontendRoot, "node_modules/next/package.json"), "utf8"),
    ).version;
    expect(installed).toBe(SUPPORTED_NEXT);
  });

  it.each(TARGETS)("el Next instalado está parcheado: %s", (target) => {
    const source = readFileSync(path.join(frontendRoot, target), "utf8");
    expect(source).toContain(MARKER);
    expect(source).toContain(PATCHED_LOOP);
  });

  it("es idempotente", () => {
    const source = readFileSync(path.join(frontendRoot, TARGETS[0]), "utf8");
    expect(patchSource(source)).toEqual({ status: "already", source });
  });

  it("deja intacto un código que no reconoce", () => {
    const source = "function handler(res, cachedData) { return res; }";
    expect(patchSource(source)).toEqual({ status: "unknown", source });
  });

  describe("bucle parcheado", () => {
    it("MISS: no repite el Location que el render ya fijó", () => {
      const res = fakeRes({ location: "/blog/x" });
      runLoop(res, { location: "/blog/x" });
      expect(res.values("location")).toEqual(["/blog/x"]);
    });

    it("HIT: sin render previo, emite el Location guardado una vez", () => {
      const res = fakeRes();
      runLoop(res, { location: "/blog/x" });
      expect(res.values("location")).toEqual(["/blog/x"]);
    });

    it("conserva un valor distinto que `res` aún no llevaba", () => {
      const res = fakeRes({ link: "</a.css>; rel=preload" });
      runLoop(res, { link: ["</a.css>; rel=preload", "</b.js>; rel=preload"] });
      expect(res.values("link")).toEqual([
        "</a.css>; rel=preload",
        "</b.js>; rel=preload",
      ]);
    });

    it("no repite un Set-Cookie idéntico pero sí uno nuevo", () => {
      const res = fakeRes({ "set-cookie": "a=1" });
      runLoop(res, { "set-cookie": ["a=1", "b=2"] });
      expect(res.values("set-cookie")).toEqual(["a=1", "b=2"]);
    });

    it("convierte números a texto y salta los undefined", () => {
      const res = fakeRes();
      runLoop(res, { "content-length": 12, "x-vacio": undefined });
      expect(res.values("content-length")).toEqual(["12"]);
      expect(res.values("x-vacio")).toEqual([]);
    });
  });
});
