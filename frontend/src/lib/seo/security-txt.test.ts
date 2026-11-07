import { describe, expect, it } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import type { SiteConfig } from "@/lib/config/types";
import { buildSecurityTxt } from "./security-txt";

function config(email: string): SiteConfig {
  const c = structuredClone(CONFIG_DEFAULTS);
  c.brand.url = "https://mitienda.example/";
  c.legal.email = email;
  return c;
}

const NOW = new Date("2026-11-07T15:30:00Z");

describe("buildSecurityTxt (RFC 9116)", () => {
  it("lleva Contact, Expires futuro y Canonical con el dominio real", () => {
    const body = buildSecurityTxt(config("seguridad@mitienda.example"), NOW)!;
    expect(body).toContain("Contact: mailto:seguridad@mitienda.example\n");
    expect(body).toContain("Canonical: https://mitienda.example/.well-known/security.txt\n");
    const expires = /^Expires: (.+)$/m.exec(body)![1]!;
    expect(expires).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
    const days = (Date.parse(expires) - NOW.getTime()) / 86_400_000;
    expect(days).toBeGreaterThan(150);
    expect(days).toBeLessThan(365);
    expect(body.endsWith("\n")).toBe(true);
  });

  it("sin correo válido o sin URL no publica nada", () => {
    expect(buildSecurityTxt(config(""), NOW)).toBeNull();
    expect(buildSecurityTxt(config("no es un correo"), NOW)).toBeNull();
    const noUrl = config("a@b.co");
    noUrl.brand.url = "";
    expect(buildSecurityTxt(noUrl, NOW)).toBeNull();
  });

  it("no puede inyectar campos con saltos de línea en el correo", () => {
    expect(buildSecurityTxt(config("a@b.co\nPolicy: https://evil.example"), NOW)).toBeNull();
  });
});
