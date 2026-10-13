// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import { InfinitePostGrid } from "./infinite-post-grid";
import type { WPPost } from "@/types/wordpress";

vi.mock("next-intl", () => ({
  useTranslations: () => (key: string) => key,
}));
vi.mock("./post-card", () => ({
  PostCard: ({ post }: { post: WPPost }) => <article>{post.title}</article>,
}));

const post = (id: string): WPPost =>
  ({ id, databaseId: Number(id), title: `Entrada ${id}`, slug: `e-${id}` }) as WPPost;

let trigger: () => void = () => {};

beforeEach(() => {
  vi.stubGlobal(
    "IntersectionObserver",
    class {
      constructor(callback: IntersectionObserverCallback) {
        trigger = () =>
          callback(
            [{ isIntersecting: true } as IntersectionObserverEntry],
            this as unknown as IntersectionObserver,
          );
      }
      observe() {}
      disconnect() {}
      unobserve() {}
    },
  );
});

afterEach(() => {
  vi.unstubAllGlobals();
});

const fetchMock = (impl: () => Promise<Partial<Response>>) => {
  const fn = vi.fn<(url: string) => Promise<Partial<Response>>>(() => impl());
  vi.stubGlobal("fetch", fn);
  return fn;
};

describe("InfinitePostGrid", () => {
  it("pide la siguiente tanda con el cursor y los filtros, y la añade", async () => {
    const fn = fetchMock(async () => ({
      ok: true,
      json: async () => ({
        posts: [post("2")],
        pageInfo: { hasNextPage: false, endCursor: null },
      }),
    }));
    render(
      <InfinitePostGrid
        initialPosts={[post("1")]}
        initialPageInfo={{ hasNextPage: true, endCursor: "CUR1" }}
        locale="es"
        filters={{ tag: "verano", search: "lino" }}
      />,
    );

    await act(async () => trigger());

    const url = new URL(String(fn.mock.calls[0]![0]), "http://x.test");
    expect(url.pathname).toBe("/api/blog/posts");
    expect(url.searchParams.get("after")).toBe("CUR1");
    expect(url.searchParams.get("tag")).toBe("verano");
    expect(url.searchParams.get("search")).toBe("lino");
    expect(screen.getByText("Entrada 2")).toBeTruthy();
  });

  it("un fallo detiene la carga y deja un botón, en vez de reintentar en bucle", async () => {
    const fn = fetchMock(async () => ({ ok: false, status: 502 }));
    render(
      <InfinitePostGrid
        initialPosts={[post("1")]}
        initialPageInfo={{ hasNextPage: true, endCursor: "CUR1" }}
        locale="es"
        filters={{}}
      />,
    );

    await act(async () => trigger());

    expect(screen.getByRole("button", { name: "loadError" })).toBeTruthy();
    expect(fn).toHaveBeenCalledTimes(1);
  });

  it("sin más páginas no hay centinela que observe", () => {
    const fn = fetchMock(async () => ({ ok: true }));
    render(
      <InfinitePostGrid
        initialPosts={[post("1")]}
        initialPageInfo={{ hasNextPage: false, endCursor: null }}
        locale="es"
        filters={{}}
      />,
    );
    expect(fn).not.toHaveBeenCalled();
  });
});
