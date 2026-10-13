import { routing } from "@/i18n/routing";
import { buildLlmsTxt, llmsResponse } from "@/app/_llms/build";

// /llms.txt del locale canónico. Los demás idiomas viven en
// `app/[locale]/llms.txt/route.ts`; todos comparten `buildLlmsTxt`.
export const runtime = "nodejs";
export const revalidate = 3600;

export async function GET(): Promise<Response> {
  const body = await buildLlmsTxt(routing.defaultLocale);
  if (body === null) return new Response("Not found", { status: 404 });
  return llmsResponse(body);
}
