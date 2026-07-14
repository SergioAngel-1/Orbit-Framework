"use client";
import { useCallback, useEffect, useMemo, useState } from "react";
import { cn } from "@/lib/utils";

// ============================================================================
//  Selector de variaciones para productos variables de WooCommerce.
//
//  Flujo:
//    1. Renderiza botones de selección por cada atributo de variación.
//    2. Cuando se seleccionan todos los atributos, busca la variación coincidente.
//    3. Llama a `onVariationChange` con la variación activa (o null si incompleta).
// ============================================================================

export interface VariationAttribute {
  name: string;
  value: string;
}

export interface ProductVariation {
  databaseId: number;
  price: string | null;
  regularPrice: string | null;
  salePrice: string | null;
  stockStatus: string | null;
  attributes: VariationAttribute[];
}

export interface ProductAttributeOption {
  /** Nombre del atributo (ej. "Talla", "Color"). */
  name: string;
  /** Slug normalizado para la comparación (ej. "talla", "color"). */
  slug?: string;
  /** Etiqueta legible. */
  label?: string;
  /** Valores posibles del atributo. */
  options: string[];
  /** Si true, este atributo afecta a variaciones. */
  variation?: boolean;
}

export interface VariationSelectorProps {
  attributes: ProductAttributeOption[];
  variations: ProductVariation[];
  /** Se llama al seleccionar una variación completa (o null si incompleta/agotada). */
  onVariationChange: (variation: ProductVariation | null) => void;
  className?: string;
}

function normalizeValue(v: string) {
  return v.trim().toLowerCase();
}

export function VariationSelector({
  attributes,
  variations,
  onVariationChange,
  className,
}: VariationSelectorProps) {
  // solo los atributos que afectan a variaciones
  const varAttrs = useMemo(
    () => attributes.filter((a) => a.variation !== false && a.options.length > 0),
    [attributes],
  );

  const [selected, setSelected] = useState<Record<string, string>>({});

  const findVariation = useCallback(
    (sel: Record<string, string>): ProductVariation | null => {
      if (Object.keys(sel).length < varAttrs.length) return null;
      return (
        variations.find((v) =>
          v.attributes.every((attr) => {
            const key = normalizeValue(attr.name);
            return normalizeValue(attr.value) === normalizeValue(sel[key] ?? "");
          }),
        ) ?? null
      );
    },
    [variations, varAttrs],
  );

  useEffect(() => {
    onVariationChange(findVariation(selected));
  }, [selected, findVariation, onVariationChange]);

  const select = (attrName: string, option: string) => {
    setSelected((prev) => ({
      ...prev,
      [normalizeValue(attrName)]: normalizeValue(option),
    }));
  };

  const isOptionAvailable = (attrName: string, option: string): boolean => {
    const hypothetical = {
      ...selected,
      [normalizeValue(attrName)]: normalizeValue(option),
    };
    return variations.some(
      (v) =>
        v.stockStatus !== "OUT_OF_STOCK" &&
        v.attributes.every((attr) => {
          const key = normalizeValue(attr.name);
          if (!(key in hypothetical)) return true;
          return normalizeValue(attr.value) === normalizeValue(hypothetical[key] ?? "");
        }),
    );
  };

  if (varAttrs.length === 0) return null;

  return (
    <div className={cn("space-y-5", className)}>
      {varAttrs.map((attr) => {
        const key = normalizeValue(attr.name);
        const currentValue = selected[key];
        const label = attr.label || attr.name;

        return (
          <div key={attr.name}>
            <div className="mb-2 flex items-center gap-2 text-sm">
              <span className="font-medium text-gray-700 dark:text-gray-300">
                {label}
              </span>
              {currentValue && (
                <span className="text-gray-500 dark:text-gray-400 capitalize">
                  {currentValue}
                </span>
              )}
            </div>

            <div
              className="flex flex-wrap gap-2"
              role="group"
              aria-label={`Seleccionar ${label}`}
            >
              {attr.options.map((option) => {
                const isSelected = normalizeValue(option) === currentValue;
                const available = isOptionAvailable(attr.name, option);

                return (
                  <button
                    key={option}
                    type="button"
                    onClick={() => available && select(attr.name, option)}
                    disabled={!available}
                    aria-pressed={isSelected}
                    aria-label={`${label}: ${option}${!available ? " (agotado)" : ""}`}
                    className={cn(
                      "relative min-w-[2.75rem] rounded-lg border px-3 py-1.5 text-sm font-medium",
                      "transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50",
                      isSelected
                        ? "border-brand bg-brand text-white"
                        : "border-gray-300 bg-white text-gray-700 hover:border-brand dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200",
                      !available && "cursor-not-allowed opacity-40 line-through",
                    )}
                  >
                    {option}
                  </button>
                );
              })}
            </div>
          </div>
        );
      })}
    </div>
  );
}
