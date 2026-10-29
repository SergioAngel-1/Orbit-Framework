import { indexNowKey } from "@/lib/seo/indexnow";

// ============================================================================
//  /indexnow.txt — el fichero de clave de IndexNow (ver `lib/seo/indexnow.ts`).
//  El buscador lo descarga para comprobar que quien anuncia URLs controla el
//  sitio. Sin `INDEXNOW_KEY`, 404: IndexNow está apagado.
// ============================================================================

export const dynamic = "force-dynamic";

export function GET(): Response {
  const key = indexNowKey();
  if (!key) return new Response("Not Found", { status: 404 });
  return new Response(key, {
    headers: {
      "Content-Type": "text/plain; charset=utf-8",
      "Cache-Control": "public, max-age=0, s-maxage=3600",
    },
  });
}
