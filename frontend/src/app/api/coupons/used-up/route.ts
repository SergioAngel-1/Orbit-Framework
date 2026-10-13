import { NextResponse } from "next/server";
import { getSession } from "@/lib/auth/session";
import { getSiteConfig } from "@/lib/config";
import { requireModule } from "@/lib/api/modules";
import { requireFlag } from "@/lib/api/flags";
import { getUsedUpCouponCodes } from "@/lib/coupons/public-coupons";

export const dynamic = "force-dynamic";

// ============================================================================
//  GET /api/coupons/used-up — códigos de cupón públicos que ESTE cliente ya no
//  puede usar (límite por usuario agotado, primera compra ya hecha, pedido
//  pendiente con el cupón).
//
//  Existe porque los avisos de ficha y de categoría son ISR y no conocen al
//  visitante: `CouponGate` (navegador) lo pregunta aquí y oculta el aviso de
//  un cupón que ya no puede usar. Solo devuelve datos del propio cliente (la
//  sesión sale de la cookie httpOnly, nunca de un parámetro). Sin sesión, sin
//  llamada a WordPress: un invitado no tiene historial.
//
//  Es del módulo `coupon-rules` y de `ecommerce.coupons_enabled`: apagados, 404.
// ============================================================================

export async function GET() {
  const config = await getSiteConfig();
  const disabled = requireModule(config, "coupon-rules");
  if (disabled) return disabled;
  const noCoupons = requireFlag(config, "coupons_enabled");
  if (noCoupons) return noCoupons;

  const session = await getSession();
  const codes = session ? [...(await getUsedUpCouponCodes(session.userId))] : [];
  // `authenticated` lo aprovecha el aviso (ISR, no conoce al visitante) para
  // invitar a iniciar sesión solo a quien no la tiene, sin otra petición.
  return NextResponse.json(
    { codes, authenticated: session !== null },
    { headers: { "Cache-Control": "private, no-store" } },
  );
}
