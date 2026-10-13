import type { ReactNode } from "react";
import { Link } from "@/i18n/navigation";
import { cn } from "@/lib/utils";

/**
 * Nombre de autor enlazado a `/blog?author=slug`. Sin tipografía propia: quien
 * lo usa pasa las clases de su contexto. Sin `slug` (WordPress no lo expuso),
 * texto plano: un enlace a un filtro que no se puede resolver llevaría a vacío.
 * `relative z-10`: gana el clic al enlace estirado de la tarjeta (ver `TagLink`).
 */
export function AuthorLink({
  author,
  className,
  children,
}: {
  author: { name: string; slug?: string | null };
  className?: string;
  children?: ReactNode;
}) {
  if (!author.slug) {
    return <span className={className}>{children ?? author.name}</span>;
  }
  return (
    <Link
      href={{ pathname: "/blog", query: { author: author.slug } }}
      className={cn(
        "relative z-10 underline-offset-2 transition-colors hover:text-brand hover:underline",
        className,
      )}
    >
      {children ?? author.name}
    </Link>
  );
}
