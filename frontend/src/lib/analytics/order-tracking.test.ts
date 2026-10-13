import { describe, expect, it } from "vitest";
import {
  ORDER_TRACKING_META,
  isValidFbCookie,
  orderTrackingMetaData,
  readOrderTracking,
} from "./order-tracking";

const FBP = "fb.1.1727440000000.1234567890";
const FBC = "fb.1.1727440000000.IwAR2abc-DEF_123";

describe("isValidFbCookie", () => {
  it("acepta la forma que escribe el píxel", () => {
    expect(isValidFbCookie(FBP)).toBe(true);
    expect(isValidFbCookie(FBC)).toBe(true);
  });

  it("rechaza cualquier otra cosa", () => {
    expect(isValidFbCookie(undefined)).toBe(false);
    expect(isValidFbCookie("")).toBe(false);
    expect(isValidFbCookie("hola")).toBe(false);
    expect(isValidFbCookie("fb.1.abc.x")).toBe(false);
    expect(isValidFbCookie(`fb.1.1.${"x".repeat(600)}`)).toBe(false);
  });
});

describe("orderTrackingMetaData", () => {
  it("sin consentimiento solo deja constancia de que no se aceptó", () => {
    expect(
      orderTrackingMetaData({
        consent: false,
        fbp: FBP,
        ip: "1.2.3.4",
        userAgent: "UA",
      }),
    ).toEqual([{ key: ORDER_TRACKING_META.consent, value: "declined" }]);
  });

  it("con consentimiento guarda cookies, IP, navegador y URL de gracias", () => {
    expect(
      orderTrackingMetaData({
        consent: true,
        fbp: FBP,
        fbc: FBC,
        ip: "181.50.1.2",
        userAgent: "Mozilla/5.0",
        sourceUrl: "https://tienda.example/en/checkout/return?ref=9",
      }),
    ).toEqual([
      { key: ORDER_TRACKING_META.consent, value: "accepted" },
      { key: ORDER_TRACKING_META.fbp, value: FBP },
      { key: ORDER_TRACKING_META.fbc, value: FBC },
      { key: ORDER_TRACKING_META.ip, value: "181.50.1.2" },
      { key: ORDER_TRACKING_META.userAgent, value: "Mozilla/5.0" },
      {
        key: ORDER_TRACKING_META.sourceUrl,
        value: "https://tienda.example/en/checkout/return?ref=9",
      },
    ]);
  });

  it("omite lo que falta o no vale (IP desconocida, cookie mal formada)", () => {
    expect(
      orderTrackingMetaData({ consent: true, fbp: "basura", ip: "unknown" }),
    ).toEqual([{ key: ORDER_TRACKING_META.consent, value: "accepted" }]);
  });
});

describe("readOrderTracking", () => {
  it("lee lo guardado", () => {
    const meta = orderTrackingMetaData({ consent: true, fbp: FBP, ip: "1.2.3.4" });
    expect(readOrderTracking(meta)).toEqual({
      consent: true,
      fbp: FBP,
      fbc: undefined,
      ip: "1.2.3.4",
      userAgent: undefined,
      sourceUrl: undefined,
    });
  });

  it("un pedido sin constancia (anterior a esto) cuenta como sin consentimiento", () => {
    expect(readOrderTracking([]).consent).toBe(false);
    expect(readOrderTracking(undefined).consent).toBe(false);
  });
});
