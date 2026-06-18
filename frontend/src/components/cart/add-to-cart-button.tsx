"use client";
import { useState } from "react";
import { useCart } from "./cart-context";

export function AddToCartButton({
  productId,
  disabled,
}: {
  productId: number;
  disabled?: boolean;
}) {
  const { addItem } = useCart();
  const [pending, setPending] = useState(false);
  const [done, setDone] = useState(false);

  async function handleClick() {
    setPending(true);
    setDone(false);
    try {
      await addItem(productId, 1);
      setDone(true);
      setTimeout(() => setDone(false), 1500);
    } finally {
      setPending(false);
    }
  }

  return (
    <button
      type="button"
      onClick={handleClick}
      disabled={disabled || pending}
      className="inline-flex items-center justify-center rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-brand-dark disabled:cursor-not-allowed disabled:opacity-50"
    >
      {pending ? "Añadiendo…" : done ? "✓ Añadido" : "Añadir al carrito"}
    </button>
  );
}
