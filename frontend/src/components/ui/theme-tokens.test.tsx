import { beforeEach, describe, expect, it, vi } from "vitest";
import type { ReactElement } from "react";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import { getSiteConfig } from "@/lib/config";
import { logger } from "@/lib/observability/logger";
import { ThemeTokens } from "./theme-tokens";

// ============================================================================
//  ThemeTokens: inyecta los tokens de diseño en un <style>. Es el punto donde
//  un valor hostil del Control Center se volvería HTML, así que se prueba la
//  salida real del componente y que el rechazo deja rastro.
// ============================================================================

vi.mock("@/lib/config", () => ({ getSiteConfig: vi.fn() }));
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: vi.fn(), error: vi.fn(), info: vi.fn() },
}));

const PAYLOAD = "red; } </style><script>alert(1)</script>";

function html(el: unknown): string {
  return (el as ReactElement<{ dangerouslySetInnerHTML: { __html: string } }>).props
    .dangerouslySetInnerHTML.__html;
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("ThemeTokens", () => {
  it("con la configuración por defecto inyecta :root con los tokens", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue(CONFIG_DEFAULTS as never);

    const out = html(await ThemeTokens());

    expect(out).toMatch(/^:root \{/);
    expect(out).toContain("--color-brand: #111111");
    expect(logger.warn).not.toHaveBeenCalled();
  });

  it("un valor hostil no puede cerrar el <style>: cae al valor por defecto y se registra", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue({
      ...CONFIG_DEFAULTS,
      design: {
        ...CONFIG_DEFAULTS.design,
        colors: { ...CONFIG_DEFAULTS.design.colors, brand: PAYLOAD },
      },
    } as never);

    const out = html(await ThemeTokens());

    expect(out).not.toMatch(/[<>]/);
    expect(out).toContain("--color-brand: #111111");
    expect(logger.warn).toHaveBeenCalledWith(
      expect.objectContaining({
        event: "design.token_invalid",
        cssVar: "--color-brand",
        kind: "color",
      }),
      expect.any(String),
    );
  });

  it("el mismo valor inválido se registra una sola vez por proceso, no en cada render", async () => {
    vi.mocked(getSiteConfig).mockResolvedValue({
      ...CONFIG_DEFAULTS,
      design: {
        ...CONFIG_DEFAULTS.design,
        colors: { ...CONFIG_DEFAULTS.design.colors, accent: "repetido-no-valido" },
      },
    } as never);

    await ThemeTokens();
    await ThemeTokens();
    await ThemeTokens();

    expect(logger.warn).toHaveBeenCalledTimes(1);
  });
});
