import Image from "next/image";
import { Link } from "@/i18n/navigation";
import { cleanExcerpt, formatDate } from "@/lib/format";
import { blogPostHref } from "@/lib/blog/post-href";
import { AuthorLink } from "./author-link";
import { CategoryLink } from "./category-link";
import { TagLink } from "./tag-link";
import { isLandingCategory } from "@/lib/blog/category-format";
import type { WPPost } from "@/types/wordpress";

/** Etiquetas visibles en una tarjeta; el listado completo está en la entrada. */
const CARD_TAG_LIMIT = 3;

/**
 * Tarjeta de entrada. Compartida entre la home (últimas publicaciones) y el
 * índice del blog.
 *
 * El enlace a la entrada es el del TÍTULO, estirado sobre toda la tarjeta
 * (`after:absolute after:inset-0`), y no un `<a>` que envuelva todo: la
 * categoría, el autor y las etiquetas son enlaces propios (`relative z-10`) y un
 * enlace dentro de otro no es HTML válido.
 */
export function PostCard({
  post,
  locale,
  readMore,
}: {
  post: WPPost;
  locale: string;
  readMore: string;
}) {
  const image = post.featuredImage?.node;
  const author = post.author?.node;
  const tags = post.tags?.nodes ?? [];
  const category = (post.categories?.nodes ?? []).find((c) =>
    isLandingCategory(c.slug),
  );

  return (
    <article className="group relative flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition-shadow focus-within:shadow-md hover:shadow-md dark:border-gray-800 dark:bg-gray-900">
      {image?.sourceUrl ? (
        <div className="relative aspect-[16/9] w-full overflow-hidden bg-gray-100 dark:bg-gray-800">
          <Image
            src={image.sourceUrl}
            alt={image.altText || ""}
            fill
            sizes="(max-width: 768px) 100vw, 50vw"
            className="object-cover transition-transform duration-300 group-hover:scale-105"
          />
        </div>
      ) : (
        <div
          aria-hidden
          className="flex aspect-[16/9] w-full items-center justify-center bg-gradient-to-br from-brand-light to-brand-dark text-2xl font-bold text-white"
        >
          {post.title.charAt(0).toUpperCase()}
        </div>
      )}

      <div className="flex flex-1 flex-col p-6">
        <div className="mb-2 flex flex-wrap items-center gap-x-2 text-xs font-medium text-gray-500 dark:text-gray-400">
          {category && (
            <>
              <CategoryLink category={category} />
              <span aria-hidden>·</span>
            </>
          )}
          <time dateTime={post.date}>{formatDate(post.date, locale)}</time>
          {author?.name && (
            <>
              <span aria-hidden>·</span>
              <AuthorLink author={author} />
            </>
          )}
        </div>

        <h2 className="mb-2 text-xl font-semibold leading-snug tracking-tight transition-colors group-hover:text-brand">
          <Link
            href={blogPostHref(post)}
            className="after:absolute after:inset-0 after:content-['']"
          >
            {post.title}
          </Link>
        </h2>

        {post.excerpt && (
          <p className="line-clamp-3 flex-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
            {cleanExcerpt(post.excerpt, 180)}
          </p>
        )}

        {tags.length > 0 && (
          <ul className="mt-4 flex flex-wrap items-center gap-1.5">
            {tags.slice(0, CARD_TAG_LIMIT).map((tag) => (
              <li key={tag.slug} className="min-w-0">
                <TagLink tag={tag} />
              </li>
            ))}
          </ul>
        )}

        <span className="mt-4 inline-flex items-center text-sm font-medium text-brand">
          {readMore}
        </span>
      </div>
    </article>
  );
}
