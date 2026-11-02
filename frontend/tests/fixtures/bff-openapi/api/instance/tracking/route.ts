// Límites en constantes del módulo (fixture de tests/unit/contract-bff.test.ts).
import { instanceRoute } from "@/lib/api/instance-route";
import { rateLimit } from "@/lib/security/rate-limit";

const RATE_LIMIT = { name: "tracking-read", limit: 60, windowSeconds: 60 } as const;
const ATTEMPTS = 5;
const WINDOW_SECONDS = 15 * 60;

export const GET = instanceRoute({ guard: { rateLimit: RATE_LIMIT } }, async () =>
  Response.json({ ok: true }),
);

export const POST = instanceRoute(
  {
    guard: {
      rateLimit: {
        name: "tracking-ip",
        limit: ATTEMPTS * 2,
        windowSeconds: WINDOW_SECONDS,
      },
    },
  },
  async ({ request }) => {
    const perOrder = await rateLimit(
      `tracking:${request.headers.get("x-order")}`,
      ATTEMPTS,
      WINDOW_SECONDS,
      { strict: true },
    );
    return Response.json({ ok: perOrder.success });
  },
);
