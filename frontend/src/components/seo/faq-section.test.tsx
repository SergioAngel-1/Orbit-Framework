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

  it("las preguntas de pagos van en una segunda columna y entran en el mismo FAQPage", () => {
    const html = renderToStaticMarkup(
      <FaqSection
        title="Preguntas"
        paymentsTitle="Pagos"
        items={[{ question: "¿Envíos?", answer: "Sí" }]}
        paymentItems={[{ question: "¿Qué tarjetas?", answer: "Todas" }]}
      />,
    );

    expect(html).toContain("Pagos");
    expect(html).toContain("¿Qué tarjetas?");
    expect(html).toContain("md:grid-cols-2");
    // Un solo bloque JSON-LD con las dos listas.
    expect(html.match(/<script/g)).toHaveLength(1);
    const json = /<script[^>]*>([\s\S]*?)<\/script>/.exec(html)![1]!;
    expect(JSON.parse(json).mainEntity).toHaveLength(2);
  });

  it("sin preguntas de pagos queda una sola columna", () => {
    const html = renderToStaticMarkup(
      <FaqSection
        title="Preguntas"
        paymentsTitle="Pagos"
        items={[{ question: "¿Envíos?", answer: "Sí" }]}
        paymentItems={[]}
      />,
    );

    expect(html).not.toContain("md:grid-cols-2");
    expect(html).not.toContain("Pagos");
  });

  it("solo con preguntas de pagos también se pinta", () => {
    const html = renderToStaticMarkup(
      <FaqSection
        title="Preguntas"
        paymentsTitle="Pagos"
        items={[]}
        paymentItems={[{ question: "¿Qué tarjetas?", answer: "Todas" }]}
      />,
    );

    expect(html).toContain("¿Qué tarjetas?");
  });

  it("sin preguntas no renderiza nada", () => {
    expect(renderToStaticMarkup(<FaqSection title="x" items={[]} />)).toBe("");
  });
});
