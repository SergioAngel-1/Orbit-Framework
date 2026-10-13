import { describe, expect, it } from "vitest";
import { wcMessageToText } from "./wc-message";

describe("wcMessageToText", () => {
  it("decodifica las entidades que WooCommerce escapa", () => {
    expect(
      wcMessageToText(
        "Usage limit for coupon &quot;bienvenida10&quot; has been reached.",
      ),
    ).toBe('Usage limit for coupon "bienvenida10" has been reached.');
    expect(wcMessageToText("Tom &amp; Jerry &#039;s &#x27;x&#x27;")).toBe(
      "Tom & Jerry 's 'x'",
    );
  });

  it("quita las etiquetas antes de decodificar", () => {
    expect(wcMessageToText('Reintenta desde <a href="/cuenta">tu cuenta</a>.')).toBe(
      "Reintenta desde tu cuenta.",
    );
    expect(wcMessageToText("&lt;b&gt;no es una etiqueta&lt;/b&gt;")).toBe(
      "<b>no es una etiqueta</b>",
    );
  });

  it("deja intactas las entidades desconocidas y el texto normal", () => {
    expect(wcMessageToText("Cupón no válido &foo;")).toBe("Cupón no válido &foo;");
  });
});
