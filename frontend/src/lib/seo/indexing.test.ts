import { describe, expect, it } from "vitest";
import { indexingBlockedByEnv } from "./indexing";

describe("indexingBlockedByEnv", () => {
  it.each(["true", "TRUE", "1", "yes", " true "])("%j bloquea", (value) => {
    expect(indexingBlockedByEnv({ HWE_NOINDEX: value })).toBe(true);
  });

  it.each([undefined, "", "false", "0", "no"])("%j no bloquea", (value) => {
    expect(indexingBlockedByEnv({ HWE_NOINDEX: value })).toBe(false);
  });
});
