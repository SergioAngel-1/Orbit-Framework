import { NextResponse } from "next/server";
import { getFreeShippingScope } from "@/lib/shipping";
import { requireModule } from "@/lib/api/modules";
import { getSiteConfig } from "@/lib/config";
import { routing } from "@/i18n/routing";

/**
 * GET /api/store/free-shipping/scope?locale= — a qué aplica el envío gratis
 * (categorías con sus subcategorías y productos sueltos, y las ciudades), en el
 * idioma de la página. Lo pide el «?» del aviso de envío gratis la primera vez que
 * se abre. Módulo `shipping`: apagado, 404.
 *
 * Público y sin nada del visitante: es la regla de la tienda. La frescura la da el
 * Data Cache (tags `shipping` y `site-config`); encima, una ventana HTTP corta.
 */
export async function GET(request: Request) {
  const config = await getSiteConfig();
  const off = requireModule(config, "shipping");
  if (off) return off;

  const requested = new URL(request.url).searchParams.get("locale") ?? "";
  const locale = (routing.locales as readonly string[]).includes(requested)
    ? requested
    : routing.defaultLocale;
  const scope = await getFreeShippingScope(locale, config);
  return NextResponse.json(scope, {
    headers: {
      "Cache-Control": "public, max-age=300, s-maxage=300, stale-while-revalidate=600",
    },
  });
}
