import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// `next/og` no se ejecuta en Vitest: `renderBrandIcon` se sustituye por un marcador.
vi.mock("next/og", () => ({
  ImageResponse: class {
    constructor(public element: unknown) {}
  },
}));

import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import { resolveIconResponse, wordpressSiteIconResponse } from "./brand-image";

function config(iconUrl: string | null) {
  const c = structuredClone(CONFIG_DEFAULTS);
  c.brand.icon_url = iconUrl;
  return c;
}

const png = (bytes = 200, type = "image/png") =>
  new Response(new Uint8Array(bytes), { headers: { "content-type": type } });

beforeEach(() => {
  vi.stubEnv("WORDPRESS_PUBLIC_URL", "https://wp.mitienda.example");
  vi.stubEnv("WORDPRESS_INTERNAL_API_URL", "http://wordpress:80/graphql");
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("wordpressSiteIconResponse", () => {
  it("sin icono del sitio, nada", async () => {
    expect(await wordpressSiteIconResponse(config(null))).toBeNull();
  });

  it("pide el icono por el origen interno y lo sirve con su tipo", async () => {
    const fn = vi.fn().mockResolvedValue(png());
    vi.stubGlobal("fetch", fn);

    const res = await wordpressSiteIconResponse(
      config("https://wp.mitienda.example/wp-content/uploads/icon.png"),
    );

    expect(fn.mock.calls[0]![0]).toBe("http://wordpress/wp-content/uploads/icon.png");
    expect(res!.headers.get("content-type")).toBe("image/png");
    expect(res!.headers.get("cache-control")).toContain("public");
  });

  it("no hace de proxy a un host ajeno aunque la config lo diga", async () => {
    const fn = vi.fn().mockResolvedValue(png());
    vi.stubGlobal("fetch", fn);

    for (const url of [
      "https://evil.example/wp-content/uploads/icon.png",
      "http://169.254.169.254/latest/meta-data",
      "http://127.0.0.1:6379/",
    ]) {
      expect(await wordpressSiteIconResponse(config(url))).toBeNull();
    }
    expect(fn).not.toHaveBeenCalled();
  });

  it("descarta un SVG, un tipo no admitido y un archivo desmesurado", async () => {
    const url = "https://wp.mitienda.example/wp-content/uploads/icon.png";
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(png(100, "image/svg+xml")));
    expect(await wordpressSiteIconResponse(config(url))).toBeNull();

    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(png(100, "text/html")));
    expect(await wordpressSiteIconResponse(config(url))).toBeNull();

    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(png(2_000_000)));
    expect(await wordpressSiteIconResponse(config(url))).toBeNull();
  });

  it("un WordPress inalcanzable o un 404 no rompe: null", async () => {
    const url = "https://wp.mitienda.example/wp-content/uploads/icon.png";
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("fetch failed")));
    expect(await wordpressSiteIconResponse(config(url))).toBeNull();

    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(new Response("", { status: 404 })),
    );
    expect(await wordpressSiteIconResponse(config(url))).toBeNull();
  });
});

describe("resolveIconResponse", () => {
  it("sin icono utilizable cae al generado desde la marca", async () => {
    const res = await resolveIconResponse(config(null), 32);
    expect(res).toBeDefined();
    expect(res.constructor.name).not.toBe("Response");
  });
});
