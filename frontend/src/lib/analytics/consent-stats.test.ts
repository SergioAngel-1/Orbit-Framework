import { describe, expect, it } from "vitest";
import { CONSENT_GENERATION } from "./consent-storage";
import {
  bucketHoursBack,
  groupConsentBuckets,
  hourBucketKey,
  parseConsentEvent,
  resolveTimeZone,
  trimToDays,
} from "./consent-stats";

describe("parseConsentEvent — solo eventos y variantes conocidos", () => {
  it("acepta un evento válido", () => {
    expect(parseConsentEvent({ event: "shown", variant: "bar" })).toEqual({
      event: "shown",
      variant: "bar",
    });
  });

  it("rechaza lo que no está en la allow-list", () => {
    expect(parseConsentEvent({ event: "clicked", variant: "bar" })).toBeNull();
    expect(parseConsentEvent({ event: "accepted", variant: "popup" })).toBeNull();
    expect(parseConsentEvent({ event: "accepted" })).toBeNull();
    expect(parseConsentEvent(null)).toBeNull();
    expect(parseConsentEvent(["accepted", "bar"])).toBeNull();
  });
});

describe("hourBucketKey — un hash por HORA en UTC", () => {
  it("usa la hora UTC, no la local del servidor", () => {
    expect(hourBucketKey(new Date("2026-09-25T23:59:59.999Z"))).toBe(
      `hwe:consent-stats:g${CONSENT_GENERATION}:2026-09-25T23`,
    );
    expect(hourBucketKey(new Date("2026-09-26T00:00:00Z"))).toBe(
      `hwe:consent-stats:g${CONSENT_GENERATION}:2026-09-26T00`,
    );
  });

  it("bucketHoursBack cubre N días completos hasta la hora actual incluida", () => {
    const hours = bucketHoursBack(new Date("2026-09-25T10:30:00Z"), 1);
    expect(hours).toHaveLength(24);
    expect(hours[0]!.toISOString()).toBe("2026-09-24T11:00:00.000Z");
    expect(hours.at(-1)!.toISOString()).toBe("2026-09-25T10:00:00.000Z");
  });
});

describe("resolveTimeZone", () => {
  it("devuelve la zona si Intl la conoce, y UTC si no", () => {
    expect(resolveTimeZone("America/Bogota")).toBe("America/Bogota");
    // `wp_timezone_string()` devuelve un offset si el sitio usa uno manual.
    expect(resolveTimeZone("-05:00")).toBe("-05:00");
    expect(resolveTimeZone("Marte/Olympus")).toBe("UTC");
    expect(resolveTimeZone(null)).toBe("UTC");
  });
});

describe("groupConsentBuckets — horas UTC → días de la zona del sitio", () => {
  const buckets: { hour: Date; fields: Record<string, string> }[] = [
    // 2026-09-25 23:00 UTC = 18:00 en Bogotá (día 25)
    {
      hour: new Date("2026-09-25T23:00:00Z"),
      fields: { "bar:shown": "3", "bar:accepted": "1" },
    },
    // 2026-09-26 04:00 UTC = 23:00 del 25 en Bogotá (sigue siendo el 25)
    {
      hour: new Date("2026-09-26T04:00:00Z"),
      fields: { "bar:shown": "2", "bar:declined": "1" },
    },
    // 2026-09-26 05:00 UTC = 00:00 del 26 en Bogotá
    {
      hour: new Date("2026-09-26T05:00:00Z"),
      fields: { "bar:shown": "4", "bar:accepted": "4" },
    },
    // Campo desconocido o basura: se ignora.
    {
      hour: new Date("2026-09-26T06:00:00Z"),
      fields: { "foo:shown": "9", "bar:shown": "x" },
    },
    { hour: new Date("2026-09-26T07:00:00Z"), fields: {} },
  ];

  it("reparte por día LOCAL y suma por variante", () => {
    const report = groupConsentBuckets(buckets, "America/Bogota");
    expect(report.days).toEqual([
      { date: "2026-09-25", variants: { bar: { shown: 5, accepted: 1, declined: 1 } } },
      { date: "2026-09-26", variants: { bar: { shown: 4, accepted: 4, declined: 0 } } },
    ]);
    expect(report.totals).toEqual({ bar: { shown: 9, accepted: 5, declined: 1 } });
  });

  it("en UTC los mismos datos caen en otros días", () => {
    const report = groupConsentBuckets(buckets, "UTC");
    expect(report.days.map((d) => d.date)).toEqual(["2026-09-25", "2026-09-26"]);
    expect(report.days[0]!.variants.bar).toEqual({
      shown: 3,
      accepted: 1,
      declined: 0,
    });
  });

  it("sin datos no inventa días ni variantes", () => {
    expect(groupConsentBuckets([], "UTC")).toEqual({ days: [], totals: {} });
  });
});

describe("trimToDays — días de calendario, no días con datos", () => {
  const counts = { shown: 1, accepted: 1, declined: 0 };
  const report = {
    days: [
      { date: "2026-09-10", variants: { bar: counts } },
      { date: "2026-09-20", variants: { bar: counts } },
      { date: "2026-09-25", variants: { bar: counts } },
    ],
    totals: { bar: { shown: 3, accepted: 3, declined: 0 } },
  };

  it("7 días hasta hoy excluyen un día con datos de hace dos semanas", () => {
    // 2026-09-25 12:00 en Bogotá → ventana 19..25 de septiembre.
    const trimmed = trimToDays(
      report,
      7,
      "America/Bogota",
      new Date("2026-09-25T17:00:00Z"),
    );
    expect(trimmed.days.map((d) => d.date)).toEqual(["2026-09-20", "2026-09-25"]);
    expect(trimmed.totals).toEqual({ bar: { shown: 2, accepted: 2, declined: 0 } });
  });
});
