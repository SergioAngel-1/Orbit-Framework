import { NextResponse } from "next/server";
import { storeFetch } from "@/lib/woocommerce/store-client";
import { writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { getCartRequestAuth } from "@/lib/woocommerce/cart-session";
import { completeStoreCart } from "@/lib/woocommerce/complete-cart";
import { requestLocale } from "@/lib/i18n/request-locale";
import { guardMutation } from "@/lib/api/guard";
import { handleApiError } from "@/lib/api/errors";
import { requireModule } from "@/lib/api/modules";
import { shippingDestinationSchema } from "@/lib/validation/store";
import { getSiteConfig } from "@/lib/config";
import { getGeoPack } from "@/lib/geo";
import { checkAddress } from "@/lib/geo/address";
import { logger } from "@/lib/observability/logger";
import type { StoreCart } from "@/types/woocommerce";

export const dynamic = "force-dynamic";

/**
 * PUT /api/store/shipping/destination — fija el destino de envío del carrito
 * (módulo `shipping`: con el módulo apagado, 404).
 *
 * La tarifa del módulo se resuelve DENTRO de WooCommerce por la ciudad del paquete
 * (`woocommerce_package_rates`), y esa ciudad sale de la dirección de envío del
 * carrito. Sin fijarla, el carrito de un invitado no tiene destino y el checkout
 * enseñaba tarifas que no eran las que luego se cobran (el pedido sí recalcula con
 * la dirección enviada). Usa el endpoint cart-level `/cart/update-customer`, que
 * devuelve el carrito completo con `shipping_rates` recalculadas.
 *
 * El estado se valida y normaliza con el paquete de país (código de la división que
 * exige WooCommerce): un departamento que no existe o no corresponde a la ciudad es
 * un 422, nunca se rellena uno por defecto.
 */
export async function PUT(request: Request) {
  const blocked = await guardMutation(request, {
    rateLimit: { name: "shipping-destination", limit: 30, windowSeconds: 60 },
  });
  if (blocked) return blocked;

  const [config, geoPack] = await Promise.all([getSiteConfig(), getGeoPack()]);
  const off = requireModule(config, "shipping");
  if (off) return off;

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return NextResponse.json({ error: "JSON inválido." }, { status: 400 });
  }

  const parsed = shippingDestinationSchema.safeParse(body);
  if (!parsed.success) {
    logger.warn(
      { event: "shipping.destination.validation" },
      "Destino de envío inválido",
    );
    return NextResponse.json(
      {
        error: "Destino de envío inválido.",
        details: parsed.error.flatten().fieldErrors,
      },
      { status: 422 },
    );
  }

  const { city, country, state, postcode } = parsed.data;
  const checked = checkAddress(geoPack, { city, country, state });
  if (checked.issues.length > 0) {
    return NextResponse.json(
      {
        error: "Destino de envío inválido.",
        details: { state: checked.issues.map((i) => i.message) },
      },
      { status: 422 },
    );
  }

  try {
    const { cartToken: token, authToken } = await getCartRequestAuth();
    const { data, cartToken } = await storeFetch<StoreCart>("/cart/update-customer", {
      method: "POST",
      body: {
        shipping_address: {
          city,
          country,
          state: checked.state,
          postcode: postcode ?? "",
        },
      },
      cartToken: token,
      authToken,
    });
    await writeCartToken(cartToken);
    return NextResponse.json(
      await completeStoreCart(data, requestLocale(request.headers)),
    );
  } catch (error) {
    logger.error(
      {
        event: "shipping.destination.error",
        err: error instanceof Error ? error.message : error,
      },
      "Error al fijar el destino de envío",
    );
    return handleApiError(error);
  }
}
