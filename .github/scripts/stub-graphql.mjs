// ============================================================================
//  Endpoint GraphQL de pega para el CI.
//
//  Responde `{"data":{}}` a cualquier petición. No imita el esquema: el objetivo
//  NO es construir con datos realistas, sino que el build se complete con el CMS
//  VACÍO y así ejercitar las ramas de fallback y null-handling de cada página.
//
//  Por qué hace falta: `next build` prerenderiza, y varias páginas ESTÁTICAS
//  bajo `[locale]` se renderizan SIEMPRE —no dependen de `generateStaticParams`,
//  que sí puede devolver lista vacía—, así que consultan GraphQL durante el
//  build. Un runner de GitHub no tiene WordPress, y `lib/catalog/products.ts`
//  propaga los errores de red A PROPÓSITO (convertirlos en 404 escondería una
//  caída del CMS detrás de "producto no encontrado"). Sin este stub el build
//  muere con ECONNREFUSED.
//
//  Esto es EXCLUSIVO del CI. El build de producción corre contra el WordPress
//  real; allí un fallo de red debe seguir rompiendo el build, porque ese build
//  sí hornea las páginas que se van a servir.
//
//  ── La ÚNICA excepción al "CMS vacío": la config pública ───────────────────
//  `/wp-json/hwe/v1/config` devuelve la paleta de `site-config.ci.json`. No es
//  un capricho: `fetchRemoteConfig` mezcla esa respuesta sobre `CONFIG_DEFAULTS`,
//  y con `{"data":{}}` el CI nunca ejercitaría la rama de ÉXITO de la config
//  remota (la que corre en producción), solo la de respaldo. El fixture
//  reproduce la paleta por defecto del framework; `tests/unit/ci-stub.test.ts`
//  comprueba que no diverge de `CONFIG_DEFAULTS`.
//
//  Solo `design.colors`: `typography` traería los enlaces a Google Fonts y con
//  ellos una dependencia de red dentro de cada spec.
//
//  Tampoco es «vacío» `GET /wp-json/wc/v3/orders[/{10..13}]`, `customers/7`,
//  `hwe-banners/v1/banners/carousel` (un slide con imagen, que el propio stub sirve en
//  `/wp-content/uploads/e2e-banner.svg`), `hwe-shipping/v1/{methods,free-shipping/scope}` (un extra de envío y un alcance con una
//  ciudad), `hwe/v1/geo` (el paquete genérico de un país sin paquete, como el que sirve
//  WordPress para el `ES` por defecto) ni
//  `hwe/v1/coupons/public` (un cupón de prueba): los
//  pedidos y el cliente de prueba de los e2e de la cuenta (`STUB_ORDERS`).
//
//  Uso:  node .github/scripts/stub-graphql.mjs   (puerto: STUB_GRAPHQL_PORT, 8080)
//        STUB_SITE_CONFIG_EXTRA='{"payments":{"cash_enabled":true}}' añade secciones
// ============================================================================
import { createServer } from "node:http";
import { readFileSync } from "node:fs";
import { pathToFileURL } from "node:url";

const CONFIG_PATH = "/wp-json/hwe/v1/config";

/**
 * Config pública que sirve el stub: los colores del fixture y, opcionalmente,
 * las secciones de `extra` (`STUB_SITE_CONFIG_EXTRA`, JSON). Sirve para que un
 * e2e ejercite algo desactivado por defecto —varios medios de pago, un
 * módulo— sin tocar el fixture. `extra` nunca sustituye la paleta.
 */
export function buildSiteConfig(fixture, extra = {}) {
  return { ...extra, design: { colors: fixture.design.colors } };
}

const line = (id, name, productId, quantity = 1) => ({
  id,
  name,
  product_id: productId,
  quantity,
  price: 25,
  total: String(25 * quantity),
  subtotal: String(25 * quantity),
});

const billing = {
  first_name: "Ana",
  last_name: "Ruiz",
  address_1: "Calle Mayor 1",
  city: "Madrid",
  postcode: "28001",
  country: "ES",
  email: "ana@example.com",
};

/**
 * Pedidos de prueba del cliente 7 (`GET /wp-json/wc/v3/orders/<id>`): uno por
 * estado que la vista de pedido distingue. Solo para los e2e de la cuenta.
 */
export const STUB_ORDERS = [
  // Completado y sin reseña → «Calificar pedido».
  { id: 10, status: "completed" },
  // Procesando → «Recibí mi pedido».
  { id: 11, status: "processing" },
  // Completado y ya calificado (espejo `_hwe_site_review`).
  {
    id: 12,
    status: "completed",
    meta_data: [
      {
        id: 1,
        key: "_hwe_site_review",
        value: {
          id: 5,
          rating: 4,
          content: "Muy buena compra.",
          date: "2026-10-01",
        },
      },
    ],
  },
  // En espera, pago en línea → «Pagar ahora».
  { id: 13, status: "on-hold", payment_method: "hwe_offline" },
].map((order) => ({
  number: String(order.id),
  currency: "EUR",
  total: "50.00",
  subtotal: "50.00",
  total_discount: "0.00",
  total_shipping: "0.00",
  total_tax: "0.00",
  customer_id: 7,
  date_created: "2026-09-30T10:00:00",
  date_modified: "2026-09-30T10:00:00",
  billing,
  shipping: billing,
  line_items: [line(order.id * 10 + 1, "Camiseta", 3, 2)],
  coupon_lines: [],
  payment_method: "cod",
  payment_method_title: "",
  meta_data: [],
  ...order,
}));

