"use client";
import { useRef, useState } from "react";
import { useTranslations } from "next-intl";
import { Link, usePathname, useRouter } from "@/i18n/navigation";
import { TagLink } from "./tag-link";
import { HUBSPOT_DO_NOT_COLLECT } from "@/lib/analytics/hubspot";
import { blogCategoryHref } from "@/lib/blog/category-format";

interface Props {
  initialQuery: string;
  tags: { name: string; slug: string; urlSlug: string; count?: number | null }[];
  categories: { name: string; slug: string }[];
}

/** Pausa tras la última pulsación antes de buscar. */
const DEBOUNCE_MS = 350;

/**
 * Buscador del blog: busca mientras se escribe (`/blog?search=`), con las
 * categorías y las etiquetas más usadas como atajos. Siempre en la misma
 * posición del árbol: si el aviso de «sin resultados» lo remontara, se perdería
 * el foco a mitad de palabra.
 */
export function BlogSearchBar({ initialQuery, tags, categories }: Props) {
  const t = useTranslations("blog");
  const router = useRouter();
  const pathname = usePathname();
  const [value, setValue] = useState(initialQuery);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  function go(query: string) {
    const trimmed = query.trim();
    router.replace(
      trimmed ? { pathname: "/blog", query: { search: trimmed } } : "/blog",
      { scroll: false },
    );
  }

  return (
    <div className="mb-8 space-y-4">
      <form
        {...HUBSPOT_DO_NOT_COLLECT}
        role="search"
        action="/blog"
        method="get"
        onSubmit={(event) => {
          event.preventDefault();
          if (timer.current) clearTimeout(timer.current);
          go(value);
        }}
      >
        <label htmlFor="blog-search" className="sr-only">
          {t("searchLabel")}
        </label>
        <input
          id="blog-search"
          type="search"
          name="search"
          value={value}
          maxLength={100}
          placeholder={t("searchPlaceholder")}
          onChange={(event) => {
            const next = event.target.value;
            setValue(next);
            if (timer.current) clearTimeout(timer.current);
            timer.current = setTimeout(() => go(next), DEBOUNCE_MS);
          }}
          className="w-full max-w-md rounded-lg border border-gray-300 px-4 py-2 dark:border-gray-700 dark:bg-gray-900"
        />
      </form>

      {categories.length > 0 && (
        <ul className="flex flex-wrap gap-2" aria-label={t("categories")}>
          {categories.map((category) => (
            <li key={category.slug}>
              <Link
                href={blogCategoryHref(category.slug)}
                aria-current={
                  pathname === `/blog/category/${category.slug}` ? "page" : undefined
                }
                className="inline-flex rounded-full border border-gray-300 px-3 py-1 text-xs font-medium transition-colors hover:border-brand dark:hover:border-accent hover:text-brand dark:hover:text-accent dark:border-gray-700"
              >
                {category.name}
              </Link>
            </li>
          ))}
        </ul>
      )}

      {tags.length > 0 && (
        <details className="text-sm">
          <summary className="cursor-pointer font-medium text-gray-700 dark:text-gray-200">
            {t("popularTags")}
          </summary>
          <ul className="mt-3 flex flex-wrap gap-2">
            {tags.map((tag) => (
              <li key={tag.slug}>
                <TagLink tag={tag} size="md" showCount />
              </li>
            ))}
          </ul>
        </details>
      )}
    </div>
  );
}
