import { describe, expect, it } from "vitest";
import { requireFlag, type EcommerceFlag } from "./flags";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

const FLAGS: EcommerceFlag[] = [
  "reviews_enabled",
  "wishlist_enabled",
  "coupons_enabled",
  "search_enabled",
];

const withFlag = (flag: EcommerceFlag, value: boolean) => ({
  ecommerce: { ...CONFIG_DEFAULTS.ecommerce, [flag]: value },
});

describe("requireFlag", () => {
  it.each(FLAGS)("%s encendido: la ruta sigue", (flag) => {
    expect(requireFlag(withFlag(flag, true), flag)).toBeNull();
  });

  it.each(FLAGS)(
    "%s apagado: 404 con el mismo cuerpo que un módulo apagado",
    async (flag) => {
      const blocked = requireFlag(withFlag(flag, false), flag);

      expect(blocked!.status).toBe(404);
      await expect(blocked!.json()).resolves.toEqual({ error: "No encontrado." });
    },
  );

  it("solo mira el flag pedido", () => {
    expect(requireFlag(withFlag("wishlist_enabled", false), "reviews_enabled")).toEqual(
      requireFlag(withFlag("wishlist_enabled", false), "reviews_enabled"),
    );
    const config = {
      ecommerce: {
        ...CONFIG_DEFAULTS.ecommerce,
        reviews_enabled: true,
        wishlist_enabled: false,
      },
    };
    expect(requireFlag(config, "reviews_enabled")).toBeNull();
    expect(requireFlag(config, "wishlist_enabled")).not.toBeNull();
  });
});
