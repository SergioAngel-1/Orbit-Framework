import { sanitizeHtml } from "@/lib/security/sanitize";
import { PostCard } from "@/components/blog/post-card";
import { ProductCard } from "@/components/products/product-card";
import type { PostSegment } from "@/lib/blog/post-content";
import type { ResolvedRec } from "@/lib/blog/resolve-recs";

/**
 * Cuerpo de una entrada: la prosa del CMS (SIEMPRE saneada, tramo a tramo) y, en
 * su sitio, las recomendaciones que el autor incrustó con `<!-- hwe-rec -->`. Una
 * recomendación que no resolvió (`null`) no pinta nada.
 */
export function PostBody({
  segments,
  recs,
  locale,
  readMore,
  recommendedLabel,
  wishlistEnabled = false,
}: {
  segments: PostSegment[];
  recs: Array<ResolvedRec | null>;
  locale: string;
  readMore: string;
  recommendedLabel: string;
  wishlistEnabled?: boolean;
}) {
  return (
    <div className="post-body">
      {segments.map((segment, i) => {
        if (segment.kind === "html") {
          return (
            <div
              key={i}
              className="prose prose-lg max-w-none dark:prose-invert"
              dangerouslySetInnerHTML={{ __html: sanitizeHtml(segment.html) }}
            />
          );
        }
        const rec = recs[segment.index];
        if (!rec) return null;
        return (
          <aside
            key={i}
            className="not-prose my-8 max-w-sm"
            aria-label={recommendedLabel}
          >
            {rec.type === "product" ? (
              <ProductCard product={rec.product} wishlistEnabled={wishlistEnabled} />
            ) : (
              <PostCard post={rec.post} locale={locale} readMore={readMore} />
            )}
          </aside>
        );
      })}
    </div>
  );
}
