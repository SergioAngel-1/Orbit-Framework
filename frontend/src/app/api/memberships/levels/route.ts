import { NextResponse } from "next/server";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { membershipFailure } from "@/lib/memberships/http";
import { getMembershipLevels } from "@/lib/memberships/server";
import { LOCALE_HEADER, requestLocale } from "@/lib/i18n/request-locale";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/memberships/levels
//
//  Pública. Niveles publicados y visibles, con el texto de cada nivel y de sus
//  beneficios ya compuesto en el idioma de la petición. Los datos se cachean por
//  ISR con el tag `memberships` (guardar un nivel lo invalida); la respuesta se
//  puede cachear en un proxy compartido POR IDIOMA (`Vary`).
// ============================================================================

export async function GET(request: Request) {
  const config = await getSiteConfig();

  const moduleOff = requireModule(config, "memberships");
  if (moduleOff) return moduleOff;

  const locale = requestLocale(request.headers);

  try {
    const levels = await getMembershipLevels(locale);
    return NextResponse.json(
      { items: levels },
      {
        headers: {
          "Cache-Control": "public, s-maxage=60, stale-while-revalidate=300",
          Vary: LOCALE_HEADER,
        },
      },
    );
  } catch (error) {
    return membershipFailure(
      error,
      "levels",
      "Error inesperado al listar los niveles de membresía",
    );
  }
}
