import { describe, expect, it } from "vitest";
import { requireModule } from "@/lib/api/modules";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import type { LoyaltyConfig, SiteConfig } from "@/lib/config/types";
import { requireLoyalty, type LoyaltyFeature } from "./guard";

function config(
  modules: boolean,
  loyalty: Partial<LoyaltyConfig> = {},
): Pick<SiteConfig, "modules" | "loyalty"> {
  return {
    modules: { ...CONFIG_DEFAULTS.modules, loyalty: modules },
    loyalty: { ...CONFIG_DEFAULTS.loyalty, ...loyalty },
  };
}

const allOn = (): Pick<SiteConfig, "modules" | "loyalty"> =>
  config(true, {
    points_enabled: true,
    referrals_enabled: true,
    transfers_enabled: true,
  });

const FEATURES: LoyaltyFeature[] = ["points", "referrals", "transfers"];

describe("requireLoyalty", () => {
  it.each(FEATURES)("%s con todo encendido deja pasar la petición", (feature) => {
    expect(requireLoyalty(allOn(), feature)).toBeNull();
  });

  it.each(FEATURES)(
    "%s con el módulo apagado responde 404 con el mismo cuerpo que requireModule",
    async (feature) => {
      const blocked = requireLoyalty(config(false), feature);
      const reference = requireModule(CONFIG_DEFAULTS, "loyalty");

      expect(blocked!.status).toBe(404);
      await expect(blocked!.json()).resolves.toEqual(await reference!.json());
    },
  );

  it("con «Puntos» apagado no hay referidos ni transferencias", () => {
    const off = config(true, {
      points_enabled: false,
      referrals_enabled: true,
      transfers_enabled: true,
    });

    expect(requireLoyalty(off, "points")).not.toBeNull();
    expect(requireLoyalty(off, "referrals")).not.toBeNull();
    expect(requireLoyalty(off, "transfers")).not.toBeNull();
  });

  it("cada interruptor solo abre su función", () => {
    const onlyPoints = config(true, {
      points_enabled: true,
      referrals_enabled: false,
      transfers_enabled: false,
    });

    expect(requireLoyalty(onlyPoints, "points")).toBeNull();
    expect(requireLoyalty(onlyPoints, "referrals")).not.toBeNull();
    expect(requireLoyalty(onlyPoints, "transfers")).not.toBeNull();

    const onlyReferrals = config(true, {
      points_enabled: true,
      referrals_enabled: true,
      transfers_enabled: false,
    });

    expect(requireLoyalty(onlyReferrals, "referrals")).toBeNull();
    expect(requireLoyalty(onlyReferrals, "transfers")).not.toBeNull();
  });
});
