// Envoltorio de firma de una instancia (fixture de tests/unit/route-signature.test.ts).
import { verifyHmacSignature } from "@/lib/security/webhook";

export function verifyPartnerSignature(raw: string, signature: string | null): boolean {
  return (
    !!signature && verifyHmacSignature(raw, signature, "secreto", { encoding: "hex" })
  );
}

/** Se llama como un verificador pero no verifica nada. */
export function verifyNothing(): boolean {
  return true;
}
