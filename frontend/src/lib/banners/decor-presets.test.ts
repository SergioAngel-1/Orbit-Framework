import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import type { DecorPreset } from "@/lib/instance/types";
import { decorPresets, defaultDecorPreset, resolveDecor } from "./decor-presets";

// PARIDAD con el filtro PHP `hwe_banners_decor_presets`: ambos lados leen el MISMO fixture
// (`backend/tests/Fixtures/decor-presets.json`). El de PHP comprueba claves y orden del selector
// del admin; este, que cada clave resuelve a su archivo y que el primero es el respaldo.

interface FixturePreset {
  key: string;
  label: string;
  src: string;
  invert: "theme" | "light" | "never";
}
const fixture = JSON.parse(
  readFileSync(
    resolve(__dirname, "../../../../backend/tests/Fixtures/decor-presets.json"),
    "utf8",
  ),
) as { presets: FixturePreset[] };

/** Lo que una instancia pondría en `InstanceRegistry.decorPresets`, en el orden del fixture. */
const REGISTRY: Record<string, DecorPreset> = Object.fromEntries(
  fixture.presets.map((p) => [p.key, { src: p.src, invert: p.invert }]),
);

describe("paridad con el filtro PHP (fixture compartido)", () => {
  it("las claves y su orden son las del fixture", () => {
    expect(Object.keys(decorPresets(REGISTRY))).toEqual(
      fixture.presets.map((p) => p.key),
    );
  });

  it("cada clave del fixture resuelve a su archivo", () => {
    for (const preset of fixture.presets) {
      expect(
        resolveDecor({ decorMode: "default", decorPreset: preset.key }, REGISTRY).src,
      ).toBe(preset.src);
    }
  });

  it("el respaldo es el primero declarado, igual que en PHP (`DecorPresets::defaultKey`)", () => {
    expect(defaultDecorPreset(REGISTRY)).toBe(fixture.presets[0].key);
  });
});

describe("resolveDecor", () => {
  it("cada acabado trae su política de inversión en tema oscuro", () => {
    expect(resolveDecor({ decorPreset: "linea-oscuro" }, REGISTRY).invertClass).toBe(
      "dark:invert",
    );
    expect(resolveDecor({ decorPreset: "linea-claro" }, REGISTRY).invertClass).toBe(
      "invert dark:invert-0",
    );
    expect(resolveDecor({ decorPreset: "linea-color" }, REGISTRY).invertClass).toBe("");
  });

  it("sin clave, o con una que ya no existe, sirve el primero (nada cambia de aspecto)", () => {
    const first = resolveDecor({ decorPreset: "linea-oscuro" }, REGISTRY);

    expect(resolveDecor({}, REGISTRY)).toEqual(first);
    expect(resolveDecor({ decorPreset: "borrado" }, REGISTRY)).toEqual(first);
  });

  it("el SVG propio manda sobre cualquier preset y no se invierte", () => {
    expect(
      resolveDecor(
        {
          decorMode: "custom",
          decorSvg: "/uploads/mio.svg",
          decorPreset: "linea-color",
        },
        REGISTRY,
      ),
    ).toEqual({ src: "/uploads/mio.svg", invertClass: "" });
  });

  it("un `custom` sin SVG cae al preset", () => {
    expect(
      resolveDecor({ decorMode: "custom", decorPreset: "linea-color" }, REGISTRY).src,
    ).toBe("/brand/decor-linea-color.svg");
  });

  it("sin presets declarados no hay nada que servir (el componente decide)", () => {
    expect(resolveDecor({ decorMode: "default" }, {})).toEqual({
      src: "",
      invertClass: "",
    });
    expect(defaultDecorPreset({})).toBe("");
  });

  it("un preset sin política usa `theme`", () => {
    expect(resolveDecor({}, { a: { src: "/a.svg" } }).invertClass).toBe("dark:invert");
  });
});
