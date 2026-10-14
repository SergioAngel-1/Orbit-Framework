import { describe, expect, it } from "vitest";
import { normalizeEmail } from "./email";

describe("normalizeEmail", () => {
  it("trim + lower", () => {
    expect(normalizeEmail("  A@B.Com ")).toBe("a@b.com");
  });
});
