import { forwardRef, type InputHTMLAttributes, type ReactNode } from "react";
import { cn } from "@/lib/utils";

export interface InputProps extends InputHTMLAttributes<HTMLInputElement> {
  /** Etiqueta visible sobre el campo. */
  label?: string;
  /** Texto de error (pone el campo en estado inválido). */
  error?: string;
  /** Texto de ayuda bajo el campo (se oculta si hay error). */
  helper?: string;
  /** Icono o elemento a la izquierda del input. */
  leading?: ReactNode;
  /** Icono o elemento a la derecha del input. */
  trailing?: ReactNode;
  /** ID del input (generado si no se proporciona). */
  id?: string;
}

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { label, error, helper, leading, trailing, id, className, ...props },
  ref,
) {
  const inputId = id ?? (label ? label.toLowerCase().replace(/\s+/g, "-") : undefined);
  const hasError = Boolean(error);

  return (
    <div className="flex flex-col gap-1">
      {label && (
        <label
          htmlFor={inputId}
          className="text-sm font-medium text-gray-700 dark:text-gray-300"
        >
          {label}
          {props.required && <span className="ml-0.5 text-red-500" aria-hidden>*</span>}
        </label>
      )}

      <div className="relative flex items-center">
        {leading && (
          <span className="pointer-events-none absolute left-3 flex items-center text-gray-400">
            {leading}
          </span>
        )}
        <input
          ref={ref}
          id={inputId}
          aria-invalid={hasError}
          aria-describedby={
            hasError ? `${inputId}-error` : helper ? `${inputId}-helper` : undefined
          }
          className={cn(
            "w-full rounded-lg border bg-transparent px-3 py-2 text-sm shadow-sm",
            "transition-colors placeholder:text-gray-400",
            "focus:outline-none focus:ring-2 focus:ring-offset-0",
            "disabled:cursor-not-allowed disabled:opacity-50",
            "dark:bg-gray-950 dark:text-gray-100",
            hasError
              ? "border-red-500 focus:border-red-500 focus:ring-red-400/30"
              : "border-gray-300 dark:border-gray-700 focus:border-brand focus:ring-brand/25",
            leading  ? "pl-9" : undefined,
            trailing ? "pr-9" : undefined,
            className,
          )}
          {...props}
        />
        {trailing && (
          <span className="absolute right-3 flex items-center text-gray-400">
            {trailing}
          </span>
        )}
      </div>

      {hasError ? (
        <p id={`${inputId}-error`} role="alert" className="text-xs text-red-600 dark:text-red-400">
          {error}
        </p>
      ) : helper ? (
        <p id={`${inputId}-helper`} className="text-xs text-gray-500 dark:text-gray-400">
          {helper}
        </p>
      ) : null}
    </div>
  );
});
