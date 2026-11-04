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
    <div class="wrap hwe-wrap" data-hwe-initial-tab="one" data-hwe-default-lang="es" ${wrapAttrs}>
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
  Array.from(
    document.querySelectorAll<HTMLDetailsElement>(
      `[data-hwe-panel="${p}"] details[data-hwe-fold]`,
    ),
  );

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
  Object.defineProperty(window, "localStorage", {
    configurable: true,
    value: memoryStorage(),
  });
  window.history.replaceState(null, "", "/wp-admin/admin.php?page=hwe-config");
});

afterEach(() => {
  document.body.innerHTML = "";
});

describe("Abrir todo / Colapsar todo", () => {
  const fixture = () =>
    panel(
      "one",
      section(
        "Datos",
        text("a") + subgroup("Sede 1", text("b")) + subgroup("Sede 2", text("c")),
      ) + section("Otros", text("d"), false),
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
    const html = panel(
      "one",
      section("Datos", text("a") + subgroup("Sede", text("b"))),
    );
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
    document
      .getElementById("b")!
      .dispatchEvent(new FocusEvent("focusin", { bubbles: true }));
    expect(inner.open).toBe(true);

    inner.open = false;
    inner
      .querySelector("summary")!
      .dispatchEvent(new FocusEvent("focusin", { bubbles: true }));
    expect(inner.open).toBe(false);
  });

  it("un control inválido abre su bloque para que el aviso del navegador se vea", () => {
    mount(panel("one", section("Datos", subgroup("Sede", text("b")))));
    const inner = folds("one")[1];
    document
      .getElementById("b")!
      .dispatchEvent(new Event("invalid", { bubbles: false }));
    expect(inner.open).toBe(true);
  });
});

