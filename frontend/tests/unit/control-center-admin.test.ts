// @vitest-environment jsdom
import { readFileSync } from "node:fs";
import path from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";

// ============================================================================
//  `assets/admin.js` del HWE Control Center (wp-admin) contra un DOM mínimo.
//
//  El script es un IIFE sin exports que se engancha al DOM al cargarse, así que cada
//  test monta su fixture y lo evalúa de nuevo. Se prueba el comportamiento (qué se ve,
//  qué se marca, qué se envía), no el marcado exacto: ese lo fija PHPUnit.
//  Cómo se ve (390 y 1280 px) se mira en el navegador.
// ============================================================================

// Los tests de `frontend/` corren con `frontend/` como directorio de trabajo.
const SCRIPT = readFileSync(
  path.resolve(
    process.cwd(),
    "../backend/wp-content/mu-plugins/hwe-control-center/assets/admin.js",
  ),
  "utf8",
);

function mount(panels: string, wrapAttrs = ""): void {
  document.body.innerHTML = `
    <div class="wrap hwe-wrap" data-hwe-initial-tab="one" ${wrapAttrs}>
      <form method="post" action="https://t.example/wp-admin/admin.php?page=hwe-config" class="hwe-form" data-hwe-form>
        <nav class="hwe-nav">
          <button type="button" class="hwe-nav__toggle"><span data-hwe-nav-current></span></button>
          <a href="#one" data-hwe-tab="one"><span class="hwe-nav__label">Uno</span><span data-hwe-dirty-mark hidden></span></a>
          <a href="#two" data-hwe-tab="two"><span class="hwe-nav__label">Dos</span><span data-hwe-dirty-mark hidden></span></a>
        </nav>
        <div class="hwe-main">
          ${panels}
          <div class="hwe-savebar">
            <p data-hwe-status data-clean="Sin cambios" data-dirty="Cambios en: %s." data-saving="Guardando" data-more="%s y %d más"></p>
            <button type="submit" class="hwe-savebar__btn">Guardar</button>
            <button type="button" data-hwe-fold-all data-open="Abrir todo" data-close="Colapsar todo" hidden>Abrir todo</button>
          </div>
        </div>
      </form>
    </div>`;
  // eslint-disable-next-line no-eval
  window.eval(SCRIPT);
}

const section = (title: string, body: string, open = true) => `
  <details class="hwe-section" data-hwe-fold ${open ? "open" : ""}>
    <summary class="hwe-section__head"><h3>${title}</h3><span data-hwe-summary-out></span><span data-hwe-fold-dirty hidden></span></summary>
    <div class="hwe-fields">${body}</div>
  </details>`;

const subgroup = (title: string, body: string, attrs = "") => `
  <details class="hwe-subgroup" data-hwe-fold ${attrs}>
    <summary class="hwe-subgroup__head"><h4>${title}</h4><span data-hwe-summary-out></span><span data-hwe-fold-dirty hidden></span></summary>
    <div class="hwe-fields">${body}</div>
  </details>`;

const text = (name: string, value = "") =>
  `<div class="hwe-field"><label for="${name}">${name}</label><input type="text" id="${name}" name="${name}" value="${value}"></div>`;

const panel = (key: string, inner: string, hidden = false) =>
  `<section class="hwe-panel" data-hwe-panel="${key}" ${hidden ? "hidden" : ""}>${inner}</section>`;

const btn = () => document.querySelector<HTMLButtonElement>("[data-hwe-fold-all]")!;
const folds = (p = "one") =>
  Array.from(document.querySelectorAll<HTMLDetailsElement>(`[data-hwe-panel="${p}"] details[data-hwe-fold]`));

// jsdom no trae almacenamiento sin una URL de origen: un Storage mínimo en memoria.
function memoryStorage(): Storage {
  const data = new Map<string, string>();
  return {
    get length() {
      return data.size;
    },
    clear: () => data.clear(),
    getItem: (k: string) => (data.has(k) ? data.get(k)! : null),
    key: (i: number) => Array.from(data.keys())[i] ?? null,
    removeItem: (k: string) => void data.delete(k),
    setItem: (k: string, v: string) => void data.set(k, String(v)),
  };
}

beforeEach(() => {
  Object.defineProperty(window, "localStorage", { configurable: true, value: memoryStorage() });
  window.history.replaceState(null, "", "/wp-admin/admin.php?page=hwe-config");
});

afterEach(() => {
  document.body.innerHTML = "";
});

