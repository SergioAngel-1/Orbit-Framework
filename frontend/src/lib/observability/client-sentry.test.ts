// @vitest-environment jsdom
import { describe, expect, it, vi } from "vitest";

// El SDK se mockea con una fábrica que solo se ejecuta si alguien lo importa:
// así se puede comprobar que, sin DSN, el módulo no llega a cargarlo.
const sentry = vi.hoisted(() => ({
  init: vi.fn(),
  replayIntegration: vi.fn(() => ({ name: "Replay" })),
  captureException: vi.fn(),
  captureRouterTransitionStart: vi.fn(),
}));

vi.mock("@sentry/nextjs", () => sentry);

import { createClientSentry, type SentrySdk } from "./client-sentry";

const DSN = "https://public@example.invalid/1";

const sdk = sentry as unknown as SentrySdk;

function deferred() {
  let run: (() => void) | undefined;
  return {
    defer: (callback: () => void) => {
      run = callback;
    },
    start: () => run?.(),
  };
}

describe("createClientSentry", () => {
  it("sin DSN no carga, no inicializa y las transiciones son no-op", async () => {
    const loadSdk = vi.fn();
    const { defer, start } = deferred();
    const client = createClientSentry({ dsn: null, defer, loadSdk });

    await client.ready;

    expect(client.enabled).toBe(false);
    expect(start).not.toThrow();
    expect(loadSdk).not.toHaveBeenCalled();
    expect(sentry.init).not.toHaveBeenCalled();

    client.onRouterTransitionStart("/productos", "push");
    expect(loadSdk).not.toHaveBeenCalled();
    expect(sentry.captureRouterTransitionStart).not.toHaveBeenCalled();
  });

  it("con DSN espera al programador antes de importar el SDK", async () => {
    const { defer, start } = deferred();
    const loadSdk = vi.fn(async () => sdk);
    const client = createClientSentry({ dsn: DSN, replay: false, defer, loadSdk });

    expect(client.enabled).toBe(true);
    expect(loadSdk).not.toHaveBeenCalled();

    start();
    await client.ready;

    expect(loadSdk).toHaveBeenCalledTimes(1);
    expect(sentry.init).toHaveBeenCalledWith(
      expect.objectContaining({ dsn: DSN, enabled: true }),
    );
    // Por defecto no se registra replay: ni la integración ni sus opciones.
    expect(sentry.replayIntegration).not.toHaveBeenCalled();
    expect(sentry.init.mock.calls[0][0]).not.toHaveProperty("integrations");
    expect(sentry.init.mock.calls[0][0]).not.toHaveProperty("replaysSessionSampleRate");
  });

  it("replay solo se registra con la opción explícita", async () => {
    const { defer, start } = deferred();
    const client = createClientSentry({
      dsn: DSN,
      replay: true,
      defer,
      loadSdk: async () => sdk,
    });

    start();
    await client.ready;

    expect(sentry.replayIntegration).toHaveBeenCalledTimes(1);
    expect(sentry.init).toHaveBeenCalledWith(
      expect.objectContaining({ integrations: [{ name: "Replay" }] }),
    );
  });

  it("la primera navegación adelanta la carga y la transición no se pierde", async () => {
    const { defer } = deferred();
    const loadSdk = vi.fn(async () => sdk);
    const client = createClientSentry({ dsn: DSN, defer, loadSdk });

    client.onRouterTransitionStart("/carrito", "traverse");
    await client.ready;

    expect(loadSdk).toHaveBeenCalledTimes(1);
    expect(sentry.captureRouterTransitionStart).toHaveBeenCalledWith(
      "/carrito",
      "traverse",
    );

    // Una vez cargado, las siguientes transiciones van directas al SDK.
    client.onRouterTransitionStart("/checkout", "push");
    expect(sentry.captureRouterTransitionStart).toHaveBeenCalledWith(
      "/checkout",
      "push",
    );
  });

  it("conserva los errores de la ventana previa a la carga", async () => {
    const { defer, start } = deferred();
    const client = createClientSentry({
      dsn: DSN,
      defer,
      loadSdk: async () => sdk,
    });

    const boom = new Error("boom antes de Sentry");
    window.dispatchEvent(new ErrorEvent("error", { error: boom }));
    const rejection = new Error("promesa rechazada");
    window.dispatchEvent(
      Object.assign(new Event("unhandledrejection"), { reason: rejection }),
    );

    start();
    await client.ready;

    expect(sentry.captureException).toHaveBeenCalledWith(boom);
    expect(sentry.captureException).toHaveBeenCalledWith(rejection);
  });

  it("si el SDK no carga, el arranque termina sin lanzar", async () => {
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    const { defer, start } = deferred();
    const client = createClientSentry({
      dsn: DSN,
      defer,
      loadSdk: async () => {
        throw new Error("sin red");
      },
    });

    start();
    await expect(client.ready).resolves.toBeUndefined();
    expect(warn).toHaveBeenCalled();

    warn.mockRestore();
  });

  it("sin programador explícito se carga sola tras la inactividad", async () => {
    const client = createClientSentry({ dsn: DSN, loadSdk: async () => sdk });

    await client.ready;

    expect(client.enabled).toBe(true);
    expect(sentry.init).toHaveBeenCalledWith(expect.objectContaining({ dsn: DSN }));
  });
});
