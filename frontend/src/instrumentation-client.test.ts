import { describe, expect, it, vi } from "vitest";

const sentryFactory = vi.hoisted(() => vi.fn());

vi.mock("@sentry/nextjs", () => {
  sentryFactory();
  return {
    init: vi.fn(),
    replayIntegration: vi.fn(),
    captureException: vi.fn(),
    captureRouterTransitionStart: vi.fn(),
  };
});

describe("instrumentation-client", () => {
  it("exporta la instrumentación de navegación y sin DSN no importa Sentry", async () => {
    vi.stubEnv("NEXT_PUBLIC_SENTRY_DSN", "");
    const before = sentryFactory.mock.calls.length;

    const instrumentation = await import("@/instrumentation-client");

    expect(typeof instrumentation.onRouterTransitionStart).toBe("function");
    expect(sentryFactory).toHaveBeenCalledTimes(before);

    instrumentation.onRouterTransitionStart("/", "push");
    expect(sentryFactory).toHaveBeenCalledTimes(before);

    vi.unstubAllEnvs();
  });
});