describe("cambios sin guardar dentro de un bloque cerrado", () => {
  it("marca el bloque cerrado, el que lo contiene y la pestaña; volver al valor original lo limpia", () => {
    mount(panel("one", section("Datos", subgroup("Sede", text("b", "x")))), "");
    const [outer, inner] = folds("one");
    const mark = (d: HTMLElement) =>
      d.querySelector<HTMLElement>(":scope > summary [data-hwe-fold-dirty]")!;
    const input = document.getElementById("b") as HTMLInputElement;

    input.value = "y";
    input.dispatchEvent(new Event("input", { bubbles: true }));
    return new Promise<void>((resolve) =>
      setTimeout(() => {
        expect(inner.open).toBe(false);
        expect(mark(inner).hidden).toBe(false);
        expect(mark(outer).hidden).toBe(false);
        expect(
          document.querySelector<HTMLElement>(
            '[data-hwe-tab="one"] [data-hwe-dirty-mark]',
          )!.hidden,
        ).toBe(false);

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

// ---------------------------------------------------------------------------
//  Conmutador de idioma
// ---------------------------------------------------------------------------

const langSwitch = (extra = ["en"]) => `
  <div class="hwe-lang" data-hwe-lang-switch data-ref-label="Original en ES" data-ref-empty="Sin texto en ES." hidden>
    <div role="group">
      ${["es", ...extra]
        .map(
          (l, i) =>
            `<button type="button" data-hwe-lang="${l}" aria-pressed="${i === 0}">${l.toUpperCase()}</button>`,
        )
        .join("")}
    </div>
    <p data-hwe-lang-hint hidden>Solo se ven los textos traducibles.</p>
  </div>`;

const translatable = (id: string, value = "", locales = ["en"]) =>
  `<div class="hwe-field" data-hwe-translatable><label for="${id}">${id}</label><div class="hwe-field-control"><input type="text" id="${id}" name="${id}" value="${value}"></div></div>` +
  locales
    .map(
      (l) =>
        `<div class="hwe-field" data-hwe-locale="${l}" data-hwe-base="${id}"><label for="${id}_${l}">${id} (${l})</label><div class="hwe-field-control"><input type="text" id="${id}_${l}" name="${id}_${l}" value=""></div></div>`,
    )
    .join("");

const toggle = (id: string, checked = false) =>
  `<div class="hwe-field hwe-field--toggle"><label for="${id}">${id}</label><div class="hwe-field-control"><input type="hidden" name="${id}" value="0"><input type="checkbox" id="${id}" name="${id}" value="1" ${checked ? "checked" : ""}></div></div>`;

const row = (id: string) =>
  document.getElementById(id)!.closest<HTMLElement>(".hwe-field")!;
const hiddenByLang = (id: string) => row(id).classList.contains("is-off-lang");
const pick = (lang: string) =>
  document.querySelector<HTMLButtonElement>(`button[data-hwe-lang="${lang}"]`)!.click();

describe("idioma de edición", () => {
  const brand = () =>
    panel(
      "one",
      langSwitch() +
        section(
          "Identidad",
          translatable("tagline", "Hola mundo") +
            text("name", "Tienda") +
            toggle("flag", true),
        ) +
        section("Colores", text("color", "#000")),
    ) + panel("two", section("Pagos", text("pay")), true);

  it("muestra el conmutador (oculto de serie) y arranca en el idioma por defecto, sin las variantes", () => {
    mount(brand());
    expect(document.querySelector<HTMLElement>("[data-hwe-lang-switch]")!.hidden).toBe(
      false,
    );
    expect(hiddenByLang("tagline_en")).toBe(true);
    expect(hiddenByLang("tagline")).toBe(false);
    expect(hiddenByLang("name")).toBe(false);
  });

  it("en un idioma secundario deja solo las variantes y esconde lo que no se traduce", () => {
    mount(brand());
    pick("en");
    expect(hiddenByLang("tagline_en")).toBe(false);
    expect(hiddenByLang("tagline")).toBe(true);
    expect(hiddenByLang("name")).toBe(true);
    expect(hiddenByLang("flag")).toBe(true);
    expect(document.querySelector<HTMLElement>("[data-hwe-lang-hint]")!.hidden).toBe(
      false,
    );
    expect(
      document
        .querySelector('button[data-hwe-lang="en"]')!
        .getAttribute("aria-pressed"),
    ).toBe("true");
    expect(
      document
        .querySelector('button[data-hwe-lang="es"]')!
        .getAttribute("aria-pressed"),
    ).toBe("false");
  });

  it("los campos escondidos siguen en el formulario y se envían", () => {
    mount(brand());
    pick("en");
    const form = document.querySelector("form")!;
    const names = Array.from(new FormData(form).keys());
    expect(names).toEqual(
      expect.arrayContaining(["tagline", "tagline_en", "name", "flag", "color"]),
    );
    expect(document.getElementById("name")!.closest("form")).toBe(form);
  });

  it("el original aparece como referencia sobre la variante y se actualiza al editarlo", () => {
    mount(brand());
    pick("en");
    const ref = () => row("tagline_en").querySelector(".hwe-ref__text")!;
    expect(row("tagline_en").querySelector(".hwe-ref__label")!.textContent).toBe(
      "Original en ES",
    );
    expect(ref().textContent).toBe("Hola mundo");

    const base = document.getElementById("tagline") as HTMLInputElement;
    base.value = "Otro";
    base.dispatchEvent(new Event("input", { bubbles: true }));
    expect(ref().textContent).toBe("Otro");

    base.value = "";
    base.dispatchEvent(new Event("input", { bubbles: true }));
    expect(ref().textContent).toBe("Sin texto en ES.");
  });

  it("la referencia de un texto enriquecido sale sin etiquetas HTML", () => {
    mount(
      panel(
        "one",
        langSwitch() +
          section(
            "FAQ",
            `<div class="hwe-field" data-hwe-translatable><div class="hwe-field-control"><textarea id="answer" name="answer"><p>Uno <strong>dos</strong></p><p>Tres</p></textarea></div></div>
             <div class="hwe-field" data-hwe-locale="en" data-hwe-base="answer"><div class="hwe-field-control"><textarea id="answer_en" name="answer_en"></textarea></div></div>`,
          ),
      ),
    );
    pick("en");
    expect(row("answer_en").querySelector(".hwe-ref__text")!.textContent).toBe(
      "Uno dos\nTres",
    );
  });

  it("una sección sin nada que traducir desaparece en el idioma secundario y vuelve con el original", () => {
    mount(brand());
    const [identity, colors] = folds("one");
    pick("en");
    expect(identity.classList.contains("is-lang-empty")).toBe(false);
    expect(colors.classList.contains("is-lang-empty")).toBe(true);
    pick("es");
    expect(colors.classList.contains("is-lang-empty")).toBe(false);
  });

  it("«abrir todo» no cuenta los bloques que el idioma esconde", () => {
    mount(brand());
    pick("en");
    folds("one").forEach((d) => (d.open = false));
    btn().click();
    const [identity, colors] = folds("one");
    expect(identity.open).toBe(true);
    expect(colors.open).toBe(false);
  });

  it("una pestaña sin conmutador siempre muestra todo, aunque el idioma elegido sea otro", () => {
    mount(brand());
    pick("en");
    document.querySelector<HTMLElement>('[data-hwe-tab="two"]')!.click();
    expect(hiddenByLang("pay")).toBe(false);
    expect(document.querySelector('[data-hwe-panel="two"] .is-lang-empty')).toBeNull();
  });

  it("varios idiomas secundarios: cada uno enseña solo su variante", () => {
    mount(
      panel(
        "one",
        langSwitch(["en", "pt_br"]) +
          section("Identidad", translatable("tagline", "Hola", ["en", "pt_br"])),
      ),
    );
    pick("pt_br");
    expect(hiddenByLang("tagline_pt_br")).toBe(false);
    expect(hiddenByLang("tagline_en")).toBe(true);
    pick("en");
    expect(hiddenByLang("tagline_en")).toBe(false);
    expect(hiddenByLang("tagline_pt_br")).toBe(true);
  });

  it("recuerda el idioma elegido; un idioma que ya no existe vuelve al de la tienda", () => {
    mount(brand());
    pick("en");
    expect(window.localStorage.getItem("hwe-cc-lang")).toBe("en");
    mount(brand(), 'data-hwe-default-lang="es"');
    expect(hiddenByLang("tagline_en")).toBe(false);

    window.localStorage.setItem("hwe-cc-lang", "fr");
    mount(brand(), 'data-hwe-default-lang="es"');
    expect(hiddenByLang("tagline_en")).toBe(true);
  });

  it("un control inválido escondido por el idioma devuelve el panel al idioma por defecto", () => {
    mount(brand(), 'data-hwe-default-lang="es"');
    pick("en");
    document.getElementById("name")!.dispatchEvent(new Event("invalid"));
    expect(hiddenByLang("name")).toBe(false);
  });

  it("sin conmutador (tienda monolingüe) no se esconde nada", () => {
    mount(
      panel("one", section("Identidad", text("tagline") + text("name"))),
      'data-hwe-default-lang="es"',
    );
    expect(document.querySelector(".is-off-lang, .is-lang-empty")).toBeNull();
  });
});

// ---------------------------------------------------------------------------
//  Landings: selector, condiciones, resúmenes y huecos
// ---------------------------------------------------------------------------

const select = (
  id: string,
  options: Array<[string, string]>,
  value: string,
  extra = "",
) =>
  `<div class="hwe-field" ${extra}><label for="${id}">${id}</label><div class="hwe-field-control"><select id="${id}" name="${id}">${options
    .map(
      ([v, l]) => `<option value="${v}" ${v === value ? "selected" : ""}>${l}</option>`,
    )
    .join("")}</select></div></div>`;

const LAYOUTS: Array<[string, string]> = [
  ["image-left", "Imagen a la izquierda"],
  ["image-right", "Imagen a la derecha"],
  ["text-only", "Solo texto"],
  ["mirror-pair", "Pareja en espejo"],
];

const showIf = (field: string, values: string[]) =>
  `data-hwe-show-if='${JSON.stringify({ f: field, in: values })}'`;

const summaryAttr = (spec: object) => `data-hwe-summary='${JSON.stringify(spec)}'`;

const slotBox = (inner: string[], addLabel = "Añadir sección") => `
  <div class="hwe-slots" data-hwe-slots>
    <p class="description" data-hwe-slots-empty hidden>Aún no hay secciones.</p>
    ${inner
      .map(
        (html) =>
          `<div class="hwe-slot" data-hwe-slot>${html}<button type="button" data-hwe-slot-remove hidden>Quitar</button></div>`,
      )
      .join("")}
    <button type="button" data-hwe-slots-add hidden>${addLabel}</button>
  </div>`;

/** Una sección de landing: `enabled` es su interruptor maestro. */
const landingSection = (
  n: number,
  values: { enabled?: boolean; title?: string; layout?: string; image?: string } = {},
) =>
  subgroup(
    `Sección ${n}`,
    toggle(`s${n}_enabled`, values.enabled ?? false) +
      select(`s${n}_layout`, LAYOUTS, values.layout ?? "image-right") +
      text(`s${n}_title`, values.title ?? "") +
      `<div class="hwe-field" ${showIf(`s${n}_layout`, ["image-left", "image-right", "mirror-pair"])}><div class="hwe-field-control"><input type="url" id="s${n}_image" name="s${n}_image" value="${values.image ?? ""}"></div></div>` +
      `<div class="hwe-field" ${showIf(`s${n}_layout`, ["mirror-pair"])}><div class="hwe-field-control"><input type="url" id="s${n}_image_2" name="s${n}_image_2" value=""></div></div>`,
    `data-hwe-master="s${n}_enabled" ${summaryAttr({
      t: `s${n}_enabled`,
      on: "Visible",
      off: "Oculta",
      p: [
        { as: "select", f: `s${n}_layout` },
        { as: "text", f: `s${n}_title`, q: 1 },
      ],
    })}`,
  );

const summaryOfBlock = (el: Element) =>
  el.querySelector(":scope > summary [data-hwe-summary-out]")!.textContent;
const hiddenByCond = (id: string) => row(id).classList.contains("is-off-cond");
const change = (id: string, value: string) => {
  const el = document.getElementById(id) as HTMLInputElement | HTMLSelectElement;
  if (el instanceof HTMLInputElement && el.type === "checkbox")
    el.checked = value === "1";
  else el.value = value;
  el.dispatchEvent(new Event("change", { bubbles: true }));
};

describe("solo lo que aplica", () => {
  it("esconde los controles que la disposición no usa, sin quitarlos del formulario ni borrar su valor", () => {
    mount(
      panel(
        "one",
        section(
          "Landing",
          landingSection(1, {
            enabled: true,
            layout: "text-only",
            image: "/uploads/a.jpg",
          }),
        ),
      ),
    );
    expect(hiddenByCond("s1_image")).toBe(true);
    expect(hiddenByCond("s1_image_2")).toBe(true);
    expect((document.getElementById("s1_image") as HTMLInputElement).value).toBe(
      "/uploads/a.jpg",
    );
    expect(Array.from(new FormData(document.querySelector("form")!).keys())).toContain(
      "s1_image",
    );

    change("s1_layout", "image-left");
    expect(hiddenByCond("s1_image")).toBe(false);
    expect(hiddenByCond("s1_image_2")).toBe(true);

    change("s1_layout", "mirror-pair");
    expect(hiddenByCond("s1_image_2")).toBe(false);
  });

  it("un bloque con «Mostrar» apagado solo enseña el interruptor", () => {
    mount(panel("one", section("Landing", landingSection(1, { enabled: false }))));
    const block = folds("one")[1];
    expect(block.classList.contains("is-off")).toBe(true);
    expect(row("s1_enabled").classList.contains("is-master")).toBe(true);

    change("s1_enabled", "1");
    expect(block.classList.contains("is-off")).toBe(false);
  });

  it("al traducir no se apaga un bloque oculto: se puede traducir de antemano", () => {
    mount(
      panel(
        "one",
        langSwitch() +
          section(
            "Landing",
            landingSection(1, { enabled: false }) + translatable("s1_extra", "Hola"),
          ),
      ),
    );
    pick("en");
    expect(folds("one")[1].classList.contains("is-off")).toBe(false);
    pick("es");
    expect(folds("one")[1].classList.contains("is-off")).toBe(true);
  });

  it("«abrir todo» no cuenta los bloques apagados", () => {
    mount(
      panel("one", section("Landing", landingSection(1, { enabled: false }), false)),
    );
    btn().click();
    const [outer] = folds("one");
    expect(outer.open).toBe(true);
    expect(btn().textContent).toBe("Colapsar todo");
  });
});

describe("resumen de la cabecera", () => {
  it("cuenta el estado del bloque cerrado y se actualiza al editar", () => {
    mount(
      panel(
        "one",
        section(
          "Landing",
          landingSection(1, {
            enabled: true,
            title: "Cómo usarlo",
            layout: "image-left",
          }),
        ),
      ),
    );
    const block = folds("one")[1];
    expect(summaryOfBlock(block)).toBe(
      "Visible · Imagen a la izquierda · «Cómo usarlo»",
    );

    change("s1_layout", "text-only");
    expect(summaryOfBlock(block)).toBe("Visible · Solo texto · «Cómo usarlo»");

    change("s1_enabled", "0");
    expect(summaryOfBlock(block)).toBe("Oculta");
  });

  it("recorta los títulos largos y omite lo que está vacío", () => {
    mount(
      panel(
        "one",
        section(
          "Landing",
          landingSection(1, { enabled: true, title: "x".repeat(120) }),
        ),
      ),
    );
    const summary = summaryOfBlock(folds("one")[1])!;
    expect(summary.length).toBeLessThan(90);
    expect(summary).toContain("…»");

    change("s1_title", "");
    expect(summaryOfBlock(folds("one")[1])).toBe("Visible · Imagen a la derecha");
  });

  it("usa el texto de reserva cuando no hay nada que decir", () => {
    mount(
      panel(
        "one",
        section(
          "Landing",
          subgroup(
            "Hero",
            text("note"),
            summaryAttr({ p: [], empty: "Se edita en Banners" }),
          ),
        ),
      ),
    );
    expect(summaryOfBlock(folds("one")[1])).toBe("Se edita en Banners");
  });
});

describe("huecos (secciones y preguntas)", () => {
  const fixture = (n = 3) =>
    panel(
      "one",
      section(
        "Landing",
        subgroup(
          "Secciones",
          slotBox(
            [
              landingSection(1, { enabled: true, title: "Una" }),
              landingSection(2, { enabled: false }),
              landingSection(3, { enabled: false, layout: "text-only" }),
            ].slice(0, n),
          ),
          summaryAttr({
            p: [{ as: "slots", unit: "secciones" }],
            empty: "Sin secciones",
          }),
        ),
      ),
    );

  const slots = () =>
    Array.from(document.querySelectorAll<HTMLElement>("[data-hwe-slot]"));
  const add = () => document.querySelector<HTMLButtonElement>("[data-hwe-slots-add]")!;

  it("esconde los huecos vacíos: un desplegable con valor por defecto no cuenta como contenido", () => {
    mount(fixture());
    expect(slots().map((s) => s.hidden)).toEqual([false, true, true]);
    expect(add().disabled).toBe(false);
    expect(summaryOfBlock(folds("one")[1])).toBe("1 de 3 secciones");
  });

  it("una sección solo marcada como visible cuenta como ocupada", () => {
    mount(
      panel(
        "one",
        section(
          "L",
          subgroup(
            "Secciones",
            slotBox([
              landingSection(1, { enabled: true }),
              landingSection(2, { enabled: false }),
            ]),
          ),
        ),
      ),
    );
    expect(slots().map((s) => s.hidden)).toEqual([false, true]);
  });

  it("«Añadir» muestra el primer hueco libre, lo abre, lo enciende y lleva el foco al primer texto", () => {
    mount(fixture());
    add().click();
    const second = slots()[1];
    expect(second.hidden).toBe(false);
    expect(second.querySelector<HTMLDetailsElement>("details")!.open).toBe(true);
    expect((document.getElementById("s2_enabled") as HTMLInputElement).checked).toBe(
      true,
    );
    expect(summaryOfBlock(folds("one")[1])).toBe("2 de 3 secciones");
    expect(document.activeElement).toBe(document.getElementById("s2_title"));

    add().click();
    expect(add().disabled).toBe(true);
  });

  it("«Quitar» vacía el hueco para guardarlo vacío y deja volver a añadirlo", () => {
    mount(fixture());
    const first = slots()[0];
    (document.getElementById("s1_image") as HTMLInputElement).value = "/uploads/a.jpg";
    first.querySelector<HTMLButtonElement>("[data-hwe-slot-remove]")!.click();

    expect(first.hidden).toBe(true);
    expect((document.getElementById("s1_title") as HTMLInputElement).value).toBe("");
    expect((document.getElementById("s1_enabled") as HTMLInputElement).checked).toBe(
      false,
    );
    expect((document.getElementById("s1_image") as HTMLInputElement).value).toBe("");
    expect(summaryOfBlock(folds("one")[1])).toBe("Sin secciones");
    expect(add().disabled).toBe(false);
    // Sigue en el formulario: se envía vacío.
    expect(new FormData(document.querySelector("form")!).get("s1_title")).toBe("");
  });

  it("al vaciar un hueco avisa del cambio sin guardar", () => {
    mount(fixture());
    slots()[0].querySelector<HTMLButtonElement>("[data-hwe-slot-remove]")!.click();
    return new Promise<void>((resolve) =>
      setTimeout(() => {
        expect(
          document.querySelector<HTMLElement>(
            '[data-hwe-tab="one"] [data-hwe-dirty-mark]',
          )!.hidden,
        ).toBe(false);
        resolve();
      }, 5),
    );
  });
});

describe("selector de landing", () => {
  const fixture = (ids = ["guia", "perfil"]) =>
    panel(
      "one",
      section(
        "Landings",
        `<div class="hwe-switcher" data-hwe-switcher>
          <div data-hwe-switcher-bar hidden><label for="sw">Editando</label><select id="sw" data-hwe-switcher-select>${ids
            .map((id) => `<option value="${id}">${id}</option>`)
            .join("")}</select></div>
          ${ids
            .map(
              (id) =>
                `<div class="hwe-switch-item" data-hwe-switch-item="${id}"><h4 class="hwe-switch-title">${id}</h4>${text(`${id}_title`, id)}</div>`,
            )
            .join("")}
        </div>`,
      ),
    ) + panel("two", section("Dos", text("x")), true);

  const items = () =>
    Array.from(document.querySelectorAll<HTMLElement>("[data-hwe-switch-item]"));
  const selectEl = () => document.getElementById("sw") as HTMLSelectElement;

  it("enseña una landing a la vez y deja las demás en el formulario", () => {
    mount(fixture());
    expect(document.querySelector<HTMLElement>("[data-hwe-switcher-bar]")!.hidden).toBe(
      false,
    );
    expect(items().map((i) => i.hidden)).toEqual([false, true]);
    expect(Array.from(new FormData(document.querySelector("form")!).keys())).toEqual(
      expect.arrayContaining(["guia_title", "perfil_title"]),
    );
  });

  it("al elegir otra, la pone en la URL y en la acción del formulario para que sobreviva al guardado", () => {
    mount(fixture());
    selectEl().value = "perfil";
    selectEl().dispatchEvent(new Event("change", { bubbles: true }));

    expect(items().map((i) => i.hidden)).toEqual([true, false]);
    expect(window.location.hash).toBe("#one/perfil");
    expect(document.querySelector("form")!.getAttribute("action")).toBe(
      "https://t.example/wp-admin/admin.php?page=hwe-config#one/perfil",
    );
  });

  it("arranca en la landing que dice la URL", () => {
    window.history.replaceState(
      null,
      "",
      "/wp-admin/admin.php?page=hwe-config#one/perfil",
    );
    mount(fixture());
    expect(items().map((i) => i.hidden)).toEqual([true, false]);
    expect(selectEl().value).toBe("perfil");
  });

  it("una landing que ya no existe en la URL cae en la primera", () => {
    window.history.replaceState(
      null,
      "",
      "/wp-admin/admin.php?page=hwe-config#one/borrada",
    );
    mount(fixture());
    expect(items().map((i) => i.hidden)).toEqual([false, true]);
  });

  it("con una sola landing no hay selector", () => {
    mount(fixture(["guia"]));
    expect(document.querySelector<HTMLElement>("[data-hwe-switcher-bar]")!.hidden).toBe(
      true,
    );
    expect(items().map((i) => i.hidden)).toEqual([false]);
  });

  it("cambiar de pestaña conserva la landing elegida en la URL de vuelta", () => {
    mount(fixture());
    selectEl().value = "perfil";
    selectEl().dispatchEvent(new Event("change", { bubbles: true }));
    document.querySelector<HTMLElement>('[data-hwe-tab="two"]')!.click();
    expect(window.location.hash).toBe("#two");
    document.querySelector<HTMLElement>('[data-hwe-tab="one"]')!.click();
    expect(window.location.hash).toBe("#one/perfil");
  });

  it("un control inválido en una landing escondida la enseña para poder avisar", () => {
    mount(fixture());
    document.getElementById("perfil_title")!.dispatchEvent(new Event("invalid"));
    expect(items().map((i) => i.hidden)).toEqual([true, false]);
  });
});
