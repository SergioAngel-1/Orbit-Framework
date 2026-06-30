import { getSiteConfig } from "@/lib/config";
import { getCategories } from "@/lib/catalog/products";
import { stripHtml } from "@/lib/format";

// /llms.txt — superficie machine-readable para agentes de IA (estándar emergente).
// Generado dinámicamente desde el Control Center + catálogo. Sin ficheros estáticos.
// Se puede desactivar desde GEO → "Generar /llms.txt".
export const runtime = "nodejs";
export const revalidate = 300;

function cleanBase(url: string): string {
  return (url || "").replace(/\/$/, "");
}

export async function GET(): Promise<Response> {
  const config = await getSiteConfig();

  if (!config.geo.llms_txt_enabled) {
    return new Response("Not found", { status: 404 });
  }

  const base = cleanBase(config.brand.url);
  const name = config.brand.name;
  const summary = config.brand.tagline || config.brand.description || "";

  const lines: string[] = [];
  lines.push(`# ${name}`);
  if (summary) lines.push("", `> ${summary}`);
  if (config.brand.description) lines.push("", config.brand.description);

  lines.push("", "## Páginas principales");
  lines.push(`- [Inicio](${base}/): Página principal con las últimas publicaciones y novedades de la tienda.`);
  lines.push(`- [Tienda](${base}/products): Catálogo completo de productos con búsqueda, filtros por categoría y precio.`);
  lines.push(`- [Blog](${base}/blog): Artículos, guías y novedades publicados por la marca.`);

  try {
    const categories = await getCategories();
    if (categories.length > 0) {
      lines.push("", "## Categorías");
      for (const cat of categories) {
        const desc = stripHtml(cat.description ?? "").slice(0, 120);
        lines.push(
          `- [${cat.name}](${base}/categories/${cat.slug}): ${desc || `Productos de la categoría ${cat.name}.`}`,
        );
      }
    }
  } catch {
    /* sin categorías disponibles */
  }

  lines.push("", "## Legal");
  lines.push(`- [Privacidad](${base}/legal/privacy): Política de privacidad y tratamiento de datos personales.`);
  lines.push(`- [Cookies](${base}/legal/cookies): Política de cookies y tecnologías de seguimiento.`);
  lines.push(`- [Términos](${base}/legal/terms): Términos y condiciones de uso y de compra.`);
  lines.push(`- [Devoluciones](${base}/legal/returns): Política de devoluciones y derecho de desistimiento.`);

  // Key Facts: datos de referencia que la IA consulta con frecuencia.
  const facts: string[] = [];
  if (config.legal.company) facts.push(`- Razón social: ${config.legal.company}`);
  if (config.seo.founding_date) facts.push(`- Fundación: ${config.seo.founding_date}`);
  if (config.ecommerce.country) facts.push(`- País: ${config.ecommerce.country}`);
  if (config.ecommerce.currency) facts.push(`- Moneda: ${config.ecommerce.currency}`);
  if (config.legal.email) facts.push(`- Contacto: ${config.legal.email}`);
  if (facts.length > 0) {
    lines.push("", "## Key Facts", ...facts);
  }

  if (config.legal.email) {
    lines.push("", "## Contacto", `- Email: ${config.legal.email}`);
  }

  lines.push("", "## Recursos", `- [Sitemap XML](${base}/sitemap.xml)`);
  lines.push("");

  return new Response(lines.join("\n"), {
    headers: {
      "Content-Type": "text/plain; charset=utf-8",
      "Cache-Control": "public, max-age=0, s-maxage=300, stale-while-revalidate=86400",
    },
  });
}
