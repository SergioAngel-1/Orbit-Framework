import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { FaqSection } from "./faq-section";

describe("FaqSection", () => {
  it("una respuesta con </script> no puede cerrar el bloque JSON-LD (texto del CMS)", () => {
    const html = renderToStaticMarkup(
      <FaqSection
        title="Preguntas"
        items={[
          {
            question: "¿Envíos?",
            answer: '</script><script>alert("x")</script>',
          },
        ]}
      />,
    );

    // Un solo <script>: el del JSON-LD. El intento de cerrarlo y abrir otro no
    // llega como etiqueta.
    expect(html.match(/<script/g)).toHaveLength(1);
    expect(html.match(/<\/script>/g)).toHaveLength(1);
    const json = /<script[^>]*>([\s\S]*?)<\/script>/.exec(html)![1]!;
    expect(JSON.parse(json).mainEntity[0].acceptedAnswer.text).toBe(
      '</script><script>alert("x")</script>',
    );
  });

  it("sin preguntas no renderiza nada", () => {
    expect(renderToStaticMarkup(<FaqSection title="x" items={[]} />)).toBe("");
  });
});
