"use client";
import { type ButtonHTMLAttributes, type ReactNode, type Ref } from "react";
import { Spinner } from "./spinner";
import { cn } from "@/lib/utils";

// ─── Props ──────────────────────────────────────────────────────────────────

type Variant = "solid" | "secondary" | "outline" | "ghost" | "destructive" | "link";
type Size    = "xs" | "sm" | "md" | "lg" | "icon";

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  /** Estilo visual del botón. Default: `solid`. */
  variant?: Variant;
  /** Tamaño. Default: `md`. */
  size?: Size;
  /** Muestra un spinner y bloquea interacción. */
  loading?: boolean;
  /** Icono (SVG o componente) antes del texto. */
  leadingIcon?: ReactNode;
  /** Icono (SVG o componente) después del texto. */
  trailingIcon?: ReactNode;
  /** Ocupa el ancho completo del contenedor. */
  fullWidth?: boolean;
  /** Ref reenviada al `<button>` nativo (React 19: ref como prop). */
  ref?: Ref<HTMLButtonElement>;
}

// ─── Variantes ──────────────────────────────────────────────────────────────

const base =
  "inline-flex items-center justify-center gap-2 rounded-lg font-semibold transition-colors " +
  "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 " +
  "focus-visible:ring-brand/60 disabled:pointer-events-none disabled:opacity-50 " +
  "select-none";

const variants: Record<Variant, string> = {
  solid:
    "bg-brand text-white hover:bg-brand-dark active:bg-brand-dark/90",
  secondary:
    "bg-secondary text-white hover:bg-secondary-dark active:bg-secondary-dark/90",
  outline:
    "border border-brand text-brand bg-transparent hover:bg-brand/8 active:bg-brand/15",
  ghost:
    "text-brand bg-transparent hover:bg-brand/8 active:bg-brand/15",
  destructive:
    "bg-red-600 text-white hover:bg-red-700 active:bg-red-700/90 focus-visible:ring-red-500/60",
  link:
    "text-brand underline-offset-4 hover:underline p-0 h-auto rounded-none",
};

const sizes: Record<Size, string> = {
  xs:   "h-7  px-2.5 text-xs  gap-1",
  sm:   "h-8  px-3   text-sm  gap-1.5",
  md:   "h-10 px-4   text-sm  gap-2",
  lg:   "h-12 px-6   text-base gap-2.5",
  icon: "h-10 w-10 p-0 flex-none",
};

// ─── Componente ─────────────────────────────────────────────────────────────

export function Button({
  variant = "solid",
  size = "md",
  loading = false,
  leadingIcon,
  trailingIcon,
  fullWidth = false,
  className,
  disabled,
  children,
  ref,
  ...props
}: ButtonProps) {
  return (
    <button
      ref={ref}
      disabled={disabled || loading}
      className={cn(
        base,
        variants[variant],
        sizes[size],
        fullWidth && "w-full",
        className,
      )}
      {...props}
    >
      {loading ? (
        <Spinner
          size="xs"
          color={variant === "solid" || variant === "secondary" || variant === "destructive" ? "white" : "brand"}
        />
      ) : (
        leadingIcon
      )}
      {children}
      {!loading && trailingIcon}
    </button>
  );
}
