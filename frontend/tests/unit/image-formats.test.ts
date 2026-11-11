import { describe, expect, it, vi } from "vitest";
import { DEFAULT_IMAGE_FORMATS, imageFormats } from "../../scripts/image-formats.mjs";

// `images.formats` decide qué codifica `/_next/image` según el `Accept` del
// navegador. AVIF ahorra ~1/3 de bytes pero cuesta varias veces la CPU de WebP
// (medido con sharp y 2 núcleos: ≈5x), así que un despliegue pequeño tiene que
// poder elegir. Lo que estas pruebas fijan es que SIN la variable la config
// queda exactamente como estaba, y que un valor roto nunca llega a Next (que
// rechazaría la config entera o serviría sin optimizar).
describe("imageFormats", () => {
  it("sin variable devuelve el defecto de siempre: AVIF primero, WebP de respaldo", () => {
    expect(DEFAULT_IMAGE_FORMATS).toEqual(["image/avif", "image/webp"]);
    expect(imageFormats({})).toEqual(["image/avif", "image/webp"]);
  });

  it("una variable vacía o en blanco cuenta como ausente, sin aviso", () => {
    const warn = vi.fn();
    expect(imageFormats({ NEXT_IMAGE_FORMATS: "" }, warn)).toEqual(
      DEFAULT_IMAGE_FORMATS,
    );
    expect(imageFormats({ NEXT_IMAGE_FORMATS: "  " }, warn)).toEqual(
      DEFAULT_IMAGE_FORMATS,
    );
    expect(warn).not.toHaveBeenCalled();
  });

  it("webp solo: sirve WebP aunque el navegador acepte AVIF", () => {
    expect(imageFormats({ NEXT_IMAGE_FORMATS: "webp" })).toEqual(["image/webp"]);
  });

  it("acepta el nombre corto y el tipo MIME, sin importar mayúsculas ni espacios", () => {
    expect(imageFormats({ NEXT_IMAGE_FORMATS: " WebP , image/AVIF " })).toEqual([
      "image/webp",
      "image/avif",
    ]);
  });

  it("respeta el orden dado y quita repetidos", () => {
    expect(imageFormats({ NEXT_IMAGE_FORMATS: "webp,avif,image/webp" })).toEqual([
      "image/webp",
      "image/avif",
    ]);
  });

  it("ignora los valores no válidos con aviso y conserva los válidos", () => {
    const warn = vi.fn();

    expect(imageFormats({ NEXT_IMAGE_FORMATS: "webp,gif,jpeg" }, warn)).toEqual([
      "image/webp",
    ]);
    expect(warn).toHaveBeenCalledTimes(1);
    expect(warn.mock.calls[0][0]).toContain("NEXT_IMAGE_FORMATS");
    expect(warn.mock.calls[0][0]).toContain("gif");
    expect(warn.mock.calls[0][0]).toContain("jpeg");
  });

  it("si no queda ningún valor válido, vuelve al defecto con aviso", () => {
    const warn = vi.fn();

    expect(imageFormats({ NEXT_IMAGE_FORMATS: "gif,,png" }, warn)).toEqual(
      DEFAULT_IMAGE_FORMATS,
    );
    expect(warn).toHaveBeenCalledTimes(1);
  });

  it("devuelve una copia: mutar el resultado no altera el defecto", () => {
    imageFormats({}).push("image/webp");

    expect(DEFAULT_IMAGE_FORMATS).toEqual(["image/avif", "image/webp"]);
  });
});
