import { describe, expect, it, vi } from "vitest";

vi.mock("@sentry/nextjs", () => ({ marker: "sentry-sdk" }));

import { loadSentrySdk } from "./sentry-sdk-loader";

describe("loadSentrySdk", () => {
  it("resuelve el SDK que importa el chunk diferido", async () => {
    await expect(loadSentrySdk()).resolves.toEqual({ marker: "sentry-sdk" });
  });
});
