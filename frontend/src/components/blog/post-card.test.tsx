// @vitest-environment jsdom
import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { PostCard } from "./post-card";
import type { WPPost } from "@/types/wordpress";

vi.mock("@/i18n/navigation", () => ({
  Link: ({
    href,
    children,
    ...rest
  }: {
    href: string | { pathname: string; query?: Record<string, string> };
    children: ReactNode;
  }) => (
    <a
      href={
        typeof href === "string"
          ? href
          : `${href.pathname}?${new URLSearchParams(href.query).toString()}`
      }
      {...rest}
    >
      {children}
    </a>
  ),
}));

const post = {
  id: "1",
  databaseId: 1,
  title: "Cómo elegir una camiseta",
  slug: "como-elegir-una-camiseta",
  date: "2026-09-01T10:00:00",
  excerpt: "<p>Una guía corta.</p>",
  featuredImage: null,
  author: { node: { name: "Ana", slug: "ana" } },
  tags: {
    nodes: [
      { name: "Verano", slug: "verano", count: 5 },
      { name: "Único", slug: "unico", count: 1 },
    ],
  },
  categories: {
    nodes: [
      { name: "Uncategorized", slug: "uncategorized", count: 20 },
      { name: "Guías", slug: "guias", count: 4 },
    ],
  },
} as unknown as WPPost;

describe("PostCard", () => {
  it("ningún enlace anida a otro (HTML válido): el título es el enlace estirado", () => {
    const { container } = render(
      <PostCard post={post} locale="es" readMore="Leer más" />,
    );

    expect(container.querySelectorAll("a a")).toHaveLength(0);
    const title = screen.getByRole("link", { name: post.title });
    expect(title.getAttribute("href")).toBe("/blog/como-elegir-una-camiseta");
    expect(title.className).toContain("after:absolute");
  });

  it("enlaza la categoría temática, no el cajón, y el autor", () => {
    render(<PostCard post={post} locale="es" readMore="Leer más" />);

    expect(screen.getByRole("link", { name: "Guías" }).getAttribute("href")).toBe(
      "/blog/category/guias",
    );
    expect(screen.queryByText("Uncategorized")).toBeNull();
    expect(screen.getByRole("link", { name: "Ana" }).getAttribute("href")).toBe(
      "/blog?author=ana",
    );
  });

  it("una etiqueta con una sola entrada es texto, no un filtro vacío", () => {
    render(<PostCard post={post} locale="es" readMore="Leer más" />);

    expect(screen.getByRole("link", { name: /Verano/ }).getAttribute("href")).toBe(
      "/blog?tag=verano",
    );
    expect(screen.queryByRole("link", { name: /Único/ })).toBeNull();
    expect(screen.getByText("Único")).toBeTruthy();
  });
});
