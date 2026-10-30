import { beforeEach, describe, expect, it, vi } from "vitest";

const fetchGraphQL = vi.fn();
vi.mock("@/lib/graphql-client", () => ({
  fetchGraphQL: (...args: unknown[]) => fetchGraphQL(...args),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { error: vi.fn(), warn: vi.fn(), info: vi.fn() },
}));
vi.mock("@/lib/memberships/catalog-flag", () => ({
  membershipsCatalogEnabled: vi.fn(async () => false),
}));

import { membershipsCatalogEnabled } from "@/lib/memberships/catalog-flag";
import { getProducts } from "./products";

const EMPTY = {
  products: { pageInfo: { hasNextPage: false, endCursor: null }, nodes: [] },
};

beforeEach(() => {
  fetchGraphQL.mockReset();
  fetchGraphQL.mockResolvedValue(EMPTY);
});

describe("getProducts: exclude", () => {
  it("sin ids no manda la variable", async () => {
    await getProducts({ exclude: [] });

    expect(fetchGraphQL.mock.calls[0]![1].variables.exclude).toBeUndefined();
  });

  it.each([false, true])("la pide en la consulta (memberships: %s)", async (memberships) => {
    vi.mocked(membershipsCatalogEnabled).mockResolvedValue(memberships);

    await getProducts({ exclude: [7, 12] });

    const [document, options] = fetchGraphQL.mock.calls[0]!;
    expect(options.variables.exclude).toEqual([7, 12]);
    expect(document).toContain("$exclude: [Int]");
    expect(document).toContain("exclude: $exclude");
  });
});
