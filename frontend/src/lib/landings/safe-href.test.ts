import { describe, expect, it } from "vitest";
import { resolveLandingHref } from "./safe-href";

describe("resolveLandingHref", () => {
  it("admite rutas internas", () => {
    expect(resolveLandingHref("/products")).toEqual({
      kind: "internal",
      href: "/products",
    });
    expect(resolveLandingHref("  /blog?x=1#a ")).toEqual({
      kind: "internal",
      href: "/blog?x=1#a",
    });
  });

  it("admite direcciones http(s)", () => {
    expect(resolveLandingHref("https://example.com/a")).toEqual({
      kind: "external",
      href: "https://example.com/a",
    });
    expect(resolveLandingHref("http://example.com")?.kind).toBe("external");
  });

  it.each([
    "javascript:alert(1)",
    "JaVaScRiPt:alert(1)",
    "data:text/html,<script>",
    "//evil.example/x",
    "/\\evil.example",
    "\\\\evil.example",
    "java\tscript:alert(1)",
    "mailto:a@b.c",
    "ftp://example.com",
    "products",
    "",
    "   ",
  ])("descarta %j", (value) => {
    expect(resolveLandingHref(value)).toBeNull();
  });

  it("descarta lo que no es texto", () => {
    expect(resolveLandingHref(null)).toBeNull();
    expect(resolveLandingHref(undefined)).toBeNull();
  });
});
