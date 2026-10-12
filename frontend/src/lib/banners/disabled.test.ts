import { afterEach, describe, expect, it, vi } from "vitest";
import { getBannerPlacement } from "./index";

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("getBannerPlacement con el módulo apagado", () => {
  it("devuelve el placement vacío sin llamar a WordPress", async () => {
    const fetchSpy = vi
      .spyOn(globalThis, "fetch")
      .mockRejectedValue(new Error("no debe llamarse"));

    const placement = await getBannerPlacement("hero", "es", { enabled: false });

    expect(placement).toEqual({ slides: [], intervalMs: 6000 });
    expect(fetchSpy).not.toHaveBeenCalled();
  });
});
