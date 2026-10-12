import { describe, expect, it, vi } from "vitest";

vi.mock("@/instance/registry", () => ({
  instanceRegistry: { revalidationTags: ["example"] },
}));

import { resolveHweTag } from "@/app/api/revalidate/route";

describe("resolveHweTag con tags de la instancia", () => {
  it("acepta un tag declarado en instanceRegistry.revalidationTags", () => {
    expect(resolveHweTag({ tag: "example" })).toBe("example");
  });

  it("sigue aceptando los del núcleo y rechazando el resto", () => {
    expect(resolveHweTag({ tag: "site-config" })).toBe("site-config");
    expect(resolveHweTag({ tag: "otro" })).toBeNull();
  });
});
