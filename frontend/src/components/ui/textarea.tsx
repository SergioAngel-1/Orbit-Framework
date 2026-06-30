import { type TextareaHTMLAttributes, type Ref } from "react";
import { cn } from "@/lib/utils";

export interface TextareaProps extends TextareaHTMLAttributes<HTMLTextAreaElement> {
  label?: string;
  error?: string;
  helper?: string;
  id?: string;
  /** Número de filas visibles. Default: 4. */
  rows?: number;
  /** Impide que el usuario redimensione. Default: false. */
  noResize?: boolean;
  /** Ref reenviada al `<textarea>` nativo (React 19: ref como prop). */
  ref?: Ref<HTMLTextAreaElement>;
}

export function Textarea({
  label,
  error,
  helper,
  id,
  rows = 4,
  noResize = false,
  className,
  ref,
  ...props
}: TextareaProps) {
  const areaId = id ?? (label ? label.toLowerCase().replace(/\s+/g, "-") : undefined);
  const hasError = Boolean(error);

  return (
    <div className="flex flex-col gap-1">
      {label && (
        <label
          htmlFor={areaId}
          className="text-sm font-medium text-gray-700 dark:text-gray-300"
        >
          {label}
          {props.required && <span className="ml-0.5 text-red-500" aria-hidden>*</span>}
        </label>
      )}

      <textarea
        ref={ref}
        id={areaId}
        rows={rows}
        aria-invalid={hasError}
        aria-describedby={
          hasError ? `${areaId}-error` : helper ? `${areaId}-helper` : undefined
        }
        className={cn(
          "w-full rounded-lg border bg-transparent px-3 py-2 text-sm shadow-sm",
          "transition-colors placeholder:text-gray-400",
          "focus:outline-none focus:ring-2 focus:ring-offset-0",
          "disabled:cursor-not-allowed disabled:opacity-50",
          "dark:bg-gray-950 dark:text-gray-100",
          noResize ? "resize-none" : "resize-y",
          hasError
            ? "border-red-500 focus:border-red-500 focus:ring-red-400/30"
            : "border-gray-300 dark:border-gray-700 focus:border-brand focus:ring-brand/25",
          className,
        )}
        {...props}
      />

      {hasError ? (
        <p id={`${areaId}-error`} role="alert" className="text-xs text-red-600 dark:text-red-400">
          {error}
        </p>
      ) : helper ? (
        <p id={`${areaId}-helper`} className="text-xs text-gray-500 dark:text-gray-400">
          {helper}
        </p>
      ) : null}
    </div>
  );
}
