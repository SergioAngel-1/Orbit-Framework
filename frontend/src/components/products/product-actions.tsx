"use client";
import { useState, useCallback } from "react";
import { useTranslations } from "next-intl";
import {
  VariationSelector,
  type ProductVariation,
  type ProductAttributeOption,
} from "./variation-selector";
import { AddToCartButton } from "@/components/cart/add-to-cart-button";
import { formatPrice } from "@/lib/format";
import { Badge } from "@/components/ui/badge";

// ============================================================================
//  ProductActions — Client Component que gestiona la selección de variaciones
//  y el botón de carrito en la ficha de producto.
//
//  Para productos simples: solo renderiza AddToCartButton.
//  Para productos variables: renderiza VariationSelector + botón dinámico.
// ============================================================================

interface ProductActionsProps {
  productId: number;
  isVariable: boolean;
  outOfStock: boolean;
  attributes: ProductAttributeOption[];
  variations: ProductVariation[];
}

export default function ProductActions({
  productId,
  isVariable,
  outOfStock,
  attributes,
  variations,
}: ProductActionsProps) {
  const t = useTranslations("products");
  const [activeVariation, setActiveVariation] = useState<ProductVariation | null>(null);

  const handleVariationChange = useCallback((v: ProductVariation | null) => {
    setActiveVariation(v);
  }, []);

  // Producto simple
  if (!isVariable) {
    return (
      <div className="mt-2">
        <AddToCartButton productId={productId} disabled={outOfStock} fullWidth />
      </div>
    );
  }

  // Producto variable
  const variantOutOfStock = activeVariation
    ? activeVariation.stockStatus === "OUT_OF_STOCK"
    : false;

  const canAddToCart = !!activeVariation && !variantOutOfStock;

  return (
    <div className="flex flex-col gap-4">
      {/* Selector de variaciones */}
      <VariationSelector
        attributes={attributes}
        variations={variations}
        onVariationChange={handleVariationChange}
      />

      {/* Precio dinámico de la variación */}
      {activeVariation && (
        <div className="flex items-baseline gap-2">
          <span className="text-xl font-bold">
            {formatPrice(activeVariation.price)}
          </span>
          {activeVariation.salePrice && activeVariation.regularPrice && (
            <span className="text-base text-gray-400 line-through">
              {formatPrice(activeVariation.regularPrice)}
            </span>
          )}
          {variantOutOfStock && (
            <Badge color="error" variant="soft">
              {t("outOfStock")}
            </Badge>
          )}
        </div>
      )}

      {/* Botón de carrito */}
      <AddToCartButton
        productId={activeVariation ? activeVariation.databaseId : productId}
        disabled={!canAddToCart}
        label={
          !activeVariation
            ? t("selectVariant")
            : variantOutOfStock
              ? t("outOfStock")
              : undefined
        }
        fullWidth
      />
    </div>
  );
}
