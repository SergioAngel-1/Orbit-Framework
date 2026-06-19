import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { assertAllowedOrigin } from "@/lib/security/origin";

function req(headers: Record<string, string>): Request {
  return new Request("https://app.example/api/x", { method: "POST", headers });
}

describe("assertAllowedOrigin", () => {
  let saved: string | undefined;
  beforeEach(() => {
    saved = process.env.ALLOWED_ORIGIN;
    process.env.ALLOWED_ORIGIN = "https://app.example";
  });
  afterEach(() => {
    if (saved === undefined) delete process.env.ALLOWED_ORIGIN;
    else process.env.ALLOWED_ORIGIN = saved;
  });

  it("acepta el Origin permitido", () => {
    expect(assertAllowedOrigin(req({ origin: "https://app.example" }))).toBe(true);
  });

  it("rechaza un Origin distinto", () => {
    expect(assertAllowedOrigin(req({ origin: "https://evil.example" }))).toBe(false);
  });

  it("usa Referer como respaldo cuando no hay Origin", () => {
    expect(assertAllowedOrigin(req({ referer: "https://app.example/login" }))).toBe(true);
    expect(assertAllowedOrigin(req({ referer: "https://evil.example/x" }))).toBe(false);
  });

  it("rechaza una escritura sin Origin ni Referer", () => {
    expect(assertAllowedOrigin(req({}))).toBe(false);
  });

  it("no restringe si ALLOWED_ORIGIN no está configurado (dev)", () => {
    delete process.env.ALLOWED_ORIGIN;
    expect(assertAllowedOrigin(req({}))).toBe(true);
  });
});
