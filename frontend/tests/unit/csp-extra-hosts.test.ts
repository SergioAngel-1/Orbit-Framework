import { describe, expect, it, vi } from "vitest";
import { cspExtraHosts } from "../../csp-extra-hosts.mjs";
import { buildContentSecurityPolicy } from "../../csp-policy.mjs";

// ============================================================================
//  Un despliegue no puede añadir hosts a la CSP (audiencias de Google Ads, una
//  pasarela de Conversions API, una CDN propia…) sin editar el núcleo. Las
//  variables `CSP_EXTRA_*_SRC` lo permiten SIN ampliar nada por defecto: sin
//  ellas la cabecera sale idéntica byte a byte.
//
//  Los valores acaban concatenados en una cabecera: un `;` colaría directivas
//  nuevas enteras, así que la validación es estricta (solo orígenes https).
// ============================================================================

describe("cspExtraHosts: validación", () => {
  it("sin variables no añade nada (la CSP no cambia)", () => {
    const warn = vi.fn();
    expect(cspExtraHosts({}, warn)).toEqual({ script: [], connect: [], img: [] });
    expect(warn).not.toHaveBeenCalled();
  });

  it("una variable vacía o en blanco cuenta como ausente, sin aviso", () => {
    const warn = vi.fn();
    expect(
      cspExtraHosts(
        {
          CSP_EXTRA_SCRIPT_SRC: "",
          CSP_EXTRA_CONNECT_SRC: "  ",
          CSP_EXTRA_IMG_SRC: " , ",
        },
        warn,
      ),
    ).toEqual({ script: [], connect: [], img: [] });
    expect(warn).not.toHaveBeenCalled();
  });

  it("cada variable añade a SU directiva, sin tocar las demás", () => {
    const hosts = cspExtraHosts({
      CSP_EXTRA_SCRIPT_SRC: "https://googleads.g.doubleclick.net",
      CSP_EXTRA_CONNECT_SRC: "https://api.pasarela.example",
      CSP_EXTRA_IMG_SRC: "https://cdn.ejemplo.com",
    });

    expect(hosts).toEqual({
      script: ["https://googleads.g.doubleclick.net"],
      connect: ["https://api.pasarela.example"],
      img: ["https://cdn.ejemplo.com"],
    });
  });

  it("admite el comodín de subdominio, el puerto y normaliza mayúsculas y espacios", () => {
    expect(
      cspExtraHosts({
        CSP_EXTRA_IMG_SRC: " HTTPS://*.CDN.Ejemplo.com , https://cdn.ejemplo.com:8443 ",
      }).img,
    ).toEqual(["https://*.cdn.ejemplo.com", "https://cdn.ejemplo.com:8443"]);
  });

  it("quita duplicados conservando el orden", () => {
    expect(
      cspExtraHosts({
        CSP_EXTRA_SCRIPT_SRC: "https://a.example,https://b.example,https://A.example",
      }).script,
    ).toEqual(["https://a.example", "https://b.example"]);
  });

  it.each([
    ["*", "comodín suelto"],
    ["http://cdn.example", "http en claro"],
    ["//cdn.example", "origen sin esquema"],
    ["cdn.example", "host sin esquema"],
    ["'unsafe-inline'", "palabra clave"],
    ["data:", "esquema de datos"],
    ["https://cdn.example/ruta/script.js", "ruta"],
    ["https://cdn.example?x=1", "consulta"],
    ["https://cdn.example#x", "fragmento"],
    ["https://user:pass@cdn.example", "credenciales"],
    ["https://*.com", "comodín sobre un TLD"],
    ["https://localhost", "host de una etiqueta"],
    ["https://cdn.example:99999", "puerto fuera de rango"],
    ["https://cdn.example/a b", "espacio interno"],
  ])("descarta %s (%s) con aviso", (value) => {
    const warn = vi.fn();

    expect(
      cspExtraHosts({ CSP_EXTRA_SCRIPT_SRC: `https://valido.example,${value}` }, warn)
        .script,
    ).toEqual(["https://valido.example"]);
    expect(warn).toHaveBeenCalledTimes(1);
    expect(warn.mock.calls[0][0]).toContain("CSP_EXTRA_SCRIPT_SRC");
    expect(warn.mock.calls[0][0]).toContain(value);
  });

  it("rechaza la inyección de directivas con `;`", () => {
    const warn = vi.fn();
    const value = "https://cdn.example; script-src *; connect-src *";

    expect(cspExtraHosts({ CSP_EXTRA_SCRIPT_SRC: value }, warn).script).toEqual([]);
    expect(warn).toHaveBeenCalledTimes(1);
  });

  it("una variable sin ningún valor válido no rompe el build: avisa y sigue", () => {
    const warn = vi.fn();

    expect(cspExtraHosts({ CSP_EXTRA_CONNECT_SRC: "*,http:" }, warn).connect).toEqual(
      [],
    );
    expect(warn).toHaveBeenCalledTimes(1);
  });
});

