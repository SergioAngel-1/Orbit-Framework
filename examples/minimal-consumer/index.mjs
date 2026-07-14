// ============================================================================
//  Consumidor mínimo del contrato HWE (docs/FRONTEND_CONNECT.md) — Node ≥ 20.
//
//  Demuestra, sin ningún framework, las tres superficies estables:
//   1. Config pública dinámica  → GET  {WP}/wp-json/hwe/v1/config
//   2. Catálogo (lectura)       → POST {WP}/graphql        (WPGraphQL/WooGraphQL)
//   3. BFF                      → GET  {BFF}/api/health  +  GET {BFF}/api/csrf
//
//  Uso:  WP_URL=http://localhost:8080 BFF_URL=http://localhost:3000 node index.mjs
// ============================================================================

const WP = (process.env.WP_URL ?? "http://localhost:8080").replace(/\/$/, "");
const BFF = (process.env.BFF_URL ?? "http://localhost:3000").replace(/\/$/, "");

const PRODUCTS_QUERY = /* GraphQL */ `
  query LatestProducts($first: Int!) {
    products(first: $first) {
      nodes {
        id
        name
        ... on SimpleProduct {
          price
        }
        ... on VariableProduct {
          price
        }
      }
    }
  }
`;

async function main() {
  // 1. Config pública (marca, diseño, flags) ---------------------------------
  const configRes = await fetch(`${WP}/wp-json/hwe/v1/config`);
  if (!configRes.ok) throw new Error(`config: HTTP ${configRes.status}`);
  const config = await configRes.json();
  console.log(`✔ Marca: ${config.brand?.name} — ${config.brand?.tagline}`);
  console.log(
    `  Flags: reviews=${config.ecommerce?.reviews_enabled} wishlist=${config.ecommerce?.wishlist_enabled}`,
  );

  // 2. Catálogo por GraphQL ---------------------------------------------------
  const gqlRes = await fetch(`${WP}/graphql`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ query: PRODUCTS_QUERY, variables: { first: 3 } }),
  });
  if (!gqlRes.ok) throw new Error(`graphql: HTTP ${gqlRes.status}`);
  const gql = await gqlRes.json();
  if (gql.errors) throw new Error(`graphql: ${gql.errors.map((e) => e.message).join(" | ")}`);
  for (const p of gql.data.products.nodes) {
    console.log(`✔ Producto: ${p.name} (${p.price ?? "sin precio"})`);
  }

  // 3. BFF: salud + token CSRF (primer paso de CUALQUIER escritura) -----------
  const health = await fetch(`${BFF}/api/health`).then((r) => r.json());
  console.log(`✔ BFF health: ${JSON.stringify(health.status ?? health)}`);

  const csrfRes = await fetch(`${BFF}/api/csrf`);
  const csrf = await csrfRes.json();
  console.log(`✔ CSRF token emitido: ${String(csrf.token ?? "").slice(0, 12)}…`);
  console.log("  (Las escrituras requieren reenviar este token en X-CSRF-Token junto a la");
  console.log("   cookie de la respuesta — flujo completo en docs/FRONTEND_CONNECT.md §A.2.)");
}

main().catch((err) => {
  console.error(`✘ ${err.message}`);
  console.error("  ¿Está levantada la pila? (docker compose up -d  +  frontend en :3000)");
  process.exit(1);
});
