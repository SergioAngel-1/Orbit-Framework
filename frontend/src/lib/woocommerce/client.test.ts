import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// ============================================================================
//  wcFetch: WooCommerce solo acepta Basic con consumer key/secret cuando
//  `is_ssl()` es true. El BFF habla con WordPress por HTTP en la red interna
//  (`http://wordpress:80`), así que sin `X-Forwarded-Proto: https` TODA llamada a
//  wc/v3 (pedidos, cuenta, direcciones, reseñas y, sobre todo, marcar pagado un
//  pedido desde el webhook de la pasarela) respondía 401 `woocommerce_rest_cannot_view`
//  mientras GraphQL y la Store API (sin ck/cs) seguían funcionando. Los tests
//  simulaban wcFetch entero y no lo veían: se encontró levantando la pila real.
// ============================================================================

const fetchMock = vi.fn();

async function loadClient(apiUrl?: string) {
  vi.resetModules();
  vi.stubEnv("WC_CONSUMER_KEY", "ck_test");
  vi.stubEnv("WC_CONSUMER_SECRET", "cs_test");
  if (apiUrl === undefined) delete process.env.WC_API_URL;
  else vi.stubEnv("WC_API_URL", apiUrl);
  vi.stubEnv("HWE_REVALIDATION_SECRET", "secreto-interno");
  return import("./client");
}

function sentHeaders(): Headers {
  return new Headers((fetchMock.mock.calls[0]![1] as RequestInit).headers);
}

beforeEach(() => {
  fetchMock.mockReset();
  fetchMock.mockResolvedValue(new Response("{}", { status: 200 }));
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("wcFetchPage: totales de una colección", () => {
  it("devuelve los elementos y los totales de las cabeceras", async () => {
    fetchMock.mockResolvedValue(
      new Response('[{"id":1}]', {
        status: 200,
        headers: { "X-WP-Total": "57", "X-WP-TotalPages": "3" },
      }),
    );
    const { wcFetchPage } = await loadClient("http://wordpress:80/wp-json/wc/v3");

    await expect(wcFetchPage("/orders", { query: { page: 2 } })).resolves.toEqual({
      items: [{ id: 1 }],
      total: 57,
      totalPages: 3,
    });
    expect(String(fetchMock.mock.calls[0]![0])).toContain("page=2");
  });

  it("sin cabeceras, los totales describen solo lo recibido", async () => {
    fetchMock.mockResolvedValue(new Response('[{"id":1},{"id":2}]', { status: 200 }));
    const { wcFetchPage } = await loadClient("http://wordpress:80/wp-json/wc/v3");

    await expect(wcFetchPage("/orders")).resolves.toMatchObject({ total: 2, totalPages: 1 });
  });

  it("wcFetch sigue devolviendo solo el cuerpo", async () => {
    fetchMock.mockResolvedValue(
      new Response('[{"id":1}]', { status: 200, headers: { "X-WP-Total": "57" } }),
    );
    const { wcFetch } = await loadClient("http://wordpress:80/wp-json/wc/v3");

    await expect(wcFetch("/orders")).resolves.toEqual([{ id: 1 }]);
  });
});

describe("wcFetch: salto interno por HTTP", () => {
  it("con la URL de WooCommerce en http:// pide a WordPress que trate la petición como HTTPS", async () => {
    const { wcFetch } = await loadClient("http://wordpress:80/wp-json/wc/v3");

    await wcFetch("/orders/1");

    expect(sentHeaders().get("x-forwarded-proto")).toBe("https");
  });

  it("con la URL por defecto (http://wordpress) también", async () => {
    const { wcFetch } = await loadClient();

    await wcFetch("/orders/1");

    expect(sentHeaders().get("x-forwarded-proto")).toBe("https");
  });

  it("con https:// no añade nada: ya es HTTPS de verdad", async () => {
    const { wcFetch } = await loadClient("https://wp.interno.example/wp-json/wc/v3");

    await wcFetch("/orders/1");

    expect(sentHeaders().has("x-forwarded-proto")).toBe(false);
  });

  it("sigue mandando las credenciales Basic y el secreto interno", async () => {
    const { wcFetch } = await loadClient("http://wordpress:80/wp-json/wc/v3");

    await wcFetch("/orders/1");

    expect(sentHeaders().get("authorization")).toBe(
      `Basic ${Buffer.from("ck_test:cs_test").toString("base64")}`,
    );
    expect(sentHeaders().get("x-hwe-internal-secret")).toBe("secreto-interno");
  });
});
