import { describe, expect, it } from "vitest";
import * as noop from "./sentry-noop";

describe("sentry-noop", () => {
  it("implementa la superficie usada por el framework sin efectos", () => {
    expect(() => noop.init()).not.toThrow();
    expect(() => noop.captureException()).not.toThrow();
    expect(() => noop.captureRouterTransitionStart()).not.toThrow();
    expect(noop.replayIntegration()).toEqual({ name: "NoopReplay" });
  });
});
