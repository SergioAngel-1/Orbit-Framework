"use client";
import { useState } from "react";
import { useTranslations } from "next-intl";
import { useCart } from "./cart-context";
import { Button } from "@/components/ui/button";

export interface AddToCartButtonProps {
  /** ID del producto (simple) o variación (variable). */
  productId: number;
  disabled?: boolean;
  /** Etiqueta personalizada en estado normal. */
  label?: string;
  className?: string;
  fullWidth?: boolean;
}

export function AddToCartButton({
  productId,
  disabled,
  label,
  className,
  fullWidth = false,
}: AddToCartButtonProps) {
  const { addItem } = useCart();
  const t           = useTranslations("addToCart");
  const [pending, setPending] = useState(false);
  const [done,    setDone]    = useState(false);

  async function handleClick() {
    setPending(true);
    setDone(false);
    try {
      await addItem(productId, 1);
      setDone(true);
      setTimeout(() => setDone(false), 1800);
    } finally {
      setPending(false);
    }
  }

  return (
    <Button
      onClick={handleClick}
      disabled={disabled || pending}
      loading={pending}
      fullWidth={fullWidth}
      className={className}
    >
      {done ? t("added") : (label ?? t("add"))}
    </Button>
  );
}
