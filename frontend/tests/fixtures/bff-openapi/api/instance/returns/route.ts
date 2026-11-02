// Ruta de escritura de una instancia (fixture de tests/unit/contract-bff.test.ts).
import { instanceRoute } from "@/lib/api/instance-route";

export const POST = instanceRoute(
  {
    session: "required",
    guard: {
      rateLimit: { name: "returns", limit: 5, windowSeconds: 600, strict: true },
    },
  },
  async () => Response.json({ ok: true }),
);
