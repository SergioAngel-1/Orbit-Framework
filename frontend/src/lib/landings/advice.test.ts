import { describe, expect, it } from "vitest";
import { ADVICE_MAX_PER_TYPE, advicePageFaq, splitAdvice } from "./advice";

const tip = (body: string) => ({ heading: null, body });
const question = (heading: string, body: string) => ({ heading, body });

describe("splitAdvice", () => {
  it("separa consejos (sin encabezado) y preguntas (con encabezado), conservando el orden", () => {
    const result = splitAdvice([
      tip("uno"),
      question("¿A?", "a"),
      tip("dos"),
      question("¿B?", "b"),
    ]);

    expect(result.tips.map((i) => i.body)).toEqual(["uno", "dos"]);
    expect(result.questions.map((i) => i.heading)).toEqual(["¿A?", "¿B?"]);
  });

  it("recorta cada tipo por separado", () => {
    const items = [
      ...Array.from({ length: 14 }, (_, i) => tip(`t${i}`)),
      ...Array.from({ length: 12 }, (_, i) => question(`¿${i}?`, `r${i}`)),
    ];

    const result = splitAdvice(items);

    expect(result.tips).toHaveLength(ADVICE_MAX_PER_TYPE);
    expect(result.questions).toHaveLength(ADVICE_MAX_PER_TYPE);
  });
});

describe("advicePageFaq", () => {
  it("solo emite las preguntas con encabezado y respuesta", () => {
    const faq = advicePageFaq({
      enabled: true,
      items: [
        tip("un consejo"),
        question("¿Cómo?", "<p>Así</p>"),
        question("¿Sin?", "  "),
      ],
    });

    expect(faq).toEqual([{ question: "¿Cómo?", answer: "Así" }]);
  });

  it("no emite nada con la sección apagada", () => {
    expect(
      advicePageFaq({ enabled: false, items: [question("¿Cómo?", "Así")] }),
    ).toEqual([]);
  });

  it("no emite nada si solo hay consejos", () => {
    expect(advicePageFaq({ enabled: true, items: [tip("a"), tip("b")] })).toEqual([]);
  });

  it("recorta igual que la sección visible", () => {
    const items = Array.from({ length: 13 }, (_, i) => question(`¿${i}?`, `r${i}`));

    expect(advicePageFaq({ enabled: true, items })).toHaveLength(ADVICE_MAX_PER_TYPE);
  });
});
