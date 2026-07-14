import { type SelectHTMLAttributes, type Ref } from "react";
import { cn } from "@/lib/utils";

export interface SelectOption {
  value: string;
  label: string;
  disabled?: boolean;
}

export interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  label?: string;
  error?: string;
  helper?: string;
  /** Opción vacía que se muestra primero. */
  placeholder?: string;
  /** Lista de opciones. Si se prefiere, se puede usar `children` directamente. */
  options?: SelectOption[];
  id?: string;
  /** Ref reenviada al `<select>` nativo (React 19: ref como prop). */
  ref?: Ref<HTMLSelectElement>;
}

export function Select({
  label,
  error,
  helper,
  placeholder,
  options,
  id,
  className,
  children,
  ref,
  ...props
}: SelectProps) {
  const selectId = id ?? (label ? label.toLowerCase().replace(/\s+/g, "-") : undefined);
  const hasError = Boolean(error);

  return (
    <div className="flex flex-col gap-1">
      {label && (
        <label
          htmlFor={selectId}
          className="text-sm font-medium text-gray-700 dark:text-gray-300"
        >
          {label}
          {props.required && (
            <span className="ml-0.5 text-red-500" aria-hidden>
              *
            </span>
          )}
        </label>
      )}

      <div className="relative">
        <select
          ref={ref}
          id={selectId}
          aria-invalid={hasError}
          aria-describedby={
            hasError ? `${selectId}-error` : helper ? `${selectId}-helper` : undefined
          }
          className={cn(
            "w-full appearance-none rounded-lg border bg-transparent",
            "py-2 pl-3 pr-9 text-sm shadow-sm",
            "transition-colors",
            "focus:outline-none focus:ring-2 focus:ring-offset-0",
            "disabled:cursor-not-allowed disabled:opacity-50",
            "dark:bg-gray-950 dark:text-gray-100",
            hasError
              ? "border-red-500 focus:border-red-500 focus:ring-red-400/30"
              : "border-gray-300 dark:border-gray-700 focus:border-brand focus:ring-brand/25",
            className,
          )}
          {...props}
        >
          {placeholder && (
            <option value="" disabled={!props.value && props.value !== ""}>
              {placeholder}
            </option>
          )}
          {options
            ? options.map((o) => (
                <option key={o.value} value={o.value} disabled={o.disabled}>
                  {o.label}
                </option>
              ))
            : children}
        </select>

        {/* Chevron icon */}
        <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-gray-400">
          <svg
            xmlns="http://www.w3.org/2000/svg"
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
            <path d="m6 9 6 6 6-6" />
          </svg>
        </span>
      </div>

      {hasError ? (
        <p
          id={`${selectId}-error`}
          role="alert"
          className="text-xs text-red-600 dark:text-red-400"
        >
          {error}
        </p>
      ) : helper ? (
        <p
          id={`${selectId}-helper`}
          className="text-xs text-gray-500 dark:text-gray-400"
        >
          {helper}
        </p>
      ) : null}
    </div>
  );
}