describe("buildContentSecurityPolicy: los hosts extra entran en su directiva", () => {
  const base = {
    isProd: true,
    wordpressOrigin: "https://cms.example.com",
    analytics: {
      script: ["https://www.googletagmanager.com"],
      connect: ["https://www.google-analytics.com"],
      img: ["https://www.googletagmanager.com"],
    },
  };

  /** Una directiva concreta de la cabecera (`; ` entre directivas). */
  const directive = (policy: string, name: string) =>
    policy.split("; ").find((part) => part === name || part.startsWith(`${name} `)) ??
    "";

  it("sin extras la cabecera es exactamente la de siempre", () => {
    expect(buildContentSecurityPolicy(base)).toBe(
      "default-src 'self'; " +
        "script-src 'self' 'unsafe-inline' https://www.googletagmanager.com; " +
        "style-src 'self' 'unsafe-inline'; " +
        "img-src 'self' data: blob: https://cms.example.com https://www.googletagmanager.com; " +
        "font-src 'self' data:; " +
        "connect-src 'self' https://www.google-analytics.com; " +
        "frame-ancestors 'none'; frame-src 'none'; object-src 'none'; " +
        "base-uri 'self'; form-action 'self'; manifest-src 'self'; " +
        "worker-src 'self' blob:; upgrade-insecure-requests",
    );
  });

  it("con extras vacíos el resultado es idéntico al de no pasarlos", () => {
    expect(
      buildContentSecurityPolicy({
        ...base,
        extra: { script: [], connect: [], img: [] },
      }),
    ).toBe(buildContentSecurityPolicy(base));
  });

  it("cada origen extra va a su directiva, detrás de los de analítica", () => {
    const policy = buildContentSecurityPolicy({
      ...base,
      extra: {
        script: ["https://googleads.g.doubleclick.net"],
        connect: ["https://api.pasarela.example"],
        img: ["https://*.cdn.ejemplo.com"],
      },
    });

    expect(directive(policy, "script-src")).toBe(
      "script-src 'self' 'unsafe-inline' https://www.googletagmanager.com https://googleads.g.doubleclick.net",
    );
    expect(directive(policy, "connect-src")).toBe(
      "connect-src 'self' https://www.google-analytics.com https://api.pasarela.example",
    );
    expect(directive(policy, "img-src")).toBe(
      "img-src 'self' data: blob: https://cms.example.com https://www.googletagmanager.com https://*.cdn.ejemplo.com",
    );
  });

  it("no repite un origen que ya venía de analítica", () => {
    const policy = buildContentSecurityPolicy({
      ...base,
      extra: { script: ["https://www.googletagmanager.com"], connect: [], img: [] },
    });

    expect(directive(policy, "script-src")).toBe(
      "script-src 'self' 'unsafe-inline' https://www.googletagmanager.com",
    );
  });

  it("en desarrollo conserva `'unsafe-eval'` y los sockets, con los extras al final", () => {
    const policy = buildContentSecurityPolicy({
      ...base,
      isProd: false,
      extra: {
        script: ["https://cdn.example"],
        connect: ["https://api.example"],
        img: [],
      },
    });

    expect(directive(policy, "script-src")).toBe(
      "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://www.googletagmanager.com https://cdn.example",
    );
    expect(directive(policy, "connect-src")).toBe(
      "connect-src 'self' https://www.google-analytics.com https://api.example ws: wss:",
    );
  });
});

describe("next.config.mjs: wiring de los extras", () => {
  type Config = {
    headers: () => Promise<
      Array<{ source: string; headers: Array<{ key: string; value: string }> }>
    >;
  };

  async function cspHeader(): Promise<string> {
    const mod = (await import("../../next.config.mjs")) as { default: Config };
    const rules = await mod.default.headers();
    const rule = rules.find((entry) => entry.source === "/:path*")!;
    return rule.headers.find((header) => header.key === "Content-Security-Policy")!
      .value;
  }

  /** Una directiva concreta de la cabecera (`; ` entre directivas). */
  const directive = (policy: string, name: string) =>
    policy.split("; ").find((part) => part === name || part.startsWith(`${name} `)) ??
    "";

  it("con las variables puestas, los orígenes llegan a la cabecera", async () => {
    vi.stubEnv("CSP_EXTRA_SCRIPT_SRC", "https://cdn.example");
    vi.stubEnv("CSP_EXTRA_CONNECT_SRC", "https://api.example");
    vi.stubEnv("CSP_EXTRA_IMG_SRC", "https://img.example");
    vi.resetModules();

    try {
      const policy = await cspHeader();

      expect(directive(policy, "script-src")).toContain("https://cdn.example");
      expect(directive(policy, "connect-src")).toContain("https://api.example");
      expect(directive(policy, "img-src")).toContain("https://img.example");
    } finally {
      vi.unstubAllEnvs();
      vi.resetModules();
    }
  });

  it("sin las variables, la cabecera no gana ningún host", async () => {
    vi.resetModules();
    const policy = await cspHeader();

    expect(policy).not.toContain("cdn.example");
    expect(policy).not.toContain("img.example");
  });
});
