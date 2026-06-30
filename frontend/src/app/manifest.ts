import type { MetadataRoute } from "next";
import { getSiteConfig } from "@/lib/config";

// Manifest PWA construido desde el Control Center. Los iconos se generan
// dinámicamente (/api/icon) a partir de la marca: sin PNG estáticos en el repo.
export const revalidate = 300;

export default async function manifest(): Promise<MetadataRoute.Manifest> {
  const config = await getSiteConfig();
  return {
    name: config.brand.name,
    short_name: config.brand.name,
    description: config.brand.description,
    start_url: "/",
    display: "standalone",
    background_color: config.design.colors.background || "#ffffff",
    theme_color: config.design.colors.brand || "#2563eb",
    icons: [
      { src: "/api/icon?size=192", sizes: "192x192", type: "image/png", purpose: "any" },
      { src: "/api/icon?size=512", sizes: "512x512", type: "image/png", purpose: "any" },
      { src: "/api/icon?size=512", sizes: "512x512", type: "image/png", purpose: "maskable" },
    ],
  };
}
