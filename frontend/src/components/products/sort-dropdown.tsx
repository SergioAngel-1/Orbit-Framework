"use client";
import { Select, type SelectOption } from "@/components/ui/select";
import { cn } from "@/lib/utils";

export interface SortDropdownProps {
  options: SelectOption[];
  value: string;
  onChange: (value: string) => void;
  /** Etiqueta accesible del control (no visible). */
  label?: string;
  className?: string;
}

// Envuelve el primitivo `Select` con un icono de ordenamiento y una etiqueta a11y.
export function SortDropdown({
  options,
  value,
  onChange,
  label = "Ordenar por",
  className,
}: SortDropdownProps) {
  return (
    <div className={cn("relative shrink-0", className)}>
      <span className="pointer-events-none absolute left-3 top-1/2 z-10 -translate-y-1/2 text-gray-400">
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
          <line x1="4" y1="6" x2="20" y2="6" />
          <line x1="8" y1="12" x2="20" y2="12" />
          <line x1="12" y1="18" x2="20" y2="18" />
        </svg>
      </span>
      <Select
        aria-label={label}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        options={options}
        className="pl-9"
      />
    </div>
  );
}
