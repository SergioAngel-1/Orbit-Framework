import { describe, expect, it } from "vitest";
import { analyticsCspHosts } from "../../csp-analytics.mjs";

// La CSP fija el MÁXIMO que el navegador deja cargar; qué se carga lo decide
// AnalyticsProvider en runtime. Un despliegue estrecha ese máximo con
// `CSP_ANALYTICS_PROVIDERS`.

describe("analyticsCspHosts", () => {
  it("por defecto autoriza todos los proveedores que el framework sabe cargar", () => {
    const hosts = analyticsCspHosts({});

    expect(hosts.script).toEqual(
      expect.arrayContaining([
        "https://www.googletagmanager.com",
        "https://connect.facebook.net",
        "https://analytics.tiktok.com",
        "https://*.hs-scripts.com",
        "https://plausible.io",
      ]),
    );
  });

  it("con una lista, solo los hosts de esos proveedores", () => {
    const hosts = analyticsCspHosts({ CSP_ANALYTICS_PROVIDERS: "meta" });

    expect(hosts.script).toEqual(["https://connect.facebook.net"]);
    expect(hosts.connect).toEqual(["https://www.facebook.com"]);
    expect(hosts.img).toEqual(["https://www.facebook.com"]);
  });

  it("`none` no autoriza ninguno", () => {
    expect(analyticsCspHosts({ CSP_ANALYTICS_PROVIDERS: "none" })).toEqual({
      script: [],
      connect: [],
      img: [],
    });
  });

  it("ignora nombres que no conoce y no repite hosts (GA4 y GTM comparten los de Google)", () => {
    const hosts = analyticsCspHosts({ CSP_ANALYTICS_PROVIDERS: "ga4, gtm, nada" });

    expect(hosts.script).toEqual(["https://www.googletagmanager.com"]);
    expect(new Set(hosts.connect).size).toBe(hosts.connect.length);
  });

  it("Plausible usa su propio host (autoalojado) y solo su origen, sin ruta", () => {
    const hosts = analyticsCspHosts({
      CSP_ANALYTICS_PROVIDERS: "plausible",
      PLAUSIBLE_HOST: "https://stats.tienda.example/js/script.js",
    });

    expect(hosts.script).toEqual(["https://stats.tienda.example"]);
    expect(hosts.connect).toEqual(["https://stats.tienda.example"]);
  });

  it("un host de Plausible que no es una URL no abre nada", () => {
    const hosts = analyticsCspHosts({
      CSP_ANALYTICS_PROVIDERS: "plausible",
      PLAUSIBLE_HOST: "no-es-una-url",
    });

    expect(hosts.script).toEqual([]);
  });
});
