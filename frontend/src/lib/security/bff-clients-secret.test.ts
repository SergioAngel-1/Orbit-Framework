import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { SignJWT } from "jose";
import { AUTH_COOKIE, REFRESH_COOKIE } from "@/lib/auth/constants";
import { fetchGraphQL } from "@/lib/graphql-client";
import { wcFetch } from "@/lib/woocommerce/client";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { fetchRemoteConfig } from "@/lib/config/remote";
import { getBanner } from "@/lib/banners";
import { GET as health } from "@/app/api/health/route";

// ============================================================================
//  Cada cliente del BFF presenta el secreto interno a WordPress.
//
//  Con `hwe-bff-only`, WordPress responde 404 a GraphQL, la Store API y
//  `hwe/v1/auth|consent` sin `X-HWE-Internal-Secret`. Los tests de cada cliente
//  simulan `fetch`, así que una cabecera olvidada no los rompería: este lo
//  comprueba cliente por cliente. (Que NO haya llamadas por otra vía lo garantiza
//  `wordpress-calls.test.ts`.)
// ============================================================================

vi.mock("@/lib/redis/client", () => ({ getRedis: vi.fn(() => null) }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const SECRET = "secreto-interno-de-prueba";
const HEADER = "X-HWE-Internal-Secret";

const saved: Record<string, string | undefined> = {};
const ENV: Record<string, string> = {
  HWE_REVALIDATION_SECRET: SECRET,
  WORDPRESS_INTERNAL_API_URL: "http://wp.interno/graphql",
  WC_API_URL: "http://wp.interno/wp-json/wc/v3",
  WC_STORE_API_URL: "http://wp.interno/wp-json/wc/store/v1",
};

let fetchMock: ReturnType<typeof vi.fn>;

/** Cabeceras de la última llamada, con las claves en minúsculas. */
function sentHeaders(call = 0): Record<string, string> {
  const init = fetchMock.mock.calls[call]![1] as RequestInit;
  return Object.fromEntries(
    Object.entries(init.headers as Record<string, string>).map(([k, v]) => [
      k.toLowerCase(),
      v,
    ]),
  );
}

beforeEach(() => {
  for (const [key, value] of Object.entries(ENV)) {
    saved[key] = process.env[key];
    process.env[key] = value;
  }
  fetchMock = vi.fn(
    async () =>
      new Response(JSON.stringify({ data: { ok: true } }), {
        status: 200,
        headers: { "content-type": "application/json" },
      }),
  );
  vi.stubGlobal("fetch", fetchMock);
});
afterEach(() => {
  vi.unstubAllGlobals();
  for (const key of Object.keys(ENV)) {
    if (saved[key] === undefined) delete process.env[key];
    else process.env[key] = saved[key];
  }
});

describe("los clientes presentan X-HWE-Internal-Secret", () => {
  it("GraphQL", async () => {
    await fetchGraphQL("query { ok }");

    expect(fetchMock.mock.calls[0]![0]).toBe("http://wp.interno/graphql");
    expect(sentHeaders()[HEADER.toLowerCase()]).toBe(SECRET);
  });

  it("GraphQL no tiene rama de navegador: ni con `window` definido usa otra URL ni omite el secreto", async () => {
    vi.stubGlobal("window", {});
    try {
      await fetchGraphQL("query { ok }");
    } finally {
      vi.unstubAllGlobals();
    }

    expect(fetchMock.mock.calls[0]![0]).toBe("http://wp.interno/graphql");
  });

  it("wc/v3", async () => {
    vi.resetModules();
    process.env.WC_CONSUMER_KEY = "ck_test";
    process.env.WC_CONSUMER_SECRET = "cs_test";
    const { wcFetch: freshWcFetch } = await import("@/lib/woocommerce/client");
    fetchMock.mockImplementation(async () => new Response("[]", { status: 200 }));

    await freshWcFetch("/orders");

    expect(sentHeaders()[HEADER.toLowerCase()]).toBe(SECRET);
    expect(sentHeaders().authorization).toMatch(/^Basic /);
    expect(typeof wcFetch).toBe("function");
  });

  it("Store API", async () => {
    vi.resetModules();
    const { storeFetch: freshStoreFetch } =
      await import("@/lib/woocommerce/store-client");
    fetchMock.mockImplementation(async () => new Response("{}", { status: 200 }));

    await freshStoreFetch("/cart", { cartToken: "tok" });

    expect(sentHeaders()[HEADER.toLowerCase()]).toBe(SECRET);
    expect(sentHeaders()["cart-token"]).toBe("tok");
    expect(typeof storeFetch).toBe("function");
  });

  it("config remota (hwe/v1/config)", async () => {
    await fetchRemoteConfig();

    expect(fetchMock.mock.calls[0]![0]).toBe("http://wp.interno/wp-json/hwe/v1/config");
    expect(sentHeaders()[HEADER.toLowerCase()]).toBe(SECRET);
  });

  it("banners (hwe-banners/v1)", async () => {
    await getBanner("carousel", "home", "es");

    expect(String(fetchMock.mock.calls[0]![0])).toContain(
      "/wp-json/hwe-banners/v1/banners/carousel?lang=es&location=home",
    );
    expect(sentHeaders()[HEADER.toLowerCase()]).toBe(SECRET);
  });

  it("sonda de salud", async () => {
    await health(new Request("https://tienda.example/api/health"));

    expect(sentHeaders()[HEADER.toLowerCase()]).toBe(SECRET);
  });
});

describe("el proxy (edge) presenta el secreto al refrescar el JWT", () => {
  async function caducado(): Promise<string> {
    const key = new TextEncoder().encode("secreto-de-prueba-suficientemente-largo");
    return new SignJWT({ data: { user: { id: "7" } } })
      .setProtectedHeader({ alg: "HS256" })
      .setIssuedAt(Math.floor(Date.now() / 1000) - 3600)
      .setExpirationTime(Math.floor(Date.now() / 1000) - 60)
      .sign(key);
  }

  it("la mutación de refresh lleva el secreto interno", async () => {
    vi.resetModules();
    vi.stubEnv("ALLOWED_ORIGIN", "https://tienda.example");
    const { proxy } = await import("@/proxy");
    const { NextRequest } = await import("next/server");
    fetchMock.mockImplementation(
      async () =>
        new Response(
          JSON.stringify({ data: { refreshJwtAuthToken: { authToken: "nuevo" } } }),
          {
            status: 200,
          },
        ),
    );

    await proxy(
      new NextRequest(new URL("/tienda", "https://tienda.example"), {
        method: "GET",
        headers: {
          cookie: `${AUTH_COOKIE}=${await caducado()}; ${REFRESH_COOKIE}=refresco`,
        },
      }),
    );
    vi.unstubAllEnvs();

    expect(fetchMock).toHaveBeenCalled();
    expect(fetchMock.mock.calls[0]![0]).toBe("http://wp.interno/graphql");
    expect(sentHeaders()[HEADER.toLowerCase()]).toBe(SECRET);
  });
});
