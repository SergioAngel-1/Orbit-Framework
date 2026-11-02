import {
  createServer,
  type IncomingMessage,
  type Server,
  type ServerResponse,
} from "node:http";
import { mkdtempSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import path from "node:path";
import type { AddressInfo } from "node:net";
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import {
  FORGOT_5XX_HINT,
  analyzeJsonLd,
  checkSitemapLocOrigin,
  classifyHttpStatus,
  compareBrandOrigin,
  exitCodeFor,
  findProductUrl,
  forgotPasswordVerdict,
  formatJson,
  imageOptimizerVerdict,
  productIds,
  productImageUrls,
  formatTable,
  isProductPath,
  parseEnvFile,
  parseSitemap,
  productFindings,
  sitemapUrlVerdict,
  softNotFoundVerdict,
  summarize,
} from "../../../tools/doctor/checks.mjs";
import { readSecret, runDoctor } from "../../../tools/doctor/run.mjs";

// ============================================================================
//  Tests del doctor (tools/doctor.mjs). Dos bloques:
//   1. Lógica pura de tools/doctor/checks.mjs, con entradas literales.
//   2. Integración: una pila SANA y otra ROTA levantadas con node:http en puertos
//      efímeros (127.0.0.1, puerto 0). Verifican los veredictos de runDoctor.
// ============================================================================

const SECRET = "secreto-de-prueba-0123456789";
const CSRF_TOKEN = "token-csrf-de-prueba";

type FindResult = { check: number; status: string; message: string; hint?: string };

const withStatus = (results: FindResult[], status: string) =>
  results.filter((r) => r.status === status);

// ---------------------------------------------------------------------------
//  Servidores falsos
// ---------------------------------------------------------------------------

type Mode = "healthy" | "broken";

function send(
  res: ServerResponse,
  status: number,
  body: string,
  headers: Record<string, string> = {},
) {
  res.writeHead(status, { "Content-Type": "text/plain; charset=utf-8", ...headers });
  res.end(body);
}

function sendJson(
  res: ServerResponse,
  status: number,
  data: unknown,
  headers: Record<string, string> = {},
) {
  send(res, status, JSON.stringify(data), {
    "Content-Type": "application/json",
    ...headers,
  });
}

function sendHtml(res: ServerResponse, status: number, html: string) {
  send(res, status, html, { "Content-Type": "text/html; charset=utf-8" });
}

function cookieValue(header: string | undefined, name: string): string | null {
  for (const part of (header ?? "").split(";")) {
    const [key, ...rest] = part.trim().split("=");
    if (key === name) return rest.join("=");
  }
  return null;
}

function productPage(mode: Mode): string {
  const product = {
    "@context": "https://schema.org",
    "@type": "Product",
    name: "Vela aromática",
    description: "Vela de soja artesanal",
    image: mode === "broken" ? [] : ["https://cdn.example.invalid/vela.jpg"],
    offers: { "@type": "Offer", price: "18.00", priceCurrency: "EUR" },
  };
  return `<!doctype html><html><head><script type="application/ld+json">${JSON.stringify(product)}</script></head><body>Vela</body></html>`;
}

function frontendHandler(mode: Mode, origin: () => string) {
  return (req: IncomingMessage, res: ServerResponse) => {
    req.resume();
    const o = origin();
    const { pathname } = new URL(req.url ?? "/", "http://fake.invalid");

    if (req.method === "GET" && pathname === "/api/health") {
      return sendJson(res, 200, {
        status: "ok",
        dependencies: { redis: "ok", wordpress: "ok" },
      });
    }
    if (req.method === "GET" && pathname === "/api/csrf") {
      return sendJson(
        res,
        200,
        { csrfToken: CSRF_TOKEN },
        { "Set-Cookie": `hwe_csrf=${CSRF_TOKEN}; Path=/; HttpOnly; SameSite=Strict` },
      );
    }
    if (req.method === "POST" && pathname === "/api/auth/forgot-password") {
      // Una pila real exige el mismo origen y el doble envío CSRF: si el doctor no los manda, da 403.
      const cookie = cookieValue(req.headers.cookie, "hwe_csrf");
      const allowed =
        req.headers.origin === o &&
        cookie !== null &&
        req.headers["x-csrf-token"] === cookie;
      if (!allowed) return sendJson(res, 403, { error: "Origen no permitido." });
      return mode === "healthy"
        ? sendJson(res, 200, { ok: true })
        : sendJson(res, 502, { error: "No se pudo procesar la solicitud." });
    }
    if (req.method === "GET" && pathname === "/robots.txt") {
      return send(res, 200, `User-agent: *\nAllow: /\nSitemap: ${o}/sitemap.xml\n`);
    }
    if (req.method === "GET" && pathname === "/sitemap.xml") {
      return send(
        res,
        200,
        `<?xml version="1.0" encoding="UTF-8"?><sitemapindex>` +
          `<sitemap><loc>${o}/sitemap/paginas.xml</loc></sitemap>` +
          `<sitemap><loc>${o}/sitemap/catalogo.xml</loc></sitemap></sitemapindex>`,
        { "Content-Type": "application/xml" },
      );
    }
    if (req.method === "GET" && pathname === "/sitemap/paginas.xml") {
      const extra =
        mode === "broken"
          ? `<url><loc>${o}/gone</loc></url><url><loc>${o}/old</loc></url>`
          : "";
      return send(
        res,
        200,
        `<?xml version="1.0"?><urlset><url><loc>${o}/</loc></url><url><loc>${o}/acerca</loc></url>${extra}</urlset>`,
        { "Content-Type": "application/xml" },
      );
    }
    if (req.method === "GET" && pathname === "/sitemap/catalogo.xml") {
      return send(
        res,
        200,
        `<?xml version="1.0"?><urlset><url><loc>${o}/products/vela-aromatica</loc></url></urlset>`,
        { "Content-Type": "application/xml" },
      );
    }
    if (req.method === "GET" && pathname === "/api/store/products") {
      // `?include=` lee por wc/v3: la pila rota reproduce el `X-Forwarded-Proto: https` que
      // subía las URL de la medioteca a https en un sitio http.
      const include = new URL(req.url ?? "/", "http://fake.invalid").searchParams.get("include");
      const base = include && mode === "broken" ? o.replace(/^http:/, "https:") : o;
      return sendJson(res, 200, {
        products: [
          { databaseId: 7, image: { sourceUrl: `${base}/wp-content/uploads/vela.jpg` } },
          { databaseId: 8, image: null },
        ],
        pageInfo: { hasNextPage: false, endCursor: null },
      });
    }
    if (req.method === "GET" && pathname === "/_next/image") {
      // Como el optimizador real: solo el origen de `remotePatterns` (WORDPRESS_PUBLIC_URL).
      const src = new URL(req.url ?? "/", "http://fake.invalid").searchParams.get("url") ?? "";
      return src.startsWith(`${o}/wp-content/uploads/`)
        ? send(res, 200, "img", { "Content-Type": "image/jpeg" })
        : send(res, 400, `"url" parameter is not allowed`);
    }
    if (req.method === "GET" && pathname === "/")
      return sendHtml(res, 200, "<!doctype html><html><body>Inicio</body></html>");
    if (req.method === "GET" && pathname === "/acerca")
      return sendHtml(res, 200, "<!doctype html><html><body>Acerca</body></html>");
    if (req.method === "GET" && pathname === "/products/vela-aromatica")
      return sendHtml(res, 200, productPage(mode));
    if (req.method === "GET" && pathname === "/gone")
      return send(res, 404, "No encontrado");
    if (req.method === "GET" && pathname === "/old")
      return send(res, 308, "", { Location: "/acerca" });

    // Ruta inexistente: la sana responde 404 real; la rota, un «soft 404» con 200.
    if (mode === "healthy") return send(res, 404, "No encontrado");
    return sendHtml(
      res,
      200,
      "<!doctype html><html><body>Página no encontrada</body></html>",
    );
  };
}

function configHandler(brandUrl: string) {
  return (req: IncomingMessage, res: ServerResponse) => {
    req.resume();
    const { pathname } = new URL(req.url ?? "/", "http://fake.invalid");
    if (req.method === "GET" && pathname === "/wp-json/hwe/v1/config") {
      // WordPress solo atiende con el secreto interno, como el mu-plugin hwe-bff-only.
      if (req.headers["x-hwe-internal-secret"] !== SECRET) return send(res, 404, "");
      return sendJson(res, 200, { brand: { name: "Vela", url: brandUrl } });
    }
    return send(res, 404, "");
  };
}

function listen(server: Server): Promise<string> {
  return new Promise((resolve, reject) => {
    server.once("error", reject);
    server.listen(0, "127.0.0.1", () => {
      const { port } = server.address() as AddressInfo;
      resolve(`http://127.0.0.1:${port}`);
    });
  });
}

interface Stack {
  frontend: string;
  wordpress: string;
  close: () => Promise<void>;
}

async function startStack(mode: Mode, brandOverride?: string): Promise<Stack> {
  const servers: Server[] = [];
  let frontend = "";
  const front = createServer(frontendHandler(mode, () => frontend));
  servers.push(front);
  frontend = await listen(front);
  const wp = createServer(configHandler(brandOverride ?? frontend));
  servers.push(wp);
  const wordpress = await listen(wp);
  return {
    frontend,
    wordpress,
    close: () =>
      Promise.all(
        servers.map(
          (s) =>
            new Promise<void>((resolve) => {
              s.closeAllConnections();
              s.close(() => resolve());
            }),
        ),
      ).then(() => undefined),
  };
}

// ---------------------------------------------------------------------------
//  1. Lógica pura
// ---------------------------------------------------------------------------

describe("doctor: lógica pura (checks.mjs)", () => {
  it("parsea un urlset y decodifica entidades XML", () => {
    const parsed = parseSitemap(
      `<?xml version="1.0"?><urlset><url><loc>https://a.test/x?a=1&amp;b=2</loc></url></urlset>`,
    );
    expect(parsed.kind).toBe("urlset");
    expect(parsed.locs).toEqual(["https://a.test/x?a=1&b=2"]);
  });

  it("distingue un sitemapindex de un urlset y de un XML desconocido", () => {
    expect(
      parseSitemap(
        "<sitemapindex><sitemap><loc>https://a.test/s.xml</loc></sitemap></sitemapindex>",
      ).kind,
    ).toBe("index");
    expect(parseSitemap("<html></html>")).toEqual({ kind: "unknown", locs: [] });
  });

  it("clasifica los códigos: 2xx ok, 3xx warn, 404/5xx/sin respuesta fail", () => {
    expect(classifyHttpStatus(200)).toBe("ok");
    expect(classifyHttpStatus(308)).toBe("warn");
    expect(classifyHttpStatus(404)).toBe("fail");
    expect(classifyHttpStatus(503)).toBe("fail");
    expect(classifyHttpStatus(0)).toBe("fail");
  });

  it("avisa de <loc> relativas o de otro origen, y no avisa de las del frontend", () => {
    expect(checkSitemapLocOrigin("/acerca", "https://tienda.test")?.status).toBe(
      "warn",
    );
    expect(
      checkSitemapLocOrigin("https://otro.test/acerca", "https://tienda.test")?.status,
    ).toBe("warn");
    expect(
      checkSitemapLocOrigin("https://tienda.test/acerca", "https://tienda.test"),
    ).toBeNull();
  });

  it("un 404 de una URL del sitemap es fail y un 308 es warn con su destino", () => {
    expect(sitemapUrlVerdict("https://t.test/gone", 404).status).toBe("fail");
    const redirect = sitemapUrlVerdict("https://t.test/old", 308, "/acerca");
    expect(redirect.status).toBe("warn");
    expect(redirect.message).toContain("/acerca");
  });

  it("compara el origen de brand.url con el del frontend", () => {
    expect(compareBrandOrigin("", "http://localhost:3000").status).toBe("warn");
    expect(
      compareBrandOrigin("https://tienda.test", "http://localhost:3000").status,
    ).toBe("fail");
    expect(
      compareBrandOrigin("http://localhost:3000/", "http://localhost:3000").status,
    ).toBe("ok");
    expect(compareBrandOrigin("no-es-url", "http://localhost:3000").status).toBe(
      "fail",
    );
  });

  it("reconoce rutas de ficha de producto y excluye el índice", () => {
    expect(isProductPath("/products/vela")).toBe(true);
    expect(isProductPath("/es/productos/vela")).toBe(true);
    expect(isProductPath("/products/archive")).toBe(false);
    expect(isProductPath("/acerca")).toBe(false);
    expect(
      findProductUrl(
        ["https://otro.test/products/x", "https://t.test/products/vela"],
        "https://t.test",
      ),
    ).toBe("https://t.test/products/vela");
  });

  it("analiza JSON-LD: encuentra Product en @graph y cuenta los bloques que no parsean", () => {
    const html =
      `<script type="application/ld+json">{"@graph":[{"@type":"Product","name":"A"}]}</script>` +
      `<script type="application/ld+json">{no json</script>`;
    const analysis = analyzeJsonLd(html);
    expect(analysis.blocks).toBe(2);
    expect(analysis.parseErrors).toHaveLength(1);
    expect(analysis.products.map((p: { name: string }) => p.name)).toEqual(["A"]);
  });

  it("marca image vacío y description vacía como fail, y la falta de offers como warn", () => {
    const bad = productFindings({
      "@type": "Product",
      name: "B",
      image: [],
      description: "",
      offers: {},
    });
    expect(bad.filter((f: { status: string }) => f.status === "fail")).toHaveLength(2);
    const noOffers = productFindings({
      "@type": "Product",
      name: "C",
      image: ["x.jpg"],
      description: "ok",
    });
    expect(noOffers).toEqual([expect.objectContaining({ status: "warn" })]);
    expect(
      productFindings({
        "@type": "Product",
        name: "D",
        image: ["x.jpg"],
        description: "ok",
        offers: {},
      }),
    ).toEqual([]);
  });

  it("un soft 404 (200 en una ruta inexistente) es fail, y un 404 real es ok", () => {
    expect(softNotFoundVerdict(200).status).toBe("fail");
    expect(softNotFoundVerdict(404).status).toBe("ok");
    expect(softNotFoundVerdict(308, "/es/").status).toBe("warn");
  });

  it("extrae ids e imágenes de una respuesta de /api/store/products", () => {
    const body = {
      products: [
        { databaseId: 7, image: { sourceUrl: "http://wp.test/wp-content/uploads/a.jpg" } },
        { databaseId: 8, image: null },
        { databaseId: "x", image: { sourceUrl: "" } },
      ],
    };
    expect(productIds(body)).toEqual([7, 8]);
    expect(productImageUrls(body)).toEqual(["http://wp.test/wp-content/uploads/a.jpg"]);
    expect(productIds(null)).toEqual([]);
    expect(productImageUrls({ products: "no" })).toEqual([]);
  });

  it("una imagen que next/image rechaza (400) es fail con la pista de remotePatterns", () => {
    const url = "https://localhost:8080/wp-content/uploads/a.jpg";
    expect(imageOptimizerVerdict(url, 200).status).toBe("ok");
    const rejected = imageOptimizerVerdict(url, 400);
    expect(rejected.status).toBe("fail");
    expect(rejected.message).toContain(url);
    expect(rejected.hint).toContain("WORDPRESS_PUBLIC_URL");
    expect(imageOptimizerVerdict(url, 502).status).toBe("warn");
  });

  it("un 5xx de recuperación de contraseña lleva la pista de origen", () => {
    const v = forgotPasswordVerdict(502);
    expect(v.status).toBe("fail");
    expect(v.hint).toBe(FORGOT_5XX_HINT);
    expect(FORGOT_5XX_HINT).toContain(
      "NEXT_PUBLIC_SITE_URL / HEADLESS_ALLOWED_ORIGINS",
    );
  });

  it("parsea un .env ignorando comentarios y quitando comillas", () => {
    const env = parseEnvFile(
      `# c\nHWE_REVALIDATION_SECRET="abc=def"\n\n# HWE_X=1\nNAME='x y'\n`,
    );
    expect(env).toEqual({ HWE_REVALIDATION_SECRET: "abc=def", NAME: "x y" });
  });

  it("resume, calcula el código de salida y formatea sin mostrar el secreto", () => {
    const results = [
      { check: 1, status: "ok", message: "brand.url coincide" },
      { check: 4, status: "fail", message: "POST → 502", hint: FORGOT_5XX_HINT },
    ];
    expect(summarize(results)).toEqual({ ok: 1, warn: 0, fail: 1, skip: 0 });
    expect(exitCodeFor(results)).toBe(1);
    expect(exitCodeFor([results[0]])).toBe(0);
    const table = formatTable(results);
    expect(table).toContain("FAIL  4.");
    expect(table).toContain(FORGOT_5XX_HINT);
    expect(JSON.parse(formatJson(results)).summary.fail).toBe(1);
  });

  it("lee el secreto del entorno o de los .env, con el entorno primero", () => {
    const dir = mkdtempSync(path.join(tmpdir(), "doctor-secret-"));
    try {
      mkdirSync(path.join(dir, "frontend"));
      writeFileSync(path.join(dir, ".env"), "HWE_REVALIDATION_SECRET=desde-env-file\n");
      writeFileSync(
        path.join(dir, "frontend", ".env.local"),
        "HWE_REVALIDATION_SECRET=desde-local\n",
      );
      expect(
        readSecret({ env: { HWE_REVALIDATION_SECRET: " del-entorno " }, rootDir: dir }),
      ).toEqual({
        value: "del-entorno",
        source: "entorno",
      });
      expect(readSecret({ env: {}, rootDir: dir })).toEqual({
        value: "desde-env-file",
        source: ".env",
      });
      rmSync(path.join(dir, ".env"));
      expect(readSecret({ env: {}, rootDir: dir })).toEqual({
        value: "desde-local",
        source: "frontend/.env.local",
      });
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  });
});

// ---------------------------------------------------------------------------
//  2. Integración contra servidores falsos
// ---------------------------------------------------------------------------

describe("doctor: integración con una pila falsa", () => {
  let healthy: Stack;
  let broken: Stack;
  let mismatch: Stack;

  beforeAll(async () => {
    healthy = await startStack("healthy");
    broken = await startStack("broken");
    mismatch = await startStack("healthy", "https://otra-marca.invalid");
  });

  afterAll(async () => {
    await Promise.all([healthy?.close(), broken?.close(), mismatch?.close()]);
  });

  it("una pila sana no da ningún fail ni warn", async () => {
    const results = await runDoctor({
      frontend: healthy.frontend,
      wordpress: healthy.wordpress,
      secret: SECRET,
    });
    expect(withStatus(results, "fail")).toEqual([]);
    expect(withStatus(results, "warn")).toEqual([]);
    expect(exitCodeFor(results)).toBe(0);
    // Los checks que dependen del BFF (CSRF + Origin) sí se ejecutan contra la pila sana.
    expect(results.find((r) => r.check === 4)?.status).toBe("ok");
    expect(results.find((r) => r.check === 6)?.status).toBe("ok");
    expect(results.find((r) => r.check === 7)?.status).toBe("ok");
  });

  it("una pila rota da fail en la recuperación de contraseña (502) con la pista de origen", async () => {
    const results = await runDoctor({
      frontend: broken.frontend,
      wordpress: broken.wordpress,
      secret: SECRET,
    });
    const forgot = results.find((r) => r.check === 4);
    expect(forgot?.status).toBe("fail");
    expect(forgot?.message).toContain("502");
    expect(forgot?.hint).toBe(FORGOT_5XX_HINT);
    expect(exitCodeFor(results)).toBe(1);
  });

  it("una pila rota marca el 404 del sitemap como fail y el 308 como warn", async () => {
    const results = await runDoctor({
      frontend: broken.frontend,
      wordpress: broken.wordpress,
      secret: SECRET,
    });
    const notFound = results.find(
      (r) => r.check === 5 && r.status === "fail" && r.message.includes("/gone"),
    );
    const redirect = results.find(
      (r) => r.check === 5 && r.status === "warn" && r.message.includes("/old"),
    );
    expect(notFound?.message).toContain("404");
    expect(redirect?.message).toContain("308");
  });

  it("una pila rota marca image [] en el JSON-LD de producto como fail", async () => {
    const results = await runDoctor({
      frontend: broken.frontend,
      wordpress: broken.wordpress,
      secret: SECRET,
    });
    const image = results.find((r) => r.check === 6 && r.status === "fail");
    expect(image?.message).toContain("image es un array vacío");
  });

  it("una imagen del BFF que next/image no admite da fail en el check 8", async () => {
    const results = await runDoctor({
      frontend: broken.frontend,
      wordpress: broken.wordpress,
      secret: SECRET,
    });
    const image = results.find((r) => r.check === 8 && r.status === "fail");
    expect(image?.message).toContain("https://127.0.0.1:");
    expect(image?.message).toContain("/wp-content/uploads/vela.jpg");

    const sane = await runDoctor({
      frontend: healthy.frontend,
      wordpress: healthy.wordpress,
      secret: SECRET,
    });
    expect(sane.find((r) => r.check === 8)?.status).toBe("ok");
  });

  it("una pila rota con soft 404 da fail en el check 7", async () => {
    const results = await runDoctor({
      frontend: broken.frontend,
      wordpress: broken.wordpress,
      secret: SECRET,
    });
    expect(results.find((r) => r.check === 7)?.status).toBe("fail");
    expect(results.find((r) => r.check === 7)?.message).toContain("soft 404");
  });

  it("brand.url de otro origen da fail en el check 1", async () => {
    const results = await runDoctor({
      frontend: mismatch.frontend,
      wordpress: mismatch.wordpress,
      secret: SECRET,
    });
    const brand = results.find((r) => r.check === 1 && r.status === "fail");
    expect(brand?.message).toContain("no es el origen del frontend");
  });

  it("un secreto incorrecto hace que WordPress no responda la config: fail en el check 1", async () => {
    const results = await runDoctor({
      frontend: healthy.frontend,
      wordpress: healthy.wordpress,
      secret: "otro",
    });
    const config = results.find((r) => r.check === 1 && r.status === "fail");
    expect(config?.hint).toContain("HWE_REVALIDATION_SECRET");
  });

  it("nunca imprime el secreto en la salida", async () => {
    const results = await runDoctor({
      frontend: broken.frontend,
      wordpress: broken.wordpress,
      secret: SECRET,
    });
    expect(formatTable(results)).not.toContain(SECRET);
    expect(formatJson(results)).not.toContain(SECRET);
  });

  it("--max-urls limita las URL comprobadas y lo anuncia", async () => {
    const results = await runDoctor({
      frontend: healthy.frontend,
      wordpress: healthy.wordpress,
      secret: SECRET,
      maxUrls: 1,
    });
    expect(
      results.some(
        (r) => r.check === 5 && r.message.includes("solo se comprueban 1 de"),
      ),
    ).toBe(true);
  });
});
