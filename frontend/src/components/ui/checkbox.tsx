import { forwardRef, type InputHTMLAttributes, type ReactNode } from "react";
import { cn } from "@/lib/utils";

export interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, "type"> {
  /** Etiqueta a la derecha del checkbox. */
  label?: ReactNode;
  /** Texto descriptivo secundario bajo la etiqueta. */
  description?: string;
  /** Estado indeterminado (parcialmente marcado). */
  indeterminate?: boolean;
  error?: string;
  id?: string;
}

export const Checkbox = forwardRef<HTMLInputElement, CheckboxProps>(function Checkbox(
  { label, description, indeterminate = false, error, id, className, ...props },
  ref,
) {
  const checkId = id ?? (typeof label === "string" ? label.toLowerCase().replace(/\s+/g, "-") : undefined);
  const hasError = Boolean(error);

  return (
    <div className="flex flex-col gap-1">
      <label
        htmlFor={checkId}
        className={cn(
          "flex cursor-pointer items-start gap-3",
          props.disabled && "cursor-not-allowed opacity-50",
        )}
      >
        <div className="relative mt-0.5 flex-none">
          <input
            ref={(el) => {
              if (el) el.indeterminate = indeterminate;
              if (typeof ref === "function") ref(el);
              else if (ref) ref.current = el;
            }}
            type="checkbox"
            id={checkId}
            aria-invalid={hasError}
            aria-describedby={hasError ? `${checkId}-error` : undefined}
            className={cn(
              "peer h-4 w-4 appearance-none rounded border bg-transparent",
              "transition-colors cursor-pointer",
              "checked:bg-brand checked:border-brand",
              "indeterminate:bg-brand/60 indeterminate:border-brand/60",
              "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/40 focus-visible:ring-offset-1",
              "disabled:cursor-not-allowed",
              hasError
                ? "border-red-500"
                : "border-gray-300 dark:border-gray-600",
              className,
            )}
            {...props}
          />
          {/* Checkmark SVG — visible only when checked */}
          <svg
            className="pointer-events-none absolute inset-0 hidden h-4 w-4 text-white peer-checked:block"
            viewBox="0 0 16 16"
            fill="currentColor"
            aria-hidden
          >
            <path
              fillRule="evenodd"
              d="M12.207 4.793a1 1 0 0 1 0 1.414l-5 5a1 1 0 0 1-1.414 0l-2-2a1 1 0 0 1 1.414-1.414L6.5 9.086l4.293-4.293a1 1 0 0 1 1.414 0z"
              clipRule="evenodd"
            />
          </svg>
        </div>

        {(label || description) && (
          <div className="flex flex-col gap-0.5">
            {label && (
              <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
                {label}
              </span>
            )}
            {description && (
              <span className="text-xs text-gray-500 dark:text-gray-400">{description}</span>
            )}
          </div>
        )}
      </label>

      {hasError && (
        <p id={`${checkId}-error`} role="alert" className="pl-7 text-xs text-red-600 dark:text-red-400">
          {error}
        </p>
      )}
    </div>
  );
});
