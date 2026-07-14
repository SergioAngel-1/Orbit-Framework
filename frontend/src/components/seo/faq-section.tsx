import { buildFaqJsonLd } from "@/lib/seo/jsonld";
import type { FaqItem } from "@/lib/seo/faq";

/**
 * Sección de preguntas frecuentes (GEO). Renderiza el FAQ de forma VISIBLE
 * (requisito de Google para FAQPage) y emite el JSON-LD `FAQPage` asociado.
 * No renderiza nada si no hay items configurados.
 */
export function FaqSection({ items, title }: { items: FaqItem[]; title: string }) {
  if (items.length === 0) return null;

  const jsonLd = buildFaqJsonLd(items);

  return (
    <section className="mt-16">
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }}
      />
      <h2 className="mb-6 text-2xl font-bold tracking-tight">{title}</h2>
      <dl className="divide-y divide-gray-200 dark:divide-gray-800">
        {items.map((item, i) => (
          <div key={i} className="py-4">
            <dt className="font-semibold text-gray-900 dark:text-gray-100">
              {item.question}
            </dt>
            <dd className="mt-1 text-gray-600 dark:text-gray-300">{item.answer}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}
