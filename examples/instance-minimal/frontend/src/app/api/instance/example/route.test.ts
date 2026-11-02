import { beforeEach, describe, expect, it, vi } from "vitest";
import { getSession } from "@/lib/auth/session";
import { getSiteConfig } from "@/lib/config";
import type { SiteConfig } from "@/lib/config/types";
import { GET } from "./route";

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/auth/session", () => ({ getSession: vi.fn() }));

beforeEach(() => {
  vi.mocked(getSession).mockResolvedValue(null);
});

describe("GET /api/instance/example", () => {
  it("devuelve el saludo del Control Center", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue({
      instance: { example: { greeting: "Buenas" } },
    } as unknown as SiteConfig);

    const res = await GET(
      new Request("https://tienda.example/api/instance/example"),
    );

    expect(res.headers.get("Cache-Control")).toBe("no-store");
    expect(await res.json()).toEqual({ greeting: "Buenas", signedIn: false });
  });

  it("sin el campo (WordPress caído) cae al saludo por defecto", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue({} as SiteConfig);
    const res = await GET(
      new Request("https://tienda.example/api/instance/example"),
    );
    expect((await res.json()).greeting).toBe("Hola");
  });
});
