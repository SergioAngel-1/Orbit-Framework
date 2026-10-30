import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import GlobalError from "./error";
import GlobalNotFound from "./not-found";
import { lastResortCopy } from "./last-resort";

const SECRET = "connect ECONNREFUSED wordpress:80 secret=abc";

function renderError(digest?: string) {
  return renderToStaticMarkup(
    <GlobalError
      error={Object.assign(new Error(SECRET), { digest })}
      reset={() => {}}
    />,
  );
}

describe("pantalla de error de último recurso", () => {
  it("nunca enseña el mensaje técnico del error", () => {
    expect(renderError()).not.toContain("ECONNREFUSED");
  });

  it("da la referencia para cruzar con el log, si la hay", () => {
    expect(renderError("1234567")).toContain("1234567");
    expect(renderError()).not.toContain("Referencia");
  });

  it("se ve bien sin hojas de estilo: estilos en línea y sin clases", () => {
    const html = renderError();
    expect(html).toMatch(/<body style="/);
    expect(html).not.toContain("class=");
  });

  it("va en el idioma del sitio, con su título", () => {
    const html = renderError();
    expect(html).toContain('<html lang="es"');
    expect(html).toContain("<title>No pudimos cargar la página</title>");
  });
});

describe("404 de último recurso", () => {
  it("va en el idioma del sitio y sin hojas de estilo", () => {
    const html = renderToStaticMarkup(<GlobalNotFound />);
    expect(html).toContain('<html lang="es"');
    expect(html).toContain("<title>Página no encontrada</title>");
    expect(html).not.toContain("Page not found");
    expect(html).not.toContain("class=");
  });
});

describe("lastResortCopy", () => {
  it("un idioma sin traducción cae al canónico, con su `lang` coherente", () => {
    expect(lastResortCopy("fr").lang).toBe("es");
    expect(lastResortCopy("en").lang).toBe("en");
    expect(lastResortCopy("en").home).toBe("Go to the home page");
  });
});
