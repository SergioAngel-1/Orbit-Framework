import { describe, expect, it, vi } from "vitest";
import {
  MAX_DYNAMIC_STALE_SECONDS,
  routerStaleTimes,
} from "../../scripts/router-stale-times.mjs";

// La caché del router del cliente para vistas dinámicas es OPT-IN: reutilizar el
// render de la cuenta o del carrito tras una escritura que no llama a
// `router.refresh()` enseñaría datos viejos. Lo que se fija aquí: sin la variable
// la config de Next no cambia, y un valor roto nunca llega a Next.
describe("routerStaleTimes", () => {
  it("sin variable (o vacía) no toca la config: Next sigue con dynamic 0", () => {
    const warn = vi.fn();
    expect(routerStaleTimes({}, warn)).toEqual({});
    expect(routerStaleTimes({ NEXT_ROUTER_STALE_DYNAMIC: "" }, warn)).toEqual({});
    expect(routerStaleTimes({ NEXT_ROUTER_STALE_DYNAMIC: "  " }, warn)).toEqual({});
    expect(warn).not.toHaveBeenCalled();
  });

  it("con segundos válidos fija dynamic y conserva el static de Next (300)", () => {
    expect(routerStaleTimes({ NEXT_ROUTER_STALE_DYNAMIC: "30" })).toEqual({
      staleTimes: { dynamic: 30, static: 300 },
    });
    expect(routerStaleTimes({ NEXT_ROUTER_STALE_DYNAMIC: " 300 " })).toEqual({
      staleTimes: { dynamic: MAX_DYNAMIC_STALE_SECONDS, static: 300 },
    });
  });

  it.each(["0", "-5", "30.5", "abc", "301", "1e3"])(
    "«%s» se ignora con aviso y no llega a Next",
    (value) => {
      const warn = vi.fn();
      expect(routerStaleTimes({ NEXT_ROUTER_STALE_DYNAMIC: value }, warn)).toEqual({});
      expect(warn).toHaveBeenCalledOnce();
      expect(warn.mock.calls[0]![0]).toContain("NEXT_ROUTER_STALE_DYNAMIC");
    },
  );
});
