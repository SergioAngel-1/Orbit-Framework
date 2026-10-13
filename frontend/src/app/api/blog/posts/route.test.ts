import { beforeEach, describe, expect, it, vi } from "vitest";

const getPosts = vi.fn();
vi.mock("@/lib/blog/posts", () => ({ getPosts: (...a: unknown[]) => getPosts(...a) }));
const rateLimit = vi.fn();
vi.mock("@/lib/security/rate-limit", () => ({
  rateLimit: (...a: unknown[]) => rateLimit(...a),
}));
vi.mock("@/lib/http/request-ip", () => ({ getClientIp: () => "203.0.113.9" }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));
vi.mock("@/i18n/routing", () => ({
  routing: { locales: ["es", "en"], defaultLocale: "es" },
}));

import { GET } from "./route";

const request = (query: string) => new Request(`https://x.test/api/blog/posts${query}`);

beforeEach(() => {
  getPosts.mockReset();
  getPosts.mockResolvedValue({
    posts: [],
    pageInfo: { hasNextPage: false, endCursor: null },
  });
  rateLimit.mockReset();
  rateLimit.mockResolvedValue({ success: true, retryAfter: 0 });
});

describe("GET /api/blog/posts", () => {
  it("pasa los filtros y una etiqueta con comas como lista", async () => {
    await GET(
      request("?tag=a,b&author=ana&category=guias&after=YXJyYXk6NQ%3D%3D&locale=en"),
    );

    expect(getPosts).toHaveBeenCalledWith(
      expect.objectContaining({
        tag: ["a", "b"],
        author: "ana",
        category: "guias",
        after: "YXJyYXk6NQ==",
        locale: "en",
      }),
    );
  });

  it("`first` va acotado: el cliente no decide cuántas entradas pide a WordPress", async () => {
    await GET(request("?first=100000"));
    expect(getPosts.mock.calls[0]![0].first).toBeLessThanOrEqual(24);

    await GET(request("?first=-5"));
    expect(getPosts.mock.calls[1]![0].first).toBe(9);

    await GET(request("?first=abc"));
    expect(getPosts.mock.calls[2]![0].first).toBe(9);
  });

  it("un cursor que no es un cursor de WPGraphQL se descarta", async () => {
    await GET(request("?after=" + encodeURIComponent("'; DROP TABLE x;--")));
    expect(getPosts.mock.calls[0]![0].after).toBeUndefined();
  });

  it("un idioma que no es del sitio cae al canónico", async () => {
    await GET(request("?locale=zz"));
    expect(getPosts.mock.calls[0]![0].locale).toBe("es");
  });

  it("acota la longitud de la búsqueda y de las etiquetas", async () => {
    await GET(
      request("?search=" + "a".repeat(500) + "&tag=" + Array(50).fill("t").join(",")),
    );
    const args = getPosts.mock.calls[0]![0];
    expect(args.search.length).toBeLessThanOrEqual(100);
    expect(args.tag.length).toBeLessThanOrEqual(10);
  });

  it("la búsqueda tiene límite por IP; el listado sin búsqueda no", async () => {
    rateLimit.mockResolvedValue({ success: false, retryAfter: 30 });

    const searched = await GET(request("?search=lino"));
    expect(searched.status).toBe(429);
    expect(searched.headers.get("Retry-After")).toBe("30");
    expect(getPosts).not.toHaveBeenCalled();

    const plain = await GET(request(""));
    expect(plain.status).toBe(200);
  });

  it("es cacheable: la respuesta es la misma para todo el mundo", async () => {
    const res = await GET(request(""));
    expect(res.headers.get("Cache-Control")).toMatch(/public/);
  });

  it("un fallo de WordPress es 502 sin filtrar el mensaje", async () => {
    getPosts.mockRejectedValue(new Error("ECONNREFUSED http://wordpress:80"));
    const res = await GET(request(""));
    expect(res.status).toBe(502);
    expect(JSON.stringify(await res.json())).not.toContain("wordpress");
  });
});
