import { beforeEach, describe, expect, it, vi } from "vitest";
import { CO_PACK } from "../../../tests/helpers/geo-pack";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { logReadFailure } from "@/lib/observability/read-failure";
import { getGeoPack, GENERIC_PACK } from "./index";

vi.mock("react", async (importOriginal) => ({
  ...(await importOriginal<typeof import("react")>()),
  cache: <T>(fn: T) => fn,
}));
vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));
vi.mock("@/lib/observability/read-failure", () => ({ logReadFailure: vi.fn() }));
vi.mock("@/lib/http/fetch-retry", () => ({
  fetchWithRetry: async (request: () => Promise<Response>) => request(),
}));

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status });

beforeEach(() => {
  vi.mocked(wpFetch).mockReset();
  vi.mocked(logReadFailure).mockReset();
});

describe("getGeoPack", () => {
  it("lee el paquete de WordPress con el tag ISR `geo`", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json(CO_PACK));

    expect(await getGeoPack()).toEqual(CO_PACK);
    expect(wpFetch).toHaveBeenCalledWith("/wp-json/hwe/v1/geo", {
      next: { revalidate: 300, tags: ["geo"] },
    });
    expect(logReadFailure).not.toHaveBeenCalled();
  });

  it("si WordPress responde un error, cae al genérico y lo registra", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({}, 503));

    expect(await getGeoPack()).toBe(GENERIC_PACK);
    expect(logReadFailure).toHaveBeenCalledWith(
      "geo.read_failed",
      "HTTP 503",
      expect.anything(),
    );
  });

  it("si la respuesta no cumple el contrato, cae al genérico y lo registra", async () => {
    vi.mocked(wpFetch).mockResolvedValue(json({ country: "co" }));

    expect(await getGeoPack()).toBe(GENERIC_PACK);
    expect(logReadFailure).toHaveBeenCalledWith(
      "geo.read_failed",
      "respuesta fuera de contrato",
      expect.anything(),
    );
  });

  it("si la red falla, cae al genérico y lo registra", async () => {
    const boom = new Error("ECONNREFUSED");
    vi.mocked(wpFetch).mockRejectedValue(boom);

    expect(await getGeoPack()).toBe(GENERIC_PACK);
    expect(logReadFailure).toHaveBeenCalledWith(
      "geo.read_failed",
      boom,
      expect.anything(),
    );
  });
});
