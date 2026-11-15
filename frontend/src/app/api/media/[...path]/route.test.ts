import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { GET } from "./route";

const fetchMock = vi.fn();

beforeEach(() => {
  vi.stubEnv("WORDPRESS_INTERNAL_API_URL", "http://wordpress:80/graphql");
  vi.stubEnv("HWE_REVALIDATION_SECRET", "s3cret");
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  fetchMock.mockReset();
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

const call = (path: string[]) =>
  GET(new Request("http://localhost/api/media/x"), {
    params: Promise.resolve({ path }),
  });

const image = (type = "image/webp") =>
  new Response(new Uint8Array([1, 2, 3]), {
    status: 200,
    headers: { "content-type": type, "content-length": "3" },
  });

describe("GET /api/media", () => {
  it("descarga del ORIGEN INTERNO y devuelve la imagen cacheable", async () => {
    fetchMock.mockResolvedValue(image());
    const res = await call(["2026", "07", "foto.webp"]);

    expect(fetchMock.mock.calls[0]![0]).toBe(
      "http://wordpress:80/wp-content/uploads/2026/07/foto.webp",
    );
    expect(res.status).toBe(200);
    expect(res.headers.get("content-type")).toBe("image/webp");
    expect(res.headers.get("cache-control")).toContain("max-age=31536000");
    expect(new Uint8Array(await res.arrayBuffer())).toEqual(new Uint8Array([1, 2, 3]));
  });

  it("codifica los segmentos y no deja que cambien de host ni salgan de uploads", async () => {
    for (const path of [
      [".."],
      ["2026", "..", "wp-config.php"],
      ["."],
      [""],
      ["a/b"],
      ["a\\b"],
      ["a?x=1"],
      ["a#b"],
      ["a\0b"],
      [],
    ]) {
      expect((await call(path)).status).toBe(404);
    }
    expect(fetchMock).not.toHaveBeenCalled();

    fetchMock.mockResolvedValue(image());
    await call(["mi foto.webp"]);
    expect(fetchMock.mock.calls[0]![0]).toBe(
      "http://wordpress:80/wp-content/uploads/mi%20foto.webp",
    );
  });

  it("no sirve SVG ni archivos que no son imágenes", async () => {
    fetchMock.mockResolvedValueOnce(image("image/svg+xml"));
    expect((await call(["a.svg"])).status).toBe(404);
    fetchMock.mockResolvedValueOnce(image("application/pdf"));
    expect((await call(["a.pdf"])).status).toBe(404);
  });

  it("404 de WordPress → 404; caída o error de WordPress → 502", async () => {
    fetchMock.mockResolvedValueOnce(new Response("", { status: 404 }));
    expect((await call(["a.png"])).status).toBe(404);
    fetchMock.mockResolvedValueOnce(new Response("", { status: 500 }));
    expect((await call(["a.png"])).status).toBe(502);
    fetchMock.mockRejectedValueOnce(new Error("ECONNREFUSED"));
    expect((await call(["a.png"])).status).toBe(502);
  });
});
