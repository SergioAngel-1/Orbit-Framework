// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NextIntlClientProvider } from "next-intl";
import es from "@/i18n/messages/es.json";
import { TwoFactorSetup } from "./two-factor-setup";

// ============================================================================
//  Alta de 2FA en el navegador.
//
//  Lo que importa del lado cliente: el secreto TOTP no sale del dominio. El QR
//  lo sirve nuestro BFF (`/api/auth/2fa/qr`, por sesión), así que ninguna URL de
//  la página lleva el secreto —ni hay terceros—, y si el QR no carga el usuario
//  puede seguir con el código manual.
// ============================================================================

const SECRET = "JBSWY3DPEHPK3PXP";
const t = es.twoFactor;

vi.mock("@/lib/client/csrf", () => ({
  csrfFetch: (url: string, init: { method: string; body?: unknown }) =>
    fetch(url, { method: init.method, body: JSON.stringify(init.body ?? null) }),
}));

type Handler = (url: string, init?: RequestInit) => Response | Promise<Response>;
let calls: Array<{ url: string; method: string; body: unknown }>;

function stubApi(overrides: Record<string, Handler> = {}) {
  const handlers: Record<string, Handler> = {
    "/api/auth/2fa/status": () => Response.json({ enabled: false }),
    "/api/auth/2fa/setup": () =>
      Response.json({ secret: SECRET, site_name: "Mi Tienda" }),
    "/api/auth/2fa/activate": () =>
      Response.json({ ok: true, recovery_codes: ["AAAA-1111", "BBBB-2222"] }),
    ...overrides,
  };
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      calls.push({
        url,
        method: init?.method ?? "GET",
        body: typeof init?.body === "string" ? JSON.parse(init.body) : null,
      });
      const handler = handlers[url];
      return handler ? handler(url, init) : new Response("not found", { status: 404 });
    }) as unknown as typeof fetch,
  );
}

function renderSetup(props: { emailVerified?: boolean } = { emailVerified: true }) {
  return render(
    <NextIntlClientProvider locale="es" messages={es}>
      <TwoFactorSetup {...props} />
    </NextIntlClientProvider>,
  );
}

async function startSetup() {
  fireEvent.click(await screen.findByRole("button", { name: t.enable }));
  return screen.findByAltText(t.qrcode);
}

beforeEach(() => {
  calls = [];
  stubApi();
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("QR del alta de 2FA", () => {
  it("apunta a nuestro endpoint, sin secreto en la URL", async () => {
    renderSetup();

    const qr = await startSetup();

    expect(qr.getAttribute("src")).toBe("/api/auth/2fa/qr");
  });

  it("ningún atributo de la página lleva el secreto ni apunta a un tercero", async () => {
    const { container } = renderSetup();
    await startSetup();

    const urls = [...container.querySelectorAll("[src],[href]")].map(
      (el) => el.getAttribute("src") ?? el.getAttribute("href") ?? "",
    );

    for (const url of urls) {
      expect(url).not.toContain(SECRET);
      expect(url).not.toMatch(/^https?:\/\//);
      expect(url).not.toContain("qrserver");
    }
    // El secreto SÍ se muestra como texto, para el alta manual.
    expect(container.textContent).toContain(SECRET);
  });

  it("si el QR no carga, avisa y deja el código manual para continuar", async () => {
    const { container } = renderSetup();
    const qr = await startSetup();

    fireEvent.error(qr);

    expect(await screen.findByText(t.qrLoadError)).toBeTruthy();
    expect(screen.queryByAltText(t.qrcode)).toBeNull();
    expect(container.textContent).toContain(SECRET);
    // Y se puede seguir: el campo del código sigue ahí.
    expect(screen.getByPlaceholderText("000000")).toBeTruthy();
  });
});

describe("requisito de email verificado", () => {
  it("sin email verificado no se llama al BFF y se explica por qué", async () => {
    renderSetup({ emailVerified: false });

    fireEvent.click(await screen.findByRole("button", { name: t.enable }));

    expect(await screen.findByText(t.emailRequired)).toBeTruthy();
    expect(calls.some((c) => c.url === "/api/auth/2fa/setup")).toBe(false);
  });

  it("si el BFF rechaza el alta (403), se muestra su mensaje", async () => {
    stubApi({
      "/api/auth/2fa/setup": () =>
        Response.json(
          { error: "Verifica tu email antes de activar 2FA." },
          { status: 403 },
        ),
    });
    renderSetup();

    fireEvent.click(await screen.findByRole("button", { name: t.enable }));

    expect(
      await screen.findByText("Verifica tu email antes de activar 2FA."),
    ).toBeTruthy();
  });
});

describe("activación", () => {
  it("envía secreto y código y muestra los códigos de recuperación", async () => {
    renderSetup();
    await startSetup();

    fireEvent.change(screen.getByPlaceholderText("000000"), {
      target: { value: "123456" },
    });
    fireEvent.click(screen.getByRole("button", { name: t.verify }));

    expect(await screen.findByText("AAAA-1111")).toBeTruthy();
    const activate = calls.find((c) => c.url === "/api/auth/2fa/activate");
    expect(activate?.body).toEqual({ secret: SECRET, code: "123456" });
    await waitFor(() => expect(screen.queryByAltText(t.qrcode)).toBeNull());
  });
});
