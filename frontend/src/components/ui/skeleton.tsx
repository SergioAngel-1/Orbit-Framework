import type { HTMLAttributes } from "react";
import { cn } from "@/lib/utils";

type SkeletonVariant = "text" | "block" | "circle" | "image";

export interface SkeletonProps extends HTMLAttributes<HTMLDivElement> {
  /** Forma del skeleton. Default: `block`. */
  variant?: SkeletonVariant;
  /** Para `variant="text"`: número de líneas de texto simuladas. */
  lines?: number;
  /** Altura explícita (ej. `h-32` o `h-[200px]`). Solo para `block` e `image`. */
  height?: string;
  /** Anchura (ej. `w-1/2`). Default: `w-full`. */
  width?: string;
}

function SkeletonBase({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      aria-hidden="true"
      className={cn("animate-pulse rounded-md bg-gray-200 dark:bg-gray-700", className)}
      {...props}
    />
  );
}

export function Skeleton({
  variant = "block",
  lines = 3,
  height = "h-4",
  width = "w-full",
  className,
  ...props
}: SkeletonProps) {
  if (variant === "text") {
    return (
      <div className={cn("flex flex-col gap-2", className)} aria-hidden="true">
        {Array.from({ length: lines }).map((_, i) => (
          <SkeletonBase
            key={i}
            className={cn("h-4", i === lines - 1 && lines > 1 ? "w-3/4" : "w-full")}
          />
        ))}
      </div>
    );
  }

  if (variant === "circle") {
    return (
      <SkeletonBase
        className={cn("rounded-full", height, width, className)}
        {...props}
      />
    );
  }

  if (variant === "image") {
    return (
      <SkeletonBase
        className={cn("aspect-square w-full rounded-xl", className)}
        {...props}
      />
    );
  }

  // block
  return <SkeletonBase className={cn(height, width, className)} {...props} />;
}

// Skeleton pre-compuesto para tarjetas de producto
export function ProductCardSkeleton() {
  return (
    <div className="flex flex-col gap-3 overflow-hidden rounded-2xl border border-gray-200 bg-white p-0 dark:border-gray-800 dark:bg-gray-900">
      <Skeleton variant="image" />
      <div className="flex flex-col gap-2 p-4">
        <Skeleton variant="block" height="h-5" width="w-3/4" />
        <Skeleton variant="block" height="h-4" width="w-1/3" />
        <Skeleton variant="block" height="h-9" className="mt-2 rounded-lg" />
      </div>
    </div>
  );
}
