import { describe, it, expect } from "vitest";
import { buildMenuTree, type MenuItemNode } from "@/lib/navigation/menu";

const node = (
  id: string,
  label: string,
  uri: string,
  parentId: string | null = null,
): MenuItemNode => ({
  id,
  label,
  uri,
  parentId,
});

describe("buildMenuTree", () => {
  it("devuelve lista plana cuando no hay jerarquía", () => {
    const tree = buildMenuTree([
      node("1", "Inicio", "/"),
      node("2", "Tienda", "/products"),
    ]);
    expect(tree).toEqual([
      { label: "Inicio", href: "/" },
      { label: "Tienda", href: "/products" },
    ]);
  });

  it("anida hijos bajo su padre respetando el orden", () => {
    const tree = buildMenuTree([
      node("1", "Tienda", "/products"),
      node("2", "Ofertas", "/products?on_sale=1", "1"),
      node("3", "Novedades", "/products?orderby=date", "1"),
      node("4", "Blog", "/blog"),
    ]);
    expect(tree).toEqual([
      {
        label: "Tienda",
        href: "/products",
        children: [
          { label: "Ofertas", href: "/products?on_sale=1" },
          { label: "Novedades", href: "/products?orderby=date" },
        ],
      },
      { label: "Blog", href: "/blog" },
    ]);
  });

  it("ignora huérfanos cuyo padre no existe (los promociona a nivel raíz)", () => {
    const tree = buildMenuTree([node("2", "Suelto", "/x", "no-existe")]);
    expect(tree).toEqual([{ label: "Suelto", href: "/x" }]);
  });

  it("descarta items sin label o sin uri", () => {
    const tree = buildMenuTree([node("1", "", "/"), node("2", "Ok", "")]);
    expect(tree).toEqual([]);
  });
});
