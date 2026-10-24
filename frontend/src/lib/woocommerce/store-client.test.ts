import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// ============================================================================
//  storeFetch: la Store API de WooCommerce publica los datos del error en
//  `data` (por ejemplo `days_left`, `level_slug` u `opens_at` de una membresía).
//  El cliente los conserva para que cada lector aplique su lista blanca; el
//  mensaje HTTP no basta para explicar el rechazo al cliente.
// ============================================================================

const fetchMock = vi.fn();

async function loadStoreClient() {
  vi.resetModules();
  vi.stubEnv("WC_STORE_API_URL", "http://wordpress:80/wp-json/wc/store/v1");
  return import("./store-client");
}

beforeEach(() => {
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("storeFetch con errores de la Store API", () => {
  it("conserva el código y los datos estructurados del error", async () => {
    const payload = {
      code: "hwe_membership_early_access",
      message: "El producto todavía no está disponible.",
      data: {
        status: 409,
        reason: "membership_early_access",
        level_slug: "nivel-a",
        opens_at: "2026-11-01T00:00:00Z",
      },
    };
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify(payload), {
        status: 409,
        headers: { "content-type": "application/json" },
      }),
    );
    const { storeFetch } = await loadStoreClient();

    await expect(storeFetch("/checkout", { method: "POST" })).rejects.toMatchObject({
      status: 409,
      code: payload.code,
      data: payload.data,
    });
  });

  it("sin datos en el cuerpo del error no inventa ninguno", async () => {
    fetchMock.mockResolvedValue(
      new Response(
        JSON.stringify({ code: "wc_store_network", message: "Sin cuerpo." }),
        {
          status: 400,
          headers: { "content-type": "application/json" },
        },
      ),
    );
    const { storeFetch } = await loadStoreClient();

    await expect(storeFetch("/cart")).rejects.toMatchObject({
      status: 400,
      code: "wc_store_network",
      data: undefined,
    });
  });
});
