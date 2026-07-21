import { describe, it, expect } from "vitest";
import { resolveHweTag } from "@/app/api/revalidate/route";

describe("resolveHweTag", () => {
  it("acepta site-config y banners", () => {
    expect(resolveHweTag({ tag: "site-config" })).toBe("site-config");
    expect(resolveHweTag({ tag: "banners" })).toBe("banners");
  });

  it("usa site-config por defecto cuando falta el tag", () => {
    expect(resolveHweTag({})).toBe("site-config");
    expect(resolveHweTag({ source: "x" })).toBe("site-config");
  });

  it("devuelve null ante un tag desconocido o body inválido", () => {
    expect(resolveHweTag({ tag: "otro" })).toBeNull();
    expect(resolveHweTag(null)).toBeNull();
    expect(resolveHweTag("no-objeto")).toBeNull();
  });
});
