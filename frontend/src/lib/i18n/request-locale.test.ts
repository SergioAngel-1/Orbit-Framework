import { describe, expect, it, vi } from "vitest";

vi.mock("@/i18n/routing", () => ({
  routing: { locales: ["es", "en", "pt-BR"], defaultLocale: "es" },
}));

import { LOCALE_HEADER, requestLocale } from "./request-locale";

describe("requestLocale", () => {
  it("devuelve el idioma del sitio que manda el cliente", () => {
    expect(requestLocale(new Headers({ [LOCALE_HEADER]: "pt-BR" }))).toBe("pt-BR");
    expect(requestLocale(new Headers({ [LOCALE_HEADER]: " en " }))).toBe("en");
  });

  it("sin cabecera o con un idioma que el sitio no declara, el idioma por defecto", () => {
    expect(requestLocale(new Headers())).toBe("es");
    expect(requestLocale(new Headers({ [LOCALE_HEADER]: "xx" }))).toBe("es");
    expect(requestLocale(new Headers({ [LOCALE_HEADER]: 'en") { x } #' }))).toBe("es");
  });
});
