import { beforeEach, describe, expect, it, vi } from "vitest";
import type { ReactElement, ReactNode } from "react";
import { getSiteConfig } from "@/lib/config";
import { getOrderById } from "@/lib/account/data";
import OrderDetailPage from "./page";

// ============================================================================
//  Vista de pedido: qué acciones ofrece según el estado y los módulos.
//  - «Calificar pedido» es del módulo `site-reviews`: apagado, no existe.
//  - «Recibí mi pedido» sirve a la calificación y a la reseña de producto.
//  - «Pagar ahora» solo en un pedido de pago en línea sin pagar.
// ============================================================================

vi.mock("next-intl/server", () => ({
  getTranslations: vi.fn(async () => (key: string) => key),
}));
vi.mock("next/navigation", () => ({
  notFound: vi.fn(() => {
    throw new Error("NEXT_NOT_FOUND");
  }),
}));
vi.mock("next/image", () => ({ default: () => null }));
vi.mock("@/i18n/navigation", () => ({ Link: () => null }));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/account/data", () => ({
  getOrderById: vi.fn(),
  getCustomer: vi.fn(async () => ({ email: "ana@example.com" })),
}));
vi.mock("@/lib/reviews/publish-product-reviews", () => ({
  reviewedProductsForOrders: vi.fn(async () => new Set<number>()),
}));
vi.mock("@/components/account/order-actions", () => ({ OrderActions: () => null }));
vi.mock("@/components/account/order-status-badge", () => ({
  OrderStatusBadge: () => null,
}));
vi.mock("@/components/account/order-support-link", () => ({
  OrderSupportLink: () => null,
}));
vi.mock("@/components/checkout/pay-order-button", () => ({
  PayOrderButton: () => null,
}));

type El = ReactElement<{ children?: ReactNode } & Record<string, unknown>>;

/** Elementos del árbol cuyo tipo es una función con ese nombre. */
function findAll(node: ReactNode, name: string, out: El[] = []): El[] {
  if (!node || typeof node !== "object") return out;
  if (Array.isArray(node)) {
    node.forEach((n) => findAll(n, name, out));
    return out;
  }
  const el = node as El;
  if (typeof el.type === "function" && el.type.name === name) out.push(el);
  findAll(el.props?.children, name, out);
  return out;
}

/** Texto que la vista pinta (solo los hijos: las props llevan datos que no se ven). */
function visibleText(node: ReactNode, out: string[] = []): string {
  if (typeof node === "string" || typeof node === "number") out.push(String(node));
  else if (Array.isArray(node)) node.forEach((n) => visibleText(n, out));
  else if (node && typeof node === "object") {
    visibleText((node as El).props?.children, out);
  }
  return out.join(" ");
}

const order = (overrides: Record<string, unknown> = {}) => ({
  id: 10,
  number: "10",
  status: "completed",
  currency: "EUR",
  total: "50.00",
  date_created: "2026-09-30T10:00:00",
  payment_method: "cod",
  billing: {},
  shipping: {},
  meta_data: [],
  line_items: [{ id: 1, name: "Camiseta", product_id: 3, quantity: 1, price: 25 }],
  ...overrides,
});

function config(opts: {
  siteReviews?: boolean;
  productReviews?: boolean;
  online?: boolean;
  cash?: boolean;
}) {
  vi.mocked(getSiteConfig).mockResolvedValue({
    modules: { "site-reviews": opts.siteReviews ?? false },
    ecommerce: { reviews_enabled: opts.productReviews ?? false, currency: "EUR" },
    payments: {
      gateway_enabled: opts.online ?? true,
      whatsapp_enabled: false,
      cash_enabled: opts.cash ?? false,
    },
    social: { whatsapp: "" },
  } as never);
}

const render = (id = "10") =>
  OrderDetailPage({ params: Promise.resolve({ locale: "es", id }) });

async function actionsProps() {
  const tree = await render();
  const [actions] = findAll(tree as ReactNode, "OrderActions");
  return actions!.props as { review: { mode: string; productCount: number } | null };
}

beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(getOrderById).mockResolvedValue(order() as never);
});

describe("calificar el pedido (módulo site-reviews)", () => {
  it("con el módulo encendido, un pedido completado ofrece calificar", async () => {
    config({ siteReviews: true });

    expect((await actionsProps()).review).toMatchObject({ mode: "rate" });
  });

  it("con el módulo apagado no ofrece calificar", async () => {
    config({ siteReviews: false, productReviews: true });

    expect((await actionsProps()).review).toBeNull();
  });

  it("con el módulo encendido enseña la calificación ya hecha", async () => {
    config({ siteReviews: true });
    vi.mocked(getOrderById).mockResolvedValue(
      order({
        meta_data: [
          {
            key: "_hwe_site_review",
            value: { rating: 4, content: "Muy buena.", date: "" },
          },
        ],
      }) as never,
    );

    expect(visibleText((await render()) as ReactNode)).toContain("Muy buena.");
  });

  it("con el módulo apagado tampoco enseña una calificación anterior", async () => {
    config({ siteReviews: false });
    vi.mocked(getOrderById).mockResolvedValue(
      order({
        meta_data: [
          {
            key: "_hwe_site_review",
            value: { rating: 4, content: "Muy buena.", date: "" },
          },
        ],
      }) as never,
    );

    expect(visibleText((await render()) as ReactNode)).not.toContain("Muy buena.");
  });
});

describe("«Recibí mi pedido»", () => {
  it("se ofrece si algo lo aprovecha (módulo o reseñas de producto)", async () => {
    vi.mocked(getOrderById).mockResolvedValue(order({ status: "processing" }) as never);

    config({ siteReviews: false, productReviews: true });
    expect((await actionsProps()).review).toMatchObject({ mode: "receive" });

    config({ siteReviews: true, productReviews: false });
    expect((await actionsProps()).review).toMatchObject({ mode: "receive" });
  });

  it("sin módulo ni reseñas de producto no sirve para nada: no se ofrece", async () => {
    vi.mocked(getOrderById).mockResolvedValue(order({ status: "processing" }) as never);
    config({ siteReviews: false, productReviews: false });

    expect((await actionsProps()).review).toBeNull();
  });
});

describe("«Pagar ahora»", () => {
  it("un pedido en espera de pago en línea lo ofrece", async () => {
    vi.mocked(getOrderById).mockResolvedValue(
      order({ status: "on-hold", payment_method: "hwe_offline" }) as never,
    );
    config({});

    expect(findAll((await render()) as ReactNode, "PayOrderButton")).toHaveLength(1);
  });

  it.each([
    ["uno pagado", { status: "processing", payment_method: "hwe_offline" }],
    ["uno de efectivo", { status: "on-hold", payment_method: "cod" }],
  ])("%s no lo ofrece", async (_name, overrides) => {
    vi.mocked(getOrderById).mockResolvedValue(order(overrides) as never);
    config({});

    expect(findAll((await render()) as ReactNode, "PayOrderButton")).toHaveLength(0);
  });

  it("con el pago en línea apagado en el panel, tampoco", async () => {
    vi.mocked(getOrderById).mockResolvedValue(
      order({ status: "on-hold", payment_method: "hwe_offline" }) as never,
    );
    // Con los tres medios apagados el checkout cae al pago en línea (no hay
    // checkout sin medio): se deja el efectivo para que «en línea» esté apagado de verdad.
    config({ online: false, cash: true });

    expect(findAll((await render()) as ReactNode, "PayOrderButton")).toHaveLength(0);
  });
});
