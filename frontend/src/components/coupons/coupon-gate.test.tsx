// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { CouponGate, GuestOnly } from "./coupon-window";
import { resetViewerCouponsCache } from "./use-viewer-coupons";

// El guardián de los avisos de cupón: la ventana de fechas se resuelve con el
// reloj del navegador y el historial del cliente con una sola petición.

const coupon = {
  code: "BIENVENIDA10",
  perUserLimited: true,
  startsAt: null,
  endsAt: null,
  activeAtRender: true,
};

function answer(codes: string[], authenticated = true) {
  const fetchMock = vi.fn(async () => ({
    ok: true,
    json: async () => ({ codes, authenticated }),
  }));
  vi.stubGlobal("fetch", fetchMock);
  return fetchMock;
}

describe("CouponGate", () => {
  beforeEach(() => resetViewerCouponsCache());
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it("oculta el aviso de un cupón que el cliente ya agotó", async () => {
    const fetchMock = answer(["bienvenida10"]);
    render(
      <CouponGate coupon={coupon}>
        <p>aviso</p>
      </CouponGate>,
    );
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    expect(screen.queryByText("aviso")).toBeNull();
  });

  it("enseña el aviso cuando el cliente aún puede usarlo, y no antes de saberlo", async () => {
    answer([]);
    render(
      <CouponGate coupon={coupon}>
        <p>aviso</p>
      </CouponGate>,
    );
    expect(screen.queryByText("aviso")).toBeNull();
    expect(await screen.findByText("aviso")).toBeTruthy();
  });

  it("varios avisos comparten una sola petición", async () => {
    const fetchMock = answer([]);
    render(
      <>
        <CouponGate coupon={coupon}>
          <p>uno</p>
        </CouponGate>
        <CouponGate coupon={{ ...coupon, code: "OTRO" }}>
          <p>dos</p>
        </CouponGate>
      </>,
    );
    await screen.findByText("uno");
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("un cupón que no depende del cliente se enseña sin preguntar", () => {
    const fetchMock = answer(["envio"]);
    render(
      <CouponGate coupon={{ ...coupon, code: "ENVIO", perUserLimited: false }}>
        <p>aviso</p>
      </CouponGate>,
    );
    expect(screen.getByText("aviso")).toBeTruthy();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("con `viewerKnown` no pregunta: el servidor ya filtró", () => {
    const fetchMock = answer(["bienvenida10"]);
    render(
      <CouponGate coupon={coupon} viewerKnown>
        <p>aviso</p>
      </CouponGate>,
    );
    expect(screen.getByText("aviso")).toBeTruthy();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("fuera de su ventana de fechas no se enseña", () => {
    answer([]);
    render(
      <CouponGate
        coupon={{
          ...coupon,
          perUserLimited: false,
          activeAtRender: false,
          startsAt: Math.floor(Date.now() / 1000) + 3600,
        }}
      >
        <p>aviso</p>
      </CouponGate>,
    );
    expect(screen.queryByText("aviso")).toBeNull();
  });
});

describe("GuestOnly", () => {
  beforeEach(() => resetViewerCouponsCache());
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
  });

  it("enseña la invitación a quien no tiene sesión", async () => {
    answer([], false);
    render(
      <GuestOnly>
        <p>inicia sesión</p>
      </GuestOnly>,
    );

    expect(await screen.findByText("inicia sesión")).toBeTruthy();
  });

  it("no se la enseña a quien ya la tiene, ni mientras no se sabe", async () => {
    const fetchMock = answer([], true);
    render(
      <GuestOnly>
        <p>inicia sesión</p>
      </GuestOnly>,
    );

    expect(screen.queryByText("inicia sesión")).toBeNull();
    await waitFor(() => expect(fetchMock).toHaveBeenCalled());
    await waitFor(() => expect(screen.queryByText("inicia sesión")).toBeNull());
  });
});