/** Cupón público de prueba (`hwe/v1/coupons/public`): 10 % en todo el catálogo. */
const STUB_COUPON = {
  code: "HOLA10",
  discountType: "percent",
  amount: 10,
  freeShipping: false,
  scope: "all",
  productSlugs: [],
  categorySlugs: [],
  excludedProductSlugs: [],
  excludedCategorySlugs: [],
  excludeSaleItems: false,
  minimumAmount: null,
  maximumAmount: null,
  requiresLogin: false,
  firstOrderOnly: false,
  perUserLimited: false,
  startsAt: null,
  endsAt: null,
};

const STUB_CUSTOMER = {
  id: 7,
  email: "ana@example.com",
  first_name: "Ana",
  last_name: "Ruiz",
  username: "ana",
  billing,
  shipping: billing,
  meta_data: [],
};

/** Paquete de país de reserva (`hwe/v1/geo`): texto libre, para el `ES` de la config por defecto. */
const STUB_GEO = {
  country: "generic",
  name: "Genérico",
  fallback: true,
  requested: "ES",
  dialCode: "",
  divisionLabel: "Provincia o estado",
  address: { division: "free", postcode: "optional", cityDivision: false },
  divisions: [],
  cities: [],
  documentTypes: [],
};

/** Extras de envío del módulo `shipping` (`hwe-shipping/v1/methods`). */
const STUB_SHIPPING_METHODS = [
  { id: 3, name: "Seguro de envío", added_value: 5 },
];

/** Alcance del envío gratis del módulo `shipping`: una ciudad, sin categorías ni productos. */
const STUB_FREE_SHIPPING_SCOPE = {
  categories: [],
  products: [],
  cities: ["Madrid"],
};

/** Imagen del slide de prueba de los banners: un SVG sencillo servido por el propio stub. */
const BANNER_IMAGE_PATH = "/wp-content/uploads/e2e-banner.svg";
const BANNER_IMAGE_SVG =
  '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="480"><rect width="1200" height="480" fill="#1e40af"/></svg>';

/** Carrusel de la portada (`hwe-banners/v1/banners/carousel`): un slide con imagen. */
const bannerCarousel = (origin) => ({
  intervalMs: 6000,
  slides: [
    {
      id: "1-0",
      bannerType: "carousel",
      location: "home",
      type: "default",
      image: `${origin}${BANNER_IMAGE_PATH}`,
      title: "Banner de prueba",
      subtitle: "Un slide servido por el stub",
    },
  ],
});

/** Respuesta fija de la REST de WooCommerce, o `undefined` si no es una del fixture. */
function wooFixture(path) {
  const order = path.match(/^\/wp-json\/wc\/v3\/orders\/(\d+)$/);
  if (order) return STUB_ORDERS.find((o) => o.id === Number(order[1]));
  if (path === "/wp-json/wc/v3/orders") return STUB_ORDERS;
  if (path === "/wp-json/wc/v3/products/reviews") return [];
  if (path === "/wp-json/hwe/v1/coupons/public")
    return { items: [STUB_COUPON] };
  if (path === "/wp-json/hwe/v1/geo") return STUB_GEO;
  if (path === "/wp-json/hwe-shipping/v1/methods") return STUB_SHIPPING_METHODS;
  if (path === "/wp-json/hwe-shipping/v1/free-shipping/scope")
    return STUB_FREE_SHIPPING_SCOPE;
  if (path === "/wp-json/wc/v3/customers/7") return STUB_CUSTOMER;
  return undefined;
}

/** Servidor HTTP del stub (sin escuchar: quien lo usa elige el puerto). */
export function createStubServer(siteConfig) {
  return createServer((req, res) => {
    // Se drena el cuerpo aunque no se use: sin consumirlo, el cliente puede
    // quedarse esperando a que se libere el socket.
    req.resume();
    req.on("end", () => {
      const path = new URL(req.url ?? "/", "http://127.0.0.1").pathname;
      if (path === BANNER_IMAGE_PATH) {
        res.writeHead(200, { "content-type": "image/svg+xml" });
        res.end(BANNER_IMAGE_SVG);
        return;
      }
      const origin = `http://${req.headers.host ?? "127.0.0.1"}`;
      const body =
        path === CONFIG_PATH
          ? siteConfig
          : path === "/wp-json/hwe-banners/v1/banners/carousel"
            ? bannerCarousel(origin)
            : (wooFixture(path) ?? { data: {} });
      res.writeHead(200, { "content-type": "application/json" });
      res.end(JSON.stringify(body));
    });
  });
}

if (
  process.argv[1] &&
  import.meta.url === pathToFileURL(process.argv[1]).href
) {
  const port = Number(process.env.STUB_GRAPHQL_PORT ?? 8080);
  const fixture = JSON.parse(
    readFileSync(new URL("./site-config.ci.json", import.meta.url), "utf8"),
  );
  const extra = process.env.STUB_SITE_CONFIG_EXTRA
    ? JSON.parse(process.env.STUB_SITE_CONFIG_EXTRA)
    : {};
  createStubServer(buildSiteConfig(fixture, extra)).listen(
    port,
    "127.0.0.1",
    () => {
      console.log(`stub GraphQL escuchando en http://127.0.0.1:${port}`);
    },
  );
}
