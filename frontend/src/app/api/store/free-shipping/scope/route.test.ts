import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSiteConfig } from "@/lib/config";
import { getFreeShippingScope } from "@/lib/shipping";
import { GET } from "./route";

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/shipping", () => ({ getFreeShippingScope: vi.fn() }));

const get = (query = "") =>
  GET(new Request(`https://tienda.example/api/store/free-shipping/scope${query}`));

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getSiteConfig).mockResolvedValue({ modules: { shipping: true } } as never);
  vi.mocked(getFreeShippingScope).mockResolvedValue({
    categories: [],
    products: [],
    cities: ["Madrid"],
  });
});

describe("GET /api/store/free-shipping/scope", () => {
  it("con el módulo apagado responde 404", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue({ modules: {} } as never);

    expect((await get()).status).toBe(404);
    expect(getFreeShippingScope).not.toHaveBeenCalled();
  });

  it("sirve el alcance en el idioma pedido, cacheable", async () => {
    const res = await get("?locale=en");

    expect(res.status).toBe(200);
    expect((await res.json()).cities).toEqual(["Madrid"]);
    expect(getFreeShippingScope).toHaveBeenCalledWith("en", expect.anything());
    expect(res.headers.get("Cache-Control")).toContain("s-maxage=300");
  });

  it("un idioma que el sitio no tiene cae al idioma por defecto", async () => {
    await get("?locale=xx");
    await get();

    expect(vi.mocked(getFreeShippingScope).mock.calls.map((c) => c[0])).toEqual([
      "es",
      "es",
    ]);
  });
});
