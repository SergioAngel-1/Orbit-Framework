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
