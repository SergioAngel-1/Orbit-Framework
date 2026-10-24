import { describe, expect, it } from "vitest";
import { requireModule } from "@/lib/api/modules";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import type { MembershipsConfig, SiteConfig } from "@/lib/config/types";
import { requireMembershipCard } from "./guard";

function config(
  modules: boolean,
  memberships: Partial<MembershipsConfig> = {},
): Pick<SiteConfig, "modules" | "memberships"> {
  return {
    modules: { ...CONFIG_DEFAULTS.modules, memberships: modules },
    memberships: { ...CONFIG_DEFAULTS.memberships, ...memberships },
  };
}

describe("requireMembershipCard", () => {
  it("con el módulo y el carné encendidos deja pasar la petición", () => {
    expect(requireMembershipCard(config(true, { card_enabled: true }))).toBeNull();
  });

  it("con el módulo apagado responde 404 con el mismo cuerpo que requireModule", async () => {
    const blocked = requireMembershipCard(config(false, { card_enabled: true }));
    const reference = requireModule(CONFIG_DEFAULTS, "memberships");

    expect(blocked!.status).toBe(404);
    await expect(blocked!.json()).resolves.toEqual(await reference!.json());
  });

  it("con el carné apagado también responde 404, aunque el módulo esté activo", async () => {
    const blocked = requireMembershipCard(config(true, { card_enabled: false }));
    const reference = requireModule(CONFIG_DEFAULTS, "memberships");

    expect(blocked!.status).toBe(404);
    await expect(blocked!.json()).resolves.toEqual(await reference!.json());
  });

  it("no depende del resto de la configuración del módulo", () => {
    const onlyCard = config(true, {
      card_enabled: true,
      card_verify_path: "",
      member_number_prefix: "",
    });

    expect(requireMembershipCard(onlyCard)).toBeNull();
  });
});
