import { Link } from "@/i18n/navigation";
import { cn } from "@/lib/utils";

export interface BreadcrumbItem {
  label: string;
  href?: string;
}

export interface BreadcrumbProps {
  items: BreadcrumbItem[];
  /** Paleta clara para usar sobre fondos oscuros (heros con overlay). */
  light?: boolean;
  className?: string;
}

export function Breadcrumb({ items, light = false, className }: BreadcrumbProps) {
  return (
    <nav aria-label="Breadcrumb" className={className}>
      <ol className="flex flex-wrap items-center gap-1.5">
        {items.map((item, idx) => {
          const isLast = idx === items.length - 1;
          return (
            <li key={idx} className="flex items-center gap-1.5">
              {item.href && !isLast ? (
                <Link
                  href={item.href}
                  className={cn(
                    "text-[11px] transition-colors duration-150",
                    light
                      ? "text-white/50 hover:text-white"
                      : "text-[--foreground]/40 hover:text-brand",
                  )}
                >
                  {item.label}
                </Link>
              ) : (
                <span
                  className={cn(
                    "text-[11px]",
                    isLast
                      ? light
                        ? "text-white/80"
                        : "text-[--foreground]/70"
                      : light
                        ? "text-white/50"
                        : "text-[--foreground]/40",
                  )}
                >
                  {item.label}
                </span>
              )}
              {!isLast && (
                <svg
                  className={cn(
                    "h-3 w-3 shrink-0",
                    light ? "text-white/30" : "text-[--foreground]/25",
                  )}
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <path d="M9 18l6-6-6-6" />
                </svg>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
