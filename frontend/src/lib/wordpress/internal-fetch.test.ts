import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { wpBaseUrl, wpFetch, wpGraphqlUrl, wpIsConfigured } from "./internal-fetch";

// ============================================================================
//  Cliente de las llamadas del BFF a WordPress (todas menos GraphQL, wc/v3 y la
//  Store API, que tienen su propio cliente). Existe para que NINGUNA llamada
//  salga sin el secreto interno: con `hwe-bff-only` activo, una que se olvidara
//  recibiría un 404 de WordPress.
// ============================================================================

const saved = {
  url: process.env.WORDPRESS_INTERNAL_API_URL,
  secret: process.env.HWE_REVALIDATION_SECRET,
};
let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
  process.env.WORDPRESS_INTERNAL_API_URL = "http://wp.interno:8080/graphql";
  process.env.HWE_REVALIDATION_SECRET = "secreto-de-prueba";
  fetchMock = vi.fn(async () => new Response("{}"));
  vi.stubGlobal("fetch", fetchMock);
});
afterEach(() => {
  vi.unstubAllGlobals();
  for (const [key, value] of [
    ["WORDPRESS_INTERNAL_API_URL", saved.url],
    ["HWE_REVALIDATION_SECRET", saved.secret],
  ] as const) {
    if (value === undefined) delete process.env[key];
    else process.env[key] = value;
  }
});

describe("URLs", () => {
  it("la base es WORDPRESS_INTERNAL_API_URL sin /graphql y sin barra final", () => {
    expect(wpGraphqlUrl()).toBe("http://wp.interno:8080/graphql");
    expect(wpBaseUrl()).toBe("http://wp.interno:8080");
    expect(wpIsConfigured()).toBe(true);

    process.env.WORDPRESS_INTERNAL_API_URL = "http://wp.interno:8080/graphql/";
    expect(wpGraphqlUrl()).toBe("http://wp.interno:8080/graphql");
    expect(wpBaseUrl()).toBe("http://wp.interno:8080");
  });

  it("sin variable, usa el servicio de la red de Docker", () => {
    delete process.env.WORDPRESS_INTERNAL_API_URL;
    expect(wpIsConfigured()).toBe(false);

    expect(wpBaseUrl()).toBe("http://wordpress:80");
    expect(wpGraphqlUrl()).toBe("http://wordpress:80/graphql");
  });
});

describe("wpFetch", () => {
  it("llama a la base de WordPress + la ruta", async () => {
    await wpFetch("/wp-json/hwe/v1/auth/me");

    expect(fetchMock.mock.calls[0]![0]).toBe(
      "http://wp.interno:8080/wp-json/hwe/v1/auth/me",
    );
  });

  it("añade SIEMPRE el secreto interno y conserva el resto de cabeceras", async () => {
    await wpFetch("/wp-json/hwe/v1/auth/me", {
      headers: { Authorization: "Bearer jwt", "Content-Type": "application/json" },
    });

    const init = fetchMock.mock.calls[0]![1] as RequestInit;
    expect(init.headers).toEqual({
      Authorization: "Bearer jwt",
      "Content-Type": "application/json",
      "X-HWE-Internal-Secret": "secreto-de-prueba",
    });
  });

  it("sin cabeceras propias también lo añade", async () => {
    await wpFetch("/wp-json/hwe/v1/config");

    expect((fetchMock.mock.calls[0]![1] as RequestInit).headers).toEqual({
      "X-HWE-Internal-Secret": "secreto-de-prueba",
    });
  });

  it.each(["X-HWE-Internal-Secret", "x-hwe-internal-secret", "X-Hwe-Internal-SECRET"])(
    "el llamador no puede pisar el secreto con otra grafía (%s)",
    async (name) => {
      await wpFetch("/wp-json/hwe/v1/auth/me", { headers: { [name]: "otro" } });

      expect((fetchMock.mock.calls[0]![1] as RequestInit).headers).toEqual({
        "X-HWE-Internal-Secret": "secreto-de-prueba",
      });
    },
  );

  it("acepta las cabeceras como Headers o lista de pares", async () => {
    await wpFetch("/a", { headers: new Headers({ Authorization: "Bearer x" }) });
    await wpFetch("/b", { headers: [["Authorization", "Bearer y"]] });

    const first = (fetchMock.mock.calls[0]![1] as RequestInit).headers as Record<
      string,
      string
    >;
    const second = (fetchMock.mock.calls[1]![1] as RequestInit).headers as Record<
      string,
      string
    >;
    expect(first["X-HWE-Internal-Secret"]).toBe("secreto-de-prueba");
    expect(second["X-HWE-Internal-Secret"]).toBe("secreto-de-prueba");
    expect(Object.values(first)).toContain("Bearer x");
    expect(Object.values(second)).toContain("Bearer y");
  });

  it("reenvía el resto de opciones (método, cuerpo, caché de Next, señal)", async () => {
    const signal = new AbortController().signal;

    await wpFetch("/wp-json/hwe/v1/config", {
      method: "POST",
      body: "{}",
      cache: "no-store",
      signal,
      next: { revalidate: 300, tags: ["site-config"] },
    });

    expect(fetchMock.mock.calls[0]![1]).toMatchObject({
      method: "POST",
      body: "{}",
      cache: "no-store",
      signal,
      next: { revalidate: 300, tags: ["site-config"] },
    });
  });

  it("sin secreto configurado, la llamada sale igualmente (sin cabecera)", async () => {
    delete process.env.HWE_REVALIDATION_SECRET;

    await wpFetch("/wp-json/hwe/v1/config", {
      headers: { Accept: "application/json" },
    });

    expect((fetchMock.mock.calls[0]![1] as RequestInit).headers).toEqual({
      Accept: "application/json",
    });
  });
});
