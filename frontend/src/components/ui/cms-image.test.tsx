// @vitest-environment jsdom
import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render } from "@testing-library/react";
import { CmsImage } from "./cms-image";

afterEach(() => {
  cleanup();
});

describe("CmsImage con variante móvil", () => {
  it("pinta UNA imagen con dos fuentes por media, no dos imágenes ocultas por CSS", () => {
    const { container } = render(
      <div className="relative">
        <CmsImage
          src="https://mitienda.example/wp-content/uploads/desk.webp"
          mobileSrc="https://mitienda.example/wp-content/uploads/mob.webp"
          alt="Banner"
          fill
          priority
        />
      </div>,
    );
    const imgs = container.querySelectorAll("img");
    expect(imgs).toHaveLength(1);
    expect(imgs[0]!.getAttribute("alt")).toBe("Banner");
    expect(imgs[0]!.getAttribute("fetchpriority")).toBe("high");
    expect(imgs[0]!.className).not.toMatch(/hidden/);

    const sources = container.querySelectorAll("picture > source");
    expect([...sources].map((s) => s.getAttribute("media"))).toEqual([
      "(min-width: 1024px)",
      "(max-width: 1023.98px)",
    ]);
    expect(sources[0]!.getAttribute("srcset")).toContain("desk.webp");
    expect(sources[1]!.getAttribute("srcset")).toContain("mob.webp");
  });

  it("el <picture> no genera caja: el <img> sigue siendo el hijo del flex del padre", () => {
    const { container } = render(
      <CmsImage src="/a.webp" mobileSrc="/b.webp" alt="" width={10} height={10} />,
    );
    expect(container.querySelector("picture")!.className).toBe("contents");
  });

  it("sin prioridad, la imagen es diferida", () => {
    const { container } = render(
      <CmsImage src="/a.webp" mobileSrc="/b.webp" alt="" width={10} height={10} />,
    );
    expect(container.querySelector("img")!.getAttribute("loading")).toBe("lazy");
  });

  it("sin variante móvil sigue siendo un next/image normal", () => {
    const { container } = render(
      <CmsImage src="/a.webp" alt="x" width={10} height={10} />,
    );
    expect(container.querySelector("picture")).toBeNull();
    expect(container.querySelectorAll("img")).toHaveLength(1);
  });
});
