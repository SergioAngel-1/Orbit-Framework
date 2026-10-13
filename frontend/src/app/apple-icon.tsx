import { getSiteConfig } from "@/lib/config";
import { resolveIconResponse } from "@/lib/seo/brand-image";

// Apple touch icon: el icono del sitio de WordPress si lo hay y, si no, uno
// generado desde la marca (Control Center). Convención de Next: se inyecta
// automáticamente en <head>. Sin PNG estático.
export const runtime = "nodejs";
export const revalidate = 300;

export const size = { width: 180, height: 180 };
export const contentType = "image/png";

export default async function AppleIcon() {
  const config = await getSiteConfig();
  return resolveIconResponse(config, 180);
}
