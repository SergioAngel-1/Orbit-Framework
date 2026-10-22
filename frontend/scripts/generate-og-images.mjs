#!/usr/bin/env node
// ============================================================================
//  Tarjetas sociales (Open Graph / Twitter) estáticas de las vistas
//  principales — OPCIONAL: por defecto el sitio comparte la tarjeta dinámica
//  de marca (`/api/og`).
//
//  Genera un PNG de 1200×630 por vista e idioma en `public/og/` y el
//  manifiesto `src/instance/og-cards.json`, que el registro de la instancia
//  (`src/instance/registry.ts`) entrega a `lib/seo/og-static.ts`.
//
//    npm run og:generate                              # todas las vistas
//    npm run og:generate -- products blog             # solo las nombradas
//    npm run og:generate -- --config ../instance.config.json
//
//  Los PNG se VERSIONAN en el repositorio de la instancia: son arte del sitio,
//  no un artefacto del build. El CI y el Dockerfile no ejecutan este script (el
//  contenedor de producción no tiene Chromium); se regenera a mano cuando
//  cambia el copy, el logo o la paleta, y se commitea el resultado.
//
//  Por qué un navegador y no `next/og` (satori) ni `sharp` a secas: Chromium
//  (el de Playwright, ya devDependency) pinta el texto con las fuentes y los
//  SVG tal como los pinta el sitio.
//
//  De dónde sale cada cosa:
//    · Paleta y marca → `design.colors` y `brand` de la configuración de la
//                       instancia (`--config`, por defecto `../instance.config.json`
//                       si existe). Un PNG no puede seguir al CMS: si la paleta
//                       cambia, se regenera.
//    · Texto          → `src/i18n/messages/{es,en}.json`, los MISMOS títulos que
//                       pinta cada página.
//    · Logo           → `public/brand/logo.svg` (y `logo-white.svg` en tarjetas
//                       oscuras), si existen.
//
//  La URL pública lleva `?v=<hash del archivo>`: WhatsApp, Facebook y LinkedIn
//  cachean la imagen por URL, y sin él una tarjeta regenerada seguiría saliendo
//  con el arte viejo.
// ============================================================================

import { createHash } from "node:crypto";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { chromium } from "@playwright/test";
import sharp from "sharp";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");
const OUT_DIR = join(ROOT, "public", "og");
const MANIFEST_PATH = join(ROOT, "src", "instance", "og-cards.json");
const LOCALES = ["es", "en"];
const WIDTH = 1200;
const HEIGHT = 630;

/**
 * Vista → de dónde sale su titular: una ruta de `messages/*.json`, o `"tagline"`
 * (el lema de la marca) para la home. Las claves de este mapa son el tipo
 * `OgView` de `lib/seo/og-static.ts`: añadir una vista aquí exige añadirla allí
 * (lo comprueba `og-static.test.ts`).
 */
const VIEWS = {
  home: { title: "tagline" },
  products: { title: "products.title" },
  blog: { title: "blog.title" },
  about: { title: "about.title" },
  contact: { title: "contact.title" },
};

function parseArgs(argv) {
  const views = [];
  let config = null;
  for (let i = 0; i < argv.length; i += 1) {
    if (argv[i] === "--config") config = argv[(i += 1)];
    else views.push(argv[i]);
  }
  return { views, config };
}

function loadInstanceConfig(explicit) {
  const path = resolve(ROOT, explicit ?? "../instance.config.json");
  if (!existsSync(path)) {
    if (explicit) throw new Error(`No existe la configuración: ${path}`);
    return {};
  }
  return JSON.parse(readFileSync(path, "utf8"));
}

const args = parseArgs(process.argv.slice(2));
const instance = loadInstanceConfig(args.config);

// Valores neutros si la instancia no define la suya: los mismos por defecto del framework.
const colors = instance.design?.colors ?? {};
const PALETTE = {
  background: colors.background || "#ffffff",
  foreground: colors.foreground || "#111827",
  brand: colors.brand || "#111111",
  brandDark: colors.brand_dark || "#000000",
};
const BRAND_NAME = instance.brand?.name || "HeadlessWP";
const TAGLINE = {
  es: instance.brand?.tagline || instance.brand?.description || BRAND_NAME,
  en:
    instance.brand?.tagline_en ||
    instance.brand?.tagline ||
    instance.brand?.description ||
    BRAND_NAME,
};

const messages = Object.fromEntries(
  LOCALES.map((l) => [
    l,
    JSON.parse(
      readFileSync(join(ROOT, "src", "i18n", "messages", `${l}.json`), "utf8"),
    ),
  ]),
);

