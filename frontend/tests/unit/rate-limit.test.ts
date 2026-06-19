import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { rateLimit } from "@/lib/security/rate-limit";

// En el entorno de test no hay REDIS_URL → getRedis() devuelve null. Así
// probamos los dos caminos de degradación: fail-open (no estricto) y el
// fallback EN MEMORIA (estricto).

describe("rateLimit sin Redis", () => {
  let saved: string | undefined;
  beforeEach(() => {
    saved = process.env.REDIS_URL;
    delete process.env.REDIS_URL;
  });
  afterEach(() => {
    if (saved !== undefined) process.env.REDIS_URL = saved;
  });

  it("no estricto: fail-open (siempre permite)", async () => {
    const id = `test-open-${Math.random()}`;
    for (let i = 0; i < 10; i++) {
      const r = await rateLimit(id, 3, 60);
      expect(r.success).toBe(true);
    }
  });

  it("estricto: limita usando el contador en memoria", async () => {
    const id = `test-strict-${Math.random()}`;
    const a = await rateLimit(id, 2, 60, { strict: true });
    const b = await rateLimit(id, 2, 60, { strict: true });
    const c = await rateLimit(id, 2, 60, { strict: true });
    expect(a.success).toBe(true);
    expect(b.success).toBe(true);
    expect(c.success).toBe(false); // se supera el límite de 2
    expect(c.retryAfter).toBeGreaterThan(0);
  });
});
