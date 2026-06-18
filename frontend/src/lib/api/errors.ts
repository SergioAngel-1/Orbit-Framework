import "server-only";
import { NextResponse } from "next/server";
import { WooCommerceError } from "@/lib/woocommerce/client";
import { PaymentError } from "@/lib/payments/types";

// ============================================================================
//  Mapeo centralizado de errores a respuestas HTTP de los Route Handlers.
//  No filtra detalles internos: los errores 5xx se devuelven genéricos.
// ============================================================================

export function handleApiError(error: unknown): NextResponse {
  // Sesión requerida ausente (lanzado por requireSession()).
  if (error instanceof Error && error.message === "UNAUTHENTICATED") {
    return NextResponse.json({ error: "No autenticado." }, { status: 401 });
  }

  // Errores de la capa de pagos (pasarela no registrada, sin implementar…).
  if (error instanceof PaymentError) {
    if (error.status >= 500 && error.status !== 501) {
      return NextResponse.json(
        { error: "El servicio de pago no está disponible." },
        { status: 502 },
      );
    }
    return NextResponse.json(
      { error: error.message, code: error.code },
      { status: error.status },
    );
  }

  if (error instanceof WooCommerceError) {
    // Errores del lado servidor -> respuesta genérica (no exponer internals).
    if (error.status >= 500) {
      return NextResponse.json(
        { error: "El servicio de tienda no está disponible." },
        { status: 502 },
      );
    }
    // Errores 4xx (validación de WC, recurso inexistente, etc.) sí informan.
    return NextResponse.json(
      { error: error.message, code: error.code },
      { status: error.status },
    );
  }

  return NextResponse.json({ error: "Error interno." }, { status: 500 });
}