function resolveTitle(spec, locale) {
  if (spec === "tagline") return TAGLINE[locale] ?? TAGLINE.es;
  const get = (l) => spec.split(".").reduce((node, key) => node?.[key], messages[l]);
  const value = get(locale) ?? get("es");
  if (typeof value !== "string" || !value.trim()) {
    throw new Error(`Clave de i18n sin texto: ${spec} (${locale})`);
  }
  return value.trim();
}

const esc = (text) =>
  text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");

function logoDataUri() {
  const path = join(ROOT, "public", "brand", "logo.svg");
  if (!existsSync(path)) return null;
  return `data:image/svg+xml;base64,${readFileSync(path).toString("base64")}`;
}

function cardHtml({ kicker, title }) {
  const logo = logoDataUri();
  return `<!doctype html>
<html><head><meta charset="utf-8"><style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  html, body { width: ${WIDTH}px; height: ${HEIGHT}px; }
  body { position: relative; overflow: hidden; color: #fff;
         background: linear-gradient(135deg, ${PALETTE.brand} 0%, ${PALETTE.brandDark} 100%);
         font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
  .content { position: absolute; inset: 0; display: flex; flex-direction: column; padding: 64px 72px; }
  .logo { height: 88px; width: auto; align-self: flex-start; }
  .copy { flex: 1; display: flex; flex-direction: column; justify-content: center; max-width: 900px; }
  .kicker { font-size: 24px; font-weight: 700; letter-spacing: 0.14em; text-transform: uppercase; opacity: 0.85; margin-bottom: 22px; }
  .title { font-size: 84px; font-weight: 800; line-height: 1.06; letter-spacing: -0.015em; text-wrap: balance; }
</style></head>
<body><div class="content">
  ${logo ? `<img class="logo" src="${logo}" alt="">` : ""}
  <div class="copy">
    <div class="kicker">${esc(kicker)}</div>
    <div class="title">${esc(title)}</div>
  </div>
</div></body></html>`;
}

/** Encoge el titular hasta que quepa: de dos palabras a una frase entera. */
async function fitTitleToCard(page) {
  await page.evaluate(async () => {
    await document.fonts.ready;
    await Promise.all([...document.images].map((img) => img.decode().catch(() => {})));
    const title = document.querySelector(".title");
    const MAX_HEIGHT = 300;
    let size = 84;
    while (title.scrollHeight > MAX_HEIGHT && size > 40) {
      size -= 2;
      title.style.fontSize = `${size}px`;
    }
  });
}

async function main() {
  const unknown = args.views.filter((name) => !(name in VIEWS));
  if (unknown.length) throw new Error(`Vista desconocida: ${unknown.join(", ")}`);
  const names = args.views.length ? args.views : Object.keys(VIEWS);

  mkdirSync(OUT_DIR, { recursive: true });
  let manifest = {};
  try {
    manifest = JSON.parse(readFileSync(MANIFEST_PATH, "utf8"));
  } catch {
    // Primera ejecución: aún no hay manifiesto.
  }

  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({
      viewport: { width: WIDTH, height: HEIGHT },
      deviceScaleFactor: 1,
    });
    for (const name of names) {
      for (const locale of LOCALES) {
        await page.setContent(
          cardHtml({
            kicker: BRAND_NAME,
            title: resolveTitle(VIEWS[name].title, locale),
          }),
        );
        await fitTitleToCard(page);
        const shot = await page.screenshot({ type: "png" });
        // Paleta indexada: arte plano pesa ~4× menos que en RGBA, y WhatsApp
        // descarta en silencio las imágenes sociales pesadas.
        const png = await sharp(shot)
          .png({ palette: true, quality: 92, effort: 10 })
          .toBuffer();
        const file = `${name}.${locale}.png`;
        writeFileSync(join(OUT_DIR, file), png);
        manifest[`${name}.${locale}`] = createHash("sha256")
          .update(png)
          .digest("hex")
          .slice(0, 10);
        console.log(`✓ public/og/${file}  ${(png.length / 1024).toFixed(0)} KB`);
      }
    }
  } finally {
    await browser.close();
  }

  const sorted = Object.fromEntries(
    Object.entries(manifest).sort(([a], [b]) => a.localeCompare(b)),
  );
  writeFileSync(MANIFEST_PATH, `${JSON.stringify(sorted, null, 2)}\n`);
  console.log(`✓ ${MANIFEST_PATH.replace(`${ROOT}/`, "")}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
