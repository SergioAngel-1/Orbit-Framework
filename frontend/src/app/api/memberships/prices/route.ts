import { NextResponse } from "next/server";
import { getSession } from "@/lib/auth/session";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { getClientIp } from "@/lib/http/request-ip";
import { membershipFailure, NO_STORE } from "@/lib/memberships/http";
import {
  getMembershipPrices,
  getPublicMembershipPrices,
} from "@/lib/memberships/server";
import { logger } from "@/lib/observability/logger";
import { rateLimit } from "@/lib/security/rate-limit";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/memberships/prices?ids=601,602
//
//  «Precio para ti» de una lista acotada de productos. Con sesión usa la MISMA
//  función que el carrito (WordPress); sin sesión devuelve los precios públicos
//  del catálogo, sin inventar un descuento. El tope de 50 ids se valida aquí
//  antes de tocar WordPress.
//
//  La ruta es anónima y la llama cada listado con el módulo encendido, así que
//  se acota por IP (no estricto: si Redis cae, se sigue sirviendo) y la rama
//  anónima lee los precios por una caché corta por id (`lib/memberships/
//  public-prices-cache.ts`). Sin ese freno, N peticiones con los mismos ids eran
//  N lecturas del catálogo con WordPress como cuello de botella.
// ============================================================================

const MAX_PRICE_IDS = 50;

/** Presupuesto por IP: un listado puede pedir precios varias veces al navegar. */
const RATE_LIMIT = 120;
const RATE_WINDOW_SECONDS = 60;

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

  const limit = await rateLimit(
    `memberships-prices:${getClientIp(request)}`,
    RATE_LIMIT,
    RATE_WINDOW_SECONDS,
  );
  if (!limit.success) {
    logger.warn(
      { event: "memberships.prices_rate_limited" },
      "Rate-limit al consultar precios públicos de membresía",
    );
    return NextResponse.json(
      { error: "Demasiadas peticiones. Inténtalo más tarde." },
      {
        status: 429,
        headers: {
          ...NO_STORE,
          "Retry-After": String(limit.retryAfter),
          "X-RateLimit-Limit": String(limit.limit),
          "X-RateLimit-Remaining": String(limit.remaining),
        },
      },
    );
  }

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
