import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { getClientIp } from "@/lib/http/request-ip";

function req(headers: Record<string, string>): Request {
  return new Request("https://app.example/api/x", { headers });
}

describe("getClientIp (anti-spoofing X-Forwarded-For)", () => {
  let saved: string | undefined;
  beforeEach(() => {
    saved = process.env.TRUSTED_PROXY_COUNT;
  });
  afterEach(() => {
    if (saved === undefined) delete process.env.TRUSTED_PROXY_COUNT;
    else process.env.TRUSTED_PROXY_COUNT = saved;
  });

  it("con 1 proxy de confianza toma la IP que añadió el proxy (no la falsificable)", () => {
    process.env.TRUSTED_PROXY_COUNT = "1";
    // El cliente intenta falsear "1.1.1.1"; Caddy añade la real "9.9.9.9" al final.
    expect(getClientIp(req({ "x-forwarded-for": "1.1.1.1, 9.9.9.9" }))).toBe("9.9.9.9");
  });

  it("con 2 proxies de confianza toma la IP a 2 posiciones del final", () => {
    process.env.TRUSTED_PROXY_COUNT = "2";
    expect(getClientIp(req({ "x-forwarded-for": "1.1.1.1, 8.8.8.8, 9.9.9.9" }))).toBe("8.8.8.8");
  });

  it("sin XFF usa x-real-ip", () => {
    process.env.TRUSTED_PROXY_COUNT = "1";
    expect(getClientIp(req({ "x-real-ip": "5.5.5.5" }))).toBe("5.5.5.5");
  });

  it("con 0 proxies prefiere x-real-ip sobre XFF (XFF no fiable)", () => {
    process.env.TRUSTED_PROXY_COUNT = "0";
    expect(getClientIp(req({ "x-forwarded-for": "1.1.1.1", "x-real-ip": "5.5.5.5" }))).toBe("5.5.5.5");
  });

  it("devuelve 'unknown' sin cabeceras", () => {
    expect(getClientIp(req({}))).toBe("unknown");
  });
});
