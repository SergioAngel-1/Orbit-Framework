import type { HTMLAttributes, ReactNode } from "react";
import { cn } from "@/lib/utils";

type Padding = "none" | "sm" | "md" | "lg";

export interface CardProps extends HTMLAttributes<HTMLDivElement> {
  /** Relleno interior. Default: `md`. */
  padding?: Padding;
  /** Añade borde. Default: `true`. */
  bordered?: boolean;
  /** Eleva al hover (añade sombra + cursor pointer). */
  hoverable?: boolean;
  /** Sombra base. Default: `sm`. */
  shadow?: "none" | "sm" | "md";
  children: ReactNode;
  as?: "div" | "article" | "section" | "li";
}

const paddings: Record<Padding, string> = {
  none: "",
  sm: "p-3",
  md: "p-5",
  lg: "p-7",
};

const shadows: Record<"none" | "sm" | "md", string> = {
  none: "",
  sm: "shadow-sm",
  md: "shadow-md",
};

export function Card({
  padding = "md",
  bordered = true,
  hoverable = false,
  shadow = "sm",
  as: Tag = "div",
  className,
  children,
  ...props
}: CardProps) {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const Component = Tag as any;
  return (
    <Component
      className={cn(
        "rounded-2xl bg-white dark:bg-gray-900",
        paddings[padding],
        shadows[shadow],
        bordered && "border border-gray-200 dark:border-gray-800",
        hoverable && "cursor-pointer transition-shadow hover:shadow-md",
        className,
      )}
      {...props}
    >
      {children}
    </Component>
  );
}

// Sub-componentes de conveniencia
export function CardHeader({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn("mb-4 flex items-center justify-between gap-2", className)}
      {...props}
    />
  );
}

export function CardTitle({ className, ...props }: HTMLAttributes<HTMLHeadingElement>) {
  return (
    <h3
      className={cn("font-semibold text-gray-900 dark:text-gray-100", className)}
      {...props}
    />
  );
}

export function CardFooter({ className, ...props }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        "mt-4 border-t border-gray-100 pt-4 dark:border-gray-800",
        className,
      )}
      {...props}
    />
  );
}
