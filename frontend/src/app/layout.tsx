import type { ReactNode } from "react";

// ============================================================================
//  Root layout "passthrough". El <html>/<body> reales los renderiza
//  app/[locale]/layout.tsx (donde sí hay contexto de locale). Este root layout
//  existe únicamente para que el not-found global (app/not-found.tsx) tenga un
//  root layout, requisito de Next.js.
// ============================================================================

export default function RootLayout({ children }: { children: ReactNode }) {
  return children;
}
