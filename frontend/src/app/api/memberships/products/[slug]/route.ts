import { NextResponse } from "next/server";
import { getSession } from "@/lib/auth/session";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import {
  membershipFailure,
  membershipUnauthorized,
  NO_STORE,
} from "@/lib/memberships/http";
import { getMembershipProduct } from "@/lib/memberships/server";
import { requestLocale } from "@/lib/i18n/request-locale";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/memberships/products/[slug]
//
//  Ficha del módulo: completa si el socio cumple el requisito y de escaparate si
//  no (lo decide WordPress con `Teaser`, para que el BFF no pueda distinguir por
//  forma). Exige sesión: §10.3 la declara de sesión y la ficha de escaparate
//  también necesita un `user_id` para evaluar el acceso. Sin caché compartida:
//  la misma URL devuelve dos formas distintas según quién la pida.
// ============================================================================

export async function GET(
  request: Request,
  { params }: { params: Promise<{ slug: string }> },
) {
  const config = await getSiteConfig();

  const moduleOff = requireModule(config, "memberships");
  if (moduleOff) return moduleOff;

  const session = await getSession();
  if (!session) return membershipUnauthorized();

  const { slug } = await params;
  const clean = slug.trim();
  if (clean === "") {
    return NextResponse.json(
      { error: "Producto no válido.", code: "invalid_request" },
      { status: 400, headers: NO_STORE },
    );
  }

  try {
    const product = await getMembershipProduct(Number(session.userId), {
      slug: clean,
      locale: requestLocale(request.headers),
    });
    return NextResponse.json(product, { headers: NO_STORE });
  } catch (error) {
    return membershipFailure(
      error,
      "product",
      "Error inesperado al consultar la ficha de membresía",
    );
  }
}
