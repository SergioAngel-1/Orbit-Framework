import { describe, expect, it } from "vitest";
import { isoDate, isoDateOrMonth } from "./iso-date";

describe("isoDate", () => {
  it("acepta un día real y lo devuelve sin espacios", () => {
    expect(isoDate("2026-03-15")).toBe("2026-03-15");
    expect(isoDate("  2024-02-29 ")).toBe("2024-02-29");
  });

  it.each([
    "2026-02-31",
    "2025-02-29",
    "2026-13-01",
    "2026-3-5",
    "15/03/2026",
    "",
    "2026-03",
  ])("rechaza %j", (value) => {
    expect(isoDate(value)).toBeNull();
  });

  it("rechaza lo que no es texto", () => {
    expect(isoDate(null)).toBeNull();
    expect(isoDate(20260315)).toBeNull();
  });
});

describe("isoDateOrMonth", () => {
  it("admite un mes a secas y un día completo", () => {
    expect(isoDateOrMonth("2026-03")).toBe("2026-03");
    expect(isoDateOrMonth("2026-03-15")).toBe("2026-03-15");
  });

  it("rechaza un mes imposible y un día imposible", () => {
    expect(isoDateOrMonth("2026-00")).toBeNull();
    expect(isoDateOrMonth("2026-13")).toBeNull();
    expect(isoDateOrMonth("2026-02-30")).toBeNull();
    expect(isoDateOrMonth("marzo 2026")).toBeNull();
  });
});
