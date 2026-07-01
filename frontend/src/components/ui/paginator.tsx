"use client";
import { cn } from "@/lib/utils";

// Paginador genérico basado en número de página (controlado).
// Úsalo para listados paginados por página (blog, resultados de búsqueda).
// Para catálogos de producto con scroll infinito usa `products/infinite-product-grid.tsx`.

type PaginatorVariant = "numbers" | "dots";

export interface PaginatorProps {
  currentPage: number;
  totalPages: number;
  onPageChange: (page: number) => void;
  variant?: PaginatorVariant;
  className?: string;
}

export function Paginator({
  currentPage,
  totalPages,
  onPageChange,
  variant = "numbers",
  className,
}: PaginatorProps) {
  if (totalPages <= 1) return null;
  return variant === "dots" ? (
    <PaginatorDots currentPage={currentPage} totalPages={totalPages} onPageChange={onPageChange} className={className} />
  ) : (
    <PaginatorNumbers currentPage={currentPage} totalPages={totalPages} onPageChange={onPageChange} className={className} />
  );
}

function getVisiblePages(currentPage: number, totalPages: number): (number | "ellipsis")[] {
  if (totalPages <= 5) {
    return Array.from({ length: totalPages }, (_, i) => i + 1);
  }
  if (currentPage <= 3) {
    return [1, 2, 3, "ellipsis", totalPages];
  }
  if (currentPage >= totalPages - 2) {
    return [1, "ellipsis", totalPages - 2, totalPages - 1, totalPages];
  }
  return [1, "ellipsis", currentPage - 1, currentPage, currentPage + 1, "ellipsis", totalPages];
}

const arrowBtn =
  "flex h-9 w-9 items-center justify-center rounded-lg text-sm transition-colors";

function PaginatorNumbers({
  currentPage,
  totalPages,
  onPageChange,
  className,
}: Omit<PaginatorProps, "variant">) {
  const pages = getVisiblePages(currentPage, totalPages);
  return (
    <nav className={cn("flex items-center justify-center gap-1.5", className)} aria-label="Paginación">
      <ArrowButton direction="prev" disabled={currentPage === 1} onClick={() => onPageChange(currentPage - 1)} />
      {pages.map((page, i) =>
        page === "ellipsis" ? (
          <span key={`e-${i}`} className="flex h-9 w-9 items-center justify-center text-sm text-[--foreground]/30">
            …
          </span>
        ) : (
          <button
            key={page}
            type="button"
            onClick={() => onPageChange(page)}
            aria-current={page === currentPage ? "page" : undefined}
            className={cn(
              arrowBtn,
              "font-sans",
              page === currentPage
                ? "bg-brand font-medium text-white shadow-sm"
                : "text-[--foreground]/60 hover:bg-brand/10 hover:text-[--foreground]",
            )}
          >
            {page}
          </button>
        ),
      )}
      <ArrowButton direction="next" disabled={currentPage === totalPages} onClick={() => onPageChange(currentPage + 1)} />
    </nav>
  );
}

function PaginatorDots({
  currentPage,
  totalPages,
  onPageChange,
  className,
}: Omit<PaginatorProps, "variant">) {
  return (
    <nav className={cn("flex items-center justify-center gap-3", className)} aria-label="Paginación">
      <ArrowButton direction="prev" disabled={currentPage === 1} onClick={() => onPageChange(currentPage - 1)} />
      <div className="flex items-center gap-2">
        {Array.from({ length: totalPages }, (_, i) => i + 1).map((page) => (
          <button
            key={page}
            type="button"
            onClick={() => onPageChange(page)}
            aria-label={`Página ${page}`}
            aria-current={page === currentPage ? "page" : undefined}
            className={cn(
              "h-2 rounded-full transition-all duration-200",
              page === currentPage ? "w-6 bg-brand" : "w-2 bg-gray-300 hover:bg-brand/50 dark:bg-gray-600",
            )}
          />
        ))}
      </div>
      <ArrowButton direction="next" disabled={currentPage === totalPages} onClick={() => onPageChange(currentPage + 1)} />
    </nav>
  );
}

function ArrowButton({
  direction,
  disabled,
  onClick,
}: {
  direction: "prev" | "next";
  disabled: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      aria-label={direction === "prev" ? "Anterior" : "Siguiente"}
      className={cn(
        arrowBtn,
        disabled
          ? "cursor-not-allowed text-[--foreground]/25"
          : "text-[--foreground]/60 hover:bg-brand/10 hover:text-[--foreground]",
      )}
    >
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        {direction === "prev" ? <path d="M15 19l-7-7 7-7" /> : <path d="M9 5l7 7-7 7" />}
      </svg>
    </button>
  );
}
