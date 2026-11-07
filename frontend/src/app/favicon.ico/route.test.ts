import { describe, expect, it, vi } from "vitest";

const resolveIconResponse = vi.fn(
  async () => new Response("png", { headers: { "Content-Type": "image/png" } }),
);

vi.mock("@/lib/config", () => ({ getSiteConfig: async () => ({ brand: {} }) }));
vi.mock("@/lib/seo/brand-image", () => ({
  resolveIconResponse: (...args: unknown[]) =>
    (resolveIconResponse as (...a: unknown[]) => Promise<Response>)(...args),
}));

import { GET } from "./route";

describe("GET /favicon.ico", () => {
  it("sirve el icono de marca (200) en lugar de un 404", async () => {
    const res = await GET();
    expect(res.status).toBe(200);
    expect(res.headers.get("Content-Type")).toBe("image/png");
    expect(resolveIconResponse).toHaveBeenCalledWith({ brand: {} }, 48);
  });
});
