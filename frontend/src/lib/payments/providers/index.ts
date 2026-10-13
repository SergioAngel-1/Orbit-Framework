import "server-only";
import { registerProvider } from "../registry";
import { NoopProvider } from "./noop";
import { WompiProvider } from "./wompi";
import { AvalPayProvider } from "./avalpay";
import { PayUProvider } from "./payu";
import { BoldProvider } from "./bold";

// ============================================================================
//  Registro de las pasarelas disponibles.
//
//  Importar este módulo (efecto secundario) puebla el registro. Los Route
//  Handlers de pagos hacen `import "@/lib/payments/providers";` antes de
//  resolver la pasarela activa con `getActiveProvider()`. Wompi y Aval Pay
//  están integradas de punta a punta; PayU y Bold son plantillas que responden
//  501.
//
//  Para añadir una pasarela: implementa `PaymentProvider` en `<nombre>.ts` y
//  añade aquí una línea `registerProvider(new XxxProvider())`. Nada más.
// ============================================================================

registerProvider(new NoopProvider());
registerProvider(new WompiProvider());
registerProvider(new AvalPayProvider());
registerProvider(new PayUProvider()); // plantilla-stub
registerProvider(new BoldProvider()); // plantilla-stub
