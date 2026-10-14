import { buildFaqJsonLd } from "@/lib/seo/jsonld";
import { safeJsonLd } from "@/lib/seo/safe-json-ld";
import type { FaqItem } from "@/lib/seo/faq";

function FaqList({ items }: { items: FaqItem[] }) {
  return (
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
  );
}

/**
 * Sección de preguntas frecuentes (GEO). Renderiza el FAQ de forma VISIBLE
 * (requisito de Google para FAQPage) y emite el JSON-LD `FAQPage` asociado.
 *
 * `paymentItems` (`geo.faq_payments`) son las preguntas sobre pagos electrónicos:
 * van en una segunda columna con su `paymentsTitle` y entran en el MISMO
 * `FAQPage`. Sin ellas, una sola columna. No renderiza nada si no hay items.
 */
export function FaqSection({
  items,
  title,
  paymentItems = [],
  paymentsTitle,
}: {
  items: FaqItem[];
  title: string;
  paymentItems?: FaqItem[];
  paymentsTitle?: string;
}) {
  if (items.length === 0 && paymentItems.length === 0) return null;

  const jsonLd = buildFaqJsonLd([...items, ...paymentItems]);
  const twoColumns = items.length > 0 && paymentItems.length > 0;

  return (
    <section className="mt-16">
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: safeJsonLd(jsonLd) }}
      />
      {twoColumns ? (
        <div className="grid gap-x-12 gap-y-8 md:grid-cols-2">
          <div>
            <h2 className="mb-6 text-2xl font-bold tracking-tight">{title}</h2>
            <FaqList items={items} />
          </div>
          <div>
            <h2 className="mb-6 text-2xl font-bold tracking-tight">
              {paymentsTitle ?? title}
            </h2>
            <FaqList items={paymentItems} />
          </div>
        </div>
      ) : (
        <>
          <h2 className="mb-6 text-2xl font-bold tracking-tight">
            {items.length > 0 ? title : (paymentsTitle ?? title)}
          </h2>
          <FaqList items={items.length > 0 ? items : paymentItems} />
        </>
      )}
    </section>
  );
}
