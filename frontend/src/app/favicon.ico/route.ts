import { getSiteConfig } from "@/lib/config";
import { resolveIconResponse } from "@/lib/seo/brand-image";

// /favicon.ico: la petición por defecto de navegadores, lectores de feeds y del
// rastreador de favicons de Google, que no leen el `<link rel="icon">`. Sirve el mismo
// icono de marca que `icon.tsx` (el del sitio de WordPress si lo hay, o el generado) en
// PNG: todos los navegadores aceptan un PNG en esta URL. Sin esto responde 404 aunque el
// `<head>` declare el icono. Una instancia con su propio `favicon.ico` estático borra
// esta ruta (o la deja: el fichero estático y la ruta no pueden coexistir).
export const runtime = "nodejs";
export const revalidate = 300;

export async function GET(): Promise<Response> {
  const config = await getSiteConfig();
  return resolveIconResponse(config, 48);
}
