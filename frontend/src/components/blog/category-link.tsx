import { Link } from "@/i18n/navigation";
import { blogCategoryHref, isLandingCategory } from "@/lib/blog/category-format";
import { cn } from "@/lib/utils";

/**
 * Categoría de una entrada. Con página propia enlaza a ella; un cajón
 * («Uncategorized») no es un tema y se pinta como texto plano. `relative z-10`
 * por el mismo motivo que `TagLink`.
 */
export function CategoryLink({
  category,
  className,
}: {
  category: { name: string; slug: string };
  className?: string;
}) {
  if (!isLandingCategory(category.slug)) {
    return <span className={className}>{category.name}</span>;
  }
  return (
    <Link
      href={blogCategoryHref(category.slug)}
      className={cn(
        "relative z-10 font-semibold uppercase tracking-wide transition-colors hover:text-brand",
        className,
      )}
    >
      {category.name}
    </Link>
  );
}
