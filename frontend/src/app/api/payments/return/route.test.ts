import { beforeEach, describe, expect, it, vi } from "vitest";
import { getOrder } from "@/lib/payments/orders";
import { getSession } from "@/lib/auth/session";
import { readGuestOrderCredential } from "@/lib/checkout/guest-order-cookie";
import { GET } from "./route";

// ============================================================================
//  GET /api/payments/return: estado del pedido tras el pago (solo UX).
//  Lo consulta quien lo creó: el cliente con sesión o el invitado con la
//  credencial de SU pedido. Nunca el de otra persona.
// ============================================================================

vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));
vi.mock("@/lib/checkout/guest-order-cookie", () => ({
  readGuestOrderCredential: vi.fn(),
}));
vi.mock("@/lib/payments/orders", async (importOriginal) => ({
  ...(await importOriginal<typeof import("@/lib/payments/orders")>()),
  getOrder: vi.fn(),
}));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const ORDER = {
  id: 42,
  status: "processing",
  total: "59.99",
  currency: "EUR",
  customer_id: 7,
  order_key: "wc_order_AbC123",
};

const request = (ref = "42") =>
  new Request(`https://tienda.example/api/payments/return?ref=${ref}`);

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getSession).mockResolvedValue({ userId: "7", token: "jwt" } as never);
  vi.mocked(readGuestOrderCredential).mockResolvedValue(null);
  vi.mocked(getOrder).mockResolvedValue(ORDER as never);
});

describe("GET /api/payments/return", () => {
  it("el dueño con sesión ve el estado y si está pagado", async () => {
    const res = await GET(request());

    expect(res.status).toBe(200);
    expect(await res.json()).toMatchObject({ reference: "42", paid: true });
  });

  it("sin sesión ni credencial: 401", async () => {
    vi.mocked(getSession).mockResolvedValue(null);

    expect((await GET(request())).status).toBe(401);
  });

  it("un invitado ve SU pedido con la credencial", async () => {
    vi.mocked(getSession).mockResolvedValue(null);
    vi.mocked(getOrder).mockResolvedValue({ ...ORDER, customer_id: 0 } as never);
    vi.mocked(readGuestOrderCredential).mockResolvedValue({
      orderId: 42,
      orderKey: "wc_order_AbC123",
    });

    expect((await GET(request())).status).toBe(200);
  });

  it("la credencial con otra clave no abre el pedido (404)", async () => {
    vi.mocked(getSession).mockResolvedValue(null);
    vi.mocked(getOrder).mockResolvedValue({ ...ORDER, customer_id: 0 } as never);
    vi.mocked(readGuestOrderCredential).mockResolvedValue({
      orderId: 42,
      orderKey: "wc_order_OtraClave",
    });

    expect((await GET(request())).status).toBe(404);
  });

  it("el pedido de otro cliente es un 404", async () => {
    vi.mocked(getOrder).mockResolvedValue({ ...ORDER, customer_id: 8 } as never);

    expect((await GET(request())).status).toBe(404);
  });

  it("una referencia que no es un número es un 400", async () => {
    expect((await GET(request("abc"))).status).toBe(400);
  });
});
