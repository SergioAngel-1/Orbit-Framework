import { afterEach, describe, expect, it, vi } from "vitest";
import {
  getWordPressInternalOrigin,
  isSvgAsset,
  resolveCmsAssetUrl,
  wpMediaInternalUrl,
  wpUploadsUrl,
} from "./media";

afterEach(() => {
  vi.unstubAllEnvs();
});

function env(values: Record<string, string>) {
  for (const key of ["WORDPRESS_PUBLIC_URL", "WORDPRESS_INTERNAL_API_URL"]) {
    vi.stubEnv(key, values[key] ?? "");
  }
}

describe("getWordPressInternalOrigin", () => {
  it("sale de WORDPRESS_INTERNAL_API_URL", () => {
    env({ WORDPRESS_INTERNAL_API_URL: "http://wordpress:80/graphql" });
    expect(getWordPressInternalOrigin()).toBe("http://wordpress");
  });

  it("sin configurar, null", () => {
    env({});
    expect(getWordPressInternalOrigin()).toBeNull();
  });
});

describe("wpUploadsUrl / resolveCmsAssetUrl", () => {
  it("compone la URL pública de la medioteca desde cualquiera de las tres formas", () => {
    env({ WORDPRESS_PUBLIC_URL: "https://wp.mitienda.example" });
    const expected = "https://wp.mitienda.example/wp-content/uploads/2026/07/foto.png";
    for (const input of [
      "2026/07/foto.png",
      "/wp-content/uploads/2026/07/foto.png",
      "wp-content/uploads/2026/07/foto.png",
    ]) {
      expect(wpUploadsUrl(input)).toBe(expected);
    }
  });

  it("hace pública una ruta relativa y deja intactas las absolutas y los data:", () => {
    env({ WORDPRESS_PUBLIC_URL: "https://wp.mitienda.example" });
    expect(resolveCmsAssetUrl("/wp-content/uploads/a.png")).toBe(
      "https://wp.mitienda.example/wp-content/uploads/a.png",
    );
    expect(resolveCmsAssetUrl("https://cdn.example/a.png")).toBe(
      "https://cdn.example/a.png",
    );
    expect(resolveCmsAssetUrl("data:image/png;base64,AA==")).toBe(
      "data:image/png;base64,AA==",
    );
    expect(resolveCmsAssetUrl("/img/logo.png")).toBe("/img/logo.png");
  });
});

describe("wpMediaInternalUrl", () => {
  it("reescribe una URL de medios del WordPress público al origen interno", () => {
    env({
      WORDPRESS_PUBLIC_URL: "http://localhost:8080",
      WORDPRESS_INTERNAL_API_URL: "http://wordpress:80/graphql",
    });
    expect(
      wpMediaInternalUrl("http://localhost:8080/wp-content/uploads/a/b.png?x=1"),
    ).toBe("http://wordpress/wp-content/uploads/a/b.png?x=1");
  });

  it("también una ruta relativa de uploads", () => {
    env({
      WORDPRESS_PUBLIC_URL: "http://localhost:8080",
      WORDPRESS_INTERNAL_API_URL: "http://wordpress:80/graphql",
    });
    expect(wpMediaInternalUrl("/wp-content/uploads/a.png")).toBe(
      "http://wordpress/wp-content/uploads/a.png",
    );
  });

  it("no-op si el origen interno coincide con el público o no existe", () => {
    const src = "http://localhost:8080/wp-content/uploads/a.png";
    env({
      WORDPRESS_PUBLIC_URL: "http://localhost:8080",
      WORDPRESS_INTERNAL_API_URL: "http://localhost:8080/graphql",
    });
    expect(wpMediaInternalUrl(src)).toBe(src);
    env({ WORDPRESS_PUBLIC_URL: "http://localhost:8080" });
    expect(wpMediaInternalUrl(src)).toBe(src);
  });

  it("nunca desvía a la red interna una URL de OTRO host con ruta de uploads (SSRF)", () => {
    env({
      WORDPRESS_PUBLIC_URL: "https://wp.mitienda.example",
      WORDPRESS_INTERNAL_API_URL: "http://wordpress:80/graphql",
    });
    for (const src of [
      "https://evil.example/wp-content/uploads/a.png",
      "http://169.254.169.254/wp-content/uploads/a.png",
      "https://wp.mitienda.example.evil.example/wp-content/uploads/a.png",
    ]) {
      expect(wpMediaInternalUrl(src)).toBe(src);
    }
  });

  it("deja intactas las rutas que no son uploads, las relativas y los data:", () => {
    env({
      WORDPRESS_PUBLIC_URL: "http://localhost:8080",
      WORDPRESS_INTERNAL_API_URL: "http://wordpress:80/graphql",
    });
    for (const src of [
      "http://localhost:8080/otra/ruta.png",
      "/img/logo.png",
      "data:image/png;base64,AA==",
    ]) {
      expect(wpMediaInternalUrl(src)).toBe(src);
    }
    expect(wpMediaInternalUrl("   ")).toBe("");
  });
});

describe("isSvgAsset", () => {
  it("reconoce un SVG con query o fragmento", () => {
    expect(isSvgAsset("https://x.test/a.SVG?v=2")).toBe(true);
    expect(isSvgAsset("/a.png")).toBe(false);
  });
});
