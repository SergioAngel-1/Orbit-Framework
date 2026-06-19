import { describe, it, expect, beforeEach, afterEach } from "vitest";
import {
  isValidIdempotencyKey,
  reserveIdempotencyKey,
} from "@/lib/security/idempotency";

describe("isValidIdempotencyKey", () => {
  it("acepta claves de longitud razonable", () => {
    expect(isValidIdempotencyKey("a".repeat(8))).toBe(true);
    expect(isValidIdempotencyKey("a".repeat(200))).toBe(true);
  });
  it("rechaza null, muy cortas o muy largas", () => {
    expect(isValidIdempotencyKey(null)).toBe(false);
    expect(isValidIdempotencyKey("short")).toBe(false);
    expect(isValidIdempotencyKey("a".repeat(201))).toBe(false);
  });
});

describe("reserveIdempotencyKey sin Redis", () => {
  let saved: string | undefined;
  beforeEach(() => {
    saved = process.env.REDIS_URL;
    delete process.env.REDIS_URL;
  });
  afterEach(() => {
    if (saved !== undefined) process.env.REDIS_URL = saved;
  });

  it("degrada a 'proceed' cuando no hay Redis (no garantiza idempotencia)", async () => {
    const state = await reserveIdempotencyKey("key-abcdefgh");
    expect(state.status).toBe("proceed");
  });
});
