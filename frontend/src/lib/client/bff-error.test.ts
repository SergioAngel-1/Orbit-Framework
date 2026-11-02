// @vitest-environment jsdom
import { describe, expect, it } from "vitest";
import { BffError, readBff } from "./bff-error";

// ============================================================================
//  readBff: un error del BFF conserva TODO su cuerpo, no solo `error`/`details`/`code`.
//  Una ruta que explica el fallo con campos propios (`reason`, el estado de un pedido…)
//  no debe obligar a la vista a volver a leer la respuesta.
// ============================================================================

const json = (status: number, body: unknown) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });

describe("readBff", () => {
  it("conserva los campos extra del cuerpo en `body`", async () => {
    const error = await readBff(
      json(409, {
        error: "El pedido ya no admite pago.",
        reason: "expired",
        status: "cancelled",
      }),
      "Reserva",
    ).catch((e: unknown) => e);

    expect(error).toBeInstanceOf(BffError);
    const bff = error as BffError;
    expect(bff.status).toBe(409);
    expect(bff.message).toBe("El pedido ya no admite pago.");
    expect(bff.body).toEqual({
      error: "El pedido ya no admite pago.",
      reason: "expired",
      status: "cancelled",
    });
  });

  it("sin cuerpo JSON, `body` es un objeto vacío y manda el texto de reserva", async () => {
    const error = (await readBff(
      new Response("oops", { status: 502 }),
      "Reserva",
    ).catch((e: unknown) => e)) as BffError;
    expect(error.message).toBe("Reserva");
    expect(error.body).toEqual({});
  });

  it("un BffError construido a mano (firma anterior) sigue valiendo", () => {
    const error = new BffError("x", 422, { a: ["y"] }, "code");
    expect(error.field("a")).toBe("y");
    expect(error.body).toEqual({});
  });
});
