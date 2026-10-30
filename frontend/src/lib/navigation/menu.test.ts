import { beforeEach, describe, expect, it, vi } from "vitest";

const fetchGraphQL = vi.hoisted(() => vi.fn());
const warn = vi.hoisted(() => vi.fn());

vi.mock("@/lib/graphql-client", () => ({ fetchGraphQL }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn, error: vi.fn(), info: vi.fn() },
}));

import { getMenu } from "./menu";

beforeEach(() => {
  fetchGraphQL.mockReset();
  warn.mockReset();
});

describe("getMenu", () => {
  it("devuelve el árbol del menú asignado, sin registrar nada", async () => {
    fetchGraphQL.mockResolvedValue({
      menuItems: { nodes: [{ id: "1", parentId: null, label: "Tienda", uri: "/tienda" }] },
    });

    await expect(getMenu("primary", "es")).resolves.toEqual([
      { label: "Tienda", href: "/tienda" },
    ]);
    expect(warn).not.toHaveBeenCalled();
  });

  it("sin menú asignado devuelve null y no es un fallo", async () => {
    fetchGraphQL.mockResolvedValue({ menuItems: { nodes: [] } });

    await expect(getMenu("primary", "es")).resolves.toBeNull();
    expect(warn).not.toHaveBeenCalled();
  });

  it("si WordPress falla devuelve null y lo registra con un evento", async () => {
    fetchGraphQL.mockRejectedValue(new Error("WordPress caído"));

    await expect(getMenu("footer", "es")).resolves.toBeNull();
    expect(warn).toHaveBeenCalledTimes(1);
    expect(warn.mock.calls[0]![0]).toMatchObject({
      event: "navigation.menu_read_failed",
      area: "footer",
      locale: "es",
      err: "WordPress caído",
    });
  });

  // Un idioma sin location registrada por el tema es una configuración válida: el
  // enum de WPGraphQL no conoce ese valor y el llamador usa su menú de respaldo. Si se
  // registrara, cada render de ese idioma ensuciaría el log con un falso fallo.
  it("una location que el tema no registra no es un fallo", async () => {
    fetchGraphQL.mockRejectedValue(
      new Error('Variable "$location" got invalid value "PRIMARY_FR"; Value "PRIMARY_FR" does not exist in "MenuLocationEnum" enum.'),
    );

    await expect(getMenu("primary", "fr")).resolves.toBeNull();
    expect(warn).not.toHaveBeenCalled();
  });
});
