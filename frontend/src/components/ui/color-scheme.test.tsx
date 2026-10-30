import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import { ColorSchemeHead, colorSchemeHtmlProps } from "./color-scheme";

describe("colorSchemeHtmlProps", () => {
  it("con `system` no añade nada: el documento es el de siempre", () => {
    expect(colorSchemeHtmlProps("system")).toEqual({});
  });

  it.each(["light", "dark"] as const)(
    "con `%s` fija clase, propiedad y marca",
    (scheme) => {
      expect(colorSchemeHtmlProps(scheme)).toEqual({
        className: scheme,
        style: { colorScheme: scheme },
        "data-color-scheme": scheme,
      });
    },
  );
});

describe("ColorSchemeHead", () => {
  it("el script también respeta un esquema fijado (un layout propio puede seguir montándolo)", () => {
    expect(renderToStaticMarkup(<ColorSchemeHead scheme="system" />)).toContain(
      "hasAttribute('data-color-scheme')",
    );
  });

  it("con `system` emite el script que aplica el tema antes del primer paint", () => {
    expect(renderToStaticMarkup(<ColorSchemeHead scheme="system" />)).toContain(
      "hwe-theme",
    );
  });

  it.each(["light", "dark"] as const)(
    "con `%s` solo emite el `<meta>`: nadie lee la preferencia guardada",
    (scheme) => {
      const html = renderToStaticMarkup(<ColorSchemeHead scheme={scheme} />);

      expect(html).toBe(`<meta name="color-scheme" content="${scheme}"/>`);
    },
  );
});
