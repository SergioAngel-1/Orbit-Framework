import { NextResponse } from "next/server";
import { getSession } from "@/lib/auth/session";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import {
  membershipFailure,
  membershipUnauthorized,
  NO_STORE,
} from "@/lib/memberships/http";
import { getMembershipState } from "@/lib/memberships/server";
import { requestLocale } from "@/lib/i18n/request-locale";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/memberships/me
//
//  Estado del socio de la sesión: nivel efectivo, cola programada, beneficios
//  con su estado, regalos restantes y elegibilidad de compra. El `user_id` sale
//  SIEMPRE de la sesión verificada; la query se ignora a propósito.
// ============================================================================

export async function GET(request: Request) {
  const config = await getSiteConfig();

  const moduleOff = requireModule(config, "memberships");
  if (moduleOff) return moduleOff;

  const session = await getSession();
  if (!session) return membershipUnauthorized();

  try {
    const state = await getMembershipState(Number(session.userId), {
      locale: requestLocale(request.headers),
      memberNumberPrefix: config.memberships?.member_number_prefix ?? "",
    });
    return NextResponse.json(state, { headers: NO_STORE });
  } catch (error) {
    return membershipFailure(
      error,
      "me",
      "Error inesperado al consultar el estado de membresía",
    );
  }
}
