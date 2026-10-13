import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { fetchGraphQL, GraphQLClientError } from "@/lib/graphql-client";

const QUERY = "query { __typename }";

function mockFetchOnce(
  response: Partial<Response> & { jsonBody?: unknown; textBody?: string },
) {
  const { jsonBody, textBody, ...rest } = response;
  vi.stubGlobal(
    "fetch",
    vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      statusText: "OK",
      json: async () => jsonBody,
      text: async () => textBody ?? "",
      ...rest,
    } as Response),
  );
}

describe("fetchGraphQL — errores tipados", () => {
  beforeEach(() => vi.unstubAllGlobals());
  afterEach(() => vi.unstubAllGlobals());

  it("devuelve data en el caso feliz", async () => {
    mockFetchOnce({ jsonBody: { data: { __typename: "RootQuery" } } });
    await expect(fetchGraphQL(QUERY)).resolves.toEqual({ __typename: "RootQuery" });
  });

  it("lanza kind=http con status cuando la respuesta no es OK", async () => {
    mockFetchOnce({
      ok: false,
      status: 502,
      statusText: "Bad Gateway",
      textBody: "upstream",
    });
    const err = (await fetchGraphQL(QUERY).catch((e) => e)) as GraphQLClientError;
    expect(err).toBeInstanceOf(GraphQLClientError);
    expect(err.kind).toBe("http");
    expect(err.status).toBe(502);
  });

  it("lanza kind=graphql con los errores originales", async () => {
    mockFetchOnce({
      jsonBody: { errors: [{ message: "Cannot query field X" }, { message: "Otro" }] },
    });
    const err = (await fetchGraphQL(QUERY).catch((e) => e)) as GraphQLClientError;
    expect(err).toBeInstanceOf(GraphQLClientError);
    expect(err.kind).toBe("graphql");
    expect(err.errors).toHaveLength(2);
    expect(err.message).toContain("Cannot query field X");
  });

  it("lanza kind=empty si no hay data ni errors", async () => {
    mockFetchOnce({ jsonBody: {} });
    const err = (await fetchGraphQL(QUERY).catch((e) => e)) as GraphQLClientError;
    expect(err).toBeInstanceOf(GraphQLClientError);
    expect(err.kind).toBe("empty");
  });

  it("lanza kind=network cuando fetch revienta", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("fetch failed")));
    const err = (await fetchGraphQL(QUERY).catch((e) => e)) as GraphQLClientError;
    expect(err).toBeInstanceOf(GraphQLClientError);
    expect(err.kind).toBe("network");
  });
});

describe("fetchGraphQL — plazo y reintentos", () => {
  beforeEach(() => {
    vi.unstubAllGlobals();
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.unstubAllEnvs();
  });

  const ok = (data: unknown) =>
    ({
      ok: true,
      status: 200,
      statusText: "OK",
      json: async () => ({ data }),
      text: async () => "",
    }) as unknown as Response;
  const http = (status: number) =>
    ({
      ok: false,
      status,
      statusText: "x",
      json: async () => ({}),
      text: async () => "",
    }) as unknown as Response;

  /** Ejecuta `fetchGraphQL` dejando correr las esperas del backoff. */
  async function run(query = QUERY) {
    const pending = fetchGraphQL(query).catch((e: unknown) => e);
    await vi.runAllTimersAsync();
    return pending;
  }

  it("reintenta un 5xx y devuelve el dato del segundo intento", async () => {
    const fn = vi
      .fn()
      .mockResolvedValueOnce(http(503))
      .mockResolvedValueOnce(ok({ a: 1 }));
    vi.stubGlobal("fetch", fn);

    expect(await run()).toEqual({ a: 1 });
    expect(fn).toHaveBeenCalledTimes(2);
  });

  it("agota los 3 intentos ante un 5xx persistente", async () => {
    const fn = vi.fn().mockResolvedValue(http(502));
    vi.stubGlobal("fetch", fn);

    const err = (await run()) as GraphQLClientError;

    expect(err.kind).toBe("http");
    expect(fn).toHaveBeenCalledTimes(3);
  });

  it("un 4xx y un error de GraphQL son deterministas: un solo intento", async () => {
    const fn = vi.fn().mockResolvedValue(http(404));
    vi.stubGlobal("fetch", fn);
    await run();
    expect(fn).toHaveBeenCalledTimes(1);

    const gql = vi.fn().mockResolvedValue({
      ok: true,
      status: 200,
      json: async () => ({ errors: [{ message: "mal" }] }),
    } as unknown as Response);
    vi.stubGlobal("fetch", gql);
    await run();
    expect(gql).toHaveBeenCalledTimes(1);
  });

  it("una mutation no se reintenta: pudo aplicarse aunque la respuesta fallara", async () => {
    const fn = vi.fn().mockResolvedValue(http(503));
    vi.stubGlobal("fetch", fn);

    await run("mutation { x }");

    expect(fn).toHaveBeenCalledTimes(1);
  });

  it("cada intento lleva su propio plazo (AbortSignal)", async () => {
    const fn = vi.fn().mockResolvedValueOnce(http(503)).mockResolvedValueOnce(ok({}));
    vi.stubGlobal("fetch", fn);

    await run();

    const first = (fn.mock.calls[0]![1] as RequestInit).signal;
    const second = (fn.mock.calls[1]![1] as RequestInit).signal;
    expect(first).toBeInstanceOf(AbortSignal);
    expect(second).toBeInstanceOf(AbortSignal);
    expect(second).not.toBe(first);
  });

  it("un plazo vencido es kind=timeout y se reintenta", async () => {
    const timeout = Object.assign(new Error("timed out"), { name: "TimeoutError" });
    const fn = vi.fn().mockRejectedValue(timeout);
    vi.stubGlobal("fetch", fn);

    const err = (await run()) as GraphQLClientError;

    expect(err.kind).toBe("timeout");
    expect(fn).toHaveBeenCalledTimes(3);
  });

  it("GRAPHQL_TIMEOUT_MS fija el plazo; un valor inservible cae al de 10 s", async () => {
    const spy = vi.spyOn(AbortSignal, "timeout");
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue(ok({})));

    vi.stubEnv("GRAPHQL_TIMEOUT_MS", "2500");
    await run();
    expect(spy).toHaveBeenLastCalledWith(2500);

    vi.stubEnv("GRAPHQL_TIMEOUT_MS", "abc");
    await run();
    expect(spy).toHaveBeenLastCalledWith(10_000);
  });
});
