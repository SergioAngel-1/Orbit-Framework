import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { SignJWT } from "jose";
import { AUTH_COOKIE, REFRESH_COOKIE } from "@/lib/auth/constants";

// ============================================================================
//  El refresh del JWT en el proxy se deduplica por refresh token.
//
//  Una página con varias peticiones simultáneas (documento, RSC, prefetch,
//  tarjetas) llega al proxy con el mismo JWT caducado. Antes cada una lanzaba su
//  propio `refreshJwtAuthToken` contra WPGraphQL: N vueltas por el mismo token.
//  Ahora comparten la promesa en vuelo, que se borra al resolverse (también si
//  falla o expira), así que un fallo no envenena el siguiente intento.
// ============================================================================

const ORIGIN = "https://tienda.example";

async function expiredToken(userId = "7"): Promise<string> {
  const secret = new TextEncoder().encode("secreto-de-prueba-suficientemente-largo");
  return new SignJWT({ data: { user: { id: userId } } })
    .setProtectedHeader({ alg: "HS256" })
    .setIssuedAt(Math.floor(Date.now() / 1000) - 3600)
    .setExpirationTime(Math.floor(Date.now() / 1000) - 60)
    .sign(secret);
}

async function pageRequest(cookies: string) {
  const { proxy } = await import("@/proxy");
  const { NextRequest } = await import("next/server");
  return proxy(
    new NextRequest(new URL("/tienda", ORIGIN), {
      method: "GET",
      headers: { cookie: cookies },
    }),
  );
}

const cookiesWith = (refreshToken: string, accessToken: string) =>
  `${AUTH_COOKIE}=${accessToken}; ${REFRESH_COOKIE}=${refreshToken}`;

/** Respuesta de WPGraphQL con un token nuevo (el valor da igual aquí). */
const refreshOk = (token = "token-nuevo") =>
  new Response(
    JSON.stringify({ data: { refreshJwtAuthToken: { authToken: token } } }),
    {
      status: 200,
    },
  );

beforeEach(() => {
  vi.resetModules();
  vi.stubEnv("ALLOWED_ORIGIN", ORIGIN);
  vi.stubEnv("WORDPRESS_INTERNAL_API_URL", "http://wordpress:80/graphql");
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe("refresh del JWT deduplicado en el proxy", () => {
  it("N peticiones concurrentes con el mismo refresh token hacen UNA llamada", async () => {
    const fetchMock = vi.fn(async () => {
      await new Promise((resolve) => setTimeout(resolve, 10));
      return refreshOk();
    });
    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);

    const cookies = cookiesWith("mismo-refresco", await expiredToken());
    const responses = await Promise.all(
      Array.from({ length: 6 }, () => pageRequest(cookies)),
    );

    expect(fetchMock).toHaveBeenCalledTimes(1);
    for (const response of responses) {
      expect(response).toBeInstanceOf(Response);
      expect(response.cookies.get(AUTH_COOKIE)?.value).toBe("token-nuevo");
    }
  });

  it("tokens de refresco distintos NO se comparten", async () => {
    const fetchMock = vi.fn(async () => {
      await new Promise((resolve) => setTimeout(resolve, 10));
      return refreshOk();
    });
    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);

    const access = await expiredToken();
    await Promise.all([
      pageRequest(cookiesWith("refresco-a", access)),
      pageRequest(cookiesWith("refresco-b", access)),
    ]);

    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("tras un fallo, un reintento posterior vuelve a llamar", async () => {
    const fetchMock = vi
      .fn<(input: string, init: RequestInit) => Promise<Response>>()
      .mockResolvedValueOnce(new Response("boom", { status: 500 }))
      .mockResolvedValueOnce(refreshOk("token-tras-reintento"));
    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);
    vi.spyOn(console, "warn").mockImplementation(() => {});

    const cookies = cookiesWith("refresco-roto", await expiredToken());

    const first = await pageRequest(cookies);
    expect(first.cookies.get(AUTH_COOKIE)?.value ?? "").toBe("");

    const second = await pageRequest(cookies);
    expect(second.cookies.get(AUTH_COOKIE)?.value).toBe("token-tras-reintento");
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it("el refresh compartido conserva el timeout de 3 s", async () => {
    const fetchMock = vi.fn<(input: string, init: RequestInit) => Promise<Response>>(
      async () => refreshOk(),
    );
    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);

    const cookies = cookiesWith("mismo-refresco", await expiredToken());
    await Promise.all([pageRequest(cookies), pageRequest(cookies)]);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const init = fetchMock.mock.calls[0]![1];
    expect(init.signal).toBeInstanceOf(AbortSignal);
  });

  it("el refresh token no aparece en los eventos de log", async () => {
    const fetchMock = vi.fn(async () => new Response("boom", { status: 500 }));
    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});

    await pageRequest(cookiesWith("refresco-secreto", await expiredToken()));

    const logged = warn.mock.calls.map((call) => String(call[0])).join("\n");
    expect(logged).toContain("proxy.refresh_failed");
    expect(logged).not.toContain("refresco-secreto");
  });
});
