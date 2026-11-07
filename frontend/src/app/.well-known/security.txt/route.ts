import { getSiteConfig, isFallbackSiteConfig } from "@/lib/config";
import { buildSecurityTxt } from "@/lib/seo/security-txt";

// ============================================================================
//  /.well-known/security.txt (RFC 9116). Ver `lib/seo/security-txt.ts`.
//  Sin `legal.email` en el Control Center, o con la config degradada (el
//  `Canonical` saldría con el dominio por defecto), 404.
// ============================================================================

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

export async function GET(): Promise<Response> {
  const config = await getSiteConfig();
  const body = isFallbackSiteConfig(config) ? null : buildSecurityTxt(config);
  if (body === null) return new Response("Not Found", { status: 404 });
  return new Response(body, {
    headers: {
      "Content-Type": "text/plain; charset=utf-8",
      "Cache-Control": "public, max-age=0, s-maxage=3600, stale-while-revalidate=86400",
    },
  });
}
