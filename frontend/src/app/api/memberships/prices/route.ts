import { NextResponse } from "next/server";
import { getSession } from "@/lib/auth/session";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { membershipFailure, NO_STORE } from "@/lib/memberships/http";
import {
  getMembershipPrices,
  getPublicMembershipPrices,
} from "@/lib/memberships/server";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/memberships/prices?ids=601,602
//
//  «Precio para ti» de una lista acotada de productos. Con sesión usa la MISMA
//  función que el carrito (WordPress); sin sesión devuelve los precios públicos
//  del catálogo, sin inventar un descuento. El tope de 50 ids se valida aquí
//  antes de tocar WordPress.
// ============================================================================

const MAX_PRICE_IDS = 50;

/**
 * Ids pedidos: `a,b,c`, separados por espacios o con el parámetro repetido.
 * Los valores no numéricos se descartan y se deduplica conservando el orden.
 */
function parseIds(params: URLSearchParams): number[] {
  const ids: number[] = [];
  for (const part of params
    .getAll("ids")
    .join(",")
    .split(/[\s,]+/)) {
    if (!/^\d+$/.test(part)) continue;
    const id = Number(part);
    if (id > 0 && !ids.includes(id)) ids.push(id);
  }
  return ids;
}

export async function GET(request: Request) {
  const config = await getSiteConfig();

  const moduleOff = requireModule(config, "memberships");
  if (moduleOff) return moduleOff;

  const ids = parseIds(new URL(request.url).searchParams);
  if (ids.length > MAX_PRICE_IDS) {
    return NextResponse.json(
      { error: "Demasiados productos en la petición.", code: "invalid_request" },
      { status: 400, headers: NO_STORE },
    );
  }
  if (ids.length === 0) {
    return NextResponse.json({ items: [] }, { headers: NO_STORE });
  }

  const session = await getSession();

  try {
    const items = session
      ? await getMembershipPrices(Number(session.userId), ids)
      : await getPublicMembershipPrices(ids);
    return NextResponse.json({ items }, { headers: NO_STORE });
  } catch (error) {
    return membershipFailure(
      error,
      "prices",
      "Error inesperado al consultar los precios de membresía",
    );
  }
}
