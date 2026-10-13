import { describe, expect, it } from "vitest";
import {
  cookieDomainCandidates,
  isTrackingCookie,
  trackingCookieNames,
} from "@/lib/analytics/tracking-cookies";

describe("isTrackingCookie", () => {
  it("reconoce las cookies de GA4, Meta, TikTok y HubSpot", () => {
    for (const name of [
      "_ga",
      "_ga_ABC123",
      "_gid",
      "_gat_UA-1",
      "_gcl_au",
      "_fbp",
      "_fbc",
      "_ttp",
      "_tt_enable_cookie",
      "ttcsid_XYZ",
      "__hstc",
      "__hssc",
      "__hssrc",
      "hubspotutk",
      "messagesUtk",
      "__hs_opt_out",
    ]) {
      expect(isTrackingCookie(name), name).toBe(true);
    }
  });

  it("no toca las técnicas del sitio", () => {
    for (const name of [
      "hwe_at",
      "hwe_rt",
      "hwe_csrf",
      "NEXT_LOCALE",
      "hwe_guest_order",
      "_gaz",
    ]) {
      expect(isTrackingCookie(name), name).toBe(false);
    }
  });
});

describe("trackingCookieNames", () => {
  it("extrae solo las de seguimiento de document.cookie", () => {
    expect(
      trackingCookieNames("hwe_csrf=a; _ga=GA1.1.2; _ga_X1=GS1; _fbp=fb.1; theme=dark"),
    ).toEqual(["_ga", "_ga_X1", "_fbp"]);
    expect(trackingCookieNames("")).toEqual([]);
  });
});

describe("cookieDomainCandidates", () => {
  it("prueba el host y cada dominio padre registrable", () => {
    expect(cookieDomainCandidates("www.siuesencias.com")).toEqual([
      null,
      "siuesencias.com",
      "www.siuesencias.com",
    ]);
  });

  it("en localhost o IP solo el host", () => {
    expect(cookieDomainCandidates("localhost")).toEqual([null]);
    expect(cookieDomainCandidates("127.0.0.1")).toEqual([null]);
  });
});
