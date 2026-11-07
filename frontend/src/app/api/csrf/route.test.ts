import { describe, expect, it, vi } from "vitest";

vi.mock("@/lib/security/csrf", () => ({
  issueCsrfToken: vi.fn().mockResolvedValue("token-de-prueba"),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { info: vi.fn() },
}));

import { GET } from "./route";

describe("GET /api/csrf", () => {
  it("devuelve el token y no deja que se cachee: es por navegador y fija una cookie", async () => {
    const res = await GET();

    expect(await res.json()).toEqual({ csrfToken: "token-de-prueba" });
    expect(res.headers.get("Cache-Control")).toBe("private, no-store");
  });
});
