// @vitest-environment jsdom
import { afterEach, describe, expect, it } from "vitest";
import { pageLocaleHeaders } from "./page-locale";

afterEach(() => {
  document.documentElement.lang = "";
});

describe("pageLocaleHeaders", () => {
  it("manda el idioma del documento", () => {
    document.documentElement.lang = "en";

    expect(pageLocaleHeaders()).toEqual({ "X-HWE-Locale": "en" });
  });

  it("sin lang no manda nada", () => {
    expect(pageLocaleHeaders()).toEqual({});
  });
});
