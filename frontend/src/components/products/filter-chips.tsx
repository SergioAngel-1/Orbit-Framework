"use client";
import { cn } from "@/lib/utils";

export interface FilterChipOption {
  value: string;
  label: string;
  /** Contador opcional que se muestra junto a la etiqueta. */
  count?: number;
}

export interface FilterChipsProps {
  options: FilterChipOption[];
  active?: string;
  onChange?: (value: string) => void;
  className?: string;
}

export function FilterChips({ options, active, onChange, className }: FilterChipsProps) {
  return (
    <div
      className={cn("flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden", className)}
    >
      {options.map((opt) => {
        const isActive = active === opt.value;
        return (
          <button
            key={opt.value}
            type="button"
            onClick={() => onChange?.(opt.value)}
            className={cn(
              "flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border px-4 py-2 font-sans text-xs transition-all duration-200",
              isActive
                ? "border-brand bg-brand text-white shadow-sm"
                : "border-gray-200 bg-surface text-[--foreground]/60 hover:border-brand/40 hover:text-[--foreground] dark:border-gray-700 dark:bg-gray-800",
            )}
          >
            {opt.label}
            {opt.count !== undefined && (
              <span
                className={cn(
                  "rounded-full px-1.5 py-0.5 text-[10px]",
                  isActive ? "bg-white/20 text-white" : "bg-gray-200 text-[--foreground]/50 dark:bg-gray-700",
                )}
              >
                {opt.count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
