import { describe, expect, it } from "vitest";
import { resolveSections, unplacedAnchors } from "./resolve-sections";
import type { BaseSectionId } from "./registry";

const BASE: { id: BaseSectionId }[] = (
  ["hero", "lead", "s1", "s2", "s3", "quote", "faq", "products", "closing"] as const
).map((id) => ({ id }));

const ids = (flow: ReturnType<typeof resolveSections>) =>
  flow.map((section) => ("key" in section ? section.id : section.id));

describe("resolveSections", () => {
  it("sin únicas ni absorciones devuelve el molde tal cual", () => {
    expect(
      ids(resolveSections(BASE, { consumesBase: [], uniqueSections: [] })),
    ).toEqual(BASE.map((s) => s.id));
  });

  it("retira lo que una única absorbe", () => {
    const flow = resolveSections(BASE, {
      consumesBase: ["lead", "faq"],
      uniqueSections: [],
    });

    expect(ids(flow)).not.toContain("lead");
    expect(ids(flow)).not.toContain("faq");
  });

  it("inserta la única justo después de su ancla", () => {
    const flow = resolveSections(BASE, {
      consumesBase: [],
      uniqueSections: [{ key: "a", after: "s1" }],
    });

    expect(ids(flow).slice(2, 5)).toEqual(["s1", "unique:a", "s2"]);
  });

  it("varias únicas sobre la misma ancla conservan el orden de declaración", () => {
    const flow = resolveSections(BASE, {
      consumesBase: [],
      uniqueSections: [
        { key: "a", after: "s1" },
        { key: "b", after: "s1" },
        { key: "c", after: "s1" },
      ],
    });

    expect(ids(flow).slice(2, 7)).toEqual([
      "s1",
      "unique:a",
      "unique:b",
      "unique:c",
      "s2",
    ]);
  });

  it("filtra las absorbidas ANTES de resolver las anclas", () => {
    // `s1` está consumida: la ancla no existe y la única va al final, no a un índice desplazado.
    const entry = {
      consumesBase: ["s1"] as BaseSectionId[],
      uniqueSections: [{ key: "huérfana", after: "s1" as BaseSectionId }],
    };

    const flow = resolveSections(BASE, entry);

    expect(ids(flow).at(-1)).toBe("unique:huérfana");
    expect(unplacedAnchors(BASE, entry)).toEqual(["huérfana"]);
  });

  it("no muta la entrada", () => {
    const entry = {
      consumesBase: ["lead"] as BaseSectionId[],
      uniqueSections: [{ key: "a", after: "s1" as BaseSectionId }],
    };
    const snapshot = JSON.stringify([BASE, entry]);

    resolveSections(BASE, entry);

    expect(JSON.stringify([BASE, entry])).toBe(snapshot);
  });
});
