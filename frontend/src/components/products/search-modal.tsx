"use client";
import { useEffect, useRef, useState } from "react";
import { Link } from "@/i18n/navigation";
import { Modal } from "@/components/ui/modal";
import { Input } from "@/components/ui/input";
import { ProductCardHorizontal } from "./product-card-horizontal";
import { cn } from "@/lib/utils";
import type { CatalogProduct } from "@/types/catalog";

export interface SearchResultItem {
  id: string;
  type: "post" | "category" | "page";
  title: string;
  subtitle?: string;
  href: string;
}

export interface SearchModalProps {
  open: boolean;
  onClose: () => void;
  /** Se invoca (con debounce a cargo del padre) cada vez que cambia la consulta. */
  onSearch?: (query: string) => void;
  /** Resultados de producto ya cargados por el padre. */
  productResults?: CatalogProduct[];
  /** Otros resultados (blog, categorías, páginas) ya cargados por el padre. */
  otherResults?: SearchResultItem[];
  placeholder?: string;
}

const typeLabels: Record<SearchResultItem["type"], string> = {
  post: "Artículos",
  category: "Categorías",
  page: "Páginas",
};

// Modal de búsqueda global presentacional: recibe los resultados por props.
// TODO: conectar `onSearch` a la Store API de WooCommerce (y a WPGraphQL para posts/páginas).
export function SearchModal({
  open,
  onClose,
  onSearch,
  productResults = [],
  otherResults = [],
  placeholder = "Buscar productos, artículos...",
}: SearchModalProps) {
  const [query, setQuery] = useState("");
  const inputRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (open) {
      const id = setTimeout(() => inputRef.current?.focus(), 100);
      return () => clearTimeout(id);
    }
    setQuery("");
  }, [open]);

  const handleChange = (value: string) => {
    setQuery(value);
    onSearch?.(value);
  };

  const hasResults = productResults.length > 0 || otherResults.length > 0;

  return (
    <Modal open={open} onClose={onClose} title="Buscar" size="lg" className="max-w-xl">
      <div className="flex flex-col gap-4">
        <Input
          ref={inputRef}
          type="search"
          value={query}
          onChange={(e) => handleChange(e.target.value)}
          placeholder={placeholder}
          leading={<SearchIcon />}
        />

        {query.length > 0 && !hasResults ? (
          <div className="flex flex-col items-center justify-center py-10 text-center">
            <p className="mb-1 font-sans text-sm text-[--foreground]/60">
              Sin resultados para &quot;{query}&quot;
            </p>
            <p className="font-sans text-xs text-[--foreground]/40">
              Intenta con otros términos
            </p>
          </div>
        ) : (
          <div className="flex max-h-[400px] flex-col gap-4 overflow-y-auto">
            {productResults.length > 0 && (
              <section>
                <p className="mb-2 px-1 font-sans text-[9px] uppercase tracking-[0.2em] text-[--foreground]/40">
                  Productos
                </p>
                <div className="flex flex-col gap-1">
                  {productResults.map((product) => (
                    <ProductCardHorizontal
                      key={product.id}
                      product={product}
                      onClick={onClose}
                    />
                  ))}
                </div>
              </section>
            )}

            {otherResults.length > 0 && (
              <section>
                <p className="mb-2 px-1 font-sans text-[9px] uppercase tracking-[0.2em] text-[--foreground]/40">
                  Más resultados
                </p>
                <div className="flex flex-col gap-1">
                  {otherResults.map((result) => (
                    <Link
                      key={result.id}
                      href={result.href}
                      onClick={onClose}
                      className="flex items-center gap-3 rounded-xl border border-transparent px-3 py-3 text-left transition-all duration-150 hover:border-gray-200 hover:bg-surface dark:hover:border-gray-700 dark:hover:bg-gray-800"
                    >
                      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-surface text-[--foreground]/50 dark:bg-gray-800">
                        <ResultTypeIcon type={result.type} />
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block truncate font-sans text-[13px] font-medium text-[--foreground]">
                          {result.title}
                        </span>
                        <span className="block truncate font-sans text-[11px] text-[--foreground]/50">
                          {result.subtitle ?? typeLabels[result.type]}
                        </span>
                      </span>
                    </Link>
                  ))}
                </div>
              </section>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
}

function SearchIcon() {
  return (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
    </svg>
  );
}

function ResultTypeIcon({ type }: { type: SearchResultItem["type"] }) {
  const common = {
    width: 16,
    height: 16,
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: 2,
    strokeLinecap: "round",
    strokeLinejoin: "round",
    "aria-hidden": true,
  } as const;
  if (type === "post") {
    return (
      <svg {...common}>
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
        <polyline points="14 2 14 8 20 8" />
        <line x1="8" y1="13" x2="16" y2="13" />
        <line x1="8" y1="17" x2="16" y2="17" />
      </svg>
    );
  }
  if (type === "category") {
    return (
      <svg {...common}>
        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z" />
        <line x1="7" y1="7" x2="7.01" y2="7" />
      </svg>
    );
  }
  return (
    <svg {...common}>
      <path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z" />
      <polyline points="13 2 13 9 20 9" />
    </svg>
  );
}
