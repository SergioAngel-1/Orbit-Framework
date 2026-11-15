// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render } from "@testing-library/react";
import { CmsImage } from "./cms-image";

afterEach(() => {
  cleanup();
  vi.unstubAllEnvs();
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

describe("CmsImage: mismo resultado en el servidor y en el cliente", () => {
  const SRC = "https://tienda.example/wp-content/uploads/2026/07/foto.webp";

  /** Pinta con el entorno del servidor (origen interno) o sin él (el navegador). */
  function html(server: boolean, props: { priority?: boolean; mobileSrc?: string }) {
    vi.stubEnv("WORDPRESS_PUBLIC_URL", server ? "https://tienda.example" : "");
    vi.stubEnv(
      "WORDPRESS_INTERNAL_API_URL",
      server ? "http://wordpress:80/graphql" : "",
    );
    const { container, unmount } = render(
      <CmsImage src={SRC} alt="x" width={400} height={300} {...props} />,
    );
    const out = container.innerHTML;
    unmount();
    return out;
  }

  it("el <img> es idéntico con y sin el origen interno, y no lo deja en el HTML", () => {
    const server = html(true, {});
    expect(server).toBe(html(false, {}));
    expect(server).not.toContain("wordpress");
    expect(server).toContain("%2Fapi%2Fmedia%2F2026%2F07%2Ffoto.webp");
  });

  it("también con variante móvil (art direction)", () => {
    const props = {
      priority: true,
      mobileSrc: "https://tienda.example/wp-content/uploads/2026/07/mov.webp",
    };
    const server = html(true, props);
    expect(server).toBe(html(false, props));
    expect(server).not.toContain("wordpress");
  });

  it("conserva ?ver= en la URL que pide al optimizador", () => {
    const { container } = render(
      <CmsImage src={`${SRC}?ver=3`} alt="x" width={400} height={300} />,
    );
    expect(container.querySelector("img")!.getAttribute("src")).toContain(
      encodeURIComponent("/api/media/2026/07/foto.webp?ver=3"),
    );
  });

  it("una URL que no es de la medioteca no se toca", () => {
    const { container } = render(
      <CmsImage src="/img/a.webp" alt="x" width={10} height={10} />,
    );
    expect(container.querySelector("img")!.getAttribute("src")).toContain(
      encodeURIComponent("/img/a.webp"),
    );
  });

  it("un SVG conserva su URL: no pasa por /api/media ni por el optimizador", () => {
    const url = "https://tienda.example/wp-content/uploads/2026/07/logo.svg";
    const { container } = render(<CmsImage src={url} alt="x" width={40} height={40} />);
    const img = container.querySelector("img")!;
    expect(img.getAttribute("src")).toBe(url);
    expect(img.getAttribute("srcset")).toBeNull();
    expect(container.innerHTML).not.toContain("api/media");
  });

  it("un SVG relativo, solo o como variante móvil, se deja tal cual", () => {
    const solo = render(
      <CmsImage src="/wp-content/uploads/a.svg" alt="x" width={40} height={40} />,
    );
    expect(solo.container.querySelector("img")!.getAttribute("src")).toBe(
      "/wp-content/uploads/a.svg",
    );
    cleanup();

    const art = render(
      <CmsImage
        src="/wp-content/uploads/a.svg"
        mobileSrc="/wp-content/uploads/b.svg"
        alt="x"
        width={40}
        height={40}
      />,
    );
    expect(art.container.innerHTML).toContain("/wp-content/uploads/b.svg");
    expect(art.container.innerHTML).not.toContain("api/media");
  });
});
