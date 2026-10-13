import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, it, expect } from "vitest";
import { OG_VIEWS, viewOgImageUrl } from "./og-static";
import { instanceRegistry } from "@/instance/registry";

const ROOT = join(__dirname, "..", "..", "..");

describe("og-static", () => {
  it("sin manifiesto de la instancia no hay tarjeta estática: se usa la dinámica", () => {
    expect(instanceRegistry.ogCards).toEqual({});
    expect(viewOgImageUrl("home", "es")).toBeNull();
    expect(viewOgImageUrl("products", "en")).toBeNull();
  });

  it("las vistas del módulo coinciden con las del script generador", () => {
    const script = readFileSync(
      join(ROOT, "scripts", "generate-og-images.mjs"),
      "utf8",
    );
    const block = /const VIEWS = \{([\s\S]*?)\n\};/.exec(script)?.[1] ?? "";
    const declared = [...block.matchAll(/^ {2}"?([a-z-]+)"?: \{/gm)].map((m) => m[1]);
    expect(declared.sort()).toEqual([...OG_VIEWS].sort());
  });
});
