import { describe, expect, it } from "vitest";
import { checkParity } from "../../scripts/check-i18n-parity.mjs";

// ============================================================================
//  Paridad de mensajes es ↔ en (scripts/check-i18n-parity.mjs, `npm run
//  i18n:check`). Una fixture mínima por comprobación: qué cuenta como error, qué
//  como aviso y qué se tolera.
// ============================================================================

type Source = { path: string; code: string };
const check = (
  es: unknown,
  en: unknown,
  sources: Source[] = [],
  esOnly: string[] = [],
) => checkParity({ es, en, sources, esOnlyPrefixes: esOnly });

/** Texto de los errores, para buscar la clave que se reporta. */
const errorText = (r: ReturnType<typeof check>) => r.errors.join("\n");

describe("1. claves", () => {
  it("mismos mensajes en los dos idiomas: sin errores", () => {
    const r = check({ a: { b: "Hola" }, c: "Adiós" }, { a: { b: "Hi" }, c: "Bye" });
    expect(r.errors).toEqual([]);
    expect(r.leafCount).toBe(2);
  });

  it("una clave solo en es.json se reporta como faltante en en.json", () => {
    const r = check({ a: "x", nueva: "y" }, { a: "x" });
    expect(errorText(r)).toMatch(/Faltan en en\.json/);
    expect(errorText(r)).toContain("nueva");
  });

  it("una clave solo en en.json se reporta como faltante en es.json", () => {
    const r = check({ a: "x" }, { a: "x", extra: "y" });
    expect(errorText(r)).toMatch(/Faltan en es\.json/);
    expect(errorText(r)).toContain("extra");
  });

  it("los prefijos solo-español no se exigen en inglés", () => {
    const r = check({ a: "x", propio: { k: "y" } }, { a: "x" }, [], ["propio."]);
    expect(r.errors).toEqual([]);
  });
});

describe("2. forma", () => {
  it("una lista con distinto número de elementos es un error", () => {
    const r = check({ pasos: ["a", "b", "c"] }, { pasos: ["a", "b"] });
    expect(errorText(r)).toMatch(/Forma distinta/);
    expect(errorText(r)).toContain("pasos");
    expect(errorText(r)).toMatch(/3 en es y 2 en en/);
  });

  it("texto en un idioma y objeto en el otro es un error", () => {
    const r = check({ k: "texto" }, { k: { a: "x" } });
    expect(r.errors.length).toBeGreaterThan(0);
  });
});

describe("3. argumentos", () => {
  it("un {argumento} que falta en la traducción es un error", () => {
    const r = check({ k: "Año {year}" }, { k: "Year" });
    expect(errorText(r)).toMatch(/Argumentos distintos/);
    expect(errorText(r)).toContain("year");
  });

  it("los argumentos de plural se comparan por nombre", () => {
    const ok = check(
      { k: "{count, plural, one {# cosa} other {# cosas}}" },
      { k: "{count, plural, one {# thing} other {# things}}" },
    );
    expect(ok.errors).toEqual([]);

    const bad = check(
      { k: "{count, plural, one {# cosa} other {# cosas}}" },
      { k: "{total, plural, one {# thing} other {# things}}" },
    );
    expect(errorText(bad)).toMatch(/Argumentos distintos/);
  });
});

describe("4. etiquetas", () => {
  it("una <etiqueta> que falta en la traducción es un error", () => {
    const r = check({ k: "Hola <em>mundo</em>" }, { k: "Hello world" });
    expect(errorText(r)).toMatch(/Etiquetas distintas/);
    expect(errorText(r)).toContain("em");
  });

  it("las etiquetas autocerradas cuentan igual que las de pareja", () => {
    const r = check({ k: "uno<br/>dos" }, { k: "one<br />two" });
    expect(r.errors).toEqual([]);
  });
});

describe("5. handlers de t.rich()", () => {
  const messages = { k: { texto: "Hola <em>mundo</em> <strong>x</strong>" } };
  const same = [messages, messages] as const;

  it("una etiqueta sin handler en la llamada rich() que la pinta es un error", () => {
    const code = `t.rich("texto", { strong: (c) => <b>{c}</b> })`;
    const r = check(...same, [{ path: "src/a.tsx", code }]);
    expect(errorText(r)).toMatch(/sin handler/);
    expect(errorText(r)).toContain("em");
    expect(errorText(r)).toContain("src/a.tsx");
  });

  it("con todos los handlers definidos no hay error", () => {
    const code = `t.rich("texto", { em: (c) => <i>{c}</i>, strong: (c) => <b>{c}</b> })`;
    expect(check(...same, [{ path: "src/a.tsx", code }]).errors).toEqual([]);
  });

  it("los handlers compartidos por spread se resuelven en el mismo archivo", () => {
    const code = `
      const mark = { em: (c) => <i>{c}</i>, strong: (c) => <b>{c}</b> };
      t.rich("texto", { ...mark })
    `;
    expect(check(...same, [{ path: "src/a.tsx", code }]).errors).toEqual([]);
  });

  it("un handler con JSX y llaves dentro no descuadra el recorte del objeto", () => {
    const code = `t.rich("texto", {
      em: (c) => <i className={cn("a", { b: true })}>{c}</i>,
      strong: (c) => <b>{c}</b>,
    })`;
    expect(check(...same, [{ path: "src/a.tsx", code }]).errors).toEqual([]);
  });

  it("sin ninguna llamada rich() es un AVISO, no un error", () => {
    const r = check(...same, [{ path: "src/a.tsx", code: "export const x = 1;" }]);
    expect(r.errors).toEqual([]);
    expect(r.warnings.join("\n")).toContain("k.texto");
  });

  it("una clave que se pinta como HTML saneado (raw) no avisa", () => {
    const code = `const html = sanitizeHtml(t.raw("texto"));`;
    const r = check(...same, [{ path: "src/a.tsx", code }]);
    expect(r.errors).toEqual([]);
    expect(r.warnings).toEqual([]);
  });

  it("los tests no cuentan como código que pinta mensajes", () => {
    const code = `t.rich("texto", { em: (c) => c, strong: (c) => c })`;
    const r = check(...same, [{ path: "src/a.test.tsx", code }]);
    expect(r.warnings.join("\n")).toContain("k.texto");
  });
});
