import { beforeEach, describe, expect, it, vi } from "vitest";

const wcFetch = vi.fn();
vi.mock("@/lib/woocommerce/client", () => ({
  wcFetch: (...a: unknown[]) => wcFetch(...a),
}));
const readGuestOrderCredential = vi.fn();
const forgetGuestOrder = vi.fn();
vi.mock("@/lib/checkout/guest-order-cookie", () => ({
  readGuestOrderCredential: () => readGuestOrderCredential(),
  forgetGuestOrder: () => forgetGuestOrder(),
}));

vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const { claimGuestOrder } = await import("./guest-order-claim");

const ORDER_KEY = "wc_order_AbCdEf123456";
const USER = 42;

function order(overrides: Record<string, unknown> = {}) {
  return {
    id: 1001,
    customer_id: 0,
    order_key: ORDER_KEY,
    billing: { email: "Ana@Example.com " },
    ...overrides,
  };
}

/** GET del pedido → GET del cliente → PUT del pedido. */
function wooResponds(o: ReturnType<typeof order>, customerEmail = "ana@example.com") {
  wcFetch.mockImplementation(async (path: string, options?: { method?: string }) => {
    if (path.startsWith("/customers/")) return { id: USER, email: customerEmail };
    if (options?.method === "PUT") return { ...o, customer_id: USER };
    return o;
  });
}

function putCalls() {
  return wcFetch.mock.calls.filter(([, options]) => options?.method === "PUT");
}

beforeEach(() => {
  wcFetch.mockReset();
  forgetGuestOrder.mockReset();
  readGuestOrderCredential.mockReset();
  readGuestOrderCredential.mockResolvedValue({ orderId: 1001, orderKey: ORDER_KEY });
});

describe("claimGuestOrder", () => {
  it("sin credencial de invitado no consulta WooCommerce", async () => {
    readGuestOrderCredential.mockResolvedValue(null);
    expect(await claimGuestOrder(USER)).toBe("no_credential");
    expect(wcFetch).not.toHaveBeenCalled();
  });

  it("liga el pedido a la cuenta si el correo coincide, y retira la cookie", async () => {
    wooResponds(order());
    expect(await claimGuestOrder(String(USER))).toBe("linked");
    expect(putCalls()).toHaveLength(1);
    expect(putCalls()[0]).toEqual([
      "/orders/1001",
      expect.objectContaining({ method: "PUT", body: { customer_id: USER } }),
    ]);
    expect(forgetGuestOrder).toHaveBeenCalledOnce();
  });

  it("no liga el pedido de otra persona: el correo tiene que ser el de la cuenta", async () => {
    wooResponds(order(), "otra@example.com");
    expect(await claimGuestOrder(USER)).toBe("email_mismatch");
    expect(putCalls()).toHaveLength(0);
    expect(forgetGuestOrder).not.toHaveBeenCalled();
  });

  it("un pedido que ya es de esta cuenta no se toca y la cookie se retira", async () => {
    wooResponds(order({ customer_id: USER }));
    expect(await claimGuestOrder(USER)).toBe("not_claimable");
    expect(putCalls()).toHaveLength(0);
    expect(forgetGuestOrder).toHaveBeenCalledOnce();
  });

  it("un pedido de OTRA cuenta no se toca ni se retira la cookie", async () => {
    wooResponds(order({ customer_id: 7 }));
    expect(await claimGuestOrder(USER)).toBe("not_claimable");
    expect(putCalls()).toHaveLength(0);
    expect(forgetGuestOrder).not.toHaveBeenCalled();
  });

  it("una clave de pedido que no casa no liga nada", async () => {
    wooResponds(order({ order_key: "wc_order_OtraClave9999" }));
    expect(await claimGuestOrder(USER)).toBe("not_claimable");
    expect(putCalls()).toHaveLength(0);
  });

  it("si WooCommerce falla, no lanza: el login sigue", async () => {
    wcFetch.mockRejectedValue(new Error("WP caído"));
    await expect(claimGuestOrder(USER)).resolves.toBe("error");
    expect(forgetGuestOrder).not.toHaveBeenCalled();
  });

  it("un id de usuario inválido no hace nada", async () => {
    expect(await claimGuestOrder(0)).toBe("no_credential");
    expect(readGuestOrderCredential).not.toHaveBeenCalled();
  });
});
