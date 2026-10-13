import { describe, it, expect } from "vitest";
import {
  staticGenerationOptions,
  staticPageGenerationTimeout,
} from "../../scripts/static-generation-options.mjs";

// Un build en un servidor pequeño comparte máquina con WordPress, MariaDB y
// Redis: prerenderizar a la concurrencia por defecto satura Apache, así que ese
// build —y solo ese— baja el ritmo por variable de entorno.
//
// Lo que estas pruebas fijan es que SIN las variables la config queda INTACTA:
// el CI construye contra un stub y no debe pagar la throttle. Un `"4"` string o
// un `NaN` colándose en `experimental` cambiaría el build en silencio.
describe("staticGenerationOptions", () => {
  it("sin variables no añade ninguna opción", () => {
    expect(staticGenerationOptions({})).toEqual({});
  });

  it("convierte las variables presentes en números", () => {
    expect(
      staticGenerationOptions({
        NEXT_STATIC_GENERATION_MAX_CONCURRENCY: "2",
        NEXT_STATIC_GENERATION_MIN_PAGES_PER_WORKER: "400",
      }),
    ).toEqual({
      staticGenerationMaxConcurrency: 2,
      staticGenerationMinPagesPerWorker: 400,
    });
  });

  it("ignora un valor no numérico en vez de colar NaN en la config", () => {
    expect(
      staticGenerationOptions({ NEXT_STATIC_GENERATION_MAX_CONCURRENCY: "dos" }),
    ).toEqual({});
  });

  it("ignora un valor cero o negativo", () => {
    expect(
      staticGenerationOptions({
        NEXT_STATIC_GENERATION_MAX_CONCURRENCY: "0",
        NEXT_STATIC_GENERATION_MIN_PAGES_PER_WORKER: "-5",
      }),
    ).toEqual({});
  });

  it("cada variable es independiente de la otra", () => {
    expect(
      staticGenerationOptions({ NEXT_STATIC_GENERATION_MIN_PAGES_PER_WORKER: "400" }),
    ).toEqual({ staticGenerationMinPagesPerWorker: 400 });
  });
});

describe("staticPageGenerationTimeout", () => {
  it("sin variable deja el default de Next (undefined, no 0)", () => {
    expect(staticPageGenerationTimeout({})).toBeUndefined();
  });

  it("un entero positivo es el plazo, en segundos", () => {
    expect(
      staticPageGenerationTimeout({ NEXT_STATIC_PAGE_GENERATION_TIMEOUT: "180" }),
    ).toBe(180);
  });

  it("un valor inservible vuelve al default en vez de colar NaN o 0", () => {
    for (const malo of ["", "mucho", "0", "-1", "1.5"]) {
      expect(
        staticPageGenerationTimeout({ NEXT_STATIC_PAGE_GENERATION_TIMEOUT: malo }),
      ).toBeUndefined();
    }
  });
});
