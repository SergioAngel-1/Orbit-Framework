import { Link } from "@/i18n/navigation";
import { cn } from "@/lib/utils";

const PILL_BASE =
  "inline-flex max-w-full items-center gap-1 rounded-full border font-medium leading-none";

/**
 * Píldora de etiqueta de WordPress. Enlaza a `/blog?tag=<urlSlug>` solo si hay
 * más de una entrada publicada con esa etiqueta (`tag.count`, que ya viene en la
 * query de entradas — sin round-trip extra): con una única, ese resultado es la
 * entrada que ya se está viendo, el filtro no aporta nada y la etiqueta se
 * muestra como texto plano.
 *
 * `relative z-10` en la variante clicable es necesario para ganarle el clic al
 * enlace estirado del título de la tarjeta que la contiene (ver `PostCard`).
 */
export function TagLink({
  tag,
  size = "sm",
  showCount = false,
}: {
  /** `urlSlug`: valor de `?tag=` en el idioma de la página. */
  tag: { name: string; slug: string; urlSlug?: string; count?: number | null };
  size?: "sm" | "md";
  /**
   * Pinta cuántas entradas tiene la etiqueta. Solo para listas ORDENADAS por uso
   * (los «tags populares» del buscador), donde el número explica el orden.
   */
  showCount?: boolean;
}) {
  const isClickable = (tag.count ?? 2) > 1;
  const sizeClass = size === "sm" ? "px-2 py-1 text-[11px]" : "px-2.5 py-1 text-xs";
  const count = tag.count ?? 0;

  const content = (
    <>
      <span aria-hidden className="opacity-50">
        #
      </span>
      {/* `min-w-0`: hijo de un `inline-flex`, sin él no baja de su ancho natural
          y el `truncate` nunca corta. */}
      <span className="min-w-0 max-w-[220px] truncate">{tag.name}</span>
      {showCount && count > 0 && (
        <span aria-hidden className="tabular-nums opacity-60">
          {count}
        </span>
      )}
    </>
  );

  if (!isClickable) {
    return (
      <span
        className={cn(
          PILL_BASE,
          sizeClass,
          "cursor-default border-gray-200 bg-gray-50 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300",
        )}
      >
        {content}
      </span>
    );
  }

  return (
    <Link
      href={{ pathname: "/blog", query: { tag: tag.urlSlug ?? tag.slug } }}
      className={cn(
        PILL_BASE,
        sizeClass,
        "relative z-10 border-brand/20 bg-brand/10 text-brand dark:border-accent/30 dark:bg-accent/10 dark:text-accent transition-colors hover:border-brand/40 dark:hover:border-accent/40 hover:bg-brand/20 dark:hover:bg-accent/20",
      )}
    >
      {content}
    </Link>
  );
}
