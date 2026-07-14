"use client";
import { cn } from "@/lib/utils";

type QuantityCounterSize = "sm" | "md" | "lg";

export interface QuantityCounterProps {
  value: number;
  min?: number;
  max?: number;
  onChange?: (value: number) => void;
  /** Al pulsar el botón de restar estando en `min`, se emite este callback en vez de decrementar. */
  onRemove?: () => void;
  size?: QuantityCounterSize;
  disabled?: boolean;
  className?: string;
}

const sizeStyles: Record<
  QuantityCounterSize,
  { button: string; icon: number; text: string }
> = {
  sm: { button: "h-9 w-9", icon: 14, text: "text-sm" },
  md: { button: "h-10 w-10", icon: 16, text: "text-[15px]" },
  lg: { button: "h-11 w-11", icon: 18, text: "text-base" },
};

export function QuantityCounter({
  value,
  min = 1,
  max = 99,
  onChange,
  onRemove,
  size = "md",
  disabled = false,
  className,
}: QuantityCounterProps) {
  const styles = sizeStyles[size];
  const isMin = value <= min;

  const handleDecrement = () => {
    if (disabled) return;
    if (isMin) onRemove?.();
    else onChange?.(value - 1);
  };

  const handleIncrement = () => {
    if (disabled) return;
    if (value < max) onChange?.(value + 1);
  };

  return (
    <div
      className={cn(
        "inline-flex items-center overflow-hidden rounded-lg border border-gray-300 dark:border-gray-700",
        className,
      )}
    >
      <button
        type="button"
        onClick={handleDecrement}
        disabled={disabled}
        aria-label={isMin ? "Eliminar" : "Restar"}
        className={cn(
          styles.button,
          "flex items-center justify-center transition-colors",
          "disabled:cursor-not-allowed disabled:opacity-40",
          isMin
            ? "bg-surface text-secondary hover:bg-brand/10 hover:text-brand dark:bg-gray-800"
            : "bg-surface hover:bg-brand/10 dark:bg-gray-800",
        )}
      >
        {isMin ? <TrashIcon size={styles.icon} /> : <MinusIcon size={styles.icon} />}
      </button>

      <span
        className={cn(
          styles.text,
          "min-w-9 flex-1 text-center font-sans font-medium tabular-nums",
        )}
      >
        {value}
      </span>

      <button
        type="button"
        onClick={handleIncrement}
        disabled={disabled || value >= max}
        aria-label="Sumar"
        className={cn(
          styles.button,
          "flex items-center justify-center bg-surface transition-colors hover:bg-brand/10 dark:bg-gray-800",
          "disabled:cursor-not-allowed disabled:opacity-40",
        )}
      >
        <PlusIcon size={styles.icon} />
      </button>
    </div>
  );
}

function MinusIcon({ size }: { size: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <line x1="5" y1="12" x2="19" y2="12" />
    </svg>
  );
}

function PlusIcon({ size }: { size: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <line x1="12" y1="5" x2="12" y2="19" />
      <line x1="5" y1="12" x2="19" y2="12" />
    </svg>
  );
}

function TrashIcon({ size }: { size: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <polyline points="3 6 5 6 21 6" />
      <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
    </svg>
  );
}
