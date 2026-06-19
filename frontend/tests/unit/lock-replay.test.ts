import { describe, it, expect, beforeEach, afterEach } from "vitest";
import { withLock } from "@/lib/security/lock";
import { markEventOnce } from "@/lib/security/replay";

describe("withLock sin Redis (degradación)", () => {
  let saved: string | undefined;
  beforeEach(() => {
    saved = process.env.REDIS_URL;
    delete process.env.REDIS_URL;
  });
  afterEach(() => {
    if (saved !== undefined) process.env.REDIS_URL = saved;
  });

  it("ejecuta la función crítica y devuelve su resultado", async () => {
    let ran = 0;
    const result = await withLock("k", async () => {
      ran += 1;
      return 42;
    });
    expect(result).toBe(42);
    expect(ran).toBe(1);
  });
});

describe("markEventOnce sin Redis (no puede deduplicar → fail-open)", () => {
  let saved: string | undefined;
  beforeEach(() => {
    saved = process.env.REDIS_URL;
    delete process.env.REDIS_URL;
  });
  afterEach(() => {
    if (saved !== undefined) process.env.REDIS_URL = saved;
  });

  it("devuelve true (procede) cuando no hay almacén", async () => {
    expect(await markEventOnce("wc", '{"id":1}')).toBe(true);
  });
});
