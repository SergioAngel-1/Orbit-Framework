"use client";
import { useEffect, useState, type ReactNode } from "react";
import {
  isCouponActive,
  nextCouponBoundary,
  shouldShowCoupon,
} from "@/lib/coupons/coupon-scope";
import { useViewerCoupons } from "./use-viewer-coupons";

// Los setTimeout del navegador desbordan por encima de ~24,8 días.
const MAX_TIMEOUT_MS = 2 ** 31 - 1;

const NO_USED_UP: ReadonlySet<string> = new Set();

/**
 * Pinta `children` solo si `shouldShowCoupon` lo decide para ESTE visitante y
 * ESTE instante: es el guardián de todos los avisos de cupón. Dos cosas cambian
 * después de que la página se generó, y las dos se resuelven aquí:
 *
 *  - La VENTANA de fechas, con el reloj del NAVEGADOR. La página llega de la
 *    caché ISR (hasta 1 h de antigüedad) y a medianoche no hay evento que la
 *    invalide: sin esto, el aviso aparecería tarde el primer día y seguiría
 *    visible tras caducar. `activeAtRender` es lo que decidió el servidor al
 *    renderizar, para que la hidratación coincida; el efecto corrige después y
 *    se programa para el siguiente cambio de estado.
 *  - El HISTORIAL del cliente (agotó el cupón, primera compra ya hecha, pedido
 *    pendiente): la ficha y la categoría son ISR y no saben quién mira, así que
 *    se pregunta a `/api/coupons/used-up` (una sola petición por página). Solo
 *    para los cupones que dependen del cliente (`perUserLimited`); mientras
 *    llega la respuesta no se enseña. `viewerKnown` es para quien YA filtró por
 *    el historial en el servidor (carrito y checkout).
 */
export function CouponGate({
  coupon,
  viewerKnown = false,
  children,
}: {
  coupon: {
    code: string;
    perUserLimited: boolean;
    startsAt: number | null;
    endsAt: number | null;
    activeAtRender: boolean;
  };
  viewerKnown?: boolean;
  children: ReactNode;
}) {
  const { startsAt, endsAt } = coupon;
  const [active, setActive] = useState(coupon.activeAtRender);
  const viewer = useViewerCoupons(coupon.perUserLimited && !viewerKnown);

  useEffect(() => {
    let timer: ReturnType<typeof setTimeout> | undefined;
    const sync = () => {
      const now = Date.now();
      setActive(isCouponActive({ startsAt, endsAt }, now));
      const next = nextCouponBoundary({ startsAt, endsAt }, now);
      if (next !== null && next - now < MAX_TIMEOUT_MS) {
        timer = setTimeout(sync, next - now + 1000);
      }
    };
    sync();
    return () => clearTimeout(timer);
  }, [startsAt, endsAt]);

  const show = shouldShowCoupon(coupon, {
    active,
    usedUp: viewerKnown ? NO_USED_UP : (viewer?.usedUp ?? null),
  });
  return show ? <>{children}</> : null;
}

/**
 * Pinta `children` solo a un visitante SIN sesión (resuelto en el navegador: la
 * ficha y la categoría son ISR y no conocen al usuario). Mientras se resuelve no
 * pinta nada, para no enseñar «inicia sesión» a quien ya la tiene.
 */
export function GuestOnly({ children }: { children: ReactNode }) {
  const viewer = useViewerCoupons(true);
  return viewer?.guest ? <>{children}</> : null;
}