describe("Abrir todo / Colapsar todo", () => {
  const fixture = () =>
    panel(
      "one",
      section("Datos", text("a") + subgroup("Sede 1", text("b")) + subgroup("Sede 2", text("c"))) +
        section("Otros", text("d"), false),
    ) + panel("two", section("Dos", text("e"), false), true);

  it("la etiqueta refleja el estado real: con algo cerrado ofrece «Abrir todo»", () => {
    mount(fixture());
    expect(btn().hidden).toBe(false);
    expect(btn().textContent).toBe("Abrir todo");
  });

  it("abre todos los bloques de la pestaña activa y pasa a ofrecer «Colapsar todo»", () => {
    mount(fixture());
    btn().click();
    expect(folds("one").every((d) => d.open)).toBe(true);
    expect(btn().textContent).toBe("Colapsar todo");
  });

  it("con todo abierto, un clic lo cierra todo; la etiqueta vuelve a «Abrir todo»", () => {
    mount(fixture());
    btn().click();
    btn().click();
    expect(folds("one").every((d) => !d.open)).toBe(true);
    expect(btn().textContent).toBe("Abrir todo");
  });

  it("actúa solo sobre la pestaña activa", () => {
    mount(fixture());
    btn().click();
    expect(folds("two").every((d) => !d.open)).toBe(true);
  });

  it("abrir a mano el último bloque cerrado cambia la etiqueta sin pulsar el botón", () => {
    mount(fixture());
    btn().click(); // todo abierto
    folds("one")[0].open = false; // se cierra uno a mano
    btn().click(); // el botón sigue la realidad: había uno cerrado, así que abre todo
    expect(folds("one").every((d) => d.open)).toBe(true);
  });

  it("cambiar de pestaña recalcula la etiqueta con los bloques de la nueva", () => {
    mount(fixture());
    btn().click();
    expect(btn().textContent).toBe("Colapsar todo");
    document.querySelector<HTMLElement>('[data-hwe-tab="two"]')!.click();
    expect(btn().textContent).toBe("Abrir todo");
  });
});

describe("estado de los bloques", () => {
  it("recuerda por pestaña lo abierto y lo cerrado", () => {
    const html = panel("one", section("Datos", text("a") + subgroup("Sede", text("b"))));
    mount(html);
    btn().click();
    expect(window.localStorage.getItem("hwe-cc-fold:one")).toBe("11");
    folds("one")[1].open = false;
    // Al volver, el panel se monta de nuevo con lo guardado.
    window.localStorage.setItem("hwe-cc-fold:one", "10");
    mount(html);
    expect(folds("one").map((d) => d.open)).toEqual([true, false]);
  });

  it("descarta lo guardado si el panel cambió de forma", () => {
    window.localStorage.setItem("hwe-cc-fold:one", "0");
    mount(panel("one", section("Datos", text("a") + subgroup("Sede", text("b")))));
    expect(folds("one").map((d) => d.open)).toEqual([true, false]);
  });

  it("funciona sin localStorage", () => {
    const original = Object.getOwnPropertyDescriptor(window, "localStorage")!;
    Object.defineProperty(window, "localStorage", {
      configurable: true,
      get() {
        throw new Error("bloqueado");
      },
    });
    try {
      mount(panel("one", section("Datos", text("a") + subgroup("Sede", text("b")))));
      btn().click();
      expect(folds("one").every((d) => d.open)).toBe(true);
    } finally {
      Object.defineProperty(window, "localStorage", original);
    }
  });

  it("un campo con el foco abre el bloque que lo contiene, pero pulsar su cabecera no lo reabre", () => {
    mount(panel("one", section("Datos", subgroup("Sede", text("b")))));
    const inner = folds("one")[1];
    expect(inner.open).toBe(false);
    document.getElementById("b")!.dispatchEvent(new FocusEvent("focusin", { bubbles: true }));
    expect(inner.open).toBe(true);

    inner.open = false;
    inner.querySelector("summary")!.dispatchEvent(new FocusEvent("focusin", { bubbles: true }));
    expect(inner.open).toBe(false);
  });

  it("un control inválido abre su bloque para que el aviso del navegador se vea", () => {
    mount(panel("one", section("Datos", subgroup("Sede", text("b")))));
    const inner = folds("one")[1];
    document.getElementById("b")!.dispatchEvent(new Event("invalid", { bubbles: false }));
    expect(inner.open).toBe(true);
  });
});

describe("cambios sin guardar dentro de un bloque cerrado", () => {
  it("marca el bloque cerrado, el que lo contiene y la pestaña; volver al valor original lo limpia", () => {
    mount(panel("one", section("Datos", subgroup("Sede", text("b", "x")))), "");
    const [outer, inner] = folds("one");
    const mark = (d: HTMLElement) => d.querySelector<HTMLElement>(":scope > summary [data-hwe-fold-dirty]")!;
    const input = document.getElementById("b") as HTMLInputElement;

    input.value = "y";
    input.dispatchEvent(new Event("input", { bubbles: true }));
    return new Promise<void>((resolve) =>
      setTimeout(() => {
        expect(inner.open).toBe(false);
        expect(mark(inner).hidden).toBe(false);
        expect(mark(outer).hidden).toBe(false);
        expect(document.querySelector<HTMLElement>('[data-hwe-tab="one"] [data-hwe-dirty-mark]')!.hidden).toBe(false);

        input.value = "x";
        input.dispatchEvent(new Event("input", { bubbles: true }));
        setTimeout(() => {
          expect(mark(inner).hidden).toBe(true);
          expect(mark(outer).hidden).toBe(true);
          resolve();
        }, 5);
      }, 5),
    );
  });
});
