// Webhook con límite propio por llamada directa (fixture de tests/unit/contract-bff.test.ts).
import { rateLimit } from "@/lib/security/rate-limit";
import { verifyHmacSignature } from "@/lib/security/webhook";

const RATE_LIMIT = { limit: 300, windowSeconds: 60 };

export async function POST(request: Request) {
  const limited = await rateLimit(
    "partner-webhook",
    RATE_LIMIT.limit,
    RATE_LIMIT.windowSeconds,
  );
  if (!limited.success) return new Response(null, { status: 429 });
  const raw = await request.text();
  if (
    !verifyHmacSignature(raw, request.headers.get("x-signature") ?? "", "s", {
      encoding: "hex",
    })
  ) {
    return new Response(null, { status: 401 });
  }
  return new Response(null, { status: 204 });
}
