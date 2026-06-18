import "server-only";
import { registerProvider } from "../registry";
import { NoopProvider } from "./noop";
import { WompiProvider } from "./wompi";
import { PayUProvider } from "./payu";
import { BoldProvider } from "./bold";

// ============================================================================
//  Registro de las pasarelas disponibles.
//
//  Importar este módulo (efecto secundario) puebla el registro. Los Route
//  Handlers de pagos hacen `import "@/lib/payments/providers";` antes de
//  resolver el proveedor activo con `getProvider()`.
//
//  Para añadir una pasarela: implementa `PaymentProvider` en `<nombre>.ts` y
//  añade aquí una línea `registerProvider(new XxxProvider())`. Nada más.
// ============================================================================

registerProvider(new NoopProvider());
registerProvider(new WompiProvider()); // plantilla-stub
registerProvider(new PayUProvider()); // plantilla-stub
registerProvider(new BoldProvider()); // plantilla-stub
