import type { PostHeading } from "@/lib/blog/post-content";

/**
 * Índice de una entrada (h2/h3). No se pinta con menos de tres encabezados: una
 * tabla de contenidos de dos líneas es ruido.
 */
export function PostToc({
  headings,
  title,
}: {
  headings: PostHeading[];
  title: string;
}) {
  if (headings.length < 3) return null;
  return (
    <nav
      aria-label={title}
      className="mb-8 rounded-xl border border-gray-200 bg-gray-50 p-5 dark:border-gray-800 dark:bg-gray-900"
    >
      <p className="mb-2 text-sm font-semibold">{title}</p>
      <ol className="space-y-1 text-sm">
        {headings.map((heading) => (
          <li key={heading.id} className={heading.level === 3 ? "ml-4" : undefined}>
            <a
              href={`#${heading.id}`}
              className="text-gray-600 transition-colors hover:text-brand dark:hover:text-accent dark:text-gray-300"
            >
              {heading.text}
            </a>
          </li>
        ))}
      </ol>
    </nav>
  );
}
