import { describe, expect, it } from "vitest";
import { classifyCmsHref, resolveCmsHref } from "./cms-href";

describe("resolveCmsHref", () => {
  it.each([
    ["/products", "/products"],
    ["  /blog?x=1#a ", "/blog?x=1#a"],
    ["#faq", "#faq"],
    ["https://example.com/a", "https://example.com/a"],
    ["http://example.com", "http://example.com"],
    ["mailto:hola@example.com", "mailto:hola@example.com"],
    ["tel:+34600000000", "tel:+34600000000"],
    ["editorial:guia", "editorial:guia"],
  ])("admite %j", (raw, expected) => {
    expect(resolveCmsHref(raw)).toBe(expected);
  });

  it.each([
    "javascript:alert(1)",
    "JaVaScRiPt:alert(1)",
    "java\tscript:alert(1)",
    "java\nscript:alert(1)",
    "data:text/html,<script>",
    "vbscript:x",
    "//evil.example/x",
    "/\\evil.example",
    "\\/evil.example",
    "\\\\evil.example",
    "/\t/evil.example",
    "ftp://example.com",
    "products",
    ":x",
    "",
    "   ",
  ])("rechaza %j", (raw) => {
    expect(resolveCmsHref(raw)).toBeNull();
  });

  it("rechaza lo que no es texto", () => {
    expect(resolveCmsHref(null)).toBeNull();
    expect(resolveCmsHref(undefined)).toBeNull();
  });
});

describe("classifyCmsHref", () => {
  it("clasifica cada forma", () => {
    expect(classifyCmsHref("/products")).toEqual({
      kind: "internal",
      href: "/products",
    });
    expect(classifyCmsHref("#faq")).toEqual({
      kind: "plain",
      href: "#faq",
      external: false,
    });
    expect(classifyCmsHref("https://example.com")).toEqual({
      kind: "plain",
      href: "https://example.com",
      external: true,
    });
    expect(classifyCmsHref("mailto:a@b.c")).toEqual({
      kind: "plain",
      href: "mailto:a@b.c",
      external: false,
    });
    expect(classifyCmsHref("editorial:guia")).toEqual({
      kind: "editorial",
      slug: "guia",
    });
  });

  it("un editorial: sin identificador o un esquema no admitido es nulo", () => {
    expect(classifyCmsHref("editorial:")).toBeNull();
    expect(classifyCmsHref("javascript:alert(1)")).toBeNull();
  });
});
