import { describe, expect, it } from "vitest";
import { WooCommerceError } from "@/lib/woocommerce/client";
import { handleApiError } from "./errors";

// WooCommerce escapa sus mensajes para HTML (`esc_html__`): el código de un
// cupón llegaba como `&quot;bienvenida10&quot;` y la UI, que pinta texto, lo
// enseñaba literal.

describe("handleApiError con errores de WooCommerce", () => {
  it("entrega el mensaje 4xx como texto plano", async () => {
    const res = handleApiError(
      new WooCommerceError(
        'El cupón &quot;bienvenida10&quot; ya no se puede usar. <a href="/x">Ver</a>',
        400,
        "woocommerce_rest_cart_coupon_error",
      ),
    );

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({
      error: 'El cupón "bienvenida10" ya no se puede usar. Ver',
      code: "woocommerce_rest_cart_coupon_error",
    });
  });

  it("un 5xx no filtra el mensaje interno", async () => {
    const res = handleApiError(new WooCommerceError("SQLSTATE[42S02]", 500));

    expect(res.status).toBe(502);
    expect((await res.json()).error).not.toContain("SQLSTATE");
  });
});
