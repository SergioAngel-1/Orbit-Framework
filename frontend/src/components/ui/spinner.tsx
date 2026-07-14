import { cn } from "@/lib/utils";

type Size = "xs" | "sm" | "md" | "lg";
type Color = "brand" | "white" | "current";

export interface SpinnerProps {
  size?: Size;
  color?: Color;
  className?: string;
  label?: string;
}

const sizes: Record<Size, string> = {
  xs: "h-3 w-3 border",
  sm: "h-4 w-4 border-2",
  md: "h-6 w-6 border-2",
  lg: "h-8 w-8 border-[3px]",
};

const colors: Record<Color, string> = {
  brand: "border-brand/30 border-t-brand",
  white: "border-white/30 border-t-white",
  current: "border-current/30 border-t-current",
};

export function Spinner({
  size = "md",
  color = "brand",
  className,
  label = "Cargando…",
}: SpinnerProps) {
  return (
    <span
      role="status"
      aria-label={label}
      className={cn(
        "inline-block animate-spin rounded-full",
        sizes[size],
        colors[color],
        className,
      )}
    />
  );
}
