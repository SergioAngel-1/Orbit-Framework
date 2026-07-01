import type { HTMLAttributes, ReactNode } from "react";
import { cn } from "@/lib/utils";

type Variant = "solid" | "outline" | "soft";
type Color   = "brand" | "secondary" | "accent" | "success" | "warning" | "error" | "gray";
type Size    = "sm" | "md";

export interface BadgeProps extends HTMLAttributes<HTMLSpanElement> {
  variant?: Variant;
  color?: Color;
  size?: Size;
  children: ReactNode;
}

const colors: Record<Color, Record<Variant, string>> = {
  brand: {
    solid:   "bg-brand text-white",
    outline: "border border-brand text-brand",
    soft:    "bg-brand/10 text-brand",
  },
  secondary: {
    solid:   "bg-secondary text-white",
    outline: "border border-secondary text-secondary",
    soft:    "bg-secondary/10 text-secondary",
  },
  accent: {
    solid:   "bg-accent text-white",
    outline: "border border-accent text-accent",
    soft:    "bg-accent/10 text-accent",
  },
  success: {
    solid:   "bg-emerald-600 text-white",
    outline: "border border-emerald-600 text-emerald-700 dark:text-emerald-400",
    soft:    "bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400",
  },
  warning: {
    solid:   "bg-amber-500 text-white",
    outline: "border border-amber-500 text-amber-700 dark:text-amber-400",
    soft:    "bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-400",
  },
  error: {
    solid:   "bg-red-600 text-white",
    outline: "border border-red-500 text-red-700 dark:text-red-400",
    soft:    "bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-400",
  },
  gray: {
    solid:   "bg-gray-600 text-white dark:bg-gray-500",
    outline: "border border-gray-400 text-gray-700 dark:text-gray-300",
    soft:    "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300",
  },
};

const sizes: Record<Size, string> = {
  sm: "px-1.5 py-0.5 text-xs",
  md: "px-2.5 py-1   text-xs",
};

export function Badge({
  variant  = "soft",
  color    = "brand",
  size     = "md",
  className,
  children,
  ...props
}: BadgeProps) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 rounded-full font-medium leading-none",
        sizes[size],
        colors[color][variant],
        className,
      )}
      {...props}
    >
      {children}
    </span>
  );
}
