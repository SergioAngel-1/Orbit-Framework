import { getSiteConfig } from "@/lib/config";
import { renderBrandIcon } from "@/lib/seo/brand-image";

// Iconos PWA (manifest) generados dinámicamente desde la marca.
// Tamaño vía ?size=192|512 (limitado a un rango razonable). Sin assets estáticos.
export const runtime = "nodejs";
export const revalidate = 300;

const ALLOWED = new Set([192, 256, 384, 512]);

export async function GET(request: Request): Promise<Response> {
  const raw = Number(new URL(request.url).searchParams.get("size") ?? "512");
  const size = ALLOWED.has(raw) ? raw : 512;
  const config = await getSiteConfig();
  return renderBrandIcon(config, size);
}
