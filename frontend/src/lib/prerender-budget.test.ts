import { describe, expect, it, vi } from "vitest";
import { prerenderLongTail, prerenderPolicy } from "./prerender-budget";

describe("prerenderPolicy", () => {
  it("por defecto hornea todo: cambiarlo no puede pasar sin que un test lo vea", () => {
    expect(prerenderPolicy({})).toBe("all");
  });

  it("`none` deja la cola larga para ISR; tolera el formato de un .env", () => {
    expect(prerenderPolicy({ PRERENDER_LONG_TAIL: "none" })).toBe("none");
    expect(prerenderPolicy({ PRERENDER_LONG_TAIL: " NONE " })).toBe("none");
  });

  it("un valor inservible cae al defecto, no a un cambio silencioso", () => {
    for (const malo of ["", "ninguno", "0", "false", undefined]) {
      expect(prerenderPolicy({ PRERENDER_LONG_TAIL: malo })).toBe("all");
    }
  });
});

describe("prerenderLongTail", () => {
  const params = [{ slug: "a" }, { slug: "b" }];

  it("con `none` devuelve [] SIN consultar el catálogo", async () => {
    const load = vi.fn(async () => params);
    await expect(
      prerenderLongTail(load, { PRERENDER_LONG_TAIL: "none" }),
    ).resolves.toEqual([]);
    expect(load).not.toHaveBeenCalled();
  });

  it("por defecto devuelve lo que carga", async () => {
    const load = vi.fn(async () => params);
    await expect(prerenderLongTail(load, {})).resolves.toEqual(params);
    expect(load).toHaveBeenCalledOnce();
  });
});
