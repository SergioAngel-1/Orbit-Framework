import "server-only";
import { fetchGraphQL } from "@/lib/graphql-client";
import { routing } from "@/i18n/routing";
import type { MenuLink } from "./types";

// ============================================================================
//  Menús de WordPress (Apariencia → Menús) para el frontend headless.
//
//  El tema hwe-headless-base registra las locations `primary`/`footer` (locale
//  por defecto) y `primary_en`/`footer_en` (inglés). "Seleccionar el menú
//  activo" = asignar un menú a la location en wp-admin. Si la location no
//  tiene menú asignado (o WP no responde), getMenu devuelve null y el caller
//  usa su fallback local. Contrato: docs/FRONTEND_CONNECT.md §A.6.
// ============================================================================

export interface MenuItemNode {
  id: string;
  parentId: string | null;
  label: string;
  uri: string;
}

interface MenuQueryData {
  menuItems: { nodes: MenuItemNode[] };
}

const MENU_QUERY = /* GraphQL */ `
  query MenuByLocation($location: MenuLocationEnum!) {
    menuItems(where: { location: $location }, first: 100) {
      nodes {
        id
        parentId
        label
        uri
      }
    }
  }
`;

/** Convierte los nodos planos de WPGraphQL en un árbol de dos niveles. */
export function buildMenuTree(nodes: MenuItemNode[]): MenuLink[] {
  const valid = nodes.filter((n) => n.label && n.uri);
  const ids = new Set(valid.map((n) => n.id));

  const roots: MenuLink[] = [];
  const byId = new Map<string, MenuLink>();

  for (const n of valid) {
    const link: MenuLink = { label: n.label, href: n.uri };
    byId.set(n.id, link);
    if (n.parentId && ids.has(n.parentId)) {
      const parent = byId.get(n.parentId);
      if (parent) {
        parent.children = parent.children ?? [];
        parent.children.push(link);
        continue;
      }
    }
    roots.push(link);
  }
  return roots;
}

/** Location de WPGraphQL para un área e idioma (enum en MAYÚSCULAS). */
function toLocationEnum(area: "primary" | "footer", locale: string): string {
  const suffix = locale === routing.defaultLocale ? "" : `_${locale}`;
  return `${area}${suffix}`.toUpperCase();
}

/**
 * Menú asignado a la location del área/idioma, o `null` si no hay menú
 * asignado, la location no existe o WordPress no responde (fail-soft:
 * la navegación nunca debe tumbar el render).
 */
export async function getMenu(
  area: "primary" | "footer",
  locale: string,
): Promise<MenuLink[] | null> {
  try {
    const data = await fetchGraphQL<MenuQueryData>(MENU_QUERY, {
      variables: { location: toLocationEnum(area, locale) },
      revalidate: 300,
      tags: ["menus"],
    });
    const tree = buildMenuTree(data.menuItems.nodes);
    return tree.length > 0 ? tree : null;
  } catch {
    return null;
  }
}
