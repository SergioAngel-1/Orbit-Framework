import { getSiteConfig } from "@/lib/config";
import { renderOgCard } from "@/lib/seo/brand-image";

// Tarjeta Open Graph por defecto, generada dinámicamente desde la marca
// (Control Center). Sin assets estáticos. ISR: se refresca con la config.
export const runtime = "nodejs";
export const revalidate = 300;

export async function GET(): Promise<Response> {
  const config = await getSiteConfig();
  return renderOgCard(config);
}
