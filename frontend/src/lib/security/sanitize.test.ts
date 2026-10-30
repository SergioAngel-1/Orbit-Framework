import { describe, expect, it } from "vitest";
import DOMPurify from "isomorphic-dompurify";
import { sanitizeHtml } from "./sanitize";

// ============================================================================
//  Sanitización de HTML del CMS.
//
//  · Un enlace que abre otra ventana (`target`) conserva `window.opener` si no
//    lleva `rel="noopener noreferrer"`: la página abierta podría redirigir la
//    nuestra (reverse tabnabbing). El hook lo fuerza FUSIONANDO con el `rel` que
//    ya traiga el CMS (p. ej. `nofollow`), no sobrescribiéndolo.
//  · El hook se registra UNA vez al importar el módulo. Registrarlo dentro de
//    `sanitizeHtml` añadía un hook más por cada llamada y los acumulaba durante
//    toda la vida del proceso del servidor.
// ============================================================================

function rel(html: string): string[] {
  const out = sanitizeHtml(html);
  const match = out.match(/rel="([^"]*)"/);
  return match ? match[1]!.split(/\s+/).filter(Boolean) : [];
}

describe("rel en enlaces que abren otra ventana", () => {
  it('fuerza rel="noopener noreferrer" con target="_blank"', () => {
    expect(rel('<a href="https://x.com" target="_blank">x</a>').sort()).toEqual([
      "noopener",
      "noreferrer",
    ]);
  });

  it("conserva el rel del CMS (nofollow) y fusiona noopener noreferrer", () => {
    expect(
      rel('<a href="https://x.com" target="_blank" rel="nofollow">x</a>').sort(),
    ).toEqual(["nofollow", "noopener", "noreferrer"]);
  });

  it("no duplica tokens que ya estaban", () => {
    const tokens = rel(
      '<a href="https://x.com" target="_blank" rel="noopener nofollow">x</a>',
    );

    expect(tokens.filter((t) => t === "noopener")).toHaveLength(1);
  });

  it("quita el token `opener`, que contradice a noopener", () => {
    expect(
      rel('<a href="https://x.com" target="_blank" rel="opener">x</a>'),
    ).not.toContain("opener");
  });

  it("también con otras grafías de target que abren una ventana", () => {
    expect(rel('<a href="https://x.com" target="_BLANK">x</a>')).toContain("noopener");
    expect(rel('<a href="https://x.com" target="ventana-nombrada">x</a>')).toContain(
      "noopener",
    );
  });

  it("no toca los enlaces que navegan en la misma pestaña", () => {
    expect(sanitizeHtml('<a href="https://x.com">x</a>')).not.toContain("rel=");
    expect(sanitizeHtml('<a href="https://x.com" target="_self">x</a>')).not.toContain(
      "rel=",
    );
  });
});

describe("el hook se registra una sola vez", () => {
  type Hook = (...args: unknown[]) => unknown;
  const ENTRY = "afterSanitizeAttributes";

  // `addHook` no se puede espiar: el export por defecto de isomorphic-dompurify es un
  // Proxy que delega en una instancia privada. Se cuentan los hooks de forma no
  // destructiva: se extraen con `removeHook` (que devuelve el hook quitado) y se
  // restituyen en el mismo orden.
  function registeredHooks(): number {
    const purify = DOMPurify as unknown as {
      removeHook: (entry: string) => Hook | undefined;
      addHook: (entry: string, hook: Hook) => void;
    };
    const popped: Hook[] = [];
    for (let hook = purify.removeHook(ENTRY); hook; hook = purify.removeHook(ENTRY)) {
      popped.push(hook);
    }
    for (const hook of popped.reverse()) purify.addHook(ENTRY, hook);
    return popped.length;
  }

  it("queda registrado al importar el módulo", () => {
    expect(registeredHooks()).toBeGreaterThanOrEqual(1);
  });

  it("sanear no registra más hooks (no crecen durante la vida del proceso)", () => {
    const before = registeredHooks();

    for (let i = 0; i < 5; i++) {
      sanitizeHtml('<a href="https://x.com" target="_blank">x</a>');
    }

    expect(registeredHooks()).toBe(before);
  });
});

describe("tablas del contenido del CMS", () => {
  it("conserva su estructura y sus atributos de accesibilidad", () => {
    const html =
      '<table><caption>Envíos</caption><thead><tr><th scope="col" colspan="2">Zona</th></tr></thead>' +
      '<tbody><tr><td rowspan="2">Centro</td><td>1 día</td></tr></tbody></table>';

    expect(sanitizeHtml(html)).toBe(html);
  });

  it("no abre vectores de XSS por los atributos de una tabla", () => {
    const clean = sanitizeHtml(
      '<table background="javascript:alert(1)" onmouseover="alert(1)" style="x:1">' +
        '<tr><td onclick="alert(1)" colspan="2">x</td></tr></table>',
    );

    expect(clean).not.toMatch(/onmouseover|onclick|background|style/);
    expect(clean).toContain('colspan="2"');
  });
});

describe("saneado general (sin regresiones)", () => {
  it.each([
    ["scripts", "<p>hola</p><script>alert(1)</script>", "<script"],
    ["atributos de evento", '<img src="x" onerror="alert(1)">', "onerror"],
    ["javascript: en href", '<a href="javascript:alert(1)">x</a>', "javascript:"],
    ["atributo style", '<p style="color:red">x</p>', "style="],
    ["iframes", '<iframe src="https://evil.example"></iframe>', "<iframe"],
  ])("elimina %s", (_caso, dirty, forbidden) => {
    expect(sanitizeHtml(dirty)).not.toContain(forbidden);
  });

  it("conserva el HTML editorial permitido", () => {
    const out = sanitizeHtml(
      "<h2>Título</h2><p><strong>negrita</strong> y <em>cursiva</em></p>",
    );

    expect(out).toBe(
      "<h2>Título</h2><p><strong>negrita</strong> y <em>cursiva</em></p>",
    );
  });
});
