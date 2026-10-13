import { describe, expect, it } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { QUALITY_ICON_KEYS } from "@/components/landings/product/quality-icons";
import { withTranslations } from "@/lib/i18n/translation";
import { ADVICE_MAX_PER_TYPE } from "@/lib/landings/advice";
import { LANDING_MAX_COMPONENTS, LANDING_MAX_QUALITIES } from "@/lib/landings/limits";
import { LANDING_PROBE_FIELDS } from "@/lib/landings/localized";
import {
  LANDING_ROW_FIELDS,
  LANDING_SCALAR_FIELDS,
  LANDING_TRANSLATABLE_SCALARS,
  PRODUCT_LANDING_QUERY,
} from "@/lib/landings/queries";

// ============================================================================
//  Paridad frontend ↔ plugin `hwe-product-landing` (backend).
//
//  El esquema de la landing vive escrito a mano en el plugin (`Fields.php`) y en
//  el frontend (`queries.ts`, `types.ts`, `product-landing.ts`). Sin estos
//  tests, un campo añadido en el plugin se pierde en silencio: la consulta no lo
//  pide y el normalizador no lo mapea. Se lee `Fields.php` como texto (no un
//  fixture versionado) para que el test falle en cuanto cualquiera de los dos
//  lados cambie.
// ============================================================================

const FIELDS_PHP = readFileSync(
  resolve(
    process.cwd(),
    "../backend/wp-content/plugins/hwe-product-landing/includes/Fields.php",
  ),
  "utf8",
);

const camel = (snake: string) =>
  snake.replace(/_([a-z0-9])/g, (_, c: string) => c.toUpperCase());

/** Bloque `public const NAME = [ ... ];` de Fields.php. */
function phpConstBlock(name: string): string {
  const match = FIELDS_PHP.match(
    new RegExp(`const ${name}\\s*=\\s*\\[([\\s\\S]*?)\\n    \\];`),
  );
  if (!match) throw new Error(`No se encontró «const ${name} = [...]» en Fields.php`);
  return match[1]!;
}

interface PhpField {
  key: string;
  type: string;
  translatable: boolean;
}

function phpScalars(): PhpField[] {
  return [
    ...phpConstBlock("SCALAR_FIELDS").matchAll(/'([a-z0-9_]+)'\s*=>\s*\[([^\]]*)\]/g),
  ].map((m) => ({
    key: m[1]!,
    type: m[2]!.match(/'type'\s*=>\s*'([a-z]+)'/)?.[1] ?? "",
    translatable: /'translatable'\s*=>\s*true/.test(m[2]!),
  }));
}

interface PhpRepeater {
  maxRows: number | null;
  fields: PhpField[];
}

function phpRepeaters(): Record<string, PhpRepeater> {
  const out: Record<string, PhpRepeater> = {};
  const block = phpConstBlock("REPEATERS");
  for (const m of block.matchAll(/'([a-z]+)'\s*=>\s*\[([\s\S]*?)\n        \],/g)) {
    const body = m[2]!;
    const maxRows = body.match(/'max_rows'\s*=>\s*(\d+)/);
    const rowFields = body.match(/'row_fields'\s*=>\s*\[([\s\S]*)$/)?.[1] ?? "";
    out[m[1]!] = {
      maxRows: maxRows ? Number(maxRows[1]) : null,
      fields: [...rowFields.matchAll(/'([a-z]+)'\s*=>\s*\[([^\]]*)\]/g)].map((rf) => ({
        key: rf[1]!,
        type: rf[2]!.match(/'type'\s*=>\s*'([a-z]+)'/)?.[1] ?? "",
        translatable: /'translatable'\s*=>\s*true/.test(rf[2]!),
      })),
    };
  }
  return out;
}

describe("hwe-product-landing ↔ frontend", () => {
  it("el parseo de Fields.php no quedó vacío (guarda contra falsos verdes)", () => {
    expect(phpScalars().length).toBeGreaterThan(25);
    expect(Object.keys(phpRepeaters())).toEqual(["components", "qualities", "advice"]);
  });

  it("los escalares del frontend son los del plugin, en el mismo orden", () => {
    expect([...LANDING_SCALAR_FIELDS]).toEqual(phpScalars().map((f) => camel(f.key)));
  });

  it("los escalares traducibles del frontend son los del plugin", () => {
    const php = phpScalars()
      .filter((f) => f.translatable)
      .map((f) => camel(f.key));
    expect([...LANDING_TRANSLATABLE_SCALARS]).toEqual(php);
  });

  it("los campos de cada lista y los traducibles coinciden con el plugin", () => {
    for (const [key, repeater] of Object.entries(phpRepeaters())) {
      const spec = LANDING_ROW_FIELDS[key as keyof typeof LANDING_ROW_FIELDS];
      expect([...spec.fields], key).toEqual(repeater.fields.map((f) => f.key));
      expect([...spec.translatable], key).toEqual(
        repeater.fields.filter((f) => f.translatable).map((f) => f.key),
      );
    }
  });

  it("los campos de peso para decidir el idioma existen y son traducibles", () => {
    for (const field of LANDING_PROBE_FIELDS) {
      expect(LANDING_TRANSLATABLE_SCALARS as readonly string[]).toContain(field);
    }
  });

  it("la consulta pide cada campo y la traducción de cada traducible", () => {
    const document = withTranslations(PRODUCT_LANDING_QUERY, ["en"]);

    for (const field of LANDING_SCALAR_FIELDS) {
      expect(document, field).toMatch(new RegExp(`\\b${field}\\b`));
    }
    const landingTranslation = document.match(
      /tr_en: translation\(locale: "en"\) \{ ([^}]*) \}/g,
    );
    // Una para la landing y una por lista.
    expect(landingTranslation).toHaveLength(4);
    expect(landingTranslation![0]).toContain(LANDING_TRANSLATABLE_SCALARS.join(" "));
  });

  it("sin idiomas secundarios la consulta no menciona `translation`", () => {
    expect(withTranslations(PRODUCT_LANDING_QUERY, [])).not.toMatch(/translation/);
  });

  it("los topes de filas de las listas coinciden con los de la vista", () => {
    const repeaters = phpRepeaters();
    expect(repeaters.components?.maxRows).toBe(LANDING_MAX_COMPONENTS);
    expect(repeaters.qualities?.maxRows).toBe(LANDING_MAX_QUALITIES);
    expect(repeaters.advice?.maxRows).toBe(2 * ADVICE_MAX_PER_TYPE);
  });

  it("el set de íconos del plugin es el del starter", () => {
    const block =
      FIELDS_PHP.match(/const DEFAULT_ICONS\s*=\s*\[([\s\S]*?)\];/)?.[1] ?? "";
    const keys = [...block.matchAll(/'([a-z][a-z0-9-]*)'\s*=>/g)].map((m) => m[1]);

    expect(keys.length).toBe(12);
    expect(QUALITY_ICON_KEYS).toEqual(keys);
  });
});
