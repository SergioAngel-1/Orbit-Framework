import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/i18n/routing", () => ({
  routing: { locales: ["es", "en", "pt-BR"], defaultLocale: "es" },
}));

const fetchGraphQL = vi.fn();
vi.mock("@/lib/graphql-client", async () => {
  const actual =
    await vi.importActual<typeof import("@/lib/graphql-client")>(
      "@/lib/graphql-client",
    );
  return { ...actual, fetchGraphQL: (...args: unknown[]) => fetchGraphQL(...args) };
});

const logError = vi.fn();
vi.mock("@/lib/observability/logger", () => ({
  logger: {
    error: (...args: unknown[]) => logError(...args),
    warn: vi.fn(),
    info: vi.fn(),
  },
}));

import { GraphQLClientError } from "@/lib/graphql-client";
import { fetchLocalizedGraphQL } from "./fetch-localized";

const DOC = `
  query Post($slug: ID!) {
    post(id: $slug, idType: SLUG) {
      title
      # @translation Post
    }
  }`;

describe("fetchLocalizedGraphQL", () => {
  beforeEach(() => {
    fetchGraphQL.mockReset();
    logError.mockReset();
  });

  it("en el idioma por defecto la query no menciona `translation`", async () => {
    fetchGraphQL.mockResolvedValue({ post: { title: "Hola" } });

    await fetchLocalizedGraphQL(DOC, "es", { variables: { slug: "x" } });

    expect(fetchGraphQL.mock.calls[0]![0]).not.toContain("translation");
  });

  it("en un idioma secundario pide su traducción y aplana la respuesta", async () => {
    fetchGraphQL.mockResolvedValue({
      post: { title: "Hola", tr_en: { title: "Hello", slug: "hello" } },
    });

    const data = await fetchLocalizedGraphQL<{ post: Record<string, string> }>(
      DOC,
      "en",
    );

    expect(fetchGraphQL.mock.calls[0]![0]).toContain('translation(locale: "en")');
    expect(data.post).toEqual({ title: "Hola", title_en: "Hello", slug_en: "hello" });
  });

  it("pasa las opciones (variables, revalidación, tags) tal cual", async () => {
    fetchGraphQL.mockResolvedValue({});
    const options = { variables: { slug: "x" }, revalidate: 300, tags: ["posts"] };

    await fetchLocalizedGraphQL(DOC, "en", options);

    expect(fetchGraphQL.mock.calls[0]![1]).toEqual(options);
  });

  it("varios idiomas piden una selección por idioma (hreflang y sitemap)", async () => {
    fetchGraphQL.mockResolvedValue({});

    await fetchLocalizedGraphQL(DOC, ["en", "pt-BR"]);

    const sent = String(fetchGraphQL.mock.calls[0]![0]);
    expect(sent).toContain("tr_en:");
    expect(sent).toContain("tr_pt_br:");
  });

  it("si WordPress no tiene el campo (idiomas sin configurar) degrada al contenido nativo y lo registra", async () => {
    fetchGraphQL
      .mockRejectedValueOnce(
        new GraphQLClientError(
          "graphql",
          'Error de GraphQL: Cannot query field "translation" on type "Post".',
        ),
      )
      .mockResolvedValueOnce({ post: { title: "Hola" } });

    const data = await fetchLocalizedGraphQL<{ post: { title: string } }>(DOC, "en");

    expect(data.post.title).toBe("Hola");
    expect(fetchGraphQL).toHaveBeenCalledTimes(2);
    expect(fetchGraphQL.mock.calls[1]![0]).not.toContain("translation");
    expect(logError).toHaveBeenCalledWith(
      expect.objectContaining({ event: "i18n.translation_unavailable" }),
      expect.any(String),
    );
  });

  it("no oculta un fallo de red ni un error de GraphQL que no es de las traducciones", async () => {
    fetchGraphQL.mockRejectedValueOnce(new GraphQLClientError("network", "caído"));
    await expect(fetchLocalizedGraphQL(DOC, "en")).rejects.toThrow("caído");

    fetchGraphQL.mockRejectedValueOnce(
      new GraphQLClientError("graphql", "Error de GraphQL: otra cosa"),
    );
    await expect(fetchLocalizedGraphQL(DOC, "en")).rejects.toThrow("otra cosa");
    expect(fetchGraphQL).toHaveBeenCalledTimes(2);
  });

  it("en el idioma por defecto un error de GraphQL se propaga sin reintentar", async () => {
    fetchGraphQL.mockRejectedValueOnce(
      new GraphQLClientError("graphql", "boom translation"),
    );

    await expect(fetchLocalizedGraphQL(DOC, "es")).rejects.toThrow("boom");
    expect(fetchGraphQL).toHaveBeenCalledTimes(1);
  });
});
