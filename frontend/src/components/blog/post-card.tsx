import Image from "next/image";
import { Link } from "@/i18n/navigation";
import { formatDate, stripHtml } from "@/lib/format";
import type { WPPost } from "@/types/wordpress";

/**
 * Tarjeta de post enlazada a su página de detalle (/blog/[slug]).
 * Compartida entre la home (últimas publicaciones) y el índice del blog.
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

  return (
    <Link
      href={`/blog/${post.slug}`}
      className="group flex flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm transition-shadow hover:shadow-md dark:border-gray-800 dark:bg-gray-900"
    >
      {image?.sourceUrl ? (
        <div className="relative aspect-[16/9] w-full overflow-hidden bg-gray-100 dark:bg-gray-800">
          <Image
            src={image.sourceUrl}
            alt={image.altText || post.title}
            fill
            sizes="(max-width: 768px) 100vw, 50vw"
            className="object-cover transition-transform duration-300 group-hover:scale-105"
          />
        </div>
      ) : (
        <div className="flex aspect-[16/9] w-full items-center justify-center bg-gradient-to-br from-brand-light to-brand-dark text-2xl font-bold text-white">
          {post.title.charAt(0).toUpperCase()}
        </div>
      )}

      <div className="flex flex-1 flex-col p-6">
        <div className="mb-2 flex items-center gap-2 text-xs font-medium text-gray-500 dark:text-gray-400">
          <time dateTime={post.date}>{formatDate(post.date, locale)}</time>
          {post.author?.node?.name && (
            <>
              <span aria-hidden>·</span>
              <span>{post.author.node.name}</span>
            </>
          )}
        </div>

        <h2 className="mb-2 text-xl font-semibold leading-snug tracking-tight transition-colors group-hover:text-brand">
          {post.title}
        </h2>

        {post.excerpt && (
          <p className="line-clamp-3 flex-1 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
            {stripHtml(post.excerpt)}
          </p>
        )}

        <span className="mt-4 inline-flex items-center text-sm font-medium text-brand">
          {readMore}
        </span>
      </div>
    </Link>
  );
}
