// Barrel export de todos los componentes UI base.
// Importa desde "@/components/ui" para acceder a cualquier componente.

export { Alert }             from "./alert";
export type { AlertProps }   from "./alert";

export { Badge }             from "./badge";
export type { BadgeProps }   from "./badge";

export { Button }            from "./button";
export type { ButtonProps }  from "./button";

export { Card, CardHeader, CardTitle, CardFooter } from "./card";
export type { CardProps }    from "./card";

export { Checkbox }          from "./checkbox";
export type { CheckboxProps } from "./checkbox";

export { DarkModeToggle, DarkModeScript } from "./dark-mode-toggle";

export { Input }             from "./input";
export type { InputProps }   from "./input";

export { Modal }             from "./modal";
export type { ModalProps }   from "./modal";

export { Paginator }         from "./paginator";
export type { PaginatorProps } from "./paginator";

export { QuantityCounter }   from "./quantity-counter";
export type { QuantityCounterProps } from "./quantity-counter";

export { Select }            from "./select";
export type { SelectProps, SelectOption } from "./select";

export { Skeleton, ProductCardSkeleton } from "./skeleton";
export type { SkeletonProps } from "./skeleton";

export { Spinner }           from "./spinner";
export type { SpinnerProps } from "./spinner"; // inferred

export { Textarea }          from "./textarea";
export type { TextareaProps } from "./textarea";

// ThemeTokens NO se re-exporta aquí a propósito: es un Server Component
// (`server-only`, usa getSiteConfig()) y este barrel lo importan también
// Client Components (p. ej. ContactForm). Re-exportarlo aquí contamina el
// grafo de módulos de cualquier consumidor cliente del barrel con código
// server-only y rompe el build ("'server-only' cannot be imported from a
// Client Component module"). Impórtalo directo: `@/components/ui/theme-tokens`
// (así lo hace `app/[locale]/layout.tsx`, su único consumidor).
