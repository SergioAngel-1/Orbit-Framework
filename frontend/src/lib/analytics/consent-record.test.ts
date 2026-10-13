import { describe, expect, it } from "vitest";
import {
  clampUserAgent,
  isConsentReceipt,
  parseConsentRecordRequest,
  sanitizeConsentPath,
  truncateIp,
} from "./consent-record";

describe("truncateIp", () => {
  it("IPv4: el último octeto a cero (/24)", () => {
    expect(truncateIp("181.49.23.117")).toBe("181.49.23.0");
  });

  it("IPv6: se queda con los tres primeros grupos (/48)", () => {
    expect(truncateIp("2800:e2:1a80:12f5:8d0c:1b2:3c4:5d6")).toBe("2800:e2:1a80::");
  });

  it("IPv6 abreviada: se expande antes de cortar", () => {
    expect(truncateIp("2800::1")).toBe("2800:0:0::");
    expect(truncateIp("::1")).toBe("0:0:0::");
  });

  it("IPv4 dentro de IPv6 (::ffff:a.b.c.d) se trata como IPv4", () => {
    expect(truncateIp("::ffff:10.1.2.3")).toBe("10.1.2.0");
  });

  it("lo que no es una IP no se guarda", () => {
    expect(truncateIp("unknown")).toBeNull();
    expect(truncateIp("")).toBeNull();
    expect(truncateIp("999.1.1.1")).toBeNull();
    expect(truncateIp("1.2.3")).toBeNull();
    expect(truncateIp("2800:zz::1")).toBeNull();
  });
});

describe("sanitizeConsentPath", () => {
  it("solo la ruta: sin query ni fragmento (pueden llevar datos personales)", () => {
    expect(sanitizeConsentPath("/checkout?email=a@b.co#pago")).toBe("/checkout");
  });

  it("rechaza lo que no es una ruta del propio sitio", () => {
    expect(sanitizeConsentPath("https://otro.com/x")).toBeNull();
    expect(sanitizeConsentPath("//otro.com/x")).toBeNull();
    expect(sanitizeConsentPath("tienda")).toBeNull();
    expect(sanitizeConsentPath(42)).toBeNull();
  });

  it("corta a 255 caracteres", () => {
    expect(sanitizeConsentPath("/" + "a".repeat(400))).toHaveLength(255);
  });
});

describe("isConsentReceipt", () => {
  it("acepta un UUID y rechaza lo demás", () => {
    expect(isConsentReceipt("3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c")).toBe(true);
    expect(isConsentReceipt("3F2B8C1E-9A4D-4C7E-8B1A-2D3E4F5A6B7C")).toBe(false);
    expect(isConsentReceipt("no-es-un-uuid")).toBe(false);
    expect(isConsentReceipt(undefined)).toBe(false);
  });
});

describe("clampUserAgent", () => {
  it("corta a 255 y vacío = null", () => {
    expect(clampUserAgent("x".repeat(300))).toHaveLength(255);
    expect(clampUserAgent("")).toBeNull();
    expect(clampUserAgent(null)).toBeNull();
  });
});

describe("parseConsentRecordRequest", () => {
  const locales = ["es", "en"] as const;
  const valid = {
    decision: "accepted",
    action: "first",
    path: "/tienda?x=1",
    variant: "bar",
    locale: "es",
    clientAt: "2026-09-25T20:00:00.000Z",
  };

  it("acepta una decisión válida y limpia la ruta", () => {
    expect(parseConsentRecordRequest(valid, locales)).toEqual({
      decision: "accepted",
      action: "first",
      path: "/tienda",
      variant: "bar",
      locale: "es",
      clientAt: "2026-09-25T20:00:00.000Z",
      services: null,
      generation: null,
    });
  });

  it("guarda la generación declarada si es un entero razonable", () => {
    expect(
      parseConsentRecordRequest({ ...valid, generation: 4 }, locales)?.generation,
    ).toBe(4);
    for (const generation of [0, -1, 1.5, "4", 1000]) {
      expect(
        parseConsentRecordRequest({ ...valid, generation }, locales)?.generation,
      ).toBeNull();
    }
  });

  it("guarda los servicios que nombró el banner, sin repetidos", () => {
    expect(
      parseConsentRecordRequest(
        { ...valid, services: ["ga4", "meta", "ga4", "hubspot"] },
        locales,
      )?.services,
    ).toEqual(["ga4", "meta", "hubspot"]);
  });

  it("una lista de servicios vacía o con nombres desconocidos invalida la decisión", () => {
    expect(parseConsentRecordRequest({ ...valid, services: [] }, locales)).toBeNull();
    expect(
      parseConsentRecordRequest({ ...valid, services: ["ga4", "x"] }, locales),
    ).toBeNull();
    expect(
      parseConsentRecordRequest({ ...valid, services: "ga4" }, locales),
    ).toBeNull();
  });

  it("rechaza decisión, acción, variante o idioma fuera de la lista", () => {
    expect(
      parseConsentRecordRequest({ ...valid, decision: "maybe" }, locales),
    ).toBeNull();
    expect(
      parseConsentRecordRequest({ ...valid, action: "revoke" }, locales),
    ).toBeNull();
    expect(
      parseConsentRecordRequest({ ...valid, variant: "popup" }, locales),
    ).toBeNull();
    expect(parseConsentRecordRequest({ ...valid, locale: "fr" }, locales)).toBeNull();
    expect(parseConsentRecordRequest(null, locales)).toBeNull();
    expect(parseConsentRecordRequest([], locales)).toBeNull();
  });

  it("una ruta inválida no invalida la decisión: se guarda sin ruta", () => {
    expect(
      parseConsentRecordRequest({ ...valid, path: "//x" }, locales)?.path,
    ).toBeNull();
  });

  it("la hora declarada por el navegador es informativa: si no es una fecha, null", () => {
    expect(
      parseConsentRecordRequest({ ...valid, clientAt: "ayer" }, locales)?.clientAt,
    ).toBeNull();
    expect(
      parseConsentRecordRequest({ ...valid, clientAt: undefined }, locales)?.clientAt,
    ).toBeNull();
  });
});
