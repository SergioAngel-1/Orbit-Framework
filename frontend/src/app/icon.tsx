import { getSiteConfig } from "@/lib/config";
import { renderBrandIcon } from "@/lib/seo/brand-image";

// Favicon generado dinámicamente desde la marca (Control Center).
// Convención de Next: se inyecta automáticamente en <head>. Sin .ico estático.
export const runtime = "nodejs";
export const revalidate = 300;

export const size = { width: 32, height: 32 };
export const contentType = "image/png";

export default async function Icon() {
  const config = await getSiteConfig();
  return renderBrandIcon(config, 32);
}
