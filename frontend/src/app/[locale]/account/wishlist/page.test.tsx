import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSiteConfig } from "@/lib/config";
import WishlistPage from "./page";

// ============================================================================
//  Vista «Lista de deseos»: es de `ecommerce.wishlist_enabled`. Apagada, no
//  existe (404) y no se consulta al cliente.
// ============================================================================

vi.mock("next-intl/server", () => ({
  getTranslations: vi.fn(async () => (key: string) => key),
}));
vi.mock("next/navigation", () => ({
  notFound: vi.fn(() => {
    throw new Error("NEXT_NOT_FOUND");
  }),
}));
vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
const requireSession = vi.fn();
vi.mock("@/lib/auth/session", () => ({
  requireSession: (...args: unknown[]) => requireSession(...args),
}));
vi.mock("@/lib/woocommerce/client", () => ({
  wcFetch: vi.fn(async () => ({ meta_data: [] })),
}));
vi.mock("./wishlist-page-client", () => ({ WishlistPageClient: () => null }));

beforeEach(() => {
  vi.clearAllMocks();
  requireSession.mockResolvedValue({ userId: "7" });
});

describe("WishlistPage", () => {
  it("apagada responde 404 sin leer la sesión", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue({
      ecommerce: { wishlist_enabled: false },
    } as never);

    await expect(WishlistPage()).rejects.toThrow("NEXT_NOT_FOUND");
    expect(requireSession).not.toHaveBeenCalled();
  });

  it("encendida pinta la vista", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue({
      ecommerce: { wishlist_enabled: true },
    } as never);

    await expect(WishlistPage()).resolves.toBeTruthy();
  });
});
