import { beforeEach, describe, expect, it, vi } from "vitest";
import { wpFetch } from "@/lib/wordpress/internal-fetch";
import { attachIdentity, isDocumentAvailable } from "./identity-api";

vi.mock("@/lib/wordpress/internal-fetch", () => ({ wpFetch: vi.fn() }));

const reply = (body: unknown, status = 200) =>
  vi.mocked(wpFetch).mockResolvedValue(Response.json(body, { status }));

const lastBody = () =>
  JSON.parse(String((vi.mocked(wpFetch).mock.calls.at(-1)?.[1] as RequestInit).body));

beforeEach(() => {
  vi.mocked(wpFetch).mockReset();
});

describe("isDocumentAvailable", () => {
  it("true/false según WordPress, por la ruta interna de auth", async () => {
    reply({ available: true });
    expect(await isDocumentAvailable("CC", "1012345678")).toBe(true);
    expect(vi.mocked(wpFetch).mock.calls[0][0]).toBe(
      "/wp-json/hwe/v1/auth/document-available",
    );
    expect(lastBody()).toEqual({ document_type: "CC", document_number: "1012345678" });

    reply({ available: false });
    expect(await isDocumentAvailable("CC", "1012345678")).toBe(false);
  });

  it("null si WordPress no responde bien (el llamador decide)", async () => {
    reply({ error: "x" }, 500);
    expect(await isDocumentAvailable("CC", "1")).toBeNull();

    vi.mocked(wpFetch).mockRejectedValue(new Error("red"));
    expect(await isDocumentAvailable("CC", "1")).toBeNull();
  });
});

describe("attachIdentity", () => {
  it("manda el documento y los términos y devuelve la identidad guardada", async () => {
    reply({
      document_type: "CC",
      document_number: "1012345678",
      terms_accepted_at: "2026-10-13T10:00:00+00:00",
    });

    const r = await attachIdentity(7, {
      documentType: "CC",
      documentNumber: "1012345678",
      termsAccepted: true,
    });

    expect(lastBody()).toEqual({
      user_id: 7,
      document_type: "CC",
      document_number: "1012345678",
      terms_accepted: true,
    });
    expect(r).toEqual({
      ok: true,
      identity: {
        documentType: "CC",
        documentNumber: "1012345678",
        termsAcceptedAt: "2026-10-13T10:00:00+00:00",
      },
    });
  });

  it("solo manda lo que se pidió", async () => {
    reply({});
    await attachIdentity(7, { termsAccepted: true });
    expect(lastBody()).toEqual({ user_id: 7, terms_accepted: true });
  });

  it.each([
    [409, { error: "document_taken" }, "document_taken"],
    [409, { error: "document_locked" }, "document_locked"],
    [400, { error: "invalid_document" }, "invalid"],
    [502, {}, "unavailable"],
  ])("traduce el %s %j a «%s»", async (status, body, reason) => {
    reply(body, status);

    expect(
      await attachIdentity(7, { documentType: "CC", documentNumber: "1012345678" }),
    ).toEqual({
      ok: false,
      reason,
    });
  });

  it("si la red falla, no disponible", async () => {
    vi.mocked(wpFetch).mockRejectedValue(new Error("red"));

    expect(await attachIdentity(7, { termsAccepted: true })).toEqual({
      ok: false,
      reason: "unavailable",
    });
  });
});
