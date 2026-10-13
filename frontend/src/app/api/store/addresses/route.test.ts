import { beforeEach, describe, expect, it, vi } from "vitest";
import { wcFetch } from "@/lib/woocommerce/client";
import { getGeoPack, GENERIC_PACK } from "@/lib/geo";
import { CO_PACK } from "../../../../../tests/helpers/geo-pack";
import { pushCartDestination } from "@/lib/woocommerce/cart-destination";
import { writeCartToken } from "@/lib/woocommerce/cart-cookie";
import { POST, PUT, DELETE } from "./route";

// La libreta de direcciones valida contra el paquete de país de la tienda: el
// departamento se guarda como código, y uno que no existe o no corresponde a la
// ciudad se rechaza (nunca se rellena uno por defecto).

vi.mock("@/lib/api/guard", () => ({ guardMutation: vi.fn(async () => null) }));
vi.mock("@/lib/auth/session", () => ({
  requireSession: vi.fn(async () => ({ token: "t", userId: "7" })),
}));
vi.mock("@/lib/woocommerce/client", () => ({ wcFetch: vi.fn() }));
vi.mock("@/lib/security/lock", () => ({
  withLock: async (_key: string, fn: () => Promise<unknown>) => fn(),
}));
vi.mock("@/lib/woocommerce/cart-destination", () => ({ pushCartDestination: vi.fn() }));
vi.mock("@/lib/woocommerce/cart-cookie", () => ({
  writeCartToken: vi.fn(async () => undefined),
}));
vi.mock("@/lib/woocommerce/cart-session", () => ({
  getCartRequestAuth: vi.fn(async () => ({
    cartToken: "ct",
    authToken: "jwt",
    userId: "7",
  })),
}));
vi.mock("@/lib/geo", async () => ({
  getGeoPack: vi.fn(),
  GENERIC_PACK: (await import("@/lib/geo/generic")).GENERIC_PACK,
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const ADDRESS = { address_1: "Calle 1 # 2-3", city: "Pasto" };

function req(method: "POST" | "PUT", body: unknown) {
  return new Request("https://tienda.example/api/store/addresses", {
    method,
    headers: { "content-type": "application/json" },
    body: JSON.stringify(body),
  });
}

/** Lo que se guardó en la meta de la libreta del cliente. */
function saved(): Array<Record<string, unknown>> {
  const put = vi.mocked(wcFetch).mock.calls.find(([, init]) => init?.method === "PUT");
  const meta = (put?.[1] as { body: { meta_data: Array<{ value: string }> } }).body
    .meta_data;
  return JSON.parse(meta[0].value);
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getGeoPack).mockResolvedValue(CO_PACK);
  vi.mocked(wcFetch).mockResolvedValue({ meta_data: [] } as never);
  vi.mocked(pushCartDestination).mockResolvedValue(null);
});

describe("POST /api/store/addresses", () => {
  it("guarda el departamento como código y el país de la tienda por defecto", async () => {
    const res = await POST(req("POST", { ...ADDRESS, state: "Nariño" }));

    expect(res.status).toBe(201);
    expect(saved()[0]).toMatchObject({
      state: "CO-NAR",
      country: "CO",
      is_default: true,
    });
  });

  it("sin departamento válido responde 422 por campo y no guarda nada", async () => {
    const res = await POST(req("POST", ADDRESS));

    expect(res.status).toBe(422);
    expect((await res.json()).details.state).toEqual(["Departamento requerido"]);
    expect(wcFetch).not.toHaveBeenCalled();
  });

  it("con el paquete genérico exige el país y deja el estado libre", async () => {
    vi.mocked(getGeoPack).mockResolvedValue(GENERIC_PACK);

    expect((await POST(req("POST", ADDRESS))).status).toBe(422);
    const res = await POST(req("POST", { ...ADDRESS, country: "PE", state: "Lima" }));

    expect(res.status).toBe(201);
    expect(saved()[0]).toMatchObject({ state: "Lima", country: "PE" });
  });
});

describe("PUT /api/store/addresses", () => {
  it("la actualización pasa por el mismo paquete", async () => {
    vi.mocked(wcFetch).mockResolvedValue({
      meta_data: [
        {
          key: "hwe_addresses",
          value: JSON.stringify([{ ...ADDRESS, state: "CO-NAR" }]),
        },
      ],
    } as never);

    const bad = await PUT(
      req("PUT", {
        index: 0,
        address: { ...ADDRESS, city: "Bogotá", state: "Antioquia" },
      }),
    );
    expect(bad.status).toBe(422);

    const ok = await PUT(
      req("PUT", { index: 0, address: { ...ADDRESS, city: "Bogotá" } }),
    );
    expect(ok.status).toBe(200);
    expect(saved()[0]).toMatchObject({ city: "Bogotá", state: "CO-DC" });
  });
});

describe("destino de envío del carrito tras cambiar la libreta (módulo shipping)", () => {
  const saved = [{ ...ADDRESS, state: "CO-NAR", country: "CO", is_default: true }];
  const withBook = () =>
    vi.mocked(wcFetch).mockResolvedValue({
      meta_data: [{ key: "hwe_addresses", value: JSON.stringify(saved) }],
    } as never);

  it("al crear una dirección empuja el destino con la libreta resultante", async () => {
    vi.mocked(pushCartDestination).mockResolvedValue({
      data: {},
      cartToken: "nuevo",
    } as never);

    const res = await POST(req("POST", { ...ADDRESS, state: "Nariño" }));

    expect(res.status).toBe(201);
    const [userId, book, auth] = vi.mocked(pushCartDestination).mock.calls[0];
    expect(userId).toBe("7");
    expect(book).toHaveLength(1);
    expect(auth).toEqual({ cartToken: "ct", authToken: "jwt" });
    expect(writeCartToken).toHaveBeenCalledWith("nuevo");
  });

  it("al actualizar y al borrar también", async () => {
    withBook();
    await PUT(req("PUT", { index: 0, address: { ...ADDRESS, state: "CO-NAR" } }));
    await DELETE(req("PUT", { index: 0 }));

    expect(pushCartDestination).toHaveBeenCalledTimes(2);
  });

  it("sin cambio de destino (módulo apagado) no toca la cookie del carrito", async () => {
    vi.mocked(pushCartDestination).mockResolvedValue(null);

    await POST(req("POST", { ...ADDRESS, state: "Nariño" }));

    expect(writeCartToken).not.toHaveBeenCalled();
  });
});
