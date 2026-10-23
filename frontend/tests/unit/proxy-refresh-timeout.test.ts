import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { SignJWT } from "jose";
import { AUTH_COOKIE, REFRESH_COOKIE } from "@/lib/auth/constants";

// ============================================================================
//  El refresh del JWT en el proxy tiene que tener techo.
//
//  Este `fetch` a WPGraphQL corre en el EDGE y está en el camino de toda
//  petición de página: el token de acceso dura ~5 min, así que cualquier
//  visitante con sesión pasa por aquí varias veces. Sin timeout, un WordPress
//  que acepta la conexión y no responde —saturado, con una query bloqueada en
//  MySQL— dejaba colgada la navegación entera antes de que ningún Route Handler
//  pudiera degradar nada.
//
//  El plazo es corto a propósito (3 s): no es una consulta que alguien esté
//  esperando ver, es un trámite de sesión con degradado limpio. Si no responde a
//  tiempo se sirve la página sin token renovado y el siguiente request
//  reintenta, porque la cookie de refresco sobrevive.
// ============================================================================

const ORIGIN = "https://tienda.example";

/** Token de acceso ya caducado: es lo que dispara la rama de refresh. */
async function tokenCaducado(): Promise<string> {
  const secreto = new TextEncoder().encode("secreto-de-prueba-suficientemente-largo");
  return new SignJWT({ data: { user: { id: "7" } } })
    .setProtectedHeader({ alg: "HS256" })
    .setIssuedAt(Math.floor(Date.now() / 1000) - 3600)
    .setExpirationTime(Math.floor(Date.now() / 1000) - 60)
    .sign(secreto);
}

async function pedirPagina(cookies: string) {
  const { proxy } = await import("@/proxy");
  const { NextRequest } = await import("next/server");
  const req = new NextRequest(new URL("/tienda", ORIGIN), {
    method: "GET",
    headers: { cookie: cookies },
  });
  return proxy(req);
}

async function cookiesCaducadas() {
  return `${AUTH_COOKIE}=${await tokenCaducado()}; ${REFRESH_COOKIE}=refresco`;
}

describe("timeout del refresh de JWT en el proxy", () => {
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

  it("pasa un AbortSignal al fetch de refresh", async () => {
    // La firma del mock está tipada (aunque el cuerpo no use los argumentos)
    // para que `mock.calls[0][1]` sea un `RequestInit`.
    const fetchMock = vi.fn<(input: string, init: RequestInit) => Promise<Response>>(
      async () =>
        new Response(
          JSON.stringify({ data: { refreshJwtAuthToken: { authToken: "nuevo" } } }),
          { status: 200 },
        ),
    );
    vi.stubGlobal("fetch", fetchMock as unknown as typeof fetch);

    await pedirPagina(await cookiesCaducadas());

    expect(fetchMock).toHaveBeenCalledOnce();
    const init = fetchMock.mock.calls[0]![1];
    expect(init.signal).toBeInstanceOf(AbortSignal);
  });

  it("un WordPress que acepta la conexión y no responde NO cuelga la navegación", async () => {
    // La conexión se acepta y la respuesta nunca llega. Sin timeout esta promesa
    // no se resolvería jamás y la página no se serviría. El `AbortSignal` del
    // proxy es lo que la corta: aquí se honra ese signal para comprobar que el
    // proxy lo propaga y que el rechazo queda atrapado.
    vi.stubGlobal(
      "fetch",
      vi.fn(
        (_url: string, init: RequestInit) =>
          new Promise((_resolve, reject) => {
            init.signal?.addEventListener("abort", () =>
              reject(new DOMException("The operation was aborted.", "TimeoutError")),
            );
          }),
      ) as unknown as typeof fetch,
    );
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});

    const res = await Promise.race([
      pedirPagina(await cookiesCaducadas()),
      // Holgura sobre el timeout de 3 s del proxy: si no abortara, esto ganaría
      // la carrera y el test fallaría con "colgado".
      new Promise((resolve) => setTimeout(() => resolve("colgado"), 8_000)),
    ]);

    expect(res).not.toBe("colgado");
    // Y responde: el visitante ve la página (sin sesión renovada) en vez de nada.
    expect(res).toBeInstanceOf(Response);
    expect(warn).toHaveBeenCalled();
  }, 15_000);

  it("el fallo del refresh no es silencioso: queda un evento estructurado", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("fetch failed");
      }) as unknown as typeof fetch,
    );
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});

    await pedirPagina(await cookiesCaducadas());

    const lines = warn.mock.calls.map((c) => String(c[0]));
    const event = lines
      .map((l) => {
        try {
          return JSON.parse(l) as { event?: string; reason?: string };
        } catch {
          return {};
        }
      })
      .find((e) => e.event === "proxy.refresh_failed");
    expect(event, "falta el evento proxy.refresh_failed").toBeDefined();
    expect(event?.reason).toContain("TypeError");
  });

  it("si WordPress responde a tiempo, la sesión se renueva y la cookie se propaga", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(
            JSON.stringify({
              data: { refreshJwtAuthToken: { authToken: "token-nuevo" } },
            }),
            { status: 200 },
          ),
      ) as unknown as typeof fetch,
    );

    const res = await pedirPagina(await cookiesCaducadas());

    expect(res.cookies.get(AUTH_COOKIE)?.value).toBe("token-nuevo");
  });

  it("una respuesta de error de WordPress degrada a anónimo sin romper la página", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () => new Response("boom", { status: 500 }),
      ) as unknown as typeof fetch,
    );

    const res = await pedirPagina(await cookiesCaducadas());

    expect(res).toBeInstanceOf(Response);
    // El token caducado se retira; la cookie de refresco sobrevive para reintentar.
    expect(res.cookies.get(AUTH_COOKIE)?.value ?? "").toBe("");
  });
});
