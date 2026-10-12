import { describe, expect, it } from "vitest";
import { requireModule } from "./modules";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";

describe("requireModule", () => {
  it("devuelve null con el módulo activo (la ruta sigue)", () => {
    const config = {
      modules: { ...CONFIG_DEFAULTS.modules, "site-reviews": true },
    };
    expect(requireModule(config, "site-reviews")).toBeNull();
  });

  it("responde 404 con el módulo apagado", async () => {
    const blocked = requireModule(CONFIG_DEFAULTS, "site-reviews");
    expect(blocked).not.toBeNull();
    expect(blocked!.status).toBe(404);
    await expect(blocked!.json()).resolves.toEqual({ error: "No encontrado." });
  });
});
