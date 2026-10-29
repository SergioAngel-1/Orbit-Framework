import { routing } from "@/i18n/routing";
import { buildLlmsTxt, llmsResponse } from "@/app/_llms/build";

// /llms.txt del locale canónico. Los demás idiomas viven en
// `app/[locale]/llms.txt/route.ts`; todos comparten `buildLlmsTxt`.
export const runtime = "nodejs";
// Dinámico a propósito: un ISR se prerenderiza en el build de la imagen, que corre SIN
// WordPress, y servía el resultado degradado (vacío / 404) hasta una hora tras cada
// despliegue. Los datos van por la Data Cache con sus tags y el CDN cachea la respuesta
// (`s-maxage`), así que pedirlo en cada petición es barato.
export const dynamic = "force-dynamic";

export async function GET(): Promise<Response> {
  const body = await buildLlmsTxt(routing.defaultLocale);
  if (body === null) return new Response("Not found", { status: 404 });
  return llmsResponse(body);
}
