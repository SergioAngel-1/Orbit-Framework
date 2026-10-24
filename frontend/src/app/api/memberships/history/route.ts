import { NextResponse } from "next/server";
import { z } from "zod";
import { getSession } from "@/lib/auth/session";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { describeHistoryEntry } from "@/lib/memberships/describe";
import {
  membershipFailure,
  membershipUnauthorized,
  NO_STORE,
} from "@/lib/memberships/http";
import { getMembershipHistory } from "@/lib/memberships/server";
import type { MembershipHistoryEntry } from "@/lib/memberships/types";
import { requestLocale } from "@/lib/i18n/request-locale";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/memberships/history?page&per_page
//
//  Historial del propio usuario. `per_page` se recorta a 1..100 antes de llegar
//  a WordPress y cada evento viaja con su texto ya compuesto en el idioma de la
//  petición (`describeHistoryEntry`): WordPress nunca devuelve frases.
// ============================================================================

const PER_PAGE_DEFAULT = 20;
const PER_PAGE_MAX = 100;

const querySchema = z.object({
  page: z.coerce.number().int().min(1).catch(1).optional().default(1),
  per_page: z.coerce
    .number()
    .int()
    .catch(PER_PAGE_DEFAULT)
    .transform((value) => Math.min(PER_PAGE_MAX, Math.max(1, value)))
    .optional()
    .default(PER_PAGE_DEFAULT),
});

export async function GET(request: Request) {
  const config = await getSiteConfig();

  const moduleOff = requireModule(config, "memberships");
  if (moduleOff) return moduleOff;

  const session = await getSession();
  if (!session) return membershipUnauthorized();

  const params = new URL(request.url).searchParams;
  const parsed = querySchema.parse({
    page: params.get("page") ?? undefined,
    per_page: params.get("per_page") ?? undefined,
  });

  try {
    const page = await getMembershipHistory(Number(session.userId), {
      page: parsed.page,
      perPage: parsed.per_page,
    });

    const locale = requestLocale(request.headers);
    const items: MembershipHistoryEntry[] = page.items.map((item) => ({
      ...item,
      description: describeHistoryEntry(item, locale),
    }));

    return NextResponse.json(
      { items, total: page.total, pages: page.pages },
      { headers: NO_STORE },
    );
  } catch (error) {
    return membershipFailure(
      error,
      "history",
      "Error inesperado al consultar el historial de membresía",
    );
  }
}
