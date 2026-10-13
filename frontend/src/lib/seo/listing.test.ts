import { describe, expect, it } from "vitest";
import { hasListingState } from "./listing";

describe("hasListingState", () => {
  const keys = ["search", "category", "after"] as const;

  it("sin parámetros es la vista base", () => {
    expect(hasListingState({}, keys)).toBe(false);
  });

  it("un parámetro con valor es una vista derivada", () => {
    expect(hasListingState({ search: "camiseta" }, keys)).toBe(true);
    expect(hasListingState({ after: "abc" }, keys)).toBe(true);
  });

  it("un parámetro vacío no cuenta", () => {
    expect(hasListingState({ search: "" }, keys)).toBe(false);
    expect(hasListingState({ category: [""] }, keys)).toBe(false);
  });

  it("ignora los parámetros que no cambian el contenido (utm, etc.)", () => {
    expect(hasListingState({ utm_source: "newsletter" }, keys)).toBe(false);
  });

  it("acepta valores repetidos", () => {
    expect(hasListingState({ category: ["a", "b"] }, keys)).toBe(true);
  });
});
