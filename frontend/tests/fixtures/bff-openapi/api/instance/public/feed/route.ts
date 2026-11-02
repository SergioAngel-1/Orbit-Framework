// Ruta pública de máquina de una instancia (fixture de tests/unit/contract-bff.test.ts).
import { instanceRoute } from "@/lib/api/instance-route";

const FEED_LIMIT = 30;

export const GET = instanceRoute(
  { public: true, guard: { rateLimit: { name: "feed", limit: FEED_LIMIT, windowSeconds: 60 } } },
  async () => Response.json({ items: [] }),
);

export const POST = instanceRoute(
  { public: true, guard: { rateLimit: { name: "feed-write", limit: 5, windowSeconds: 60 } } },
  async () => Response.json({ ok: true }),
);

export async function OPTIONS() {
  return new Response(null, { status: 204, headers: { Allow: "GET, POST, OPTIONS" } });
}
