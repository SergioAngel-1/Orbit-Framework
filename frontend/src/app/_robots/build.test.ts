import { readFileSync, existsSync } from "node:fs";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { CONFIG_DEFAULTS } from "@/lib/config/defaults";
import type { SiteConfig } from "@/lib/config/types";

let currentConfig: SiteConfig;
let degraded = false;

const warn = vi.fn();
vi.mock("@/lib/observability/logger", () => ({
  logger: { warn: (...a: unknown[]) => warn(...a), error: vi.fn(), info: vi.fn() },
}));

vi.mock("@/lib/config", () => ({
  getSiteConfig: async () => currentConfig,
  isFallbackSiteConfig: () => degraded,
}));

import { PRIVATE_PATHS, contentSignal, robotsResponse } from "./build";

function config(patch: Partial<SiteConfig["geo"]> = {}): SiteConfig {
  const c = structuredClone(CONFIG_DEFAULTS);
  c.brand.url = "https://mitienda.example/";
  c.geo = { ...c.geo, ...patch };
  return c;
}

async function robots(): Promise<string> {
  vi.stubEnv("NODE_ENV", "production");
  return (await robotsResponse()).text();
}

beforeEach(() => {
  degraded = false;
  currentConfig = config();
});

afterEach(() => {
  vi.unstubAllEnvs();
});

describe("Content-Signal", () => {
  it("usa las señales que existen en el borrador: search, ai-input y ai-train", () => {
    for (const policy of ["allow", "search_only", "block"]) {
      const value = contentSignal(policy);
      // `ai-retrieval` no existe: un parser que lo ve inválido descarta la línea.
      expect(value).not.toContain("ai-retrieval");
      expect(value).toMatch(/^ai-train=(yes|no), search=(yes|no), ai-input=(yes|no)$/);
    }
  });

  it("es coherente con la política de crawlers", () => {
    expect(contentSignal("allow")).toBe("ai-train=yes, search=yes, ai-input=yes");
    expect(contentSignal("search_only")).toBe("ai-train=no, search=yes, ai-input=yes");
    expect(contentSignal("block")).toBe("ai-train=no, search=no, ai-input=no");
  });
});

describe("robots.txt", () => {
  it("permite las tarjetas e iconos aunque /api/ esté vetado", async () => {
    const body = await robots();
    expect(body).toContain("Allow: /api/og");
    expect(body).toContain("Allow: /api/icon");
    expect(body).toContain("Disallow: /api/");
  });

  it("veta las vistas privadas en cada idioma", async () => {
    const body = await robots();
    for (const path of PRIVATE_PATHS) {
      expect(body).toContain(`Disallow: ${path}`);
      expect(body).toContain(`Disallow: /en${path}`);
    }
  });

  it("anuncia el sitemap con la URL real y sin barra doble", async () => {
    expect(await robots()).toContain("Sitemap: https://mitienda.example/sitemap.xml");
  });

  it("con la config degradada no anuncia un sitemap con el dominio por defecto", async () => {
    degraded = true;
    const response = await (async () => {
      vi.stubEnv("NODE_ENV", "production");
      return robotsResponse();
    })();
    const body = await response.text();
    expect(body).not.toContain("Sitemap:");
    expect(body).toContain("Disallow: /api/");
    expect(response.headers.get("Cache-Control")).toBe("no-store");
  });

  it("la política «block» veta entrenamiento y búsqueda de IA; «allow» no veta ninguno", async () => {
    currentConfig = config({ ai_crawlers: "block" });
    const blocked = await robots();
    expect(blocked).toContain("User-agent: GPTBot\nDisallow: /");
    expect(blocked).toContain("User-agent: OAI-SearchBot\nDisallow: /");

    currentConfig = config({ ai_crawlers: "allow" });
    expect(await robots()).not.toContain("User-agent: GPTBot");
  });

  it("«search_only» veta el entrenamiento pero deja la búsqueda", async () => {
    currentConfig = config({ ai_crawlers: "search_only" });
    const body = await robots();
    expect(body).toContain("User-agent: GPTBot\nDisallow: /");
    expect(body).not.toContain("User-agent: OAI-SearchBot");
  });

  it("sin Content-Signal activado no emite la directiva", async () => {
    currentConfig = config({ content_signal: false });
    expect(await robots()).not.toContain("Content-Signal");
  });

  it("con HWE_NOINDEX (staging) veta todo y no se cachea", async () => {
    vi.stubEnv("HWE_NOINDEX", "true");
    vi.stubEnv("NODE_ENV", "production");
    const res = await robotsResponse();
    expect(await res.text()).toBe("User-agent: *\nDisallow: /\n");
    expect(res.headers.get("Cache-Control")).toBe("no-store");
  });

  it("noindex en el panel sin la guarda de entorno deja un aviso en el log", async () => {
    warn.mockClear();
    currentConfig = config();
    currentConfig.seo.robots = "noindex,nofollow";
    await robots();
    expect(warn).toHaveBeenCalledWith(
      { event: "seo.site_noindex" },
      expect.any(String),
    );
    warn.mockClear();
    currentConfig = config();
    await robots();
    expect(warn).not.toHaveBeenCalled();
  });

  it("en desarrollo veta todo", async () => {
    vi.stubEnv("NODE_ENV", "development");
    expect(await (await robotsResponse()).text()).toBe("User-agent: *\nDisallow: /\n");
  });
});

// Todo lo que robots.txt veta es una vista privada: cada una tiene que declarar
// ella misma `noindex` (un `Disallow` impide rastrear, no indexar la URL si
// alguien la enlaza). Una vista privada nueva sin `privateMetadata` rompe aquí.
describe("vistas privadas", () => {
  const APP = join(__dirname, "..", "[locale]");

  it.each(PRIVATE_PATHS.map((path) => [path]))(
    "%s declara noindex (privateMetadata)",
    (path) => {
      const dir = join(APP, path.slice(1));
      const sources = ["page.tsx", "layout.tsx"]
        .map((file) => join(dir, file))
        .filter((file) => existsSync(file))
        .map((file) => readFileSync(file, "utf8"));
      expect(sources.length, `${path}: sin página ni layout`).toBeGreaterThan(0);
      expect(sources.some((source) => source.includes("privateMetadata("))).toBe(true);
    },
  );
});
